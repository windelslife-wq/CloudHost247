<?php
/**
 * Domain transfers.
 *
 * Flow: eligibility pre-check (TLD sold + transferable, WHOIS state, EPP
 * syntax) → server-side price quote (catalogue + club discount) → real
 * invoice through the host gateway (the existing billing system — no second
 * billing engine) → tracked transfer record with an append-only history.
 *
 * On InvoicePaid the transfer is submitted to the configured provider via a
 * DOMAIN_TRANSFER job. With the default WHMCS provider the submission is
 * honestly reported as unsupported (WHMCS runs transfers through its own order
 * pipeline); the job then fails with a machine-readable code, the transfer
 * stays visible in Domain Operations, and the platform reconciliation step
 * marks the transfer COMPLETED once WHMCS' own records show the domain active
 * for the client (e.g. completed by staff in WHMCS or by the WHMCS pipeline).
 *
 * EPP/auth codes are sealed at rest (Secrets) and never logged, echoed or
 * included in history/audit payloads.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\ForbiddenException;
use Chs\Core\InvalidTransitionException;
use Chs\Core\Money;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\ProviderException;
use Chs\Core\RateLimiter;
use Chs\Core\Secrets;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;
use Chs\Providers\Domain\ProviderRegistry;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

class TransferService
{
    public const PENDING             = 'PENDING';
    public const INITIATED           = 'INITIATED';
    public const AWAITING_AUTH_CODE  = 'AWAITING_AUTH_CODE';
    public const PROCESSING          = 'PROCESSING';
    public const PENDING_REGISTRY    = 'PENDING_REGISTRY';
    public const COMPLETED           = 'COMPLETED';
    public const FAILED              = 'FAILED';
    public const CANCELLED           = 'CANCELLED';
    public const EXPIRED             = 'EXPIRED';

    private const FINAL_STATES = [self::COMPLETED, self::CANCELLED, self::EXPIRED];

    /** WHOIS statuses that make a transfer impossible right now. */
    private const BLOCKING_WHOIS_STATUSES = [
        'clienthold', 'serverhold', 'pendingdelete', 'redemptionperiod',
        'clienttransferprohibited', 'servertransferprohibited',
    ];

    /** @var ProviderRegistry|null */
    private $registry;
    /** @var JobQueue|null */
    private $queue;
    /** @var WhoisService|null */
    private $whois;

    public function __construct(ProviderRegistry $registry = null, JobQueue $queue = null, WhoisService $whois = null)
    {
        $this->registry = $registry;
        $this->queue = $queue;
        $this->whois = $whois;
    }

    protected function registry()
    {
        return $this->registry ?: $this->registry = new ProviderRegistry();
    }

    protected function queue()
    {
        return $this->queue ?: $this->queue = new JobQueue();
    }

    protected function whois()
    {
        return $this->whois ?: $this->whois = new WhoisService();
    }

    /* ------------------------------------------------------- eligibility -- */

    /**
     * Pre-check: is this domain transferable to us right now?
     *
     * @param string      $domainInput
     * @param string|null $eppInput   auth code (syntax-checked when provided)
     * @return array{eligible:?bool, reasons:array, whois:array|null, quote:array|null}
     *         eligible=null when the answer is unknown (e.g. WHOIS unavailable)
     */
    public function checkEligibility($domainInput, $eppInput = null, $clientId = null)
    {
        $domain = DomainName::parse($domainInput);
        $reasons = [];

        if ($eppInput !== null && $eppInput !== '') {
            $this->validateEpp($eppInput); // throws ValidationException
        }

        $catalog = new TldCatalogService();
        $tldRow = $catalog->detail($domain->tld());
        if (!$tldRow) {
            return [
                'eligible' => false,
                'reasons'  => ['We do not sell .' . $domain->tld() . ' — no transfer route exists.'],
                'whois'    => null,
                'quote'    => null,
            ];
        }
        if (empty($tldRow['features']['epp'])) {
            $reasons[] = 'This extension does not support EPP/auth-code transfers.';
        }
        if ($tldRow['transfer_minor'] === null) {
            $reasons[] = 'No transfer price is configured for this extension yet.';
        }

        // Live WHOIS state (honest: unavailable → eligible stays unknown).
        $whois = null;
        $whoisFailed = false;
        try {
            $whois = $this->whois()->lookup($domain->fqdn());
        } catch (\Throwable $e) {
            $whoisFailed = true;
        }
        if ($whois !== null) {
            $statuses = [];
            $parsed = isset($whois['parsed']) ? $whois['parsed'] : [];
            foreach ((array) (isset($parsed['statuses']) ? $parsed['statuses'] : []) as $status) {
                // Registry statuses arrive as "state https://..." — the state is
                // the first token.
                $tokens = preg_split('/\s+/', strtolower(trim((string) $status)));
                $statuses[] = (string) $tokens[0];
            }
            foreach ($statuses as $status) {
                if (in_array($status, self::BLOCKING_WHOIS_STATUSES, true)) {
                    $reasons[] = 'The registry reports status "' . $status . '" — the domain must be unlocked and out of redemption first.';
                }
            }
            $created = isset($whois['parsed']['created']) ? $whois['parsed']['created'] : null;
            if ($created) {
                $createdTs = Clock::toTime($created);
                if ($createdTs && (Clock::time() - $createdTs) < 60 * 86400) {
                    $reasons[] = 'The domain was registered less than 60 days ago; ICANN rules block transfers in that window.';
                }
            }
        }

        if ($reasons !== []) {
            $eligible = false;
        } elseif ($whoisFailed) {
            $eligible = null; // could not verify — never guess
        } else {
            $eligible = true;
        }

        return [
            'eligible' => $eligible,
            'reasons'  => $reasons,
            'whois'    => $whois,
            'quote'    => $eligible === false ? null : $this->quote($domain, $clientId),
        ];
    }

    /** Server-side transfer quote for a domain (catalogue price + club discount). */
    public function quote(DomainName $domain, $clientId = null)
    {
        $tldRow = (new TldCatalogService())->detail($domain->tld());
        if (!$tldRow || $tldRow['transfer_minor'] === null) {
            return null;
        }
        $clientId = $clientId === null ? null : (int) $clientId;
        $currency = $clientId
            ? Platform::gateway()->clientCurrency($clientId)
            : Platform::gateway()->defaultCurrency();
        $base = (int) $tldRow['transfer_minor'];
        $final = $base;
        $percent = null;
        if ($clientId) {
            $quote = (new DomainSearchService())->quoteFor($tldRow, $clientId);
            $final = $quote['transfer_final_minor'] === null ? $base : (int) $quote['transfer_final_minor'];
            $percent = $quote['discount_percent'];
        }
        return [
            'domain'         => $domain->fqdn(),
            'tld'            => $domain->tld(),
            'base_minor'     => $base,
            'final_minor'    => $final,
            'discount_percent' => $percent,
            'currency'       => strtoupper(substr((string) $currency, 0, 3)),
            'years'          => 1,
        ];
    }

    /* ------------------------------------------------------------- create -- */

    /**
     * Start a transfer: validate, price server-side, raise a real invoice.
     *
     * @return array{transfer:array, invoice_id:int, url:string}
     */
    public function create($clientId, $domainInput, $eppCode)
    {
        if (!Settings::bool('transfer_enabled', true)) {
            throw new ServiceUnavailableException('Domain transfers are temporarily unavailable.');
        }
        $clientId = (int) $clientId;
        if ($clientId <= 0 || !Platform::gateway()->clientExists($clientId)) {
            throw new ForbiddenException('Please sign in to start a transfer.');
        }
        if (\Chs\Core\Identity::isMasquerading()) {
            throw new ForbiddenException('Transfers cannot be started while masquerading as a client.');
        }
        RateLimiter::hitOrFail('domain_transfer', RateLimiter::bucketForCurrentRequest($clientId), 20, 86400);

        $domain = DomainName::parse($domainInput);
        $this->validateEpp($eppCode);

        // Idempotency: an open transfer for the same client+domain is reused.
        $existing = Db::query(
            'SELECT * FROM ' . Db::t('domain_transfers')
            . ' WHERE client_id = ? AND domain = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 1',
            [$clientId, $domain->fqdn(), self::PENDING, self::INITIATED]
        );
        if ($existing) {
            $transfer = $existing[0];
            $status = Platform::gateway()->invoiceStatus((int) $transfer['invoice_id']);
            if ($status === 'Paid') {
                return [
                    'transfer'   => $transfer,
                    'invoice_id' => (int) $transfer['invoice_id'],
                    'url'        => Platform::gateway()->invoiceUrl((int) $transfer['invoice_id']),
                ];
            }
            if ($status === 'Unpaid') {
                // Rotate the sealed EPP in case the customer re-submits with a new code.
                Db::update('domain_transfers', ['id' => (int) $transfer['id']], [
                    'epp_enc'    => $this->sealEpp($eppCode),
                    'updated_at' => Clock::now(),
                ]);
                return [
                    'transfer'   => Db::first('domain_transfers', ['id' => (int) $transfer['id']]),
                    'invoice_id' => (int) $transfer['invoice_id'],
                    'url'        => Platform::gateway()->invoiceUrl((int) $transfer['invoice_id']),
                ];
            }
            // Cancelled/refunded invoice: expire the record and continue fresh.
            $this->transition((int) $transfer['id'], self::EXPIRED, Audit::ACTOR_SYSTEM, 0, 'Invoice no longer payable; superseded by a new transfer request.');
        }

        $eligibility = $this->checkEligibility($domain->fqdn(), $eppCode, $clientId);
        if ($eligibility['eligible'] === false) {
            throw new ValidationException(['domain' => implode(' ', $eligibility['reasons'])]);
        }

        $quote = $eligibility['quote'];
        if (!$quote) {
            throw new ValidationException(['domain' => 'No transfer price is configured for .' . $domain->tld() . ' yet.']);
        }

        $dueDays = max(1, Settings::int('transfer_invoice_due_days', 7));
        $invoiceId = Platform::gateway()->createInvoice($clientId, [[
            'description' => 'Domain transfer — ' . $domain->fqdn() . ' (1 year, includes renewal)',
            'amount_minor' => (int) $quote['final_minor'],
            'taxed'       => true,
        ]], $quote['currency'], $dueDays, 'Domain transfer order. The transfer is submitted to the registry once this invoice is paid.');

        $registrar = '';
        if (!empty($eligibility['whois']['parsed']['registrar'])) {
            $registrar = substr((string) $eligibility['whois']['parsed']['registrar'], 0, 64);
        }

        $id = Db::insert('domain_transfers', [
            'client_id'     => $clientId,
            'domain'        => $domain->fqdn(),
            'tld'           => $domain->tld(),
            'status'        => self::PENDING,
            'registrar'     => $registrar,
            'epp_enc'       => $this->sealEpp($eppCode),
            'price_minor'   => (int) $quote['final_minor'],
            'currency'      => $quote['currency'],
            'invoice_id'    => $invoiceId,
            'history'       => json_encode([$this->historyEntry(Audit::ACTOR_CLIENT, $clientId, null, self::PENDING, 'Transfer requested; invoice issued.')]),
            'requested_at'  => Clock::now(),
            'created_at'    => Clock::now(),
            'updated_at'    => Clock::now(),
        ]);

        $transfer = Db::first('domain_transfers', ['id' => $id]);
        (new DomainEventService())->client(DomainEventService::DOMAIN_TRANSFER_STARTED, $domain->fqdn(), $clientId, [
            'transfer_id' => $id,
            'invoice_id'  => $invoiceId,
            'price_minor' => (int) $quote['final_minor'],
            'currency'    => $quote['currency'],
            'registrar'   => $registrar,
        ]);
        (new NotificationService())->notify($clientId, 'domain_transfer_started',
            'Transfer started for ' . $domain->fqdn(),
            'We issued invoice #' . $invoiceId . ' for the transfer of ' . $domain->fqdn() . ' (1 year). '
            . 'The transfer is submitted to the registry as soon as the invoice is paid.',
            Platform::gateway()->invoiceUrl($invoiceId));

        return [
            'transfer'   => $transfer,
            'invoice_id' => $invoiceId,
            'url'        => Platform::gateway()->invoiceUrl($invoiceId),
        ];
    }

    /* ------------------------------------------------------ invoice hook -- */

    /**
     * InvoicePaid entry point: a paid transfer moves to INITIATED and the
     * provider submission is queued as a job (never inline).
     */
    public function invoicePaid($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        $transfer = Db::first('domain_transfers', ['invoice_id' => $invoiceId]);
        if (!$transfer || $transfer['status'] !== self::PENDING) {
            return false;
        }
        $this->transition((int) $transfer['id'], self::INITIATED, Audit::ACTOR_SYSTEM, 0, 'Invoice paid; provider submission queued.');
        $this->queue()->enqueue(DomainJobTypes::TRANSFER, [
            'transfer_id' => (int) $transfer['id'],
        ], [
            'idempotency_key' => 'domain-transfer:' . (int) $transfer['id'],
            'correlation_id'  => Str::random(12),
            'entity_type'     => 'domain_transfer',
            'entity_id'       => (int) $transfer['id'],
        ]);
        return true;
    }

    /* ------------------------------------------------------ worker steps -- */

    /**
     * DOMAIN_TRANSFER job handler: submit the transfer to the provider, or
     * reconcile against the platform. Honest failure (PROVIDER_OPERATION_
     * UNSUPPORTED / DOMAIN_PROVIDER_NOT_CONFIGURED) leaves the transfer
     * INITIATED with the error recorded — an admin retries from Domain
     * Operations once the provider can actually submit.
     */
    public function executeProviderStep($transferId)
    {
        $transfer = Db::first('domain_transfers', ['id' => (int) $transferId]);
        if (!$transfer) {
            throw new NotFoundException('Transfer not found.');
        }
        if (in_array($transfer['status'], self::FINAL_STATES, true)) {
            return $transfer; // already settled — idempotent no-op
        }

        // Platform reconciliation first: if WHMCS already shows the domain
        // active for this client, the transfer is done — record the truth.
        $reconciled = $this->reconcileOne($transfer);
        if ($reconciled) {
            return Db::first('domain_transfers', ['id' => (int) $transferId]);
        }

        $epp = Secrets::decrypt((string) $transfer['epp_enc']);
        if ($epp === null || $epp === '') {
            $this->transition((int) $transfer['id'], self::AWAITING_AUTH_CODE, Audit::ACTOR_SYSTEM, 0,
                'No usable auth code on file — customer must supply the EPP code.');
            throw new ValidationException(['epp' => 'No usable EPP/auth code is stored for this transfer.']);
        }

        $provider = $this->registry()->forTld($transfer['tld']);
        try {
            $answer = $provider->transferDomain($transfer['domain'], $epp, 1);
        } catch (ProviderException $e) {
            Db::update('domain_transfers', ['id' => (int) $transfer['id']], [
                'error'      => substr($e->getMessage(), 0, 255),
                'updated_at' => Clock::now(),
            ]);
            throw $e; // job fails with machine code; stays visible for admin retry
        }
        $this->transition((int) $transfer['id'], self::PROCESSING, Audit::ACTOR_SYSTEM, 0,
            'Submitted to provider' . (isset($answer['provider_ref']) && $answer['provider_ref'] !== ''
                ? ' (ref ' . $answer['provider_ref'] . ')' : '') . '.', isset($answer['provider_ref']) ? $answer['provider_ref'] : '');
        return Db::first('domain_transfers', ['id' => (int) $transferId]);
    }

    /**
     * Reconcile open transfers against the platform's own domain records:
     * a domain that WHMCS shows active for the client means the transfer
     * completed (by WHMCS' pipeline or by staff). Returns the number updated.
     */
    public function syncFromPlatform()
    {
        $count = 0;
        $open = Db::query(
            'SELECT * FROM ' . Db::t('domain_transfers')
            . ' WHERE status IN (?, ?, ?, ?)',
            [self::PENDING, self::INITIATED, self::AWAITING_AUTH_CODE, self::PROCESSING]
        );
        foreach ($open as $transfer) {
            if ($this->reconcileOne($transfer)) {
                $count++;
            }
        }
        return $count;
    }

    /* --------------------------------------------------------- customer UI -- */

    /** @return array[] */
    public function listFor($clientId)
    {
        return Db::all('domain_transfers', ['client_id' => (int) $clientId], 'id DESC');
    }

    /** Ownership-checked detail (IDOR-safe). */
    public function getFor($clientId, $transferId)
    {
        $transfer = Db::first('domain_transfers', ['id' => (int) $transferId]);
        if (!$transfer || (int) $transfer['client_id'] !== (int) $clientId) {
            throw new NotFoundException('Transfer not found.');
        }
        return $this->viewModel($transfer);
    }

    public function cancel($clientId, $transferId)
    {
        $transfer = Db::first('domain_transfers', ['id' => (int) $transferId, 'client_id' => (int) $clientId]);
        if (!$transfer) {
            throw new NotFoundException('Transfer not found.');
        }
        if (in_array($transfer['status'], self::FINAL_STATES, true)) {
            throw new InvalidTransitionException('This transfer is already ' . strtolower($transfer['status']) . ' and cannot be cancelled.');
        }
        Db::transaction(function () use ($transfer) {
            $this->transition((int) $transfer['id'], self::CANCELLED, Audit::ACTOR_CLIENT, (int) $transfer['client_id'], 'Cancelled by the customer.');
            if ($transfer['invoice_id']) {
                $status = Platform::gateway()->invoiceStatus((int) $transfer['invoice_id']);
                if ($status === 'Unpaid') {
                    Platform::gateway()->cancelInvoice((int) $transfer['invoice_id']);
                }
            }
        });
        (new DomainEventService())->client(DomainEventService::DOMAIN_TRANSFER_FAILED, $transfer['domain'], (int) $clientId, [
            'transfer_id' => (int) $transfer['id'],
            'note'        => 'cancelled by customer',
        ]);
        return true;
    }

    /* ---------------------------------------------------------- admin ops -- */

    /** @return array[] */
    public function adminList($status = '', $limit = 100)
    {
        if ($status !== '') {
            return Db::all('domain_transfers', ['status' => $status], 'id DESC', (int) $limit);
        }
        return Db::all('domain_transfers', [], 'id DESC', (int) $limit);
    }

    public function adminGet($transferId)
    {
        $transfer = Db::first('domain_transfers', ['id' => (int) $transferId]);
        if (!$transfer) {
            throw new NotFoundException('Transfer not found.');
        }
        return $this->viewModel($transfer);
    }

    /** Admin retry: re-queue the provider submission job (idempotent). */
    public function adminRetry($transferId, $adminId)
    {
        $transfer = Db::first('domain_transfers', ['id' => (int) $transferId]);
        if (!$transfer) {
            throw new NotFoundException('Transfer not found.');
        }
        if (in_array($transfer['status'], self::FINAL_STATES, true)) {
            throw new InvalidTransitionException('A ' . strtolower($transfer['status']) . ' transfer cannot be retried.');
        }
        $this->queue()->enqueue(DomainJobTypes::TRANSFER, [
            'transfer_id' => (int) $transfer['id'],
        ], [
            'idempotency_key' => 'domain-transfer:' . (int) $transfer['id'],
            'correlation_id'  => Str::random(12),
            'entity_type'     => 'domain_transfer',
            'entity_id'       => (int) $transfer['id'],
        ]);
        $this->appendHistory((int) $transfer['id'], $this->historyEntry(Audit::ACTOR_ADMIN, (int) $adminId, null, $transfer['status'], 'Provider submission re-queued by admin.'));
        (new DomainEventService())->admin(DomainEventService::DOMAIN_ADMIN_UPDATED, $transfer['domain'], (int) $adminId, [
            'transfer_id' => (int) $transfer['id'],
            'action'      => 'retry_submission',
        ]);
        return true;
    }

    /** Admin marks the transfer submitted in WHMCS (or at the provider). */
    public function adminMarkSubmitted($transferId, $adminId, $providerRef = '')
    {
        $transfer = Db::first('domain_transfers', ['id' => (int) $transferId]);
        if (!$transfer) {
            throw new NotFoundException('Transfer not found.');
        }
        if (in_array($transfer['status'], self::FINAL_STATES, true)) {
            throw new InvalidTransitionException('A ' . strtolower($transfer['status']) . ' transfer cannot be updated.');
        }
        $this->transition((int) $transfer['id'], self::PROCESSING, Audit::ACTOR_ADMIN, (int) $adminId,
            'Marked as submitted' . ($providerRef !== '' ? ' (ref ' . $providerRef . ')' : '') . ' by admin.', $providerRef);
        (new DomainEventService())->admin(DomainEventService::DOMAIN_ADMIN_UPDATED, $transfer['domain'], (int) $adminId, [
            'transfer_id' => (int) $transfer['id'],
            'action'      => 'mark_submitted',
        ]);
        return true;
    }

    /** Admin marks the transfer completed (domain is with us). */
    public function adminMarkCompleted($transferId, $adminId, $note = '')
    {
        $transfer = Db::first('domain_transfers', ['id' => (int) $transferId]);
        if (!$transfer) {
            throw new NotFoundException('Transfer not found.');
        }
        if (in_array($transfer['status'], self::FINAL_STATES, true)) {
            throw new InvalidTransitionException('A ' . strtolower($transfer['status']) . ' transfer cannot be updated.');
        }
        $this->transition((int) $transfer['id'], self::COMPLETED, Audit::ACTOR_ADMIN, (int) $adminId,
            $note !== '' ? $note : 'Marked completed by admin.');
        $this->completeTransfer((int) $transfer['id']);
        (new DomainEventService())->admin(DomainEventService::DOMAIN_ADMIN_UPDATED, $transfer['domain'], (int) $adminId, [
            'transfer_id' => (int) $transfer['id'],
            'action'      => 'mark_completed',
        ]);
        return true;
    }

    public static function statuses()
    {
        return [
            self::PENDING, self::INITIATED, self::AWAITING_AUTH_CODE, self::PROCESSING,
            self::PENDING_REGISTRY, self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED,
        ];
    }

    /* ------------------------------------------------------------ internals -- */

    /** Validate EPP/auth code syntax (never stored raw). */
    private function validateEpp($epp)
    {
        $epp = (string) $epp;
        if (trim($epp) === '') {
            throw new ValidationException(['epp' => 'The EPP/auth code from your current registrar is required.']);
        }
        if (strlen($epp) > 64) {
            throw new ValidationException(['epp' => 'The EPP/auth code is too long (max 64 characters).']);
        }
        if (preg_match('/[\x00-\x20\x7f]/', $epp) === 1) {
            throw new ValidationException(['epp' => 'The EPP/auth code contains invalid characters.']);
        }
    }

    /** Seal the EPP code; fails closed when no credential key is configured. */
    private function sealEpp($epp)
    {
        return Secrets::encrypt((string) $epp);
    }

    private function reconcileOne(array $transfer)
    {
        try {
            $domains = Platform::gateway()->clientDomains((int) $transfer['client_id']);
        } catch (\Throwable $e) {
            return false;
        }
        foreach ($domains as $row) {
            if (strtolower((string) $row['domain']) !== strtolower((string) $transfer['domain'])) {
                continue;
            }
            if (strtolower((string) $row['status']) === 'active') {
                $this->transition((int) $transfer['id'], self::COMPLETED, Audit::ACTOR_SYSTEM, 0,
                    'Platform reconciliation: the domain is active in the client account.');
                $this->completeTransfer((int) $transfer['id']);
                return true;
            }
        }
        return false;
    }

    private function completeTransfer($transferId)
    {
        $transfer = Db::first('domain_transfers', ['id' => $transferId]);
        if (!$transfer) {
            return;
        }
        Db::update('domain_transfers', ['id' => $transferId], [
            'completed_at' => Clock::now(),
            'error'        => '',
        ]);
        (new DomainEventService())->system(DomainEventService::DOMAIN_TRANSFER_COMPLETED, $transfer['domain'], [
            'transfer_id' => $transferId,
            'client_id'   => (int) $transfer['client_id'],
        ]);
        (new NotificationService())->notify((int) $transfer['client_id'], 'domain_transfer_completed',
            'Transfer completed for ' . $transfer['domain'],
            'The transfer of ' . $transfer['domain'] . ' to CloudHost247 is complete. Manage the domain from your dashboard.',
            'index.php?m=cloudhost247services&action=domains');
        // Register/refresh the module domain-service row.
        try {
            (new DomainManagementService())->syncFromPlatform();
        } catch (\Throwable $e) {
            \Chs\Core\Logger::warning('Post-transfer domain sync skipped', ['message' => $e->getMessage()]);
        }
    }

    private function transition($transferId, $to, $actorType, $actorId, $note, $providerRef = '')
    {
        $transfer = Db::first('domain_transfers', ['id' => $transferId]);
        if (!$transfer) {
            throw new NotFoundException('Transfer not found.');
        }
        $from = (string) $transfer['status'];
        Db::update('domain_transfers', ['id' => $transferId], [
            'status'       => $to,
            'provider_ref' => $providerRef !== '' ? substr($providerRef, 0, 191) : $transfer['provider_ref'],
            'error'        => in_array($to, [self::COMPLETED, self::CANCELLED], true) ? '' : $transfer['error'],
            'updated_at'   => Clock::now(),
        ]);
        $this->appendHistory($transferId, $this->historyEntry($actorType, $actorId, $from, $to, $note));
    }

    private function appendHistory($transferId, array $entry)
    {
        $transfer = Db::first('domain_transfers', ['id' => $transferId]);
        $history = json_decode((string) $transfer['history'], true);
        $history = is_array($history) ? $history : [];
        $history[] = $entry;
        Db::update('domain_transfers', ['id' => $transferId], [
            'history' => json_encode($history, JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function historyEntry($actorType, $actorId, $from, $to, $note)
    {
        return [
            'at'        => Clock::now(),
            'actor'     => substr((string) $actorType, 0, 16),
            'actor_id'  => (int) $actorId,
            'from'      => $from,
            'to'        => (string) $to,
            'note'      => substr((string) $note, 0, 255),
        ];
    }

    /** Customer-safe view model: never includes the sealed EPP. */
    private function viewModel(array $transfer)
    {
        $history = json_decode((string) $transfer['history'], true);
        return [
            'id'           => (int) $transfer['id'],
            'domain'       => $transfer['domain'],
            'tld'          => $transfer['tld'],
            'status'       => $transfer['status'],
            'registrar'    => $transfer['registrar'],
            'price_minor'  => (int) $transfer['price_minor'],
            'currency'     => $transfer['currency'],
            'invoice_id'   => $transfer['invoice_id'] === null ? null : (int) $transfer['invoice_id'],
            'provider_ref' => $transfer['provider_ref'],
            'error'        => $transfer['error'],
            'requested_at' => $transfer['requested_at'],
            'completed_at' => $transfer['completed_at'],
            'created_at'   => $transfer['created_at'],
            'history'      => is_array($history) ? $history : [],
            'epp_status'   => $transfer['epp_enc'] !== null && $transfer['epp_enc'] !== ''
                ? 'on file (hidden)' : 'missing',
        ];
    }
}
