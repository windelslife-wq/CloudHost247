<?php
/**
 * EntitlementService — grant, revoke, suspend, restore and the guard rails
 * that decide who may receive access at all.
 */
require_once __DIR__ . '/bootstrap.php';

use DigitalProducts\Core\Clock;
use DigitalProducts\Services\EntitlementService;
use WHMCS\Database\Capsule;

DPDb::reset();
$service = new EntitlementService();

DPTest::section('entitlement — grant');
$productId = DPDb::product(['status' => 'active']);
$packageId = (int) DPDb::row('mod_digitalproducts_products', $productId)->whmcs_product_id;
$versionId = DPDb::version($productId, '1.0.0');
Capsule::table('mod_digitalproducts_products')->where('id', $productId)->update(['current_version_id' => $versionId]);
$serviceId = DPDb::service(7, $packageId, ['orderid' => 777, 'domainstatus' => 'Active']);

$ent = $service->grantForService($serviceId, ['order_id' => 777]);
DPTest::ok('active service is granted', $ent !== null);
DPTest::same('grant is bound to the client', 7, (int) $ent->client_id);
DPTest::same('grant records the bought release', $versionId, (int) $ent->purchase_version_id);

$again = $service->grantForService($serviceId, ['order_id' => 777]);
DPTest::same('grant is idempotent', (int) $ent->id, (int) $again->id);
DPTest::same('grant does not duplicate rows', 1, Capsule::table('mod_digitalproducts_entitlements')->count());

DPTest::section('entitlement — guards');
$pendingId = DPDb::service(8, $packageId, ['orderid' => 778, 'domainstatus' => 'Pending']);
DPTest::ok('pending service is not granted', $service->grantForService($pendingId) === null);
DPTest::same('pending service creates no row', 1, Capsule::table('mod_digitalproducts_entitlements')->count());

$cancelledId = DPDb::service(9, $packageId, ['orderid' => 779, 'domainstatus' => 'Cancelled']);
DPTest::ok('cancelled service is not granted', $service->grantForService($cancelledId) === null);

$draftProduct = DPDb::product(['status' => 'draft']);
$draftPackage = (int) DPDb::row('mod_digitalproducts_products', $draftProduct)->whmcs_product_id;
$draftService = DPDb::service(10, $draftPackage, ['domainstatus' => 'Active']);
DPTest::ok('draft product is not granted', $service->grantForService($draftService) === null);

$unpublished = DPDb::product(['status' => 'active']);
$unpublishedPackage = (int) DPDb::row('mod_digitalproducts_products', $unpublished)->whmcs_product_id;
$unpublishedService = DPDb::service(11, $unpublishedPackage, ['domainstatus' => 'Active']);
DPTest::ok('product with no published release is not granted', $service->grantForService($unpublishedService) === null);

$unlinkedPackage = 4242;
$unlinkedService = DPDb::service(12, $unlinkedPackage, ['domainstatus' => 'Active']);
DPTest::ok('service on an unlinked package is not granted', $service->grantForService($unlinkedService) === null);

DPTest::section('entitlement — lifecycle');
$service->suspendByService($serviceId);
DPTest::same('suspend moves the entitlement to suspended', 'suspended', DPDb::row('mod_digitalproducts_entitlements', $ent->id)->status);
$service->restoreByService($serviceId);
DPTest::same('unsuspend restores the entitlement', 'active', DPDb::row('mod_digitalproducts_entitlements', $ent->id)->status);

$service->revokeByService($serviceId, 'service_terminated');
DPTest::same('terminate revokes the entitlement', 'revoked', DPDb::row('mod_digitalproducts_entitlements', $ent->id)->status);
DPTest::same('revocation records its reason', 'service_terminated', DPDb::row('mod_digitalproducts_entitlements', $ent->id)->revoked_reason);

// A later payment must re-activate a previously revoked entitlement.
$reactivated = $service->grantForService($serviceId, ['order_id' => 777]);
DPTest::same('repayment reactivates the entitlement', 'active', DPDb::row('mod_digitalproducts_entitlements', $ent->id)->status);
DPTest::ok('reactivation clears the revoked reason', DPDb::row('mod_digitalproducts_entitlements', $ent->id)->revoked_reason === null);

DPTest::section('entitlement — order level');
$orderProduct = DPDb::product(['status' => 'active']);
$orderPackage = (int) DPDb::row('mod_digitalproducts_products', $orderProduct)->whmcs_product_id;
$orderVersion = DPDb::version($orderProduct, '2.0.0');
Capsule::table('mod_digitalproducts_products')->where('id', $orderProduct)->update(['current_version_id' => $orderVersion]);
$a = DPDb::service(20, $orderPackage, ['orderid' => 900, 'domainstatus' => 'Active']);
$b = DPDb::service(20, $orderPackage, ['orderid' => 900, 'domainstatus' => 'Active']);
$granted = $service->grantForOrder(900);
DPTest::same('grantForOrder grants every service on the order', 2, count($granted));
DPTest::same('order grant created two entitlements', 2, Capsule::table('mod_digitalproducts_entitlements')->where('order_id', 900)->count());
$service->revokeByOrder(900, 'order_refunded');
DPTest::same('refund revokes every entitlement on the order', 0, Capsule::table('mod_digitalproducts_entitlements')->where('order_id', 900)->where('status', 'active')->count());

DPTest::section('entitlement — download counter');
$service->resetCounter($ent->id);
Capsule::table('mod_digitalproducts_entitlements')->where('id', $ent->id)->update(['downloads_used' => 9]);
DPTest::same('counter is used', 9, (int) DPDb::row('mod_digitalproducts_entitlements', $ent->id)->downloads_used);
$service->resetCounter($ent->id);
DPTest::same('admin reset clears the counter', 0, (int) DPDb::row('mod_digitalproducts_entitlements', $ent->id)->downloads_used);

DPTest::section('entitlement — audit trail');
DPTest::ok('grant wrote an audit row', Capsule::table('mod_digitalproducts_audit')->where('action', 'entitlement.granted')->exists());
DPTest::ok('audit rows carry a correlation id', Capsule::table('mod_digitalproducts_audit')->where('correlation_id', '!=', '')->exists());

exit(DPTest::summary());
