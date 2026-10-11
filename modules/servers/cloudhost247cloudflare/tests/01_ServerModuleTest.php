<?php
/**
 * Offline tests for the CloudHost247 Cloudflare WHMCS provisioning module.
 *
 * The addon classes (repositories, provisioning, logger) are replaced with
 * in-memory doubles; the real CloudflareException is used. Run with
 * tests/run.mjs (php-wasm) or directly under php. Prints FAIL=<n>.
 */

namespace CloudHost247\Cloudflare\Core {
    class FakeLoggerCapture
    {
        public static $events = [];
        public static function reset() { self::$events = []; }
    }
    class Logger extends FakeLoggerCapture
    {
        public static function error($event, array $context = []) { self::$events[] = [$event, $context]; }
    }
}

namespace CloudHost247\Cloudflare\Repository {
    class ServiceRepository
    {
        public static $rows = [];
        public static $throw = null;
        public static function reset() { self::$rows = []; self::$throw = null; }
        public function findByHostingId($hostingId)
        {
            if (self::$throw) throw self::$throw;
            return isset(self::$rows[(int) $hostingId]) ? self::$rows[(int) $hostingId] : null;
        }
    }
    class AccountRepository
    {
        public static $accounts = [];
        public static $testResult = ['account_id' => 'acc_1', 'account_name' => 'Main'];
        public static $testedIds = [];
        public static $throw = null;
        public static function reset() { self::$accounts = []; self::$testedIds = []; self::$throw = null; self::$testResult = ['account_id' => 'acc_1', 'account_name' => 'Main']; }
        public function listAll() { return self::$accounts; }
        public function test($id)
        {
            if (self::$throw) throw self::$throw;
            self::$testedIds[] = (int) $id;
            return self::$testResult;
        }
    }
}

namespace CloudHost247\Cloudflare\Service {
    class ProvisioningService
    {
        public static $calls = [];
        public static $provisionResult = ['status' => 'ACTIVE'];
        public static $throw = null;
        public static function reset() { self::$calls = []; self::$throw = null; self::$provisionResult = ['status' => 'ACTIVE']; }
        public function provisionHosting($whmcsServiceId) { self::$calls[] = ['provisionHosting', (int) $whmcsServiceId]; if (self::$throw) throw self::$throw; return self::$provisionResult; }
        public function suspend($id, $actorType, $actorId) { self::$calls[] = ['suspend', (int) $id, $actorType, $actorId]; if (self::$throw) throw self::$throw; return true; }
        public function unsuspend($id, $actorType, $actorId) { self::$calls[] = ['unsuspend', (int) $id, $actorType, $actorId]; if (self::$throw) throw self::$throw; return true; }
        public function terminate($id, $actorType, $actorId) { self::$calls[] = ['terminate', (int) $id, $actorType, $actorId]; if (self::$throw) throw self::$throw; return true; }
        public function changePlan($serviceId, $productId, $actorType, $actorId) { self::$calls[] = ['changePlan', (int) $serviceId, (int) $productId, $actorType, $actorId]; if (self::$throw) throw self::$throw; return true; }
        public function sync($id, $actorType, $actorId) { self::$calls[] = ['sync', (int) $id, $actorType, $actorId]; if (self::$throw) throw self::$throw; return true; }
    }
}

namespace {

use CloudHost247\Cloudflare\Core\Logger;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;
use CloudHost247\Cloudflare\Service\ProvisioningService;

define('WHMCS', true);
require_once __DIR__ . '/../cloudhost247cloudflare.php';

$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
}
function resetAll() {
    Logger::reset(); ServiceRepository::reset(); AccountRepository::reset(); ProvisioningService::reset();
    $_SESSION = [];
}
function serviceRow(array $over = []) {
    return array_merge(['id' => 9, 'status' => 'ACTIVE', 'plan_label' => 'Pro', 'zone_id' => 'zone_1',
        'zone_name' => 'example.test', 'nameservers_json' => '["ns1.test","ns2.test"]',
        'last_synced_at' => '2026-10-10 10:00:00', 'last_error_code' => '', 'last_error_message' => ''], $over);
}

echo "== metadata and config ==\n";
resetAll();
$meta = cloudhost247cloudflare_MetaData();
check('display name', $meta['DisplayName'] === 'CloudHost247 Cloudflare');
check('no dedicated server required', $meta['RequiresServer'] === false);
check('no product-level options (addon owns mapping)', cloudhost247cloudflare_ConfigOptions() === []);

echo "== create ==\n";
resetAll();
check('missing service id is refused', cloudhost247cloudflare_CreateAccount([]) === 'Error: WHMCS service ID is missing.');
check('ACTIVE provisions', cloudhost247cloudflare_CreateAccount(['serviceid' => 7]) === 'success');
ProvisioningService::$provisionResult = ['status' => 'pending_nameserver_update'];
check('pending nameserver update still succeeds', cloudhost247cloudflare_CreateAccount(['serviceid' => 7]) === 'success');
ProvisioningService::$provisionResult = ['status' => 'FAILED'];
check('failed status does not report success', cloudhost247cloudflare_CreateAccount(['serviceid' => 7]) !== 'success');
ProvisioningService::$throw = new \CloudHost247\Cloudflare\Core\CloudflareException('CF_X', 'Zone already exists.');
check('domain error message surfaces', cloudhost247cloudflare_CreateAccount(['serviceid' => 7]) === 'Error: Zone already exists.');
ProvisioningService::$throw = new \RuntimeException('db gone');
check('unexpected error is generic', cloudhost247cloudflare_CreateAccount(['serviceid' => 7]) === 'Error: Cloudflare provisioning failed. Check the admin Cloudflare logs.');
check('failure logs the exception class', count(Logger::$events) === 2 && Logger::$events[1][0] === 'WHMCS CreateAccount failed' && Logger::$events[1][1]['error'] === 'RuntimeException');

echo "== suspend / unsuspend ==\n";
resetAll();
ServiceRepository::$rows[7] = serviceRow();
check('suspend succeeds', cloudhost247cloudflare_SuspendAccount(['serviceid' => 7]) === 'success');
check('suspend targets the internal id as system', ProvisioningService::$calls[0] === ['suspend', 9, 'system', 0]);
check('unsuspend succeeds', cloudhost247cloudflare_UnsuspendAccount(['serviceid' => 7]) === 'success');
check('unmapped suspend is an error', cloudhost247cloudflare_SuspendAccount(['serviceid' => 404]) === 'Error: Cloudflare service mapping not found.');
check('unmapped unsuspend is an error', cloudhost247cloudflare_UnsuspendAccount(['serviceid' => 404]) === 'Error: Cloudflare service mapping not found.');
ProvisioningService::$throw = new \RuntimeException('x');
check('suspend failure is generic and logged', cloudhost247cloudflare_SuspendAccount(['serviceid' => 7]) === 'Error: Cloudflare suspension failed. Check the admin Cloudflare logs.' && count(Logger::$events) === 1);

echo "== terminate ==\n";
resetAll();
ServiceRepository::$rows[7] = serviceRow();
check('terminate succeeds', cloudhost247cloudflare_TerminateAccount(['serviceid' => 7]) === 'success');
check('terminate targets the internal id', ProvisioningService::$calls[0][0] === 'terminate' && ProvisioningService::$calls[0][1] === 9);
check('terminate without a mapping still succeeds (idempotent)', cloudhost247cloudflare_TerminateAccount(['serviceid' => 404]) === 'success');
ProvisioningService::$throw = new \RuntimeException('x');
check('terminate failure is generic', cloudhost247cloudflare_TerminateAccount(['serviceid' => 7]) === 'Error: Cloudflare termination failed. Check the admin Cloudflare logs.');

echo "== change package ==\n";
resetAll();
check('incomplete upgrade params are refused', cloudhost247cloudflare_ChangePackage(['serviceid' => 7]) === 'Error: WHMCS upgrade parameters are incomplete.');
check('plan change succeeds', cloudhost247cloudflare_ChangePackage(['serviceid' => 7, 'newpackageid' => 42]) === 'success');
check('plan change passes hosting id, product and system actor', ProvisioningService::$calls[0] === ['changePlan', 7, 42, 'system', 0]);
check('legacy packageid key is accepted', cloudhost247cloudflare_ChangePackage(['serviceid' => 7, 'packageid' => 43]) === 'success' && ProvisioningService::$calls[1][2] === 43);
ProvisioningService::$throw = new \CloudHost247\Cloudflare\Core\CloudflareException('CF_Y', 'Unknown plan.');
check('domain error message surfaces', cloudhost247cloudflare_ChangePackage(['serviceid' => 7, 'newpackageid' => 42]) === 'Error: Unknown plan.');

echo "== test connection ==\n";
resetAll();
check('no accounts configured', cloudhost247cloudflare_TestConnection([]) === ['success' => false, 'message' => 'No enabled Cloudflare account is configured.']);
AccountRepository::$accounts = [['id' => 1, 'enabled' => false]];
check('no enabled account configured', cloudhost247cloudflare_TestConnection([])['success'] === false);
AccountRepository::$accounts = [['id' => 1, 'enabled' => false], ['id' => 2, 'enabled' => true]];
$ok = cloudhost247cloudflare_TestConnection([]);
check('first enabled account is tested', AccountRepository::$testedIds === [2]);
check('success names the account', $ok['success'] === true && strpos($ok['message'], 'Main') !== false);
AccountRepository::$testResult = ['account_id' => 'acc_9', 'account_name' => ''];
check('empty name falls back to the id', strpos(cloudhost247cloudflare_TestConnection([])['message'], 'acc_9') !== false);
AccountRepository::$throw = new \CloudHost247\Cloudflare\Core\CloudflareException('CF_Z', 'Bad token.');
check('domain test failure surfaces', cloudhost247cloudflare_TestConnection([]) === ['success' => false, 'message' => 'Bad token.']);
AccountRepository::$throw = new \RuntimeException('x');
check('unexpected test failure is generic', cloudhost247cloudflare_TestConnection([]) === ['success' => false, 'message' => 'Cloudflare connection test failed.']);

echo "== client area ==\n";
resetAll();
ServiceRepository::$rows[7] = serviceRow();
$ca = cloudhost247cloudflare_ClientArea(['serviceid' => 7]);
check('client area template', $ca['templatefile'] === 'clientarea');
check('service passed to the template', $ca['vars']['cloudflare_service']['zone_id'] === 'zone_1');
check('management url carries the internal id', $ca['vars']['cloudflare_url'] === 'index.php?m=cloudhost247cloudflare&action=service&id=9');
$pending = cloudhost247cloudflare_ClientArea(['serviceid' => 404]);
check('unmapped service renders the pending state', $pending['vars']['cloudflare_service'] === [] && substr($pending['vars']['cloudflare_url'], -4) === 'id=0');

echo "== admin tab ==\n";
resetAll();
check('unmapped service shows not provisioned', cloudhost247cloudflare_AdminServicesTabFields(['serviceid' => 404]) === ['Cloudflare Status' => 'Not provisioned']);
ServiceRepository::$rows[7] = serviceRow();
$tab = cloudhost247cloudflare_AdminServicesTabFields(['serviceid' => 7]);
check('six tab fields', count($tab) === 6 && $tab['Cloudflare Status'] === 'ACTIVE' && $tab['Cloudflare Plan'] === 'Pro');
check('nameservers joined', $tab['Cloudflare Nameservers'] === 'ns1.test, ns2.test');
ServiceRepository::$rows[7] = serviceRow(['status' => '<script>alert(1)</script>', 'plan_label' => '"><img src=x onerror=1>',
    'zone_id' => '<b>z</b>', 'nameservers_json' => '["<i>ns</i>"]', 'last_error_code' => 'E', 'last_error_message' => '<script>e</script>']);
$xss = implode("\n", cloudhost247cloudflare_AdminServicesTabFields(['serviceid' => 7]));
check('no raw markup reaches the admin tab', strpos($xss, '<script>') === false && strpos($xss, '<img') === false && strpos($xss, '<b>') === false);
check('escaped content preserved', strpos($xss, '&lt;script&gt;') !== false);
ServiceRepository::$rows[7] = serviceRow(['nameservers_json' => 'not-json{{', 'last_synced_at' => null]);
$odd = cloudhost247cloudflare_AdminServicesTabFields(['serviceid' => 7]);
check('broken nameserver json degrades to empty', $odd['Cloudflare Nameservers'] === '');
check('missing sync time reads Never', $odd['Cloudflare Last Sync'] === 'Never');

echo "== admin custom button ==\n";
resetAll();
check('sync button registered', cloudhost247cloudflare_AdminCustomButtonArray() === ['Sync Cloudflare Zone' => 'SyncCloudflareZone']);
check('sync without mapping reports it', cloudhost247cloudflare_SyncCloudflareZone(['serviceid' => 404]) === 'Cloudflare service mapping not found.');
ServiceRepository::$rows[7] = serviceRow();
$_SESSION['adminid'] = 5;
check('sync succeeds', cloudhost247cloudflare_SyncCloudflareZone(['serviceid' => 7]) === 'Cloudflare zone and DNS synchronized.');
check('sync records the admin actor', ProvisioningService::$calls[0] === ['sync', 9, 'admin', 5]);
ProvisioningService::$throw = new \RuntimeException('x');
check('sync failure is generic and logged', cloudhost247cloudflare_SyncCloudflareZone(['serviceid' => 7]) === 'Cloudflare sync failed. Check the Cloudflare addon logs.' && count(Logger::$events) === 1);

echo "== template static checks ==\n";
$tpl = (string) file_get_contents(__DIR__ . '/../templates/clientarea.tpl');
$tags = [];
preg_match_all('/\{\$[^}]+\}/', $tpl, $tags);
$unescaped = array_values(array_filter($tags[0], function ($t) { return strpos($t, '|escape') === false; }));
check('every template interpolation is escaped', count($tags[0]) > 0 && count($unescaped) === 0);
$module = (string) file_get_contents(__DIR__ . '/../cloudhost247cloudflare.php');
check('management url id is cast to int', strpos($module, "(int) (\$service['id'] ?? 0)") !== false);
check('no dead Db import', strpos($module, 'Core\\Db;') === false);

echo "\nserver module: PASS=$pass FAIL=$fail\n";
echo "FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
}
