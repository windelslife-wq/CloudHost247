<?php
/**
 * Native WHMCS HTTP boundary for client Passkey ceremonies and management.
 *
 * Public ceremony routes are rate-limited and return attacker-safe generic
 * errors. Authenticated routes require the WHMCS session plus CSRF
 * validation. Every route fails closed with CONFIGURATION_REQUIRED or
 * SERVICE_UNAVAILABLE instead of bypassing verification.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Http;

use CloudHost247\Passkey\Core\Db;
use CloudHost247\Passkey\Core\PasskeyActionConfirmationService;
use CloudHost247\Passkey\Core\PasskeyCredentialManagementService;
use CloudHost247\Passkey\Core\PasskeyLoginCoordinator;
use CloudHost247\Passkey\Core\PasskeyLoginPolicy;
use CloudHost247\Passkey\Core\PasskeyNotificationService;
use CloudHost247\Passkey\Core\PasskeyPolicyResolver;
use CloudHost247\Passkey\Core\PasskeyRecoveryGrantService;
use CloudHost247\Passkey\Core\PasskeySecurityService;
use CloudHost247\Passkey\Core\SecurityEventRepository;
use CloudHost247\Passkey\Core\SessionBinding;
use CloudHost247\Passkey\Core\SettingsRepository;
use CloudHost247\Passkey\Core\WebAuthnConfig;
use CloudHost247\Passkey\Core\WebAuthnService;
use CloudHost247\Passkey\Core\WhmcsMailNotificationSink;
use CloudHost247\Passkey\Core\WhmcsNativeAuthBridge;
use CloudHost247\Passkey\Core\WhmcsNativeIdentityProvider;
use CloudHost247\Passkey\Integration\PasskeyLoginContext;
use CloudHost247\Passkey\Integration\PasskeyRegistrationContext;
use CloudHost247\Passkey\Integration\SecurityNotification;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Model\ChallengeRecord;
use CloudHost247\Passkey\Model\IdentityScope;

class PasskeyHttpKernel
{
    const GENERIC_AUTH_FAILURE = 'Sign-in with Passkey failed. Please try again.';
    const GENERIC_UNAVAILABLE = 'Passkey authentication is not available right now.';
    const GENERIC_INVALID = 'The Passkey request was invalid. Please try again.';
    const RESET_SESSION_KEY = 'ch247pk_reset';

    /** Dispatch one JSON action; always exits with a JSON envelope. */
    public static function dispatchAjax()
    {
        $action = RequestContext::postParam('passkey_action', RequestContext::queryParam('passkey_action', ''));
        try {
            switch ((string) $action) {
                case 'register_options':
                    self::registerOptions();
                    break;
                case 'register_verify':
                    self::registerVerify();
                    break;
                case 'auth_options':
                    self::authOptions();
                    break;
                case 'auth_verify':
                    self::authVerify();
                    break;
                case 'credentials':
                    self::credentials();
                    break;
                case 'credential_rename':
                    self::credentialRename();
                    break;
                case 'credential_revoke':
                    self::credentialRevoke();
                    break;
                case 'credential_disable':
                    self::credentialDisable(true);
                    break;
                case 'credential_enable':
                    self::credentialDisable(false);
                    break;
                case 'action_options':
                    self::actionOptions();
                    break;
                case 'action_verify':
                    self::actionVerify();
                    break;
                case 'pwreset_options':
                    self::passwordResetOptions();
                    break;
                case 'pwreset_verify':
                    self::passwordResetVerify();
                    break;
                case 'pwreset_complete':
                    self::passwordResetComplete();
                    break;
                case 'preferences_get':
                    self::preferencesGet();
                    break;
                case 'preferences_set':
                    self::preferencesSet();
                    break;
                case 'events':
                    self::recentEvents();
                    break;
                default:
                    JsonResponse::fail('INVALID_REQUEST', self::GENERIC_INVALID, 400);
            }
        } catch (\Throwable $error) {
            self::failClosed($error);
        }
    }

    // ------------------------------------------------------------------
    // Registration (authenticated).
    // ------------------------------------------------------------------

    private static function registerOptions()
    {
        self::requirePost();
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $services = self::services();
        self::requireRateLimit($services['security']->consumeRateLimit(
            'passkey.register_options',
            $identity->userType() . ':' . $identity->userId(),
            10,
            60,
            self::audit($identity)
        ));
        $config = self::webAuthnConfig($services['settings']);
        $display = self::displayNames($identity);
        $ceremonies = new WebAuthnService();
        $management = new PasskeyCredentialManagementService(
            $ceremonies,
            $services['provider'],
            $services['policy']
        );
        $options = $management->beginRegistration(
            $config,
            $identity,
            $display['username'],
            $display['display_name']
        );
        $services['events']->append([
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'event_type' => 'registration.started',
            'success' => 1,
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => ['origin' => $config->origin(), 'rp_id' => $config->rpId()],
        ]);
        JsonResponse::ok(['options' => $options]);
    }

    private static function registerVerify()
    {
        self::requirePost();
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $body = self::body();
        $services = self::services();
        self::requireRateLimit($services['security']->consumeRateLimit(
            'passkey.register_verify',
            $identity->userType() . ':' . $identity->userId(),
            10,
            60,
            self::audit($identity)
        ));
        $config = self::webAuthnConfig($services['settings']);
        $responseJson = self::credentialJson($body, 'response');
        $deviceName = isset($body['device_name']) ? (string) $body['device_name'] : 'Passkey';
        $ceremonies = new WebAuthnService();
        $management = new PasskeyCredentialManagementService(
            $ceremonies,
            $services['provider'],
            $services['policy']
        );
        try {
            $result = $management->finishRegistration(
                $config,
                $identity,
                $responseJson,
                $deviceName,
                PasskeyRegistrationContext::fromTrustedArray([
                    'ip_address' => RequestContext::ipAddress(),
                    'user_agent' => RequestContext::userAgent(),
                ])
            );
        } catch (\Throwable $error) {
            $services['events']->append([
                'user_type' => $identity->userType(),
                'user_id' => $identity->userId(),
                'event_type' => 'registration.failed',
                'success' => 0,
                'reason_code' => 'verification_failed',
                'ip_address' => RequestContext::ipAddress(),
                'user_agent' => RequestContext::userAgent(),
                'metadata' => ['origin' => $config->origin()],
            ]);
            throw $error;
        }
        $services['events']->append([
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'passkey_id' => isset($result['credential_record_id']) ? (int) $result['credential_record_id'] : null,
            'event_type' => 'registration.succeeded',
            'success' => 1,
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => ['origin' => $config->origin()],
        ]);
        self::notify($services, $identity, SecurityNotification::SECURITY_EVENT, 'registration.succeeded');
        JsonResponse::ok([
            'registered' => true,
            'credential_record_id' => isset($result['credential_record_id']) ? (int) $result['credential_record_id'] : null,
        ]);
    }

    // ------------------------------------------------------------------
    // Login (public, discoverable, rate-limited).
    // ------------------------------------------------------------------

    private static function authOptions()
    {
        self::requirePost();
        $body = self::body();
        $userType = isset($body['user_type']) ? (string) $body['user_type'] : IdentityScope::CLIENT;
        if (!in_array($userType, [IdentityScope::CLIENT, IdentityScope::ADMIN], true)) {
            JsonResponse::fail('INVALID_REQUEST', self::GENERIC_INVALID, 400);
        }
        $services = self::services();
        $principal = (string) RequestContext::ipAddress() . ':' . $userType;
        $decision = $services['security']->consumeRateLimit(
            'passkey.auth_options',
            $principal !== ':' ? $principal : 'unknown:' . $userType,
            20,
            60,
            ['user_type' => $userType]
        );
        if (!$decision['allowed']) {
            JsonResponse::fail('RATE_LIMITED', 'Too many Passkey requests. Please wait and try again.', 429, [
                'retry_after' => $decision['retry_after'],
            ]);
        }
        $config = self::webAuthnConfig($services['settings']);
        $services['policy']->assertLoginAllowed($userType);
        // Discoverable (usernameless) login: identical options shape for every
        // visitor, so the endpoint cannot be used to enumerate accounts.
        $options = (new WebAuthnService())->beginAuthentication($config, $userType, null);
        JsonResponse::ok(['options' => $options]);
    }

    private static function authVerify()
    {
        self::requirePost();
        $body = self::body();
        $userType = isset($body['user_type']) ? (string) $body['user_type'] : IdentityScope::CLIENT;
        if (!in_array($userType, [IdentityScope::CLIENT, IdentityScope::ADMIN], true)) {
            JsonResponse::fail('INVALID_REQUEST', self::GENERIC_INVALID, 400);
        }
        $services = self::services();
        $principal = (string) RequestContext::ipAddress() . ':' . $userType;
        $decision = $services['security']->consumeRateLimit(
            'passkey.auth_verify',
            $principal !== ':' ? $principal : 'unknown:' . $userType,
            10,
            60,
            ['user_type' => $userType]
        );
        if (!$decision['allowed']) {
            JsonResponse::fail('RATE_LIMITED', 'Too many Passkey requests. Please wait and try again.', 429, [
                'retry_after' => $decision['retry_after'],
            ]);
        }
        $config = self::webAuthnConfig($services['settings']);
        $responseJson = self::credentialJson($body, 'response');
        $coordinator = new PasskeyLoginCoordinator(
            new WebAuthnService(),
            $services['provider'],
            $services['bridge'],
            $services['policy']
        );
        $source = $userType === IdentityScope::ADMIN ? 'admin_login' : 'client_login';
        try {
            $result = $coordinator->authenticate(
                $config,
                $userType,
                null,
                $responseJson,
                [
                    'remember_me' => false,
                    'request_id' => self::requestId(),
                    'source' => $source,
                    'ip_address' => RequestContext::ipAddress(),
                    'user_agent' => RequestContext::userAgent(),
                ]
            );
        } catch (\Throwable $error) {
            $services['events']->append([
                'user_type' => $userType,
                'user_id' => null,
                'event_type' => 'authentication.failed',
                'success' => 0,
                'reason_code' => self::failureReason($error),
                'ip_address' => RequestContext::ipAddress(),
                'user_agent' => RequestContext::userAgent(),
                'metadata' => ['origin' => $config->origin()],
            ]);
            JsonResponse::fail('AUTHENTICATION_FAILED', self::GENERIC_AUTH_FAILURE, 401);
        }
        $identity = $result->identity();
        $handoff = $result->handoff();
        $services['events']->append([
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'event_type' => 'authentication.succeeded',
            'success' => 1,
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => ['origin' => $config->origin()],
        ]);
        if ($handoff->sessionEstablishedValue()) {
            self::notify($services, $identity, SecurityNotification::LOGIN, 'authentication.succeeded');
            JsonResponse::ok([
                'authenticated' => true,
                'next_step' => $identity->userType() === IdentityScope::ADMIN ? 'admin_area' : 'client_area',
            ]);
        }
        JsonResponse::ok([
            'authenticated' => false,
            'next_step' => 'two_factor',
            'message' => 'This account uses WHMCS two-factor authentication. Please complete the standard login.',
        ]);
    }

    // ------------------------------------------------------------------
    // Credential management (authenticated).
    // ------------------------------------------------------------------

    private static function credentials()
    {
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $services = self::services();
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            $services['provider'],
            $services['policy']
        );
        JsonResponse::ok(['credentials' => $management->listCredentials($identity, true)]);
    }

    private static function credentialRename()
    {
        self::requirePost();
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $body = self::body();
        $services = self::services();
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            $services['provider'],
            $services['policy']
        );
        $summary = $management->renameCredential(
            $identity,
            isset($body['id']) ? $body['id'] : null,
            isset($body['device_name']) ? (string) $body['device_name'] : ''
        );
        $services['events']->append([
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'passkey_id' => $summary['id'],
            'event_type' => 'credential.renamed',
            'success' => 1,
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => [],
        ]);
        JsonResponse::ok(['credential' => $summary]);
    }

    private static function credentialRevoke()
    {
        self::requirePost();
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $body = self::body();
        $services = self::services();
        self::maybeRequireClientConfirmation($services, $identity, 'credential.revoke', $body);
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            $services['provider'],
            $services['policy']
        );
        $summary = $management->revokeCredential($identity, isset($body['id']) ? $body['id'] : null);
        $services['events']->append([
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'passkey_id' => $summary['id'],
            'event_type' => 'credential.revoked',
            'success' => 1,
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => [],
        ]);
        self::notify($services, $identity, SecurityNotification::SECURITY_EVENT, 'credential.revoked');
        JsonResponse::ok(['credential' => $summary]);
    }

    private static function credentialDisable($disabled)
    {
        self::requirePost();
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $body = self::body();
        $services = self::services();
        if ($disabled) {
            self::maybeRequireClientConfirmation($services, $identity, 'credential.disable', $body);
        }
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            $services['provider'],
            $services['policy']
        );
        if ($disabled) {
            $summary = $management->disableCredential($identity, isset($body['id']) ? $body['id'] : null);
            $event = 'credential.disabled';
        } else {
            $summary = $management->enableCredential($identity, isset($body['id']) ? $body['id'] : null);
            $event = 'credential.enabled';
        }
        $services['events']->append([
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'passkey_id' => $summary['id'],
            'event_type' => $event,
            'success' => 1,
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => [],
        ]);
        JsonResponse::ok(['credential' => $summary]);
    }

    // ------------------------------------------------------------------
    // Sensitive-action confirmation (authenticated).
    // ------------------------------------------------------------------

    private static function actionOptions()
    {
        self::requirePost();
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $body = self::body();
        $services = self::services();
        self::requireRateLimit($services['security']->consumeRateLimit(
            'passkey.action_options',
            $identity->userType() . ':' . $identity->userId(),
            10,
            60,
            self::audit($identity)
        ));
        $config = self::webAuthnConfig($services['settings']);
        $confirmation = new PasskeyActionConfirmationService($services['provider'], $services['resolver']);
        $result = $confirmation->beginConfirmation(
            $config,
            new WebAuthnService(),
            $identity,
            isset($body['action_code']) ? (string) $body['action_code'] : ''
        );
        JsonResponse::ok([
            'ticket_id' => $result['ticket_id'],
            'action_code' => $result['action_code'],
            'expires_in' => $result['expires_in'],
            'options' => ['publicKey' => $result['publicKey']],
        ]);
    }

    private static function actionVerify()
    {
        self::requirePost();
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $body = self::body();
        $services = self::services();
        $config = self::webAuthnConfig($services['settings']);
        $confirmation = new PasskeyActionConfirmationService($services['provider'], $services['resolver']);
        $receipt = $confirmation->confirm(
            $config,
            new WebAuthnService(),
            $identity,
            isset($body['ticket_id']) ? $body['ticket_id'] : null,
            self::credentialJson($body, 'response'),
            ['ip_address' => RequestContext::ipAddress(), 'user_agent' => RequestContext::userAgent()]
        );
        JsonResponse::ok(['receipt' => $receipt]);
    }

    // ------------------------------------------------------------------
    // Passkey-assisted password reset (public, enumeration-safe).
    // ------------------------------------------------------------------

    private static function passwordResetOptions()
    {
        self::requirePost();
        $services = self::services();
        $decision = $services['security']->consumeRateLimit(
            'passkey.pwreset_options',
            (string) RequestContext::ipAddress() !== '' ? (string) RequestContext::ipAddress() : 'unknown',
            5,
            300,
            ['user_type' => IdentityScope::CLIENT]
        );
        if (!$decision['allowed']) {
            JsonResponse::fail('RATE_LIMITED', 'Too many reset requests. Please wait and try again.', 429, [
                'retry_after' => $decision['retry_after'],
            ]);
        }
        $config = self::webAuthnConfig($services['settings']);
        $services['policy']->assertLoginAllowed(IdentityScope::CLIENT);
        // Reset intent ticket: session/RP/origin bound, user resolved at verify
        // time from the verified assertion so accounts cannot be enumerated.
        $nowEpoch = time();
        $ticketId = Db::insert('challenges', [
            'challenge_hash' => hash('sha256', random_bytes(32)),
            'user_type' => IdentityScope::CLIENT,
            'user_id' => null,
            'challenge_type' => ChallengeRecord::PASSWORD_RESET,
            'action_code' => 'password.reset',
            'session_binding_hash' => SessionBinding::currentHash(),
            'rp_id' => $config->rpId(),
            'origin' => $config->origin(),
            'expires_at' => gmdate('Y-m-d H:i:s', $nowEpoch + 300),
            'consumed_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s', $nowEpoch),
        ]);
        $options = (new WebAuthnService())->beginAuthentication($config, IdentityScope::CLIENT, null);
        JsonResponse::ok(['ticket_id' => (int) $ticketId, 'options' => $options]);
    }

    private static function passwordResetVerify()
    {
        self::requirePost();
        $body = self::body();
        $services = self::services();
        $decision = $services['security']->consumeRateLimit(
            'passkey.pwreset_verify',
            (string) RequestContext::ipAddress() !== '' ? (string) RequestContext::ipAddress() : 'unknown',
            5,
            300,
            ['user_type' => IdentityScope::CLIENT]
        );
        if (!$decision['allowed']) {
            JsonResponse::fail('RATE_LIMITED', 'Too many reset requests. Please wait and try again.', 429, [
                'retry_after' => $decision['retry_after'],
            ]);
        }
        $config = self::webAuthnConfig($services['settings']);
        $ticketId = filter_var(isset($body['ticket_id']) ? $body['ticket_id'] : null, FILTER_VALIDATE_INT);
        if ($ticketId === false || (int) $ticketId < 1) {
            JsonResponse::fail('AUTHENTICATION_FAILED', self::GENERIC_AUTH_FAILURE, 401);
        }
        try {
            $ticket = self::consumeResetTicket((int) $ticketId, $config);
            $verified = (new WebAuthnService())->finishAuthentication(
                $config,
                IdentityScope::CLIENT,
                null,
                self::credentialJson($body, 'response')
            );
            list($verifiedType, $verifiedId) = IdentityScope::validate($verified['user_type'], $verified['user_id']);
            if ($verifiedType !== IdentityScope::CLIENT) {
                throw new \RuntimeException('Reset ceremony resolved outside the client audience.');
            }
            $services['resolver']->assertAllowed($verifiedType, $verifiedId);
            if ($services['provider']->resolve($verifiedType, $verifiedId) === null) {
                throw new \RuntimeException('Reset identity is not loginable.');
            }
            $services['security']->issueRecoveryGrant(
                $verifiedType,
                $verifiedId,
                function ($token) use ($verifiedType, $verifiedId) {
                    // Token stays server-side; the browser only learns that a
                    // short-lived reset authorization now exists.
                    $_SESSION[self::RESET_SESSION_KEY] = [
                        'token' => $token,
                        'user_type' => $verifiedType,
                        'user_id' => $verifiedId,
                    ];
                },
                $ticket['id'],
                300,
                self::audit(new WhmcsIdentity($verifiedType, $verifiedId))
            );
        } catch (\Throwable $error) {
            $services['events']->append([
                'user_type' => IdentityScope::CLIENT,
                'user_id' => null,
                'event_type' => 'authentication.failed',
                'success' => 0,
                'reason_code' => 'password_reset_failed',
                'ip_address' => RequestContext::ipAddress(),
                'user_agent' => RequestContext::userAgent(),
                'metadata' => ['origin' => $config->origin()],
            ]);
            JsonResponse::fail('AUTHENTICATION_FAILED', self::GENERIC_AUTH_FAILURE, 401);
        }
        JsonResponse::ok(['reset_authorized' => true, 'expires_in' => 300]);
    }

    private static function passwordResetComplete()
    {
        self::requirePost();
        CsrfProtection::verify(self::csrfInput());
        $body = self::body();
        $services = self::services();
        $grant = isset($_SESSION[self::RESET_SESSION_KEY]) ? $_SESSION[self::RESET_SESSION_KEY] : null;
        if (!is_array($grant) || !isset($grant['token'], $grant['user_type'], $grant['user_id'])) {
            JsonResponse::fail('AUTHENTICATION_FAILED', 'The reset authorization is missing or expired.', 401);
        }
        $password = isset($body['new_password']) ? (string) $body['new_password'] : '';
        $confirm = isset($body['confirm_password']) ? (string) $body['confirm_password'] : '';
        if ($password === '' || !hash_equals($password, $confirm) || strlen($password) < 12 || strlen($password) > 256) {
            JsonResponse::fail('INVALID_REQUEST', 'Passwords must match and be at least 12 characters.', 400);
        }
        $decision = $services['security']->consumeRateLimit(
            'passkey.pwreset_complete',
            (string) RequestContext::ipAddress() !== '' ? (string) RequestContext::ipAddress() : 'unknown',
            5,
            300,
            ['user_type' => IdentityScope::CLIENT, 'user_id' => (int) $grant['user_id']]
        );
        if (!$decision['allowed']) {
            JsonResponse::fail('RATE_LIMITED', 'Too many reset requests. Please wait and try again.', 429, [
                'retry_after' => $decision['retry_after'],
            ]);
        }
        try {
            $capability = $services['security']->consumeRecoveryGrant(
                $grant['token'],
                $grant['user_type'],
                $grant['user_id'],
                null,
                self::audit(new WhmcsIdentity($grant['user_type'], (int) $grant['user_id']))
            );
            unset($_SESSION[self::RESET_SESSION_KEY]);
            self::updateClientPassword((int) $capability['user_id'], $password);
        } catch (\Throwable $error) {
            unset($_SESSION[self::RESET_SESSION_KEY]);
            JsonResponse::fail('AUTHENTICATION_FAILED', 'The reset authorization is missing or expired.', 401);
        }
        $identity = new WhmcsIdentity($grant['user_type'], (int) $grant['user_id']);
        $services['events']->append([
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'event_type' => 'password_reset.succeeded',
            'success' => 1,
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => [],
        ]);
        self::notify($services, $identity, SecurityNotification::SECURITY_EVENT, 'password_reset.succeeded');
        JsonResponse::ok(['password_changed' => true]);
    }

    // ------------------------------------------------------------------
    // Preferences and activity (authenticated).
    // ------------------------------------------------------------------

    private static function preferencesGet()
    {
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $services = self::services();
        $notifications = new PasskeyNotificationService($services['provider'], $services['resolver']);
        JsonResponse::ok(['preferences' => $notifications->preferences($identity)]);
    }

    private static function preferencesSet()
    {
        self::requirePost();
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $body = self::body();
        $services = self::services();
        $notifications = new PasskeyNotificationService($services['provider'], $services['resolver']);
        $updated = $notifications->updatePreferences($identity, [
            'login_notification_enabled' => !empty($body['login_notification_enabled']) ? 1 : 0,
            'security_event_notification_enabled' => !empty($body['security_event_notification_enabled']) ? 1 : 0,
        ]);
        JsonResponse::ok(['preferences' => $updated]);
    }

    private static function recentEvents()
    {
        $identity = self::requireClientSession();
        CsrfProtection::verify(self::csrfInput());
        $rows = Db::query(
            'SELECT `event_type`, `success`, `ip_address`, `created_at` FROM `' . Db::table('events') . '` '
            . 'WHERE `user_type` = ? AND `user_id` = ? ORDER BY `id` DESC LIMIT 25',
            [$identity->userType(), $identity->userId()]
        );
        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'event_type' => $row['event_type'],
                'success' => (int) $row['success'] === 1,
                'ip_address' => $row['ip_address'],
                'created_at' => $row['created_at'],
            ];
        }
        JsonResponse::ok(['events' => $events]);
    }

    // ------------------------------------------------------------------
    // Shared request plumbing.
    // ------------------------------------------------------------------

    private static function services()
    {
        $settings = (new SettingsRepository())->values();
        $policy = new PasskeyLoginPolicy($settings);
        $provider = new WhmcsNativeIdentityProvider();
        $events = new SecurityEventRepository();
        return [
            'settings' => $settings,
            'policy' => $policy,
            'provider' => $provider,
            'bridge' => new WhmcsNativeAuthBridge(),
            'resolver' => new PasskeyPolicyResolver($policy),
            'events' => $events,
            'security' => new PasskeySecurityService(
                new PasskeyPolicyResolver($policy),
                null,
                new PasskeyRecoveryGrantService(),
                $events
            ),
        ];
    }

    private static function webAuthnConfig(array $settings)
    {
        return WebAuthnConfig::fromSettings(
            $settings,
            RequestContext::requestOrigin(),
            RequestContext::isHttps()
        );
    }

    /** Current client session; administrators masquerading as clients are refused. */
    private static function requireClientSession()
    {
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
        if ($uid < 1) {
            JsonResponse::fail('FORBIDDEN', 'Please log in to manage Passkeys.', 403);
        }
        if (isset($_SESSION['adminid']) && (int) $_SESSION['adminid'] > 0) {
            JsonResponse::fail('FORBIDDEN', 'Passkey management is unavailable while logged in as the client.', 403);
        }
        return new WhmcsIdentity(IdentityScope::CLIENT, $uid);
    }

    private static function requirePost()
    {
        if (RequestContext::method() !== 'POST') {
            JsonResponse::fail('INVALID_REQUEST', self::GENERIC_INVALID, 405);
        }
    }

    private static function requireRateLimit(array $decision)
    {
        if (!$decision['allowed']) {
            JsonResponse::fail('RATE_LIMITED', 'Too many Passkey requests. Please wait and try again.', 429, [
                'retry_after' => $decision['retry_after'],
            ]);
        }
    }

    private static function body()
    {
        $body = RequestContext::jsonBody();
        if ($body !== null) {
            return $body;
        }
        return $_POST;
    }

    private static function csrfInput()
    {
        if (function_exists('check_token')) {
            return null;
        }
        if (isset($_POST[CsrfProtection::FIELD])) {
            return $_POST[CsrfProtection::FIELD];
        }
        if (isset($_POST['token'])) {
            return $_POST['token'];
        }
        return isset($_SERVER[CsrfProtection::HEADER]) ? $_SERVER[CsrfProtection::HEADER] : null;
    }

    /** Accept the browser credential as an object or a JSON string. */
    private static function credentialJson(array $body, $key)
    {
        if (!array_key_exists($key, $body)) {
            throw new \InvalidArgumentException('WebAuthn response is missing.');
        }
        $value = $body[$key];
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 262144) {
                throw new \InvalidArgumentException('WebAuthn response is invalid.');
            }
            return $encoded;
        }
        if (!is_string($value) || $value === '' || strlen($value) > 262144) {
            throw new \InvalidArgumentException('WebAuthn response is invalid.');
        }
        return $value;
    }

    private static function consumeResetTicket($ticketId, WebAuthnConfig $config)
    {
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('challenges') . '` WHERE `id` = ?',
            [$ticketId]
        );
        if (!$row) {
            throw new \RuntimeException('Reset ticket is unknown or expired.');
        }
        $record = new ChallengeRecord($row);
        $ticket = $record->toArray();
        $now = gmdate('Y-m-d H:i:s');
        if ($ticket['challenge_type'] !== ChallengeRecord::PASSWORD_RESET
            || $ticket['user_type'] !== IdentityScope::CLIENT
            || !hash_equals($ticket['session_binding_hash'], SessionBinding::currentHash())
            || !hash_equals($ticket['rp_id'], $config->rpId())
            || !hash_equals($ticket['origin'], $config->origin())
            || !$record->isUsableAt($now)) {
            throw new \RuntimeException('Reset ticket binding failed.');
        }
        $changed = Db::update(
            'challenges',
            ['id' => $ticket['id'], 'consumed_at' => null],
            ['consumed_at' => $now]
        );
        if ($changed !== 1) {
            throw new \RuntimeException('Reset ticket has already been consumed.');
        }
        return $ticket;
    }

    private static function updateClientPassword($clientId, $password)
    {
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        $result = localAPI('UpdateClient', ['clientid' => (int) $clientId, 'password2' => $password]);
        if (!is_array($result) || (isset($result['result']) && $result['result'] === 'error')) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
    }

    private static function displayNames(WhmcsIdentity $identity)
    {
        $username = $identity->userType() . '-' . $identity->userId();
        $displayName = 'CloudHost247 ' . $identity->userType() . ' ' . $identity->userId();
        try {
            if (class_exists('WHMCS\\Database\\Capsule')) {
                $table = $identity->userType() === IdentityScope::ADMIN ? 'tbladmins' : 'tblclients';
                $row = \WHMCS\Database\Capsule::table($table)->where('id', $identity->userId())->first();
                if ($row !== null) {
                    $row = (array) $row;
                    if (!empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                        $username = substr((string) $row['email'], 0, 254);
                    }
                    $name = trim(
                        (isset($row['firstname']) ? (string) $row['firstname'] : '') . ' '
                        . (isset($row['lastname']) ? (string) $row['lastname'] : '')
                    );
                    if ($name !== '') {
                        $displayName = substr($name, 0, 128);
                    }
                }
            }
        } catch (\Throwable $error) {
            // Display names are cosmetic; ceremony identity stays scope-bound.
        }
        return ['username' => $username, 'display_name' => $displayName];
    }

    private static function notify(array $services, WhmcsIdentity $identity, $type, $event)
    {
        try {
            $globalKey = $type === SecurityNotification::LOGIN
                ? 'login_notifications_enabled'
                : 'security_event_notifications_enabled';
            if (!isset($services['settings'][$globalKey]) || $services['settings'][$globalKey] !== '1') {
                return;
            }
            $notifications = new PasskeyNotificationService($services['provider'], $services['resolver']);
            $notifications->dispatch($identity, $type, $event, ['CloudHost247\\Passkey\\Core\\WhmcsMailNotificationSink', 'deliver']);
        } catch (\Throwable $error) {
            if (function_exists('logActivity')) {
                try {
                    logActivity('CloudHost247 Passkey notification delivery failed: ' . $type . '/' . $event . '.');
                } catch (\Throwable $ignored) {
                    // Never break authentication for notification bookkeeping.
                }
            }
        }
    }

    /**
     * Enforce step-up Passkey confirmation for destructive client actions when
     * the sensitive-action policy requires it. Issues a ticket plus ceremony
     * options when the caller has not supplied a completed confirmation yet.
     */
    private static function maybeRequireClientConfirmation(
        array $services,
        WhmcsIdentity $identity,
        $actionCode,
        array $body
    ) {
        if (!isset($services['settings']['sensitive_action_policy'])
            || $services['settings']['sensitive_action_policy'] !== 'required') {
            return null;
        }
        $confirmation = new PasskeyActionConfirmationService($services['provider'], $services['resolver']);
        if (isset($body['ticket_id']) && array_key_exists('confirmation_response', $body)) {
            $config = self::webAuthnConfig($services['settings']);
            return $confirmation->confirm(
                $config,
                new WebAuthnService(),
                $identity,
                $body['ticket_id'],
                self::credentialJson($body, 'confirmation_response'),
                ['ip_address' => RequestContext::ipAddress(), 'user_agent' => RequestContext::userAgent()]
            );
        }
        $config = self::webAuthnConfig($services['settings']);
        $issued = $confirmation->beginConfirmation(
            $config,
            new WebAuthnService(),
            $identity,
            $actionCode
        );
        JsonResponse::fail(
            'CONFIRMATION_REQUIRED',
            'Confirm with your passkey to continue.',
            403,
            [
                'ticket_id' => $issued['ticket_id'],
                'action_code' => $issued['action_code'],
                'options' => ['publicKey' => $issued['publicKey']],
            ]
        );
        return null;
    }

    private static function audit(WhmcsIdentity $identity = null)
    {
        $audit = [
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
        ];
        if ($identity !== null) {
            $audit['user_type'] = $identity->userType();
            $audit['user_id'] = $identity->userId();
        }
        return $audit;
    }

    private static function requestId()
    {
        try {
            return 'web-' . substr(hash('sha256', random_bytes(16)), 0, 24);
        } catch (\Throwable $error) {
            return 'web-' . substr(hash('sha256', microtime(true) . session_id()), 0, 24);
        }
    }

    private static function failureReason(\Throwable $error)
    {
        $message = $error->getMessage();
        if (strpos($message, 'disabled') !== false || strpos($message, 'policy') !== false) {
            return 'policy_denied';
        }
        if (strpos($message, 'expired') !== false || strpos($message, 'consumed') !== false
            || strpos($message, 'unknown') !== false) {
            return 'invalid_challenge';
        }
        if (strpos($message, 'loginable') !== false || strpos($message, 'scope') !== false
            || strpos($message, 'audience') !== false) {
            return 'unknown_identity';
        }
        return 'verification_failed';
    }

    /** Map internal failures to safe public envelopes without leaking state. */
    private static function failClosed(\Throwable $error)
    {
        $message = $error->getMessage();
        if ($message === 'CSRF_FAILED') {
            JsonResponse::fail('FORBIDDEN', 'Security validation failed. Please reload and try again.', 403);
        }
        if ($message === 'SERVICE_UNAVAILABLE') {
            JsonResponse::fail('SERVICE_UNAVAILABLE', self::GENERIC_UNAVAILABLE, 503);
        }
        if (strpos($message, 'Rate limit exceeded') !== false || strpos($message, 'rate-limit') !== false) {
            JsonResponse::fail('RATE_LIMITED', 'Too many Passkey requests. Please wait and try again.', 429);
        }
        if ($error instanceof \InvalidArgumentException) {
            $text = $message;
            $safe = [
                'Passkey device name is empty or invalid.',
                'Passkey credential record ID is invalid.',
                'Unsupported Passkey confirmation action.',
                'Passkey confirmation ticket ID is invalid.',
                'WebAuthn response is missing.',
                'WebAuthn response is invalid.',
                'Request body is too large.',
                'Request body is not valid JSON.',
            ];
            if (!in_array($text, $safe, true)) {
                $text = self::GENERIC_INVALID;
            }
            JsonResponse::fail('INVALID_REQUEST', $text, 400);
        }
        if (strpos($message, 'maximum number') !== false) {
            JsonResponse::fail('INVALID_REQUEST', 'The maximum number of Passkeys for this account has been reached.', 400);
        }
        if (strpos($message, 'last active Passkey') !== false) {
            JsonResponse::fail('INVALID_REQUEST', 'The last active Passkey cannot be removed while password login is disabled.', 400);
        }
        if (strpos($message, 'disabled') !== false || strpos($message, 'not explicitly enabled') !== false
            || strpos($message, 'temporarily disabled') !== false) {
            JsonResponse::fail('CONFIGURATION_REQUIRED', 'Passkey authentication is not enabled for this account.', 403);
        }
        if (strpos($message, 'HTTPS') !== false || strpos($message, 'origin') !== false
            || strpos($message, 'relying-party') !== false || strpos($message, 'allowlist') !== false) {
            JsonResponse::fail('CONFIGURATION_REQUIRED', self::GENERIC_UNAVAILABLE, 503);
        }
        if (strpos($message, 'not loginable') !== false || strpos($message, 'outside the current session scope') !== false) {
            JsonResponse::fail('FORBIDDEN', self::GENERIC_AUTH_FAILURE, 401);
        }
        if (strpos($message, 'credential is missing') !== false || strpos($message, 'credential is disabled') !== false) {
            JsonResponse::fail('INVALID_REQUEST', 'The selected Passkey is no longer available.', 404);
        }
        JsonResponse::fail('AUTHENTICATION_FAILED', self::GENERIC_AUTH_FAILURE, 401);
    }
}
