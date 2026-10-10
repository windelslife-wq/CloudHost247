<?php
/**
 * License service — generation, validation, activation and the boundaries
 * that keep one customer's key from being used as another's.
 */
require_once __DIR__ . '/bootstrap.php';

use DigitalProducts\Core\Clock;
use DigitalProducts\Core\Crypto;
use DigitalProducts\License;
use WHMCS\Database\Capsule;

DPDb::reset();
putenv('DIGITALPRODUCTS_ENCRYPTION_KEY=test-only-key-abc');
$lic = new License();

$productId = DPDb::product(['status' => 'active']);
$productId2 = DPDb::product(['status' => 'active']);

DPTest::section('license — generation');
$key = $lic->generateLicense(['product_id' => $productId, 'service_id' => 55, 'client_id' => 7, 'entitlement_id' => 1, 'domain' => 'Store.Example.test']);
DPTest::ok('a key is returned', is_string($key) && $key !== '');
DPTest::ok('key uses the CloudHost247 prefix', strpos($key, 'CH247-') === 0);

$row = Capsule::table('mod_digitalproducts_licenses')->where('service_id', 55)->first();
DPTest::same('the raw key is not stored', null, $row->license_key);
DPTest::same('a SHA-256 hash is stored', hash('sha256', $key), $row->license_hash);
DPTest::ok('an encrypted copy is stored', !empty($row->license_encrypted));
DPTest::ok('the encrypted copy is not the plaintext', $row->license_encrypted !== $key);
DPTest::same('the stored domain is normalised', '["store.example.test"]', $row->domains);

$again = $lic->generateLicense(['product_id' => $productId, 'service_id' => 55, 'client_id' => 7]);
DPTest::same('generation is idempotent per service', $key, $again);
DPTest::same('no duplicate license rows', 1, Capsule::table('mod_digitalproducts_licenses')->where('service_id', 55)->count());

DPTest::section('license — validation');
$valid = $lic->validateLicense($key);
DPTest::ok('the issued key validates', $valid['valid'] === true);
DPTest::ok('an unknown key is refused', $lic->validateLicense('CH247-ZZZZ-ZZZZ-ZZZZ-ZZZZ')['valid'] === false);
DPTest::ok('an empty key is refused', $lic->validateLicense('')['valid'] === false);
DPTest::ok('an over-long key is refused', $lic->validateLicense(str_repeat('A', 200))['valid'] === false);
DPTest::ok('a near-miss key is refused', $lic->validateLicense(substr($key, 0, -1) . 'X')['valid'] === false);

Capsule::table('mod_digitalproducts_licenses')->where('id', $row->id)->update(['status' => 'suspended']);
DPTest::ok('a suspended key is refused', $lic->validateLicense($key)['valid'] === false);
Capsule::table('mod_digitalproducts_licenses')->where('id', $row->id)->update(['status' => 'active']);

Capsule::table('mod_digitalproducts_licenses')->where('id', $row->id)->update(['expires_at' => date('Y-m-d H:i:s', time() - 10)]);
DPTest::ok('an expired key is refused', $lic->validateLicense($key)['valid'] === false);
DPTest::same('validation marks an expired key expired', 'expired', Capsule::table('mod_digitalproducts_licenses')->where('id', $row->id)->first()->status);
Capsule::table('mod_digitalproducts_licenses')->where('id', $row->id)->update(['status' => 'active', 'expires_at' => null]);

DPTest::section('license — domain binding');
$lic->activateLicense($key, 'https://Shop.Example.test/path');
$row = Capsule::table('mod_digitalproducts_licenses')->where('id', $row->id)->first();
DPTest::same('activation normalises the domain', '["store.example.test","shop.example.test"]', $row->domains);
DPTest::ok('activation is allowed on a bound domain', $lic->validateLicense($key, 'store.example.test')['valid'] === true);
DPTest::ok('validation refuses an unbound domain', $lic->validateLicense($key, 'other.example.test')['valid'] === false);

DPTest::section('license — activation limits');
$limitedKey = $lic->generateLicense(['product_id' => $productId2, 'service_id' => 77, 'client_id' => 8, 'domain_limit' => 2]);
$limitedRow = Capsule::table('mod_digitalproducts_licenses')->where('service_id', 77)->first();
DPTest::same('a new license starts with no activations', 0, (int) $limitedRow->activations_count);

DPTest::ok('first activation succeeds', !empty($lic->activateLicense($limitedKey, 'a.example.test')['success']));
DPTest::same('first activation counts one', 1, (int) Capsule::table('mod_digitalproducts_licenses')->where('id', $limitedRow->id)->first()->activations_count);
DPTest::ok('second activation succeeds', !empty($lic->activateLicense($limitedKey, 'b.example.test')['success']));
DPTest::same('second activation counts two', 2, (int) Capsule::table('mod_digitalproducts_licenses')->where('id', $limitedRow->id)->first()->activations_count);

$third = $lic->activateLicense($limitedKey, 'c.example.test');
DPTest::ok('activation past the domain limit is refused', empty($third['success']));
DPTest::same('refused activation does not add a domain', 2, count(json_decode(Capsule::table('mod_digitalproducts_licenses')->where('id', $limitedRow->id)->first()->domains, true)));
DPTest::same('refused activation does not raise the count', 2, (int) Capsule::table('mod_digitalproducts_licenses')->where('id', $limitedRow->id)->first()->activations_count);

$repeat = $lic->activateLicense($limitedKey, 'a.example.test');
DPTest::ok('re-activating a known domain succeeds', !empty($repeat['success']));
DPTest::ok('re-activation reports already_active', !empty($repeat['already_active']));
DPTest::same('re-activation does not double count', 2, (int) Capsule::table('mod_digitalproducts_licenses')->where('id', $limitedRow->id)->first()->activations_count);
DPTest::ok('activation requires a domain', empty($lic->activateLicense($limitedKey, '')['success']));
DPTest::ok('activation of a refused key fails', empty($lic->activateLicense('CH247-NOPE-NOPE-NOPE-NOPE', 'a.example.test')['success']));

DPTest::section('license — default activation policy');
// Every license the entitlement path issues carries no activation cap, because
// generateLicense() is never given a limit. Documented, not silently changed.
$defaultRow = Capsule::table('mod_digitalproducts_licenses')->where('service_id', 55)->first();
DPTest::same('entitlement-issued licenses have no domain cap by default', 0, (int) $defaultRow->domain_limit);
DPTest::same('entitlement-issued licenses have no activation cap by default', 0, (int) $defaultRow->activation_limit);

DPTest::section('license — legacy plaintext keys');
$legacyId = Capsule::table('mod_digitalproducts_licenses')->insertGetId([
    'product_id' => $productId, 'service_id' => 99, 'client_id' => 9,
    'license_key' => 'CH247-LEGACY-PLAIN-TEXT-0001', 'license_hash' => hash('sha256', 'CH247-LEGACY-PLAIN-TEXT-0001'),
    'status' => 'active', 'activation_limit' => 0, 'activations_limit' => 0, 'activations_count' => 0, 'domain_limit' => 0,
    'created_at' => Clock::now(), 'updated_at' => Clock::now(),
]);
DPTest::ok('a hashed legacy key still validates', $lic->validateLicense('CH247-LEGACY-PLAIN-TEXT-0001')['valid'] === true);
DPTest::ok('a legacy key without a hash is refused', $lic->validateLicense('CH247-LEGACY-NO-HASH')['valid'] === false);

DPTest::section('license — status changes');
$lic->updateLicenseStatus($row->id, 'suspended');
DPTest::same('status change is persisted', 'suspended', Capsule::table('mod_digitalproducts_licenses')->where('id', $row->id)->first()->status);
try { $lic->updateLicenseStatus($row->id, 'nonsense'); DPTest::ok('an invalid status is rejected', false); }
catch (\Throwable $e) { DPTest::ok('an invalid status is rejected', $e instanceof \InvalidArgumentException); }

DPTest::section('license — crypto');
DPTest::ok('encryption is randomised', Crypto::encrypt('same-value') !== Crypto::encrypt('same-value'));
DPTest::ok('tampered ciphertext yields null', Crypto::decrypt(substr(Crypto::encrypt('value'), 0, -4) . 'AAAA') !== 'value');
DPTest::ok('garbage ciphertext yields null', Crypto::decrypt('not-base64-at-all') === null);

exit(DPTest::summary());
