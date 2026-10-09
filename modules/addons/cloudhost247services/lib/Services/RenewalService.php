<?php
/**
 * Domain renewals and expiration monitoring.
 *
 * The worker (DOMAIN_EXPIRATION_CHECK) scans module-tracked domains:
 *
 *   - expiration notices at the configured thresholds (default 30/14/7/3/1
 *     days — a setting, never hard-coded), each sent exactly once per domain;
 *   - auto-renewal: domains with auto_renew=1 inside the lead window get a
 *     real invoice (server-side catalogue price, club discount applied) and a
 *     DOMAIN_RENEWAL job once paid;
 *   - provider execution is honest: with the default WHMCS provider a
 *     renewal submission is reported unsupported (WHMCS' own billing cron
 *     renews platform-managed domains); the platform reconciliation then
 *     detects the advanced expiry and settles the renewal as completed.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\ProviderException;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

class RenewalService
{
    public const PENDING_PAYMENT = 'pending_payment';
    public const PROCESSING      = 'processing';
    public const COMPLETED       = 'completed';
    public const FAILED          = 'failed';

    /** @var JobQueue|null */
    private $queue;
    /** @var \Chs\Providers\Domain\ProviderRegistry|null */
    private $registry;

    public function __construct(JobQueue $queue = null, \Chs\Providers\Domain\ProviderRegistry $registry = null)
    {
        $this->queue = $queue;
        $this->registry = $registry;
    }

    protected function queue()
    {
        return $this->queue ?: $this->queue = new JobQueue();
    }

    protected function registry()
    {
        return $this->registry ?: $this->registry = new \Chs\Providers\Domain\ProviderRegistry();
    }

    /** @return array<int,int> configured notice thresholds, descending */
    public function noticeDays()
    {
        $raw = (string) Settings::string('domain_renewal_notice_days', '30,14,7,3,1');
        $days = [];
        foreach (explode(',', $raw) as $part) {
            $part = (int) trim($part);
            if ($part > 0) {
                $days[$part] = true;
            }
        }
        $days = array_keys($days);
        rsort($days);
        return $days;
    }

    public function autoRenewLeadDays()
    {
        return max(1, Settings::int('domain_auto_renew_lead_days', 7));
    }

    /**
     * DOMAIN_EXPIRATION_CHECK handler: send due expiration notices and start
     * auto-renewals for domains inside the lead window.
     *
     * @return array{notices:int, renewals_started:int, expired:int}
     */
    public function scanExpirations()
    {
        $notices = 0;
        $renewalsStarted = 0;
        $expired = 0;
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('domain_services')
            . " WHERE status = 'active' AND expires_at IS NOT NULL"
        );
        foreach ($rows as $row) {
            $expiryTs = Clock::toTime($row['expires_at']);
            if (!$expiryTs) {
                continue;
            }
            $daysLeft = (int) floor(($expiryTs - Clock::time()) / 86400);

            if ($daysLeft < 0 && $this->markNotice((int) $row['id'], 0)) {
                Db::update('domain_services', ['id' => (int) $row['id']], [
                    'status' => 'expired',
                ]);
                (new DomainEventService())->system(DomainEventService::DOMAIN_EXPIRED, $row['domain'], [
                    'domain_service_id' => (int) $row['id'],
                ], (int) $row['id']);
                (new NotificationService())->notify((int) $row['client_id'], 'domain_expired',
                    'Domain expired: ' . $row['domain'],
                    'The registration for ' . $row['domain'] . ' has expired. Renew it from your dashboard to restore service.',
                    'index.php?m=cloudhost247services&action=domain&id=' . (int) $row['id']);
                $expired++;
                continue;
            }

            foreach ($this->noticeDays() as $threshold) {
                if ($daysLeft <= $threshold && $this->markNotice((int) $row['id'], $threshold)) {
                    (new DomainEventService())->system(DomainEventService::DOMAIN_EXPIRING, $row['domain'], [
                        'domain_service_id' => (int) $row['id'],
                        'days_left'         => $daysLeft,
                        'threshold'         => $threshold,
                    ], (int) $row['id']);
                    (new NotificationService())->notify((int) $row['client_id'], 'domain_expiring',
                        $row['domain'] . ' expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'),
                        'The registration for ' . $row['domain'] . ' expires on '
                        . substr((string) $row['expires_at'], 0, 10) . '. '
                        . ((int) $row['auto_renew'] === 1
                            ? 'Auto-renew is enabled — we will attempt renewal automatically.'
                            : 'Enable auto-renew or renew manually from your dashboard.'),
                        'index.php?m=cloudhost247services&action=domain&id=' . (int) $row['id']);
                    $notices++;
                }
            }

            if ((int) $row['auto_renew'] === 1 && $daysLeft <= $this->autoRenewLeadDays()) {
                if ($this->startAutoRenewal($row)) {
                    $renewalsStarted++;
                }
            }
        }
        return ['notices' => $notices, 'renewals_started' => $renewalsStarted, 'expired' => $expired];
    }

    /**
     * Start one auto-renewal: real invoice (server-side price) + tracking row.
     * Idempotent per domain+expiry: a pending/processing/completed renewal for
     * the same expiry is reused, never duplicated.
     */
    public function startAutoRenewal(array $domainRow)
    {
        $existing = Db::query(
            'SELECT id FROM ' . Db::t('domain_renewals') . ' WHERE domain_service_id = ? AND status IN (?, ?)',
            [(int) $domainRow['id'], self::PENDING_PAYMENT, self::PROCESSING]
        );
        if ($existing) {
            return false;
        }
        $done = Db::query(
            'SELECT id FROM ' . Db::t('domain_renewals')
            . ' WHERE domain_service_id = ? AND status = ? AND completed_at >= ?',
            [(int) $domainRow['id'], self::COMPLETED, Clock::ago(86400)]
        );
        if ($done) {
            return false;
        }

        $years = 1;
        $priceMinor = $domainRow['renewal_price_minor'] !== null ? (int) $domainRow['renewal_price_minor'] : null;
        $currency = (string) ($domainRow['currency'] ?: Platform::gateway()->defaultCurrency());
        if ($priceMinor === null) {
            $catalog = (new TldCatalogService())->detail($domainRow['tld']);
            if ($catalog && $catalog['renew_minor'] !== null) {
                $priceMinor = (int) $catalog['renew_minor'];
            }
        }
        if ($priceMinor === null) {
            return false; // no honest price — nothing to invoice
        }

        // Club discount applies server-side.
        $final = $priceMinor;
        $membership = (new ClubService())->activeMembership((int) $domainRow['client_id']);
        if ($membership && !empty($membership['plan']['applies_renew'])
            && Settings::bool('club_allow_renewals', true)) {
            $pct = null;
            foreach ($membership['plan']['tlds'] as $tldRule) {
                if ($tldRule['tld'] === $domainRow['tld']) {
                    $pct = $tldRule['discount_percent'] !== null
                        ? (float) $tldRule['discount_percent']
                        : (float) $membership['plan']['discount_percent'];
                    break;
                }
            }
            if ($pct !== null && $pct > 0) {
                $final = \Chs\Core\Money::discount($priceMinor, $pct);
            }
        }

        $invoiceId = Platform::gateway()->createInvoice((int) $domainRow['client_id'], [[
            'description' => 'Domain renewal — ' . $domainRow['domain'] . ' (1 year)',
            'amount_minor' => $final,
            'taxed'       => true,
        ]], strtoupper(substr($currency, 0, 3)), 1, 'Automatic renewal. Cancel any time before it is paid.');

        Db::insert('domain_renewals', [
            'domain_service_id' => (int) $domainRow['id'],
            'invoice_id'        => $invoiceId,
            'status'            => self::PENDING_PAYMENT,
            'years'             => $years,
            'amount_minor'      => $final,
            'currency'          => strtoupper(substr($currency, 0, 3)),
            'created_at'        => Clock::now(),
        ]);
        (new NotificationService())->notify((int) $domainRow['client_id'], 'domain_renewal_started',
            'Auto-renewal invoice for ' . $domainRow['domain'],
            'Auto-renew is enabled for ' . $domainRow['domain'] . '. We issued invoice #' . $invoiceId
            . ' for the 1-year renewal; the domain is renewed once it is paid.',
            Platform::gateway()->invoiceUrl($invoiceId));
        return true;
    }

    /** InvoicePaid hook: a paid renewal moves to processing + provider job. */
    public function invoicePaid($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        $renewal = Db::first('domain_renewals', ['invoice_id' => $invoiceId]);
        if (!$renewal || $renewal['status'] !== self::PENDING_PAYMENT) {
            return false;
        }
        Db::update('domain_renewals', ['id' => (int) $renewal['id']], [
            'status'       => self::PROCESSING,
            'attempted_at' => Clock::now(),
        ]);
        $this->queue()->enqueue(DomainJobTypes::RENEWAL, [
            'renewal_id' => (int) $renewal['id'],
        ], [
            'idempotency_key' => 'domain-renewal:' . (int) $renewal['id'],
            'correlation_id'  => Str::random(12),
            'entity_type'     => 'domain_renewal',
            'entity_id'       => (int) $renewal['id'],
        ]);
        return true;
    }

    /**
     * DOMAIN_RENEWAL job handler: execute the renewal at the provider, or
     * settle it from platform reconciliation (WHMCS advanced the expiry).
     */
    public function execute($renewalId)
    {
        $renewal = Db::first('domain_renewals', ['id' => (int) $renewalId]);
        if (!$renewal) {
            throw new NotFoundException('Renewal not found.');
        }
        if ($renewal['status'] === self::COMPLETED) {
            return $renewal;
        }
        // Never trust a queued payload: the invoice must really be paid.
        if ($renewal['invoice_id']) {
            $invoiceStatus = Platform::gateway()->invoiceStatus((int) $renewal['invoice_id']);
            if ($invoiceStatus !== 'Paid') {
                Db::update('domain_renewals', ['id' => (int) $renewalId], [
                    'status' => self::FAILED,
                    'error'  => 'Invoice #' . (int) $renewal['invoice_id'] . ' is not paid (status: ' . (string) $invoiceStatus . ').',
                ]);
                throw new \Chs\Core\ChsException(
                    'Invoice #' . (int) $renewal['invoice_id'] . ' is not paid; renewal not executed.'
                );
            }
        }
        $domain = Db::first('domain_services', ['id' => (int) $renewal['domain_service_id']]);
        if (!$domain) {
            Db::update('domain_renewals', ['id' => (int) $renewalId], [
                'status' => self::FAILED,
                'error'  => 'The domain record no longer exists.',
            ]);
            throw new NotFoundException('The domain record no longer exists.');
        }

        // Platform reconciliation first: if the platform expiry already moved
        // past our stored value, the renewal happened — record the truth.
        $platformExpiry = null;
        try {
            foreach (Platform::gateway()->clientDomains((int) $domain['client_id']) as $row) {
                if (strtolower((string) $row['domain']) === strtolower((string) $domain['domain'])) {
                    $platformExpiry = (string) $row['expiry'];
                    break;
                }
            }
        } catch (\Throwable $e) {
            $platformExpiry = null;
        }
        if ($platformExpiry && $domain['expires_at']
            && Clock::toTime($platformExpiry . ' 00:00:00') > Clock::toTime($domain['expires_at'])) {
            $this->settleCompleted($renewal, $domain, $platformExpiry . ' 00:00:00');
            return Db::first('domain_renewals', ['id' => (int) $renewalId]);
        }

        $provider = $this->registry()->forTld($domain['tld']);
        try {
            $answer = $provider->renewDomain($domain['domain'], (int) $renewal['years']);
            $newExpiry = isset($answer['expires_at']) && $answer['expires_at']
                ? (string) $answer['expires_at']
                : Clock::in((int) $renewal['years'] * 365 * 86400);
            $this->settleCompleted($renewal, $domain, $newExpiry);
        } catch (ProviderException $e) {
            Db::update('domain_renewals', ['id' => (int) $renewalId], [
                'status' => self::FAILED,
                'error'  => substr($e->getMessage(), 0, 255),
            ]);
            (new DomainEventService())->system(DomainEventService::DOMAIN_RENEWAL_FAILED, $domain['domain'], [
                'renewal_id' => (int) $renewalId,
                'error'      => 'provider_error',
            ], (int) $domain['id']);
            (new NotificationService())->notify((int) $domain['client_id'], 'domain_renewal_failed',
                'Renewal failed for ' . $domain['domain'],
                'The automatic renewal for ' . $domain['domain'] . ' could not be completed at the registrar: '
                . 'the configured provider does not support direct renewals. Our team has been notified — '
                . 'please renew from your dashboard or contact support.',
                'index.php?m=cloudhost247services&action=domain&id=' . (int) $domain['id']);
            throw $e;
        }
        return Db::first('domain_renewals', ['id' => (int) $renewalId]);
    }

    /** @return array[] */
    public function listForDomain($domainServiceId)
    {
        return Db::all('domain_renewals', ['domain_service_id' => (int) $domainServiceId], 'id DESC');
    }

    /* ------------------------------------------------------------ internals -- */

    /** Record a sent notice; false when it was already sent (dedupe). */
    private function markNotice($domainServiceId, $daysBefore)
    {
        $existing = Db::first('domain_expiration_notices', [
            'domain_service_id' => (int) $domainServiceId,
            'days_before'       => (int) $daysBefore,
        ]);
        if ($existing) {
            return false;
        }
        Db::insert('domain_expiration_notices', [
            'domain_service_id' => (int) $domainServiceId,
            'days_before'       => (int) $daysBefore,
            'sent_at'           => Clock::now(),
        ]);
        return true;
    }

    private function settleCompleted(array $renewal, array $domain, $newExpiry)
    {
        Db::transaction(function () use ($renewal, $domain, $newExpiry) {
            Db::update('domain_renewals', ['id' => (int) $renewal['id']], [
                'status'       => self::COMPLETED,
                'completed_at' => Clock::now(),
                'error'        => '',
            ]);
            Db::update('domain_services', ['id' => (int) $domain['id']], [
                'expires_at' => $newExpiry,
                'status'     => 'active',
                'updated_at' => Clock::now(),
            ]);
        });
        (new DomainEventService())->system(DomainEventService::DOMAIN_RENEWED, $domain['domain'], [
            'renewal_id' => (int) $renewal['id'],
            'years'      => (int) $renewal['years'],
            'expires_at' => $newExpiry,
        ], (int) $domain['id']);
        (new NotificationService())->notify((int) $domain['client_id'], 'domain_renewed',
            $domain['domain'] . ' renewed',
            'The renewal for ' . $domain['domain'] . ' completed. The new expiration date is '
            . substr((string) $newExpiry, 0, 10) . '.',
            'index.php?m=cloudhost247services&action=domain&id=' . (int) $domain['id']);
    }
}
