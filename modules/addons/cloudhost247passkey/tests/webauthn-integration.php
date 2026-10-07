<?php
/**
 * Real registration/assertion integration tests using the declared WebAuthn library.
 * Cryptographic verification stays inside web-auth/webauthn-lib; this fixture only
 * constructs standards-shaped attestation/assertion bytes with OpenSSL and CBOR.
 */

require_once dirname(__DIR__) . '/autoload.php';

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use CloudHost247\Passkey\Core\Base64Url;
use CloudHost247\Passkey\Core\Db;
use CloudHost247\Passkey\Core\Migrator;
use CloudHost247\Passkey\Core\PasskeyCredentialManagementService;
use CloudHost247\Passkey\Core\PasskeyLoginCoordinator;
use CloudHost247\Passkey\Core\PasskeyLoginPolicy;
use CloudHost247\Passkey\Core\UserHandleRepository;
use CloudHost247\Passkey\Core\WebAuthnConfig;
use CloudHost247\Passkey\Core\WebAuthnService;
use CloudHost247\Passkey\Integration\CallbackWhmcsAuthBridge;
use CloudHost247\Passkey\Integration\CallbackWhmcsIdentityProvider;
use CloudHost247\Passkey\Integration\PasskeyLoginContext;
use CloudHost247\Passkey\Integration\PasskeyRegistrationContext;
use CloudHost247\Passkey\Integration\WhmcsAuthHandoff;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Model\IdentityScope;

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
$expectFailure = function ($name, callable $callback) use (&$checks, &$failures) {
    $checks++;
    try {
        $callback();
        $failures++;
        echo 'FAIL ' . $name . " (accepted invalid ceremony)\n";
    } catch (Throwable $error) {
        echo 'PASS ' . $name . ' (' . get_class($error) . ")\n";
    }
};

/** Encode one browser client-data object without exposing any authenticator secret. */
$clientData = function ($type, $challenge) {
    return json_encode([
        'type' => $type,
        'challenge' => $challenge,
        'origin' => 'https://portal.cloudhost247.com',
        'crossOrigin' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};

/** Build the COSE EC2 public-key map using the project's CBOR dependency. */
$encodeCosePublicKey = function (array $details) {
    if (!isset($details['ec']['x'], $details['ec']['y'])
        || strlen($details['ec']['x']) !== 32 || strlen($details['ec']['y']) !== 32) {
        throw new RuntimeException('OpenSSL did not return a P-256 public key.');
    }
    $key = MapObject::create();
    $key->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)); // kty: EC2
    $key->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)); // alg: ES256
    $key->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)); // crv: P-256
    $key->add(NegativeIntegerObject::create(-2), ByteStringObject::create($details['ec']['x']));
    $key->add(NegativeIntegerObject::create(-3), ByteStringObject::create($details['ec']['y']));
    return (string) $key;
};

/** Create a none-attestation registration response around a generated P-256 key. */
$registrationResponse = function ($credentialId, $privateKey, $challenge, $rpId) use ($clientData, $encodeCosePublicKey) {
    $details = openssl_pkey_get_details($privateKey);
    if (!is_array($details)) {
        throw new RuntimeException('OpenSSL could not read the generated public key.');
    }
    $coseKey = $encodeCosePublicKey($details);
    $authenticatorData = hash('sha256', $rpId, true)
        . chr(0x45) // User present, user verified, and attested credential data present.
        . pack('N', 0)
        . str_repeat("\0", 16) // Zero AAGUID; none attestation reveals no authenticator identity.
        . pack('n', strlen($credentialId))
        . $credentialId
        . $coseKey;

    $attestationObject = MapObject::create();
    $attestationObject->add(TextStringObject::create('fmt'), TextStringObject::create('none'));
    $attestationObject->add(TextStringObject::create('attStmt'), MapObject::create());
    $attestationObject->add(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData));

    $credentialIdEncoded = Base64Url::encode($credentialId);
    return json_encode([
        'id' => $credentialIdEncoded,
        'rawId' => $credentialIdEncoded,
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => Base64Url::encode($clientData('webauthn.create', $challenge)),
            'attestationObject' => Base64Url::encode((string) $attestationObject),
        ],
        'clientExtensionResults' => new stdClass(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};

/** Create an ES256 assertion; the library under test validates its signature and authenticator data. */
$assertionResponse = function ($credentialId, $privateKey, $challenge, $rpId, $counter, $userHandle = null, $corruptSignature = false) use ($clientData) {
    $clientDataJson = $clientData('webauthn.get', $challenge);
    $authenticatorData = hash('sha256', $rpId, true) . chr(0x05) . pack('N', $counter);
    $signedData = $authenticatorData . hash('sha256', $clientDataJson, true);
    $signature = '';
    if (!openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('OpenSSL could not create the test assertion.');
    }
    if ($corruptSignature) {
        $lastByte = strlen($signature) - 1;
        $signature[$lastByte] = chr(ord($signature[$lastByte]) ^ 0x01);
    }
    $credentialIdEncoded = Base64Url::encode($credentialId);
    $response = [
        'clientDataJSON' => Base64Url::encode($clientDataJson),
        'authenticatorData' => Base64Url::encode($authenticatorData),
        'signature' => Base64Url::encode($signature),
    ];
    if ($userHandle !== null) {
        // Browser JSON represents ArrayBuffer user handles as canonical base64url.
        $response['userHandle'] = Base64Url::encode($userHandle);
    }
    return json_encode([
        'id' => $credentialIdEncoded,
        'rawId' => $credentialIdEncoded,
        'type' => 'public-key',
        'response' => $response,
        'clientExtensionResults' => new stdClass(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};

try {
    if (!WebAuthnService::isAvailable()) {
        throw new RuntimeException('Composer WebAuthn dependencies or runtime extensions are unavailable. Run composer install first.');
    }
    if (!function_exists('openssl_pkey_new') || !extension_loaded('openssl') || !extension_loaded('mbstring')) {
        throw new RuntimeException('The WebAuthn integration suite requires OpenSSL and mbstring.');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @ini_set('session.use_cookies', '0');
        @ini_set('session.cache_limiter', '');
        @session_save_path(sys_get_temp_dir());
        session_id('ch247-webauthn-integration-' . bin2hex(random_bytes(8)));
        if (!@session_start()) {
            throw new RuntimeException('Could not start the isolated ceremony test session.');
        }
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('PRAGMA foreign_keys = ON');
    Db::setPdo($pdo, 'sqlite');
    (new Migrator(dirname(__DIR__) . '/install/migrations'))->migrate();

    $rpId = 'cloudhost247.com';
    $config = WebAuthnConfig::fromSettings([
        'service_enabled' => '1',
        'require_https' => '1',
        'rp_name' => 'CloudHost247',
        'rp_id' => $rpId,
        'allowed_origins' => '["https://portal.cloudhost247.com"]',
        'user_verification' => 'preferred',
    ], 'https://portal.cloudhost247.com', true);
    $service = new WebAuthnService();
    $userType = IdentityScope::CLIENT;
    $userId = 74291;

    // The Node runner creates this ephemeral fixture key; it is never written to disk or logged.
    $privateKeyEncoded = getenv('CH247PK_TEST_PRIVATE_PEM_B64');
    $privateKeyPem = is_string($privateKeyEncoded) ? base64_decode($privateKeyEncoded, true) : false;
    $privateKey = is_string($privateKeyPem) ? openssl_pkey_get_private($privateKeyPem) : false;
    if ($privateKey === false) {
        throw new RuntimeException('The ephemeral P-256 integration-test key was not provided or could not be imported.');
    }
    $privateKeyDetails = openssl_pkey_get_details($privateKey);
    if (!is_array($privateKeyDetails) || ($privateKeyDetails['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
        throw new RuntimeException('The integration-test key is not an EC credential.');
    }
    $credentialId = random_bytes(32);

    $registrationOptions = $service->beginRegistration($config, $userType, $userId, 'passkey-test@example.invalid', 'Passkey Integration');
    $registrationChallenge = $registrationOptions['publicKey']['challenge'] ?? null;
    $assert('registration options come from the real WebAuthn library and contain a challenge', is_string($registrationChallenge) && $registrationChallenge !== '');
    if (!is_string($registrationChallenge) || $registrationChallenge === '') {
        throw new RuntimeException('Library did not return a usable registration challenge.');
    }
    $registrationResponseJson = $registrationResponse($credentialId, $privateKey, $registrationChallenge, $rpId);
    $registration = $service->finishRegistration(
        $config,
        $userType,
        $userId,
        $registrationResponseJson,
        'Integration passkey'
    );
    $credentialRow = Db::firstQuery(
        'SELECT * FROM `' . Db::table('credentials') . '` WHERE `user_type` = ? AND `user_id` = ?',
        [$userType, $userId]
    );
    $credentialIdEncoded = Base64Url::encode($credentialId);
    $assert('library verifies a none-attestation registration and persists one public credential source',
        !empty($registration['registered']) && $registration['credential_record_id'] > 0
        && $credentialRow !== null && $credentialRow['credential_id'] === $credentialIdEncoded);
    $assert('persisted source contains no private-key or biometric material',
        is_array($credentialRow)
        && strpos($credentialRow['credential_source_json'], 'PRIVATE KEY') === false
        && stripos($credentialRow['credential_source_json'], 'private_key') === false
        && stripos($credentialRow['credential_source_json'], 'biometric') === false);

    $userHandle = UserHandleRepository::findForIdentity($userType, $userId);
    $authenticationOptions = $service->beginAuthentication($config, $userType, $userId);
    $authenticationChallenge = $authenticationOptions['publicKey']['challenge'] ?? null;
    $allowed = $authenticationOptions['publicKey']['allowCredentials'] ?? [];
    $assert('scoped authentication options load the credential descriptor for the existing client',
        is_string($authenticationChallenge) && count($allowed) === 1 && $allowed[0]['id'] === $credentialIdEncoded);
    if (!is_string($authenticationChallenge) || !$allowed || !is_string($userHandle)) {
        throw new RuntimeException('Stored credential could not be used to generate authentication options.');
    }

    $firstAssertionJson = $assertionResponse($credentialId, $privateKey, $authenticationChallenge, $rpId, 1, $userHandle);
    $firstAuthentication = $service->finishAuthentication($config, $userType, $userId, $firstAssertionJson);
    $credentialRow = Db::firstQuery(
        'SELECT * FROM `' . Db::table('credentials') . '` WHERE `credential_id_hash` = ?',
        [hash('sha256', $credentialIdEncoded)]
    );
    $assert('library verifies an ES256 assertion and binds the response user handle to the known client',
        $firstAuthentication['user_type'] === $userType && $firstAuthentication['user_id'] === $userId
        && $credentialRow !== null && (int) $credentialRow['sign_count'] === 1);

    $expectFailure('a consumed assertion challenge cannot be replayed', function () use ($service, $config, $userType, $userId, $firstAssertionJson) {
        $service->finishAuthentication($config, $userType, $userId, $firstAssertionJson);
    });

    $discoverableOptions = $service->beginAuthentication($config, $userType, null);
    $discoverableChallenge = $discoverableOptions['publicKey']['challenge'] ?? null;
    $discoverableAllowed = $discoverableOptions['publicKey']['allowCredentials'] ?? [];
    $assert('discoverable authentication options leave the credential list empty',
        is_string($discoverableChallenge) && $discoverableAllowed === []);
    if (!is_string($discoverableChallenge)) {
        throw new RuntimeException('Library did not return a discoverable authentication challenge.');
    }
    $discoverableResponseJson = $assertionResponse($credentialId, $privateKey, $discoverableChallenge, $rpId, 2, $userHandle);
    $discoverableAuthentication = $service->finishAuthentication($config, $userType, null, $discoverableResponseJson);
    $credentialRow = Db::firstQuery(
        'SELECT `sign_count` FROM `' . Db::table('credentials') . '` WHERE `credential_id_hash` = ?',
        [hash('sha256', $credentialIdEncoded)]
    );
    $assert('base64url discoverable user handles normalize for the library and resolve the existing client',
        $discoverableAuthentication['user_type'] === $userType && $discoverableAuthentication['user_id'] === $userId
        && $credentialRow !== null && (int) $credentialRow['sign_count'] === 2);

    $coordinatorOptions = $service->beginAuthentication($config, $userType, null);
    $coordinatorChallenge = $coordinatorOptions['publicKey']['challenge'] ?? null;
    if (!is_string($coordinatorChallenge)) {
        throw new RuntimeException('Library did not return a coordinator authentication challenge.');
    }
    $coordinatorResponseJson = $assertionResponse($credentialId, $privateKey, $coordinatorChallenge, $rpId, 3, $userHandle);
    $phase4Policy = new PasskeyLoginPolicy([
        'service_enabled' => '1',
        'client_policy' => 'optional',
        'admin_policy' => 'optional',
        'password_fallback' => 'allowed',
    ]);
    $phase4Provider = new CallbackWhmcsIdentityProvider(function ($resolvedType, $resolvedId) use ($userType, $userId) {
        return $resolvedType === $userType && $resolvedId === $userId
            ? ['user_type' => $resolvedType, 'user_id' => $resolvedId, 'loginable' => true]
            : null;
    });
    $phase4Bridge = new CallbackWhmcsAuthBridge(function (WhmcsIdentity $identity, PasskeyLoginContext $context) {
        if ($identity->userType() !== IdentityScope::CLIENT || $context->source() !== 'client_login') {
            throw new RuntimeException('Integration bridge received the wrong audience.');
        }
        return WhmcsAuthHandoff::twoFactorRequired();
    });
    $phase4Result = (new PasskeyLoginCoordinator($service, $phase4Provider, $phase4Bridge, $phase4Policy))->authenticate(
        $config,
        $userType,
        null,
        $coordinatorResponseJson,
        ['remember_me' => true, 'request_id' => 'real-library-phase4', 'source' => 'client_login']
    );
    $credentialRow = Db::firstQuery(
        'SELECT `sign_count` FROM `' . Db::table('credentials') . '` WHERE `credential_id_hash` = ?',
        [hash('sha256', $credentialIdEncoded)]
    );
    $assert('Phase 4 coordinator consumes a real library assertion and preserves the existing WHMCS 2FA handoff',
        $phase4Result->identity()->toArray() === ['user_type' => IdentityScope::CLIENT, 'user_id' => $userId]
        && $phase4Result->handoff()->requiresTwoFactor()
        && $credentialRow !== null && (int) $credentialRow['sign_count'] === 3);

    $managedUserId = 74292;
    $managedIdentity = new WhmcsIdentity(IdentityScope::CLIENT, $managedUserId);
    $managedProvider = new CallbackWhmcsIdentityProvider(function ($resolvedType, $resolvedId) use ($managedUserId) {
        return $resolvedType === IdentityScope::CLIENT && $resolvedId === $managedUserId
            ? ['user_type' => $resolvedType, 'user_id' => $resolvedId, 'loginable' => true]
            : null;
    });
    $managedPolicy = new PasskeyLoginPolicy([
        'service_enabled' => '1',
        'client_policy' => 'optional',
        'admin_policy' => 'optional',
        'password_fallback' => 'allowed',
    ]);
    $managedCredentials = new PasskeyCredentialManagementService($service, $managedProvider, $managedPolicy);
    $managedOptions = $managedCredentials->beginRegistration($config, $managedIdentity, 'managed-client@example.invalid', 'Managed Client');
    $managedChallenge = $managedOptions['publicKey']['challenge'] ?? null;
    if (!is_string($managedChallenge)) {
        throw new RuntimeException('Managed client registration challenge was not issued.');
    }
    $managedCredentialId = random_bytes(32);
    $managedResponse = $registrationResponse($managedCredentialId, $privateKey, $managedChallenge, $rpId);
    $managedRegistration = $managedCredentials->finishRegistration(
        $config,
        $managedIdentity,
        $managedResponse,
        'Managed security key',
        PasskeyRegistrationContext::fromTrustedArray([
            'ip_address' => '198.51.100.21',
            'user_agent' => 'Phase5 real-library browser',
        ])
    );
    $managedList = $managedCredentials->listCredentials($managedIdentity);
    $assert('Phase 5 management completes a real library-backed enrollment for the existing client session',
        !empty($managedRegistration['registered']) && count($managedList) === 1
        && $managedList[0]['device_name'] === 'Managed security key'
        && $managedList[0]['status'] === 'active');

    $expectFailure('a discoverable assertion cannot authenticate through the administrator scope', function () use ($service, $config, $credentialId, $privateKey, $rpId, $userHandle, $assertionResponse) {
        $adminOptions = $service->beginAuthentication($config, IdentityScope::ADMIN, null);
        $adminChallenge = $adminOptions['publicKey']['challenge'] ?? null;
        if (!is_string($adminChallenge)) {
            throw new RuntimeException('Administrator challenge was not issued.');
        }
        $payload = $assertionResponse($credentialId, $privateKey, $adminChallenge, $rpId, 3, $userHandle);
        $service->finishAuthentication($config, IdentityScope::ADMIN, null, $payload);
    });

    $tamperOptions = $service->beginAuthentication($config, $userType, $userId);
    $tamperChallenge = $tamperOptions['publicKey']['challenge'] ?? null;
    if (!is_string($tamperChallenge)) {
        throw new RuntimeException('Library did not return a challenge for the negative signature test.');
    }
    $tamperedResponseJson = $assertionResponse($credentialId, $privateKey, $tamperChallenge, $rpId, 3, $userHandle, true);
    $expectFailure('library rejects an assertion with a corrupted ES256 signature', function () use ($service, $config, $userType, $userId, $tamperedResponseJson) {
        $service->finishAuthentication($config, $userType, $userId, $tamperedResponseJson);
    });
    $credentialRow = Db::firstQuery(
        'SELECT `sign_count` FROM `' . Db::table('credentials') . '` WHERE `credential_id_hash` = ?',
        [hash('sha256', $credentialIdEncoded)]
    );
    $assert('failed signature verification does not advance the stored authenticator counter',
        $credentialRow !== null && (int) $credentialRow['sign_count'] === 3);

    if (function_exists('openssl_pkey_free') && is_resource($privateKey)) {
        openssl_pkey_free($privateKey);
    }
    Db::reset();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
} catch (Throwable $error) {
    $failures++;
    $checks++;
    $message = preg_replace('/[\r\n\t]+/', ' ', $error->getMessage());
    echo 'FAIL unexpected integration error (' . get_class($error) . '): ' . substr($message, 0, 240) . "\n";
    Db::reset();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

echo 'INTEGRATION_CHECKS=' . $checks . "\n";
echo 'INTEGRATION_FAILURES=' . $failures . "\n";
echo $failures === 0 ? "PASSKEY_WEBAUTHN_INTEGRATION_OK\n" : "PASSKEY_WEBAUTHN_INTEGRATION_FAILED\n";
exit($failures === 0 ? 0 : 1);
