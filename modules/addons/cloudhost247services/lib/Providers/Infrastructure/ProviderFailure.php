<?php
/**
 * A provider failure with a machine-readable code and a retry classification.
 *
 * The provisioning worker uses the code for the job record (visible in
 * admin → Provisioning) and the retryable flag to decide whether the queue
 * should retry (transient: timeouts, rate limits, provider 5xx) or fail
 * terminally without blind retries (permanent: unconfigured provider,
 * unavailable image, invalid configuration, authentication failure).
 *
 * @package Chs\Providers\Infrastructure
 */

namespace Chs\Providers\Infrastructure;

use Chs\Core\ChsException;

class ProviderFailure extends ChsException
{
    /** @var bool */
    private $retryable;

    public function __construct($message, $code = 'PROVIDER_ERROR', $retryable = true)
    {
        parent::__construct($message);
        $this->machineCode = (string) $code;
        $this->retryable = (bool) $retryable;
    }

    public function isRetryable()
    {
        return $this->retryable;
    }

    /** Transient provider-side failure (timeout, 5xx) — safe to retry. */
    public static function transient($message, $code = 'PROVIDER_ERROR')
    {
        return new self($message, $code, true);
    }

    /** Permanent failure — retrying blindly would only burn attempts. */
    public static function permanent($message, $code)
    {
        return new self($message, $code, false);
    }

    public static function notConfigured($what = 'provider')
    {
        return new self(
            'PROVIDER_NOT_CONFIGURED: no infrastructure ' . $what . ' is configured.',
            'PROVIDER_NOT_CONFIGURED',
            false
        );
    }

    public static function timeout($operation)
    {
        return new self(
            'PROVIDER_TIMEOUT: the provider did not answer ' . $operation . ' in time.',
            'PROVIDER_TIMEOUT',
            true
        );
    }

    public static function imageUnavailable($imageId)
    {
        return new self(
            'IMAGE_UNAVAILABLE: provider image "' . $imageId . '" is not deployable.',
            'IMAGE_UNAVAILABLE',
            false
        );
    }

    public static function invalidConfiguration($message)
    {
        return new self('INVALID_CONFIGURATION: ' . $message, 'INVALID_CONFIGURATION', false);
    }

    public static function insufficientCapacity($message = '')
    {
        return new self(
            'INSUFFICIENT_CAPACITY: the provider cannot allocate this server. ' . $message,
            'INSUFFICIENT_CAPACITY',
            false
        );
    }

    public static function unsupported($operation)
    {
        return new self(
            'PROVIDER_OPERATION_UNSUPPORTED: this provider cannot ' . $operation . '.',
            'PROVIDER_OPERATION_UNSUPPORTED',
            false
        );
    }

    /** Classify an HTTP status from a provider API call. */
    public static function fromHttpStatus($status, $body = '')
    {
        $status = (int) $status;
        $hint = $body !== '' ? ' — ' . substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 160) : '';
        if ($status === 401 || $status === 403) {
            return new self('AUTHENTICATION_FAILED: provider rejected the credentials.' . $hint, 'AUTHENTICATION_FAILED', false);
        }
        if ($status === 404) {
            return new self('PROVIDER_RESOURCE_NOT_FOUND.' . $hint, 'PROVIDER_RESOURCE_NOT_FOUND', false);
        }
        if ($status === 402 || $status === 422) {
            return self::invalidConfiguration('provider rejected the request (HTTP ' . $status . ').' . $hint);
        }
        if ($status === 409) {
            return new self('PROVIDER_CONFLICT: the resource already exists or is in a conflicting state.' . $hint, 'PROVIDER_CONFLICT', false);
        }
        if ($status === 429) {
            return new self('RATE_LIMITED: provider rate limit hit.' . $hint, 'RATE_LIMITED', true);
        }
        if ($status === 503 || $status === 502 || $status === 504) {
            return new self('PROVIDER_UNAVAILABLE: provider returned HTTP ' . $status . '.' . $hint, 'PROVIDER_UNAVAILABLE', true);
        }
        if ($status >= 500) {
            return new self('PROVIDER_ERROR: provider returned HTTP ' . $status . '.' . $hint, 'PROVIDER_ERROR', true);
        }
        if ($status >= 400) {
            return new self('PROVIDER_ERROR: provider returned HTTP ' . $status . '.' . $hint, 'PROVIDER_ERROR', false);
        }
        return new self('PROVIDER_ERROR: unexpected provider response (HTTP ' . $status . ').' . $hint, 'PROVIDER_ERROR', true);
    }
}
