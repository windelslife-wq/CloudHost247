<?php
namespace CloudHost247\Cloudflare\Service;
class ZoneState
{
    public static function fromProvider(array $zone)
    {
        $providerStatus = strtolower((string) ($zone['status'] ?? ''));
        if ($providerStatus === 'active' && empty($zone['paused'])) return ['status' => 'ACTIVE', 'activation_status' => 'ACTIVE'];
        if (in_array($providerStatus, ['initializing','pending'], true)) return ['status' => 'PENDING_NAMESERVER_UPDATE', 'activation_status' => 'PENDING_NAMESERVER_UPDATE'];
        if (!empty($zone['paused'])) return ['status' => 'SUSPENDED', 'activation_status' => 'ACTIVE'];
        return ['status' => 'PENDING', 'activation_status' => 'PENDING'];
    }
}
