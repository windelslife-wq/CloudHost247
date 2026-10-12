<?php
/**
 * File-backed rate limiter with a locked read/modify/write cycle.
 * Limits requests per authenticated client ID or, for the shared API key, IP.
 */

class RateLimiter {
    private $config;
    private $file;
    const MAX_STORE_BYTES = 10485760;

    public function __construct($config) {
        $this->config = is_array($config) ? $config : [];
        $cachePath = isset($this->config['cache_path']) && is_string($this->config['cache_path'])
            ? rtrim($this->config['cache_path'], '/\\')
            : '';
        $this->file = $cachePath === '' ? '' : $cachePath . DIRECTORY_SEPARATOR . 'rate-limits.json';
    }

    /** Atomically add a request and evaluate all configured rolling windows. */
    public function check($clientId, $ip, $category, $action) {
        $limits = isset($this->config['rate_limit']) && is_array($this->config['rate_limit'])
            ? $this->config['rate_limit']
            : [];
        if (empty($limits['enabled'])) {
            return true;
        }

        $handle = $this->openLockedFile(LOCK_EX);
        if ($handle === false) {
            return false;
        }
        $valid = false;
        $data = $this->readData($handle, $valid);
        if (!$valid) {
            $this->closeLockedFile($handle);
            return false;
        }

        $now = time();
        $identifier = (int) $clientId > 0 ? 'client_' . (int) $clientId : 'ip_' . md5((string) $ip);
        if (!isset($data[$identifier])) {
            $data[$identifier] = ['requests' => [], 'blocked_until' => 0];
        }
        $bucket = $data[$identifier];
        if ($bucket['blocked_until'] > $now) {
            $this->closeLockedFile($handle);
            return false;
        }

        $bucket['requests'] = array_values(array_filter($bucket['requests'], function ($timestamp) use ($now) {
            return $timestamp > ($now - 86400);
        }));
        $bucket['requests'][] = $now;

        $minuteLimit = max(0, (int) (isset($limits['requests_per_minute']) ? $limits['requests_per_minute'] : 60));
        $hourLimit = max(0, (int) (isset($limits['requests_per_hour']) ? $limits['requests_per_hour'] : 500));
        $dayLimit = max(0, (int) (isset($limits['requests_per_day']) ? $limits['requests_per_day'] : 5000));
        $perMinute = $this->countSince($bucket['requests'], $now - 60);
        $perHour = $this->countSince($bucket['requests'], $now - 3600);
        $perDay = count($bucket['requests']);

        $allowed = true;
        if ($perMinute > $minuteLimit) {
            $bucket['blocked_until'] = $now + 60;
            $allowed = false;
        } elseif ($perHour > $hourLimit) {
            $bucket['blocked_until'] = $now + 3600;
            $allowed = false;
        } elseif ($perDay > $dayLimit) {
            $bucket['blocked_until'] = $now + 86400;
            $allowed = false;
        }
        $data[$identifier] = $bucket;

        $saved = $this->writeData($handle, $data);
        $this->closeLockedFile($handle);
        // Fail closed on storage errors; never silently disable an enabled limit.
        return $saved && $allowed;
    }

    /** Get current rolling-window usage under a shared file lock. */
    public function getUsage($clientId, $ip) {
        $empty = ['per_minute' => 0, 'per_hour' => 0, 'per_day' => 0];
        if (empty($this->config['rate_limit']['enabled'])) {
            return $empty;
        }
        $handle = $this->openLockedFile(LOCK_SH);
        if ($handle === false) {
            return $empty;
        }
        $valid = false;
        $data = $this->readData($handle, $valid);
        $this->closeLockedFile($handle);
        if (!$valid) {
            return $empty;
        }

        $identifier = (int) $clientId > 0 ? 'client_' . (int) $clientId : 'ip_' . md5((string) $ip);
        if (!isset($data[$identifier])) {
            return $empty;
        }
        $now = time();
        $requests = $data[$identifier]['requests'];
        return [
            'per_minute' => $this->countSince($requests, $now - 60),
            'per_hour' => $this->countSince($requests, $now - 3600),
            'per_day' => count(array_filter($requests, function ($timestamp) use ($now) { return $timestamp > $now - 86400; })),
        ];
    }

    private function countSince($timestamps, $cutoff) {
        return count(array_filter($timestamps, function ($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        }));
    }

    /** Open and lock the private rate-limit store. */
    private function openLockedFile($lockType) {
        if ($this->file === '') {
            return false;
        }
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            return false;
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return false;
        }
        @chmod($this->file, 0600);
        if (!@flock($handle, $lockType)) {
            fclose($handle);
            return false;
        }
        return $handle;
    }

    /** Parse and validate storage while the caller holds a lock. */
    private function readData($handle, &$valid) {
        $valid = false;
        rewind($handle);
        $content = stream_get_contents($handle, self::MAX_STORE_BYTES + 1);
        if (!is_string($content) || strlen($content) > self::MAX_STORE_BYTES) {
            return [];
        }
        if (trim($content) === '') {
            $valid = true;
            return [];
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }
        foreach ($decoded as $identifier => $bucket) {
            if (!is_string($identifier) || !preg_match('/^(client_[0-9]+|ip_[a-f0-9]{32})$/D', $identifier)
                || !is_array($bucket) || !isset($bucket['requests'], $bucket['blocked_until'])
                || !is_array($bucket['requests']) || !is_numeric($bucket['blocked_until'])) {
                return [];
            }
            foreach ($bucket['requests'] as $timestamp) {
                if (!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) {
                    return [];
                }
            }
        }
        $valid = true;
        return $decoded;
    }

    /** Replace the store while holding its exclusive lock. */
    private function writeData($handle, $data) {
        $encoded = json_encode($data);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_STORE_BYTES) {
            return false;
        }
        rewind($handle);
        if (!ftruncate($handle, 0)) {
            return false;
        }
        $length = strlen($encoded);
        $written = 0;
        while ($written < $length) {
            $chunk = fwrite($handle, substr($encoded, $written));
            if ($chunk === false || $chunk === 0) {
                return false;
            }
            $written += $chunk;
        }
        return fflush($handle);
    }

    private function closeLockedFile($handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
