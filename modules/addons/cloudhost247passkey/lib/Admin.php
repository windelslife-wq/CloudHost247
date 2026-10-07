<?php
/**
 * Administrator dashboard, policy management, and admin-session JSON boundary.
 *
 * Super administrators manage global settings, all credentials, per-identity
 * policy, and retention. Staff administrators manage only their own Passkeys.
 * Sensitive mutations require step-up Passkey confirmation when the
 * sensitive-action policy requires it. All failures stay admin-safe and the
 * surrounding WHMCS admin area keeps working.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey;

use CloudHost247\Passkey\Core\CredentialManagementRepository;
use CloudHost247\Passkey\Core\Db;
use CloudHost247\Passkey\Core\PasskeyActionConfirmationService;
use CloudHost247\Passkey\Core\PasskeyCredentialManagementService;
use CloudHost247\Passkey\Core\PasskeyDiagnostics;
use CloudHost247\Passkey\Core\PasskeyLoginPolicy;
use CloudHost247\Passkey\Core\PasskeyMaintenanceService;
use CloudHost247\Passkey\Core\PasskeyPolicyAdministrationService;
use CloudHost247\Passkey\Core\PasskeyPolicyResolver;
use CloudHost247\Passkey\Core\PasskeySecurityService;
use CloudHost247\Passkey\Core\SecurityEventRepository;
use CloudHost247\Passkey\Core\SettingsRepository;
use CloudHost247\Passkey\Core\WebAuthnConfig;
use CloudHost247\Passkey\Core\WebAuthnService;
use CloudHost247\Passkey\Core\WhmcsNativeIdentityProvider;
use CloudHost247\Passkey\Http\CsrfProtection;
use CloudHost247\Passkey\Http\JsonResponse;
use CloudHost247\Passkey\Http\RequestContext;
use CloudHost247\Passkey\Integration\CallbackPasskeyPolicyAdminAuthorization;
use CloudHost247\Passkey\Integration\PasskeyRegistrationContext;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Model\CredentialRecord;
use CloudHost247\Passkey\Model\IdentityScope;
use CloudHost247\Passkey\Model\UserPolicyRecord;

class Admin
{
    private $vars;
    private $modulelink;
    private $notice;
    private $error;

    public function __construct(array $vars = [])
    {
        $this->vars = $vars;
        $this->modulelink = isset($vars['modulelink']) ? (string) $vars['modulelink'] : '';
    }

    // ------------------------------------------------------------------
    // Admin-session JSON boundary (own Passkeys + step-up confirmation).
    // ------------------------------------------------------------------

    public static function dispatchAjax()
    {
        try {
            $adminId = (int) ($_SESSION['adminid'] ?? 0);
            if ($adminId < 1) {
                JsonResponse::fail('FORBIDDEN', 'Administrator login is required.', 403);
            }
            $action = '';
            if (isset($_POST['passkey_action']) && is_string($_POST['passkey_action'])) {
                $action = $_POST['passkey_action'];
            } elseif (isset($_GET['passkey_action']) && is_string($_GET['passkey_action'])) {
                $action = $_GET['passkey_action'];
            }
            $identity = new WhmcsIdentity(IdentityScope::ADMIN, $adminId);
            switch ($action) {
                case 'admin_register_options':
                    self::adminRegisterOptions($identity);
                    break;
                case 'admin_register_verify':
                    self::adminRegisterVerify($identity);
                    break;
                case 'admin_credentials':
                    self::adminCredentials($identity);
                    break;
                case 'admin_credential_rename':
                    self::adminCredentialRename($identity);
                    break;
                case 'admin_credential_revoke':
                    self::adminCredentialRevoke($identity);
                    break;
                case 'admin_credential_disable':
                    self::adminCredentialDisable($identity, true);
                    break;
                case 'admin_credential_enable':
                    self::adminCredentialDisable($identity, false);
                    break;
                case 'admin_action_options':
                    self::adminActionOptions($identity);
                    break;
                case 'admin_action_verify':
                    self::adminActionVerify($identity);
                    break;
                default:
                    JsonResponse::fail('INVALID_REQUEST', 'Unknown Passkey admin action.', 400);
            }
        } catch (\Throwable $error) {
            $message = $error->getMessage();
            if ($message === 'CSRF_FAILED') {
                JsonResponse::fail('FORBIDDEN', 'Security validation failed. Reload and try again.', 403);
            }
            if ($message === 'SERVICE_UNAVAILABLE') {
                JsonResponse::fail('SERVICE_UNAVAILABLE', 'Passkey service is unavailable.', 503);
            }
            if ($error instanceof \InvalidArgumentException) {
                JsonResponse::fail('INVALID_REQUEST', 'The Passkey admin request was invalid.', 400);
            }
            JsonResponse::fail('AUTHENTICATION_FAILED', 'The Passkey admin request failed.', 400);
        }
    }

    // ------------------------------------------------------------------
    // Page rendering.
    // ------------------------------------------------------------------

    public function render()
    {
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        if ($adminId < 1) {
            echo '<div class="alert alert-danger">Administrator login is required.</div>';
            return;
        }
        if (RequestContext::method() === 'POST' && isset($_POST['ch247pk_admin'])) {
            $this->handlePost($adminId);
        }
        $tab = isset($_GET['ch247pk_tab']) ? (string) $_GET['ch247pk_tab'] : 'dashboard';
        if (!in_array($tab, ['dashboard', 'mine', 'credentials', 'events', 'policy', 'settings', 'entra', 'diagnostics'], true)) {
            $tab = 'dashboard';
        }
        $super = $this->isSuperAdmin($adminId);
        echo '<h2>CloudHost247 Passkey</h2>';
        $this->renderServiceBanner();
        if ($this->notice !== null) {
            echo '<div class="alert alert-success">' . $this->e($this->notice) . '</div>';
        }
        if ($this->error !== null) {
            echo '<div class="alert alert-danger">' . $this->e($this->error) . '</div>';
        }
        $this->renderTabs($tab, $super);
        try {
            switch ($tab) {
                case 'mine':
                    $this->renderMine($adminId);
                    break;
                case 'credentials':
                    $this->renderCredentials($adminId, $super);
                    break;
                case 'events':
                    $this->renderEvents($super);
                    break;
                case 'policy':
                    $this->renderPolicy($adminId, $super);
                    break;
                case 'settings':
                    $this->renderSettings($super);
                    break;
                case 'entra':
                    $this->renderEntra($super);
                    break;
                case 'diagnostics':
                    $this->renderDiagnostics();
                    break;
                default:
                    $this->renderDashboard($super);
                    break;
            }
        } catch (\Throwable $error) {
            echo '<div class="alert alert-warning">Passkey data is temporarily unavailable. '
                . 'Existing WHMCS authentication is unaffected.</div>';
        }
    }

    // ------------------------------------------------------------------
    // POST handling (settings, policy, credentials, maintenance, secret).
    // ------------------------------------------------------------------

    private function handlePost($adminId)
    {
        try {
            CsrfProtection::verify();
            $op = (string) $_POST['ch247pk_admin'];
            $super = $this->isSuperAdmin($adminId);
            switch ($op) {
                case 'save_settings':
                    $this->requireSuper($super);
                    $this->opSaveSettings($adminId);
                    break;
                case 'set_policy':
                    $this->requireSuper($super);
                    $this->opSetPolicy($adminId);
                    break;
                case 'clear_policy':
                    $this->requireSuper($super);
                    $this->opClearPolicy($adminId);
                    break;
                case 'cred_rename':
                    $this->opCredential($adminId, $super, 'rename');
                    break;
                case 'cred_revoke':
                    $this->opCredential($adminId, $super, 'revoke');
                    break;
                case 'cred_disable':
                    $this->opCredential($adminId, $super, 'disable');
                    break;
                case 'cred_enable':
                    $this->opCredential($adminId, $super, 'enable');
                    break;
                case 'maintenance_run':
                    $this->requireSuper($super);
                    $this->opMaintenance();
                    break;
                case 'entra_secret':
                    $this->requireSuper($super);
                    $this->opEntraSecret($adminId);
                    break;
                default:
                    throw new \RuntimeException('Unknown administrator action.');
            }
        } catch (\Throwable $error) {
            $this->error = $this->publicAdminError($error);
        }
    }

    private function opSaveSettings($adminId)
    {
        $settings = (new SettingsRepository())->values();
        $identity = new WhmcsIdentity(IdentityScope::ADMIN, $adminId);
        $this->requireStepUp($settings, $identity, 'auth.policy', 'settings.update');
        $clean = $this->validateSettings($_POST);
        foreach ($clean as $key => $value) {
            $this->writeSetting($key, $value, $adminId);
        }
        (new SecurityEventRepository())->append([
            'user_type' => IdentityScope::ADMIN,
            'user_id' => $adminId,
            'event_type' => 'policy.changed',
            'success' => 1,
            'reason_code' => 'settings_update',
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => ['action_code' => 'settings.update'],
        ]);
        $this->logActivity('CloudHost247 Passkey settings updated by administrator #' . $adminId . '.');
        $this->notice = 'Passkey settings saved.';
    }

    private function opSetPolicy($adminId)
    {
        $settings = (new SettingsRepository())->values();
        $administrator = new WhmcsIdentity(IdentityScope::ADMIN, $adminId);
        $this->requireStepUp($settings, $administrator, 'auth.policy', 'policy.set');
        $target = new WhmcsIdentity(
            isset($_POST['user_type']) ? (string) $_POST['user_type'] : '',
            isset($_POST['user_id']) ? $_POST['user_id'] : null
        );
        $policy = isset($_POST['policy']) ? (string) $_POST['policy'] : '';
        $untilRaw = isset($_POST['temporary_until']) ? trim((string) $_POST['temporary_until']) : '';
        $until = null;
        if ($untilRaw !== '') {
            $until = str_replace('T', ' ', $untilRaw);
            if (strlen($until) === 16) {
                $until .= ':00';
            }
        }
        $reason = isset($_POST['reason_code']) ? trim((string) $_POST['reason_code']) : 'administrator_change';
        $service = new PasskeyPolicyAdministrationService(
            new WhmcsNativeIdentityProvider(),
            new CallbackPasskeyPolicyAdminAuthorization(function ($admin, $targetIdentity, $operation) {
                return $this->isSuperAdmin($admin->userId());
            })
        );
        $service->setPolicy($administrator, $target, $policy, $until, $reason === '' ? 'administrator_change' : $reason, [
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
        ]);
        $this->logActivity('CloudHost247 Passkey policy set for ' . $target->userType() . ' #' . $target->userId() . '.');
        $this->notice = 'Passkey policy updated.';
    }

    private function opClearPolicy($adminId)
    {
        $settings = (new SettingsRepository())->values();
        $administrator = new WhmcsIdentity(IdentityScope::ADMIN, $adminId);
        $this->requireStepUp($settings, $administrator, 'auth.policy', 'policy.clear');
        $target = new WhmcsIdentity(
            isset($_POST['user_type']) ? (string) $_POST['user_type'] : '',
            isset($_POST['user_id']) ? $_POST['user_id'] : null
        );
        $service = new PasskeyPolicyAdministrationService(
            new WhmcsNativeIdentityProvider(),
            new CallbackPasskeyPolicyAdminAuthorization(function ($admin, $targetIdentity, $operation) {
                return $this->isSuperAdmin($admin->userId());
            })
        );
        $service->clearPolicy($administrator, $target, 'administrator_clear', [
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
        ]);
        $this->notice = 'Passkey policy cleared to the audience default.';
    }

    private function opCredential($adminId, $super, $op)
    {
        $userType = isset($_POST['user_type']) ? (string) $_POST['user_type'] : '';
        $userId = isset($_POST['user_id']) ? $_POST['user_id'] : null;
        $recordId = isset($_POST['credential_record_id']) ? $_POST['credential_record_id'] : null;
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        if (!$super && ($userType !== IdentityScope::ADMIN || (int) $userId !== (int) $adminId)) {
            throw new \RuntimeException('FORBIDDEN');
        }
        $settings = (new SettingsRepository())->values();
        $actor = new WhmcsIdentity(IdentityScope::ADMIN, $adminId);
        $actionCode = $op === 'rename' ? null : ($op === 'revoke' ? 'credential.revoke' : 'credential.disable');
        if ($actionCode !== null) {
            $this->requireStepUp($settings, $actor, $actionCode, 'credential.' . $op);
        }
        $repo = new CredentialManagementRepository();
        if ($op === 'rename') {
            $summary = $repo->rename($userType, $userId, $recordId, isset($_POST['device_name']) ? (string) $_POST['device_name'] : '');
        } elseif ($op === 'revoke') {
            $summary = $repo->revoke($userType, $userId, $recordId);
        } else {
            $summary = $repo->setDisabled($userType, $userId, $recordId, $op === 'disable');
        }
        (new SecurityEventRepository())->append([
            'user_type' => $userType,
            'user_id' => $userId,
            'passkey_id' => $summary['id'],
            'event_type' => 'credential.admin_managed',
            'success' => 1,
            'reason_code' => 'admin_' . $op,
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'metadata' => ['action_code' => 'credential.' . $op],
        ]);
        $this->logActivity('CloudHost247 Passkey credential #' . $summary['id'] . ' ' . $op . ' by administrator #' . $adminId . '.');
        $this->notice = 'Credential updated.';
    }

    private function opMaintenance()
    {
        $result = (new PasskeyMaintenanceService())->run(time(), 500);
        $this->notice = 'Maintenance complete: ' . (int) $result['total_deleted'] . ' expired row(s) purged.';
    }

    private function opEntraSecret($adminId)
    {
        $secret = isset($_POST['entra_client_secret']) ? (string) $_POST['entra_client_secret'] : '';
        if ($secret === '' || strlen($secret) > 2048) {
            throw new \InvalidArgumentException('Entra client secret is empty or too long.');
        }
        if (!function_exists('encrypt')) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        $ciphertext = 'ch247pk:v1:' . base64_encode(encrypt($secret));
        $existing = Db::firstQuery(
            'SELECT `setting_key` FROM `' . Db::table('settings') . '` WHERE `setting_key` = ?',
            ['entra_client_secret']
        );
        $now = gmdate('Y-m-d H:i:s');
        if ($existing) {
            Db::update('settings', ['setting_key' => 'entra_client_secret'], [
                'setting_value' => null,
                'encrypted_value' => $ciphertext,
                'is_secret' => 1,
                'updated_by_admin_id' => $adminId,
                'updated_at' => $now,
            ]);
        } else {
            Db::insert('settings', [
                'setting_key' => 'entra_client_secret',
                'setting_value' => null,
                'encrypted_value' => $ciphertext,
                'is_secret' => 1,
                'updated_by_admin_id' => $adminId,
                'updated_at' => $now,
            ]);
        }
        $this->logActivity('CloudHost247 Passkey Entra client secret stored by administrator #' . $adminId . '.');
        $this->notice = 'Entra client secret stored encrypted. It is never displayed again.';
    }

    // ------------------------------------------------------------------
    // Views.
    // ------------------------------------------------------------------

    private function renderTabs($active, $super)
    {
        $tabs = [
            'dashboard' => 'Dashboard',
            'mine' => 'My Passkeys',
            'credentials' => 'Credentials',
            'events' => 'Activity Log',
            'policy' => 'Policy',
            'settings' => 'Settings',
            'entra' => 'Entra ID',
            'diagnostics' => 'Diagnostics',
        ];
        echo '<ul class="nav nav-tabs" style="margin-bottom:15px">';
        foreach ($tabs as $key => $label) {
            if (!$super && in_array($key, ['settings', 'policy', 'entra'], true)) {
                continue;
            }
            $url = $this->modulelink . '&ch247pk_tab=' . $key;
            $class = $key === $active ? ' class="active"' : '';
            echo '<li' . $class . '><a href="' . $this->e($url) . '">' . $this->e($label) . '</a></li>';
        }
        echo '</ul>';
    }

    private function renderDashboard($super)
    {
        $stats = $this->dashboardStats();
        $cards = [
            ['Registered Passkeys', $stats['total']],
            ['Active Passkeys', $stats['active']],
            ['Clients Using Passkeys', $stats['clients_using']],
            ['Administrators Using Passkeys', $stats['admins_using']],
            ['Passkey Logins (24h)', $stats['logins_24h']],
            ['Failed Attempts (24h)', $stats['failed_24h']],
            ['Revoked Credentials', $stats['revoked']],
            ['Enforced Accounts', $stats['enforced']],
        ];
        echo '<div class="row">';
        foreach ($cards as $card) {
            echo '<div class="col-sm-3"><div class="panel panel-default"><div class="panel-body text-center">'
                . '<div style="font-size:26px;font-weight:700">' . (int) $card[1] . '</div>'
                . '<div class="text-muted">' . $this->e($card[0]) . '</div></div></div></div>';
        }
        echo '</div>';
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Authentication activity (7 days)</strong></div>'
            . '<div class="panel-body">';
        $max = 1;
        foreach ($stats['by_day'] as $day) {
            $max = max($max, $day['ok'] + $day['fail']);
        }
        foreach ($stats['by_day'] as $day) {
            $okWidth = (int) round(($day['ok'] / $max) * 100);
            $failWidth = (int) round(($day['fail'] / $max) * 100);
            echo '<div style="margin-bottom:6px"><span style="display:inline-block;width:110px">' . $this->e($day['day']) . '</span>'
                . '<span style="display:inline-block;background:#5cb85c;height:12px;width:' . $okWidth . '%" title="Successful: ' . (int) $day['ok'] . '"></span>'
                . '<span style="display:inline-block;background:#d9534f;height:12px;width:' . $failWidth . '%" title="Failed: ' . (int) $day['fail'] . '"></span>'
                . ' <small class="text-muted">' . (int) $day['ok'] . ' ok / ' . (int) $day['fail'] . ' failed</small></div>';
        }
        echo '</div></div>';
        if ($super) {
            echo '<form method="post" action="' . $this->e($this->modulelink) . '&ch247pk_tab=dashboard">'
                . CsrfProtection::field()
                . '<input type="hidden" name="ch247pk_admin" value="maintenance_run">'
                . '<button class="btn btn-default" type="submit">Run retention maintenance now</button></form>';
        }
    }

    private function renderMine($adminId)
    {
        $identity = new WhmcsIdentity(IdentityScope::ADMIN, $adminId);
        $settings = (new SettingsRepository())->values();
        $policy = new PasskeyLoginPolicy($settings);
        $credentials = [];
        try {
            $management = new PasskeyCredentialManagementService(
                new WebAuthnService(),
                new WhmcsNativeIdentityProvider(),
                $policy
            );
            $credentials = $management->listCredentials($identity, true);
        } catch (\Throwable $error) {
            $credentials = [];
        }
        $available = $policy->isEnabledFor(IdentityScope::ADMIN) && !empty($settings['rp_id']);
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>My Passkeys</strong></div><div class="panel-body">';
        if (!$available) {
            echo '<div class="alert alert-warning">Passkey service is not enabled for administrators (CONFIGURATION_REQUIRED).</div>';
        }
        echo '<p class="text-muted">Use your device secure authentication to sign in without entering your password.</p>';
        echo '<button class="btn btn-primary" id="ch247pk-admin-add" ' . ($available ? '' : 'disabled') . '>Add Passkey</button> ';
        echo '<span class="text-muted" id="ch247pk-admin-status"></span>';
        echo '<table class="table table-striped" style="margin-top:15px"><tr><th>Device</th><th>Registered</th><th>Last used</th><th>Status</th><th>Actions</th></tr>';
        foreach ($credentials as $credential) {
            echo '<tr><td>' . $this->e($credential['device_name']) . '<br><small class="text-muted">'
                . $this->e($credential['credential_id_display']) . '</small></td>'
                . '<td>' . $this->e($credential['created_at']) . '</td>'
                . '<td>' . $this->e($credential['last_used_at'] ?: 'Never') . '</td>'
                . '<td>' . $this->e($credential['status']) . '</td><td>'
                . '<button class="btn btn-xs btn-default" data-ch247pk-admin-rename="' . (int) $credential['id'] . '">Rename</button> '
                . ($credential['status'] === 'disabled'
                    ? '<button class="btn btn-xs btn-default" data-ch247pk-admin-enable="' . (int) $credential['id'] . '">Enable</button> '
                    : '<button class="btn btn-xs btn-default" data-ch247pk-admin-disable="' . (int) $credential['id'] . '">Disable</button> ')
                . '<button class="btn btn-xs btn-danger" data-ch247pk-admin-revoke="' . (int) $credential['id'] . '">Revoke</button>'
                . '</td></tr>';
        }
        if (!$credentials) {
            echo '<tr><td colspan="5" class="text-muted">No Passkeys registered yet.</td></tr>';
        }
        echo '</table></div></div>';
        $ajax = $this->modulelink . '&passkey_ajax=1';
        echo '<script>window.CH247PK_ADMIN = {ajaxUrl: ' . json_encode($ajax)
            . ', csrfToken: ' . json_encode($this->safeToken())
            . ', csrfField: ' . json_encode(CsrfProtection::FIELD) . '};</script>';
    }

    private function renderCredentials($adminId, $super)
    {
        $filterType = isset($_GET['f_type']) ? (string) $_GET['f_type'] : '';
        $filterStatus = isset($_GET['f_status']) ? (string) $_GET['f_status'] : '';
        $filterUser = isset($_GET['f_user']) ? (string) $_GET['f_user'] : '';
        $page = max(1, min(1000, (int) (isset($_GET['p']) ? $_GET['p'] : 1)));
        $per = 25;
        $where = [];
        $bindings = [];
        if (in_array($filterType, [IdentityScope::CLIENT, IdentityScope::ADMIN, IdentityScope::CLIENT_USER], true)) {
            $where[] = '`user_type` = ?';
            $bindings[] = $filterType;
        }
        if ($filterStatus === 'active') {
            $where[] = '`revoked_at` IS NULL AND `disabled_at` IS NULL';
        } elseif ($filterStatus === 'revoked') {
            $where[] = '`revoked_at` IS NOT NULL';
        } elseif ($filterStatus === 'disabled') {
            $where[] = '`revoked_at` IS NULL AND `disabled_at` IS NOT NULL';
        }
        if ($filterUser !== '' && preg_match('/^[0-9]+$/', $filterUser)) {
            $where[] = '`user_id` = ?';
            $bindings[] = (int) $filterUser;
        }
        if (!$super) {
            $where[] = '`user_type` = ? AND `user_id` = ?';
            $bindings[] = IdentityScope::ADMIN;
            $bindings[] = $adminId;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $totalRow = Db::firstQuery(
            'SELECT COUNT(*) AS c FROM `' . Db::table('credentials') . '` ' . $sqlWhere,
            $bindings
        );
        $total = $totalRow ? (int) $totalRow['c'] : 0;
        $offset = ($page - 1) * $per;
        $rows = Db::query(
            'SELECT * FROM `' . Db::table('credentials') . '` ' . $sqlWhere . ' ORDER BY `id` DESC LIMIT ' . $per . ' OFFSET ' . $offset,
            $bindings
        );
        $settings = (new SettingsRepository())->values();
        $confirmRequired = $this->stepUpRequired($settings);
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Credentials</strong>'
            . ' <small class="text-muted">Private key material is never displayed.</small></div><div class="panel-body">';
        echo '<form class="form-inline" method="get" action="addonmodules.php" style="margin-bottom:12px">'
            . '<input type="hidden" name="module" value="cloudhost247passkey">'
            . '<input type="hidden" name="ch247pk_tab" value="credentials">'
            . ' Type <select class="form-control input-sm" name="f_type"><option value="">All</option>'
            . $this->options(['client' => 'Client', 'client_user' => 'Client user', 'admin' => 'Administrator'], $filterType) . '</select>'
            . ' Status <select class="form-control input-sm" name="f_status"><option value="">All</option>'
            . $this->options(['active' => 'Active', 'disabled' => 'Disabled', 'revoked' => 'Revoked'], $filterStatus) . '</select>'
            . ' User ID <input class="form-control input-sm" name="f_user" size="8" value="' . $this->e($filterUser) . '">'
            . ' <button class="btn btn-sm btn-default" type="submit">Filter</button></form>';
        echo '<table class="table table-striped table-condensed"><tr><th>User</th><th>Device</th><th>Created</th>'
            . '<th>Last used</th><th>Status</th><th>Actions</th></tr>';
        foreach ($rows as $row) {
            try {
                $public = (new CredentialRecord($row))->toPublicArray();
            } catch (\Throwable $error) {
                continue;
            }
            echo '<tr><td>' . $this->e($public['user_type']) . ' #' . (int) $public['user_id'] . '</td>'
                . '<td>' . $this->e($public['device_name']) . '<br><small class="text-muted">'
                . $this->e($public['credential_id_display']) . '</small></td>'
                . '<td>' . $this->e($public['created_at']) . '</td>'
                . '<td>' . $this->e($public['last_used_at'] ?: 'Never') . '</td>'
                . '<td>' . $this->e($public['status']) . '</td><td>';
            $this->credentialActionForms($public, $confirmRequired);
            echo '</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="6" class="text-muted">No credentials match this filter.</td></tr>';
        }
        echo '</table>';
        $pages = max(1, (int) ceil($total / $per));
        echo '<p class="text-muted">Page ' . $page . ' of ' . $pages . ' (' . $total . ' total).</p>';
        if ($page > 1) {
            echo '<a class="btn btn-xs btn-default" href="' . $this->e($this->pageUrl($page - 1)) . '">Previous</a> ';
        }
        if ($page < $pages) {
            echo '<a class="btn btn-xs btn-default" href="' . $this->e($this->pageUrl($page + 1)) . '">Next</a>';
        }
        echo '</div></div>';
    }

    private function renderEvents($super)
    {
        $filterType = isset($_GET['e_type']) ? (string) $_GET['e_type'] : '';
        $filterSuccess = isset($_GET['e_ok']) ? (string) $_GET['e_ok'] : '';
        $page = max(1, min(1000, (int) (isset($_GET['p']) ? $_GET['p'] : 1)));
        $per = 25;
        $where = [];
        $bindings = [];
        if ($filterType !== '' && preg_match('/^[a-z0-9_.]+$/', $filterType)) {
            $where[] = '`event_type` = ?';
            $bindings[] = $filterType;
        }
        if ($filterSuccess === '1' || $filterSuccess === '0') {
            $where[] = '`success` = ?';
            $bindings[] = (int) $filterSuccess;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $totalRow = Db::firstQuery('SELECT COUNT(*) AS c FROM `' . Db::table('events') . '` ' . $sqlWhere, $bindings);
        $total = $totalRow ? (int) $totalRow['c'] : 0;
        $offset = ($page - 1) * $per;
        $rows = Db::query(
            'SELECT `user_type`, `user_id`, `passkey_id`, `event_type`, `success`, `reason_code`, '
            . '`ip_address`, `metadata_json`, `created_at` FROM `' . Db::table('events') . '` '
            . $sqlWhere . ' ORDER BY `id` DESC LIMIT ' . $per . ' OFFSET ' . $offset,
            $bindings
        );
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Activity Log</strong>'
            . ' <small class="text-muted">Append-only; secrets are never logged.</small></div><div class="panel-body">';
        echo '<form class="form-inline" method="get" action="addonmodules.php" style="margin-bottom:12px">'
            . '<input type="hidden" name="module" value="cloudhost247passkey">'
            . '<input type="hidden" name="ch247pk_tab" value="events">'
            . ' Event <input class="form-control input-sm" name="e_type" value="' . $this->e($filterType) . '">'
            . ' Result <select class="form-control input-sm" name="e_ok"><option value="">All</option>'
            . $this->options(['1' => 'Success', '0' => 'Failure'], $filterSuccess) . '</select>'
            . ' <button class="btn btn-sm btn-default" type="submit">Filter</button></form>';
        echo '<table class="table table-striped table-condensed"><tr><th>Time (UTC)</th><th>User</th><th>Event</th>'
            . '<th>Result</th><th>Reason</th><th>IP</th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td>' . $this->e($row['created_at']) . '</td>'
                . '<td>' . $this->e($row['user_type']) . ' ' . $this->e($row['user_id'] === null ? '-' : '#' . $row['user_id']) . '</td>'
                . '<td>' . $this->e($row['event_type']) . '</td>'
                . '<td>' . ((int) $row['success'] === 1 ? 'Success' : 'Failure') . '</td>'
                . '<td>' . $this->e($row['reason_code'] ?: '-') . '</td>'
                . '<td>' . $this->e($row['ip_address'] ?: '-') . '</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="6" class="text-muted">No events match this filter.</td></tr>';
        }
        echo '</table></div></div>';
    }

    private function renderPolicy($adminId, $super)
    {
        if (!$super) {
            echo '<div class="alert alert-warning">Per-identity policy management requires a Super Administrator.</div>';
            return;
        }
        $lookupType = isset($_GET['u_type']) ? (string) $_GET['u_type'] : IdentityScope::CLIENT;
        $lookupId = isset($_GET['u_id']) ? (string) $_GET['u_id'] : '';
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Individual Policy</strong></div><div class="panel-body">';
        echo '<form class="form-inline" method="get" action="addonmodules.php" style="margin-bottom:12px">'
            . '<input type="hidden" name="module" value="cloudhost247passkey">'
            . '<input type="hidden" name="ch247pk_tab" value="policy">'
            . ' Type <select class="form-control input-sm" name="u_type">'
            . $this->options(['client' => 'Client', 'admin' => 'Administrator'], $lookupType) . '</select>'
            . ' User ID <input class="form-control input-sm" name="u_id" size="10" value="' . $this->e($lookupId) . '">'
            . ' <button class="btn btn-sm btn-default" type="submit">Look up</button></form>';
        if ($lookupId !== '' && preg_match('/^[0-9]+$/', $lookupId)) {
            $this->renderPolicyDetail($lookupType, (int) $lookupId);
        } else {
            echo '<p class="text-muted">Look up a client or administrator to view credentials, last Passkey login, and policy.</p>';
        }
        echo '</div></div>';
    }

    private function renderPolicyDetail($userType, $userId)
    {
        $settings = (new SettingsRepository())->values();
        $policy = new PasskeyLoginPolicy($settings);
        $resolver = new PasskeyPolicyResolver($policy);
        try {
            list($userType, $userId) = IdentityScope::validate($userType, $userId);
        } catch (\Throwable $error) {
            echo '<div class="alert alert-danger">Invalid identity scope.</div>';
            return;
        }
        $effective = 'unknown';
        try {
            $effective = $resolver->effectivePolicy($userType, $userId);
        } catch (\Throwable $error) {
            $effective = 'unavailable';
        }
        $override = Db::firstQuery(
            'SELECT `policy`, `temporary_disabled_until`, `reason_code`, `updated_at` FROM `'
            . Db::table('user_policies') . '` WHERE `user_type` = ? AND `user_id` = ?',
            [$userType, $userId]
        );
        $count = Db::count('credentials', ['user_type' => $userType, 'user_id' => $userId, 'revoked_at' => null, 'disabled_at' => null]);
        $last = Db::firstQuery(
            'SELECT `created_at` FROM `' . Db::table('events') . '` WHERE `user_type` = ? AND `user_id` = ? '
            . 'AND `event_type` = ? AND `success` = 1 ORDER BY `id` DESC LIMIT 1',
            [$userType, $userId, 'authentication.succeeded']
        );
        echo '<dl class="dl-horizontal"><dt>Identity</dt><dd>' . $this->e($userType) . ' #' . (int) $userId . '</dd>'
            . '<dt>Effective policy</dt><dd><strong>' . $this->e($effective) . '</strong></dd>'
            . '<dt>Active credentials</dt><dd>' . (int) $count . '</dd>'
            . '<dt>Last Passkey login</dt><dd>' . $this->e($last ? $last['created_at'] : 'Never') . '</dd>'
            . '<dt>Stored override</dt><dd>' . $this->e($override ? $override['policy'] : 'default') . '</dd></dl>';
        $confirmRequired = $this->stepUpRequired($settings) ? '1' : '0';
        echo '<form method="post" action="' . $this->e($this->modulelink) . '&ch247pk_tab=policy&u_type='
            . $this->e($userType) . '&u_id=' . (int) $userId . '" data-ch247pk-confirm="auth.policy" data-confirm-required="' . $confirmRequired . '">'
            . CsrfProtection::field()
            . '<input type="hidden" name="ch247pk_admin" value="set_policy">'
            . '<input type="hidden" name="user_type" value="' . $this->e($userType) . '">'
            . '<input type="hidden" name="user_id" value="' . (int) $userId . '">'
            . '<div class="form-inline"> Policy <select class="form-control input-sm" name="policy">'
            . $this->options(
                ['default' => 'Default', 'optional' => 'Passkey optional', 'required' => 'Passkey required', 'temporarily_disabled' => 'Temporarily disabled'],
                $override ? $override['policy'] : 'default'
            ) . '</select>'
            . ' Until (UTC, temporary only) <input class="form-control input-sm" type="datetime-local" name="temporary_until" value="">'
            . ' Reason <input class="form-control input-sm" name="reason_code" size="20" value="administrator_change">'
            . ' <button class="btn btn-sm btn-primary" type="submit">Save policy</button></div></form> ';
        echo '<form method="post" action="' . $this->e($this->modulelink) . '&ch247pk_tab=policy&u_type='
            . $this->e($userType) . '&u_id=' . (int) $userId . '" style="margin-top:8px" data-ch247pk-confirm="auth.policy" data-confirm-required="' . $confirmRequired . '">'
            . CsrfProtection::field()
            . '<input type="hidden" name="ch247pk_admin" value="clear_policy">'
            . '<input type="hidden" name="user_type" value="' . $this->e($userType) . '">'
            . '<input type="hidden" name="user_id" value="' . (int) $userId . '">'
            . '<button class="btn btn-sm btn-default" type="submit">Clear to audience default</button></form>';
        $this->renderConfirmScript();
    }

    private function renderSettings($super)
    {
        if (!$super) {
            echo '<div class="alert alert-warning">Global settings require a Super Administrator.</div>';
            return;
        }
        $settings = (new SettingsRepository())->values();
        $origins = '';
        $decoded = json_decode(isset($settings['allowed_origins']) ? $settings['allowed_origins'] : '[]', true);
        if (is_array($decoded)) {
            $origins = implode("\n", $decoded);
        }
        $confirmRequired = $this->stepUpRequired($settings) ? '1' : '0';
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>General &amp; Authentication Policy</strong></div>'
            . '<div class="panel-body"><form method="post" action="' . $this->e($this->modulelink) . '&ch247pk_tab=settings"'
            . ' data-ch247pk-confirm="auth.policy" data-confirm-required="' . $confirmRequired . '">'
            . CsrfProtection::field() . '<input type="hidden" name="ch247pk_admin" value="save_settings">';
        $this->field('Enable Passkey service', '<select class="form-control" name="service_enabled">'
            . $this->options(['0' => 'Disabled', '1' => 'Enabled'], isset($settings['service_enabled']) ? $settings['service_enabled'] : '0') . '</select>'
            . '<p class="help-block">Keep disabled until RP ID, origins, and HTTPS are verified.</p>');
        $this->field('Client policy', '<select class="form-control" name="client_policy">'
            . $this->options(['optional' => 'Optional', 'required' => 'Required for all clients'], isset($settings['client_policy']) ? $settings['client_policy'] : 'optional') . '</select>');
        $this->field('Administrator policy', '<select class="form-control" name="admin_policy">'
            . $this->options(['optional' => 'Optional', 'required' => 'Required for all administrators'], isset($settings['admin_policy']) ? $settings['admin_policy'] : 'optional') . '</select>');
        $this->field('Password fallback', '<select class="form-control" name="password_fallback">'
            . $this->options(['allowed' => 'Allowed', 'disabled' => 'Disabled when Passkey is required'], isset($settings['password_fallback']) ? $settings['password_fallback'] : 'allowed') . '</select>'
            . '<p class="help-block">Disabling fallback never locks accounts permanently: use a temporary per-identity exemption for recovery.</p>');
        $maxOptions = ['1' => '1', '2' => '2', '3' => '3', '5' => '5', '10' => '10', '25' => '25', '50' => '50', 'unlimited' => 'Unlimited (bounded at 1000)'];
        $this->field('Max Passkeys per client', '<select class="form-control" name="max_credentials_client">'
            . $this->options($maxOptions, isset($settings['max_credentials_client']) ? $settings['max_credentials_client'] : '5') . '</select>');
        $this->field('Max Passkeys per administrator', '<select class="form-control" name="max_credentials_admin">'
            . $this->options($maxOptions, isset($settings['max_credentials_admin']) ? $settings['max_credentials_admin'] : '5') . '</select>');
        $this->field('User verification', '<select class="form-control" name="user_verification">'
            . $this->options(['preferred' => 'Preferred', 'required' => 'Required', 'discouraged' => 'Discouraged'], isset($settings['user_verification']) ? $settings['user_verification'] : 'preferred') . '</select>');
        $this->field('Sensitive-action user verification', '<select class="form-control" name="sensitive_action_user_verification">'
            . $this->options(['required' => 'Required', 'preferred' => 'Preferred'], isset($settings['sensitive_action_user_verification']) ? $settings['sensitive_action_user_verification'] : 'required') . '</select>');
        $this->field('Sensitive-action step-up policy', '<select class="form-control" name="sensitive_action_policy">'
            . $this->options(
                ['disabled' => 'Disabled', 'optional' => 'Optional', 'required_for_admin' => 'Required for administrators', 'required' => 'Required for everyone'],
                isset($settings['sensitive_action_policy']) ? $settings['sensitive_action_policy'] : 'optional'
            ) . '</select>');
        echo '</div></div>';
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>WebAuthn (RP &amp; Origins)</strong></div><div class="panel-body">';
        $this->field('RP display name', '<input class="form-control" name="rp_name" maxlength="64" value="' . $this->e(isset($settings['rp_name']) ? $settings['rp_name'] : '') . '">');
        $this->field('RP ID', '<input class="form-control" name="rp_id" maxlength="253" placeholder="portal.example.com" value="' . $this->e(isset($settings['rp_id']) ? $settings['rp_id'] : '') . '">'
            . '<p class="help-block">Must be the effective authentication domain. Never a development placeholder in production.</p>');
        $this->field('Allowed origins (one exact https origin per line)', '<textarea class="form-control" name="allowed_origins" rows="4" placeholder="https://portal.example.com">' . $this->e($origins) . '</textarea>');
        echo '</div></div>';
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Notifications &amp; Retention</strong></div><div class="panel-body">';
        $this->field('Login notifications (global)', '<select class="form-control" name="login_notifications_enabled">'
            . $this->options(['0' => 'Off', '1' => 'On'], isset($settings['login_notifications_enabled']) ? $settings['login_notifications_enabled'] : '0') . '</select>'
            . '<p class="help-block">Users must still opt in individually.</p>');
        $this->field('Security-event notifications (global)', '<select class="form-control" name="security_event_notifications_enabled">'
            . $this->options(['0' => 'Off', '1' => 'On'], isset($settings['security_event_notifications_enabled']) ? $settings['security_event_notifications_enabled'] : '0') . '</select>');
        $this->field('Event retention (days, 1-3650)', '<input class="form-control" name="event_retention_days" size="8" value="' . $this->e(isset($settings['event_retention_days']) ? $settings['event_retention_days'] : '365') . '">');
        $this->field('Challenge retention (hours, 1-720)', '<input class="form-control" name="challenge_retention_hours" size="8" value="' . $this->e(isset($settings['challenge_retention_hours']) ? $settings['challenge_retention_hours'] : '24') . '">');
        echo '<button class="btn btn-primary" type="submit">Save settings</button></form></div></div>';
        $this->renderConfirmScript();
    }

    private function renderEntra($super)
    {
        if (!$super) {
            echo '<div class="alert alert-warning">Entra ID configuration requires a Super Administrator.</div>';
            return;
        }
        $settings = (new SettingsRepository())->values();
        $domains = '';
        $decoded = json_decode(isset($settings['entra_allowed_domains']) ? $settings['entra_allowed_domains'] : '[]', true);
        if (is_array($decoded)) {
            $domains = implode("\n", $decoded);
        }
        echo '<div class="alert alert-info">Microsoft Entra ID is <strong>optional</strong>. Interactive OAuth sign-in is not enabled by this addon version: '
            . 'these settings prepare verified account linking, and sign-in attempts report CONFIGURATION_REQUIRED until then.</div>';
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Microsoft Entra ID</strong></div><div class="panel-body">'
            . '<form method="post" action="' . $this->e($this->modulelink) . '&ch247pk_tab=entra">'
            . CsrfProtection::field() . '<input type="hidden" name="ch247pk_admin" value="save_settings">';
        // Reuse the settings saver: include current non-Entra values as hidden fields.
        foreach (['service_enabled', 'client_policy', 'admin_policy', 'password_fallback', 'max_credentials_client', 'max_credentials_admin', 'user_verification', 'sensitive_action_user_verification', 'sensitive_action_policy', 'rp_name', 'rp_id', 'login_notifications_enabled', 'security_event_notifications_enabled', 'event_retention_days', 'challenge_retention_hours'] as $passthrough) {
            echo '<input type="hidden" name="' . $this->e($passthrough) . '" value="' . $this->e(isset($settings[$passthrough]) ? $settings[$passthrough] : '') . '">';
        }
        $origins = '';
        $originDecoded = json_decode(isset($settings['allowed_origins']) ? $settings['allowed_origins'] : '[]', true);
        if (is_array($originDecoded)) {
            $origins = implode("\n", $originDecoded);
        }
        echo '<input type="hidden" name="allowed_origins" value="' . $this->e($origins) . '">';
        $this->field('Enable Entra ID linking', '<select class="form-control" name="entra_enabled">'
            . $this->options(['0' => 'Disabled', '1' => 'Enabled'], isset($settings['entra_enabled']) ? $settings['entra_enabled'] : '0') . '</select>');
        $this->field('Client-area login enabled', '<select class="form-control" name="entra_client_login_enabled">'
            . $this->options(['0' => 'No', '1' => 'Yes'], isset($settings['entra_client_login_enabled']) ? $settings['entra_client_login_enabled'] : '0') . '</select>');
        $this->field('Admin login enabled', '<select class="form-control" name="entra_admin_login_enabled">'
            . $this->options(['0' => 'No', '1' => 'Yes'], isset($settings['entra_admin_login_enabled']) ? $settings['entra_admin_login_enabled'] : '0') . '</select>');
        $this->field('Tenant ID', '<input class="form-control" name="entra_tenant_id" maxlength="191" value="' . $this->e(isset($settings['entra_tenant_id']) ? $settings['entra_tenant_id'] : '') . '">');
        $this->field('Client ID', '<input class="form-control" name="entra_client_id" maxlength="191" value="' . $this->e(isset($settings['entra_client_id']) ? $settings['entra_client_id'] : '') . '">');
        $this->field('Redirect URI', '<input class="form-control" name="entra_redirect_uri" maxlength="512" placeholder="https://portal.example.com/index.php?m=cloudhost247passkey&entra=callback" value="' . $this->e(isset($settings['entra_redirect_uri']) ? $settings['entra_redirect_uri'] : '') . '">');
        $this->field('Allowed domains (one per line)', '<textarea class="form-control" name="entra_allowed_domains" rows="3">' . $this->e($domains) . '</textarea>');
        echo '<button class="btn btn-primary" type="submit">Save Entra settings</button></form></div></div>';
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Client secret</strong></div><div class="panel-body">'
            . '<p class="text-muted">Stored encrypted via WHMCS encryption. Never displayed, never committed to Git.</p>'
            . '<form method="post" action="' . $this->e($this->modulelink) . '&ch247pk_tab=entra">'
            . CsrfProtection::field() . '<input type="hidden" name="ch247pk_admin" value="entra_secret">'
            . '<input class="form-control" type="password" name="entra_client_secret" autocomplete="new-password" maxlength="2048" style="max-width:420px"> '
            . '<button class="btn btn-default" type="submit">Store secret</button></form></div></div>';
    }

    private function renderDiagnostics()
    {
        $settings = (new SettingsRepository())->values();
        $report = PasskeyDiagnostics::collect($settings, RequestContext::isHttps(), RequestContext::requestOrigin());
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Diagnostics</strong>'
            . ' <span class="label ' . ($report['overall'] === 'ok' ? 'label-success' : ($report['overall'] === 'warning' ? 'label-warning' : 'label-danger')) . '">'
            . $this->e(strtoupper($report['overall'])) . '</span></div><div class="panel-body">'
            . '<table class="table table-striped"><tr><th>Check</th><th>Status</th><th>Code</th><th>Detail</th></tr>';
        foreach ($report['checks'] as $name => $check) {
            echo '<tr><td>' . $this->e($name) . '</td><td>' . $this->e($check['status']) . '</td>'
                . '<td><code>' . $this->e($check['code']) . '</code></td><td>' . $this->e($check['summary']) . '</td></tr>';
        }
        echo '</table><p class="text-muted">System URL hint: ' . $this->e(RequestContext::systemUrl() ?: '(unavailable)') . '</p></div></div>';
    }

    // ------------------------------------------------------------------
    // Admin AJAX implementations.
    // ------------------------------------------------------------------

    private static function adminServices()
    {
        $settings = (new SettingsRepository())->values();
        $policy = new PasskeyLoginPolicy($settings);
        return [
            'settings' => $settings,
            'policy' => $policy,
            'provider' => new WhmcsNativeIdentityProvider(),
            'resolver' => new PasskeyPolicyResolver($policy),
            'events' => new SecurityEventRepository(),
        ];
    }

    private static function adminConfig(array $settings)
    {
        return WebAuthnConfig::fromSettings(
            $settings,
            RequestContext::requestOrigin(),
            RequestContext::isHttps()
        );
    }

    private static function adminCsrf()
    {
        if (function_exists('check_token')) {
            CsrfProtection::verify();
            return;
        }
        $submitted = isset($_POST[CsrfProtection::FIELD]) ? $_POST[CsrfProtection::FIELD]
            : (isset($_SERVER[CsrfProtection::HEADER]) ? $_SERVER[CsrfProtection::HEADER] : null);
        CsrfProtection::verify($submitted);
    }

    private static function adminBody()
    {
        $body = RequestContext::jsonBody();
        return $body !== null ? $body : $_POST;
    }

    private static function adminCredentialJson(array $body)
    {
        if (!array_key_exists('response', $body)) {
            throw new \InvalidArgumentException('WebAuthn response is missing.');
        }
        $value = $body['response'];
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if (!is_string($value) || $value === '' || strlen($value) > 262144) {
            throw new \InvalidArgumentException('WebAuthn response is invalid.');
        }
        return $value;
    }

    private static function adminRegisterOptions(WhmcsIdentity $identity)
    {
        self::adminCsrf();
        $services = self::adminServices();
        $config = self::adminConfig($services['settings']);
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            $services['provider'],
            $services['policy']
        );
        $email = 'admin-' . $identity->userId();
        try {
            if (class_exists('WHMCS\\Database\\Capsule')) {
                $row = \WHMCS\Database\Capsule::table('tbladmins')->where('id', $identity->userId())->first();
                if ($row !== null) {
                    $row = (array) $row;
                    if (!empty($row['email'])) {
                        $email = substr((string) $row['email'], 0, 254);
                    }
                }
            }
        } catch (\Throwable $error) {
            // Cosmetic display name only.
        }
        JsonResponse::ok(['options' => $management->beginRegistration($config, $identity, $email, $email)]);
    }

    private static function adminRegisterVerify(WhmcsIdentity $identity)
    {
        self::adminCsrf();
        $body = self::adminBody();
        $services = self::adminServices();
        $config = self::adminConfig($services['settings']);
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            $services['provider'],
            $services['policy']
        );
        $result = $management->finishRegistration(
            $config,
            $identity,
            self::adminCredentialJson($body),
            isset($body['device_name']) ? (string) $body['device_name'] : 'Passkey',
            PasskeyRegistrationContext::fromTrustedArray([
                'ip_address' => RequestContext::ipAddress(),
                'user_agent' => RequestContext::userAgent(),
            ])
        );
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
        JsonResponse::ok(['registered' => true]);
    }

    private static function adminCredentials(WhmcsIdentity $identity)
    {
        self::adminCsrf();
        $services = self::adminServices();
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            $services['provider'],
            $services['policy']
        );
        JsonResponse::ok(['credentials' => $management->listCredentials($identity, true)]);
    }

    private static function adminCredentialRename(WhmcsIdentity $identity)
    {
        self::adminCsrf();
        $body = self::adminBody();
        $services = self::adminServices();
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
        JsonResponse::ok(['credential' => $summary]);
    }

    private static function adminCredentialRevoke(WhmcsIdentity $identity)
    {
        self::adminCsrf();
        $body = self::adminBody();
        $services = self::adminServices();
        self::maybeRequireAdminConfirmation($services, $identity, 'credential.revoke', $body);
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            $services['provider'],
            $services['policy']
        );
        $summary = $management->revokeCredential($identity, isset($body['id']) ? $body['id'] : null);
        JsonResponse::ok(['credential' => $summary]);
    }

    private static function adminCredentialDisable(WhmcsIdentity $identity, $disabled)
    {
        self::adminCsrf();
        $body = self::adminBody();
        $services = self::adminServices();
        if ($disabled) {
            self::maybeRequireAdminConfirmation($services, $identity, 'credential.disable', $body);
        }
        $management = new PasskeyCredentialManagementService(
            new WebAuthnService(),
            $services['provider'],
            $services['policy']
        );
        if ($disabled) {
            $summary = $management->disableCredential($identity, isset($body['id']) ? $body['id'] : null);
        } else {
            $summary = $management->enableCredential($identity, isset($body['id']) ? $body['id'] : null);
        }
        JsonResponse::ok(['credential' => $summary]);
    }

    private static function adminActionOptions(WhmcsIdentity $identity)
    {
        self::adminCsrf();
        $body = self::adminBody();
        $services = self::adminServices();
        $config = self::adminConfig($services['settings']);
        $confirmation = new PasskeyActionConfirmationService($services['provider'], $services['resolver']);
        $issued = $confirmation->beginConfirmation(
            $config,
            new WebAuthnService(),
            $identity,
            isset($body['action_code']) ? (string) $body['action_code'] : ''
        );
        JsonResponse::ok([
            'ticket_id' => $issued['ticket_id'],
            'action_code' => $issued['action_code'],
            'options' => ['publicKey' => $issued['publicKey']],
        ]);
    }

    private static function adminActionVerify(WhmcsIdentity $identity)
    {
        self::adminCsrf();
        $body = self::adminBody();
        $services = self::adminServices();
        $config = self::adminConfig($services['settings']);
        $confirmation = new PasskeyActionConfirmationService($services['provider'], $services['resolver']);
        $receipt = $confirmation->confirm(
            $config,
            new WebAuthnService(),
            $identity,
            isset($body['ticket_id']) ? $body['ticket_id'] : null,
            self::adminCredentialJson($body),
            ['ip_address' => RequestContext::ipAddress(), 'user_agent' => RequestContext::userAgent()]
        );
        JsonResponse::ok(['receipt' => $receipt]);
    }

    private static function maybeRequireAdminConfirmation(array $services, WhmcsIdentity $identity, $actionCode, array $body)
    {
        $policy = isset($services['settings']['sensitive_action_policy'])
            ? $services['settings']['sensitive_action_policy']
            : 'optional';
        if (!in_array($policy, ['required_for_admin', 'required'], true)) {
            return null;
        }
        $confirmation = new PasskeyActionConfirmationService($services['provider'], $services['resolver']);
        if (isset($body['ticket_id']) && array_key_exists('confirmation_response', $body)) {
            $config = self::adminConfig($services['settings']);
            $response = $body['confirmation_response'];
            if (is_array($response)) {
                $response = json_encode($response, JSON_UNESCAPED_SLASHES);
            }
            return $confirmation->confirm(
                $config,
                new WebAuthnService(),
                $identity,
                $body['ticket_id'],
                $response,
                ['ip_address' => RequestContext::ipAddress(), 'user_agent' => RequestContext::userAgent()]
            );
        }
        $config = self::adminConfig($services['settings']);
        $issued = $confirmation->beginConfirmation($config, new WebAuthnService(), $identity, $actionCode);
        JsonResponse::fail('CONFIRMATION_REQUIRED', 'Confirm with your passkey to continue.', 403, [
            'ticket_id' => $issued['ticket_id'],
            'action_code' => $issued['action_code'],
            'options' => ['publicKey' => $issued['publicKey']],
        ]);
        return null;
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    private function dashboardStats()
    {
        $count = function ($sql, array $bindings = []) {
            $row = Db::firstQuery($sql, $bindings);
            return $row ? (int) reset($row) : 0;
        };
        $day = gmdate('Y-m-d H:i:s', time() - 86400);
        $byDay = [];
        for ($i = 6; $i >= 0; $i--) {
            $start = gmdate('Y-m-d 00:00:00', time() - ($i * 86400));
            $end = gmdate('Y-m-d 00:00:00', time() - (($i - 1) * 86400));
            $byDay[] = [
                'day' => gmdate('Y-m-d', time() - ($i * 86400)),
                'ok' => $count(
                    'SELECT COUNT(*) AS c FROM `' . Db::table('events') . '` '
                    . 'WHERE `event_type` = ? AND `success` = 1 AND `created_at` >= ? AND `created_at` < ?',
                    ['authentication.succeeded', $start, $end]
                ),
                'fail' => $count(
                    'SELECT COUNT(*) AS c FROM `' . Db::table('events') . '` '
                    . 'WHERE `event_type` = ? AND `success` = 0 AND `created_at` >= ? AND `created_at` < ?',
                    ['authentication.failed', $start, $end]
                ),
            ];
        }
        return [
            'total' => $count('SELECT COUNT(*) AS c FROM `' . Db::table('credentials') . '`'),
            'active' => $count('SELECT COUNT(*) AS c FROM `' . Db::table('credentials') . '` WHERE `revoked_at` IS NULL AND `disabled_at` IS NULL'),
            'revoked' => $count('SELECT COUNT(*) AS c FROM `' . Db::table('credentials') . '` WHERE `revoked_at` IS NOT NULL'),
            'clients_using' => $count('SELECT COUNT(DISTINCT `user_id`) AS c FROM `' . Db::table('credentials') . '` WHERE `user_type` = ? AND `revoked_at` IS NULL AND `disabled_at` IS NULL', ['client']),
            'admins_using' => $count('SELECT COUNT(DISTINCT `user_id`) AS c FROM `' . Db::table('credentials') . '` WHERE `user_type` = ? AND `revoked_at` IS NULL AND `disabled_at` IS NULL', ['admin']),
            'logins_24h' => $count('SELECT COUNT(*) AS c FROM `' . Db::table('events') . '` WHERE `event_type` = ? AND `success` = 1 AND `created_at` >= ?', ['authentication.succeeded', $day]),
            'failed_24h' => $count('SELECT COUNT(*) AS c FROM `' . Db::table('events') . '` WHERE `event_type` = ? AND `created_at` >= ?', ['authentication.failed', $day]),
            'enforced' => $count('SELECT COUNT(*) AS c FROM `' . Db::table('user_policies') . '` WHERE `policy` = ?', ['required']),
            'by_day' => $byDay,
        ];
    }

    private function validateSettings(array $input)
    {
        $clean = [];
        $enum = function ($key, array $allowed, $fallback) use ($input) {
            $value = isset($input[$key]) ? (string) $input[$key] : $fallback;
            if (!in_array($value, $allowed, true)) {
                throw new \InvalidArgumentException('Invalid value for ' . $key . '.');
            }
            return $value;
        };
        $clean['service_enabled'] = $enum('service_enabled', ['0', '1'], '0');
        $clean['client_policy'] = $enum('client_policy', ['optional', 'required'], 'optional');
        $clean['admin_policy'] = $enum('admin_policy', ['optional', 'required'], 'optional');
        $clean['password_fallback'] = $enum('password_fallback', ['allowed', 'disabled'], 'allowed');
        $clean['max_credentials_client'] = $enum(
            'max_credentials_client',
            ['1', '2', '3', '5', '10', '25', '50', 'unlimited'],
            '5'
        );
        $clean['max_credentials_admin'] = $enum(
            'max_credentials_admin',
            ['1', '2', '3', '5', '10', '25', '50', 'unlimited'],
            '5'
        );
        $clean['user_verification'] = $enum('user_verification', ['required', 'preferred', 'discouraged'], 'preferred');
        $clean['sensitive_action_user_verification'] = $enum('sensitive_action_user_verification', ['required', 'preferred'], 'required');
        $clean['sensitive_action_policy'] = $enum(
            'sensitive_action_policy',
            ['disabled', 'optional', 'required_for_admin', 'required'],
            'optional'
        );
        $clean['login_notifications_enabled'] = $enum('login_notifications_enabled', ['0', '1'], '0');
        $clean['security_event_notifications_enabled'] = $enum('security_event_notifications_enabled', ['0', '1'], '0');
        $clean['entra_enabled'] = $enum('entra_enabled', ['0', '1'], '0');
        $clean['entra_client_login_enabled'] = $enum('entra_client_login_enabled', ['0', '1'], '0');
        $clean['entra_admin_login_enabled'] = $enum('entra_admin_login_enabled', ['0', '1'], '0');

        $rpName = trim(isset($input['rp_name']) ? (string) $input['rp_name'] : '');
        if ($rpName === '' || strlen($rpName) > 64 || preg_match('/[\x00-\x1F\x7F]/', $rpName)) {
            throw new \InvalidArgumentException('RP display name is empty or invalid.');
        }
        $clean['rp_name'] = $rpName;

        $rpId = strtolower(trim(isset($input['rp_id']) ? (string) $input['rp_id'] : ''));
        if ($rpId !== '') {
            $this->assertDomain($rpId);
        }
        $clean['rp_id'] = $rpId;

        $origins = preg_split('/\R/', isset($input['allowed_origins']) ? (string) $input['allowed_origins'] : '');
        $validatedOrigins = [];
        foreach ($origins as $origin) {
            $origin = trim($origin);
            if ($origin === '') {
                continue;
            }
            $validatedOrigins[] = $this->validateOrigin($origin, $rpId);
        }
        if (count($validatedOrigins) !== count(array_unique($validatedOrigins))) {
            throw new \InvalidArgumentException('Duplicate origin in the allowlist.');
        }
        $clean['allowed_origins'] = json_encode(array_values($validatedOrigins), JSON_UNESCAPED_SLASHES);

        foreach (['event_retention_days' => [1, 3650], 'challenge_retention_hours' => [1, 720]] as $key => $range) {
            $value = isset($input[$key]) ? (string) $input[$key] : '';
            if (!preg_match('/^[0-9]+$/', $value) || (int) $value < $range[0] || (int) $value > $range[1]) {
                throw new \InvalidArgumentException('Invalid value for ' . $key . '.');
            }
            $clean[$key] = (string) (int) $value;
        }

        foreach (['entra_tenant_id', 'entra_client_id'] as $key) {
            $value = trim(isset($input[$key]) ? (string) $input[$key] : '');
            if (strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F\s]/', $value)) {
                throw new \InvalidArgumentException('Invalid value for ' . $key . '.');
            }
            $clean[$key] = $value;
        }
        $redirect = trim(isset($input['entra_redirect_uri']) ? (string) $input['entra_redirect_uri'] : '');
        if ($redirect !== '') {
            if (strlen($redirect) > 512 || strpos($redirect, 'https://') !== 0) {
                throw new \InvalidArgumentException('Entra redirect URI must be an https URL.');
            }
        }
        $clean['entra_redirect_uri'] = $redirect;
        $domains = preg_split('/\R/', isset($input['entra_allowed_domains']) ? (string) $input['entra_allowed_domains'] : '');
        $validatedDomains = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '') {
                continue;
            }
            $this->assertDomain($domain);
            $validatedDomains[] = $domain;
        }
        $clean['entra_allowed_domains'] = json_encode(array_values(array_unique($validatedDomains)), JSON_UNESCAPED_SLASHES);
        return $clean;
    }

    private function validateOrigin($origin, $rpId)
    {
        if (strlen($origin) > 512) {
            throw new \InvalidArgumentException('Origin is too long: ' . substr($origin, 0, 60) . '.');
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '')) {
            throw new \InvalidArgumentException('Only canonical https origins without paths are allowed.');
        }
        $host = strtolower($parts['host']);
        $this->assertDomain($host);
        if ($rpId !== '' && $host !== $rpId && substr($host, -strlen('.' . $rpId)) !== '.' . $rpId) {
            throw new \InvalidArgumentException('Origin host must be the RP ID or one of its subdomains.');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535 || $port === 443)) {
            throw new \InvalidArgumentException('Origin port is invalid or non-canonical.');
        }
        $canonical = 'https://' . $host . ($port === null ? '' : ':' . $port);
        if (!hash_equals($canonical, $origin)) {
            throw new \InvalidArgumentException('Origins must use canonical lowercase https serialization.');
        }
        return $canonical;
    }

    private function assertDomain($domain)
    {
        if ($domain === '' || strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Value must be a DNS host name.');
        }
        if ($domain === 'localhost') {
            return;
        }
        foreach (explode('.', $domain) as $part) {
            if (strlen($part) < 1 || strlen($part) > 63
                || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $part)) {
                throw new \InvalidArgumentException('Value contains an invalid DNS label.');
            }
        }
    }

    private function writeSetting($key, $value, $adminId)
    {
        $now = gmdate('Y-m-d H:i:s');
        $existing = Db::firstQuery(
            'SELECT `setting_key` FROM `' . Db::table('settings') . '` WHERE `setting_key` = ?',
            [$key]
        );
        if ($existing) {
            Db::update('settings', ['setting_key' => $key], [
                'setting_value' => $value,
                'updated_by_admin_id' => $adminId,
                'updated_at' => $now,
            ]);
        } else {
            Db::insert('settings', [
                'setting_key' => $key,
                'setting_value' => $value,
                'encrypted_value' => null,
                'is_secret' => 0,
                'updated_by_admin_id' => $adminId,
                'updated_at' => $now,
            ]);
        }
    }

    private function stepUpRequired(array $settings)
    {
        $policy = isset($settings['sensitive_action_policy']) ? $settings['sensitive_action_policy'] : 'optional';
        return in_array($policy, ['required_for_admin', 'required'], true);
    }

    /**
     * Verify step-up confirmation for admin mutations when policy requires it.
     * The admin form carries ticket_id plus the assertion response; absence
     * fails closed with a guidance error instead of applying the change.
     */
    private function requireStepUp(array $settings, WhmcsIdentity $actor, $actionCode, $auditAction)
    {
        if (!$this->stepUpRequired($settings)) {
            return null;
        }
        if (!isset($_POST['ticket_id']) || !array_key_exists('confirmation_response', $_POST)) {
            throw new \RuntimeException('STEP_UP_REQUIRED');
        }
        $response = $_POST['confirmation_response'];
        if (!is_string($response) || $response === '' || strlen($response) > 262144) {
            throw new \RuntimeException('STEP_UP_REQUIRED');
        }
        $provider = new WhmcsNativeIdentityProvider();
        $policy = new PasskeyLoginPolicy($settings);
        $confirmation = new PasskeyActionConfirmationService($provider, new PasskeyPolicyResolver($policy));
        $config = WebAuthnConfig::fromSettings(
            $settings,
            RequestContext::requestOrigin(),
            RequestContext::isHttps()
        );
        return $confirmation->confirm(
            $config,
            new WebAuthnService(),
            $actor,
            $_POST['ticket_id'],
            $response,
            ['ip_address' => RequestContext::ipAddress(), 'user_agent' => RequestContext::userAgent()]
        );
    }

    private function credentialActionForms(array $public, $confirmRequired)
    {
        $base = $this->modulelink . '&ch247pk_tab=credentials';
        if ($public['status'] !== 'revoked') {
            echo '<form method="post" action="' . $this->e($base) . '" style="display:inline">'
                . CsrfProtection::field()
                . '<input type="hidden" name="ch247pk_admin" value="cred_rename">'
                . '<input type="hidden" name="user_type" value="' . $this->e($public['user_type']) . '">'
                . '<input type="hidden" name="user_id" value="' . (int) $public['user_id'] . '">'
                . '<input type="hidden" name="credential_record_id" value="' . (int) $public['id'] . '">'
                . '<input class="form-control input-sm" name="device_name" size="12" value="' . $this->e($public['device_name']) . '" style="display:inline;width:auto"> '
                . '<button class="btn btn-xs btn-default" type="submit">Rename</button></form> ';
        }
        if ($public['status'] === 'revoked') {
            echo '<span class="text-muted">Revoked</span>';
            return;
        }
        $confirm = $confirmRequired
            ? ' data-ch247pk-confirm="' . ($public['status'] === 'disabled' ? 'credential.disable' : 'credential.disable') . '" data-confirm-required="1"'
            : '';
        if ($public['status'] === 'disabled') {
            echo '<form method="post" action="' . $this->e($base) . '" style="display:inline">'
                . CsrfProtection::field()
                . '<input type="hidden" name="ch247pk_admin" value="cred_enable">'
                . '<input type="hidden" name="user_type" value="' . $this->e($public['user_type']) . '">'
                . '<input type="hidden" name="user_id" value="' . (int) $public['user_id'] . '">'
                . '<input type="hidden" name="credential_record_id" value="' . (int) $public['id'] . '">'
                . '<button class="btn btn-xs btn-default" type="submit">Enable</button></form> ';
        } else {
            echo '<form method="post" action="' . $this->e($base) . '" style="display:inline"' . $confirm . '>'
                . CsrfProtection::field()
                . '<input type="hidden" name="ch247pk_admin" value="cred_disable">'
                . '<input type="hidden" name="user_type" value="' . $this->e($public['user_type']) . '">'
                . '<input type="hidden" name="user_id" value="' . (int) $public['user_id'] . '">'
                . '<input type="hidden" name="credential_record_id" value="' . (int) $public['id'] . '">'
                . '<button class="btn btn-xs btn-default" type="submit">Disable</button></form> ';
        }
        echo '<form method="post" action="' . $this->e($base) . '" style="display:inline"'
            . ($confirmRequired ? ' data-ch247pk-confirm="credential.revoke" data-confirm-required="1"' : '') . '>'
            . CsrfProtection::field()
            . '<input type="hidden" name="ch247pk_admin" value="cred_revoke">'
            . '<input type="hidden" name="user_type" value="' . $this->e($public['user_type']) . '">'
            . '<input type="hidden" name="user_id" value="' . (int) $public['user_id'] . '">'
            . '<input type="hidden" name="credential_record_id" value="' . (int) $public['id'] . '">'
            . '<button class="btn btn-xs btn-danger" type="submit" onclick="return confirm(\'Revoke this Passkey?\')">Revoke</button></form>';
        $this->renderConfirmScript();
    }

    private function renderConfirmScript()
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;
        echo '<script>window.CH247PK_ADMIN = window.CH247PK_ADMIN || {ajaxUrl: '
            . json_encode($this->modulelink . '&passkey_ajax=1')
            . ', csrfToken: ' . json_encode($this->safeToken())
            . ', csrfField: ' . json_encode(CsrfProtection::FIELD) . '};</script>';
    }

    private function pageUrl($page)
    {
        $params = $_GET;
        $params['p'] = $page;
        return 'addonmodules.php?' . http_build_query($params);
    }

    private function field($label, $control)
    {
        echo '<div class="form-group" style="max-width:640px"><label>' . $this->e($label) . '</label>' . $control . '</div>';
    }

    private function options(array $options, $selected)
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"'
                . ((string) $value === (string) $selected ? ' selected' : '') . '>'
                . $this->e($label) . '</option>';
        }
        return $html;
    }

    private function requireSuper($super)
    {
        if (!$super) {
            throw new \RuntimeException('FORBIDDEN');
        }
    }

    /** Honest service-status banner; clients and staff keep password login while disabled. */
    private function renderServiceBanner()
    {
        try {
            $row = Db::firstQuery(
                'SELECT `setting_value` FROM `' . Db::table('settings') . '` WHERE `setting_key` = ?',
                ['service_enabled']
            );
        } catch (\Throwable $error) {
            return;
        }
        if (!$row || !isset($row['setting_value']) || (string) $row['setting_value'] !== '1') {
            echo '<div class="alert alert-warning"><strong>Passkey authentication is currently disabled.</strong> '
                . 'Clients and administrators keep using their existing password login and 2FA until this service is enabled.</div>';
        }
    }

    public function isSuperAdmin($adminId)
    {
        try {
            if (!class_exists('WHMCS\\Database\\Capsule')) {
                return false;
            }
            $row = \WHMCS\Database\Capsule::table('tbladmins')->where('id', (int) $adminId)->first();
            if ($row === null) {
                return false;
            }
            $row = (array) $row;
            return isset($row['roleid']) && (int) $row['roleid'] === 1 && (int) $row['disabled'] === 0;
        } catch (\Throwable $error) {
            return false;
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

    private function logActivity($message)
    {
        if (function_exists('logActivity')) {
            try {
                logActivity($message);
            } catch (\Throwable $error) {
                // Admin audit logging is best-effort.
            }
        }
    }

    private function publicAdminError(\Throwable $error)
    {
        $message = $error->getMessage();
        if ($message === 'FORBIDDEN') {
            return 'This action requires a Super Administrator.';
        }
        if ($message === 'STEP_UP_REQUIRED') {
            return 'Confirm with your Passkey to apply this change (sensitive-action policy requires step-up confirmation).';
        }
        if ($message === 'SERVICE_UNAVAILABLE') {
            return 'A required WHMCS service is unavailable (SERVICE_UNAVAILABLE). No change was applied.';
        }
        if ($message === 'CSRF_FAILED' || strpos($message, 'Security token') !== false) {
            return 'Security validation failed. Please reload and try again.';
        }
        if ($error instanceof \InvalidArgumentException) {
            return $message;
        }
        if (strpos($message, 'confirmation ticket') !== false || strpos($message, 'ceremony') !== false) {
            return 'Passkey confirmation failed or expired. Please try again; no change was applied.';
        }
        if (strpos($message, 'not authorized') !== false) {
            return 'You are not authorized for this policy operation.';
        }
        if (strpos($message, 'missing, disabled') !== false || strpos($message, 'missing or disabled') !== false) {
            return 'The target identity is missing or disabled.';
        }
        return 'This action could not be completed. No change was applied.';
    }

    private function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
