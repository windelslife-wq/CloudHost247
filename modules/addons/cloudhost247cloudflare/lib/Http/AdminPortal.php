<?php
namespace CloudHost247\Cloudflare\Http;

use CloudHost247\Cloudflare\Core\Audit;
use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\Csrf;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\Identity;
use CloudHost247\Cloudflare\Core\CloudflareException;
use CloudHost247\Cloudflare\Core\Logger;
use CloudHost247\Cloudflare\Core\ValidationException;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\PackageRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;
use CloudHost247\Cloudflare\Service\Features;
use CloudHost247\Cloudflare\Service\ProvisioningService;
use CloudHost247\Cloudflare\Service\ZoneManagementService;

class AdminPortal
{
    private $vars; private $link; private $action; private $error = ''; private $success = '';
    public function __construct(array $vars) { $this->vars = $vars; $this->link = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=cloudhost247cloudflare'); $this->action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? 'dashboard')); }
    public function render()
    {
        try { Identity::requireAdmin(); }
        catch (\Throwable $e) { return '<div class="alert alert-danger">' . self::h($e->getMessage()) . '</div>'; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try { Csrf::verifyRequest(); $this->post(Identity::requireAdmin()); }
            catch (\Throwable $e) {
                $this->error = $e instanceof CloudflareException ? $e->getMessage() : 'The requested Cloudflare action failed. Check API and audit logs.';
                Logger::error('admin action failed', ['action' => (string) ($_POST['ch247cf_action'] ?? ''), 'error' => get_class($e)]);
            }
        }
        $pages = ['dashboard'=>'Dashboard','accounts'=>'Integrations / Accounts','packages'=>'Product mappings','services'=>'Services','zones'=>'Zones','logs'=>'API & audit logs'];
        if (!isset($pages[$this->action])) $this->action = 'dashboard';
        $content = '';
        try {
            switch ($this->action) {
                case 'accounts': $content = $this->accountsPage(); break;
                case 'packages': $content = $this->packagesPage(); break;
                case 'services': $content = $this->servicesPage(); break;
                case 'zones': $content = $this->zonesPage(); break;
                case 'logs': $content = $this->logsPage(); break;
                default: $content = $this->dashboardPage();
            }
        } catch (\Throwable $e) {
            $content = '<div class="alert alert-danger">Unable to load this section. Check that the Cloudflare addon is activated.</div>';
            Logger::error('admin render failed', ['page' => $this->action, 'error' => get_class($e)]);
        }
        $html = '<div class="ch247cf-admin"><h2><i class="fa fa-cloud"></i> Cloudflare Reseller &amp; Management</h2>';
        if ($this->success !== '') $html .= '<div class="alert alert-success">' . self::h($this->success) . '</div>';
        if ($this->error !== '') $html .= '<div class="alert alert-danger">' . self::h($this->error) . '</div>';
        $html .= '<ul class="nav nav-tabs" style="margin:15px 0 20px">';
        foreach ($pages as $key => $label) $html .= '<li' . ($this->action === $key ? ' class="active"' : '') . '><a href="' . self::h($this->url($key)) . '">' . self::h($label) . '</a></li>';
        $html .= '</ul>' . $content . '</div>';
        return $html;
    }
    private function post($adminId)
    {
        $action = (string) ($_POST['ch247cf_action'] ?? ''); $accounts = new AccountRepository(); $packages = new PackageRepository(); $services = new ProvisioningService();
        switch ($action) {
            case 'save_account':
                $id = $accounts->save($_POST); Audit::record('admin', $adminId, 'CLOUDFLARE_ACCOUNT_SAVED', 'account', $id, null, null, true, ['label' => trim((string) ($_POST['label'] ?? ''))]);
                $this->success = 'Cloudflare account saved. The API token is encrypted and will not be shown again.'; $this->action = 'accounts'; break;
            case 'test_account':
                $test = $accounts->test((int) ($_POST['id'] ?? 0));
                Audit::record('admin', $adminId, 'CLOUDFLARE_CONNECTION_TESTED', 'account', (int) ($_POST['id'] ?? 0), null, null, true, ['response_time_ms' => $test['response_time_ms']]);
                $this->success = 'Connected to ' . ($test['account_name'] ?: $test['account_id']) . ' (' . $test['response_time_ms'] . ' ms). Account:Read and Zone:Read were verified. Other token permissions were not tested.'; $this->action = 'accounts'; break;
            case 'delete_account':
                if (($_POST['confirm'] ?? '') !== '1') throw new ValidationException('Confirm account removal before continuing.');
                $accounts->delete((int) ($_POST['id'] ?? 0)); Audit::record('admin', $adminId, 'CLOUDFLARE_ACCOUNT_DELETED', 'account', (int) ($_POST['id'] ?? 0));
                $this->success = 'Cloudflare account removed.'; $this->action = 'accounts'; break;
            case 'save_package':
                $id = $packages->save($_POST); Audit::record('admin', $adminId, 'CLOUDFLARE_PACKAGE_SAVED', 'package', $id, null, null, true, ['whmcs_product_id' => (int) ($_POST['whmcs_product_id'] ?? 0), 'whmcs_addon_id' => (int) ($_POST['whmcs_addon_id'] ?? 0)]);
                $this->success = 'WHMCS Cloudflare product mapping saved. Pricing remains controlled by WHMCS.'; $this->action = 'packages'; break;
            case 'delete_package':
                $packages->delete((int) ($_POST['id'] ?? 0)); Audit::record('admin', $adminId, 'CLOUDFLARE_PACKAGE_DELETED', 'package', (int) ($_POST['id'] ?? 0));
                $this->success = 'Product mapping removed.'; $this->action = 'packages'; break;
            case 'service_operation':
                $id = (int) ($_POST['service_id'] ?? 0); $op = (string) ($_POST['operation'] ?? '');
                if (in_array($op, ['terminate','purge'], true) && ($_POST['confirm'] ?? '') !== '1') throw new ValidationException('Confirm the destructive Cloudflare operation before continuing.');
                if ($op === 'provision') $services->retry($id, 'admin', $adminId);
                elseif ($op === 'sync') $services->sync($id, 'admin', $adminId);
                elseif ($op === 'suspend') $services->suspend($id, 'admin', $adminId);
                elseif ($op === 'unsuspend') $services->unsuspend($id, 'admin', $adminId);
                elseif ($op === 'terminate') $services->terminate($id, 'admin', $adminId);
                elseif ($op === 'sync_origin') $services->syncOriginIp($id, 'admin', $adminId);
                else throw new ValidationException('Unsupported Cloudflare service operation.');
                $this->success = 'Cloudflare service operation completed.'; $this->action = 'services'; break;
            default: throw new ValidationException('Unsupported Cloudflare admin action.');
        }
    }
    private function dashboardPage()
    {
        $services = new ServiceRepository(); $accounts = (new AccountRepository())->listAll();
        $stats = [
            'Services' => Db::count('services'), 'Active zones' => $services->countByStatus('ACTIVE'),
            'Provisioning' => Db::query('SELECT COUNT(*) AS c FROM `' . Db::table('services') . '` WHERE status IN (\'PENDING\',\'PROVISIONING\',\'PENDING_NAMESERVER_UPDATE\')')[0]['c'] ?? 0,
            'Suspended' => $services->countByStatus('SUSPENDED'), 'Failed' => $services->countByStatus('PROVISIONING_FAILED') + $services->countByStatus('SYNC_FAILED'),
        ];
        $lastSync = Db::firstQuery('SELECT MAX(last_synced_at) AS value FROM `' . Db::table('services') . '`');
        $apiErrors = Db::firstQuery('SELECT COUNT(*) AS c FROM `' . Db::table('api_logs') . '` WHERE outcome=\'error\' AND created_at>=?', [Clock::before(86400)]);
        $content = '<div class="row">';
        foreach ($stats as $label => $value) $content .= '<div class="col-sm-6 col-md-2"><div class="panel panel-default"><div class="panel-body"><div class="text-muted">' . self::h($label) . '</div><h3 style="margin:8px 0">' . number_format((int) $value) . '</h3></div></div></div>';
        $content .= '</div><div class="row"><div class="col-md-6"><div class="panel panel-default"><div class="panel-heading"><strong>Provider health</strong></div><div class="panel-body">';
        if (!$accounts) $content .= '<p class="text-warning">No Cloudflare account configured. Provisioning is disabled.</p>';
        else foreach ($accounts as $account) $content .= '<p><strong>' . self::h($account['label']) . '</strong> — ' . self::badge($account['connection_status']) . ' &nbsp; ' . (!empty($account['connection_tested_at']) ? 'tested ' . self::h($account['connection_tested_at']) : 'not tested') . '</p>';
        $content .= '<p>Last service sync: ' . self::h($lastSync['value'] ?? 'Never') . '</p><p>API errors in the last 24 hours: ' . number_format((int) ($apiErrors['c'] ?? 0)) . '</p></div></div></div>';
        $content .= '<div class="col-md-6"><div class="panel panel-default"><div class="panel-heading"><strong>Active Cloudflare plans</strong></div><div class="panel-body">';
        $plans = Db::query('SELECT plan_label, COUNT(*) AS services FROM `' . Db::table('services') . '` WHERE status IN (\'ACTIVE\',\'PENDING_NAMESERVER_UPDATE\') GROUP BY plan_label ORDER BY plan_label');
        if (!$plans) $content .= '<p class="text-muted">No Cloudflare services have been provisioned yet.</p>';
        foreach ($plans as $plan) $content .= '<p>' . self::h($plan['plan_label']) . ' <span class="badge">' . (int) $plan['services'] . '</span></p>';
        $content .= '</div></div></div></div><div class="alert alert-info"><strong>Revenue:</strong> WHMCS remains the billing source of truth. Recurring charges, invoices, payment status and subscriptions are managed in WHMCS; this module does not add prices or invoices of its own.</div>';
        return $content;
    }
    private function accountsPage()
    {
        $repo = new AccountRepository(); $rows = $repo->listAll(); $id = (int) ($_GET['id'] ?? 0); $selected = $id ? $repo->find($id) : null;
        $html = '<div class="row"><div class="col-md-7"><div class="panel panel-default"><div class="panel-heading"><strong>Cloudflare accounts</strong></div><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Name</th><th>Account ID</th><th>Enabled</th><th>Connection</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) $html .= '<tr><td>' . self::h($row['label']) . '</td><td><code>' . self::h($row['account_id']) . '</code></td><td>' . ((int) $row['enabled'] ? 'Yes' : 'No') . '</td><td>' . self::badge($row['connection_status']) . '</td><td><a class="btn btn-xs btn-default" href="' . self::h($this->url('accounts', ['id' => $row['id']])) . '">Edit</a></td></tr>';
        if (!$rows) $html .= '<tr><td colspan="5" class="text-muted">No account has been added. Credentials are never sent to customers.</td></tr>';
        $html .= '</tbody></table></div></div><div class="alert alert-warning"><strong>Token permissions:</strong> configure least-privilege Cloudflare API Token scopes for Account Read, Zone Read/Edit, DNS Read/Edit, Zone Settings Read/Edit, DNSSEC Read/Edit, Analytics Read, Cache Purge, and Rulesets Read/Edit. Some optional controls require additional plan/API access.</div></div>';
        $html .= '<div class="col-md-5"><div class="panel panel-primary"><div class="panel-heading"><strong>' . ($selected ? 'Edit Cloudflare account' : 'Add Cloudflare account') . '</strong></div><div class="panel-body">';
        $html .= '<form method="post">' . Csrf::field() . '<input type="hidden" name="ch247cf_action" value="save_account"><input type="hidden" name="id" value="' . (int) ($selected['id'] ?? 0) . '">';
        $html .= self::input('label','Account label',$selected['label'] ?? '', 'text', true);
        $html .= self::input('account_id','Cloudflare Account ID',$selected['account_id'] ?? '', 'text', true);
        $html .= self::input('api_base_url','API endpoint',$selected['api_base_url'] ?? 'https://api.cloudflare.com/client/v4','url',true);
        $html .= '<div class="form-group"><label>API token ' . ($selected && $selected['encrypted_api_token'] !== '' ? '<small class="text-success">(configured; blank keeps existing token)</small>' : '') . '</label><input type="password" class="form-control" name="api_token" value="" autocomplete="new-password" placeholder="' . ($selected ? 'Leave blank to keep current token' : 'Paste a Cloudflare API token') . '"><small>Encrypted with AES-256-GCM. The raw token is never displayed or returned to the browser.</small></div>';
        $html .= '<div class="form-group"><label>Zone creation mode</label><select class="form-control" name="zone_mode">' . self::options(['full'=>'Full zone / nameserver onboarding','partial'=>'Partial / CNAME setup'], $selected['zone_mode'] ?? 'full') . '</select></div>';
        $html .= '<div class="form-group"><label>Default SSL mode</label><select class="form-control" name="ssl_mode">' . self::options(['off'=>'Off','flexible'=>'Flexible','full'=>'Full','strict'=>'Full (Strict)'], $selected['ssl_mode'] ?? 'full') . '</select></div>';
        $html .= '<div class="form-group"><label>Default cache level</label><select class="form-control" name="cache_level">' . self::options(['basic'=>'Basic','standard'=>'Standard','aggressive'=>'Aggressive'], $selected['cache_level'] ?? 'standard') . '</select></div>';
        $html .= self::input('browser_cache_ttl','Default browser cache TTL (seconds)',$selected['browser_cache_ttl'] ?? '14400','number',true);
        $html .= '<div class="form-group"><label>Configured nameserver fallback (optional)</label><textarea class="form-control" rows="2" name="default_nameservers" placeholder="one nameserver per line">' . self::h(implode("\n", json_decode((string) ($selected['default_nameservers'] ?? '[]'), true) ?: [])) . '</textarea><small>Cloudflare-reported nameservers are authoritative; this value is used only if the API returns none.</small></div>';
        $html .= self::check('proxy_default','Proxy eligible A/AAAA/CNAME records by default', (int) ($selected['proxy_default'] ?? 1));
        $html .= self::check('delete_zone_on_terminate','Delete Cloudflare zone when the WHMCS service is terminated', (int) ($selected['delete_zone_on_terminate'] ?? 0));
        $html .= self::check('enabled','Enable this account for provisioning', (int) ($selected['enabled'] ?? 0));
        $html .= '<button class="btn btn-primary" type="submit">Save account</button></form>';
        if ($selected) {
            $html .= '<hr><form method="post" style="display:inline-block;margin-right:6px">' . Csrf::field() . '<input type="hidden" name="ch247cf_action" value="test_account"><input type="hidden" name="id" value="' . (int) $selected['id'] . '"><button class="btn btn-info" type="submit">Test Connection</button></form>';
            $html .= '<form method="post" style="display:inline-block" onsubmit="return confirm(\'Remove this Cloudflare account?\')">' . Csrf::field() . '<input type="hidden" name="ch247cf_action" value="delete_account"><input type="hidden" name="id" value="' . (int) $selected['id'] . '"><input type="hidden" name="confirm" value="1"><button class="btn btn-danger" type="submit">Remove account</button></form>';
        }
        $html .= '</div></div></div></div>';
        return $html;
    }
    private function packagesPage()
    {
        $repo = new PackageRepository(); $rows = $repo->listAll(); $accounts = (new AccountRepository())->listAll();
        $selected = !empty($_GET['id']) ? $repo->find((int) $_GET['id']) : null;
        $html = '<div class="row"><div class="col-md-7"><div class="panel panel-default"><div class="panel-heading"><strong>Existing WHMCS products and addons mapped to Cloudflare</strong></div><div class="table-responsive"><table class="table table-striped"><thead><tr><th>WHMCS catalog item</th><th>Cloudflare plan</th><th>Features</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $features = Features::normalize($row['features_json'] ?? []); $enabled = count(array_filter($features));
            $name = $row['whmcs_product_id'] ? ('Product #' . $row['whmcs_product_id'] . ' — ' . ($row['product_name'] ?? '')) : ('Addon #' . $row['whmcs_addon_id'] . ' — ' . ($row['addon_name'] ?? ''));
            $html .= '<tr><td>' . self::h($name) . '</td><td>' . self::h($row['plan_label']) . ' <small>(' . self::h($row['provider_plan_id']) . ')</small></td><td>' . $enabled . ' enabled</td><td>' . ((int) $row['enabled'] ? 'Enabled' : 'Disabled') . '</td><td><a class="btn btn-xs btn-default" href="' . self::h($this->url('packages', ['id' => $row['id']])) . '">Edit</a></td></tr>';
        }
        if (!$rows) $html .= '<tr><td colspan="5" class="text-muted">Create the WHMCS product first. Add the product ID here to map provider plan and entitlements.</td></tr>';
        $html .= '</tbody></table></div></div><div class="alert alert-info">Prices and billing cycles are never stored here. Set them in WHMCS Product Pricing; this mapping only controls the provider plan, provisioning defaults and backend-enforced feature entitlements.</div></div>';
        $html .= '<div class="col-md-5"><div class="panel panel-primary"><div class="panel-heading"><strong>' . ($selected ? 'Edit package mapping' : 'Add package mapping') . '</strong></div><div class="panel-body"><form method="post">' . Csrf::field() . '<input type="hidden" name="ch247cf_action" value="save_package"><input type="hidden" name="id" value="' . (int) ($selected['id'] ?? 0) . '">';
        $html .= self::input('whmcs_product_id','WHMCS product ID',$selected['whmcs_product_id'] ?? '', 'number', false);
        $html .= '<div class="text-center text-muted" style="margin:-7px 0 8px">— OR —</div>' . self::input('whmcs_addon_id','WHMCS addon ID (tbladdons)',$selected['whmcs_addon_id'] ?? '', 'number', false);
        $html .= '<div class="form-group"><label>Cloudflare account</label><select class="form-control" name="account_id"><option value="0">Use the first enabled account</option>';
        foreach ($accounts as $account) $html .= '<option value="' . (int) $account['id'] . '"' . ((int) ($selected['account_id'] ?? 0) === (int) $account['id'] ? ' selected' : '') . '>' . self::h($account['label']) . ' (#' . (int) $account['id'] . ')</option>';
        $html .= '</select></div>';
        $html .= self::input('plan_label','Customer-facing plan label',$selected['plan_label'] ?? '', 'text', true);
        $html .= self::input('provider_plan_id','Cloudflare provider plan ID',$selected['provider_plan_id'] ?? '', 'text', true);
        $html .= self::input('max_domains','Maximum domains',$selected['max_domains'] ?? 1, 'number', true);
        $html .= '<div class="form-group"><label>Zone creation mode override</label><select class="form-control" name="zone_mode">' . self::options([''=>'Use account default','full'=>'Full zone','partial'=>'Partial / CNAME'], $selected['zone_mode'] ?? '') . '</select></div>';
        $html .= '<div class="form-group"><label>Default SSL mode override</label><select class="form-control" name="ssl_mode">' . self::options([''=>'Use account default','off'=>'Off','flexible'=>'Flexible','full'=>'Full','strict'=>'Full (Strict)'], $selected['ssl_mode'] ?? '') . '</select></div>';
        $html .= '<div class="form-group"><label>Cache level override</label><select class="form-control" name="cache_level">' . self::options([''=>'Use account default','basic'=>'Basic','standard'=>'Standard','aggressive'=>'Aggressive'], $selected['cache_level'] ?? '') . '</select></div>';
        $html .= self::input('browser_cache_ttl','Browser cache TTL override (seconds)',$selected['browser_cache_ttl'] ?? '', 'number', false);
        $html .= '<div class="form-group"><label>Default DNS proxy</label><select class="form-control" name="proxy_default">' . self::options([''=>'Use account default','1'=>'Proxied','0'=>'DNS only'], isset($selected['proxy_default']) ? (string) $selected['proxy_default'] : '') . '</select></div>';
        $html .= self::check('dns_discovery','Ask Cloudflare to discover existing DNS records on new zone', (int) ($selected['dns_discovery'] ?? 1));
        $features = Features::normalize($selected['features_json'] ?? Features::defaults());
        $html .= '<fieldset><legend style="font-size:15px">Feature entitlements</legend><div class="row">';
        foreach (Features::all() as $key => $label) $html .= '<div class="col-sm-6">' . self::check('features[' . $key . ']', $label, !empty($features[$key])) . '</div>';
        $html .= '</div></fieldset>' . self::check('enabled','Mapping enabled', (int) ($selected['enabled'] ?? 1));
        $html .= '<button class="btn btn-primary" type="submit">Save mapping</button></form>';
        if ($selected) $html .= '<hr><form method="post" onsubmit="return confirm(\'Remove this package mapping?\')">' . Csrf::field() . '<input type="hidden" name="ch247cf_action" value="delete_package"><input type="hidden" name="id" value="' . (int) $selected['id'] . '"><button class="btn btn-danger" type="submit">Delete mapping</button></form>';
        return $html . '</div></div></div></div>';
    }
    private function servicesPage()
    {
        $search = trim((string) ($_GET['search'] ?? '')); $rows = (new ServiceRepository())->listForAdmin($search, 250);
        $html = '<form class="form-inline" method="get"><input type="hidden" name="module" value="cloudhost247cloudflare"><input type="hidden" name="action" value="services"><div class="form-group"><input class="form-control" name="search" value="' . self::h($search) . '" placeholder="Domain, customer email or service ID"></div> <button class="btn btn-default">Search</button></form><br><div class="panel panel-default"><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Service</th><th>Customer</th><th>Domain / zone</th><th>Plan</th><th>Status</th><th>Last sync</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>#' . (int) $row['id'] . '<br><small>WHMCS #' . (int) ($row['whmcs_service_id'] ?: $row['whmcs_addon_id']) . '</small></td><td>' . self::h(trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''))) . '<br><small>' . self::h($row['email'] ?? '') . '</small></td><td>' . self::h($row['display_domain'] ?? $row['zone_name']) . '<br><small>' . self::h($row['zone_id'] ?? 'No zone') . '</small></td><td>' . self::h($row['plan_label']) . '</td><td>' . self::badge($row['status']) . ($row['last_error_code'] ? '<br><small class="text-danger">' . self::h($row['last_error_code']) . '</small>' : '') . '</td><td>' . self::h($row['last_synced_at'] ?? '—') . '</td><td>' . $this->serviceActions($row) . '</td></tr>';
        }
        if (!$rows) $html .= '<tr><td colspan="7" class="text-muted">No Cloudflare services found.</td></tr>';
        return $html . '</tbody></table></div></div>';
    }
    private function zonesPage()
    {
        $rows = Db::query('SELECT s.id,s.zone_id,s.zone_name,s.status,s.activation_status,s.nameservers_json,s.last_synced_at,cl.firstname,cl.lastname,cl.email FROM `' . Db::table('services') . '` s LEFT JOIN tblclients cl ON cl.id=s.customer_id WHERE s.zone_id IS NOT NULL ORDER BY s.id DESC LIMIT 250');
        $html = '<div class="panel panel-default"><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Zone ID</th><th>Domain</th><th>Customer</th><th>Zone status</th><th>Nameservers</th><th>Last sync</th><th>Service</th></tr></thead><tbody>';
        foreach ($rows as $row) { $ns = json_decode((string) ($row['nameservers_json'] ?? '[]'), true) ?: []; $html .= '<tr><td><code>' . self::h($row['zone_id']) . '</code></td><td>' . self::h($row['zone_name']) . '</td><td>' . self::h(trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''))) . '<br><small>' . self::h($row['email'] ?? '') . '</small></td><td>' . self::badge($row['activation_status']) . '</td><td>' . self::h(implode(', ', $ns) ?: 'Not returned by API') . '</td><td>' . self::h($row['last_synced_at'] ?? '—') . '</td><td><a href="' . self::h($this->url('services')) . '">#' . (int) $row['id'] . '</a></td></tr>'; }
        if (!$rows) $html .= '<tr><td colspan="7" class="text-muted">No Cloudflare zones have been created.</td></tr>';
        return $html . '</tbody></table></div></div>';
    }
    private function logsPage()
    {
        $api = Db::query('SELECT * FROM `' . Db::table('api_logs') . '` ORDER BY id DESC LIMIT 100');
        $html = '<h3>Cloudflare API logs</h3><div class="panel panel-default"><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>Time</th><th>Request ID</th><th>Operation</th><th>Method / path</th><th>HTTP</th><th>Duration</th><th>Outcome</th><th>Error</th></tr></thead><tbody>';
        foreach ($api as $row) $html .= '<tr><td>' . self::h($row['created_at']) . '</td><td><code>' . self::h($row['request_id']) . '</code></td><td>' . self::h($row['operation']) . '</td><td>' . self::h($row['method'] . ' ' . $row['path']) . '</td><td>' . (int) $row['http_status'] . '</td><td>' . (int) $row['duration_ms'] . ' ms</td><td>' . self::badge($row['outcome']) . '</td><td>' . self::h($row['error_code'] ?? '') . '</td></tr>';
        if (!$api) $html .= '<tr><td colspan="8" class="text-muted">No API requests have been logged.</td></tr>';
        $html .= '</tbody></table></div></div><h3>Audit events</h3><div class="panel panel-default"><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Customer</th><th>Success</th><th>Error</th></tr></thead><tbody>';
        foreach (Audit::recent(100) as $row) $html .= '<tr><td>' . self::h($row['created_at']) . '</td><td>' . self::h($row['actor_type'] . ' #' . $row['actor_id']) . '</td><td>' . self::h($row['action']) . '</td><td>' . self::h($row['entity_type'] . ' ' . $row['entity_id']) . '</td><td>' . self::h($row['customer_id'] ?? '') . '</td><td>' . ((int) $row['success'] ? 'Yes' : 'No') . '</td><td>' . self::h($row['error_code'] ?? '') . '</td></tr>';
        return $html . '</tbody></table></div></div><p class="text-muted">Request bodies, response bodies, API tokens, and authorization headers are never logged.</p>';
    }
    private function serviceActions(array $service)
    {
        $actions = ['sync' => 'Sync Now'];
        if (in_array($service['status'], ['PROVISIONING_FAILED','PENDING','PROVISIONING'], true)) $actions['provision'] = 'Retry provision';
        if ($service['status'] === 'ACTIVE' || $service['status'] === 'PENDING_NAMESERVER_UPDATE') $actions['suspend'] = 'Suspend';
        if ($service['status'] === 'SUSPENDED') $actions['unsuspend'] = 'Unsuspend';
        if (!empty($service['origin_hosting_id'])) $actions['sync_origin'] = 'Sync hosting DNS';
        if (!in_array($service['status'], ['TERMINATED','TERMINATING'], true)) $actions['terminate'] = 'Terminate';
        $html = '';
        foreach ($actions as $op => $label) {
            $confirm = in_array($op, ['terminate'], true) ? ' onsubmit="return confirm(\'Terminate this Cloudflare service?\')"' : '';
            $html .= '<form method="post" style="display:inline-block;margin:0 3px 3px 0"' . $confirm . '>' . Csrf::field() . '<input type="hidden" name="ch247cf_action" value="service_operation"><input type="hidden" name="service_id" value="' . (int) $service['id'] . '"><input type="hidden" name="operation" value="' . self::h($op) . '">' . ($op === 'terminate' ? '<input type="hidden" name="confirm" value="1">' : '') . '<button class="btn btn-xs btn-default">' . self::h($label) . '</button></form>';
        }
        return $html;
    }
    private function url($action, array $extra = [])
    {
        $url = $this->link . '&action=' . rawurlencode($action);
        foreach ($extra as $key => $value) $url .= '&' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        return $url;
    }
    private static function input($name,$label,$value,$type='text',$required=false)
    {
        return '<div class="form-group"><label>' . self::h($label) . '</label><input class="form-control" type="' . self::h($type) . '" name="' . self::h($name) . '" value="' . self::h($value) . '"' . ($required ? ' required' : '') . '></div>';
    }
    private static function check($name,$label,$checked)
    {
        return '<div class="checkbox"><label><input type="checkbox" name="' . self::h($name) . '" value="1"' . ($checked ? ' checked' : '') . '> ' . self::h($label) . '</label></div>';
    }
    private static function options(array $options,$current)
    {
        $html=''; foreach ($options as $value=>$label) $html .= '<option value="' . self::h($value) . '"' . ((string) $value === (string) $current ? ' selected' : '') . '>' . self::h($label) . '</option>'; return $html;
    }
    private static function badge($value)
    {
        $value = (string) $value; $class = in_array(strtolower($value), ['active','connected','success','completed'], true) ? 'success' : (in_array(strtolower($value), ['failed','error','provisioning_failed','sync_failed'], true) ? 'danger' : 'warning');
        return '<span class="label label-' . $class . '">' . self::h($value ?: 'unknown') . '</span>';
    }
    private static function h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
