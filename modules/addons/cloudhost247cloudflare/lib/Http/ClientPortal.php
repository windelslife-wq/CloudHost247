<?php
namespace CloudHost247\Cloudflare\Http;

use CloudHost247\Cloudflare\Core\Audit;
use CloudHost247\Cloudflare\Core\AuthorizationException;
use CloudHost247\Cloudflare\Core\CloudflareException;
use CloudHost247\Cloudflare\Core\Csrf;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\Identity;
use CloudHost247\Cloudflare\Core\Logger;
use CloudHost247\Cloudflare\Core\RateLimiter;
use CloudHost247\Cloudflare\Core\ValidationException;
use CloudHost247\Cloudflare\Repository\ServiceRepository;
use CloudHost247\Cloudflare\Service\DnsRecordService;
use CloudHost247\Cloudflare\Service\DnsRecordValidator;
use CloudHost247\Cloudflare\Service\Features;
use CloudHost247\Cloudflare\Service\IntegrationStatus;
use CloudHost247\Cloudflare\Service\ProvisioningService;
use CloudHost247\Cloudflare\Service\ZoneManagementService;

class ClientPortal
{
    private $success = ''; private $error = ''; private $currentServiceId = 0; private $currentAction = '';
    public function dispatch($vars)
    {
        $clientId = Identity::clientId();
        if (!$clientId) return $this->page('Sign in required', ['login_required' => true, 'modulelink' => 'index.php?m=cloudhost247cloudflare']);
        if (!IntegrationStatus::enabled()) return $this->page('Cloudflare services', ['unavailable' => true, 'message' => 'Cloudflare service management is temporarily unavailable. Contact support.']);
        $action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? 'services'));
        $serviceId = max(0, (int) ($_GET['id'] ?? 0));
        $tab = preg_replace('/[^a-z_]/', '', (string) ($_GET['tab'] ?? 'overview'));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                Csrf::verifyRequest();
                $serviceId = max(0, (int) ($_POST['service_id'] ?? 0));
                $this->currentServiceId = $serviceId;
                $this->currentAction = (string) ($_POST['ch247cf_action'] ?? '');
                $service = (new ServiceRepository())->forCustomer($serviceId, $clientId);
                $this->post($service, $clientId);
                $tab = preg_replace('/[^a-z_]/', '', (string) ($_POST['tab'] ?? 'overview'));
                $this->redirect('service', $serviceId, $tab);
            } catch (\Throwable $e) {
                $this->error = $e instanceof CloudflareException ? $e->getMessage() : 'This Cloudflare action could not be completed. Reload the page and try again.';
                if ($serviceId > 0) Audit::record('client', $clientId, $this->auditAction($this->currentAction), 'cloudflare_service', $serviceId, $clientId, $serviceId, false, [], $e instanceof CloudflareException ? $e->errorCode() : 'INTERNAL_ERROR');
                if (!$e instanceof CloudflareException) Logger::error('client operation failed', ['service_id' => $serviceId, 'action' => $this->currentAction, 'error' => get_class($e)]);
                $action = 'service'; $tab = preg_replace('/[^a-z_]/', '', (string) ($_POST['tab'] ?? 'overview'));
            }
        }
        if ($action !== 'service' || $serviceId <= 0) return $this->serviceList($clientId);
        try { $service = (new ServiceRepository())->forCustomer($serviceId, $clientId); }
        catch (\Throwable $e) { return $this->page('Cloudflare service not found', ['unavailable' => true, 'message' => 'This Cloudflare service could not be found in your account.']); }
        return $this->servicePage($service, $clientId, $tab);
    }

    private function post(array $service, $clientId)
    {
        RateLimiter::consume($clientId, 'cloudflare_write', 30, 60);
        $action = $this->currentAction; $actorType = 'client'; $actorId = (int) $clientId;
        $dns = new DnsRecordService(); $manage = new ZoneManagementService();
        switch ($action) {
            case 'dns_create':
                $dns->create($service, $_POST, $actorType, $actorId); $this->success = 'DNS record created.'; break;
            case 'dns_update':
                $dns->update($service, (string) ($_POST['record_id'] ?? ''), $_POST, $actorType, $actorId); $this->success = 'DNS record updated.'; break;
            case 'dns_delete':
                if (($_POST['confirm_delete'] ?? '') !== '1') throw new ValidationException('Confirm DNS record deletion before continuing.');
                $dns->delete($service, (string) ($_POST['record_id'] ?? ''), $actorType, $actorId); $this->success = 'DNS record deleted.'; break;
            case 'dnssec_change':
                $enabled = (string) ($_POST['enabled'] ?? '') === '1';
                $manage->setDnssec($service, $enabled, $actorType, $actorId); $this->success = $enabled ? 'DNSSEC enabled at Cloudflare.' : 'DNSSEC disabled at Cloudflare.'; break;
            case 'ssl_setting':
                $manage->saveSslSetting($service, (string) ($_POST['setting'] ?? ''), $_POST['value'] ?? '', $actorType, $actorId); $this->success = 'SSL/TLS setting updated.'; break;
            case 'firewall_setting':
                $manage->saveFirewallSetting($service, (string) ($_POST['setting'] ?? ''), $_POST['value'] ?? '', $actorType, $actorId); $this->success = 'Firewall setting updated.'; break;
            case 'firewall_add':
                $manage->createFirewallRule($service, $_POST, $actorType, $actorId); $this->success = 'Firewall rule added.'; break;
            case 'firewall_delete':
                if (($_POST['confirm_delete'] ?? '') !== '1') throw new ValidationException('Confirm firewall rule deletion before continuing.');
                $manage->deleteFirewallRule($service, (string) ($_POST['rule_id'] ?? ''), $actorType, $actorId); $this->success = 'Firewall rule deleted.'; break;
            case 'speed_setting':
                $setting = (string) ($_POST['setting'] ?? '');
                $value = $setting === 'minify' ? (array) ($_POST['minify'] ?? []) : ($_POST['value'] ?? '');
                $manage->saveSpeedSetting($service, $setting, $value, $actorType, $actorId); $this->success = 'Speed setting updated.'; break;
            case 'cache_setting':
                $manage->saveCacheSetting($service, (string) ($_POST['setting'] ?? ''), $_POST['value'] ?? '', $actorType, $actorId); $this->success = 'Caching setting updated.'; break;
            case 'cache_purge':
                $mode = (string) ($_POST['purge_mode'] ?? 'urls');
                if ($mode === 'everything') {
                    if (($_POST['confirm_purge'] ?? '') !== '1') throw new ValidationException('Confirm Purge Everything before continuing.');
                    $manage->purgeCache($service, null, $actorType, $actorId);
                } else {
                    $urls = preg_split('/\r\n|\r|\n/', (string) ($_POST['urls'] ?? ''));
                    $urls = array_values(array_filter(array_map('trim', $urls), function ($url) { return $url !== ''; }));
                    $manage->purgeCache($service, $urls, $actorType, $actorId);
                }
                $this->success = 'Cloudflare cache purge request accepted.'; break;
            case 'scrape_setting':
                $manage->saveScrapeShieldSetting($service, (string) ($_POST['setting'] ?? ''), $_POST['value'] ?? '', $actorType, $actorId); $this->success = 'Content protection setting updated.'; break;
            default: throw new ValidationException('Unsupported Cloudflare action.');
        }
    }

    private function serviceList($clientId)
    {
        $rows = (new ServiceRepository())->listForCustomer($clientId);
        return $this->page('My Cloudflare services', [
            'services' => $rows, 'modulelink' => 'index.php?m=cloudhost247cloudflare',
            'csrf_field' => Csrf::field(), 'success' => $this->success, 'error' => $this->error,
            'unavailable' => false, 'list_view' => true,
        ]);
    }
    private function servicePage(array $service, $clientId, $tab)
    {
        $features = Features::normalize($service['features_json'] ?? []);
        $tabs = $this->tabs($features);
        $allowed = array_column($tabs, 'key');
        if (!in_array($tab, $allowed, true)) $tab = 'overview';
        $data = [
            'service' => $service, 'features' => $features, 'tabs' => $tabs, 'active_tab' => $tab,
            'modulelink' => 'index.php?m=cloudhost247cloudflare', 'csrf_field' => Csrf::field(),
            'success' => $this->success, 'error' => $this->error, 'unavailable' => false,
            'nameservers' => json_decode((string) ($service['nameservers_json'] ?? '[]'), true) ?: [],
            'records' => [], 'dnssec' => null, 'analytics' => null, 'settings' => [], 'firewall_rules' => [],
            'activity' => (new ServiceRepository())->recentActivity($clientId, (int) $service['id'], 50),
            'dns_types' => DnsRecordValidator::TYPES, 'selected_record' => null,
            'service_manageable' => in_array(strtoupper((string) $service['status']), ['ACTIVE','PENDING_NAMESERVER_UPDATE'], true)
                && strtolower((string) ($service['whmcs_service_status'] ?? $service['whmcs_addon_status'] ?? 'active')) === 'active'
                && strtolower((string) ($service['addon_parent_status'] ?? 'active')) === 'active',
            'plan_upgrade_url' => !empty($service['whmcs_service_id']) ? 'upgrade.php?type=package&id=' . (int) $service['whmcs_service_id'] : '',
        ];
        if (!$data['service_manageable']) $data['notice'] = 'This service is not currently active. Cloudflare changes are disabled until the linked WHMCS service is active.';
        try {
            if ($tab === 'dns' && $data['service_manageable'] && !empty($features['dns.manage'])) {
                $data['records'] = (new DnsRecordService())->localRecords((int) $service['id']);
                if (!empty($_GET['refresh']) || !$service['last_synced_at'] || strtotime($service['last_synced_at']) < time() - 60) $data['records'] = (new DnsRecordService())->listForService($service);
                $recordId = (string) ($_GET['record_id'] ?? '');
                if ($recordId !== '') $data['selected_record'] = Db::first('dns_records', ['service_id' => (int) $service['id'], 'cloudflare_record_id' => $recordId]);
            } elseif ($tab === 'dnssec' && $data['service_manageable']) {
                $data['dnssec'] = (new ZoneManagementService())->dnssec($service);
            } elseif ($tab === 'analytics' && $data['service_manageable']) {
                $data['analytics'] = (new ZoneManagementService())->analytics($service, (string) ($_GET['range'] ?? '24h'), (string) ($_GET['since'] ?? ''), (string) ($_GET['until'] ?? ''));
            } elseif ($tab === 'ssl' && $data['service_manageable']) {
                $data['settings'] = (new ZoneManagementService())->sslSettings($service);
            } elseif ($tab === 'firewall' && $data['service_manageable']) {
                $manage = new ZoneManagementService(); $data['settings'] = $manage->firewallSettings($service); $data['firewall_rules'] = $manage->firewallRules($service);
            } elseif ($tab === 'speed' && $data['service_manageable']) {
                $data['settings'] = (new ZoneManagementService())->speedSettings($service);
            } elseif ($tab === 'caching' && $data['service_manageable']) {
                $data['settings'] = (new ZoneManagementService())->cacheSettings($service);
            } elseif ($tab === 'security' && $data['service_manageable']) {
                $data['settings'] = (new ZoneManagementService())->scrapeShieldSettings($service);
            } elseif ($tab === 'plan' && !empty($service['zone_id'])) {
                $data['provider_plan'] = (new ZoneManagementService())->plan($service);
            }
        } catch (\Throwable $e) {
            $data['section_error'] = $e instanceof CloudflareException ? $e->getMessage() : 'This Cloudflare data is temporarily unavailable.';
            if (!$e instanceof CloudflareException) Logger::error('client page read failed', ['service_id' => (int) $service['id'], 'tab' => $tab, 'error' => get_class($e)]);
        }
        $data['setting_rows'] = $this->settingRows($data['settings']);
        return $this->page('Cloudflare ' . $tab, $data);
    }
    private function settingRows(array $settings)
    {
        $labels = ['ssl'=>'SSL mode','min_tls_version'=>'Minimum TLS version','tls_1_3'=>'TLS 1.3','always_use_https'=>'Always use HTTPS','automatic_https_rewrites'=>'Automatic HTTPS Rewrites','opportunistic_encryption'=>'Opportunistic Encryption','security_level'=>'Security level','browser_check'=>'Browser integrity check','challenge_ttl'=>'Challenge passage','minify'=>'Auto Minify','rocket_loader'=>'Rocket Loader','brotli'=>'Brotli','http2'=>'HTTP/2','http3'=>'HTTP/3','ip_geolocation'=>'IP Geolocation','early_hints'=>'Early Hints','cache_level'=>'Cache level','browser_cache_ttl'=>'Browser cache TTL','development_mode'=>'Development mode','email_obfuscation'=>'Email address obfuscation','server_side_exclude'=>'Server-side excludes','hotlink_protection'=>'Hotlink protection'];
        $rows = [];
        foreach ($settings as $key => $value) {
            $unavailable = is_array($value) && isset($value['status']) && $value['status'] === 'DATA_UNAVAILABLE';
            $row = ['key'=>$key, 'label'=>$labels[$key] ?? $key, 'unavailable'=>$unavailable, 'value'=>'', 'css'=>false, 'html'=>false, 'js'=>false];
            if (!$unavailable && is_array($value) && $key === 'minify') { $row['css'] = !empty($value['css']); $row['html'] = !empty($value['html']); $row['js'] = !empty($value['js']); $row['value'] = json_encode($value); }
            elseif (!$unavailable && (is_scalar($value) || $value === null)) $row['value'] = (string) $value;
            elseif (!$unavailable) $row['value'] = 'DATA_UNAVAILABLE';
            $rows[] = $row;
        }
        return $rows;
    }
    private function tabs(array $features)
    {
        $tabs = [['key'=>'overview','label'=>'Overview']];
        $possible = [
            'dns' => 'dns.manage', 'dnssec' => 'dnssec.manage', 'analytics' => 'analytics.read',
            'ssl' => 'ssl.manage', 'firewall' => 'firewall.manage', 'speed' => 'speed.manage',
            'caching' => 'cache.manage', 'security' => 'scrape_shield.manage',
        ];
        $labels = ['dns'=>'DNS','dnssec'=>'DNSSEC','analytics'=>'Analytics','ssl'=>'SSL / TLS','firewall'=>'Firewall','speed'=>'Speed','caching'=>'Caching','security'=>'Content protection'];
        foreach ($possible as $key => $feature) if (!empty($features[$feature]) || ($key === 'caching' && !empty($features['cache.purge'])) || ($key === 'security' && (!empty($features['email_obfuscation.manage']) || !empty($features['server_side_excludes.manage']) || !empty($features['hotlink_protection.manage'])))) $tabs[] = ['key'=>$key,'label'=>$labels[$key]];
        $tabs[] = ['key'=>'plan','label'=>'Plan']; $tabs[] = ['key'=>'activity','label'=>'Activity'];
        return $tabs;
    }
    private function page($title, array $vars)
    {
        $vars['csrf_field'] = $vars['csrf_field'] ?? Csrf::field();
        $vars['cloudflare_title'] = $title;
        return ['pagetitle' => $title, 'breadcrumb' => ['index.php?m=cloudhost247cloudflare' => 'Cloudflare'], 'templatefile' => 'templates/client/index', 'templatevariables' => $vars, 'requirelogin' => true];
    }
    private function redirect($action, $serviceId = 0, $tab = 'overview')
    {
        $url = 'index.php?m=cloudhost247cloudflare';
        if ($action !== 'services') $url .= '&action=' . rawurlencode($action);
        if ($serviceId > 0) $url .= '&id=' . (int) $serviceId . '&tab=' . rawurlencode($tab ?: 'overview');
        if (!headers_sent()) { header('Location: ' . $url, true, 303); exit; }
    }
    private function auditAction($action)
    {
        $map = ['dns_create'=>'DNS_RECORD_CREATED','dns_update'=>'DNS_RECORD_UPDATED','dns_delete'=>'DNS_RECORD_DELETED','dnssec_change'=>'DNSSEC_UPDATED','cache_purge'=>'CACHE_PURGE_REQUESTED','firewall_add'=>'FIREWALL_RULE_CREATED'];
        return $map[$action] ?? strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $action ?: 'CLOUDFLARE_ACTION'));
    }
}
