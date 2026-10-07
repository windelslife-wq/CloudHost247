<?php
namespace CloudHost247\Cloudflare\Core;
class Clock
{
    public static function now() { return gmdate('Y-m-d H:i:s'); }
    public static function after($seconds) { return gmdate('Y-m-d H:i:s', time() + max(0, (int) $seconds)); }
    public static function before($seconds) { return gmdate('Y-m-d H:i:s', time() - max(0, (int) $seconds)); }
}
