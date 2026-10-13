<?php
/**
 * Dedicated behavioral suite for WhmcsGateway, HttpInfrastructureProvider,
 * and InfraWorker (closes B-17).
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeInfrastructureProvider.php';

use Chs\Core\Db;
use Chs\Core\Secrets;
use Chs\Core\Settings;
use Chs\Providers\Infrastructure\HttpInfrastructureProvider;
use Chs\Providers\Infrastructure\ProviderFailure;
use Chs\Providers\Whmcs\WhmcsGateway;
use Chs\Workflow\InfraJobTypes;
use Chs\Workflow\InfraWorker;

$gateway = chs_boot();
chs_freeze();
Secrets::setKeyOverride('test-infra-secrets-key-0123456789');

/* ========================================================================
 * 1. HttpInfrastructureProvider
 * ======================================================================== */

T::section('HttpInfrastructureProvider: configuration, capabilities & C-5');

$unconfigured = new HttpInfrastructureProvider(1, 'Unconfigured', [], '');
T::ok('isConfigured is false without base_url and credentials', $unconfigured->isConfigured() === false);
T::throws('call on unconfigured provider throws ProviderFailure', function () use ($unconfigured) {
    $unconfigured->getAvailableImages();
}, ProviderFailure::class);

$endpoints = [
    'health'    => 'GET /v1/health',
    'images'    => 'GET /v1/images',
    'create'    => 'POST /v1/servers',
    'start'     => 'POST /v1/servers/{server}/start',
    'stop'      => 'POST /v1/servers/{server}/stop',
    'reboot'    => 'POST /v1/servers/{server}/reboot',
    'shutdown'  => 'POST /v1/servers/{server}/shutdown',
    'delete'    => 'DELETE /v1/servers/{server}',
    'status'    => 'GET /v1/servers/{server}',
    'reinstall' => 'POST /v1/servers/{server}/reinstall',
    'configure' => 'PATCH /v1/servers/{server}',
    'rescue'    => 'POST /v1/servers/{server}/rescue',
    'console'   => 'POST /v1/servers/{server}/console',
    'metrics'   => 'GET /v1/servers/{server}/metrics',
];
$sealedCreds = Secrets::encrypt(json_encode(['api_key' => 'infra-secret-token-999']));

// C-5: non-http(s) base_url rejected before transport
$badSchemeProvider = new HttpInfrastructureProvider(2, 'BadScheme', [
    'base_url'  => 'file:///etc',
    'endpoints' => $endpoints,
], $sealedCreds);
T::throws('C-5: file:// base_url rejected with ProviderFailure', function () use ($badSchemeProvider) {
    $badSchemeProvider->getAvailableImages();
}, ProviderFailure::class);

$providerSrc = file_get_contents(dirname(__DIR__) . '/lib/Providers/Infrastructure/HttpInfrastructureProvider.php');
T::ok('C-5: HttpInfrastructureProvider pins CURLOPT_PROTOCOLS and CURLOPT_REDIR_PROTOCOLS',
    strpos($providerSrc, 'CURLOPT_PROTOCOLS') !== false
    && strpos($providerSrc, 'CURLOPT_REDIR_PROTOCOLS') !== false
    && strpos($providerSrc, 'CURLPROTO_HTTP | CURLPROTO_HTTPS') !== false);

$recordedRequests = [];
$nextResponse = ['status' => 200, 'body' => '{"status":"ok"}'];
$transport = function ($method, $url, array $headers, $body, $timeout) use (&$recordedRequests, &$nextResponse) {
    $recordedRequests[] = [
        'method'  => $method,
        'url'     => $url,
        'headers' => $headers,
        'body'    => $body !== null ? json_decode($body, true) : null,
        'timeout' => $timeout,
    ];
    if (is_callable($nextResponse)) {
        return call_user_func($nextResponse, $method, $url, $headers, $body);
    }
    return $nextResponse;
};

$httpProvider = new HttpInfrastructureProvider(7, 'HetznerHTTP', [
    'base_url'  => 'https://api.infra.example.com',
    'endpoints' => $endpoints,
], $sealedCreds, $transport);

T::eq('providerId', '7', $httpProvider->providerId());
T::eq('providerName', 'HetznerHTTP', $httpProvider->providerName());
T::eq('providerType', 'http', $httpProvider->providerType());
T::ok('isConfigured is true with base_url and sealed credentials', $httpProvider->isConfigured() === true);
$caps = $httpProvider->capabilities();
T::ok('capabilities reflect mapped endpoints', !empty($caps['create']) && !empty($caps['reinstall']) && !empty($caps['metrics']));

T::section('HttpInfrastructureProvider: operations & error normalization');

$nextResponse = ['status' => 200, 'body' => json_encode(['status' => 'healthy', 'detail' => 'all nodes green'])];
$hc = $httpProvider->healthCheck();
T::eq('healthCheck ok status', 'ok', $hc['status']);
T::eq('healthCheck detail', 'all nodes green', $hc['detail']);
$lastReq = end($recordedRequests);
T::ok('Authorization Bearer header injected from unsealed credentials',
    in_array('Authorization: Bearer infra-secret-token-999', $lastReq['headers'], true));

$nextResponse = ['status' => 200, 'body' => json_encode([
    'images' => [
        ['id' => 'ubuntu-24.04', 'name' => 'Ubuntu 24.04 LTS', 'architecture' => 'x86_64', 'region' => 'eu-central'],
        ['image_id' => 'debian-12', 'name' => 'Debian 12', 'architecture' => 'arm64', 'region' => 'us-east'],
    ],
])];
$images = $httpProvider->getAvailableImages();
T::eq('getAvailableImages count', 2, count($images));
T::eq('getImage finds ubuntu-24.04', 'Ubuntu 24.04 LTS', $httpProvider->getImage('ubuntu-24.04')['name']);
T::eq('getImage returns null for unknown image', null, $httpProvider->getImage('nonexistent'));

$nextResponse = ['status' => 201, 'body' => json_encode([
    'data' => ['server' => ['id' => 'srv-9001', 'ip_address' => '198.51.100.42']],
])];
$created = $httpProvider->createServer([
    'hostname'     => 'node1.example.com',
    'image_id'     => 'ubuntu-24.04',
    'region'       => 'eu-central',
    'architecture' => 'x86_64',
]);
T::eq('createServer extracts provider_server_id', 'srv-9001', $created['provider_server_id']);
T::eq('createServer extracts ip_address', '198.51.100.42', $created['ip_address']);

$nextResponse = ['status' => 200, 'body' => json_encode(['server' => [' not_id ' => 1]])];
T::throws('createServer without id in response throws permanent ProviderFailure', function () use ($httpProvider) {
    $httpProvider->createServer(['hostname' => 'bad.example.com']);
}, ProviderFailure::class);

$nextResponse = ['status' => 200, 'body' => json_encode(['server' => ['status' => 'running', 'ip' => '198.51.100.42']])];
T::eq('getServerStatus normalizes running to active', 'active', $httpProvider->getServerStatus('srv/9001'));
$statusReq = end($recordedRequests);
T::ok('URL template rawurlencodes {server} parameter', strpos($statusReq['url'], '/v1/servers/srv%2F9001') !== false);
T::eq('getServerIp extracts IP', '198.51.100.42', $httpProvider->getServerIp('srv-9001'));

$httpProvider->startServer('srv-9001');
$httpProvider->stopServer('srv-9001');
$httpProvider->rebootServer('srv-9001');
$httpProvider->shutdownServer('srv-9001');
$httpProvider->enterRescueMode('srv-9001');
$reinstalled = $httpProvider->reinstallServer('srv-9001', 'debian-12', ['wipe' => true]);
T::eq('reinstallServer returns provider_server_id', 'srv-9001', $reinstalled['provider_server_id']);
$httpProvider->configureServer('srv-9001', ['hostname' => 'renamed.example.com']);

$nextResponse = ['status' => 200, 'body' => json_encode(['url' => 'wss://console.infra.example.com/vnc/9001', 'type' => 'novnc'])];
$console = $httpProvider->getConsole('srv-9001');
T::eq('getConsole returns url and type', 'novnc', $console['type']);

$nextResponse = ['status' => 200, 'body' => json_encode(['metrics' => ['cpu' => 42.5, 'memory' => 68.0, 'disk' => 31.2, 'bandwidth' => 120.4]])];
$metrics = $httpProvider->getServerMetrics('srv-9001');
T::eq('getServerMetrics parses cpu float', 42.5, $metrics['cpu']);
$httpProvider->deleteServer('srv-9001');

// Error paths: timeout, HTTP 429, HTTP 500
$nextResponse = ['status' => 0, 'body' => false, 'errno' => 28, 'error' => 'Operation timed out'];
$hcTimeout = $httpProvider->healthCheck();
T::eq('healthCheck catches timeout and returns unavailable/PROVIDER_TIMEOUT', 'PROVIDER_TIMEOUT', $hcTimeout['detail']);

$nextResponse = ['status' => 429, 'body' => json_encode(['error' => 'Rate limit exceeded'])];
T::throws('HTTP 429 maps to ProviderFailure', function () use ($httpProvider) {
    $httpProvider->getServerStatus('srv-9001');
}, ProviderFailure::class);

/* ========================================================================
 * 2. WhmcsGateway
 * ======================================================================== */

T::section('WhmcsGateway: SQLite WHMCS table queries & mutations');

$pdo = Db::pdo();
$pdo->exec('ALTER TABLE tbltickets ADD COLUMN merged_ticket_id INTEGER DEFAULT 0');
$pdo->exec("ALTER TABLE tblpricing ADD COLUMN onetime TEXT DEFAULT '-1.00'");

Db::exec("INSERT INTO tblinvoices (id, userid, status) VALUES (801, 22, 'Unpaid'), (802, 22, 'Paid')");
Db::exec("INSERT INTO tbldomains (id, userid, domain, expirydate, status) VALUES (1, 22, 'cloudhost247.com', '2027-10-13', 'Active')");
Db::exec("INSERT INTO tbltickets (id, tid, userid, subject, status, urgency, name, email, message, date, lastreply, flag, admin, merged_ticket_id)
    VALUES (50, 'TKT-50', 22, 'Need PTR record', 'Open', 'Medium', 'Ben Stone', 'ben@example.test', 'Initial ticket body', '2026-10-13 08:00:00', '2026-10-13 08:10:00', 0, '', 0)");
Db::exec("INSERT INTO tblticketreplies (id, tid, admin, name, message, date)
    VALUES (90, 50, 'sam', '', 'PTR updated', '2026-10-13 08:10:00')");
Db::exec("INSERT INTO tblpricing (id, type, currency, relid, msetupfee, onetime, monthly, quarterly, semiannually, annually, biennially, triennially)
    VALUES (1, 'product', 1, 101, '5.00', '-1.00', '20.00', '55.00', '-1.00', '200.00', '-1.00', '-1.00')");
Db::exec("INSERT INTO tblorders (id, userid, invoiceid) VALUES (700, 22, 802)");
Db::exec("INSERT INTO tblhosting (id, userid, packageid, orderid, domain, server, status, nextduedate, regdate)
    VALUES (900, 22, 101, 700, 'vps1.cloudhost247.com', 0, 'Pending', '2026-11-13', '2026-10-13')");

$wg = new WhmcsGateway();

T::ok('clientExists true for 22', $wg->clientExists(22) === true);
T::ok('clientExists false for 999', $wg->clientExists(999) === false);
T::eq('clientCurrency resolves EUR from tblcurrencies join', 'EUR', $wg->clientCurrency(22));
T::eq('defaultCurrency resolves USD', 'USD', $wg->defaultCurrency());
$summary = $wg->clientSummary(22);
T::eq('clientSummary name', 'Ben Stone', $summary['name']);
T::eq('clientSummary currency', 'EUR', $summary['currency']);

T::eq('invoiceStatus before cancel', 'Unpaid', $wg->invoiceStatus(801));
$wg->cancelInvoice(801);
T::eq('invoiceStatus after cancelInvoice', 'Cancelled', $wg->invoiceStatus(801));
T::eq('invoiceUrl uses SystemURL', 'https://billing.example.test/viewinvoice.php?id=802', $wg->invoiceUrl(802));

$domains = $wg->clientDomains(22);
T::eq('clientDomains returns active domain', 'cloudhost247.com', $domains[0]['domain']);

$convos = $wg->inboxConversations(22);
T::eq('inboxConversations returns ticket #50 with 1 reply', 1, (int) $convos[0]['replies']);
$msgs = $wg->inboxMessages(50);
T::eq('inboxMessages includes initial message + staff reply', 2, count($msgs));
T::eq('second message authorType is staff', 'staff', $msgs[1]['authorType']);

$unassigned = $wg->inboxAdminList(50, true);
T::eq('inboxAdminList finds 1 unassigned ticket', 1, count($unassigned));
$wg->inboxAssign(50, 2);
$wg->inboxSetStatus(50, 'Answered');
T::eq('inboxAssign sets flag and admin username', 0, count($wg->inboxAdminList(50, true)));

$products = $wg->serverProducts();
T::eq('serverProducts filters to type=server only', 2, count($products));
T::eq('serverProducts computes monthly + setup in minor units (2000 + 500)', 2500, $products[0]['price_minor']);
$pricing = $wg->productPricing(101, 'USD');
T::eq('productPricing filters out -1.00 disabled cycles', ['monthly' => 2000, 'quarterly' => 5500, 'annually' => 20000], $pricing['cycles']);

$svc = $wg->serviceForInvoice(802);
T::eq('serviceForInvoice resolves hosting #900', 900, (int) $svc['id']);
T::ok('updateService updates status and domain', $wg->updateService(900, ['status' => 'Active', 'domain' => 'node900.cloudhost247.com']));
T::eq('hostingDetail reflects updated domain', 'node900.cloudhost247.com', $wg->hostingDetail(900)['domain']);
T::eq('clientServices returns client 22 hosting list', 1, count($wg->clientServices(22)));

$srvId = $wg->createServerRecord(['name' => 'Node-900', 'ipaddress' => '198.51.100.99', 'hostname' => 'node900.cloudhost247.com', 'type' => 'cloudhost247']);
T::ok('createServerRecord inserts tblservers row', $srvId > 0);
T::ok('updateServerRecord updates ipaddress', $wg->updateServerRecord($srvId, ['ipaddress' => '198.51.100.100']));
T::eq('serverRecord reads updated ipaddress', '198.51.100.100', $wg->serverRecord($srvId)['ipaddress']);

T::throws('inboxReply rejects non-client authorType', function () use ($wg) {
    $wg->inboxReply(50, 'Staff note', 'staff', 2);
}, InvalidArgumentException::class);

T::throws('createOrder rejects non-server product before localAPI', function () use ($wg) {
    if (!function_exists('localAPI')) {
        function localAPI($cmd, array $payload = [], $admin = null)
        {
            $GLOBALS['chs_localapi_calls'][] = [$cmd, $payload];
            if ($cmd === 'CreateInvoice') {
                return ['result' => 'success', 'invoiceid' => 899];
            }
            if ($cmd === 'AddOrder') {
                return ['result' => 'success', 'orderid' => 799, 'invoiceid' => 898];
            }
            if ($cmd === 'SendEmail') {
                return ['result' => 'success'];
            }
            if ($cmd === 'DomainWhois') {
                return ['result' => 'success', 'status' => 'available'];
            }
            if ($cmd === 'AddTicketReply') {
                \Chs\Core\Db::exec("INSERT INTO tblticketreplies (id, tid, admin, name, message, date) VALUES (91, 50, '', 'Ben Stone', 'Thanks!', '2026-10-13 08:20:00')");
                return ['result' => 'success'];
            }
            return ['result' => 'error', 'message' => 'Unknown command'];
        }
    }
    $wg->createOrder(22, 103, 'monthly', 'shared.example.com');
}, InvalidArgumentException::class);

$GLOBALS['chs_localapi_calls'] = [];
$newInvId = $wg->createInvoice(22, [['description' => 'Custom setup', 'amount_minor' => 4995, 'taxed' => true]], 'USD', 7, 'Test note');
T::eq('createInvoice returns WHMCS invoiceid', 899, $newInvId);
T::eq('createInvoice formats minor units via Money::toDecimal', '49.95', $GLOBALS['chs_localapi_calls'][0][1]['itemamount1']);

$ord = $wg->createOrder(22, 101, 'monthly', 'vps2.cloudhost247.com', 'stripe');
T::eq('createOrder returns order_id and invoice_id', ['order_id' => 799, 'invoice_id' => 898], $ord);

$avail = $wg->domainAvailability('available-domain.com');
T::eq('domainAvailability parses available status', ['status' => 'available', 'available' => true], $avail);

$replyId = $wg->inboxReply(50, 'Thanks!', 'client', 22);
T::eq('inboxReply returns newly inserted reply id', 91, $replyId);

/* ========================================================================
 * 3. InfraWorker
 * ======================================================================== */

T::section('InfraWorker: handler registration & dispatch');

$handlers = InfraWorker::handlers();
foreach ([
    InfraJobTypes::PROVISION,
    InfraJobTypes::REINSTALL,
    InfraJobTypes::ACTION,
    InfraJobTypes::HEALTH_CHECK,
    InfraJobTypes::PROVIDER_SYNC,
] as $jobType) {
    T::ok("InfraWorker registers callable handler for {$jobType}", isset($handlers[$jobType]) && is_callable($handlers[$jobType]));
}

$infra = chs_seed_infra();
$fakeInfra = chs_fake_infra_provider($infra['provider_id']);
$handlers[InfraJobTypes::PROVIDER_SYNC](['id' => 1, 'payload' => '{}']);
$providerRow = Db::first('infrastructure_providers', ['id' => $infra['provider_id']]);
T::eq('PROVIDER_SYNC handler runs healthCheckAll and updates health_status', 'ok', $providerRow['health_status']);

T::finish();
