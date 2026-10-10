<?php
require_once __DIR__ . '/bootstrap.php';
use WHMCS\Database\Capsule;

DPDb::reset();

DPTest::section('harness');
foreach (['mod_digitalproducts_products','mod_digitalproducts_versions','mod_digitalproducts_entitlements',
          'mod_digitalproducts_download_tokens','mod_digitalproducts_licenses','mod_digitalproducts_downloads',
          'mod_digitalproducts_api_tokens','mod_digitalproducts_audit','mod_digitalproducts_rate_limits',
          'mod_digitalproducts_migrations'] as $t) {
    DPTest::ok("table {$t} exists", Capsule::schema()->hasTable($t));
}
DPTest::ok('migrations ledger recorded', Capsule::table('mod_digitalproducts_migrations')->count() >= 3);

$pid = DPDb::product(['whmcs_product_id' => 901, 'product_id' => 901, 'slug' => 'p1']);
$vid = DPDb::version($pid, '1.0.0');
DPTest::ok('product inserted', $pid > 0);
DPTest::ok('version inserted', $vid > 0);
Capsule::table('mod_digitalproducts_products')->where('id', $pid)->update(['current_version_id' => $vid]);
$sid = DPDb::service(7, 901, ['orderid' => 777]);
DPTest::ok('service inserted', $sid > 0);

$ent = (new \DigitalProducts\Services\EntitlementService())->grantForService($sid, ['order_id' => 777]);
DPTest::ok('entitlement granted', $ent !== null);
DPTest::same('entitlement client', 7, (int) $ent->client_id);
DPTest::same('entitlement version', $vid, (int) $ent->purchase_version_id);

exit(DPTest::summary());
