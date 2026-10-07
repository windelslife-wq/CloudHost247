<?php
/**
 * Client-area page controller for Passkey management.
 *
 * Renders the management page and handles plain-POST fallbacks for rename,
 * revoke, disable, enable, and notification preferences. WebAuthn ceremonies
 * always run through the JSON boundary with JavaScript.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey;

use CloudHost247\Passkey\Core\Db;
use CloudHost247\Passkey\Core\PasskeyCredentialManagementService;
use CloudHost247\Passkey\Core\PasskeyLoginPolicy;
use CloudHost247\Passkey\Core\PasskeyNotificationService;
use CloudHost247\Passkey\Core\PasskeyPolicyResolver;
use CloudHost247\Passkey\Core\SecurityEventRepository;
use CloudHost247\Passkey\Core\SettingsRepository;
use CloudHost247\Passkey\Core\WebAuthnService;
use CloudHost247\Passkey\Core\WhmcsNativeIdentityProvider;
use CloudHost247\Passkey\Http\CsrfProtection;
use CloudHost247\Passkey\Http\RequestContext;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Model\IdentityScope;

class Client
{
    private $vars;
    private $notice;
    private $error;

    public function __construct(array $vars = [])
    {
        $this->vars = $vars;
    }

    public function clientId()
    {
        return (int) ($_SESSION['uid'] ?? 0);
    }

    public function isMasquerading()
    {
        return $this->clientId() > 0 && (int) ($_SESSION['adminid'] ?? 0) > 0;
    }

    /** Plain-POST fallback for management without ceremony JavaScript. */
    public function handleRequest()
    {
        if ($this->clientId() < 1 || $this->isMasquerading()) {
            return;
        }
        if (RequestContext::method() !== 'POST' || !isset($_POST['ch247pk_action'])) {
            return;
        }
        try {
            CsrfProtection::verify();
            $identity = new WhmcsIdentity(IdentityScope::CLIENT, $this->clientId());
            $action = (string) $_POST['ch247pk_action'];
            if ($action === 'preferences') {
                $this->savePreferences($identity);
            } elseif (in_array($action, ['rename', 'revoke', 'disable', 'enable'], true)) {
                $this->mutateCredential($identity, $action);
            } else {
                throw new \RuntimeException('Unknown Passkey action.');
            }
            $this->notice = 'Passkeys updated.';
        } catch (\Throwable $error) {
            $this->error = $this->publicError($error);
        }
    }

    public function viewData()
    {
        $modulelink = isset($this->vars['modulelink'])
            ? (string) $this->vars['modulelink']
            : 'index.php?m=cloudhost247passkey';
        $data = [
            'modulelink' => $modulelink,
            'ajax_url' => $modulelink,
            'csrf_field' => CsrfProtection::field(),
            'csrf_token' => $this->safeToken(),
            'notice' => $this->notice,
            'error' => $this->error,
            'blocked_masquerade' => $this->isMasquerading(),
            'service_available' => false,
            'credentials' => [],
            'preferences' => [
                'login_notification_enabled' => 0,
                'security_event_notification_enabled' => 0,
            ],
            'events' => [],
            'max_credentials' => 5,
            'policy' => 'optional',
        ];
        if ($this->clientId() < 1 || $this->isMasquerading()) {
            return $data;
        }
        try {
            $settings = (new SettingsRepository())->values();
            $policy = new PasskeyLoginPolicy($settings);
            $data['service_available'] = $policy->isEnabledFor(IdentityScope::CLIENT)
                && !empty($settings['rp_id'])
                && isset($settings['allowed_origins']) && $settings['allowed_origins'] !== '[]';
            $data['max_credentials'] = $policy->maxCredentialsFor(IdentityScope::CLIENT);
            $data['policy'] = $policy->policyFor(IdentityScope::CLIENT);
            $identity = new WhmcsIdentity(IdentityScope::CLIENT, $this->clientId());
            $provider = new WhmcsNativeIdentityProvider();
            if ($provider->resolve($identity->userType(), $identity->userId()) !== null) {
                $management = new PasskeyCredentialManagementService(
                    new WebAuthnService(),
                    $provider,
                    $policy
                );
                $data['credentials'] = $management->listCredentials($identity, true);
                $notifications = new PasskeyNotificationService(
                    $provider,
                    new PasskeyPolicyResolver($policy)
                );
                $data['preferences'] = $notifications->preferences($identity);
            }
            $rows = Db::query(
                'SELECT `event_type`, `success`, `created_at` FROM `' . Db::table('events') . '` '
                . 'WHERE `user_type` = ? AND `user_id` = ? ORDER BY `id` DESC LIMIT 10',
                [$identity->userType(), $identity->userId()]
            );
            foreach ($rows as $row) {
                $data['events'][] = [
                    'event_type' => $row['event_type'],
                    'success' => (int) $row['success'] === 1,
                    'created_at' => $row['created_at'],
                ];
            }
        } catch (\Throwable $error) {
            $data['service_available'] = false;
            if ($this->error === null) {
                $data['error'] = 'Passkey information is temporarily unavailable.';
            }
        }
        return $data;
    }

    private function savePreferences(WhmcsIdentity $identity)
    {
        $settings = (new SettingsRepository())->values();
        $policy = new PasskeyLoginPolicy($settings);
        $notifications = new PasskeyNotificationService(
            new WhmcsNativeIdentityProvider(),
            new PasskeyPolicyResolver($policy)
        );
        $notifications->updatePreferences($identity, [
            'login_notification_enabled' => !empty($_POST['login_notification_enabled']) ? 1 : 0,
            'security_event_notification_enabled' => !empty($_POST['security_event_notification_enabled']) ? 1 : 0,
        ]);
    }

    private function mutateCredential(WhmcsIdentity $identity, $action)
    {
        $settings = (new SettingsRepository())->values();
        $policy = new PasskeyLoginPolicy($settings);
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            new WhmcsNativeIdentityProvider(),
            $policy
        );
        $id = isset($_POST['credential_id']) ? $_POST['credential_id'] : null;
        $events = new SecurityEventRepository();
        $audit = [
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'success' => 1,
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => [],
        ];
        if ($action === 'rename') {
            $summary = $management->renameCredential(
                $identity,
                $id,
                isset($_POST['device_name']) ? (string) $_POST['device_name'] : ''
            );
            $audit['event_type'] = 'credential.renamed';
            $audit['passkey_id'] = $summary['id'];
            $events->append($audit);
        } elseif ($action === 'revoke') {
            $summary = $management->revokeCredential($identity, $id);
            $audit['event_type'] = 'credential.revoked';
            $audit['passkey_id'] = $summary['id'];
            $events->append($audit);
        } elseif ($action === 'disable') {
            $summary = $management->disableCredential($identity, $id);
            $audit['event_type'] = 'credential.disabled';
            $audit['passkey_id'] = $summary['id'];
            $events->append($audit);
        } else {
            $summary = $management->enableCredential($identity, $id);
            $audit['event_type'] = 'credential.enabled';
            $audit['passkey_id'] = $summary['id'];
            $events->append($audit);
        }
    }

    private function safeToken()
    {
        try {
            return CsrfProtection::token();
        } catch (\Throwable $error) {
            return '';
        }
    }

    private function publicError(\Throwable $error)
    {
        $message = $error->getMessage();
        if (strpos($message, 'maximum number') !== false) {
            return 'The maximum number of Passkeys for this account has been reached.';
        }
        if (strpos($message, 'last active Passkey') !== false) {
            return 'The last active Passkey cannot be removed while password login is disabled.';
        }
        if (strpos($message, 'device name') !== false) {
            return 'Please enter a valid device name (1-120 characters).';
        }
        if (strpos($message, 'missing, revoked') !== false || strpos($message, 'no longer available') !== false) {
            return 'The selected Passkey is no longer available.';
        }
        if (strpos($message, 'Security token') !== false || $message === 'CSRF_FAILED') {
            return 'Security validation failed. Please reload and try again.';
        }
        return 'This Passkey request could not be completed. Please try again.';
    }
}
