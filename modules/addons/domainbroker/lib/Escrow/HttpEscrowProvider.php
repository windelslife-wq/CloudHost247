<?php
/**
 * Domain Broker — generic HTTP escrow provider.
 *
 * Talks to a third-party escrow API over HTTPS using a REST shape that most
 * providers can be adapted to with the endpoint/path settings, authenticating
 * with a bearer token read from the environment
 * (DOMAINBROKER_ESCROW_API_KEY). Webhooks are authenticated with an HMAC
 * SHA-256 signature over the raw body plus a timestamp, with a replay window,
 * compared in constant time.
 *
 * Credentials are never read from the database and never logged. If no
 * endpoint or key is configured the provider refuses to operate rather than
 * silently pretending the money moved.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Escrow;

use DomainBroker\Core\Clock;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Logger;
use DomainBroker\Core\Money;
use DomainBroker\Core\Settings;

class HttpEscrowProvider implements EscrowProviderInterface
{
    /** Max clock skew accepted on a signed webhook, in seconds. */
    const WEBHOOK_TOLERANCE = 300;

    public function name()
    {
        return 'http';
    }

    public function label()
    {
        return 'Third-party escrow API (' . ($this->endpoint() ?: 'not configured') . ')';
    }

    public function confirmsSynchronously()
    {
        return false;
    }

    protected function endpoint()
    {
        return rtrim((string) Settings::get('escrow_endpoint', ''), '/');
    }

    protected function apiKey()
    {
        return (string) Settings::get('escrow_api_key', '');
    }

    protected function webhookSecret()
    {
        return (string) Settings::get('escrow_webhook_secret', '');
    }

    public function isConfigured()
    {
        return $this->endpoint() !== '' && $this->apiKey() !== '';
    }

    public function hold(array $context)
    {
        if (!$this->isConfigured()) {
            return EscrowResult::fail('The escrow provider is not configured.');
        }
        $payment = $context['payment'];
        $request = $context['request'];

        $response = $this->call('POST', '/holdings', [
            'external_id' => $payment['reference'],
            'amount'      => Money::toDecimalString($payment['total_minor'], $payment['currency']),
            'currency'    => $payment['currency'],
            'description' => 'Domain acquisition ' . $request['domain'] . ' (' . $request['reference'] . ')',
            'metadata'    => [
                'request_reference' => $request['reference'],
                'invoice_id' => (int) $payment['whmcs_invoice_id'],
            ],
        ], $payment['reference'] . ':hold');

        if ($response === null) {
            return EscrowResult::fail('The escrow provider could not be reached.');
        }
        $reference = isset($response['id']) ? (string) $response['id'] : null;
        $state = $this->mapState(isset($response['status']) ? $response['status'] : '');

        if ($state === EscrowResult::STATE_HELD) {
            return EscrowResult::ok($state, $reference, 'Funds held by the escrow provider.');
        }
        return EscrowResult::deferred($reference, 'Escrow holding created; awaiting confirmation.');
    }

    public function release(array $context)
    {
        if (!$this->isConfigured()) {
            return EscrowResult::fail('The escrow provider is not configured.');
        }
        if (empty($context['transfer_verified'])) {
            return EscrowResult::fail('Funds cannot be released before the transfer is verified.');
        }
        $payment = $context['payment'];
        $reference = Crypto::tryDecrypt($payment['escrow_reference_enc'], 'escrow.reference');
        if (!$reference) {
            return EscrowResult::fail('No escrow holding reference is on file.');
        }

        $response = $this->call('POST', '/holdings/' . rawurlencode($reference) . '/release', [
            'external_id' => $payment['reference'],
        ], $payment['reference'] . ':release');

        if ($response === null) {
            return EscrowResult::fail('The escrow provider could not be reached.');
        }
        $state = $this->mapState(isset($response['status']) ? $response['status'] : '');
        return $state === EscrowResult::STATE_RELEASED
            ? EscrowResult::ok($state, $reference, 'Funds released by the escrow provider.')
            : EscrowResult::deferred($reference, 'Release requested; awaiting provider confirmation.');
    }

    public function refund(array $context, $amountMinor)
    {
        if (!$this->isConfigured()) {
            return EscrowResult::fail('The escrow provider is not configured.');
        }
        $payment = $context['payment'];
        $reference = Crypto::tryDecrypt($payment['escrow_reference_enc'], 'escrow.reference');
        if (!$reference) {
            return EscrowResult::fail('No escrow holding reference is on file.');
        }

        $response = $this->call('POST', '/holdings/' . rawurlencode($reference) . '/refund', [
            'external_id' => $payment['reference'],
            'amount' => Money::toDecimalString($amountMinor, $payment['currency']),
            'currency' => $payment['currency'],
        ], $payment['reference'] . ':refund:' . $amountMinor);

        if ($response === null) {
            return EscrowResult::fail('The escrow provider could not be reached.');
        }
        $state = $this->mapState(isset($response['status']) ? $response['status'] : '');
        return EscrowResult::ok($state ?: EscrowResult::STATE_PARTIALLY_REFUNDED, $reference, 'Refund submitted.');
    }

    public function status(array $context)
    {
        if (!$this->isConfigured()) {
            return EscrowResult::fail('The escrow provider is not configured.');
        }
        $reference = Crypto::tryDecrypt($context['payment']['escrow_reference_enc'], 'escrow.reference');
        if (!$reference) {
            return EscrowResult::fail('No escrow holding reference is on file.');
        }
        $response = $this->call('GET', '/holdings/' . rawurlencode($reference), null, null);
        if ($response === null) {
            return EscrowResult::fail('The escrow provider could not be reached.');
        }
        return EscrowResult::ok(
            $this->mapState(isset($response['status']) ? $response['status'] : ''),
            $reference,
            'Provider state retrieved.'
        );
    }

    /* ---------------------------------------------------------- webhooks */

    public function verifyWebhookSignature($rawBody, array $headers)
    {
        $secret = $this->webhookSecret();
        if ($secret === '') {
            Logger::warning('Escrow webhook rejected: no webhook secret configured.');
            return false;
        }

        $signature = '';
        $timestamp = '';
        foreach ($headers as $key => $value) {
            $lower = strtolower((string) $key);
            if ($lower === 'x-escrow-signature' || $lower === 'x-signature') {
                $signature = (string) $value;
            } elseif ($lower === 'x-escrow-timestamp' || $lower === 'x-timestamp') {
                $timestamp = (string) $value;
            }
        }
        if ($signature === '' || $timestamp === '') {
            return false;
        }
        if (!ctype_digit($timestamp)) {
            return false;
        }
        if (abs(Clock::timestamp() - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            Logger::warning('Escrow webhook rejected: timestamp outside tolerance.');
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . (string) $rawBody, $secret);
        // Accept both bare hex and the "sha256=" prefixed form.
        $candidate = preg_replace('/^sha256=/i', '', trim($signature));

        return hash_equals($expected, $candidate);
    }

    public function parseWebhook(array $payload)
    {
        return [
            'event_id'     => isset($payload['id']) ? (string) $payload['id'] : '',
            'type'         => isset($payload['type']) ? (string) $payload['type'] : '',
            'reference'    => isset($payload['holding_id']) ? (string) $payload['holding_id'] : null,
            'state'        => isset($payload['status']) ? $this->mapState($payload['status']) : null,
            'amount_minor' => isset($payload['amount'], $payload['currency'])
                ? Money::toMinor($payload['amount'], $payload['currency']) : null,
            'external_id'  => isset($payload['external_id']) ? (string) $payload['external_id'] : null,
        ];
    }

    /* ----------------------------------------------------------- client */

    protected function mapState($providerState)
    {
        switch (strtolower((string) $providerState)) {
            case 'held':
            case 'funded':
            case 'secured':
                return EscrowResult::STATE_HELD;
            case 'released':
            case 'disbursed':
            case 'completed':
                return EscrowResult::STATE_RELEASED;
            case 'refunded':
                return EscrowResult::STATE_REFUNDED;
            case 'partially_refunded':
                return EscrowResult::STATE_PARTIALLY_REFUNDED;
            case 'failed':
            case 'cancelled':
                return EscrowResult::STATE_FAILED;
            default:
                return EscrowResult::STATE_PENDING;
        }
    }

    /**
     * @return array|null decoded JSON response, or null on any failure
     */
    protected function call($method, $path, array $body = null, $idempotencyKey = null)
    {
        $url = $this->endpoint() . $path;
        if (strncasecmp($url, 'https://', 8) !== 0) {
            Logger::error('Escrow endpoint must be https.');
            return null;
        }
        if (!function_exists('curl_init')) {
            Logger::error('cURL is unavailable; escrow provider cannot be reached.');
            return null;
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey(),
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: CloudHost247-DomainBroker/' . (defined('DOMAINBROKER_VERSION') ? DOMAINBROKER_VERSION : '1.0.0'),
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . substr(hash('sha256', $idempotencyKey), 0, 48);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('Escrow API transport error', ['error' => $error, 'path' => $path]);
            return null;
        }
        if ($status < 200 || $status >= 300) {
            Logger::error('Escrow API returned an error status', ['status' => $status, 'path' => $path]);
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            Logger::error('Escrow API returned a non-JSON body', ['path' => $path]);
            return null;
        }
        return $decoded;
    }
}
