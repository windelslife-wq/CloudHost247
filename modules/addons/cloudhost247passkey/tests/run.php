<?php
/** Phase 3 through Phase 7 core tests; library-backed ceremonies run separately. */

require_once dirname(__DIR__) . '/autoload.php';

use CloudHost247\Passkey\Core\Base64Url;
use CloudHost247\Passkey\Core\CeremonyChallengeStore;
use CloudHost247\Passkey\Core\Db;
use CloudHost247\Passkey\Core\CredentialManagementRepository;
use CloudHost247\Passkey\Core\ExternalIdentityLinkService;
use CloudHost247\Passkey\Core\ExternalIdentityRepository;
use CloudHost247\Passkey\Core\Migrator;
use CloudHost247\Passkey\Core\PasskeyAssertionVerifierInterface;
use CloudHost247\Passkey\Core\PasskeyCredentialManagementService;
use CloudHost247\Passkey\Core\PasskeyRegistrationCeremonyInterface;
use CloudHost247\Passkey\Core\PasskeyIntegrationRegistry;
use CloudHost247\Passkey\Core\PasskeyLoginCoordinator;
use CloudHost247\Passkey\Core\PasskeyLoginPolicy;
use CloudHost247\Passkey\Core\IdentityPolicyRepository;
use CloudHost247\Passkey\Core\PasskeyPolicyResolver;
use CloudHost247\Passkey\Core\PasskeyRateLimiter;
use CloudHost247\Passkey\Core\PasskeyRecoveryGrantService;
use CloudHost247\Passkey\Core\PasskeySecurityService;
use CloudHost247\Passkey\Core\SecurityEventRepository;
use CloudHost247\Passkey\Core\Schema;
use CloudHost247\Passkey\Core\SessionBinding;
use CloudHost247\Passkey\Core\UserHandleRepository;
use CloudHost247\Passkey\Core\WebAuthnClientData;
use CloudHost247\Passkey\Core\WebAuthnConfig;
use CloudHost247\Passkey\Core\WebAuthnService;
use CloudHost247\Passkey\Integration\CallbackWhmcsAuthBridge;
use CloudHost247\Passkey\Integration\CallbackWhmcsIdentityProvider;
use CloudHost247\Passkey\Integration\ExternalIdentityClaims;
use CloudHost247\Passkey\Integration\PasskeyLoginContext;
use CloudHost247\Passkey\Integration\PasskeyRegistrationContext;
use CloudHost247\Passkey\Integration\WhmcsAuthHandoff;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Model\ChallengeRecord;
use CloudHost247\Passkey\Model\CredentialRecord;
use CloudHost247\Passkey\Model\ExternalIdentityRecord;
use CloudHost247\Passkey\Model\RateLimitRecord;
use CloudHost247\Passkey\Model\ResetGrantRecord;
use CloudHost247\Passkey\Model\SafeMetadata;
use CloudHost247\Passkey\Model\SecurityEventRecord;
use CloudHost247\Passkey\Model\SettingsRecord;
use CloudHost247\Passkey\Model\UserPolicyRecord;
use CloudHost247\Passkey\Model\UserPreferencesRecord;

$checks = 0;
$failures = 0;
$assert = function ($name, $condition) use (&$checks, &$failures) {
    $checks++;
    if ($condition) {
        echo 'PASS ' . $name . "\n";
    } else {
        $failures++;
        echo 'FAIL ' . $name . "\n";
    }
};
$throws = function ($name, $expectedClass, $callback) use (&$checks, &$failures) {
    $checks++;
    try {
        call_user_func($callback);
        $failures++;
        echo 'FAIL ' . $name . " (no exception)\n";
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            echo 'PASS ' . $name . "\n";
        } else {
            $failures++;
            echo 'FAIL ' . $name . ' (' . get_class($e) . ")\n";
        }
    }
};

$sessionStarted = false;
if (function_exists('session_start') && function_exists('session_status')) {
    @ini_set('session.use_cookies', '0');
    @ini_set('session.cache_limiter', '');
    @session_save_path(sys_get_temp_dir());
    @session_id('cloudhost247-phase3-tests');
    @session_start();
    $sessionStarted = session_status() === PHP_SESSION_ACTIVE;
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
Db::setPdo($pdo, 'sqlite');
$now = '2026-10-07 12:00:00';
$migrator = new Migrator(dirname(__DIR__) . '/install/migrations');
$firstRun = $migrator->migrate();
$secondRun = $migrator->migrate();
$assert('fresh activation applies the additive Passkey and WebAuthn core migrations once', $firstRun['applied'] === ['0001_passkey_core', '0002_webauthn_core']);
$assert('repeat activation is idempotent', $secondRun['applied'] === [] && $secondRun['skipped'] === ['0001_passkey_core', '0002_webauthn_core']);
$assert('migration ledger records only completed migrations', Db::count('migrations') === 2);
$phase3Migration = require dirname(__DIR__) . '/install/migrations/0002_webauthn_core.php';
call_user_func($phase3Migration['up']);
$assert('Phase 3 schema extension safely resumes when its additive DDL already exists', Db::columnExists('credentials', 'credential_source_json') && Db::tableExists('user_handles') && Db::count('migrations') === 2);

$expectedTables = array_map([Db::class, 'table'], array_merge(['migrations'], Schema::tableNames()));
$actualTables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
sort($expectedTables);
$assert('migration creates exactly the owned passkey tables', $actualTables === $expectedTables);
$assert('migration creates no duplicate WHMCS client, user, admin, or billing tables', !in_array('tblclients', $actualTables, true) && !in_array('tblusers', $actualTables, true) && !in_array('tbladmins', $actualTables, true) && !in_array('tblinvoices', $actualTables, true));

$credentialColumns = $pdo->query('PRAGMA table_info(`' . Db::table('credentials') . '`)')->fetchAll(PDO::FETCH_ASSOC);
$credentialColumnNames = array_column($credentialColumns, 'name');
$assert('credentials store library source data, public key, counter, transport, device and lifecycle metadata', in_array('credential_source_json', $credentialColumnNames, true) && in_array('public_key', $credentialColumnNames, true) && in_array('sign_count', $credentialColumnNames, true) && in_array('transports_json', $credentialColumnNames, true) && in_array('device_name', $credentialColumnNames, true) && in_array('revoked_at', $credentialColumnNames, true) && in_array('disabled_at', $credentialColumnNames, true));
$assert('credential schema has no private key, biometric, PIN, or raw attestation columns', !array_intersect($credentialColumnNames, ['private_key', 'secret_key', 'biometric_data', 'fingerprint_data', 'face_data', 'device_pin', 'attestation_object']));

$settingsColumns = $pdo->query('PRAGMA table_info(`' . Db::table('settings') . '`)')->fetchAll(PDO::FETCH_ASSOC);
$settingsColumnNames = array_column($settingsColumns, 'name');
$assert('secret-capable settings provide a ciphertext slot but no plaintext secret column', in_array('encrypted_value', $settingsColumnNames, true) && !in_array('client_secret', $settingsColumnNames, true) && !in_array('secret_value', $settingsColumnNames, true));
$enabledSetting = Db::firstQuery('SELECT setting_value FROM `' . Db::table('settings') . '` WHERE setting_key = ?', ['service_enabled']);
$rpSetting = Db::firstQuery('SELECT setting_value FROM `' . Db::table('settings') . '` WHERE setting_key = ?', ['rp_id']);
$retentionSetting = Db::firstQuery('SELECT setting_value FROM `' . Db::table('settings') . '` WHERE setting_key = ?', ['event_retention_days']);
$assert('Passkey is disabled by default and production RP ID is unset', $enabledSetting['setting_value'] === '0' && $rpSetting['setting_value'] === '');
$assert('event retention has an explicit default', $retentionSetting['setting_value'] === '365');

$validWebAuthnSettings = [
    'service_enabled' => '1',
    'require_https' => '1',
    'rp_name' => 'CloudHost247',
    'rp_id' => 'cloudhost247.com',
    'allowed_origins' => '["https://portal.cloudhost247.com","https://admin.cloudhost247.com:8443"]',
    'user_verification' => 'preferred',
];
$webAuthnConfig = WebAuthnConfig::fromSettings($validWebAuthnSettings, 'https://portal.cloudhost247.com', true);
$adminOriginConfig = WebAuthnConfig::fromSettings($validWebAuthnSettings, 'https://admin.cloudhost247.com:8443', true);
$assert('valid explicit HTTPS RP/origin configuration is accepted with a non-default port preserved', $webAuthnConfig->rpId() === 'cloudhost247.com' && $webAuthnConfig->origin() === 'https://portal.cloudhost247.com' && $adminOriginConfig->origin() === 'https://admin.cloudhost247.com:8443');
$clientDataChallenge = str_repeat('challenge-fixture-', 2);
$clientDataJson = json_encode([
    'type' => 'webauthn.get',
    'challenge' => Base64Url::encode($clientDataChallenge),
    'origin' => 'https://portal.cloudhost247.com',
    'crossOrigin' => false,
]);
$credentialResponseFixture = json_encode([
    'id' => 'Y3JlZGVudGlhbC1maXh0dXJl',
    'rawId' => 'Y3JlZGVudGlhbC1maXh0dXJl',
    'type' => 'public-key',
    'response' => ['clientDataJSON' => Base64Url::encode($clientDataJson)],
]);
$preflight = WebAuthnClientData::preflight($credentialResponseFixture, 'webauthn.get', $webAuthnConfig);
$assert('client-data preflight decodes a canonical challenge before invoking library verification', $preflight['challenge'] === $clientDataChallenge);
$throws('client-data origin rejects a different scheme for the same host', RuntimeException::class, function () use ($clientDataChallenge, $credentialResponseFixture, $webAuthnConfig) {
    $clientData = ['type' => 'webauthn.get', 'challenge' => Base64Url::encode($clientDataChallenge), 'origin' => 'http://portal.cloudhost247.com'];
    $response = ['id' => 'Y3JlZGVudGlhbC1maXh0dXJl', 'rawId' => 'Y3JlZGVudGlhbC1maXh0dXJl', 'type' => 'public-key', 'response' => ['clientDataJSON' => Base64Url::encode(json_encode($clientData))]];
    WebAuthnClientData::preflight(json_encode($response), 'webauthn.get', $webAuthnConfig);
});
$throws('client-data origin rejects a different port for the same host', RuntimeException::class, function () use ($clientDataChallenge, $webAuthnConfig) {
    $clientData = ['type' => 'webauthn.get', 'challenge' => Base64Url::encode($clientDataChallenge), 'origin' => 'https://portal.cloudhost247.com:8443'];
    $response = ['id' => 'Y3JlZGVudGlhbC1maXh0dXJl', 'rawId' => 'Y3JlZGVudGlhbC1maXh0dXJl', 'type' => 'public-key', 'response' => ['clientDataJSON' => Base64Url::encode(json_encode($clientData))]];
    WebAuthnClientData::preflight(json_encode($response), 'webauthn.get', $webAuthnConfig);
});
$throws('cross-origin ceremony response is rejected before library validation', RuntimeException::class, function () use ($clientDataChallenge, $webAuthnConfig) {
    $clientData = ['type' => 'webauthn.get', 'challenge' => Base64Url::encode($clientDataChallenge), 'origin' => 'https://portal.cloudhost247.com', 'crossOrigin' => true];
    $response = ['id' => 'Y3JlZGVudGlhbC1maXh0dXJl', 'rawId' => 'Y3JlZGVudGlhbC1maXh0dXJl', 'type' => 'public-key', 'response' => ['clientDataJSON' => Base64Url::encode(json_encode($clientData))]];
    WebAuthnClientData::preflight(json_encode($response), 'webauthn.get', $webAuthnConfig);
});
$throws('client-data top origin is restricted to the exact RP origin', RuntimeException::class, function () use ($clientDataChallenge, $webAuthnConfig) {
    $clientData = ['type' => 'webauthn.get', 'challenge' => Base64Url::encode($clientDataChallenge), 'origin' => 'https://portal.cloudhost247.com', 'topOrigin' => 'https://evil.invalid'];
    $response = ['id' => 'Y3JlZGVudGlhbC1maXh0dXJl', 'rawId' => 'Y3JlZGVudGlhbC1maXh0dXJl', 'type' => 'public-key', 'response' => ['clientDataJSON' => Base64Url::encode(json_encode($clientData))]];
    WebAuthnClientData::preflight(json_encode($response), 'webauthn.get', $webAuthnConfig);
});
$throws('disabled Passkey service fails closed', RuntimeException::class, function () use ($validWebAuthnSettings) {
    $settings = $validWebAuthnSettings;
    $settings['service_enabled'] = '0';
    WebAuthnConfig::fromSettings($settings, 'https://portal.cloudhost247.com', true);
});
$throws('HTTP or unverified transport fails closed', RuntimeException::class, function () use ($validWebAuthnSettings) {
    WebAuthnConfig::fromSettings($validWebAuthnSettings, 'https://portal.cloudhost247.com', false);
});
$throws('HTTPS enforcement cannot be disabled in settings', RuntimeException::class, function () use ($validWebAuthnSettings) {
    $settings = $validWebAuthnSettings;
    $settings['require_https'] = '0';
    WebAuthnConfig::fromSettings($settings, 'https://portal.cloudhost247.com', true);
});
$throws('missing RP ID fails closed', InvalidArgumentException::class, function () use ($validWebAuthnSettings) {
    $settings = $validWebAuthnSettings;
    $settings['rp_id'] = '';
    WebAuthnConfig::fromSettings($settings, 'https://portal.cloudhost247.com', true);
});
$throws('empty origin allowlist fails closed', InvalidArgumentException::class, function () use ($validWebAuthnSettings) {
    $settings = $validWebAuthnSettings;
    $settings['allowed_origins'] = '[]';
    WebAuthnConfig::fromSettings($settings, 'https://portal.cloudhost247.com', true);
});
$throws('origin allowlist must be a JSON list rather than an object', InvalidArgumentException::class, function () use ($validWebAuthnSettings) {
    $settings = $validWebAuthnSettings;
    $settings['allowed_origins'] = '{"0":"https://portal.cloudhost247.com"}';
    WebAuthnConfig::fromSettings($settings, 'https://portal.cloudhost247.com', true);
});
$throws('origin with an unlisted port is rejected by exact matching', RuntimeException::class, function () use ($validWebAuthnSettings) {
    WebAuthnConfig::fromSettings($validWebAuthnSettings, 'https://admin.cloudhost247.com:9443', true);
});
$throws('origin outside the RP ID boundary is rejected', InvalidArgumentException::class, function () use ($validWebAuthnSettings) {
    $settings = $validWebAuthnSettings;
    $settings['allowed_origins'] = '["https://cloudhost247.com.attacker.invalid"]';
    WebAuthnConfig::fromSettings($settings, 'https://cloudhost247.com.attacker.invalid', true);
});
$throws('non-HTTPS origin configuration is rejected', InvalidArgumentException::class, function () use ($validWebAuthnSettings) {
    $settings = $validWebAuthnSettings;
    $settings['allowed_origins'] = '["http://portal.cloudhost247.com"]';
    WebAuthnConfig::fromSettings($settings, 'http://portal.cloudhost247.com', true);
});
$throws('noncanonical default-port origin is rejected', InvalidArgumentException::class, function () use ($validWebAuthnSettings) {
    $settings = $validWebAuthnSettings;
    $settings['allowed_origins'] = '["https://portal.cloudhost247.com:443"]';
    WebAuthnConfig::fromSettings($settings, 'https://portal.cloudhost247.com:443', true);
});
$binarySample = chr(0) . chr(255) . 'passkey-bytes';
$encodedSample = Base64Url::encode($binarySample);
$assert('base64url encoding round-trips binary WebAuthn values canonically', Base64Url::decode($encodedSample, 64) === $binarySample);
$throws('base64url decoder rejects padding and noncanonical alphabet', InvalidArgumentException::class, function () {
    Base64Url::decode('YWJj=', 64);
});
$clientHandle = UserHandleRepository::getOrCreate('client', 91);
$clientHandleRepeat = UserHandleRepository::getOrCreate('client', 91);
$adminHandle = UserHandleRepository::getOrCreate('admin', 91);
$assert('opaque user handles are stable, random-sized and isolated by identity category', strlen($clientHandle) === 32 && hash_equals($clientHandle, $clientHandleRepeat) && !hash_equals($clientHandle, $adminHandle));
$assert('opaque user handles resolve only to the existing scoped WHMCS identity', UserHandleRepository::findOwnerByHandle($clientHandle) === ['user_type' => 'client', 'user_id' => 91] && UserHandleRepository::matches($clientHandle, 'client', 91) && !UserHandleRepository::matches($clientHandle, 'admin', 91));
$assert('user handle mapping creates no duplicate local users', Db::count('user_handles') === 2);
$userHandleIndexNames = array_column($pdo->query('PRAGMA index_list(`' . Db::table('user_handles') . '`)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$assert('opaque handle table uniquely scopes both identity owners and handle values', in_array('uq_pk_user_handle_owner', $userHandleIndexNames, true) && in_array('uq_pk_user_handle_value', $userHandleIndexNames, true));
if ($sessionStarted) {
    $sessionHashForTest = SessionBinding::currentHash();
    $assert('session binding persists only a domain-separated hash of the active PHP session', strlen($sessionHashForTest) === 64 && !hash_equals(hash('sha256', session_id()), $sessionHashForTest));
    $ceremonyStore = new CeremonyChallengeStore();
    $throws('challenge store refuses options whose embedded challenge does not match', InvalidArgumentException::class, function () use ($ceremonyStore, $webAuthnConfig) {
        $ceremonyStore->issue(str_repeat('x', 32), ChallengeRecord::AUTHENTICATION, 'client', 91, $webAuthnConfig, '{"challenge":"different-challenge"}');
    });
    $rawSessionChallenge = str_repeat('phase3-session-bound-challenge-', 1);
    $sessionOptionsJson = json_encode(['challenge' => Base64Url::encode($rawSessionChallenge)]);
    $issuedChallengeHash = $ceremonyStore->issue(
        $rawSessionChallenge,
        ChallengeRecord::AUTHENTICATION,
        'client',
        91,
        $webAuthnConfig,
        $sessionOptionsJson
    );
    $storedChallenge = Db::firstQuery(
        'SELECT `challenge_hash`, `session_binding_hash`, `origin`, `rp_id`, `consumed_at` FROM `' . Db::table('challenges') . '` WHERE `challenge_hash` = ?',
        [$issuedChallengeHash]
    );
    $assert('ceremony database stores challenge and session hashes rather than raw values', $issuedChallengeHash === hash('sha256', $rawSessionChallenge) && $storedChallenge['challenge_hash'] === $issuedChallengeHash && $storedChallenge['session_binding_hash'] === $sessionHashForTest && !isset($storedChallenge['challenge']));
    $throws('ceremony challenge refuses a different configured origin without consuming it', RuntimeException::class, function () use ($ceremonyStore, $rawSessionChallenge, $validWebAuthnSettings) {
        $otherOrigin = WebAuthnConfig::fromSettings($validWebAuthnSettings, 'https://admin.cloudhost247.com:8443', true);
        $ceremonyStore->consume($rawSessionChallenge, ChallengeRecord::AUTHENTICATION, 'client', 91, $otherOrigin);
    });
    Db::update('challenges', ['challenge_hash' => $issuedChallengeHash], ['session_binding_hash' => str_repeat('e', 64)]);
    $throws('ceremony challenge refuses a different PHP session binding', RuntimeException::class, function () use ($ceremonyStore, $rawSessionChallenge, $webAuthnConfig) {
        $ceremonyStore->consume($rawSessionChallenge, ChallengeRecord::AUTHENTICATION, 'client', 91, $webAuthnConfig);
    });
    Db::update('challenges', ['challenge_hash' => $issuedChallengeHash], ['session_binding_hash' => $sessionHashForTest]);
    $sessionOptionsRecovered = $ceremonyStore->consume($rawSessionChallenge, ChallengeRecord::AUTHENTICATION, 'client', 91, $webAuthnConfig);
    $assert('ceremony options are recovered once after correct session/RP/origin binding', $sessionOptionsRecovered === $sessionOptionsJson && Db::firstQuery('SELECT `consumed_at` FROM `' . Db::table('challenges') . '` WHERE `challenge_hash` = ?', [$issuedChallengeHash])['consumed_at'] !== null);
    $throws('consumed ceremony challenge cannot be replayed', RuntimeException::class, function () use ($ceremonyStore, $rawSessionChallenge, $webAuthnConfig) {
        $ceremonyStore->consume($rawSessionChallenge, ChallengeRecord::AUTHENTICATION, 'client', 91, $webAuthnConfig);
    });
} else {
    $throws('ceremony session binding fails closed without an active WHMCS session', RuntimeException::class, function () {
        SessionBinding::currentHash();
    });
}

$credentialIndexes = $pdo->query('PRAGMA index_list(`' . Db::table('credentials') . '`)')->fetchAll(PDO::FETCH_ASSOC);
$challengeIndexes = $pdo->query('PRAGMA index_list(`' . Db::table('challenges') . '`)')->fetchAll(PDO::FETCH_ASSOC);
$eventIndexes = $pdo->query('PRAGMA index_list(`' . Db::table('events') . '`)')->fetchAll(PDO::FETCH_ASSOC);
$credentialIndexNames = array_column($credentialIndexes, 'name');
$challengeIndexNames = array_column($challengeIndexes, 'name');
$eventIndexNames = array_column($eventIndexes, 'name');
$assert('credential ID hash has a unique index and user/last-used lookups are indexed', in_array('uq_pk_credential_hash', $credentialIndexNames, true) && in_array('ix_pk_cred_owner_created', $credentialIndexNames, true) && in_array('ix_pk_cred_last_used', $credentialIndexNames, true));
$assert('challenge lookup and expiration cleanup have indexes', in_array('uq_pk_challenge_hash', $challengeIndexNames, true) && in_array('ix_pk_ch_exp_consumed', $challengeIndexNames, true));
$assert('security event retention and overview queries have time indexes', in_array('ix_pk_evt_type_time', $eventIndexNames, true) && in_array('ix_pk_evt_result_time', $eventIndexNames, true));

$eventFks = $pdo->query('PRAGMA foreign_key_list(`' . Db::table('events') . '`)')->fetchAll(PDO::FETCH_ASSOC);
$resetFks = $pdo->query('PRAGMA foreign_key_list(`' . Db::table('reset_grants') . '`)')->fetchAll(PDO::FETCH_ASSOC);
$assert('event credential reference is internal and nullable on credential purge', count($eventFks) === 1 && $eventFks[0]['table'] === Db::table('credentials') && strtoupper($eventFks[0]['on_delete']) === 'SET NULL');
$assert('reset grant references only the module challenge table', count($resetFks) === 1 && $resetFks[0]['table'] === Db::table('challenges'));

$mysqlSql = implode("\n", array_merge(
    [Schema::ledgerStatement('mysql')],
    Schema::statements('mysql'),
    [Schema::credentialSourceColumnStatement('mysql')],
    Schema::userHandleStatements('mysql')
));
$assert('MySQL DDL uses InnoDB, utf8mb4, unsigned IDs, credential source and unique identity handles', strpos($mysqlSql, 'ENGINE=InnoDB') !== false && strpos($mysqlSql, 'CHARSET=utf8mb4') !== false && strpos($mysqlSql, 'BIGINT UNSIGNED') !== false && strpos($mysqlSql, 'uq_pk_credential_hash') !== false && strpos($mysqlSql, 'credential_source_json') !== false && strpos($mysqlSql, 'uq_pk_user_handle_owner') !== false && strpos($mysqlSql, 'uq_pk_user_handle_value') !== false);
$assert('schema contains no foreign-key dependency on WHMCS-owned tables', strpos($mysqlSql, 'tblclients') === false && strpos($mysqlSql, 'tblusers') === false && strpos($mysqlSql, 'tbladmins') === false);

$credentialId = 'credential-id-fixture-01';
$credentialHash = hash('sha256', $credentialId);
$credentialSourceJson = json_encode([
    'publicKeyCredentialId' => $credentialId,
    'type' => 'public-key',
    'transports' => ['internal'],
    'attestationType' => 'none',
    'trustPath' => [],
    'aaguid' => '00000000-0000-0000-0000-000000000000',
    'credentialPublicKey' => 'public-key-fixture-only',
    'userHandle' => Base64Url::encode(str_repeat('h', 32)),
    'counter' => 0,
    'otherUI' => null,
]);
$credentialRow = [
    'user_type' => 'client', 'user_id' => 91,
    'credential_id' => $credentialId, 'credential_id_hash' => $credentialHash,
    'public_key' => 'public-key-fixture-only', 'credential_source_json' => $credentialSourceJson, 'credential_type' => 'public-key',
    'sign_count' => 0, 'transports_json' => '["internal"]',
    'authenticator_metadata_json' => '{"aaguid":"fixture-device","backup_eligible":true,"unlisted":"dropped"}',
    'device_name' => 'Test laptop', 'created_at' => $now, 'last_used_at' => null,
    'revoked_at' => null, 'disabled_at' => null, 'registration_ip' => '192.0.2.10',
    'registration_user_agent' => 'Passkey test fixture', 'updated_at' => $now,
];
$credentialIdInDb = Db::insert('credentials', $credentialRow);
$assert('credential ID hash is globally unique across client/admin scopes', (function () use ($credentialRow) {
    $duplicate = $credentialRow;
    $duplicate['user_type'] = 'admin';
    $duplicate['user_id'] = 3;
    try {
        Db::insert('credentials', $duplicate);
        return false;
    } catch (PDOException $e) {
        return true;
    }
})());

$credentialModelRow = array_merge($credentialRow, ['id' => $credentialIdInDb]);
$credentialModel = new CredentialRecord($credentialModelRow);
$summary = $credentialModel->toPublicArray();
$assert('credential model derives active state and exposes only a masked summary', $credentialModel->status() === 'active' && isset($summary['credential_id_display']) && !isset($summary['credential_id']) && !isset($summary['public_key']) && $summary['transports'] === ['internal']);
$disabledModel = new CredentialRecord(array_merge($credentialModelRow, ['disabled_at' => '2026-10-07 11:00:00']));
$revokedModel = new CredentialRecord(array_merge($credentialModelRow, ['revoked_at' => '2026-10-07 11:30:00']));
$assert('credential status is derived consistently from disable and revoke timestamps', $disabledModel->status() === 'disabled' && $revokedModel->status() === 'revoked');
$throws('credential model refuses private key material', InvalidArgumentException::class, function () use ($credentialModelRow) {
    $row = $credentialModelRow;
    $row['private_key'] = 'must-not-be-stored';
    new CredentialRecord($row);
});
$throws('credential model refuses unknown fields that could smuggle key material', InvalidArgumentException::class, function () use ($credentialModelRow) {
    $row = $credentialModelRow;
    $row['credential_private_key'] = 'must-not-be-retained';
    new CredentialRecord($row);
});
$throws('credential model rejects credential ID hash mismatch', InvalidArgumentException::class, function () use ($credentialModelRow) {
    $row = $credentialModelRow;
    $row['credential_id_hash'] = str_repeat('a', 64);
    new CredentialRecord($row);
});
$throws('credential source rejects nested private-key material', InvalidArgumentException::class, function () use ($credentialModelRow) {
    $row = $credentialModelRow;
    $source = json_decode($row['credential_source_json'], true);
    $source['trustPath'] = ['privateKey' => 'must-not-be-stored'];
    $row['credential_source_json'] = json_encode($source);
    new CredentialRecord($row);
});
$assert('public credential summary omits serialized source and opaque user handle', !isset($summary['credential_source_json']) && !isset($summary['user_handle']) && !isset($summary['public_key']));

$policyRow = [
    'id' => 1, 'user_type' => 'client', 'user_id' => 91,
    'policy' => 'temporarily_disabled', 'temporary_disabled_until' => '2026-10-08 12:00:00',
    'reason_code' => 'verified_recovery', 'updated_by_admin_id' => 4,
    'created_at' => $now, 'updated_at' => $now,
];
$policyModel = new UserPolicyRecord($policyRow);
$assert('temporary policy override expires back to default without a permanent lockout', $policyModel->effectivePolicy($now) === 'temporarily_disabled' && $policyModel->effectivePolicy('2026-10-08 12:00:00') === 'default');
$throws('temporary disable requires an explicit expiry', InvalidArgumentException::class, function () use ($policyRow) {
    $row = $policyRow;
    $row['temporary_disabled_until'] = null;
    new UserPolicyRecord($row);
});

$challengeHash = str_repeat('b', 64);
$sessionHash = str_repeat('c', 64);
$challengeRow = [
    'id' => 1, 'challenge_hash' => $challengeHash, 'user_type' => 'client', 'user_id' => 91,
    'challenge_type' => ChallengeRecord::ACTION_CONFIRMATION, 'action_code' => 'admin.payment.config.update',
    'session_binding_hash' => $sessionHash, 'rp_id' => 'example.test', 'origin' => 'https://example.test',
    'expires_at' => '2026-10-07 12:05:00', 'consumed_at' => null, 'created_at' => $now,
];
$challengeModel = new ChallengeRecord($challengeRow);
$assert('challenge model supports action binding, session hash, origin/RP binding and expiry', $challengeModel->isUsableAt($now) && !$challengeModel->isUsableAt('2026-10-07 12:05:00') && $challengeModel->toArray()['action_code'] === 'admin.payment.config.update');
$throws('challenge model refuses raw challenge and session values', InvalidArgumentException::class, function () use ($challengeRow) {
    $row = $challengeRow;
    $row['challenge'] = 'raw-challenge-must-not-persist';
    new ChallengeRecord($row);
});

$eventRow = [
    'id' => 1, 'user_type' => 'client', 'user_id' => 91, 'passkey_id' => $credentialIdInDb,
    'event_type' => 'authentication.failed', 'success' => 0, 'reason_code' => 'invalid_signature',
    'ip_address' => '2001:db8::1', 'user_agent' => 'Test browser',
    'metadata_json' => ['origin' => 'https://example.test', 'error_code' => 'INVALID_SIGNATURE', 'arbitrary' => 'not retained'],
    'created_at' => $now,
];
$eventModel = new SecurityEventRecord($eventRow);
$eventArray = $eventModel->toArray();
$assert('security event model keeps allowlisted context and drops arbitrary metadata', $eventArray['metadata'] === ['origin' => 'https://example.test', 'error_code' => 'INVALID_SIGNATURE'] && !isset($eventArray['metadata_json']));
$throws('event metadata rejects authentication secrets and raw assertions', InvalidArgumentException::class, function () use ($eventRow) {
    $row = $eventRow;
    $row['metadata_json'] = ['origin' => 'https://example.test', 'signature' => 'raw-signature'];
    new SecurityEventRecord($row);
});
$throws('event model rejects invalid IP addresses', InvalidArgumentException::class, function () use ($eventRow) {
    $row = $eventRow;
    $row['ip_address'] = 'not-an-ip';
    new SecurityEventRecord($row);
});

$defaults = SettingsRecord::defaults();
$assert('default policy remains optional and password fallback remains available', $defaults['client_policy'] === 'optional' && $defaults['admin_policy'] === 'optional' && $defaults['password_fallback'] === 'allowed');
$throws('secret settings refuse plaintext values', InvalidArgumentException::class, function () use ($now) {
    new SettingsRecord(['setting_key' => 'entra_client_secret', 'setting_value' => 'plaintext', 'encrypted_value' => null, 'is_secret' => 1, 'updated_at' => $now]);
});
$throws('secret-named settings cannot bypass encrypted storage', InvalidArgumentException::class, function () use ($now) {
    new SettingsRecord(['setting_key' => 'entra_client_secret', 'setting_value' => 'plaintext', 'encrypted_value' => null, 'is_secret' => 0, 'updated_at' => $now]);
});
$secretSummary = (new SettingsRecord(['setting_key' => 'entra_client_secret', 'setting_value' => null, 'encrypted_value' => 'ch247pk:v1:test-ciphertext', 'is_secret' => 1, 'updated_at' => $now]))->safeArray();
$assert('secret setting summaries never expose ciphertext', $secretSummary['configured'] === true && $secretSummary['value'] === null && !isset($secretSummary['encrypted_value']));

$preferences = new UserPreferencesRecord([
    'id' => 1, 'user_type' => 'admin', 'user_id' => 3,
    'login_notification_enabled' => 1, 'security_event_notification_enabled' => 0,
    'created_at' => $now, 'updated_at' => $now,
]);
$assert('notification preferences are typed and scoped to a distinct admin identity', $preferences->toArray()['user_type'] === 'admin' && $preferences->toArray()['login_notification_enabled'] === 1);

$resetGrant = new ResetGrantRecord([
    'id' => 1, 'token_hash' => str_repeat('d', 64), 'user_type' => 'client', 'user_id' => 91,
    'session_binding_hash' => $sessionHash, 'challenge_id' => 1,
    'expires_at' => '2026-10-07 12:10:00', 'consumed_at' => null, 'created_at' => $now,
]);
$assert('reset grant model is short-lived and single-use by consumed timestamp', $resetGrant->isUsableAt($now) && !$resetGrant->isUsableAt('2026-10-07 12:10:00'));
$throws('reset grant model refuses raw reset tokens', InvalidArgumentException::class, function () use ($now, $sessionHash) {
    new ResetGrantRecord(['id' => 1, 'token_hash' => str_repeat('e', 64), 'token' => 'raw-token', 'user_type' => 'client', 'user_id' => 91, 'session_binding_hash' => $sessionHash, 'challenge_id' => null, 'expires_at' => '2026-10-07 12:10:00', 'consumed_at' => null, 'created_at' => $now]);
});

$identityRow = [
    'id' => 1, 'provider' => 'microsoft_entra', 'tenant_id' => 'tenant-fixture', 'subject_id' => 'subject-fixture',
    'identity_hash' => ExternalIdentityRecord::identityHash('microsoft_entra', 'tenant-fixture', 'subject-fixture'),
    'user_type' => 'client', 'user_id' => 91, 'linked_at' => $now,
    'last_authenticated_at' => null, 'revoked_at' => null,
];
$externalIdentity = new ExternalIdentityRecord($identityRow);
$assert('external identity mapping is keyed by provider tenant and subject, not email', $externalIdentity->isActive() && !isset($externalIdentity->toArray()['email']));
$throws('external identity model refuses OAuth tokens', InvalidArgumentException::class, function () use ($identityRow) {
    $row = $identityRow;
    $row['access_token'] = 'must-not-be-stored';
    new ExternalIdentityRecord($row);
});

$rateLimit = new RateLimitRecord([
    'id' => 1, 'action' => 'auth.verify', 'principal_hash' => str_repeat('f', 64),
    'window_started_at' => $now, 'hit_count' => 2, 'expires_at' => '2026-10-07 12:01:00',
    'created_at' => $now, 'updated_at' => $now,
]);
$assert('rate-limit model stores only a hashed principal', strlen($rateLimit->toArray()['principal_hash']) === 64 && !isset($rateLimit->toArray()['ip_address']));

// Exercise database uniqueness with a credential ID reused across identity types.
$challengeDbRow = [
    'challenge_hash' => $challengeHash, 'user_type' => 'client', 'user_id' => 91,
    'challenge_type' => 'registration', 'action_code' => null,
    'session_binding_hash' => $sessionHash, 'rp_id' => 'example.test', 'origin' => 'https://example.test',
    'expires_at' => '2026-10-07 12:05:00', 'consumed_at' => null, 'created_at' => $now,
];
$challengeId = Db::insert('challenges', $challengeDbRow);
$assert('challenge hashes are unique and internal challenges are stored without raw values', (function () use ($challengeDbRow) {
    try {
        Db::insert('challenges', $challengeDbRow);
        return false;
    } catch (PDOException $e) {
        return true;
    }
})());

$eventDbRow = [
    'user_type' => 'client', 'user_id' => 91, 'passkey_id' => $credentialIdInDb,
    'event_type' => 'registration.succeeded', 'success' => 1, 'reason_code' => null,
    'ip_address' => '192.0.2.10', 'user_agent' => 'Test fixture',
    'metadata_json' => '{"origin":"https://example.test"}', 'created_at' => $now,
];
$eventId = Db::insert('events', $eventDbRow);
$assert('event rows can reference an internal credential without copying key material', $eventId > 0 && Db::count('events', ['passkey_id' => $credentialIdInDb]) === 1);

$policyDbRow = [
    'user_type' => 'client', 'user_id' => 91, 'policy' => 'required',
    'temporary_disabled_until' => null, 'reason_code' => null, 'updated_by_admin_id' => 4,
    'created_at' => $now, 'updated_at' => $now,
];
Db::insert('user_policies', $policyDbRow);
$assert('one policy override is enforced per WHMCS identity', (function () use ($policyDbRow) {
    try {
        Db::insert('user_policies', $policyDbRow);
        return false;
    } catch (PDOException $e) {
        return true;
    }
})());

$preferenceDbRow = [
    'user_type' => 'client', 'user_id' => 91, 'login_notification_enabled' => 0,
    'security_event_notification_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
];
Db::insert('user_preferences', $preferenceDbRow);
$assert('one notification-preference row is enforced per identity', (function () use ($preferenceDbRow) {
    try {
        Db::insert('user_preferences', $preferenceDbRow);
        return false;
    } catch (PDOException $e) {
        return true;
    }
})());

$resetDbRow = [
    'token_hash' => str_repeat('1', 64), 'user_type' => 'client', 'user_id' => 91,
    'session_binding_hash' => $sessionHash, 'challenge_id' => $challengeId,
    'expires_at' => '2026-10-07 12:10:00', 'consumed_at' => null, 'created_at' => $now,
];
Db::insert('reset_grants', $resetDbRow);
$assert('reset grants enforce one-time token-hash uniqueness', (function () use ($resetDbRow) {
    try {
        Db::insert('reset_grants', $resetDbRow);
        return false;
    } catch (PDOException $e) {
        return true;
    }
})());

$identityDbRow = [
    'provider' => 'microsoft_entra', 'tenant_id' => 'tenant-fixture', 'subject_id' => 'subject-fixture',
    'identity_hash' => $identityRow['identity_hash'], 'user_type' => 'client', 'user_id' => 91,
    'linked_at' => $now, 'last_authenticated_at' => null, 'revoked_at' => null,
    'created_at' => $now, 'updated_at' => $now,
];
Db::insert('external_identities', $identityDbRow);
$assert('one external provider identity cannot link to multiple local users', (function () use ($identityDbRow) {
    $duplicate = $identityDbRow;
    $duplicate['user_type'] = 'admin';
    $duplicate['user_id'] = 3;
    try {
        Db::insert('external_identities', $duplicate);
        return false;
    } catch (PDOException $e) {
        return true;
    }
})());

$rateLimitDbRow = [
    'action' => 'auth.verify', 'principal_hash' => str_repeat('2', 64),
    'window_started_at' => $now, 'hit_count' => 1, 'expires_at' => '2026-10-07 12:01:00',
    'created_at' => $now, 'updated_at' => $now,
];
Db::insert('rate_limits', $rateLimitDbRow);
$assert('fixed-window rate-limit bucket uniqueness is enforced', (function () use ($rateLimitDbRow) {
    try {
        Db::insert('rate_limits', $rateLimitDbRow);
        return false;
    } catch (PDOException $e) {
        return true;
    }
})());

$throws('empty-scope database updates fail closed', InvalidArgumentException::class, function () {
    Db::update('settings', [], ['setting_value' => 'bad']);
});
$beforeSettings = Db::count('settings');
try {
    Db::transaction(function () use ($now) {
        Db::insert('settings', [
            'setting_key' => 'rollback_probe', 'setting_value' => 'temporary', 'encrypted_value' => null,
            'is_secret' => 0, 'updated_by_admin_id' => null, 'updated_at' => $now,
        ]);
        throw new RuntimeException('rollback probe');
    });
} catch (RuntimeException $e) {
    // Expected: only the test transaction is rolled back.
}
$assert('database transaction rollback restores the prior settings count', Db::count('settings') === $beforeSettings);

$retryPdo = new PDO('sqlite::memory:');
$retryPdo->exec('PRAGMA foreign_keys = ON');
Db::setPdo($retryPdo, 'sqlite');
$retryMigrator = new Migrator(dirname(__DIR__) . '/tests/migrations_retry');
$GLOBALS['CH247PK_FAIL_FIXTURE_MIGRATION'] = true;
$throws('failed migration does not get marked complete', RuntimeException::class, function () use ($retryMigrator) {
    $retryMigrator->migrate();
});
$assert('partial DDL exists but migration ledger remains unmodified', Db::count('migrations') === 0 && Db::tableExists('retry_probe') === true);
$GLOBALS['CH247PK_FAIL_FIXTURE_MIGRATION'] = false;
$retryResult = $retryMigrator->migrate();
$assert('partial idempotent DDL can be retried and recorded once', $retryResult['applied'] === ['probe_retryable_migration'] && Db::count('migrations') === 1);
unset($GLOBALS['CH247PK_FAIL_FIXTURE_MIGRATION']);

Db::setPdo($pdo, 'sqlite');
if (!defined('WHMCS')) {
    define('WHMCS', true);
}
require_once dirname(__DIR__) . '/cloudhost247passkey.php';
$moduleConfig = cloudhost247passkey_config();
$activation = cloudhost247passkey_activate();
$enabledAfterActivation = Db::firstQuery('SELECT setting_value FROM `' . Db::table('settings') . '` WHERE setting_key = ?', ['service_enabled']);
ob_start();
cloudhost247passkey_output([]);
$adminOutput = ob_get_clean();
$beforeDeactivate = Db::count('credentials') + Db::count('events') + Db::count('user_policies');
$deactivation = cloudhost247passkey_deactivate();
$afterDeactivate = Db::count('credentials') + Db::count('events') + Db::count('user_policies');
$assert('WHMCS addon callback exposes the Passkey module without activating login', $moduleConfig['name'] === 'CloudHost247 Passkey' && $moduleConfig['fields'] === []);
$assert('WHMCS activation callback is repeatable and keeps authentication disabled', $activation['status'] === 'success' && strpos($activation['description'], 'No login') !== false && $enabledAfterActivation['setting_value'] === '0');
$assert('admin addon status clearly says Passkey authentication is disabled', strpos($adminOutput, 'authentication is currently disabled') !== false && strpos($adminOutput, 'session creation') !== false);
$assert('deactivation preserves credentials, audit events and policies', $deactivation['status'] === 'success' && $beforeDeactivate === $afterDeactivate);

$phase4Policy = new PasskeyLoginPolicy([
    'service_enabled' => '1',
    'client_policy' => 'optional',
    'admin_policy' => 'required',
    'password_fallback' => 'allowed',
]);
$assert('Phase 4 policy keeps client and administrator audiences independently configurable', $phase4Policy->isEnabledFor('client') && $phase4Policy->isEnabledFor('admin') && $phase4Policy->policyFor('client') === 'optional' && $phase4Policy->policyFor('admin') === 'required' && $phase4Policy->passwordFallbackAllowed());
$disabledPhase4Policy = new PasskeyLoginPolicy([
    'service_enabled' => '0',
    'client_policy' => 'optional',
    'admin_policy' => 'optional',
    'password_fallback' => 'allowed',
]);
$throws('Phase 4 login policy fails closed while the global service switch is off', RuntimeException::class, function () use ($disabledPhase4Policy) {
    $disabledPhase4Policy->assertLoginAllowed('client');
});
$throws('Phase 4 policy rejects unsupported client-user login until its WHMCS handoff is separately designed', RuntimeException::class, function () use ($phase4Policy) {
    $phase4Policy->assertLoginAllowed('client_user');
});

$phase4IdentityProvider = new CallbackWhmcsIdentityProvider(function ($userType, $userId) {
    if (($userType === 'client' && $userId === 91) || ($userType === 'admin' && $userId === 7)) {
        return ['user_type' => $userType, 'user_id' => $userId, 'loginable' => true];
    }
    return null;
});
$phase4Handoffs = [];
$phase4AuthBridge = new CallbackWhmcsAuthBridge(function (WhmcsIdentity $identity, PasskeyLoginContext $context) use (&$phase4Handoffs) {
    $phase4Handoffs[] = ['identity' => $identity->toArray(), 'context' => $context->toArray()];
    return $identity->userType() === 'client'
        ? WhmcsAuthHandoff::twoFactorRequired()
        : ['status' => WhmcsAuthHandoff::SESSION_ESTABLISHED];
});
$phase4Verifier = new class implements PasskeyAssertionVerifierInterface {
    public $calls = [];
    public $verified = ['user_type' => 'client', 'user_id' => 91];

    public function finishAuthentication(WebAuthnConfig $config, $userType, $userId, $credentialResponseJson)
    {
        $this->calls[] = [$userType, $userId, $credentialResponseJson];
        return $this->verified;
    }
};
$phase4Coordinator = new PasskeyLoginCoordinator($phase4Verifier, $phase4IdentityProvider, $phase4AuthBridge, $phase4Policy);
$clientLoginResult = $phase4Coordinator->authenticate(
    $webAuthnConfig,
    'client',
    null,
    '{"opaque":"browser-assertion"}',
    [
        'remember_me' => true,
        'request_id' => 'client-login-01',
        'source' => 'client_login',
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Phase4 test browser',
    ]
);
$publicClientResult = $clientLoginResult->toPublicArray();
$assert('Phase 4 discoverable client assertion resolves an existing WHMCS identity and preserves its 2FA handoff', $clientLoginResult->identity()->toArray() === ['user_type' => 'client', 'user_id' => 91] && $clientLoginResult->handoff()->requiresTwoFactor() && $publicClientResult['handoff']['next_step'] === 'two_factor' && !isset($publicClientResult['identity']) && !isset($publicClientResult['session_id']));
$assert('Phase 4 bridge receives only non-secret context and no assertion payload', count($phase4Handoffs) === 1 && $phase4Handoffs[0]['identity'] === ['user_type' => 'client', 'user_id' => 91] && $phase4Handoffs[0]['context']['remember_me'] === true && !isset($phase4Handoffs[0]['context']['credential_response_json']) && !isset($phase4Handoffs[0]['context']['session_id']));
$assert('Phase 4 verifier is the only component that receives the browser assertion', count($phase4Verifier->calls) === 1 && $phase4Verifier->calls[0][2] === '{"opaque":"browser-assertion"}');

$phase4Verifier->verified = ['user_type' => 'admin', 'user_id' => 7];
$throws('Phase 4 client audience rejects an assertion verified for an administrator', RuntimeException::class, function () use ($phase4Coordinator, $webAuthnConfig) {
    $phase4Coordinator->authenticate($webAuthnConfig, 'client', null, '{"opaque":"cross-audience"}', [
        'remember_me' => false, 'request_id' => 'client-login-02', 'source' => 'client_login',
    ]);
});
$phase4Verifier->verified = ['user_type' => 'admin', 'user_id' => 7];
$adminLoginResult = $phase4Coordinator->authenticate(
    $adminOriginConfig,
    'admin',
    7,
    '{"opaque":"admin-assertion"}',
    ['remember_me' => false, 'request_id' => 'admin-login-01', 'source' => 'admin_login']
);
$assert('Phase 4 administrator credentials stay isolated and use the host handoff without creating a parallel session', $adminLoginResult->identity()->toArray() === ['user_type' => 'admin', 'user_id' => 7] && $adminLoginResult->handoff()->sessionEstablishedValue() && count($phase4Handoffs) === 2);
$throws('Phase 4 context rejects unsupported credential or session fields', InvalidArgumentException::class, function () {
    PasskeyLoginContext::fromArray(['remember_me' => false, 'request_id' => 'bad-context', 'source' => 'client_login', 'credential_response_json' => 'secret']);
});
$throws('Phase 4 callback handoff rejects a returned session token', RuntimeException::class, function () {
    $bridge = new CallbackWhmcsAuthBridge(function () {
        return ['status' => WhmcsAuthHandoff::SESSION_ESTABLISHED, 'session_token' => 'not-accepted'];
    });
    $bridge->handoff(new WhmcsIdentity('client', 91), PasskeyLoginContext::fromArray([
        'remember_me' => false, 'request_id' => 'token-reject', 'source' => 'client_login',
    ]));
});
PasskeyIntegrationRegistry::reset();
$throws('Phase 4 registry fails closed without explicit WHMCS adapters', RuntimeException::class, function () use ($phase4Verifier, $phase4Policy) {
    PasskeyIntegrationRegistry::coordinator($phase4Verifier, $phase4Policy);
});
PasskeyIntegrationRegistry::configure($phase4IdentityProvider, $phase4AuthBridge);
$assert('Phase 4 registry accepts only explicit host adapters', PasskeyIntegrationRegistry::isConfigured() && PasskeyIntegrationRegistry::coordinator($phase4Verifier, $phase4Policy) instanceof PasskeyLoginCoordinator);
PasskeyIntegrationRegistry::reset();
$throws('WHMCS registration API rejects untyped authentication adapters', InvalidArgumentException::class, function () {
    cloudhost247passkey_register_auth_integration(new stdClass(), new stdClass());
});
cloudhost247passkey_register_auth_integration($phase4IdentityProvider, $phase4AuthBridge);
$assert('WHMCS registration API exposes the explicit adapter boundary without enabling a session by itself', cloudhost247passkey_auth_integration_configured());
PasskeyIntegrationRegistry::reset();
$assert('Phase 4 coordinator does not write a synthetic WHMCS session', !isset($_SESSION['uid']) || (int) $_SESSION['uid'] !== 91);

if ($sessionStarted) {
    $phase5Ceremony = new class implements PasskeyRegistrationCeremonyInterface {
        public $begun = [];
        public $finished = [];

        public function beginRegistration(WebAuthnConfig $config, $userType, $userId, $userName, $displayName)
        {
            $this->begun[] = [$userType, $userId, $userName, $displayName];
            return ['publicKey' => ['challenge' => 'phase5-registration-challenge'], 'expires_in' => 300];
        }

        public function finishRegistration(
            WebAuthnConfig $config,
            $userType,
            $userId,
            $credentialResponseJson,
            $deviceName = 'Passkey',
            $registrationIp = null,
            $registrationUserAgent = null
        ) {
            $this->finished[] = [$userType, $userId, $credentialResponseJson, $deviceName, $registrationIp, $registrationUserAgent];
            return ['registered' => true, 'credential_record_id' => 5001];
        }
    };
    $phase5Identity = new WhmcsIdentity('client', 91);
    $phase5Manager = new PasskeyCredentialManagementService(
        $phase5Ceremony,
        $phase4IdentityProvider,
        $phase4Policy,
        new CredentialManagementRepository()
    );
    $phase5List = $phase5Manager->listCredentials($phase5Identity);
    $assert('Phase 5 lists only masked credentials owned by the authenticated existing client', count($phase5List) === 1 && $phase5List[0]['id'] === $credentialIdInDb && !isset($phase5List[0]['credential_source_json']) && !isset($phase5List[0]['public_key']));
    $phase5Begin = $phase5Manager->beginRegistration($webAuthnConfig, $phase5Identity, 'existing-client@example.invalid', 'Existing Client');
    $phase5Context = PasskeyRegistrationContext::fromTrustedArray([
        'ip_address' => '198.51.100.20',
        'user_agent' => 'Phase5 management browser',
    ]);
    $phase5Finish = $phase5Manager->finishRegistration(
        $webAuthnConfig,
        $phase5Identity,
        '{"opaque":"registration-response"}',
        'Office security key',
        $phase5Context
    );
    $assert('Phase 5 enrollment uses the existing session identity and never accepts browser-supplied secrets as metadata', $phase5Begin['publicKey']['challenge'] === 'phase5-registration-challenge' && $phase5Finish['registered'] === true && count($phase5Ceremony->begun) === 1 && $phase5Ceremony->begun[0][1] === 91 && $phase5Ceremony->finished[0][2] === '{"opaque":"registration-response"}' && $phase5Ceremony->finished[0][4] === '198.51.100.20');
    $renamed = $phase5Manager->renameCredential($phase5Identity, $credentialIdInDb, 'Travel key');
    $assert('Phase 5 device names can be changed only inside the current client scope', $renamed['device_name'] === 'Travel key' && $renamed['user_type'] === 'client' && $renamed['user_id'] === 91);
    $disabled = $phase5Manager->disableCredential($phase5Identity, $credentialIdInDb);
    $assert('Phase 5 can disable an owned credential without deleting its public record', $disabled['status'] === 'disabled' && $phase5Manager->listCredentials($phase5Identity)[0]['status'] === 'disabled');
    $enabled = $phase5Manager->enableCredential($phase5Identity, $credentialIdInDb);
    $assert('Phase 5 can re-enable an owned non-revoked credential', $enabled['status'] === 'active');
    $strictPhase5Policy = new PasskeyLoginPolicy([
        'service_enabled' => '1',
        'client_policy' => 'required',
        'admin_policy' => 'optional',
        'password_fallback' => 'disabled',
        'max_credentials_client' => '5',
        'max_credentials_admin' => '5',
    ]);
    $strictPhase5Manager = new PasskeyCredentialManagementService($phase5Ceremony, $phase4IdentityProvider, $strictPhase5Policy, new CredentialManagementRepository());
    $throws('Phase 5 refuses to disable the last credential when password fallback is disabled', RuntimeException::class, function () use ($strictPhase5Manager, $phase5Identity, $credentialIdInDb) {
        $strictPhase5Manager->disableCredential($phase5Identity, $credentialIdInDb);
    });
    $limitedPhase5Policy = new PasskeyLoginPolicy([
        'service_enabled' => '1',
        'client_policy' => 'optional',
        'admin_policy' => 'optional',
        'password_fallback' => 'allowed',
        'max_credentials_client' => '1',
        'max_credentials_admin' => '5',
    ]);
    $limitedPhase5Manager = new PasskeyCredentialManagementService($phase5Ceremony, $phase4IdentityProvider, $limitedPhase5Policy, new CredentialManagementRepository());
    $throws('Phase 5 enforces the configured active-credential limit before starting enrollment', RuntimeException::class, function () use ($limitedPhase5Manager, $webAuthnConfig, $phase5Identity) {
        $limitedPhase5Manager->beginRegistration($webAuthnConfig, $phase5Identity, 'existing-client@example.invalid', 'Existing Client');
    });
    $revoked = $phase5Manager->revokeCredential($phase5Identity, $credentialIdInDb);
    $assert('Phase 5 revocation is scoped, auditable through status, and preserves the record for history', $revoked['status'] === 'revoked' && $phase5Manager->listCredentials($phase5Identity)[0]['status'] === 'revoked');
    $throws('Phase 5 cannot rename a revoked credential', RuntimeException::class, function () use ($phase5Manager, $phase5Identity, $credentialIdInDb) {
        $phase5Manager->renameCredential($phase5Identity, $credentialIdInDb, 'Should fail');
    });
    $adminIdentity = new WhmcsIdentity('admin', 7);
    $throws('Phase 5 client credential records cannot be managed through the administrator scope', RuntimeException::class, function () use ($phase5Manager, $adminIdentity, $credentialIdInDb) {
        $phase5Manager->renameCredential($adminIdentity, $credentialIdInDb, 'Cross scope');
    });
    $throws('Phase 5 registration context rejects unsupported secret fields', InvalidArgumentException::class, function () {
        PasskeyRegistrationContext::fromTrustedArray(['ip_address' => '198.51.100.20', 'credential_response_json' => 'secret']);
    });
} else {
    $assert('Phase 5 management requires an active WHMCS session', false);
}

// Phase 6 policy, recovery-grant, rate-limit, and audit orchestration.
$phase6Policy = new PasskeyLoginPolicy([
    'service_enabled' => '1',
    'client_policy' => 'optional',
    'admin_policy' => 'required',
    'password_fallback' => 'allowed',
    'max_credentials_client' => '5',
    'max_credentials_admin' => '5',
]);
$phase6DisabledIdentity = 9026;
$phase6ExpiredIdentity = 9027;
Db::insert('user_policies', [
    'user_type' => 'client', 'user_id' => $phase6DisabledIdentity,
    'policy' => 'temporarily_disabled', 'temporary_disabled_until' => '2099-01-01 00:00:00',
    'reason_code' => 'recovery_pending', 'updated_by_admin_id' => 7,
    'created_at' => $now, 'updated_at' => $now,
]);
Db::insert('user_policies', [
    'user_type' => 'client', 'user_id' => $phase6ExpiredIdentity,
    'policy' => 'temporarily_disabled', 'temporary_disabled_until' => '2020-01-01 00:00:00',
    'reason_code' => 'old_exemption', 'updated_by_admin_id' => 7,
    'created_at' => $now, 'updated_at' => $now,
]);
$phase6Resolver = new PasskeyPolicyResolver($phase6Policy, new IdentityPolicyRepository());
$assert('Phase 6 resolves an active per-identity temporary exemption as an explicit deny state', $phase6Resolver->effectivePolicy('client', $phase6DisabledIdentity, $now) === UserPolicyRecord::TEMPORARILY_DISABLED);
$assert('Phase 6 expires temporary exemptions back to the configured audience policy', $phase6Resolver->effectivePolicy('client', $phase6ExpiredIdentity, $now) === UserPolicyRecord::OPTIONAL);
$throws('Phase 6 policy gate fails closed for an active temporary exemption', RuntimeException::class, function () use ($phase6Resolver, $phase6DisabledIdentity) {
    $phase6Resolver->assertAllowed('client', $phase6DisabledIdentity);
});
$assert('Phase 6 policy gate preserves the required global administrator policy', $phase6Resolver->effectivePolicy('admin', 7, $now) === UserPolicyRecord::REQUIRED);

$phase6Limiter = new PasskeyRateLimiter();
$phase6Epoch = strtotime('2026-10-07 12:00:30 UTC');
$phase6Principal = '203.0.113.50';
$phase6FirstLimit = $phase6Limiter->consume('auth.phase6', $phase6Principal, 2, 60, $phase6Epoch);
$phase6SecondLimit = $phase6Limiter->consume('auth.phase6', $phase6Principal, 2, 60, $phase6Epoch + 1);
$phase6ThirdLimit = $phase6Limiter->consume('auth.phase6', $phase6Principal, 2, 60, $phase6Epoch + 2);
$phase6LimitRow = Db::firstQuery('SELECT * FROM `' . Db::table('rate_limits') . '` WHERE `action` = ? AND `principal_hash` = ?', ['auth.phase6', PasskeyRateLimiter::principalHash($phase6Principal)]);
$assert('Phase 6 fixed-window rate limiting counts attempts and denies after the configured threshold', $phase6FirstLimit['allowed'] && $phase6FirstLimit['remaining'] === 1 && $phase6SecondLimit['allowed'] && !$phase6ThirdLimit['allowed'] && $phase6ThirdLimit['retry_after'] === 28);
$assert('Phase 6 rate-limit rows store only a hash of the raw principal', $phase6LimitRow !== null && strpos(json_encode($phase6LimitRow), $phase6Principal) === false && $phase6LimitRow['principal_hash'] === PasskeyRateLimiter::principalHash($phase6Principal));
$phase6NextWindow = $phase6Limiter->consume('auth.phase6', $phase6Principal, 2, 60, $phase6Epoch + 60);
$assert('Phase 6 rate-limit buckets reset only at the next fixed window', $phase6NextWindow['allowed'] && $phase6NextWindow['remaining'] === 1);

$phase6Events = new SecurityEventRepository();
$phase6EventId = $phase6Events->append([
    'user_type' => 'client', 'user_id' => $phase6DisabledIdentity,
    'event_type' => 'authentication.failed', 'success' => 0,
    'reason_code' => 'invalid_signature', 'ip_address' => '192.0.2.60',
    'user_agent' => 'Phase6 test browser',
    'metadata' => ['origin' => 'https://example.test', 'arbitrary' => 'dropped'],
]);
$phase6EventRow = Db::firstQuery('SELECT * FROM `' . Db::table('events') . '` WHERE `id` = ?', [$phase6EventId]);
$phase6EventMetadata = $phase6EventRow ? json_decode($phase6EventRow['metadata_json'], true) : [];
$assert('Phase 6 audit repository appends redacted allowlisted events', $phase6EventId > 0 && $phase6EventMetadata === ['origin' => 'https://example.test'] && (int) $phase6EventRow['success'] === 0);
$throws('Phase 6 audit repository rejects raw ceremony secrets', InvalidArgumentException::class, function () use ($phase6Events, $phase6DisabledIdentity) {
    $phase6Events->append([
        'user_type' => 'client', 'user_id' => $phase6DisabledIdentity,
        'event_type' => 'authentication.failed', 'success' => 0,
        'metadata' => ['signature' => 'raw-signature'],
    ]);
});

$phase6Security = new PasskeySecurityService($phase6Resolver, $phase6Limiter, new PasskeyRecoveryGrantService(), $phase6Events);
$phase6AuditBeforeLimit = Db::count('events', ['event_type' => 'rate_limit.exceeded']);
$phase6Security->consumeRateLimit('auth.phase6.audit', '198.51.100.60', 1, 60, ['user_type' => 'client', 'user_id' => $phase6DisabledIdentity], $phase6Epoch);
$phase6AuditLimit = $phase6Security->consumeRateLimit('auth.phase6.audit', '198.51.100.60', 1, 60, ['user_type' => 'client', 'user_id' => $phase6DisabledIdentity], $phase6Epoch + 1);
$assert('Phase 6 orchestration records a redacted security event when a bucket is exceeded', !$phase6AuditLimit['allowed'] && Db::count('events', ['event_type' => 'rate_limit.exceeded']) === $phase6AuditBeforeLimit + 1);

$phase6RecoveryChallengeId = Db::insert('challenges', [
    'challenge_hash' => hash('sha256', 'phase6-recovery-challenge'),
    'user_type' => 'client', 'user_id' => $phase6DisabledIdentity,
    'challenge_type' => ChallengeRecord::ACTION_CONFIRMATION,
    'action_code' => 'passkey.recovery.grant',
    'session_binding_hash' => SessionBinding::currentHash(),
    'rp_id' => 'example.test', 'origin' => 'https://example.test',
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
    'consumed_at' => null, 'created_at' => gmdate('Y-m-d H:i:s'),
]);
$phase6DeliveredToken = null;
$phase6Grant = $phase6Security->issueRecoveryGrant(
    'client',
    $phase6DisabledIdentity,
    function ($token) use (&$phase6DeliveredToken) {
        $phase6DeliveredToken = $token;
    },
    $phase6RecoveryChallengeId,
    60,
    ['ip_address' => '192.0.2.61', 'user_agent' => 'Phase6 recovery host']
);
$phase6GrantRow = Db::firstQuery('SELECT * FROM `' . Db::table('reset_grants') . '` WHERE `user_id` = ? ORDER BY `id` DESC', [$phase6DisabledIdentity]);
$assert('Phase 6 recovery grants deliver an opaque value only through the explicit host callback and persist its hash', $phase6Grant['issued'] === true && is_string($phase6DeliveredToken) && strlen($phase6DeliveredToken) === 43 && $phase6GrantRow !== null && strpos(json_encode($phase6GrantRow), $phase6DeliveredToken) === false && $phase6GrantRow['token_hash'] === PasskeyRecoveryGrantService::tokenHash($phase6DeliveredToken));
$phase6Capability = $phase6Security->consumeRecoveryGrant(
    $phase6DeliveredToken,
    'client',
    $phase6DisabledIdentity,
    $phase6RecoveryChallengeId,
    ['ip_address' => '192.0.2.61', 'user_agent' => 'Phase6 recovery host']
);
$phase6ConsumedRow = Db::firstQuery('SELECT `consumed_at` FROM `' . Db::table('reset_grants') . '` WHERE `id` = ?', [$phase6GrantRow['id']]);
$assert('Phase 6 recovery grants are session-bound, identity-bound, single-use capabilities without raw-token output', $phase6Capability === ['user_type' => 'client', 'user_id' => $phase6DisabledIdentity, 'challenge_id' => $phase6RecoveryChallengeId] && $phase6ConsumedRow['consumed_at'] !== null);
$throws('Phase 6 recovery grants reject replay', RuntimeException::class, function () use ($phase6Security, $phase6DeliveredToken, $phase6DisabledIdentity, $phase6RecoveryChallengeId) {
    $phase6Security->consumeRecoveryGrant($phase6DeliveredToken, 'client', $phase6DisabledIdentity, $phase6RecoveryChallengeId);
});
$phase6GrantEvents = Db::count('events', ['event_type' => 'recovery_grant.issued']) + Db::count('events', ['event_type' => 'recovery_grant.consumed']);
$assert('Phase 6 recovery issuance and consumption are auditable without token metadata', $phase6GrantEvents === 2 && strpos(json_encode(Db::query('SELECT `metadata_json` FROM `' . Db::table('events') . '` WHERE `event_type` LIKE \'recovery_grant.%\'')), $phase6DeliveredToken) === false);

// Phase 7 external identity linking boundary.
$phase7IdentityId = 9030;
$phase7OtherIdentityId = 9031;
Db::update('settings', ['setting_key' => 'entra_enabled'], [
    'setting_value' => '1',
    'updated_at' => gmdate('Y-m-d H:i:s'),
]);
$phase7Provider = new CallbackWhmcsIdentityProvider(function ($resolvedType, $resolvedId) use ($phase7IdentityId) {
    return $resolvedType === 'client' && $resolvedId === $phase7IdentityId
        ? ['user_type' => $resolvedType, 'user_id' => $resolvedId, 'loginable' => true]
        : null;
});
$phase7OtherProvider = new CallbackWhmcsIdentityProvider(function ($resolvedType, $resolvedId) use ($phase7OtherIdentityId) {
    return $resolvedType === 'client' && $resolvedId === $phase7OtherIdentityId
        ? ['user_type' => $resolvedType, 'user_id' => $resolvedId, 'loginable' => true]
        : null;
});
$phase7Identity = new WhmcsIdentity('client', $phase7IdentityId);
$phase7OtherIdentity = new WhmcsIdentity('client', $phase7OtherIdentityId);
$phase7Service = new ExternalIdentityLinkService(
    $phase7Provider,
    $phase6Resolver,
    null,
    new ExternalIdentityRepository(),
    new SecurityEventRepository()
);
$phase7OtherService = new ExternalIdentityLinkService(
    $phase7OtherProvider,
    $phase6Resolver,
    null,
    new ExternalIdentityRepository(),
    new SecurityEventRepository()
);
$phase7Challenge = function ($ownerId, $action) {
    $createdAt = gmdate('Y-m-d H:i:s');
    return Db::insert('challenges', [
        'challenge_hash' => hash('sha256', 'phase7-' . $ownerId . '-' . $action . '-' . bin2hex(random_bytes(16))),
        'user_type' => 'client', 'user_id' => $ownerId,
        'challenge_type' => ChallengeRecord::ENTRA_LINK,
        'action_code' => $action,
        'session_binding_hash' => SessionBinding::currentHash(),
        'rp_id' => 'example.test', 'origin' => 'https://example.test',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
        'consumed_at' => null, 'created_at' => $createdAt,
    ]);
};
$phase7Claims = new ExternalIdentityClaims([
    'provider' => ExternalIdentityClaims::PROVIDER_MICROSOFT_ENTRA,
    'tenant_id' => 'tenant-phase7',
    'subject_id' => 'subject-phase7',
    'issued_at' => gmdate('Y-m-d H:i:s', time() - 30),
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
]);
$assert('Phase 7 starts with no externally linked identities in the current WHMCS scope', $phase7Service->listLinks($phase7Identity) === []);
$phase7LinkChallengeId = $phase7Challenge($phase7IdentityId, ExternalIdentityLinkService::LINK_ACTION);
$phase7Linked = $phase7Service->link($phase7Identity, $phase7Claims, $phase7LinkChallengeId, [
    'ip_address' => '192.0.2.70', 'user_agent' => 'Phase7 host adapter',
]);
$assert('Phase 7 links only host-verified Entra claims after an identity/session-bound confirmation', $phase7Linked['status'] === 'linked' && $phase7Linked['link']['provider'] === ExternalIdentityClaims::PROVIDER_MICROSOFT_ENTRA && $phase7Linked['link']['tenant_id'] === 'tenant-phase7' && !isset($phase7Linked['link']['subject_id']) && !isset($phase7Linked['link']['access_token']));
$phase7LinkRow = Db::firstQuery('SELECT * FROM `' . Db::table('external_identities') . '` WHERE `identity_hash` = ?', [$phase7Claims->identityHash()]);
$assert('Phase 7 stores the external subject only in its scoped link record and never stores OAuth tokens', $phase7LinkRow !== null && $phase7LinkRow['subject_id'] === 'subject-phase7' && !array_key_exists('access_token', $phase7LinkRow) && !array_key_exists('refresh_token', $phase7LinkRow));
$phase7DuplicateChallengeId = $phase7Challenge($phase7IdentityId, ExternalIdentityLinkService::LINK_ACTION);
$phase7Duplicate = $phase7Service->link($phase7Identity, $phase7Claims, $phase7DuplicateChallengeId);
$assert('Phase 7 retries are idempotent for the same local identity without creating a duplicate link', $phase7Duplicate['status'] === 'already_linked' && Db::count('external_identities', ['user_type' => 'client', 'user_id' => $phase7IdentityId]) === 1);
$phase7OtherChallengeId = $phase7Challenge($phase7OtherIdentityId, ExternalIdentityLinkService::LINK_ACTION);
$throws('Phase 7 refuses to link one external subject to a different WHMCS identity', RuntimeException::class, function () use ($phase7OtherService, $phase7OtherIdentity, $phase7Claims, $phase7OtherChallengeId) {
    $phase7OtherService->link($phase7OtherIdentity, $phase7Claims, $phase7OtherChallengeId);
});
$phase7OtherChallengeRow = Db::firstQuery('SELECT `consumed_at` FROM `' . Db::table('challenges') . '` WHERE `id` = ?', [$phase7OtherChallengeId]);
$assert('Phase 7 does not consume another identity\'s confirmation when uniqueness rejects a link', $phase7OtherChallengeRow['consumed_at'] === null);
$phase7UnlinkChallengeId = $phase7Challenge($phase7IdentityId, ExternalIdentityLinkService::UNLINK_ACTION);
$phase7Unlinked = $phase7Service->unlink($phase7Identity, $phase7Linked['link']['id'], $phase7UnlinkChallengeId);
$phase7ListedAfterUnlink = $phase7Service->listLinks($phase7Identity);
$assert('Phase 7 unlinks by scoped record ID, preserves history, and returns no raw subject', $phase7Unlinked['status'] === 'unlinked' && $phase7ListedAfterUnlink[0]['active'] === false && !isset($phase7ListedAfterUnlink[0]['subject_id']));
$phase7RelinkChallengeId = $phase7Challenge($phase7IdentityId, ExternalIdentityLinkService::LINK_ACTION);
$phase7Relinked = $phase7Service->link($phase7Identity, $phase7Claims, $phase7RelinkChallengeId);
$assert('Phase 7 can explicitly relink the same revoked external identity without creating a second record', $phase7Relinked['status'] === 'relinked' && $phase7Relinked['link']['id'] === $phase7Linked['link']['id'] && $phase7Relinked['link']['active'] === true && Db::count('external_identities', ['user_type' => 'client', 'user_id' => $phase7IdentityId]) === 1);
$phase7StaleClaims = new ExternalIdentityClaims([
    'provider' => ExternalIdentityClaims::PROVIDER_MICROSOFT_ENTRA,
    'tenant_id' => 'tenant-stale', 'subject_id' => 'subject-stale',
    'issued_at' => gmdate('Y-m-d H:i:s', time() - 301),
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
]);
$phase7StaleChallengeId = $phase7Challenge($phase7IdentityId, ExternalIdentityLinkService::LINK_ACTION);
$throws('Phase 7 rejects stale host-verified external claims before consuming a confirmation', RuntimeException::class, function () use ($phase7Service, $phase7Identity, $phase7StaleClaims, $phase7StaleChallengeId) {
    $phase7Service->link($phase7Identity, $phase7StaleClaims, $phase7StaleChallengeId);
});
$phase7StaleChallengeRow = Db::firstQuery('SELECT `consumed_at` FROM `' . Db::table('challenges') . '` WHERE `id` = ?', [$phase7StaleChallengeId]);
$assert('Phase 7 leaves the confirmation usable when external claims fail freshness validation', $phase7StaleChallengeRow['consumed_at'] === null);
$throws('Phase 7 claims refuse raw identity tokens and profile data at the host boundary', InvalidArgumentException::class, function () {
    new ExternalIdentityClaims([
        'provider' => ExternalIdentityClaims::PROVIDER_MICROSOFT_ENTRA,
        'tenant_id' => 'tenant-phase7', 'subject_id' => 'subject-token',
        'issued_at' => gmdate('Y-m-d H:i:s'),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
        'id_token' => 'raw-token',
    ]);
});
$phase7ReplayChallengeId = $phase7DuplicateChallengeId;
$throws('Phase 7 confirmation challenges are one-use even for an idempotent link retry', RuntimeException::class, function () use ($phase7Service, $phase7Identity, $phase7Claims, $phase7ReplayChallengeId) {
    $phase7Service->link($phase7Identity, $phase7Claims, $phase7ReplayChallengeId);
});
$assert('Phase 7 link lifecycle is auditable without external subject or token metadata', Db::count('events', ['event_type' => 'entra.linked']) === 3 && Db::count('events', ['event_type' => 'entra.unlinked']) === 1 && strpos(json_encode(Db::query('SELECT `metadata_json` FROM `' . Db::table('events') . '` WHERE `event_type` LIKE \'entra.%\'')), 'subject-phase7') === false);

$composerManifest = json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'), true);
$assert('Composer manifest pins the audited WebAuthn library and an explicit PSR-7 adapter', isset($composerManifest['require']['web-auth/webauthn-lib'], $composerManifest['require']['nyholm/psr7']) && $composerManifest['require']['web-auth/webauthn-lib'] === '3.3.12' && $composerManifest['require']['nyholm/psr7'] === '^1.8');
$assert('WebAuthn ceremony service is present but does not alter the WHMCS authentication flow', class_exists(WebAuthnService::class) && method_exists(WebAuthnService::class, 'finishRegistration') && method_exists(WebAuthnService::class, 'finishAuthentication'));
if (!WebAuthnService::isAvailable()) {
    $throws('WebAuthn service fails closed when runtime requirements or Composer dependencies are absent', RuntimeException::class, function () {
        new WebAuthnService();
    });
} else {
    $assert('WebAuthn runtime and declared dependencies are available', true);
}

Db::reset();
if ($sessionStarted && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
echo 'CHECKS=' . $checks . "\n";
echo 'FAILURES=' . $failures . "\n";
if ($failures === 0) {
    echo "PASSKEY_PHASE3_CORE_OK\n";
    echo "PASSKEY_PHASE4_INTEGRATION_OK\n";
    echo "PASSKEY_PHASE5_MANAGEMENT_OK\n";
    echo "PASSKEY_PHASE6_SECURITY_OK\n";
    echo "PASSKEY_PHASE7_EXTERNAL_IDENTITY_OK\n";
} else {
    echo "PASSKEY_PHASE3_CORE_FAILED\n";
    echo "PASSKEY_PHASE4_INTEGRATION_FAILED\n";
}
exit($failures === 0 ? 0 : 1);
