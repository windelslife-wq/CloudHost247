<?php
/**
 * CloudHost247 App Cloud — the payment gate.
 *
 * The only place in the platform where money turns into provisioning, and the
 * only place that may call InstallationService::markPaid().
 *
 * Three rules, in order:
 *   1. A webhook is recorded before it is interpreted, and processed at most once
 *      (unique provider + provider event id). Replays are answered 200 and do
 *      nothing.
 *   2. The signature is verified with the provider secret configured for this
 *      installation. An unverifiable webhook — including one from a provider with
 *      no secret configured — is stored as `failed` and never provisions
 *      anything. A browser telling us "payment succeeded" is not evidence.
 *   3. Even with a valid signature, the invoice is re-checked against WHMCS
 *      itself (`isInvoicePaid`). Only then is provisioning triggered, and the
 *      `provisioning_triggered` flag on the order link makes it exactly once.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Billing;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\PaymentException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\InstallationService;
use Ch247Apps\Integration\Gateway;
use Ch247Apps\Integration\GatewayInterface;

class PaymentGate
{
    const STATUS_PENDING  = 'pending';
    const STATUS_PAID     = 'paid';
    const STATUS_UNPAID   = 'unpaid';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_CANCELLED = 'cancelled';

    /** Webhook event types that mean "the money arrived". */
    const PAID_EVENT_TYPES = [
        'invoice.paid', 'invoice.payment_succeeded', 'payment_intent.succeeded',
        'checkout.session.completed', 'PAYMENT.SALE.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED',
        'payment.captured', 'payment.completed', 'invoice_paid', 'payment_succeeded',
    ];

    /** Webhook event types that mean "the payment failed or was refused". */
    const FAILED_EVENT_TYPES = [
        'invoice.payment_failed', 'payment_intent.payment_failed', 'payment.failed',
        'PAYMENT.SALE.DENIED', 'payment_failed', 'invoice.payment_action_required',
    ];

    /** @var Actor */
    private $actor;

    /** @var GatewayInterface */
    private $gateway;

    public function __construct(Actor $actor = null, GatewayInterface $gateway = null)
    {
        $this->actor = $actor ?: Actor::system('PaymentGate');
        $this->gateway = $gateway ?: Gateway::get();
    }

    /* ------------------------------------------------------------- webhooks */

    /**
     * Record and process a provider webhook.
     *
     * @param string $provider  stripe|paypal|blockonomics|…
     * @param string $rawBody   the exact bytes received (signatures cover these)
     * @param string $signature the provider signature header
     * @param array  $meta      source_ip, tolerance
     * @return array{recorded: bool, replayed: bool, status: string, provisioned: array}
     */
    public function handleWebhook($provider, $rawBody, $signature, array $meta = [])
    {
        $provider = strtolower(trim((string) $provider));
        if ($provider === '') {
            throw new ValidationException('A payment provider is required.', [
                'errors' => ['provider' => 'Required'],
            ]);
        }
        $rawBody = (string) $rawBody;
        $payload = Str::jsonDecode($rawBody, null);
        if (!is_array($payload)) {
            $payload = ['raw' => Str::clip($rawBody, 4000)];
        }

        $eventId = $this->extractEventId($payload);
        $eventType = $this->extractEventType($payload);
        $invoiceId = (int) $this->extractInvoiceId($payload);

        $now = Clock::now();
        $existing = Db::first('payment_events', [
            'provider' => Str::clip($provider, 40),
            'provider_event_id' => Str::clip((string) $eventId, 191),
        ]);
        if ($existing) {
            // A replay: acknowledge it, do nothing.
            return [
                'recorded' => false, 'replayed' => true,
                'status' => (string) $existing['status'],
                'provisioned' => [],
                'payment_event_id' => (int) $existing['id'],
            ];
        }

        $verification = $this->verifySignature($provider, $rawBody, (string) $signature, $meta);

        $id = Db::insert('payment_events', [
            'provider' => Str::clip($provider, 40),
            'provider_event_id' => Str::clip((string) $eventId, 191),
            'event_type' => Str::clip((string) $eventType, 80),
            'whmcs_invoice_id' => $invoiceId > 0 ? $invoiceId : null,
            'customer_id' => (int) $this->extractCustomerId($payload) ?: null,
            'amount_minor' => $this->extractAmountMinor($payload),
            'currency' => Str::clip((string) $this->extractCurrency($payload), 3) ?: null,
            'signature_status' => $verification['status'],
            'signature_detail' => Str::clip((string) $verification['detail'], 255),
            'status' => 'received',
            'payload' => Str::jsonEncode($payload),
            'source_ip' => isset($meta['source_ip']) ? Str::clip((string) $meta['source_ip'], 45) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($this->actor, Audit::BILLING_EVENT_RECEIVED, [
            'resource_type' => 'payment_event', 'resource_id' => $id,
            'metadata' => ['provider' => $provider, 'event_type' => $eventType,
                'invoice_id' => $invoiceId ?: null, 'signature' => $verification['status']],
            'severity' => $verification['status'] === 'verified' ? 'info' : 'warning',
        ]);

        if ($verification['status'] !== 'verified') {
            Db::update('payment_events', ['status' => 'failed', 'processed_at' => Clock::now(),
                'error_message' => Str::clip((string) $verification['detail'], 1000),
                'updated_at' => Clock::now()], ['id' => $id]);
            Logger::warning('Rejected an unverified payment webhook.', [
                'provider' => $provider, 'event_type' => $eventType, 'invoice_id' => $invoiceId,
                'detail' => $verification['detail'], 'source' => 'billing',
            ]);
            // Still a 200 to the provider (so it stops retrying) but nothing happens.
            return ['recorded' => true, 'replayed' => false, 'status' => 'failed',
                'provisioned' => [], 'payment_event_id' => $id,
                'error_code' => $verification['code']];
        }

        if ($invoiceId <= 0) {
            Db::update('payment_events', ['status' => 'ignored', 'processed_at' => Clock::now(),
                'error_message' => 'No invoice reference in the event payload.',
                'updated_at' => Clock::now()], ['id' => $id]);
            return ['recorded' => true, 'replayed' => false, 'status' => 'ignored',
                'provisioned' => [], 'payment_event_id' => $id];
        }

        if (in_array($eventType, self::FAILED_EVENT_TYPES, true)) {
            Db::update('payment_events', ['status' => 'processed', 'processed_at' => Clock::now(),
                'updated_at' => Clock::now()], ['id' => $id]);
            $this->markUnpaid($invoiceId, $eventType);
            return ['recorded' => true, 'replayed' => false, 'status' => 'processed',
                'provisioned' => [], 'payment_event_id' => $id, 'outcome' => 'payment_failed'];
        }

        if (!in_array($eventType, self::PAID_EVENT_TYPES, true) && !$this->looksPaid($payload)) {
            Db::update('payment_events', ['status' => 'ignored', 'processed_at' => Clock::now(),
                'error_message' => 'Event type "' . $eventType . '" does not confirm a payment.',
                'updated_at' => Clock::now()], ['id' => $id]);
            return ['recorded' => true, 'replayed' => false, 'status' => 'ignored',
                'provisioned' => [], 'payment_event_id' => $id];
        }

        try {
            $result = $this->confirmInvoicePaid($invoiceId, 'webhook:' . $provider, [
                'payment_event_id' => $id,
                'event_type' => $eventType,
                'amount_minor' => $this->extractAmountMinor($payload),
                'payment_reference' => $this->extractReference($payload),
            ]);
            Db::update('payment_events', ['status' => 'processed', 'processed_at' => Clock::now(),
                'updated_at' => Clock::now()], ['id' => $id]);
            return ['recorded' => true, 'replayed' => false, 'status' => 'processed',
                'payment_event_id' => $id] + $result;
        } catch (PaymentException $e) {
            Db::update('payment_events', ['status' => 'failed', 'processed_at' => Clock::now(),
                'error_message' => $e->getMessage(), 'updated_at' => Clock::now()], ['id' => $id]);
            throw $e;
        }
    }

    /**
     * Verify the invoice is really paid (server-side, against WHMCS) and then —
     * exactly once — trigger provisioning of what the customer bought.
     *
     * @throws PaymentException when WHMCS says the invoice is not paid
     */
    public function confirmInvoicePaid($invoiceId, $source = 'manual', array $meta = [])
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            throw new ValidationException('An invoice id is required.', ['errors' => ['invoice' => 'Required']]);
        }

        $paid = false;
        $invoice = null;
        try {
            $paid = (bool) $this->gateway->isInvoicePaid($invoiceId);
            $invoice = $this->gateway->getInvoice($invoiceId);
        } catch (\Throwable $e) {
            Logger::error('Could not verify an invoice with the billing gateway.', [
                'invoice_id' => $invoiceId, 'error' => $e->getMessage(), 'source' => 'billing',
            ]);
        }
        if (!$paid) {
            throw new PaymentException(
                'Payment for invoice ' . $invoiceId . ' is not confirmed. Provisioning was not started.',
                ['error_code' => 'PAYMENT_NOT_CONFIRMED', 'invoice_id' => $invoiceId, 'source' => $source]
            );
        }

        $links = Db::fetch('order_links', ['whmcs_invoice_id' => $invoiceId], ['order' => 'id']);
        $provisioned = [];
        $installations = new InstallationService($this->actor);

        foreach ($links as $link) {
            $installationId = (int) $link['installation_id'];
            if ($installationId <= 0) {
                continue;
            }
            // Exactly once: the CAS on provisioning_triggered is the gate.
            $claimed = Db::compareAndSet('order_links', [
                'provisioning_triggered' => 1,
                'provisioning_triggered_at' => Clock::now(),
                'status' => self::STATUS_PAID,
                'paid_at' => Clock::now(),
                'payment_provider' => Str::clip((string) $source, 60),
                'payment_reference' => isset($meta['payment_reference'])
                    ? Str::clip((string) $meta['payment_reference'], 191) : null,
                'updated_at' => Clock::now(),
            ], ['id' => (int) $link['id'], 'provisioning_triggered' => 0]);
            if (!$claimed) {
                $provisioned[] = ['installation_id' => $installationId, 'already_triggered' => true];
                continue;
            }
            $provisioned[] = ['installation_id' => $installationId]
                + $installations->markPaid($installationId, [
                    'invoice_id' => $invoiceId,
                    'order_id' => $link['whmcs_order_id'] ? (int) $link['whmcs_order_id'] : null,
                    'service_id' => $link['whmcs_service_id'] ? (int) $link['whmcs_service_id'] : null,
                    'amount' => isset($meta['amount_minor']) ? (int) $meta['amount_minor'] : null,
                ]);
        }

        // Installations created without an order link (an admin-created order, or a
        // plan added after the fact) are still released by their invoice id.
        foreach (Db::fetch('installations', ['whmcs_invoice_id' => $invoiceId, 'deleted_at' => null]) as $row) {
            $already = false;
            foreach ($provisioned as $entry) {
                if ((int) $entry['installation_id'] === (int) $row['id']) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }
            if ((string) $row['payment_status'] === 'paid') {
                continue;
            }
            $provisioned[] = ['installation_id' => (int) $row['id']] + $installations->markPaid((int) $row['id'], [
                'invoice_id' => $invoiceId,
                'order_id' => $row['whmcs_order_id'] ? (int) $row['whmcs_order_id'] : null,
                'service_id' => $row['whmcs_service_id'] ? (int) $row['whmcs_service_id'] : null,
            ]);
        }

        if ($invoice !== null && is_array($invoice)) {
            Db::update('order_links', ['status' => self::STATUS_PAID, 'updated_at' => Clock::now()],
                ['whmcs_invoice_id' => $invoiceId]);
        }

        Logger::info('A payment was confirmed server-side.', [
            'invoice_id' => $invoiceId, 'trigger' => $source, 'provisioned' => count($provisioned),
            'client_id' => isset($meta['client_id']) ? (int) $meta['client_id'] : null,
            'source' => 'billing',
        ]);
        Events::emit(Events::PAYMENT_CONFIRMED, [
            'invoice_id' => $invoiceId, 'trigger' => $source, 'installations' => count($provisioned),
        ], isset($meta['client_id']) ? ['client_id' => (int) $meta['client_id']] : []);

        return [
            'invoice_id' => $invoiceId,
            'confirmed' => true,
            'provisioned' => $provisioned,
            'installations' => count($provisioned),
        ];
    }

    /** A failed payment: keep the installation pending and tell the customer. */
    public function markUnpaid($invoiceId, $reason = '')
    {
        $now = Clock::now();
        Db::update('order_links', ['status' => self::STATUS_UNPAID, 'updated_at' => $now],
            ['whmcs_invoice_id' => (int) $invoiceId]);
        Db::update('installations', ['payment_status' => 'unpaid', 'updated_at' => $now],
            ['whmcs_invoice_id' => (int) $invoiceId, 'payment_status' => 'unpaid']);
        Audit::record($this->actor, Audit::BILLING_EVENT_RECEIVED, [
            'resource_type' => 'invoice', 'resource_id' => (int) $invoiceId,
            'metadata' => ['outcome' => 'payment_failed', 'reason' => Str::clip((string) $reason, 120)],
            'severity' => 'warning',
        ]);
        Events::emit(Events::PAYMENT_FAILED, ['invoice_id' => (int) $invoiceId,
            'reason' => Str::clip((string) $reason, 120)], []);
        return true;
    }

    /** Refuse to continue unless the invoice is genuinely paid. */
    public function assertPaid($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        if ($this->gateway->isInvoicePaid($invoiceId)) {
            return true;
        }
        throw new PaymentException('That invoice has not been paid.', [
            'error_code' => 'PAYMENT_NOT_CONFIRMED', 'invoice_id' => $invoiceId,
        ]);
    }

    /* ---------------------------------------------------------- order links */

    /**
     * Record the order that an installation is waiting on.
     *
     * Called by the install wizard when the chosen plan costs money: the row is
     * what `confirmInvoicePaid()` looks for, and its `provisioning_triggered`
     * flag is the exactly-once gate.
     */
    public function linkOrder($installationId, array $info)
    {
        $installation = Db::first('installations', ['id' => (int) $installationId]);
        if (!$installation) {
            throw new NotFoundException('That installation does not exist.');
        }
        $invoiceId = !empty($info['invoice_id']) ? (int) $info['invoice_id'] : null;
        $existing = $invoiceId ? Db::first('order_links', [
            'installation_id' => (int) $installationId, 'whmcs_invoice_id' => $invoiceId,
        ]) : null;
        $now = Clock::now();
        $fields = [
            'installation_id' => (int) $installationId,
            'customer_id' => (int) $installation['customer_id'],
            'whmcs_order_id' => !empty($info['order_id']) ? (int) $info['order_id'] : null,
            'whmcs_invoice_id' => $invoiceId,
            'whmcs_service_id' => !empty($info['service_id']) ? (int) $info['service_id'] : null,
            'plan_id' => $installation['plan_id'] ? (int) $installation['plan_id'] : null,
            'subtotal_minor' => isset($info['subtotal_minor']) ? (int) $info['subtotal_minor'] : null,
            'tax_minor' => isset($info['tax_minor']) ? (int) $info['tax_minor'] : null,
            'discount_minor' => isset($info['discount_minor']) ? (int) $info['discount_minor'] : null,
            'total_minor' => isset($info['total_minor']) ? (int) $info['total_minor'] : null,
            'currency' => Str::clip(isset($info['currency']) ? (string) $info['currency'] : 'USD', 3),
            'status' => isset($info['status']) ? Str::clip((string) $info['status'], 20) : self::STATUS_PENDING,
            'updated_at' => $now,
        ];
        if ($existing) {
            Db::update('order_links', $fields, ['id' => (int) $existing['id']]);
            return $this->presentLink(Db::first('order_links', ['id' => (int) $existing['id']]));
        }
        $fields['created_at'] = $now;
        $id = Db::insert('order_links', $fields);
        return $this->presentLink($this->linkRow($id));
    }

    /** @throws NotFoundException */
    public function linkRow($linkId)
    {
        $row = Db::first('order_links', ['id' => (int) $linkId]);
        if (!$row) {
            throw new NotFoundException('That order link does not exist.');
        }
        return $row;
    }

    public function forInstallation($installationId)
    {
        $out = [];
        foreach (Db::fetch('order_links', ['installation_id' => (int) $installationId], ['order' => 'id']) as $row) {
            $out[] = $this->presentLink($row);
        }
        return $out;
    }

    /** Orders a customer still has to pay for. */
    public function pendingForCustomer($customerId)
    {
        $out = [];
        foreach (Db::fetch('order_links', ['customer_id' => (int) $customerId,
            'status' => ['in', [self::STATUS_PENDING, self::STATUS_UNPAID]]], ['order' => 'id']) as $row) {
            $out[] = $this->presentLink($row);
        }
        return $out;
    }

    public function presentLink(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'installation_id' => (int) $row['installation_id'],
            'customer_id' => (int) $row['customer_id'],
            'whmcs_order_id' => $row['whmcs_order_id'] ? (int) $row['whmcs_order_id'] : null,
            'whmcs_invoice_id' => $row['whmcs_invoice_id'] ? (int) $row['whmcs_invoice_id'] : null,
            'whmcs_service_id' => $row['whmcs_service_id'] ? (int) $row['whmcs_service_id'] : null,
            'plan_id' => $row['plan_id'] ? (int) $row['plan_id'] : null,
            'total_minor' => $row['total_minor'] !== null ? (int) $row['total_minor'] : null,
            'currency' => $row['currency'],
            'status' => $row['status'],
            'paid_at' => isset($row['paid_at']) ? $row['paid_at'] : null,
            'provisioning_triggered' => (bool) $row['provisioning_triggered'],
            'provisioning_triggered_at' => isset($row['provisioning_triggered_at'])
                ? $row['provisioning_triggered_at'] : null,
            'created_at' => $row['created_at'],
        ];
    }

    /** Recent webhook activity (admin view). Raw payloads are stored, not shown. */
    public function recentEvents($limit = 50, $provider = null)
    {
        Rbac::assert($this->actor, Rbac::BILLING_VIEW_ALL);
        $where = [];
        if ($provider !== null && $provider !== '') {
            $where['provider'] = Str::clip((string) $provider, 40);
        }
        $out = [];
        foreach (Db::fetch('payment_events', $where, ['order' => 'id', 'dir' => 'desc',
            'limit' => max(1, min(200, (int) $limit))]) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'provider' => $row['provider'],
                'provider_event_id' => $row['provider_event_id'],
                'event_type' => $row['event_type'],
                'whmcs_invoice_id' => $row['whmcs_invoice_id'] ? (int) $row['whmcs_invoice_id'] : null,
                'amount_minor' => $row['amount_minor'] !== null ? (int) $row['amount_minor'] : null,
                'currency' => isset($row['currency']) ? $row['currency'] : null,
                'signature_status' => $row['signature_status'],
                'status' => $row['status'],
                'error_message' => isset($row['error_message']) ? $row['error_message'] : null,
                'created_at' => $row['created_at'],
                'processed_at' => isset($row['processed_at']) ? $row['processed_at'] : null,
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Verify a provider signature.
     *
     * Supports the two shapes the platform actually uses: Stripe-style
     * `t=<unix>,v1=<hex hmac>` over "<t>.<body>", and a bare hex/base64 HMAC over
     * the body (PayPal, Blockonomics, custom providers). Constant-time compare.
     *
     * @return array{status: string, detail: string, code: string|null}
     */
    private function verifySignature($provider, $rawBody, $signature, array $meta)
    {
        $secret = Settings::string('webhook_secret_' . $provider, '');
        if ($secret === '') {
            return ['status' => 'failed', 'code' => 'WEBHOOK_SECRET_NOT_CONFIGURED',
                'detail' => 'No webhook secret is configured for provider "' . $provider
                    . '". Configure webhook_secret_' . $provider . ' before accepting payments.'];
        }
        if (trim($signature) === '') {
            return ['status' => 'failed', 'code' => 'WEBHOOK_SIGNATURE_MISSING',
                'detail' => 'The request carried no signature header.'];
        }

        $tolerance = isset($meta['tolerance']) ? (int) $meta['tolerance']
            : Settings::int('payment_webhook_tolerance_seconds', 300);
        $timestamp = null;
        $candidates = [];
        if (strpos($signature, 't=') !== false) {
            foreach (explode(',', $signature) as $part) {
                $part = trim($part);
                if (strpos($part, 't=') === 0) {
                    $timestamp = (int) substr($part, 2);
                } elseif (strpos($part, 'v1=') === 0) {
                    $candidates[] = substr($part, 3);
                } elseif (strpos($part, 'v0=') === 0) {
                    $candidates[] = substr($part, 3);
                }
            }
            if ($timestamp !== null && abs(Clock::timestamp() - $timestamp) > $tolerance) {
                return ['status' => 'failed', 'code' => 'WEBHOOK_TIMESTAMP_SKEW',
                    'detail' => 'The webhook timestamp is outside the accepted window.'];
            }
            if ($timestamp !== null) {
                $signedPayload = $timestamp . '.' . $rawBody;
            } else {
                $signedPayload = $rawBody;
            }
        } else {
            $candidates[] = $signature;
            $signedPayload = $rawBody;
        }

        $expected = Crypto::hmac($signedPayload, $secret);
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            if (hash_equals($expected, strtolower($candidate))) {
                return ['status' => 'verified', 'detail' => 'HMAC-SHA256 signature matched.', 'code' => null];
            }
            // Some providers send base64 instead of hex.
            $decoded = base64_decode($candidate, true);
            if ($decoded !== false && hash_equals(Crypto::hmacRaw($signedPayload, $secret), $decoded)) {
                return ['status' => 'verified', 'detail' => 'HMAC-SHA256 signature matched (base64).',
                    'code' => null];
            }
        }

        return ['status' => 'failed', 'code' => 'WEBHOOK_SIGNATURE_INVALID',
            'detail' => 'The signature did not match the configured secret.'];
    }

    private function extractEventId(array $payload)
    {
        foreach (['id', 'event_id', 'eventId', 'notification_id', 'txnid', 'txn_id'] as $key) {
            if (!empty($payload[$key])) {
                return (string) $payload[$key];
            }
        }
        // No id at all: derive one so a replay is still detected.
        return 'hash:' . substr(hash('sha256', Str::jsonEncode($payload)), 0, 32);
    }

    private function extractEventType(array $payload)
    {
        foreach (['type', 'event_type', 'eventType', 'event', 'status'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                return (string) $payload[$key];
            }
        }
        return 'unknown';
    }

    private function extractInvoiceId(array $payload)
    {
        foreach (['invoice_id', 'invoiceId', 'invoice', 'whmcs_invoice_id'] as $key) {
            if (!empty($payload[$key]) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }
        // Nested shapes: data.object.invoice (Stripe), resource.invoice (PayPal).
        foreach (['data', 'resource', 'object', 'payload'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $nested = $this->extractInvoiceId($payload[$key]);
                if ($nested > 0) {
                    return $nested;
                }
                if (isset($payload[$key]['object']) && is_array($payload[$key]['object'])) {
                    $nested = $this->extractInvoiceId($payload[$key]['object']);
                    if ($nested > 0) {
                        return $nested;
                    }
                }
            }
        }
        return 0;
    }

    private function extractCustomerId(array $payload)
    {
        foreach (['client_id', 'clientId', 'customer_id', 'userid'] as $key) {
            if (!empty($payload[$key]) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }
        return 0;
    }

    private function extractAmountMinor(array $payload)
    {
        foreach (['amount_minor', 'amount_received', 'amount', 'total'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $value = (float) $payload[$key];
                // Stripe sends minor units in `amount*`; `total` is a decimal string.
                return $key === 'total' ? (int) round($value * 100) : (int) round($value);
            }
        }
        return null;
    }

    private function extractCurrency(array $payload)
    {
        foreach (['currency', 'currency_code'] as $key) {
            if (!empty($payload[$key])) {
                return strtoupper((string) $payload[$key]);
            }
        }
        return '';
    }

    private function extractReference(array $payload)
    {
        foreach (['payment_reference', 'reference', 'transaction_id', 'id'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                return Str::clip($payload[$key], 191);
            }
        }
        return null;
    }

    private function looksPaid(array $payload)
    {
        $status = strtolower((string) (isset($payload['status']) ? $payload['status'] : ''));
        return in_array($status, ['paid', 'succeeded', 'completed', 'captured'], true);
    }
}
