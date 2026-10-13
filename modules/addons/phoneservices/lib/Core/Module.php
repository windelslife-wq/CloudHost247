<?php
/**
 * Core Module Class
 * Handles activation, database management, rendering, and lifecycle
 */

namespace PhoneServices\Core;

use PhoneServices\Providers\ProviderFactory;
use PhoneServices\Services\NumberService;
use PhoneServices\Services\VoipService;
use PhoneServices\Services\SmsService;
use PhoneServices\Services\EsimService;
use PhoneServices\Services\UsageService;
use PhoneServices\Services\PricingService;

class Module
{
    private $config;
    private $db;
    
    public function __construct()
    {
        $this->config = new Config();
        $this->db = new Database();
    }
    
    /**
     * Activate module - create database tables
     */
    public function activate()
    {
        $schemaFile = __DIR__ . '/../../install/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    full_query($statement);
                }
            }
        }

        // Per-client API keys table (P-5)
        ApiKeyStore::ensureTable();

        // Insert default settings
        $this->insertDefaultSettings();
    }
    
    /**
     * Deactivate module
     */
    public function deactivate()
    {
        // Do not drop tables to preserve data
        // Just disable scheduled tasks if any
    }
    
    /**
     * Upgrade module
     */
    public function upgrade($currentVersion)
    {
        $migrationsDir = __DIR__ . '/../../install/migrations/';
        if (is_dir($migrationsDir)) {
            $migrations = glob($migrationsDir . '*.sql');
            sort($migrations);
            foreach ($migrations as $migration) {
                $version = basename($migration, '.sql');
                if (version_compare($version, $currentVersion, '>')) {
                    $sql = file_get_contents($migration);
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    foreach ($statements as $statement) {
                        if (!empty($statement)) {
                            full_query($statement);
                        }
                    }
                }
            }
        }

        // Per-client API keys table (P-5) — idempotent, also covers fresh
        // upgrades that run before the migration set above.
        ApiKeyStore::ensureTable();
    }
    
    /**
     * Insert default settings on activation
     */
    private function insertDefaultSettings()
    {
        $defaults = [
            ['setting_name' => 'default_provider', 'setting_value' => 'twilio'],
            ['setting_name' => 'api_mode', 'setting_value' => 'sandbox'],
            ['setting_name' => 'enable_numbers', 'setting_value' => '1'],
            ['setting_name' => 'enable_voip', 'setting_value' => '1'],
            ['setting_name' => 'enable_sms', 'setting_value' => '1'],
            ['setting_name' => 'enable_esim', 'setting_value' => '1'],
            ['setting_name' => 'log_retention_days', 'setting_value' => '90'],
        ];
        
        foreach ($defaults as $setting) {
            $exists = \WHMCS\Database\Capsule::table('mod_phoneservices_settings')
                ->where('setting_name', $setting['setting_name'])
                ->exists();
            if (!$exists) {
                \WHMCS\Database\Capsule::table('mod_phoneservices_settings')->insert($setting);
            }
        }
    }
    
    // ==================== ADMIN RENDERERS ====================
    
    public function renderAdminDashboard($vars)
    {
        $usageService = new UsageService();
        $stats = $usageService->getSystemStats();
        
        $template = __DIR__ . '/../../templates/admin/dashboard.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'stats' => $stats]);
            include $template;
        }
    }
    
    public function renderApiConfig($vars)
    {
        $config = new Config();
        $providers = ProviderFactory::getAvailableProviders();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleApiConfigPost($vars, $_POST);
        }
        
        $template = __DIR__ . '/../../templates/admin/api_config.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'providers' => $providers, 'config' => $config]);
            include $template;
        }
    }
    
    private function handleApiConfigPost($vars, $post)
    {
        $allowedFields = [
            'api_mode', 'default_provider', 'twilio_account_sid', 'twilio_auth_token',
            'vonage_api_key', 'vonage_api_secret', 'vonage_signature_secret',
            'airalo_api_token',
            'truphone_api_key', 'sendgrid_api_key', 'whatsapp_business_token'
        ];

        $config = new Config();
        foreach ($allowedFields as $field) {
            // Password fields: a blank submission keeps the stored credential
            // (the admin UI does not echo secrets back, so blank == unchanged).
            if ($config->isSensitiveKey($field)) {
                if (isset($post[$field]) && $post[$field] !== '') {
                    $config->set($field, $post[$field]);
                }
                continue;
            }
            if (isset($post[$field])) {
                $config->set($field, $post[$field]);
            }
        }

        // Update feature toggles
        $toggles = ['enable_numbers', 'enable_voip', 'enable_sms', 'enable_esim'];
        foreach ($toggles as $toggle) {
            $config->set($toggle, isset($post[$toggle]) ? '1' : '0');
        }

        header('Location: ' . $vars['modulelink'] . '&action=api_config&success=1');
        exit;
    }

    /**
     * Admin UI: manage per-client API keys (finding P-5). The shared
     * all-tenant key is retired; keys are issued per client and scope every
     * REST request to that client.
     */
    public function renderApiKeys($vars)
    {
        ApiKeyStore::ensureTable();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleApiKeyPost($vars, $_POST);
        }

        $clientid = (int) ($_GET['clientid'] ?? 0);
        $keys = $clientid > 0 ? ApiKeyStore::listFor($clientid) : [];

        $clients = \WHMCS\Database\Capsule::table('tblclients')
            ->select('id', 'firstname', 'lastname', 'email')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($row) {
                return (array) $row;
            })
            ->all();

        $newKey = isset($_SESSION['ps_new_key']) ? $_SESSION['ps_new_key'] : null;
        unset($_SESSION['ps_new_key']);

        $flashSuccess = isset($_SESSION['ps_flash_success']) ? $_SESSION['ps_flash_success'] : null;
        unset($_SESSION['ps_flash_success']);
        $flashError = isset($_SESSION['ps_flash_error']) ? $_SESSION['ps_flash_error'] : null;
        unset($_SESSION['ps_flash_error']);

        $template = __DIR__ . '/../../templates/admin/api_keys.tpl';
        if (file_exists($template)) {
            extract([
                'vars' => $vars,
                'keys' => $keys,
                'clients' => $clients,
                'clientid' => $clientid,
                'clientLabel' => $clientid > 0 ? ApiKeyStore::clientLabel($clientid) : '',
                'newKey' => $newKey,
                'flashSuccess' => $flashSuccess,
                'flashError' => $flashError,
            ]);
            include $template;
        }
    }

    private function handleApiKeyPost($vars, $post)
    {
        $action = (string) ($post['key_action'] ?? '');
        $clientid = (int) ($post['clientid'] ?? 0);

        if ($action === 'create') {
            if ($clientid <= 0) {
                $_SESSION['ps_flash_error'] = 'Select the client to issue the key for.';
            } else {
                $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $clientid)->first();
                if ($client === null) {
                    $_SESSION['ps_flash_error'] = 'Client not found.';
                } else {
                    $result = ApiKeyStore::create($clientid, trim((string) ($post['name'] ?? '')));
                    // Shown to the admin exactly once, in-session (never in a URL).
                    $_SESSION['ps_new_key'] = ['key' => $result['key'], 'client' => $clientid];
                    Logger::info('Admin issued per-client API key', ['client' => $clientid, 'key_id' => $result['id']]);
                }
            }
        } elseif ($action === 'revoke') {
            $keyId = (int) ($post['key_id'] ?? 0);
            if ($keyId > 0 && ApiKeyStore::revoke($keyId)) {
                $_SESSION['ps_flash_success'] = 'API key revoked.';
                Logger::info('Admin revoked per-client API key', ['key_id' => $keyId]);
            } else {
                $_SESSION['ps_flash_error'] = 'API key not found or already revoked.';
            }
        }

        $redirect = $vars['modulelink'] . '&action=api_keys';
        if ($clientid > 0) {
            $redirect .= '&clientid=' . $clientid;
        }
        header('Location: ' . $redirect);
        exit;
    }
    
    public function renderPricing($vars)
    {
        $pricingService = new PricingService();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $pricingService->updatePricing($_POST);
            header('Location: ' . $vars['modulelink'] . '&action=pricing&success=1');
            exit;
        }
        
        $pricing = $pricingService->getAllPricing();
        $template = __DIR__ . '/../../templates/admin/pricing.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'pricing' => $pricing]);
            include $template;
        }
    }
    
    public function renderNumbersAdmin($vars)
    {
        $numberService = new NumberService();
        $numbers = $numberService->getAllNumbers();
        $template = __DIR__ . '/../../templates/admin/numbers.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'numbers' => $numbers]);
            include $template;
        }
    }
    
    public function renderVoipAdmin($vars)
    {
        $voipService = new VoipService();
        $calls = $voipService->getAllCallLogs();
        $template = __DIR__ . '/../../templates/admin/voip.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'calls' => $calls]);
            include $template;
        }
    }
    
    public function renderSmsAdmin($vars)
    {
        $smsService = new SmsService();
        $messages = $smsService->getAllMessages();
        $template = __DIR__ . '/../../templates/admin/sms.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'messages' => $messages]);
            include $template;
        }
    }
    
    public function renderEsimAdmin($vars)
    {
        $esimService = new EsimService();
        $esims = $esimService->getAllProfiles();
        $template = __DIR__ . '/../../templates/admin/esim.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'esims' => $esims]);
            include $template;
        }
    }
    
    public function renderUsageAdmin($vars)
    {
        $usageService = new UsageService();
        $reports = $usageService->getSystemUsageReport();
        $template = __DIR__ . '/../../templates/admin/usage.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'reports' => $reports]);
            include $template;
        }
    }
    
    public function renderTransactionsAdmin($vars)
    {
        $usageService = new UsageService();
        $transactions = $usageService->getAllTransactions();
        $template = __DIR__ . '/../../templates/admin/transactions.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'transactions' => $transactions]);
            include $template;
        }
    }
    
    public function renderUsersAdmin($vars)
    {
        $users = \WHMCS\Database\Capsule::table('tblclients')
            ->select('id', 'firstname', 'lastname', 'email', 'status')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($row) {
                return (array) $row;
            })
            ->all();
        $template = __DIR__ . '/../../templates/admin/users.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'users' => $users]);
            include $template;
        }
    }
    
    public function renderLogsAdmin($vars)
    {
        $logs = Logger::getRecentLogs(100);
        $template = __DIR__ . '/../../templates/admin/logs.tpl';
        if (file_exists($template)) {
            extract(['vars' => $vars, 'logs' => $logs]);
            include $template;
        }
    }
    
    // ==================== CLIENT AREA ====================
    
    public function renderClientArea($vars, $action)
    {
        $userId = isset($_SESSION['uid']) ? $_SESSION['uid'] : 0;
        if (!$userId && $action !== 'dashboard') {
            $action = 'dashboard';
        }
        
        $data = [];
        $data['vars'] = $vars;
        $data['action'] = $action;
        
        switch ($action) {
            case 'numbers':
                $service = new NumberService();
                $data['numbers'] = $service->getUserNumbers($userId);
                $data['availableCountries'] = $service->getAvailableCountries();
                break;
            case 'voip':
                $service = new VoipService();
                $data['calls'] = $service->getUserCallLogs($userId);
                $data['webRtcConfig'] = $service->getWebRtcConfig($userId);
                break;
            case 'sms':
                $service = new SmsService();
                $data['messages'] = $service->getUserMessages($userId);
                break;
            case 'esim':
                $service = new EsimService();
                $data['esims'] = $service->getUserProfiles($userId);
                $data['plans'] = $service->getAvailablePlans();
                break;
            case 'usage':
                $service = new UsageService();
                $data['usage'] = $service->getUserUsage($userId);
                $data['transactions'] = $service->getUserTransactions($userId);
                break;
            case 'dashboard':
            default:
                $usageService = new UsageService();
                $numberService = new NumberService();
                $data['stats'] = [
                    'numbers' => $numberService->countUserNumbers($userId),
                    'calls' => $usageService->countUserCalls($userId),
                    'sms' => $usageService->countUserSms($userId),
                    'esims' => $usageService->countUserEsims($userId),
                ];
                break;
        }
        
        $template = __DIR__ . '/../../templates/client/' . $action . '.tpl';
        $output = '';
        if (file_exists($template)) {
            extract($data);
            ob_start();
            include $template;
            $output = ob_get_clean();
        } else {
            $output = '<div class="alert alert-danger">Template not found: ' . htmlspecialchars($action) . '</div>';
        }
        
        return [
            'pagetitle' => 'Phone Services',
            'breadcrumb' => [
                'index.php?m=phoneservices' => 'Phone Services',
            ],
            'templatefile' => 'clientarea',
            'requirelogin' => true,
            'forcessl' => false,
            'vars' => [
                'content' => $output,
                'action' => $action,
                'modulelink' => $vars['modulelink'],
            ],
        ];
    }
}
