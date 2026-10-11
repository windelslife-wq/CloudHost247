<?php
/**
 * D-6 (option b): global license-issuance defaults.
 *
 * Licenses used to be issued with unlimited activations because nothing ever
 * supplied domain_limit/activations_limit (G-4). The addon settings now carry
 * license_default_domain_limit / license_default_activations_limit (0 =
 * unlimited), EntitlementService stamps them onto every license it issues,
 * and the admin settings screen manages them.
 */
require_once __DIR__ . '/bootstrap.php';

use DigitalProducts\Core\Settings;
use DigitalProducts\Core\ValidationException;
use DigitalProducts\License;
use DigitalProducts\Services\EntitlementService;
use WHMCS\Database\Capsule;

DPDb::reset();
Settings::clearCache();
putenv('DIGITALPRODUCTS_ENCRYPTION_KEY=test-only-key-abc');
$service = new EntitlementService();
$lic = new License();

function dpGrantableProduct()
{
    $productId = DPDb::product(['status' => 'active']);
    $packageId = (int) DPDb::row('mod_digitalproducts_products', $productId)->whmcs_product_id;
    $versionId = DPDb::version($productId, '1.0.0');
    Capsule::table('mod_digitalproducts_products')->where('id', $productId)->update(['current_version_id' => $versionId]);
    return [$productId, $packageId];
}

DPTest::section('license defaults — out of the box');
DPTest::same('domain default is unlimited (0)', 0, Settings::int('license_default_domain_limit'));
DPTest::same('activations default is unlimited (0)', 0, Settings::int('license_default_activations_limit'));

DPTest::section('license defaults — issuance without caps');
list(, $packageId) = dpGrantableProduct();
$serviceId = DPDb::service(21, $packageId, ['orderid' => 771, 'domainstatus' => 'Active']);
$ent = $service->grantForService($serviceId, ['order_id' => 771]);
DPTest::ok('service is granted', $ent !== null);
$row = $lic->getLicenseByService($serviceId);
DPTest::ok('grant issues a license', $row !== null);
DPTest::same('issued row stores domain_limit 0', 0, (int) $row->domain_limit);
DPTest::same('issued row stores activations_limit 0', 0, (int) $row->activations_limit);
DPTest::same('issued row stores activation_limit 0', 0, (int) $row->activation_limit);
$openKey = $lic->displayKey($row);

DPTest::section('license defaults — unlimited stays unlimited');
DPTest::ok('first extra activation succeeds', !empty($lic->activateLicense($openKey, 'a.example.test')['success']));
DPTest::ok('second extra activation succeeds', !empty($lic->activateLicense($openKey, 'b.example.test')['success']));
DPTest::ok('third extra activation succeeds', !empty($lic->activateLicense($openKey, 'c.example.test')['success']));
$openRow = $lic->getLicenseByService($serviceId);
DPTest::same(
    'service domain plus three activations are all bound',
    '["client.example.test","a.example.test","b.example.test","c.example.test"]',
    $openRow->domains
);

DPTest::section('license defaults — operator sets caps');
Settings::set('license_default_domain_limit', '2');
Settings::set('license_default_activations_limit', '5');
list(, $packageId2) = dpGrantableProduct();
$serviceId2 = DPDb::service(22, $packageId2, ['orderid' => 772, 'domainstatus' => 'Active']);
$ent2 = $service->grantForService($serviceId2, ['order_id' => 772]);
DPTest::ok('service is granted under caps', $ent2 !== null);
$cappedRow = $lic->getLicenseByService($serviceId2);
DPTest::same('issued row stores the domain default', 2, (int) $cappedRow->domain_limit);
DPTest::same('issued row stores the activations default', 5, (int) $cappedRow->activations_limit);
DPTest::same('issued row mirrors activation_limit', 5, (int) $cappedRow->activation_limit);
$cappedKey = $lic->displayKey($cappedRow);

DPTest::section('license defaults — enforcement end to end');
DPTest::same('the service domain is pre-bound at issuance', '["client.example.test"]', $cappedRow->domains);
DPTest::ok('activation within the default cap succeeds', !empty($lic->activateLicense($cappedKey, 'shop.example.test')['success']));
$third = $lic->activateLicense($cappedKey, 'extra.example.test');
DPTest::ok('activation past the default cap is refused', empty($third['success']));
DPTest::same('refusal names the limit', 'Activation limit reached.', $third['error']);
DPTest::same('refused activation keeps two domains', 2, count(json_decode($lic->getLicenseByService($serviceId2)->domains, true)));
DPTest::same('refused activation keeps the count at two', 2, (int) $lic->getLicenseByService($serviceId2)->activations_count);

DPTest::section('license defaults — explicit values still win');
$explicitKey = $lic->generateLicense(['product_id' => 9991, 'service_id' => 901, 'client_id' => 23, 'domain_limit' => 10, 'activation_limit' => 9]);
$explicitRow = $lic->getLicenseByService(901);
DPTest::same('explicit domain_limit survives the defaults', 10, (int) $explicitRow->domain_limit);
DPTest::same('explicit activation_limit survives the defaults', 9, (int) $explicitRow->activation_limit);
DPTest::same('explicit activations_limit mirrors the singular key', 9, (int) $explicitRow->activations_limit);
$bareKey = $lic->generateLicense(['product_id' => 9992, 'service_id' => 902, 'client_id' => 23]);
DPTest::same('direct issuance without limits stays unlimited', 0, (int) $lic->getLicenseByService(902)->domain_limit);

DPTest::section('license defaults — validation');
$normalised = Settings::validate(['license_default_domain_limit' => '-3', 'license_default_activations_limit' => 'abc']);
DPTest::same('a negative domain default normalises to 0', 0, $normalised['license_default_domain_limit']);
DPTest::same('a non-numeric activations default normalises to 0', 0, $normalised['license_default_activations_limit']);
$kept = Settings::validate(['license_default_domain_limit' => '7', 'license_default_activations_limit' => 4]);
DPTest::same('a valid domain cap passes through', 7, $kept['license_default_domain_limit']);
DPTest::same('a valid activations cap passes through', 4, $kept['license_default_activations_limit']);
DPTest::throws('unrelated validation still rejects a bad access mode', ValidationException::class, function () {
    Settings::validate(['access_mode' => 'nope']);
});

DPTest::section('license defaults — admin wiring (static)');
$admin = (string) file_get_contents(dirname(__DIR__) . '/lib/Admin.php');
DPTest::ok('admin save path stores the domain default', strpos($admin, "'license_default_domain_limit' => max(0") !== false);
DPTest::ok('admin save path stores the activations default', strpos($admin, "'license_default_activations_limit' => max(0") !== false);
DPTest::ok('admin form renders the domain field', strpos($admin, 'name="license_default_domain_limit"') !== false);
DPTest::ok('admin form renders the activations field', strpos($admin, 'name="license_default_activations_limit"') !== false);

DPTest::section('license defaults — no retroactive capping');
$grandfathered = $lic->getLicenseByService($serviceId);
DPTest::same('a pre-existing license keeps domain_limit 0 after defaults change', 0, (int) $grandfathered->domain_limit);
DPTest::ok('a pre-existing license still activates past the new default', !empty($lic->activateLicense($openKey, 'late.example.test')['success']));

exit(DPTest::summary());
