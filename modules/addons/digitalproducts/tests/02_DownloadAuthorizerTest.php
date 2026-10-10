<?php
/**
 * DownloadAuthorizer — every denial path, plus the happy path.
 * Runs against the real schema through the SQLite Capsule shim.
 */
require_once __DIR__ . '/bootstrap.php';

use DigitalProducts\Core\Clock;
use DigitalProducts\Security\DownloadAuthorizer;
use DigitalProducts\Security\TokenService;
use WHMCS\Database\Capsule;

DPDb::reset();

/** Build one complete, granted download and return the pieces. */
function dp_scenario(array $overrides = [], array $serviceOverrides = [])
{
    $productId = DPDb::product($overrides['product'] ?? []);
    $packageId = (int) DPDb::row('mod_digitalproducts_products', $productId)->whmcs_product_id;
    $currentId = DPDb::version($productId, '1.0.0');
    Capsule::table('mod_digitalproducts_products')->where('id', $productId)->update(['current_version_id' => $currentId]);
    $serviceId = DPDb::service(7, $packageId, array_merge(['orderid' => 777], $serviceOverrides));
    $entitlementId = DPDb::entitlement(7, $productId, $serviceId, array_merge([
        'purchase_version_id' => $currentId,
    ], $overrides['entitlement'] ?? []));
    $issued = (new TokenService())->issue($entitlementId, $currentId, 7);
    return compact('productId', 'packageId', 'currentId', 'serviceId', 'entitlementId') + ['token' => $issued, 'tokenId' => $issued['id']];
}

function dp_resolve($tokenId, $clientId = 0)
{
    return (new DownloadAuthorizer())->resolve($tokenId, $clientId);
}

/* ------------------------------------------------------------------ happy */

DPTest::section('download authorizer — happy path');
$s = dp_scenario();
$auth = dp_resolve($s['tokenId'], 7);
DPTest::ok('granted download resolves', !empty($auth['ok']));
DPTest::same('resolved version', $s['currentId'], (int) $auth['record']->version_id);
DPTest::same('resolved entitlement', $s['entitlementId'], (int) $auth['record']->entitlement_id);

/* -------------------------------------------------------------- denials -- */

DPTest::section('download authorizer — denial paths');

$bad = dp_scenario();
DPTest::same('other client is refused', 'not_entitled', dp_resolve($bad['tokenId'], 999)['reason']);

$expired = dp_scenario();
Capsule::table('mod_digitalproducts_download_tokens')->where('id', $expired['tokenId'])
    ->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
DPTest::same('expired token is refused', 'expired', dp_resolve($expired['tokenId'], 7)['reason']);

$used = dp_scenario();
Capsule::table('mod_digitalproducts_download_tokens')->where('id', $used['tokenId'])
    ->update(['used_at' => Clock::now()]);
DPTest::same('replayed single-use token is refused', 'invalid_token', dp_resolve($used['tokenId'], 7)['reason']);

$missing = dp_resolve(999999, 7);
DPTest::same('unknown token is refused', 'invalid_token', $missing['reason']);

$revoked = dp_scenario();
Capsule::table('mod_digitalproducts_entitlements')->where('id', $revoked['entitlementId'])->update(['status' => 'revoked']);
DPTest::same('revoked entitlement is refused', 'not_entitled', dp_resolve($revoked['tokenId'], 7)['reason']);

$suspended = dp_scenario();
Capsule::table('mod_digitalproducts_entitlements')->where('id', $suspended['entitlementId'])->update(['status' => 'suspended']);
DPTest::same('suspended entitlement is refused', 'not_entitled', dp_resolve($suspended['tokenId'], 7)['reason']);

$inactiveProduct = dp_scenario();
Capsule::table('mod_digitalproducts_products')->where('id', $inactiveProduct['productId'])->update(['status' => 'inactive']);
DPTest::same('inactive product is refused', 'not_entitled', dp_resolve($inactiveProduct['tokenId'], 7)['reason']);

$inactiveVersion = dp_scenario();
Capsule::table('mod_digitalproducts_versions')->where('id', $inactiveVersion['currentId'])->update(['status' => 'draft']);
DPTest::same('unpublished version is refused', 'not_entitled', dp_resolve($inactiveVersion['tokenId'], 7)['reason']);

$deadService = dp_scenario([], ['domainstatus' => 'Suspended']);
DPTest::same('suspended WHMCS service is refused', 'not_entitled', dp_resolve($deadService['tokenId'], 7)['reason']);

$cancelledService = dp_scenario();
Capsule::table('tblhosting')->where('id', $cancelledService['serviceId'])->update(['domainstatus' => 'Cancelled']);
DPTest::same('cancelled WHMCS service is refused', 'not_entitled', dp_resolve($cancelledService['tokenId'], 7)['reason']);

$wrongOwner = dp_scenario();
Capsule::table('tblhosting')->where('id', $wrongOwner['serviceId'])->update(['userid' => 4242]);
DPTest::same('service owned by another client is refused', 'not_entitled', dp_resolve($wrongOwner['tokenId'], 7)['reason']);

$wrongPackage = dp_scenario();
Capsule::table('tblhosting')->where('id', $wrongPackage['serviceId'])->update(['packageid' => 902]);
DPTest::same('service on another package is refused', 'not_entitled', dp_resolve($wrongPackage['tokenId'], 7)['reason']);

$deadOrder = dp_scenario();
Capsule::table('tblorders')->insert(['id' => 777, 'invoiceid' => 1, 'status' => 'Cancelled']);
DPTest::same('cancelled order is refused', 'not_entitled', dp_resolve($deadOrder['tokenId'], 7)['reason']);
Capsule::table('tblorders')->where('id', 777)->delete();

/* ------------------------------------------------------- version binding -- */

DPTest::section('download authorizer — version binding');

// current_version mode: a superseded release must not be downloadable.
$cur = dp_scenario();
$newer = DPDb::version($cur['productId'], '2.0.0');
Capsule::table('mod_digitalproducts_products')->where('id', $cur['productId'])->update(['current_version_id' => $newer]);
DPTest::same('current_version mode refuses the superseded release', 'not_entitled', dp_resolve($cur['tokenId'], 7)['reason']);

// purchase_version mode: locked to the release bought even after a new release.
$lock = dp_scenario(['entitlement' => ['access_mode' => 'purchase_version']]);
$newer2 = DPDb::version($lock['productId'], '3.0.0');
Capsule::table('mod_digitalproducts_products')->where('id', $lock['productId'])->update(['current_version_id' => $newer2]);
DPTest::ok('purchase_version mode still allows the bought release', !empty(dp_resolve($lock['tokenId'], 7)['ok']));
$newerToken = (new TokenService())->issue($lock['entitlementId'], $newer2, 7);
DPTest::same('purchase_version mode refuses the newer release', 'not_entitled', dp_resolve($newerToken['id'], 7)['reason']);

// A version belonging to a different product must never resolve.
$cross = dp_scenario();
$otherProduct = DPDb::product([]);
$otherVersion = DPDb::version($otherProduct, '9.9.9');
$crossToken = (new TokenService())->issue($cross['entitlementId'], $otherVersion, 7);
DPTest::same('version from another product is refused', 'not_entitled', dp_resolve($crossToken['id'], 7)['reason']);

/* ----------------------------------------------------------- rate/limits -- */

DPTest::section('download authorizer — download limit');

$limited = dp_scenario(['product' => ['download_limit' => 2], 'entitlement' => ['download_limit' => 2]]);
$record = dp_resolve($limited['tokenId'], 7)['record'];
$authorizer = new DownloadAuthorizer();
DPTest::ok('first claim succeeds', $authorizer->claim($record));
DPTest::ok('second claim succeeds', $authorizer->claim($record));
DPTest::same('downloads_used reached the limit', 2, (int) DPDb::row('mod_digitalproducts_entitlements', $limited['entitlementId'])->downloads_used);
DPTest::ok('third claim is refused', !$authorizer->claim($record));
DPTest::same('downloads_used is not incremented past the limit', 2, (int) DPDb::row('mod_digitalproducts_entitlements', $limited['entitlementId'])->downloads_used);

$unlimited = dp_scenario(['product' => ['download_limit' => 0], 'entitlement' => ['download_limit' => 0]]);
$recordU = dp_resolve($unlimited['tokenId'], 7)['record'];
for ($i = 0; $i < 5; $i++) $authorizer->claim($recordU);
DPTest::same('limit 0 means unlimited', 5, (int) DPDb::row('mod_digitalproducts_entitlements', $unlimited['entitlementId'])->downloads_used);

exit(DPTest::summary());
