<?php
/**
 * Module-initiated domain registration.
 *
 * The customer-facing register flow stays in WHMCS' own cart/checkout (real
 * orders, real registrar modules). This service covers module-initiated
 * registrations — currently auction settlement and admin-issued orders: it
 * prices server-side, creates the invoice through the host gateway, and runs
 * the provider registration as an idempotent DOMAIN_REGISTRATION job once the
 * invoice is paid.
 *
 * With the default WHMCS provider a direct registration submission is
 * honestly unsupported (WHMCS registers through its own order pipeline); the
 * job fails with a machine-readable code, the failure is audited, and an
 * admin completes the registration in WHMCS or configures a real registrar
 * provider. Nothing is ever faked.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\Money;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\ProviderException;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;
use Chs\Providers\Domain\ProviderRegistry;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

class RegistrationService
{
    /** @var JobQueue|null */
    private $queue;
    /** @var ProviderRegistry|null */
    private $registry;

    public function __construct(JobQueue $queue = null, ProviderRegistry $registry = null)
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
        return $this->registry ?: $this->registry = new ProviderRegistry();
    }

    /**
     * Server-side registration quote for a domain (catalogue price per year,
     * club discount applied). years is validated against 1..10.
     *
     * @return array{domain:string,tld:string,years:int,base_minor:int,final_minor:int,
     *               discount_percent:?float,currency:string}
     */
    public function quote($domainInput, $years = 1, $clientId = null)
    {
        $domain = DomainName::parse($domainInput);
        $years = (int) $years;
        if ($years < 1 || $years > 10) {
            throw new ValidationException(['years' => 'Registration term must be between 1 and 10 years.']);
        }
        $tldRow = (new TldCatalogService())->detail($domain->tld());
        if (!$tldRow || $tldRow['register_minor'] === null) {
            throw new ValidationException(['domain' => 'We do not sell .' . $domain->tld() . ' registrations yet.']);
        }
        $clientId = $clientId === null ? null : (int) $clientId;
        $currency = $clientId
            ? Platform::gateway()->clientCurrency($clientId)
            : Platform::gateway()->defaultCurrency();

        $base = (int) $tldRow['register_minor'] * $years;
        $final = $base;
        $percent = null;
        if ($clientId) {
            $quote = (new DomainSearchService())->quoteFor($tldRow, $clientId);
            if ($quote['register_final_minor'] !== null) {
                $final = (int) $quote['register_final_minor'] * $years;
                $percent = $quote['discount_percent'];
            }
        }
        return [
            'domain'           => $domain->fqdn(),
            'tld'              => $domain->tld(),
            'years'            => $years,
            'base_minor'       => $base,
            'final_minor'      => $final,
            'discount_percent' => $percent,
            'currency'         => strtoupper(substr((string) $currency, 0, 3)),
        ];
    }

    /**
     * Issue a registration invoice (real billing via the host gateway) and
     * record the intent. Returns the invoice to pay.
     *
     * @return array{invoice_id:int, url:string, quote:array}
     */
    public function createOrder($clientId, $domainInput, $years = 1)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0 || !Platform::gateway()->clientExists($clientId)) {
            throw new ValidationException(['client' => 'A signed-in customer is required.']);
        }
        $quote = $this->quote($domainInput, $years, $clientId);

        $invoiceId = Platform::gateway()->createInvoice($clientId, [[
            'description' => 'Domain registration — ' . $quote['domain'] . ' (' . $quote['years'] . ' year'
                . ($quote['years'] === 1 ? '' : 's') . ')',
            'amount_minor' => (int) $quote['final_minor'],
            'taxed'       => true,
        ]], $quote['currency'], max(1, Settings::int('transfer_invoice_due_days', 7)),
            'Domain registration order. The registration is submitted to the registrar once this invoice is paid.');

        Audit::log(Audit::ACTOR_CLIENT, $clientId, 'domain.registration_ordered', [
            'domain'      => $quote['domain'],
            'years'       => $quote['years'],
            'invoice_id'  => $invoiceId,
            'price_minor' => (int) $quote['final_minor'],
            'currency'    => $quote['currency'],
        ]);
        (new NotificationService())->notify($clientId, 'domain_registration_ordered',
            'Registration order for ' . $quote['domain'],
            'We issued invoice #' . $invoiceId . ' for the registration of ' . $quote['domain']
            . '. The domain is registered at the registrar as soon as the invoice is paid.',
            Platform::gateway()->invoiceUrl($invoiceId));

        return [
            'invoice_id' => $invoiceId,
            'url'        => Platform::gateway()->invoiceUrl($invoiceId),
            'quote'      => $quote,
        ];
    }

    /**
     * InvoicePaid hook: queue the provider registration (idempotent per
     * invoice — a replayed payment webhook cannot double-register).
     */
    public function invoicePaid($invoiceId, array $context = [])
    {
        $invoiceId = (int) $invoiceId;
        $existing = $this->queue()->findByIdempotencyKey('domain-registration:invoice:' . $invoiceId);
        if ($existing) {
            return false; // already queued — replay is a no-op
        }
        if (empty($context['domain'])) {
            return false; // not a domain registration invoice (caller passes context)
        }
        $job = $this->queue()->enqueue(DomainJobTypes::REGISTRATION, [
            'invoice_id' => $invoiceId,
            'client_id'  => isset($context['client_id']) ? (int) $context['client_id'] : 0,
            'domain'     => (string) $context['domain'],
            'years'      => isset($context['years']) ? (int) $context['years'] : 1,
            'source'     => (string) (isset($context['source']) ? $context['source'] : 'module'),
        ], [
            'idempotency_key' => 'domain-registration:invoice:' . $invoiceId,
            'correlation_id'  => Str::random(12),
            'entity_type'     => 'invoice',
            'entity_id'       => $invoiceId,
        ]);
        return $job !== null;
    }

    /**
     * DOMAIN_REGISTRATION job handler: verify the invoice is really paid
     * (never trust a queued payload), then register at the provider.
     */
    public function execute(array $payload)
    {
        $invoiceId = isset($payload['invoice_id']) ? (int) $payload['invoice_id'] : 0;
        $fqdn = strtolower(trim((string) (isset($payload['domain']) ? $payload['domain'] : '')));
        $years = isset($payload['years']) ? max(1, (int) $payload['years']) : 1;
        $clientId = isset($payload['client_id']) ? (int) $payload['client_id'] : 0;
        if ($invoiceId <= 0 || $fqdn === '') {
            throw new ValidationException(['payload' => 'Malformed registration job.']);
        }

        $status = Platform::gateway()->invoiceStatus($invoiceId);
        if ($status !== 'Paid') {
            (new DomainEventService())->system(DomainEventService::DOMAIN_REGISTRATION_FAILED, $fqdn, [
                'invoice_id' => $invoiceId,
                'client_id'  => $clientId,
                'error'      => 'invoice_not_paid',
            ]);
            throw new \Chs\Core\ChsException('Invoice #' . $invoiceId . ' is not paid (status: ' . (string) $status . '); registration not executed.');
        }

        $domain = DomainName::parse($fqdn);
        $provider = $this->registry()->forTld($domain->tld());
        try {
            $answer = $provider->registerDomain($domain->fqdn(), $years);
        } catch (ProviderException $e) {
            (new DomainEventService())->system(DomainEventService::DOMAIN_REGISTRATION_FAILED, $domain->fqdn(), [
                'invoice_id' => $invoiceId,
                'client_id'  => $clientId,
                'error'      => 'provider_error',
            ]);
            throw $e;
        }

        $expiresAt = isset($answer['expires_at']) && $answer['expires_at']
            ? (string) $answer['expires_at']
            : Clock::in($years * 365 * 86400);

        // Record the module domain-service row (the platform row is created by
        // WHMCS' own pipeline when it processes the order).
        $existing = Db::first('domain_services', ['domain' => $domain->fqdn()]);
        $data = [
            'client_id'     => $clientId,
            'tld'           => $domain->tld(),
            'status'        => 'active',
            'provider_id'   => is_numeric($provider->providerId()) ? (int) $provider->providerId() : null,
            'expires_at'    => $expiresAt,
            'last_synced_at' => Clock::now(),
            'updated_at'    => Clock::now(),
        ];
        $catalog = (new TldCatalogService())->detail($domain->tld());
        if ($catalog && $catalog['renew_minor'] !== null) {
            $data['renewal_price_minor'] = (int) $catalog['renew_minor'];
            $data['currency'] = Platform::gateway()->defaultCurrency();
        }
        if ($existing) {
            Db::update('domain_services', ['id' => (int) $existing['id']], $data);
            $serviceId = (int) $existing['id'];
        } else {
            $data['domain'] = $domain->fqdn();
            $data['registered_at'] = Clock::now();
            $data['created_at'] = Clock::now();
            $serviceId = Db::insert('domain_services', $data);
        }

        (new DomainEventService())->system(DomainEventService::DOMAIN_REGISTERED, $domain->fqdn(), [
            'invoice_id' => $invoiceId,
            'client_id'  => $clientId,
            'years'      => $years,
            'expires_at' => $expiresAt,
            'provider_ref' => isset($answer['provider_ref']) ? $answer['provider_ref'] : '',
        ], $serviceId);
        if ($clientId > 0) {
            (new NotificationService())->notify($clientId, 'domain_registered',
                $domain->fqdn() . ' is registered',
                'The registration of ' . $domain->fqdn() . ' completed. The domain is active until '
                . substr($expiresAt, 0, 10) . '.',
                'index.php?m=cloudhost247services&action=domain&id=' . (int) $serviceId);
        }
        return ['domain_service_id' => $serviceId, 'expires_at' => $expiresAt];
    }
}
