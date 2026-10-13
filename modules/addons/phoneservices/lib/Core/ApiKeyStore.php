<?php
/**
 * Per-client API key store (finding P-5).
 *
 * The module previously authenticated REST requests with a single shared
 * "api_key" setting that granted all-tenant access (any customer's numbers
 * could be listed, suspended or released) and could not be set from the
 * admin UI. Keys are now issued per WHMCS client; each key scopes the
 * request to exactly one client. Only SHA-256 hashes (plus a short display
 * prefix) are stored, never the raw key.
 */

namespace PhoneServices\Core;

class ApiKeyStore
{
    const KEY_PREFIX = 'psk_';

    /**
     * Create the keys table if it does not exist (idempotent).
     */
    public static function ensureTable(): void
    {
        full_query("CREATE TABLE IF NOT EXISTS `mod_phoneservices_apikeys` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `clientid` INT(10) UNSIGNED NOT NULL,
            `name` VARCHAR(100) NOT NULL DEFAULT 'Default',
            `key_hash` CHAR(64) NOT NULL,
            `key_prefix` VARCHAR(12) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` DATETIME NULL DEFAULT NULL,
            `revoked_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `clientid` (`clientid`),
            KEY `key_hash` (`key_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Generate a new random API key (shown to the admin exactly once).
     */
    public static function generateKey(): string
    {
        return self::KEY_PREFIX . bin2hex(random_bytes(24));
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Issue a key for a client.
     *
     * @return array{key: string, id: int} The raw key (shown once) and the stored row ID.
     */
    public static function create(int $clientid, string $name = 'Default'): array
    {
        self::ensureTable();
        $key = self::generateKey();
        $id = Database::insert('mod_phoneservices_apikeys', [
            'clientid' => $clientid,
            'name' => mb_substr($name !== '' ? $name : 'Default', 0, 100),
            'key_hash' => self::hashKey($key),
            'key_prefix' => substr($key, 0, 12),
        ]);
        Logger::info('API key issued for client', ['client' => $clientid, 'key_id' => $id]);
        return ['key' => $key, 'id' => (int) $id];
    }

    /**
     * Resolve a presented key to its client row (null when unknown or revoked).
     *
     * @return array{id: int, clientid: int, name: string, key_prefix: string, created_at: string, last_used_at: ?string}|null
     */
    public static function lookup(string $key): ?array
    {
        if ($key === '' || strpos($key, self::KEY_PREFIX) !== 0) {
            return null;
        }

        self::ensureTable();
        $row = \WHMCS\Database\Capsule::table('mod_phoneservices_apikeys')
            ->where('key_hash', self::hashKey($key))
            ->whereNull('revoked_at')
            ->first();
        if ($row === null) {
            return null;
        }

        \WHMCS\Database\Capsule::table('mod_phoneservices_apikeys')
            ->where('id', $row->id)
            ->update(['last_used_at' => date('Y-m-d H:i:s')]);

        return [
            'id' => (int) $row->id,
            'clientid' => (int) $row->clientid,
            'name' => (string) $row->name,
            'key_prefix' => (string) $row->key_prefix,
            'created_at' => (string) $row->created_at,
            'last_used_at' => $row->last_used_at !== null ? (string) $row->last_used_at : null,
        ];
    }

    /**
     * Revoke a key. Returns false when the key was unknown or already revoked.
     */
    public static function revoke(int $id): bool
    {
        self::ensureTable();
        $updated = \WHMCS\Database\Capsule::table('mod_phoneservices_apikeys')
            ->where('id', $id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
        return $updated > 0;
    }

    /**
     * List a client's keys (most recent first), without exposing raw keys.
     */
    public static function listFor(int $clientid): array
    {
        self::ensureTable();
        return \WHMCS\Database\Capsule::table('mod_phoneservices_apikeys')
            ->where('clientid', $clientid)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'clientid' => (int) $row->clientid,
                    'name' => (string) $row->name,
                    'key_prefix' => (string) $row->key_prefix,
                    'created_at' => (string) $row->created_at,
                    'last_used_at' => $row->last_used_at !== null ? (string) $row->last_used_at : null,
                    'revoked' => $row->revoked_at !== null,
                ];
            })
            ->all();
    }

    /**
     * Human-readable client label for the admin UI.
     */
    public static function clientLabel(int $clientid): string
    {
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', $clientid)
            ->first(['firstname', 'lastname']);
        if ($client === null) {
            return 'Client #' . $clientid;
        }
        return trim($client->firstname . ' ' . $client->lastname);
    }
}
