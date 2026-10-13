<?php
namespace CloudHost247\SoYouStart\Cron;

/** Shared safeguards and pure cursor arithmetic for scheduled SoYouStart jobs. */
final class CronGuard
{
    public static function isCliSapi($sapi)
    {
        return is_string($sapi) && $sapi === 'cli';
    }

    public static function requireCli()
    {
        if (self::isCliSapi(PHP_SAPI)) {
            return;
        }

        if (!headers_sent()) {
            if (function_exists('http_response_code')) {
                http_response_code(404);
            }
            header('Content-Type: text/plain; charset=UTF-8');
        }

        exit('Not Found');
    }
}

/**
 * Cursor helpers for priceSync's bounded product batches.
 * These functions intentionally have no WHMCS or provider dependencies.
 */
final class AdditionalIpQuantity
{
    /** Returns an integer from 1 through 256, or false for any invalid input. */
    public static function parse($value)
    {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }

        return filter_var((string) $value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 256],
        ]);
    }
}

final class PriceSyncBatch
{
    public static function normalizeOffset($offset, $total)
    {
        $total = max(0, (int) $total);
        $offset = max(0, (int) $offset);

        if ($total === 0 || $offset >= $total) {
            return 0;
        }

        return $offset;
    }

    public static function nextOffset($offset, $total, $batchSize = 10)
    {
        $total = max(0, (int) $total);
        $batchSize = (int) $batchSize;
        if ($total === 0 || $batchSize < 1) {
            return 0;
        }

        $offset = self::normalizeOffset($offset, $total);
        return min($offset + $batchSize, $total);
    }
}

/**
 * Single-flight file lock for scheduled SoYouStart cron jobs (finding CR-O5).
 * Prevents overlapping runs from racing the priceSync cursor, double-ordering
 * additional IPs, or sending duplicate notification emails.
 */
final class CronLock
{
    /** @var array<int,string> resource id => lock path */
    private static $heldByResource = [];
    /** @var array<string,bool> lock path => true */
    private static $heldPaths = [];

    /**
     * Acquire a non-blocking exclusive lock for $jobName.
     *
     * @param string      $jobName
     * @param string|null $lockDir
     * @return resource|null Lock handle on success, or null if another run holds the lock.
     */
    public static function acquire($jobName, $lockDir = null)
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $jobName);
        if ($safe === '') {
            throw new \InvalidArgumentException('Invalid cron job lock name.');
        }
        $dir = is_string($lockDir) && $lockDir !== '' ? rtrim($lockDir, '/\\') : rtrim(sys_get_temp_dir(), '/\\');
        $path = $dir . '/ch247_soyoustart_' . $safe . '.lock';
        if (!empty(self::$heldPaths[$path])) {
            return null;
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return null;
        }
        @ftruncate($handle, 0);
        @fwrite($handle, (string) getmypid() . "\n");
        @fflush($handle);
        self::$heldPaths[$path] = true;
        self::$heldByResource[(int) $handle] = $path;
        return $handle;
    }

    /**
     * Release a lock previously acquired via acquire().
     *
     * @param resource|null $handle
     * @return void
     */
    public static function release($handle)
    {
        if (is_resource($handle)) {
            $id = (int) $handle;
            if (isset(self::$heldByResource[$id])) {
                $path = self::$heldByResource[$id];
                unset(self::$heldByResource[$id], self::$heldPaths[$path]);
            }
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}

/**
 * Dual-read / encrypted-at-rest helper for mod_soyoustart_imap credentials.
 * Supports WHMCS encrypt()/decrypt() under enc:v1:, legacy AES-256-CBC
 * (encryptionKey1234567891234567), and legacy plaintext rows.
 */
final class ImapCredential
{
    const ENCRYPT_PREFIX = 'enc:v1:';
    const LEGACY_KEY = 'encryptionKey1234567891234567';
    const LEGACY_IV = '1234567891011121';

    public static function encrypt($plaintext)
    {
        $plaintext = (string) $plaintext;
        if ($plaintext === '') {
            return '';
        }
        if (function_exists('encrypt')) {
            return self::ENCRYPT_PREFIX . base64_encode((string) encrypt($plaintext));
        }
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', self::LEGACY_KEY, OPENSSL_RAW_DATA, self::LEGACY_IV);
        return $cipher !== false ? base64_encode($cipher) : $plaintext;
    }

    public static function decrypt($stored)
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return '';
        }
        if (strpos($stored, self::ENCRYPT_PREFIX) === 0) {
            $b64 = substr($stored, strlen(self::ENCRYPT_PREFIX));
            $decoded = base64_decode($b64, true);
            if ($decoded !== false && function_exists('decrypt')) {
                $plain = decrypt($decoded);
                if (is_string($plain) && $plain !== '') {
                    return $plain;
                }
            }
            return is_string($decoded) ? $decoded : '';
        }
        $raw = base64_decode($stored, true);
        if (is_string($raw) && $raw !== '' && (strlen($raw) % 16 === 0)) {
            $legacy = openssl_decrypt($raw, 'AES-256-CBC', self::LEGACY_KEY, OPENSSL_RAW_DATA, self::LEGACY_IV);
            if (is_string($legacy) && $legacy !== '') {
                return $legacy;
            }
        }
        $legacyDirect = openssl_decrypt($stored, 'AES-256-CBC', self::LEGACY_KEY, 0, self::LEGACY_IV);
        if (is_string($legacyDirect) && $legacyDirect !== '') {
            return $legacyDirect;
        }
        return $stored;
    }
}

/**
 * Pure helpers for getServer.php order config parsing and payment verification (finding CR-O1).
 */
final class OrderPaymentJudge
{
    /**
     * Extract the right-hand or left-hand segment of a "code|label" product configoption.
     */
    public static function splitConfigOption($raw, $index = 0)
    {
        $parts = explode('|', (string) $raw);
        if ($index === 1 && count($parts) < 2) {
            return trim($parts[0]);
        }
        return isset($parts[$index]) ? trim($parts[$index]) : '';
    }

    /**
     * Evaluate ApiCall::getpaymentdetail() return value.
     * ApiCall::getpaymentdetail() returns:
     *  - "success" when auto-pay is enabled,
     *  - ['httpcode' => 200, 'result' => ...] when payWithRegisteredPaymentMean succeeds,
     *  - an error string (e.g. "Error description: ..." or "Please add default payment method first.") on failure.
     */
    public static function isPaymentSucceeded($paymentResponse)
    {
        if ($paymentResponse === 'success') {
            return true;
        }
        if (is_array($paymentResponse)) {
            $httpCode = isset($paymentResponse['httpcode']) ? (int) $paymentResponse['httpcode'] : 0;
            $hasErrorMessage = isset($paymentResponse['result']) && is_object($paymentResponse['result']) && isset($paymentResponse['result']->message);
            return $httpCode === 200 && !$hasErrorMessage;
        }
        return false;
    }
}

/**
 * Pure helper for getIpStatus.php destination verification (finding CR-O2).
 */
final class IpDestinationValidator
{
    /**
     * Return the matched serviceName from /ip/{ip}/move eligible destinations
     * (across both vps and dedicatedServer lists), or null if not eligible.
     */
    public static function findEligibleService($destinationsResponse, $targetServiceName)
    {
        $target = trim((string) $targetServiceName);
        if ($target === '' || !is_array($destinationsResponse) || !isset($destinationsResponse['result']) || !is_object($destinationsResponse['result'])) {
            return null;
        }
        $result = $destinationsResponse['result'];
        foreach (['vps', 'dedicatedServer'] as $bucket) {
            if (!isset($result->{$bucket}) || !is_array($result->{$bucket})) {
                continue;
            }
            foreach ($result->{$bucket} as $entry) {
                $serviceName = is_object($entry) && isset($entry->serviceName)
                    ? (string) $entry->serviceName
                    : (is_array($entry) && isset($entry['serviceName']) ? (string) $entry['serviceName'] : '');
                if ($serviceName !== '' && $serviceName === $target) {
                    return $serviceName;
                }
            }
        }
        return null;
    }
}
