<?php
namespace CloudHost247\Cloudflare\Service;

use CloudHost247\Cloudflare\Core\Db;
class IntegrationStatus
{
    public static function enabled()
    {
        $override = getenv('CLOUDFLARE_ENABLED');
        if ($override !== false && $override !== '') return in_array(strtolower((string) $override), ['1','yes','on','true'], true);
        // The WHMCS addon master switch is fail-closed when explicitly stored.
        try {
            $row = Db::firstQuery('SELECT value FROM tbladdonmodules WHERE module=? AND setting=? LIMIT 1', ['cloudhost247cloudflare','service_enabled']);
            if ($row !== null) return in_array(strtolower((string) $row['value']), ['1','yes','on','true'], true);
        } catch (\Throwable $e) {}
        try { return Db::count('accounts', ['enabled' => 1]) > 0; } catch (\Throwable $e) { return false; }
    }
    public static function requireEnabled()
    {
        if (!self::enabled()) throw new \CloudHost247\Cloudflare\Core\ConfigurationException('Cloudflare integration is disabled or not configured.');
    }
}
