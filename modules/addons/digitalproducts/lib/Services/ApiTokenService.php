<?php
namespace DigitalProducts\Services;

use DigitalProducts\Core\Clock;
use WHMCS\Database\Capsule;

class ApiTokenService
{
    public function issue($clientId, $name, array $permissions = [], $expiresAt = null)
    {
        $raw = bin2hex(random_bytes(32));
        $id = Capsule::table('mod_digitalproducts_api_tokens')->insertGetId(['client_id' => (int) $clientId, 'token_name' => substr(trim((string) $name), 0, 100), 'token_hash' => hash('sha256', $raw), 'permissions' => json_encode(array_values($permissions)), 'expires_at' => $expiresAt, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        return ['id' => $id, 'token' => $raw, 'expires_at' => $expiresAt];
    }
    public function revoke($id, $clientId = null)
    {
        $query = Capsule::table('mod_digitalproducts_api_tokens')->where('id', (int) $id); if ($clientId !== null) $query->where('client_id', (int) $clientId); return $query->delete();
    }
    public function listForClient($clientId) { return Capsule::table('mod_digitalproducts_api_tokens')->where('client_id', (int) $clientId)->select('id', 'token_name', 'permissions', 'last_used_at', 'expires_at', 'created_at')->get(); }
}
