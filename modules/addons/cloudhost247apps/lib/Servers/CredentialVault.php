<?php
/**
 * CloudHost247 App Cloud — credential vault.
 *
 * Every secret the platform holds about a server (SSH keys, WHM API tokens,
 * kubeconfig, DNS API tokens, registry credentials) is sealed with AES-256-GCM
 * under a key derived from CH247APPS_ENCRYPTION_KEY, tagged with the key version
 * that produced it, and indexed by a blind fingerprint so a credential can be
 * found without decrypting anything.
 *
 * Rules this class exists to enforce:
 *   • plaintext is only ever handed to an adapter or the worker (machine actors),
 *     and a human reveal is audited
 *   • secrets are never returned by describe(), never logged, never put in an
 *     event payload or an API response
 *   • rotation is a first-class operation: key version bumps, expiry, revocation
 *     and re-encryption are all recorded
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Servers;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class CredentialVault
{
    const TYPE_SSH_KEY        = 'ssh_key';
    const TYPE_SSH_PASSWORD   = 'ssh_password';
    const TYPE_AGENT_SECRET   = 'agent_secret';
    const TYPE_WHM_API_TOKEN  = 'whm_api_token';
    const TYPE_CPANEL_TOKEN   = 'cpanel_uapi_token';
    const TYPE_KUBE_TOKEN     = 'kube_token';
    const TYPE_KUBE_CONFIG    = 'kube_config';
    const TYPE_DNS_TOKEN      = 'dns_api_token';
    const TYPE_REGISTRY_TOKEN = 'registry_token';
    const TYPE_STORAGE_KEY    = 'storage_key';

    const TYPES = [
        self::TYPE_SSH_KEY, self::TYPE_SSH_PASSWORD, self::TYPE_AGENT_SECRET,
        self::TYPE_WHM_API_TOKEN, self::TYPE_CPANEL_TOKEN, self::TYPE_KUBE_TOKEN,
        self::TYPE_KUBE_CONFIG, self::TYPE_DNS_TOKEN, self::TYPE_REGISTRY_TOKEN,
        self::TYPE_STORAGE_KEY,
    ];

    const STATUS_ACTIVE   = 'active';
    const STATUS_ROTATING = 'rotating';
    const STATUS_REVOKED  = 'revoked';
    const STATUS_EXPIRED  = 'expired';

    /** Context used for authenticated encryption (binds a secret to its row). */
    const CONTEXT = 'server_credentials.secret';

    /** @var Actor */
    private $actor;

    public function __construct(Actor $actor = null)
    {
        $this->actor = $actor ?: Actor::system('CredentialVault');
    }

    /* ---------------------------------------------------------------- write */

    /**
     * Store (or replace) a credential for a server.
     *
     * @param array $meta username, name, expires_at, notes
     * @return array the stored row, without the secret
     */
    public function store($serverId, $type, $secret, array $meta = [])
    {
        Rbac::assert($this->actor, Rbac::SERVER_CREDENTIAL_WRITE);
        $serverId = (int) $serverId;
        $type = $this->assertType($type);

        if (!Db::first('servers', ['id' => $serverId])) {
            throw new NotFoundException('That server is not registered.');
        }
        if (!Crypto::isConfigured()) {
            throw new ConfigurationException(
                'Credentials cannot be stored until CH247APPS_ENCRYPTION_KEY is configured.'
            );
        }
        $secret = (string) $secret;
        if (trim($secret) === '') {
            throw new ValidationException('A credential secret is required.', ['errors' => ['secret' => 'Required']]);
        }
        if (strlen($secret) > 65535) {
            throw new ValidationException('That credential is too large to store.');
        }

        $name = Str::clip(isset($meta['name']) && $meta['name'] !== '' ? $meta['name'] : 'primary', 120);
        $sealed = Crypto::seal($secret, self::contextFor($serverId, $type, $name));
        $now = Clock::now();

        $fields = [
            'server_id' => $serverId,
            'name' => $name,
            'credential_type' => $type,
            'username' => isset($meta['username']) ? Str::clip($meta['username'], 190) : null,
            'encrypted_secret' => $sealed['ciphertext'],
            'key_version' => (int) $sealed['key_version'],
            'secret_fingerprint' => Crypto::blindIndex($secret, self::CONTEXT . '|' . $type),
            'status' => self::STATUS_ACTIVE,
            'expires_at' => !empty($meta['expires_at']) ? (string) $meta['expires_at'] : null,
            'verified' => 0,
            'last_verified_at' => null,
            'rotated_by' => $this->actor->identity(),
            'rotated_at' => $now,
            'updated_at' => $now,
        ];

        $existing = Db::first('server_credentials', [
            'server_id' => $serverId, 'credential_type' => $type, 'name' => $name,
        ]);
        if ($existing) {
            Db::update('server_credentials', $fields, ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
            Audit::record($this->actor, Audit::SERVER_CREDENTIAL_ROTATED, [
                'resource_type' => 'server_credential', 'resource_id' => $id, 'server_id' => $serverId,
                'metadata' => ['type' => $type, 'name' => $name, 'key_version' => $fields['key_version'],
                    'action' => 'replaced'],
                'severity' => 'warning',
            ]);
        } else {
            $fields['created_at'] = $now;
            $id = Db::insert('server_credentials', $fields);
            Audit::record($this->actor, Audit::SERVER_CREDENTIAL_WRITTEN, [
                'resource_type' => 'server_credential', 'resource_id' => $id, 'server_id' => $serverId,
                'metadata' => ['type' => $type, 'name' => $name, 'key_version' => $fields['key_version']],
            ]);
        }

        Events::emit(Events::SERVER_REGISTERED, [
            'credential_type' => $type, 'credential_id' => $id, 'action' => $existing ? 'rotated' : 'stored',
        ], ['server_id' => $serverId]);

        // The secret itself is never logged; only its shape is.
        Logger::info('Server credential stored.', [
            'server_id' => $serverId, 'type' => $type, 'name' => $name,
            'key_version' => $fields['key_version'], 'length' => strlen($secret), 'source' => 'servers',
        ]);

        return $this->describeOne(Db::first('server_credentials', ['id' => $id]));
    }

    /**
     * Rotate a credential to a new secret.
     *
     * The old value is not kept: a rotation that preserved the previous secret
     * would leave the thing an operator rotated away from still decryptable.
     */
    public function rotate($credentialId, $newSecret, $reason = '')
    {
        Rbac::assert($this->actor, Rbac::SERVER_CREDENTIAL_ROTATE);
        $row = $this->row($credentialId);
        if (!Crypto::isConfigured()) {
            throw new ConfigurationException('Rotation needs CH247APPS_ENCRYPTION_KEY.');
        }
        $newSecret = (string) $newSecret;
        if (trim($newSecret) === '') {
            throw new ValidationException('A new secret is required.');
        }
        $context = self::contextFor((int) $row['server_id'], $row['credential_type'], $row['name']);
        if ($row['encrypted_secret'] !== null && Crypto::tryDecrypt($row['encrypted_secret'], $context) === $newSecret) {
            throw new ValidationException('The new secret is identical to the current one.');
        }

        $sealed = Crypto::seal($newSecret, $context);
        $now = Clock::now();
        Db::update('server_credentials', [
            'encrypted_secret' => $sealed['ciphertext'],
            'key_version' => (int) $sealed['key_version'],
            'secret_fingerprint' => Crypto::blindIndex($newSecret, self::CONTEXT . '|' . $row['credential_type']),
            'status' => self::STATUS_ACTIVE,
            'verified' => 0,
            'last_verified_at' => null,
            'rotated_by' => $this->actor->identity(),
            'rotated_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        Audit::record($this->actor, Audit::SERVER_CREDENTIAL_ROTATED, [
            'resource_type' => 'server_credential', 'resource_id' => (int) $row['id'],
            'server_id' => (int) $row['server_id'],
            'metadata' => ['type' => $row['credential_type'], 'name' => $row['name'],
                'reason' => Str::clip($reason, 200), 'key_version' => (int) $sealed['key_version']],
            'severity' => 'warning',
        ]);
        Logger::info('Server credential rotated.', [
            'credential_id' => (int) $row['id'], 'type' => $row['credential_type'],
            'reason' => Str::clip($reason, 200), 'source' => 'servers',
        ]);

        return $this->describeOne(Db::first('server_credentials', ['id' => (int) $row['id']]));
    }

    /**
     * Re-seal every credential with the current key version.
     *
     * This is the second half of key rotation: bumping CH247APPS_KEY_VERSION lets
     * old ciphertext still be read, and this rewrites it so the old key can be
     * destroyed.
     *
     * @return array{rewritten: int, failed: int}
     */
    public function reencryptAll()
    {
        Rbac::assert($this->actor, Rbac::SERVER_CREDENTIAL_ROTATE);
        $rewritten = 0;
        $failed = 0;
        foreach (Db::fetch('server_credentials', ['status' => ['notin', [self::STATUS_REVOKED]]]) as $row) {
            $context = self::contextFor((int) $row['server_id'], $row['credential_type'], $row['name']);
            $plain = Crypto::tryDecrypt($row['encrypted_secret'], $context);
            if ($plain === null) {
                $failed++;
                Logger::error('Credential could not be decrypted during re-encryption.', [
                    'credential_id' => (int) $row['id'], 'key_version' => (int) $row['key_version'],
                    'source' => 'servers',
                ]);
                continue;
            }
            if ((int) $row['key_version'] === Crypto::CURRENT_KEY_VERSION) {
                continue;
            }
            $sealed = Crypto::seal($plain, $context);
            Db::update('server_credentials', [
                'encrypted_secret' => $sealed['ciphertext'],
                'key_version' => (int) $sealed['key_version'],
                'rotated_by' => $this->actor->identity(),
                'rotated_at' => Clock::now(),
                'updated_at' => Clock::now(),
            ], ['id' => (int) $row['id']]);
            $rewritten++;
        }
        Audit::record($this->actor, Audit::SERVER_CREDENTIAL_ROTATED, [
            'resource_type' => 'server_credential', 'resource_id' => 'bulk',
            'metadata' => ['rewritten' => $rewritten, 'failed' => $failed,
                'key_version' => Crypto::CURRENT_KEY_VERSION],
            'severity' => $failed ? 'error' : 'info',
        ]);
        return ['rewritten' => $rewritten, 'failed' => $failed];
    }

    public function revoke($credentialId, $reason = '')
    {
        Rbac::assert($this->actor, Rbac::SERVER_CREDENTIAL_ROTATE);
        $row = $this->row($credentialId);
        Db::update('server_credentials', [
            'status' => self::STATUS_REVOKED,
            // Destroy the ciphertext: a revoked credential must not be recoverable.
            'encrypted_secret' => null,
            'secret_fingerprint' => null,
            'rotated_by' => $this->actor->identity(),
            'rotated_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);
        Audit::record($this->actor, Audit::SERVER_CREDENTIAL_ROTATED, [
            'resource_type' => 'server_credential', 'resource_id' => (int) $row['id'],
            'server_id' => (int) $row['server_id'],
            'metadata' => ['type' => $row['credential_type'], 'action' => 'revoked',
                'reason' => Str::clip($reason, 200)],
            'severity' => 'warning',
        ]);
        return true;
    }

    /* ----------------------------------------------------------------- read */

    /**
     * Reveal a plaintext secret.
     *
     * @internal for adapters and the deployment worker only. A human caller must
     *           hold SERVER_CREDENTIAL_WRITE and the read is audited; a machine
     *           actor (worker/agent dispatch) is not audited per call because it
     *           happens on every deployment step.
     */
    public function reveal($credentialId)
    {
        $row = $this->row($credentialId);
        if ($row['status'] !== self::STATUS_ACTIVE) {
            throw new NotFoundException('That credential is ' . $row['status'] . '.');
        }
        if (!$this->actor->isMachine()) {
            Rbac::assert($this->actor, Rbac::SERVER_CREDENTIAL_WRITE);
            Audit::record($this->actor, Audit::SERVER_CREDENTIAL_WRITTEN, [
                'resource_type' => 'server_credential', 'resource_id' => (int) $row['id'],
                'server_id' => (int) $row['server_id'],
                'metadata' => ['type' => $row['credential_type'], 'action' => 'revealed'],
                'severity' => 'warning',
            ]);
        }
        $context = self::contextFor((int) $row['server_id'], $row['credential_type'], $row['name']);
        $plain = Crypto::tryDecrypt($row['encrypted_secret'], $context);
        if ($plain === null) {
            Logger::error('Stored credential could not be decrypted.', [
                'credential_id' => (int) $row['id'], 'key_version' => (int) $row['key_version'],
                'source' => 'servers',
            ]);
            throw new ConfigurationException(
                'That credential could not be decrypted with the current encryption key. '
                . 'It was sealed with key version ' . (int) $row['key_version'] . '.'
            );
        }
        Db::update('server_credentials', ['last_used_at' => Clock::now()], ['id' => (int) $row['id']]);
        return $plain;
    }

    /** Reveal by server + type, or null when the server has no such credential. */
    public function revealFor($serverId, $type, $name = 'primary')
    {
        $row = Db::first('server_credentials', [
            'server_id' => (int) $serverId, 'credential_type' => (string) $type,
            'name' => (string) $name, 'status' => self::STATUS_ACTIVE,
        ]);
        return $row ? $this->reveal((int) $row['id']) : null;
    }

    /** Credential metadata for a server — never contains a secret. */
    public function describe($serverId)
    {
        $out = [];
        foreach (Db::fetch('server_credentials', ['server_id' => (int) $serverId,
            'status' => ['notin', [self::STATUS_REVOKED]]], ['order' => 'credential_type']) as $row) {
            $out[] = $this->describeOne($row);
        }
        return $out;
    }

    public function describeOne(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'server_id' => (int) $row['server_id'],
            'name' => $row['name'],
            'credential_type' => $row['credential_type'],
            'username' => isset($row['username']) ? $row['username'] : null,
            'status' => $row['status'],
            'key_version' => (int) $row['key_version'],
            'needs_rotation' => Crypto::needsRotation((int) $row['key_version']),
            'verified' => (bool) $row['verified'],
            'expires_at' => isset($row['expires_at']) ? $row['expires_at'] : null,
            'expired' => !empty($row['expires_at']) && Clock::isPast($row['expires_at']),
            'last_used_at' => isset($row['last_used_at']) ? $row['last_used_at'] : null,
            'last_verified_at' => isset($row['last_verified_at']) ? $row['last_verified_at'] : null,
            'rotated_at' => isset($row['rotated_at']) ? $row['rotated_at'] : null,
            'rotated_by' => isset($row['rotated_by']) ? $row['rotated_by'] : null,
            'fingerprint' => isset($row['secret_fingerprint']) ? Str::clip($row['secret_fingerprint'], 12) : null,
        ];
    }

    /** Record the outcome of a connectivity check. */
    public function markVerified($credentialId, $verified, $detail = '')
    {
        $row = $this->row($credentialId);
        Db::update('server_credentials', [
            'verified' => $verified ? 1 : 0,
            'last_verified_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);
        if (!$verified) {
            Logger::warning('Credential verification failed.', [
                'credential_id' => (int) $row['id'], 'type' => $row['credential_type'],
                'detail' => Str::clip($detail, 200), 'source' => 'servers',
            ]);
        }
        return $verified;
    }

    /** Credentials that need attention: old key version, expired, or unverified. */
    public function attention($serverId = null)
    {
        $where = ['status' => self::STATUS_ACTIVE];
        if ($serverId !== null) {
            $where['server_id'] = (int) $serverId;
        }
        $out = [];
        foreach (Db::fetch('server_credentials', $where) as $row) {
            $reasons = [];
            if (Crypto::needsRotation((int) $row['key_version'])) {
                $reasons[] = 'key_version';
            }
            if (!empty($row['expires_at']) && Clock::isPast($row['expires_at'])) {
                $reasons[] = 'expired';
            }
            if (!(int) $row['verified']) {
                $reasons[] = 'unverified';
            }
            if ($reasons) {
                $entry = $this->describeOne($row);
                $entry['reasons'] = $reasons;
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Find a credential by its secret without decrypting anything (used to detect
     * that two servers were registered with the same key).
     */
    public function findBySecret($type, $secret)
    {
        $fingerprint = Crypto::blindIndex((string) $secret, self::CONTEXT . '|' . (string) $type);
        return $fingerprint === null ? null : Db::first('server_credentials', [
            'credential_type' => (string) $type, 'secret_fingerprint' => $fingerprint,
        ]);
    }

    /* ------------------------------------------------------------ internals */

    private function row($credentialId)
    {
        $row = Db::first('server_credentials', ['id' => (int) $credentialId]);
        if (!$row) {
            throw new NotFoundException('That credential does not exist.');
        }
        return $row;
    }

    private function assertType($type)
    {
        $type = strtolower((string) $type);
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException('Unknown credential type "' . $type . '".', [
                'errors' => ['credential_type' => 'Must be one of: ' . implode(', ', self::TYPES)],
            ]);
        }
        return $type;
    }

    /**
     * Additional authenticated data binds a ciphertext to its own row: copying
     * server A's ciphertext into server B's record will not decrypt.
     */
    public static function contextFor($serverId, $type, $name)
    {
        return self::CONTEXT . '|server:' . (int) $serverId . '|' . $type . '|' . $name;
    }
}
