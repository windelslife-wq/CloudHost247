<?php
/** Linux host kernel uptime from /proc. No service-health inference. */
namespace Ch247Agent;

class UptimeReader
{
    public static function read()
    {
        // /proc/uptime is a kernel pseudo-file (often zero bytes according to
        // filesize()). Read a bounded number of bytes instead of trusting size.
        $raw = @file_get_contents('/proc/uptime', false, null, 0, 128);
        if ($raw === false) {
            throw new \RuntimeException('Linux host uptime is unavailable.');
        }
        return self::parse($raw);
    }

    public static function parse($raw)
    {
        if (!is_string($raw) || strlen($raw) > 128
            || !preg_match('/^([0-9]+)(?:\.[0-9]+)?[ \t]+[0-9]+(?:\.[0-9]+)?\s*$/D', $raw, $match)) {
            throw new \RuntimeException('Linux host uptime is invalid.');
        }
        $seconds = ltrim($match[1], '0');
        $seconds = $seconds === '' ? '0' : $seconds;
        if (strlen($seconds) > 10 || (strlen($seconds) === 10 && strcmp($seconds, '2147483647') > 0)) {
            throw new \RuntimeException('Linux host uptime exceeds the supported range.');
        }
        return (int) $seconds;
    }
}
