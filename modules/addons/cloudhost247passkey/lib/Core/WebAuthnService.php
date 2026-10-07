<?php
/** Library-backed WebAuthn ceremonies; cryptographic checks stay in web-auth/webauthn-lib. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\ChallengeRecord;
use CloudHost247\Passkey\Model\IdentityScope;
use Nyholm\Psr7\ServerRequest;
use RuntimeException;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\Server;

class WebAuthnService implements PasskeyAssertionVerifierInterface, PasskeyRegistrationCeremonyInterface
{
    const OPTION_ALGORITHMS = ['ES256', 'RS256'];

    private $challengeStore;

    public function __construct(CeremonyChallengeStore $challengeStore = null)
    {
        self::loadVendorAutoloader();
        self::assertRuntimeAvailable();
        $this->challengeStore = $challengeStore ?: new CeremonyChallengeStore();
    }

    public static function isAvailable()
    {
        try {
            self::loadVendorAutoloader();
            self::assertRuntimeAvailable();
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /** Generate registration options; no WHMCS login or session integration is performed. */
    public function beginRegistration(WebAuthnConfig $config, $userType, $userId, $userName, $displayName)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $userName = self::displayText($userName, 'user name', 254);
        $displayName = self::displayText($displayName, 'display name', 128);
        $repository = (new DbCredentialSourceRepository())->restrictToIdentity($userType, $userId);
        $userHandle = UserHandleRepository::getOrCreate($userType, $userId);
        $user = new PublicKeyCredentialUserEntity($userName, $userHandle, $displayName);
        $criteria = AuthenticatorSelectionCriteria::create()
            ->setRequireResidentKey(true)
            ->setResidentKey(AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED)
            ->setUserVerification($config->userVerification());
        $options = $this->server($config, $repository)->generatePublicKeyCredentialCreationOptions(
            $user,
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $repository->descriptorsForIdentity($userType, $userId),
            $criteria
        );
        $this->persistOptions($options, ChallengeRecord::REGISTRATION, $userType, $userId, $config);
        return [
            'publicKey' => self::browserOptions($options, true),
            'expires_in' => CeremonyChallengeStore::TTL_SECONDS,
        ];
    }

    /** Validate and persist one attested public credential source. */
    public function finishRegistration(
        WebAuthnConfig $config,
        $userType,
        $userId,
        $credentialResponseJson,
        $deviceName = 'Passkey',
        $registrationIp = null,
        $registrationUserAgent = null
    ) {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $client = WebAuthnClientData::preflight($credentialResponseJson, 'webauthn.create', $config);
        $optionsJson = $this->challengeStore->consume(
            $client['challenge'], ChallengeRecord::REGISTRATION, $userType, $userId, $config
        );
        $options = PublicKeyCredentialCreationOptions::createFromString($optionsJson);
        if (!hash_equals($options->getChallenge(), $client['challenge'])
            || $options->getRp()->getId() !== $config->rpId()
            || !UserHandleRepository::matches($options->getUser()->getId(), $userType, $userId)) {
            throw new RuntimeException('Registration options do not match the consumed ceremony.');
        }

        $repository = (new DbCredentialSourceRepository())->restrictToIdentity($userType, $userId);
        $source = $this->server($config, $repository)->loadAndCheckAttestationResponse(
            $credentialResponseJson,
            $options,
            self::serverRequest($config)
        );
        if ($source->getAttestationType() !== 'none') {
            throw new RuntimeException('Only anonymized none attestation is accepted for Passkey registration.');
        }
        if (!UserHandleRepository::matches($source->getUserHandle(), $userType, $userId)) {
            throw new RuntimeException('Verified credential is not bound to the expected local identity.');
        }
        $credentialRecordId = $repository->saveNewCredentialSource(
            $source,
            $userType,
            $userId,
            $deviceName,
            $registrationIp,
            $registrationUserAgent
        );
        return ['registered' => true, 'credential_record_id' => (int) $credentialRecordId];
    }

    /** Generate authentication options for one identity or discoverable login within a scope. */
    public function beginAuthentication(WebAuthnConfig $config, $userType, $userId = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId, true);
        $repository = (new DbCredentialSourceRepository())->restrictToIdentity($userType, $userId);
        $allowedCredentials = [];
        if ($userId !== null) {
            $userHandle = UserHandleRepository::findForIdentity($userType, $userId);
            if ($userHandle === null) {
                throw new RuntimeException('No Passkey identity is registered for this account.');
            }
            $user = new PublicKeyCredentialUserEntity('passkey-user', $userHandle, 'Passkey user');
            foreach ($repository->findAllForUserEntity($user) as $source) {
                $allowedCredentials[] = $source->getPublicKeyCredentialDescriptor();
            }
            if (!$allowedCredentials) {
                throw new RuntimeException('No active Passkey is registered for this account.');
            }
        }
        $options = $this->server($config, $repository)->generatePublicKeyCredentialRequestOptions(
            $config->userVerification(),
            $allowedCredentials
        );
        $this->persistOptions($options, ChallengeRecord::AUTHENTICATION, $userType, $userId, $config);
        return [
            'publicKey' => self::browserOptions($options, false),
            'expires_in' => CeremonyChallengeStore::TTL_SECONDS,
        ];
    }

    /**
     * Validate an assertion and persist its counter/source updates. Returns only the
     * existing local identity reference; it does not create a WHMCS login session.
     */
    public function finishAuthentication(WebAuthnConfig $config, $userType, $userId, $credentialResponseJson)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId, true);
        $client = WebAuthnClientData::preflight($credentialResponseJson, 'webauthn.get', $config);
        $optionsJson = $this->challengeStore->consume(
            $client['challenge'], ChallengeRecord::AUTHENTICATION, $userType, $userId, $config
        );
        $options = PublicKeyCredentialRequestOptions::createFromString($optionsJson);
        if (!hash_equals($options->getChallenge(), $client['challenge']) || $options->getRpId() !== $config->rpId()) {
            throw new RuntimeException('Authentication options do not match the consumed ceremony.');
        }

        $user = null;
        if ($userId !== null) {
            $userHandle = UserHandleRepository::findForIdentity($userType, $userId);
            if ($userHandle === null) {
                throw new RuntimeException('Passkey identity handle is no longer available.');
            }
            $user = new PublicKeyCredentialUserEntity('passkey-user', $userHandle, 'Passkey user');
        }
        $repository = (new DbCredentialSourceRepository())->restrictToIdentity($userType, $userId);
        $libraryCredentialResponseJson = self::normalizeAssertionUserHandle($credentialResponseJson);
        $source = $this->server($config, $repository)->loadAndCheckAssertionResponse(
            $libraryCredentialResponseJson,
            $options,
            $user,
            self::serverRequest($config)
        );
        $owner = UserHandleRepository::findOwnerByHandle($source->getUserHandle());
        if ($owner === null || $owner['user_type'] !== $userType
            || ($userId !== null && $owner['user_id'] !== $userId)) {
            throw new RuntimeException('Verified Passkey does not belong to the requested identity scope.');
        }
        return ['user_type' => $owner['user_type'], 'user_id' => $owner['user_id']];
    }

    /**
     * web-auth/webauthn-lib v3 decodes assertion userHandle with standard Base64,
     * while the browser WebAuthn JSON boundary uses canonical unpadded base64url.
     * Normalize only this field for the library; all other credential bytes remain
     * in the library's expected WebAuthn JSON representation.
     */
    private static function normalizeAssertionUserHandle($credentialResponseJson)
    {
        try {
            $credential = json_decode($credentialResponseJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('WebAuthn assertion response could not be decoded.', 0, $error);
        }
        if (!is_array($credential) || !isset($credential['response']) || !is_array($credential['response'])
            || !array_key_exists('userHandle', $credential['response'])
            || $credential['response']['userHandle'] === null
            || $credential['response']['userHandle'] === '') {
            return $credentialResponseJson;
        }
        if (!is_string($credential['response']['userHandle'])) {
            throw new RuntimeException('WebAuthn assertion user handle is invalid.');
        }
        $userHandle = Base64Url::decode($credential['response']['userHandle'], 64);
        if ($userHandle === '' || strlen($userHandle) > 64) {
            throw new RuntimeException('WebAuthn assertion user handle has an invalid size.');
        }
        $credential['response']['userHandle'] = base64_encode($userHandle);
        try {
            return json_encode($credential, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('WebAuthn assertion response could not be normalized.', 0, $error);
        }
    }

    private function persistOptions($options, $challengeType, $userType, $userId, WebAuthnConfig $config)
    {
        try {
            $optionsJson = json_encode($options, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('WebAuthn options could not be serialized.', 0, $error);
        }
        $this->challengeStore->issue(
            $options->getChallenge(),
            $challengeType,
            $userType,
            $userId,
            $config,
            $optionsJson
        );
    }

    private function server(WebAuthnConfig $config, DbCredentialSourceRepository $repository)
    {
        return (new Server(
            new PublicKeyCredentialRpEntity($config->rpName(), $config->rpId()),
            $repository
        ))->setSelectedAlgorithms(self::OPTION_ALGORITHMS);
    }

    private static function browserOptions($options, $creation)
    {
        try {
            $json = json_encode($options, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('WebAuthn browser options could not be serialized.', 0, $error);
        }
        if (!is_array($data) || !isset($data['challenge'])) {
            throw new RuntimeException('WebAuthn library returned incomplete browser options.');
        }
        if ($creation) {
            if (!isset($data['user']) || !($options instanceof PublicKeyCredentialCreationOptions)) {
                throw new RuntimeException('WebAuthn creation options are incomplete.');
            }
            // v3 emits this single user handle as standard Base64; the WebAuthn JSON API needs base64url.
            $data['user']['id'] = Base64Url::encode($options->getUser()->getId());
        }
        return $data;
    }

    private static function serverRequest(WebAuthnConfig $config)
    {
        $parts = parse_url($config->origin());
        $uri = $config->origin() . '/';
        return new ServerRequest('POST', $uri, [
            'origin' => $config->origin(),
            'host' => $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''),
        ], '', '1.1', []);
    }

    private static function displayText($value, $label, $maxLength)
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maxLength
            || preg_match('/[\\x00-\\x1F\\x7F]/', $value)) {
            throw new \InvalidArgumentException('Passkey ' . $label . ' is missing or invalid.');
        }
        return trim($value);
    }

    private static function loadVendorAutoloader()
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    private static function assertRuntimeAvailable()
    {
        if (PHP_VERSION_ID < 70400 || !extension_loaded('json')
            || !extension_loaded('mbstring') || !extension_loaded('openssl')
            || !extension_loaded('simplexml') || !extension_loaded('bcmath')) {
            throw new RuntimeException('WebAuthn requires PHP 7.4+, JSON, mbstring, OpenSSL, SimpleXML and BCMath.');
        }
        if (!class_exists(Server::class) || !class_exists(PublicKeyCredentialCreationOptions::class)
            || !class_exists(PublicKeyCredentialRequestOptions::class)
            || !class_exists(ServerRequest::class)) {
            throw new RuntimeException('WebAuthn dependencies are not installed; install the declared Composer dependencies.');
        }
    }
}
