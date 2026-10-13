<?php
/**
 * cloudhost247cloudflare - SQLite database & lifecycle tests (closes B-16).
 *
 * Exercises the DB-backed surface offline against an in-memory SQLite PDO
 * instance:
 *   - Migrator & schema creation (0001_cloudflare_core)
 *   - AccountRepository, PackageRepository, ServiceRepository
 *   - JobQueue & Worker
 *   - ProvisioningService (hosting & addon provisioning, sync, origin IP sync,
 *     plan change, suspend/unsuspend, terminate, BillingGuard, domain ownership)
 *   - DnsRecordService & ZoneManagementService
 *   - AdminPortal & ClientPortal (auth, CSRF, IDOR ownership, rendering)
 */

define('CH247CF_TESTING', true);
require __DIR__ . '/../autoload.php';

use CloudHost247\Cloudflare\Core\AuthorizationException;
use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\ConfigurationException;
use CloudHost247\Cloudflare\Core\Crypto;
use CloudHost247\Cloudflare\Core\Csrf;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\Migrator;
use CloudHost247\Cloudflare\Core\NotFoundException;
use CloudHost247\Cloudflare\Core\ValidationException;
use CloudHost247\Cloudflare\Http\AdminPortal;
use CloudHost247\Cloudflare\Http\ClientPortal;
use CloudHost247\Cloudflare\Provider\TransportInterface;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\PackageRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;
use CloudHost247\Cloudflare\Service\DnsRecordService;
use CloudHost247\Cloudflare\Service\Features;
use CloudHost247\Cloudflare\Service\JobQueue;
use CloudHost247\Cloudflare\Service\ProvisioningService;
use CloudHost247\Cloudflare\Service\Worker;
use CloudHost247\Cloudflare\Service\ZoneManagementService;

$pass = 0;
$fail = 0;

function cf2_ok($label, $cond)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL $label\n";
    }
}

function cf2_eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n";
    }
}

function cf2_throws($label, $class, callable $fn)
{
    global $pass, $fail;
    try {
        $fn();
        $fail++;
        echo "  FAIL $label: no exception thrown (expected $class)\n";
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            $pass++;
        } else {
            $fail++;
            echo "  FAIL $label: expected $class, got " . get_class($e) . ': ' . $e->getMessage() . "\n";
        }
    }
}

/**
 * Scriptable mock Cloudflare v4 API transport for offline lifecycle tests.
 */
class MockCloudflareTransport implements TransportInterface
{
    public $calls = [];
    public $accountHex = '0123456789abcdef0123456789abcdef';
    public $zones = [];
    public $dnsRecords = [];
    public $settings = [];
    public $rulesets = [];
    public $purges = [];
    public $planId = 'cf-free';

    public function send($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $query = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        $payload = $body !== null ? json_decode((string) $body, true) : null;
        $this->calls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $payload];

        // 1. Token verify
        if ($method === 'GET' && $path === '/client/v4/user/tokens/verify') {
            return $this->json(200, ['id' => 'tok1', 'status' => 'active']);
        }
        // 2. Account read
        if ($method === 'GET' && preg_match('#^/client/v4/accounts/([a-f0-9]{32})$#', $path, $m)) {
            return $this->json(200, ['id' => $m[1], 'name' => 'CloudHost247 Reseller']);
        }
        // 3. List zones
        if ($method === 'GET' && $path === '/client/v4/zones') {
            $list = array_values($this->zones);
            if (!empty($query['name'])) {
                $want = strtolower($query['name']);
                $list = array_values(array_filter($list, function ($z) use ($want) {
                    return strtolower($z['name']) === $want;
                }));
            }
            return $this->json(200, $list);
        }
        // 4. Create zone
        if ($method === 'POST' && $path === '/client/v4/zones') {
            $zoneId = 'zone_' . substr(md5($payload['name']), 0, 12);
            $zone = [
                'id' => $zoneId,
                'name' => strtolower($payload['name']),
                'status' => 'active',
                'paused' => false,
                'type' => $payload['type'] ?? 'full',
                'name_servers' => ['ns1.cloudflare.com', 'ns2.cloudflare.com'],
                'account' => ['id' => $this->accountHex],
            ];
            $this->zones[$zoneId] = $zone;
            if (!isset($this->dnsRecords[$zoneId])) {
                $this->dnsRecords[$zoneId] = [];
            }
            return $this->json(200, $zone);
        }
        // 5. Get / Patch / Delete single zone
        if (preg_match('#^/client/v4/zones/([A-Za-z0-9_-]+)$#', $path, $m)) {
            $zoneId = $m[1];
            if (!isset($this->zones[$zoneId])) {
                return $this->error(404, 1001, 'Zone not found');
            }
            if ($method === 'GET') {
                return $this->json(200, $this->zones[$zoneId]);
            }
            if ($method === 'PATCH') {
                if (isset($payload['paused'])) {
                    $this->zones[$zoneId]['paused'] = (bool) $payload['paused'];
                }
                return $this->json(200, $this->zones[$zoneId]);
            }
            if ($method === 'DELETE') {
                unset($this->zones[$zoneId]);
                return $this->json(200, ['id' => $zoneId]);
            }
        }
        // 6. List / Create DNS records
        if (preg_match('#^/client/v4/zones/([A-Za-z0-9_-]+)/dns_records$#', $path, $m)) {
            $zoneId = $m[1];
            if ($method === 'GET') {
                return $this->json(200, array_values($this->dnsRecords[$zoneId] ?? []));
            }
            if ($method === 'POST') {
                $recId = $zoneId . '_rec_' . (count($this->dnsRecords[$zoneId] ?? []) + 1);
                $rec = [
                    'id' => $recId,
                    'type' => strtoupper($payload['type']),
                    'name' => strtolower($payload['name']),
                    'content' => $payload['content'],
                    'ttl' => $payload['ttl'] ?? 1,
                    'proxied' => $payload['proxied'] ?? false,
                    'priority' => $payload['priority'] ?? null,
                ];
                $this->dnsRecords[$zoneId][$recId] = $rec;
                return $this->json(200, $rec);
            }
        }
        // 7. Update / Delete single DNS record
        if (preg_match('#^/client/v4/zones/([A-Za-z0-9_-]+)/dns_records/([A-Za-z0-9_-]+)$#', $path, $m)) {
            $zoneId = $m[1];
            $recId = $m[2];
            if ($method === 'PATCH') {
                $rec = array_merge($this->dnsRecords[$zoneId][$recId] ?? ['id' => $recId], [
                    'id' => $recId,
                    'type' => strtoupper($payload['type']),
                    'name' => strtolower($payload['name']),
                    'content' => $payload['content'],
                    'ttl' => $payload['ttl'] ?? 1,
                    'proxied' => $payload['proxied'] ?? false,
                ]);
                $this->dnsRecords[$zoneId][$recId] = $rec;
                return $this->json(200, $rec);
            }
            if ($method === 'DELETE') {
                unset($this->dnsRecords[$zoneId][$recId]);
                return $this->json(200, ['id' => $recId]);
            }
        }
        // 8. Zone settings
        if (preg_match('#^/client/v4/zones/([A-Za-z0-9_-]+)/settings/([a-z0-9_]+)$#', $path, $m)) {
            $zoneId = $m[1];
            $setting = $m[2];
            if ($method === 'PATCH') {
                $this->settings[$zoneId][$setting] = $payload['value'] ?? null;
            }
            $val = $this->settings[$zoneId][$setting] ?? ($setting === 'ssl' ? 'full' : 'on');
            return $this->json(200, ['id' => $setting, 'value' => $val]);
        }
        // 9. Subscription / Plan
        if (preg_match('#^/client/v4/zones/([A-Za-z0-9_-]+)/subscription$#', $path, $m)) {
            if ($method === 'PATCH') {
                $this->planId = $payload['plan']['id'] ?? $this->planId;
            }
            return $this->json(200, ['plan' => ['id' => $this->planId, 'name' => strtoupper($this->planId)]]);
        }
        // 10. Cache purge
        if (preg_match('#^/client/v4/zones/([A-Za-z0-9_-]+)/purge_cache$#', $path, $m) && $method === 'POST') {
            $this->purges[] = [$m[1], $payload];
            return $this->json(200, ['id' => $m[1]]);
        }
        // 11. DNSSEC
        if (preg_match('#^/client/v4/zones/([A-Za-z0-9_-]+)/dnssec$#', $path, $m)) {
            $status = ($method === 'PATCH' && ($payload['status'] ?? '') === 'active') ? 'active' : 'disabled';
            return $this->json(200, ['status' => $status, 'ds' => 'example.com. 3600 IN DS 2371 13 2 ABCD']);
        }

        return $this->json(200, []);
    }

    private function json($status, $result)
    {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['success' => true, 'errors' => [], 'messages' => [], 'result' => $result]),
        ];
    }

    private function error($status, $code, $message)
    {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['success' => false, 'errors' => [['code' => $code, 'message' => $message]]]),
        ];
    }
}

/* ------------------------------------------- bootstrap SQLite + WHMCS stubs -- */

echo "== SQLite schema + Migrator ==\n";

$pdo = new PDO('sqlite::memory:');
Db::setPdo($pdo, 'sqlite');
Crypto::setKeyForTests('test-key-material-cloudflare-sqlite-0123456789');

// WHMCS core stub tables referenced by PackageRepository, ServiceRepository, BillingGuard, IntegrationStatus
$pdo->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, name TEXT, paytype TEXT)');
$pdo->exec('CREATE TABLE tbladdons (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, email TEXT)');
$pdo->exec('CREATE TABLE tblorders (id INTEGER PRIMARY KEY, invoiceid INTEGER)');
$pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT)');
$pdo->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INTEGER, packageid INTEGER, domain TEXT, domainstatus TEXT, orderid INTEGER, amount REAL, dedicatedip TEXT, serverip TEXT)');
$pdo->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INTEGER, addonid INTEGER, status TEXT, name TEXT)');
$pdo->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INTEGER, domain TEXT)');
$pdo->exec('CREATE TABLE tbladdonmodules (module TEXT, setting TEXT, value TEXT)');

$migrator = new Migrator();
$firstRun = $migrator->migrate();
cf2_eq('first migrate applies 0001_cloudflare_core', $firstRun['applied'], ['0001_cloudflare_core']);
$secondRun = $migrator->migrate();
cf2_eq('second migrate is idempotent (skipped)', $secondRun['skipped'], ['0001_cloudflare_core']);

foreach (['accounts', 'packages', 'services', 'dns_records', 'security_rules', 'jobs', 'api_logs', 'audit_logs', 'analytics_cache', 'rate_limits'] as $logical) {
    cf2_ok("table {$logical} exists in SQLite", Db::tableExists($logical));
}

// Seed WHMCS catalog & client data
Db::exec("INSERT INTO tbladdonmodules (module, setting, value) VALUES ('cloudhost247cloudflare', 'service_enabled', 'on')");
Db::exec("INSERT INTO tblproducts (id, name, paytype) VALUES (10, 'Cloudflare Pro Plan', 'recurring'), (11, 'Cloudflare Business Plan', 'recurring')");
Db::exec("INSERT INTO tbladdons (id, name) VALUES (20, 'Cloudflare Security Addon')");
Db::exec("INSERT INTO tblclients (id, firstname, lastname, email) VALUES (101, 'Ada', 'Obi', 'ada@example.com'), (102, 'Bola', 'Ade', 'bola@example.com')");
Db::exec("INSERT INTO tblinvoices (id, status) VALUES (500, 'Paid'), (501, 'Unpaid')");
Db::exec("INSERT INTO tblorders (id, invoiceid) VALUES (400, 500), (401, 501)");
Db::exec("INSERT INTO tblhosting (id, userid, packageid, domain, domainstatus, orderid, amount, dedicatedip, serverip) VALUES
    (300, 101, 10, 'Example.com', 'Active', 400, 15.00, '203.0.113.10', '203.0.113.1'),
    (301, 101, 10, 'unpaid.example.com', 'Pending', 401, 15.00, '', '203.0.113.1'),
    (302, 102, 10, 'bola-site.com', 'Active', 400, 15.00, '203.0.113.20', ''),
    (303, 101, 10, 'addon-site.example.com', 'Active', 400, 15.00, '203.0.113.50', '')");
Db::exec("INSERT INTO tblhostingaddons (id, hostingid, addonid, status, name) VALUES (600, 303, 20, 'Active', 'CF Addon')");
Db::exec("INSERT INTO tbldomains (id, userid, domain) VALUES (700, 101, 'example.com'), (701, 102, 'taken-by-bola.com')");

/* ------------------------------------------- AccountRepository -- */

echo "== AccountRepository & CF-1 port validation ==\n";

$transport = new MockCloudflareTransport();
$accounts = new AccountRepository($transport);

cf2_throws('AccountRepository::save rejects non-standard port in api_base_url (CF-1)', ValidationException::class, function () use ($accounts, $transport) {
    $accounts->save([
        'label' => 'Bad Port Account',
        'account_id' => $transport->accountHex,
        'api_base_url' => 'https://api.cloudflare.com:22/client/v4',
        'api_token' => 'secret-token-123',
        'enabled' => 1,
    ]);
});

$accountId = $accounts->save([
    'label' => 'Primary Cloudflare Account',
    'account_id' => $transport->accountHex,
    'api_base_url' => 'https://api.cloudflare.com/client/v4',
    'api_token' => 'cf-live-token-xyz987',
    'enabled' => 1,
    'zone_mode' => 'full',
    'ssl_mode' => 'strict',
    'cache_level' => 'aggressive',
    'proxy_default' => 1,
    'delete_zone_on_terminate' => 1,
    'default_nameservers' => "ns1.cloudflare.com\nns2.cloudflare.com",
]);
cf2_ok('Account saved and assigned primary key', $accountId > 0);
$savedAccount = $accounts->find($accountId);
cf2_ok('Stored token is encrypted with cfenc:v1:', strpos($savedAccount['encrypted_api_token'], 'cfenc:v1:') === 0);
cf2_eq('Account connection test updates status to connected', $accounts->test($accountId)['status'], 'Connected');
cf2_eq('Account row connection_status is connected', $accounts->find($accountId)['connection_status'], 'connected');

/* ------------------------------------------- PackageRepository -- */

echo "== PackageRepository ==\n";

$packages = new PackageRepository();
cf2_throws('PackageRepository rejects specifying both product and addon ID', ValidationException::class, function () use ($packages, $accountId) {
    $packages->save(['whmcs_product_id' => 10, 'whmcs_addon_id' => 20, 'account_id' => $accountId, 'plan_label' => 'Both', 'provider_plan_id' => 'pro']);
});

$proPkgId = $packages->save([
    'whmcs_product_id' => 10,
    'account_id' => $accountId,
    'plan_label' => 'Pro Plan',
    'provider_plan_id' => 'cf-pro',
    'ssl_mode' => 'strict',
    'cache_level' => 'aggressive',
    'browser_cache_ttl' => 14400,
    'proxy_default' => 1,
    'dns_discovery' => 1,
    'enabled' => 1,
    'features' => array_fill_keys(array_keys(Features::all()), 1),
]);
$bizPkgId = $packages->save([
    'whmcs_product_id' => 11,
    'account_id' => $accountId,
    'plan_label' => 'Business Plan',
    'provider_plan_id' => 'cf-biz',
    'enabled' => 1,
    'features' => array_fill_keys(array_keys(Features::all()), 1),
]);
$addonPkgId = $packages->save([
    'whmcs_addon_id' => 20,
    'account_id' => $accountId,
    'plan_label' => 'Addon Plan',
    'provider_plan_id' => 'cf-addon',
    'enabled' => 1,
    'features' => array_fill_keys(array_keys(Features::all()), 1),
]);
cf2_ok('Product and addon package mappings saved', $proPkgId > 0 && $bizPkgId > 0 && $addonPkgId > 0);
cf2_eq('forProduct hydrates features and plan_label', $packages->forProduct(10)['plan_label'], 'Pro Plan');
cf2_eq('forAddon hydrates addon mapping', $packages->forAddon(20)['provider_plan_id'], 'cf-addon');

/* ------------------------------------------- ProvisioningService & ServiceRepository -- */

echo "== ProvisioningService & ServiceRepository ==\n";

$services = new ServiceRepository();
$dnsService = new DnsRecordService($services, $accounts);
$provisioning = new ProvisioningService($accounts, $packages, $services, $dnsService);

// Unpaid hosting service must be blocked by BillingGuard
cf2_throws('Unpaid WHMCS service is rejected by BillingGuard', AuthorizationException::class, function () use ($provisioning) {
    $provisioning->provisionHosting(301);
});

// Provision paid hosting service #300 (Example.com -> example.com)
$provisioned = $provisioning->provisionHosting(300);
cf2_eq('Hosting service provisioned to ACTIVE', $provisioned['status'], 'ACTIVE');
cf2_eq('Domain normalized to lowercase example.com', $provisioned['zone_name'], 'example.com');
cf2_ok('Zone ID assigned', !empty($provisioned['zone_id']));
cf2_eq('Origin A and www CNAME records created during provisioning', Db::count('dns_records', ['service_id' => (int) $provisioned['id']]), 2);

// Idempotency: re-running provisionHosting does not create another zone
$callCountBefore = count($transport->calls);
$reprovisioned = $provisioning->provisionHosting(300);
cf2_eq('Repeated provisionHosting is idempotent and makes 0 API calls', count($transport->calls), $callCountBefore);
cf2_eq('Idempotent return keeps same service id', (int) $reprovisioned['id'], (int) $provisioned['id']);

// Cross-customer domain hijack guard
Db::exec("UPDATE tblhosting SET domain = 'taken-by-bola.com' WHERE id = 301");
Db::exec("UPDATE tblinvoices SET status = 'Paid' WHERE id = 501");
cf2_throws('Provisioning rejects domain owned by another WHMCS client', AuthorizationException::class, function () use ($provisioning) {
    $provisioning->provisionHosting(301);
});

// Customer ownership (IDOR protection) in ServiceRepository::forCustomer
$owned = $services->forCustomer((int) $provisioned['id'], 101);
cf2_eq('Owner client 101 can load their Cloudflare service', (int) $owned['id'], (int) $provisioned['id']);
cf2_throws('Other client 102 cannot access client 101 service (IDOR blocked)', NotFoundException::class, function () use ($services, $provisioned) {
    $services->forCustomer((int) $provisioned['id'], 102);
});
cf2_eq('listForCustomer(101) returns 1 service', count($services->listForCustomer(101)), 1);
cf2_eq('listForCustomer(102) returns 0 services', count($services->listForCustomer(102)), 0);

// Account and package deletion blocked while linked to a service
cf2_throws('AccountRepository::delete refuses to delete an account with active services', ValidationException::class, function () use ($accounts, $accountId) {
    $accounts->delete($accountId);
});
cf2_throws('PackageRepository::delete refuses to delete a package with active services', ValidationException::class, function () use ($packages, $proPkgId) {
    $packages->delete($proPkgId);
});

// Plan upgrade, suspend, unsuspend, sync
$upgraded = $provisioning->changePlan(300, 11, 'admin', 1);
cf2_eq('changePlan updates provider_plan_id to cf-biz', $upgraded['provider_plan_id'], 'cf-biz');

$suspended = $provisioning->suspend((int) $provisioned['id'], 'admin', 1);
cf2_eq('suspend marks service SUSPENDED', $suspended['status'], 'SUSPENDED');
cf2_eq('Cloudflare zone paused is true', $transport->zones[$provisioned['zone_id']]['paused'], true);

$unsuspended = $provisioning->unsuspend((int) $provisioned['id'], 'admin', 1);
cf2_eq('unsuspend restores service to ACTIVE', $unsuspended['status'], 'ACTIVE');
cf2_eq('Cloudflare zone paused is false', $transport->zones[$provisioned['zone_id']]['paused'], false);

$synced = $provisioning->sync((int) $provisioned['id'], 'admin', 1);
cf2_eq('sync returns synced zone and records', $synced['service']['status'], 'ACTIVE');

// Addon provisioning & origin IP synchronization
$addonService = $provisioning->provisionAddon(600);
cf2_eq('Addon service provisioned to ACTIVE', $addonService['status'], 'ACTIVE');
Db::exec("UPDATE tblhosting SET dedicatedip = '203.0.113.99' WHERE id = 303");
$originSync = $provisioning->syncOriginIp((int) $addonService['id'], 'admin', 1);
cf2_eq('syncOriginIp updates system-managed A record to new dedicated IP', $originSync['updated'], 1);

/* ------------------------------------------- DnsRecordService & ZoneManagementService -- */

echo "== DnsRecordService & ZoneManagementService ==\n";

$createdRec = $dnsService->create($unsuspended, [
    'type' => 'TXT',
    'name' => '_dmarc.example.com',
    'content' => 'v=DMARC1; p=reject',
    'ttl' => 3600,
], 'client', 101);
cf2_eq('Customer TXT record created and stored locally', $createdRec['ownership'], 'CUSTOMER_MANAGED');

$updatedRec = $dnsService->update($unsuspended, $createdRec['cloudflare_record_id'], [
    'name' => '_dmarc.example.com',
    'content' => 'v=DMARC1; p=quarantine',
    'ttl' => 3600,
], 'client', 101);
cf2_eq('Customer TXT record updated', $updatedRec['content'], 'v=DMARC1; p=quarantine');

cf2_ok('Customer TXT record deleted', $dnsService->delete($unsuspended, $createdRec['cloudflare_record_id'], 'client', 101));

$zoneManage = new ZoneManagementService($accounts, $services);
$zoneManage->saveSslSetting($unsuspended, 'ssl', 'strict', 'client', 101);
cf2_eq('saveSslSetting updates local service ssl_mode', $services->find((int) $unsuspended['id'])['ssl_mode'], 'strict');
$zoneManage->purgeCache($unsuspended, null, 'client', 101);
cf2_eq('purgeCache dispatches purge_everything to Cloudflare API', count($transport->purges), 1);

/* ------------------------------------------- JobQueue & Worker -- */

echo "== JobQueue & Worker ==\n";

$jobId1 = JobQueue::enqueue('sync_service', (int) $provisioned['id'], [], 'sync-key-1');
$jobId2 = JobQueue::enqueue('sync_service', (int) $provisioned['id'], [], 'sync-key-1');
cf2_eq('JobQueue::enqueue is idempotent on idempotency_key', $jobId1, $jobId2);

$worker = new Worker($provisioning);
$summary = $worker->run(10);
cf2_eq('Worker claims and completes due sync_service job', $summary['completed'], 1);
cf2_eq('Completed job status is completed', Db::first('jobs', ['id' => $jobId1])['status'], 'completed');

// Test job failure backoff & terminal transition
$failJobId = JobQueue::enqueue('unknown_kind', (int) $provisioned['id'], [], 'bad-job-1');
Db::update('jobs', ['id' => $failJobId], ['max_attempts' => 2]);
$summaryFail1 = $worker->run(10);
cf2_eq('First failure schedules retry', $summaryFail1['retrying'], 1);
Db::update('jobs', ['id' => $failJobId], ['next_attempt_at' => Clock::before(10)]);
$summaryFail2 = $worker->run(10);
cf2_eq('Reaching max_attempts marks job terminally failed', $summaryFail2['failed'], 1);
cf2_eq('Terminal job status is failed', Db::first('jobs', ['id' => $failJobId])['status'], 'failed');

/* ------------------------------------------- AdminPortal & ClientPortal -- */

echo "== AdminPortal & ClientPortal ==\n";

$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$unauthAdmin = (new AdminPortal([]))->render();
cf2_ok('AdminPortal rejects unauthenticated visitor', strpos($unauthAdmin, 'administrator session is required') !== false);

$_SESSION['adminid'] = 1;
foreach (['dashboard', 'accounts', 'packages', 'services', 'zones', 'logs'] as $pageKey) {
    $_GET = ['action' => $pageKey];
    $html = (new AdminPortal([]))->render();
    cf2_ok("AdminPortal renders {$pageKey} tab without error", strpos($html, 'Cloudflare Reseller &amp; Management') !== false && strpos($html, 'Unable to load this section') === false);
}

// Admin POST without CSRF token fails closed ($proPkgId is unused after service 300 upgraded to $bizPkgId)
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['ch247cf_action' => 'delete_package', 'id' => $proPkgId];
$csrfFailHtml = (new AdminPortal([]))->render();
cf2_ok('AdminPortal POST without CSRF token fails closed', strpos($csrfFailHtml, 'security token expired') !== false);
cf2_ok('Package was not deleted on CSRF failure', $packages->find($proPkgId) !== null);

// Admin POST with valid CSRF token succeeds
$token = Csrf::token();
$_POST = ['ch247cf_csrf' => $token, 'ch247cf_action' => 'delete_package', 'id' => $proPkgId];
$csrfOkHtml = (new AdminPortal([]))->render();
cf2_ok('AdminPortal POST with valid CSRF token deletes unused package', strpos($csrfOkHtml, 'Product mapping removed.') !== false && $packages->find($proPkgId) === null);

// ClientPortal guards
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];
$unauthClient = (new ClientPortal())->dispatch([]);
cf2_eq('ClientPortal requires login when unauthenticated', $unauthClient['templatevariables']['login_required'] ?? false, true);

$_SESSION['uid'] = 102;
$_GET = ['action' => 'service', 'id' => (int) $provisioned['id']];
$foreignClient = (new ClientPortal())->dispatch([]);
cf2_eq('ClientPortal blocks client 102 from viewing client 101 service', $foreignClient['templatevariables']['unavailable'] ?? false, true);

$_SESSION['uid'] = 101;
$_GET = ['action' => 'service', 'id' => (int) $provisioned['id'], 'tab' => 'overview'];
$ownerClient = (new ClientPortal())->dispatch([]);
cf2_eq('ClientPortal renders overview for owner client 101', $ownerClient['templatevariables']['unavailable'] ?? true, false);
cf2_eq('ClientPortal marks active paid service as manageable', $ownerClient['templatevariables']['service_manageable'] ?? false, true);

// Terminate service and verify zone deletion when delete_zone_on_terminate=1
$terminated = $provisioning->terminate((int) $provisioned['id'], 'admin', 1);
cf2_eq('terminate marks service TERMINATED', $terminated['status'], 'TERMINATED');
cf2_ok('Cloudflare zone was deleted because delete_zone_on_terminate=1', !isset($transport->zones[$provisioned['zone_id']]));

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
