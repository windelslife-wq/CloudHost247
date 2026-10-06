<?php
namespace DigitalProducts\Core;

use WHMCS\Database\Capsule;

/** Append-only module audit adapter. */
class Audit
{
    public static function record($action, $resource = null, array $context = [], $result = 'success')
    {
        $redacted = self::redact($context);
        $row = [
            'action' => substr((string) $action, 0, 100),
            'resource' => $resource === null ? null : substr((string) $resource, 0, 190),
            'result' => substr((string) $result, 0, 32),
            'admin_id' => (int) ($_SESSION['adminid'] ?? 0),
            'client_id' => (int) ($_SESSION['uid'] ?? 0),
            'correlation_id' => bin2hex(random_bytes(12)),
            'context' => json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_hash' => hash('sha256', Http::ip()),
            'created_at' => Clock::now(),
        ];
        try {
            if (Capsule::schema()->hasTable('mod_digitalproducts_audit')) return Capsule::table('mod_digitalproducts_audit')->insertGetId($row);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) logActivity('DigitalProducts audit failure: ' . $e->getMessage());
        }
        return null;
    }

    protected static function redact($value)
    {
        if (!is_array($value)) return $value;
        $out = [];
        foreach ($value as $key => $item) {
            $lower = strtolower((string) $key);
            $out[$key] = preg_match('/token|secret|password|api[_-]?key|license[_-]?key|hash/', $lower) ? '[redacted]' : (is_array($item) ? self::redact($item) : $item);
        }
        return $out;
    }
}
