<?php
/**
 * CloudHost247 Tools - Rate limiter and CSRF guard.
 *
 * Every network-dependent tool is rate limited per client IP. A database
 * backed counter is used when WHMCS is available; otherwise the limiter
 * degrades to an APCu/file counter so it still works in isolation.
 *
 * @package CloudHost247\Tools
 */

if (!defined('WHMCS') && !defined('CLOUDHOST247_TOOLS')) {
    die('This file cannot be accessed directly');
}

class CloudHost247ToolsRateLimitException extends \Exception
{
    /** @var int Seconds until the caller may retry. */
    public $retryAfter = 60;

    public function __construct($message, $retryAfter = 60)
    {
        parent::__construct($message);
        $this->retryAfter = (int) $retryAfter;
    }
}

class CloudHost247ToolsRateLimiter
{
    /** Default allowance per window, by execution class. */
    const DEFAULTS = [
        'client' => 0,   // 0 = unlimited; nothing leaves the browser.
        'hybrid' => 60,
        'server' => 30,
        'heavy'  => 10,  // traceroute, ping, blacklist, broken links, OCR
    ];

    /** Window length in seconds. */
    const WINDOW = 60;

    /** Tools whose server cost justifies the "heavy" bucket. */
    const HEAVY_TOOLS = [
        'traceroute', 'ping', 'dns-propagation-checker', 'blacklist-check',
        'broken-links-checker', 'image-to-text', 'website-crawl-test',
        'port-checker', 'smtp-test', 'page-rank', 'website-status',
        'reverse-image-search', 'speed-test',
    ];

    /** Global ceiling per IP across all tools, per window. */
    const GLOBAL_LIMIT = 120;

    /**
     * Resolve the allowance for a tool record from the catalog.
     */
    public static function limitFor(array $tool)
    {
        if (!empty($tool['rate_limit'])) {
            return (int) $tool['rate_limit'];
        }
        if (in_array($tool['slug'], self::HEAVY_TOOLS, true)) {
            return self::DEFAULTS['heavy'];
        }
        $exec = isset($tool['exec']) ? $tool['exec'] : 'server';

        return isset(self::DEFAULTS[$exec]) ? self::DEFAULTS[$exec] : self::DEFAULTS['server'];
    }

    /**
     * Consume one unit of quota for this tool, or throw.
     *
     * @throws CloudHost247ToolsRateLimitException
     */
    public static function check(array $tool, $ip = null)
    {
        $limit = self::limitFor($tool);
        if ($limit <= 0) {
            return ['limit' => 0, 'remaining' => 0, 'unlimited' => true];
        }

        $ip = $ip ?: CloudHost247ToolsSecurity::clientIp();

        // Global ceiling first, so one IP cannot spread abuse across tools.
        $globalUsed = self::hit('global:' . $ip);
        if ($globalUsed > self::GLOBAL_LIMIT) {
            CloudHost247ToolsSecurity::logSecurityEvent('rate_limit_global', $tool['slug'], $ip);
            throw new CloudHost247ToolsRateLimitException(
                'You have made too many tool requests in a short period. Please wait a minute and try again.',
                self::WINDOW
            );
        }

        $used = self::hit($tool['slug'] . ':' . $ip);
        if ($used > $limit) {
            CloudHost247ToolsSecurity::logSecurityEvent('rate_limit', $tool['slug'], $ip);
            throw new CloudHost247ToolsRateLimitException(
                'Rate limit reached for ' . $tool['name'] . ' (' . $limit . ' requests per minute). Please wait a moment.',
                self::WINDOW
            );
        }

        return [
            'limit'     => $limit,
            'remaining' => max(0, $limit - $used),
            'unlimited' => false,
        ];
    }

    /**
     * Increment and return the count for a key within the current window.
     */
    protected static function hit($key)
    {
        $bucket = (int) floor(time() / self::WINDOW);
        $key    = 'ch247tools:' . $bucket . ':' . $key;

        if (function_exists('apcu_inc') && function_exists('apcu_enabled') && @apcu_enabled()) {
            $ok    = false;
            $count = apcu_inc($key, 1, $ok, self::WINDOW * 2);
            if ($ok !== false) {
                return (int) $count;
            }
        }

        if (class_exists('\Illuminate\Database\Capsule\Manager')) {
            try {
                $db = \Illuminate\Database\Capsule\Manager::class;
                $db::table('mod_CloudHost247_tools_ratelimit')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
                $row = $db::table('mod_CloudHost247_tools_ratelimit')->where('bucket_key', $key)->first();
                if ($row) {
                    $count = (int) $row->hits + 1;
                    $db::table('mod_CloudHost247_tools_ratelimit')->where('bucket_key', $key)->update(['hits' => $count]);
                } else {
                    $count = 1;
                    $db::table('mod_CloudHost247_tools_ratelimit')->insert([
                        'bucket_key' => $key,
                        'hits'       => 1,
                        'expires_at' => date('Y-m-d H:i:s', time() + self::WINDOW * 2),
                    ]);
                }

                return $count;
            } catch (\Exception $e) {
                // Fall through to the file counter.
            }
        }

        return self::fileHit($key);
    }

    protected static function fileHit($key)
    {
        $dir = sys_get_temp_dir() . '/ch247tools-rl';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . sha1($key) . '.cnt';

        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return 1;
        }
        @flock($fh, LOCK_EX);
        $count = (int) stream_get_contents($fh) + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $count);
        @flock($fh, LOCK_UN);
        fclose($fh);

        // Opportunistic cleanup of stale buckets.
        if (mt_rand(1, 50) === 1) {
            foreach ((array) glob($dir . '/*.cnt') as $old) {
                if (@filemtime($old) < time() - self::WINDOW * 4) {
                    @unlink($old);
                }
            }
        }

        return $count;
    }

    // -----------------------------------------------------------------
    //  CSRF
    // -----------------------------------------------------------------

    /**
     * Issue (and memoise in the session) a CSRF token for tool requests.
     */
    public static function token()
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (empty($_SESSION['ch247_tools_csrf'])) {
            $_SESSION['ch247_tools_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['ch247_tools_csrf'];
    }

    /**
     * Validate a submitted CSRF token in constant time.
     */
    public static function validateToken($submitted)
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $expected = isset($_SESSION['ch247_tools_csrf']) ? $_SESSION['ch247_tools_csrf'] : '';

        if ($expected === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }
}
