<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Helpers;

/**
 * Fixed-window, file-backed rate limiter for the usage/log proxy.
 *
 * One JSON file per key (named by the SHA-256 of the key) holds the window
 * start and the request count. Each update runs under an exclusive lock, so
 * concurrent requests cannot both pass the limit.
 *
 * If the storage directory or file cannot be used, allow() throws a
 * RuntimeException. The proxy turns that into a 503, so a broken limiter
 * never becomes an unlimited one.
 */
class SmtpUsageRateLimiter
{
    /** @var string */
    private $directory;

    /**
     * @param string $directory writable directory for the counter files
     */
    public function __construct($directory)
    {
        $this->directory = rtrim((string) $directory, DIRECTORY_SEPARATOR);
    }

    /**
     * @param string   $key    e.g. "client:42"
     * @param int      $limit  maximum requests per window
     * @param int      $window window length in seconds
     * @param int|null $now    current Unix time (injectable for tests)
     * @return bool true when the request is allowed (and counted)
     * @throws \RuntimeException when the counter storage is unavailable
     */
    public function allow($key, $limit, $window, $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('rate limiter storage is not available.');
        }

        $file = $this->directory . DIRECTORY_SEPARATOR . hash('sha256', (string) $key) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('rate limiter storage is not writable.');
        }

        try {
            flock($handle, LOCK_EX);

            $raw  = stream_get_contents($handle);
            $data = json_decode((string) $raw, true);
            if (!is_array($data) || !isset($data['ts'], $data['count'])
                || ($now - (int) $data['ts']) >= $window) {
                $data = ['ts' => $now, 'count' => 0];
            }

            if ((int) $data['count'] >= (int) $limit) {
                return false;
            }

            $data['count'] = (int) $data['count'] + 1;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($data));
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
