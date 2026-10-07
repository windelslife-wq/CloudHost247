<?php
/** Phase 3 WebAuthn core tests; library-backed ceremony tests run separately. */

require_once dirname(__DIR__) . '/autoload.php';

use CloudHost247\Passkey\Core\Base64Url;
use CloudHost247\Passkey\Core\CeremonyChallengeStore;
use CloudHost247\Passkey\Core\Db;
use CloudHost247\Passkey\Core\Migrator;
use CloudHost247\Passkey\Core\Schema;
use CloudHost247\Passkey\Core\SessionBinding;
use CloudHost247\Passkey\Core\UserHandleRepository;
use CloudHost247\Passkey\Core\WebAuthnClientData;
use CloudHost247\Passkey\Core\WebAuthnConfig;
use CloudHost247\Passkey\Core\WebAuthnService;
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
echo $failures === 0 ? "PASSKEY_PHASE3_CORE_OK\n" : "PASSKEY_PHASE3_CORE_FAILED\n";
exit($failures === 0 ? 0 : 1);
