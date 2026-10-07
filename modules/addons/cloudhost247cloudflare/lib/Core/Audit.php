<?php
namespace CloudHost247\Cloudflare\Core;
class Audit
{
    public static function record($actorType, $actorId, $action, $entityType, $entityId = null, $customerId = null, $serviceId = null, $success = true, array $details = [], $errorCode = null)
    {
        try {
            unset($details['token'], $details['api_token'], $details['authorization'], $details['encrypted_api_token']);
            Db::insert('audit_logs', [
                'actor_type' => substr((string) $actorType, 0, 16), 'actor_id' => (int) $actorId,
                'customer_id' => $customerId ? (int) $customerId : null, 'service_id' => $serviceId ? (int) $serviceId : null,
                'action' => substr((string) $action, 0, 100), 'entity_type' => substr((string) $entityType, 0, 40),
                'entity_id' => $entityId === null ? null : substr((string) $entityId, 0, 80), 'success' => $success ? 1 : 0,
                'details_json' => $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'ip_address' => self::ip(), 'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'error_code' => $errorCode ? substr((string) $errorCode, 0, 80) : null, 'created_at' => Clock::now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('audit write failed', ['action' => $action, 'reason' => get_class($e)]);
        }
    }
    public static function recentForService($serviceId, $customerId, $limit = 50)
    {
        return Db::query('SELECT action, entity_type, entity_id, success, details_json, error_code, created_at FROM `' . Db::table('audit_logs') . '` WHERE service_id = ? AND customer_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [(int) $serviceId, (int) $customerId]);
    }
    public static function recent($limit = 100)
    {
        return Db::query('SELECT * FROM `' . Db::table('audit_logs') . '` ORDER BY id DESC LIMIT ' . (int) $limit);
    }
    private static function ip()
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
