<?php
/**
 * Provider account lifecycle and context-bound credential vault.
 *
 * The database contains authenticated ciphertext only. A secret is decrypted
 * only for a human-authorized connection test or inside the worker immediately
 * before an adapter call; the API and account presentation never contain it.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\ProviderAuthenticationException;
use Ch247Apps\Core\ProviderConfigurationException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\JobQueue;

class ProviderAccountService
{
    const STATUS_UNVERIFIED = 'unverified';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_ERROR = 'error';

    /** @var Actor */
    private $actor;
    /** @var JobQueue */
    private $queue;

    public function __construct(Actor $actor = null, JobQueue $queue = null)
    {
        $this->actor = $actor ?: Actor::system('ProviderAccountService');
        $this->queue = $queue ?: new JobQueue();
    }

    /** Create an account without making it usable for provisioning. */
    public function create(array $input)
    {
        Rbac::assert($this->actor, Rbac::PROVIDER_ACCOUNT_MANAGE);

        $providerCode = strtolower(trim((string) (isset($input['provider_code']) ? $input['provider_code'] : '')));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $providerCode)
            || !ProviderRegistry::isKnownProvider($providerCode)) {
            throw new ValidationException('Choose a provider from the registered provider catalog.', [
                'field' => 'provider_code',
            ]);
        }
        $name = trim((string) (isset($input['name']) ? $input['name'] : ''));
        if ($name === '' || strlen($name) > 120) {
            throw new ValidationException('An account name of 1–120 characters is required.', ['field' => 'name']);
        }
        $region = trim((string) (isset($input['region']) ? $input['region'] : ''));
        if ($region !== '' && (strlen($region) > 80 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $region))) {
            throw new ValidationException('The provider region is invalid.', ['field' => 'region']);
        }
        $config = self::validatePublicConfig(isset($input['public_config']) && is_array($input['public_config'])
            ? $input['public_config'] : []);
        $credentials = isset($input['credentials']) ? self::validateCredentials($input['credentials']) : [];
        if ($credentials && !Crypto::isConfigured()) {
            throw new ProviderConfigurationException(
                'Provider credentials cannot be stored until CH247APPS_ENCRYPTION_KEY is configured.'
            );
        }
        if ($credentials) {
            Rbac::assert($this->actor, Rbac::PROVIDER_CREDENTIAL_WRITE);
        }

        $now = Clock::now();
        $id = Db::transaction(function () use ($providerCode, $name, $region, $config, $credentials, $now) {
            $id = Db::insert('provider_accounts', [
                'uuid' => Str::uuid4(),
                'provider_code' => $providerCode,
                'name' => $name,
                'status' => self::STATUS_UNVERIFIED,
                'region' => $region !== '' ? $region : null,
                'public_config' => Str::jsonEncode($config),
                'encrypted_credentials' => null,
                'credential_key_version' => 0,
                'credential_fingerprint' => null,
                'last_verified_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'created_by' => Str::clip($this->actor->identity(), 120),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($credentials) {
                $this->storeCredentials($id, $credentials);
            }
            return $id;
        });

        Audit::record($this->actor, Audit::PROVIDER_ACCOUNT_CREATED, [
            'resource_type' => 'provider_account', 'resource_id' => $id,
            'metadata' => ['provider_code' => $providerCode, 'credentials_configured' => (bool) $credentials],
        ]);
        return $this->present($this->row($id));
    }

    /** Write or rotate credentials. The new value is never returned. */
    public function writeCredentials($accountId, array $credentials, $rotation = false)
    {
        Rbac::assert($this->actor, $rotation ? Rbac::PROVIDER_CREDENTIAL_ROTATE : Rbac::PROVIDER_CREDENTIAL_WRITE);
        $row = $this->row($accountId);
        $credentials = self::validateCredentials($credentials);
        if (!Crypto::isConfigured()) {
            throw new ProviderConfigurationException(
                'Provider credentials cannot be stored until CH247APPS_ENCRYPTION_KEY is configured.'
            );
        }
        $this->storeCredentials((int) $row['id'], $credentials);
        $action = $rotation ? Audit::PROVIDER_CREDENTIAL_ROTATED : Audit::PROVIDER_CREDENTIAL_WRITTEN;
        Audit::record($this->actor, $action, [
            'resource_type' => 'provider_account', 'resource_id' => (int) $row['id'],
            'metadata' => ['provider_code' => $row['provider_code']],
        ]);
        return $this->present($this->row((int) $row['id']));
    }

    /** Queue verification; the HTTP request never calls a provider API. */
    public function requestVerification($accountId, $idempotencyKey)
    {
        Rbac::assert($this->actor, Rbac::PROVIDER_ACCOUNT_VERIFY);
        $row = $this->row($accountId);
        if ((string) $row['status'] === self::STATUS_SUSPENDED) {
            throw new ProviderConfigurationException('A suspended provider account must be reconfigured before verification.');
        }
        if (empty($row['encrypted_credentials'])) {
            throw new ProviderConfigurationException('Configure provider-account credentials before verification.');
        }
        // Fail clearly before queueing when no real adapter exists.
        ProviderRegistry::forProvider($row['provider_code']);
        $key = trim((string) $idempotencyKey);
        if ($key === '' || strlen($key) > 120 || preg_match('/[\\x00-\\x20\\x7F]/', $key)) {
            throw new ValidationException('A valid Idempotency-Key header is required for provider verification.');
        }
        $run = Idempotency::run('provider-account.verify', $key, ['provider_account_id' => (int) $row['id']], function () use ($row, $key) {
            $job = $this->queue->enqueue(JobQueue::TYPE_PROVIDER_ACCOUNT_VERIFY, [
                'provider_account_id' => (int) $row['id'],
            ], [
                'queue' => JobQueue::QUEUE_PROVISIONING,
                'idempotency_key' => 'provider-account:verify:' . (int) $row['id'] . ':' . substr(hash('sha256', $key), 0, 24),
                'provider_account_id' => (int) $row['id'],
                'requested_by' => $this->actor->identity(),
                'max_attempts' => 3,
            ]);
            return ['provider_account_id' => (int) $row['id'], 'job_id' => (int) $job['id']];
        });
        $ids = isset($run['result']) ? $run['result'] : [];
        if (empty($run['replayed'])) {
            Audit::record($this->actor, Audit::PROVIDER_ACCOUNT_VERIFY_REQUESTED, [
                'resource_type' => 'provider_account', 'resource_id' => (int) $row['id'],
                'metadata' => ['job_id' => isset($ids['job_id']) ? (int) $ids['job_id'] : null,
                    'provider_code' => $row['provider_code']],
            ]);
        }
        $jobRow = !empty($ids['job_id']) ? $this->queue->find((int) $ids['job_id']) : null;
        return [
            'account' => $this->present($this->row((int) $row['id'])),
            'job' => $jobRow ? $this->queue->present($jobRow) : null,
            'replayed' => !empty($run['replayed']),
        ];
    }

    /**
     * Credentials are activated only after the worker's real adapter check.
     * This method is deliberately system-only; HTTP callers enqueue verification.
     */
    public function verify($accountId)
    {
        if (!$this->actor->isSystem()) {
            throw new AuthorizationException('Provider credential verification is worker-only.');
        }
        $row = $this->row($accountId);
        if ((string) $row['status'] === self::STATUS_SUSPENDED) {
            throw new ProviderConfigurationException('A suspended provider account cannot be verified.');
        }
        if (empty($row['encrypted_credentials'])) {
            throw new ProviderConfigurationException('Configure provider-account credentials before verification.');
        }
        $adapter = ProviderRegistry::forProvider($row['provider_code']);
        $credentials = $this->decryptCredentials($row);
        $config = Str::jsonDecode($row['public_config'], []);

        try {
            $verified = $adapter->verifyCredentials($credentials, $config);
        } catch (ProviderAuthenticationException $e) {
            $this->markError($row, $e->errorCode(), 'The provider rejected the configured credentials.');
            throw $e;
        }
        if ($verified !== true) {
            $this->markError($row, 'AUTHENTICATION_FAILED', 'The provider did not confirm the configured credentials.');
            throw new ProviderAuthenticationException('The provider did not confirm the configured credentials.');
        }

        $updated = Db::compareAndSet('provider_accounts', [
            'status' => self::STATUS_ACTIVE,
            'last_verified_at' => Clock::now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'updated_at' => Clock::now(),
        ], [
            'id' => (int) $row['id'],
            'credential_fingerprint' => $row['credential_fingerprint'],
            'status' => (string) $row['status'],
        ]);
        if (!$updated) {
            $fresh = $this->row((int) $row['id']);
            if ((string) $fresh['status'] !== self::STATUS_ACTIVE
                || (string) $fresh['credential_fingerprint'] !== (string) $row['credential_fingerprint']) {
                throw new ConflictException('Provider credentials or account status changed during verification; queue a fresh verification.');
            }
        }
        Audit::record($this->actor, Audit::PROVIDER_ACCOUNT_VERIFIED, [
            'resource_type' => 'provider_account', 'resource_id' => (int) $row['id'],
            'metadata' => ['provider_code' => $row['provider_code']],
        ]);
        return $this->present($this->row((int) $row['id']));
    }

    /** Suspend account use without deleting credentials or customer resources. */
    public function suspend($accountId)
    {
        Rbac::assert($this->actor, Rbac::PROVIDER_ACCOUNT_MANAGE);
        $row = $this->row($accountId);
        Db::update('provider_accounts', [
            'status' => self::STATUS_SUSPENDED,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);
        Audit::record($this->actor, Audit::PROVIDER_ACCOUNT_SUSPENDED, [
            'resource_type' => 'provider_account', 'resource_id' => (int) $row['id'],
            'metadata' => ['provider_code' => $row['provider_code']], 'severity' => 'warning',
        ]);
        return $this->present($this->row((int) $row['id']));
    }

    /** List provider accounts without ever including ciphertext or plaintext. */
    public function listing()
    {
        Rbac::assert($this->actor, Rbac::PROVIDER_ACCOUNT_VIEW);
        $out = [];
        foreach (Db::fetch('provider_accounts', [], ['order' => 'id', 'dir' => 'desc']) as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function get($accountId)
    {
        Rbac::assert($this->actor, Rbac::PROVIDER_ACCOUNT_VIEW);
        return $this->present($this->row($accountId));
    }

    /**
     * Worker-only adapter context. Plaintext exists only in process memory for
     * the duration of a provider call and is never added to the job payload.
     */
    public function operationalContext($accountId, array $requiredCapabilities = [])
    {
        if (!$this->actor->isSystem()) {
            throw new AuthorizationException('Provider credentials may only be revealed to the worker.');
        }
        $row = $this->row($accountId);
        if ((string) $row['status'] !== self::STATUS_ACTIVE || empty($row['encrypted_credentials'])) {
            throw new ProviderConfigurationException(
                'The provider account is not verified and active.',
                ['provider_account_id' => (int) $row['id']]
            );
        }
        $adapter = ProviderRegistry::forProvider($row['provider_code']);
        ProviderRegistry::assertSupports($adapter, $requiredCapabilities);
        return [
            'account' => $row,
            'adapter' => $adapter,
            'credentials' => $this->decryptCredentials($row),
            'config' => Str::jsonDecode($row['public_config'], []),
        ];
    }

    /** Fast preflight; does not decrypt credentials or reveal them to HTTP. */
    public function assertOperational($accountId, array $requiredCapabilities = [])
    {
        $row = $this->row($accountId);
        if ((string) $row['status'] !== self::STATUS_ACTIVE || empty($row['encrypted_credentials'])) {
            throw new ProviderConfigurationException('The provider account must be active and credential-verified.');
        }
        $adapter = ProviderRegistry::forProvider($row['provider_code']);
        ProviderRegistry::assertSupports($adapter, $requiredCapabilities);
        return $this->present($row);
    }

    /** Raw module row for internal services only; never return this from an API. */
    public function row($accountId)
    {
        $row = Db::first('provider_accounts', ['id' => (int) $accountId]);
        if (!$row) {
            throw new NotFoundException('That provider account does not exist.');
        }
        return $row;
    }

    public function present(array $row)
    {
        $adapterAvailable = ProviderRegistry::hasAdapter($row['provider_code']);
        $capabilities = [];
        if ($adapterAvailable) {
            $adapter = ProviderRegistry::forProvider($row['provider_code']);
            $capabilities = ProviderRegistry::normaliseCapabilities($adapter->capabilities());
        }
        return [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'provider_code' => (string) $row['provider_code'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'region' => isset($row['region']) ? $row['region'] : null,
            'public_config' => Str::jsonDecode($row['public_config'], []),
            'credentials_configured' => !empty($row['encrypted_credentials']),
            'adapter_available' => $adapterAvailable,
            'capabilities' => $capabilities,
            'last_verified_at' => isset($row['last_verified_at']) ? $row['last_verified_at'] : null,
            'last_error_code' => isset($row['last_error_code']) ? $row['last_error_code'] : null,
            'last_error_message' => isset($row['last_error_message']) ? $row['last_error_message'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function storeCredentials($accountId, array $credentials)
    {
        $json = Str::jsonEncode($credentials);
        $sealed = Crypto::seal($json, self::context($accountId));
        Db::update('provider_accounts', [
            'encrypted_credentials' => $sealed['ciphertext'],
            'credential_key_version' => (int) $sealed['key_version'],
            'credential_fingerprint' => hash('sha256', (string) $sealed['ciphertext']),
            'status' => self::STATUS_UNVERIFIED,
            'last_verified_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $accountId]);
    }

    private function decryptCredentials(array $row)
    {
        try {
            $plain = Crypto::decrypt($row['encrypted_credentials'], self::context((int) $row['id']));
            $credentials = Str::jsonDecode($plain, null);
        } catch (\Throwable $e) {
            throw new ProviderConfigurationException(
                'Provider credentials could not be decrypted; verify the App Cloud encryption-key configuration.'
            );
        }
        if (!is_array($credentials) || !$credentials) {
            throw new ProviderConfigurationException('The encrypted provider credentials are invalid.');
        }
        return $credentials;
    }

    private function markError(array $row, $code, $message)
    {
        Db::compareAndSet('provider_accounts', [
            'status' => self::STATUS_ERROR,
            'last_error_code' => Str::clip((string) $code, 60),
            'last_error_message' => Str::clip((string) $message, 1000),
            'updated_at' => Clock::now(),
        ], [
            'id' => (int) $row['id'],
            'credential_fingerprint' => isset($row['credential_fingerprint']) ? $row['credential_fingerprint'] : null,
            'status' => (string) $row['status'],
        ]);
    }

    private static function context($accountId)
    {
        return 'provider-account:' . (int) $accountId . ':credentials';
    }

    private static function validateCredentials($credentials)
    {
        if (!is_array($credentials) || !$credentials) {
            throw new ValidationException('A non-empty provider credentials object is required.', [
                'field' => 'credentials',
            ]);
        }
        if (count($credentials) > 30) {
            throw new ValidationException('A provider account may contain at most 30 credential fields.');
        }
        $out = [];
        foreach ($credentials as $key => $value) {
            $key = (string) $key;
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $key)
                || !(is_string($value) || is_int($value) || is_bool($value) || is_float($value))) {
                throw new ValidationException('Provider credentials must be named scalar fields.');
            }
            $value = (string) $value;
            if ($value === '' || strlen($value) > 8192) {
                throw new ValidationException('Provider credential fields must be non-empty and at most 8192 bytes.');
            }
            $out[$key] = $value;
        }
        if (strlen(Str::jsonEncode($out)) > 24576) {
            throw new ValidationException('The provider credentials object is too large.');
        }
        return $out;
    }

    private static function validatePublicConfig(array $config)
    {
        if (count($config) > 40) {
            throw new ValidationException('Provider public configuration has too many fields.');
        }
        $out = [];
        foreach ($config as $key => $value) {
            $key = (string) $key;
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $key)
                || preg_match('/password|passwd|secret|token|private|credential|api[_-]?key|access[_-]?key|authorization/i', $key)) {
                throw new ValidationException('Secret material belongs in the encrypted credentials object, not public_config.');
            }
            if (!(is_string($value) || is_int($value) || is_bool($value) || is_float($value))) {
                throw new ValidationException('Provider public configuration must contain scalar values only.');
            }
            $value = (string) $value;
            if (strlen($value) > 512) {
                throw new ValidationException('A provider public configuration value is too long.');
            }
            $out[$key] = $value;
        }
        if (strlen(Str::jsonEncode($out)) > 8192) {
            throw new ValidationException('Provider public configuration is too large.');
        }
        return $out;
    }
}
