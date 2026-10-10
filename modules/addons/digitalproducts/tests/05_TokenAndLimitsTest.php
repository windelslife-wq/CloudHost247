<?php
/**
 * Download tokens, the rate limiter, and the single rule that decides which
 * release an entitlement is allowed to download.
 */
require_once __DIR__ . '/bootstrap.php';

use DigitalProducts\Core\Clock;
use DigitalProducts\Core\RateLimiter;
use DigitalProducts\Core\RateLimitException;
use DigitalProducts\Security\DownloadAuthorizer;
use DigitalProducts\Security\TokenService;
use WHMCS\Database\Capsule;

DPDb::reset();
$tokens = new TokenService();

DPTest::section('token — issuance');
$productId = DPDb::product(['status' => 'active', 'download_expiry_hours' => 48]);
$versionId = DPDb::version($productId, '1.0.0');
Capsule::table('mod_digitalproducts_products')->where('id', $productId)->update(['current_version_id' => $versionId]);
$entitlementId = DPDb::entitlement(7, $productId, 1);

$issued = $tokens->issue($entitlementId, $versionId, 7);
DPTest::same('issued token is 64 hex chars', 1, preg_match('/^[a-f0-9]{64}$/', $issued['token']));
DPTest::ok('issued token was not stored in the clear', !Capsule::table('mod_digitalproducts_download_tokens')->where('token_hash', $issued['token'])->exists());
DPTest::ok('a SHA-256 hash was stored instead', Capsule::table('mod_digitalproducts_download_tokens')->where('token_hash', hash('sha256', $issued['token']))->exists());
DPTest::ok('the download url carries the token', strpos($issued['url'], 'token=' . $issued['token']) !== false);
DPTest::ok('the url points at the private endpoint', strpos($issued['url'], '/modules/addons/digitalproducts/download.php') !== false);
DPTest::ok('a default expiry is set', $issued['expires_at'] !== null);

DPTest::section('token — lookup and replay');
$found = $tokens->find($issued['token']);
DPTest::ok('a fresh token resolves', $found !== null);
DPTest::same('lookup binds the entitlement', $entitlementId, (int) $found->entitlement_id);
DPTest::ok('a forged token does not resolve', $tokens->find(str_repeat('a', 64)) === null);
DPTest::ok('a malformed token does not resolve', $tokens->find('not-a-token') === null);
DPTest::ok('an empty token does not resolve', $tokens->find('') === null);

DPTest::ok('single-use tokens are marked single use', (int) $found->single_use === 1);
DPTest::ok('first consume succeeds', $tokens->consume($found->id));
DPTest::ok('second consume is refused (replay)', !$tokens->consume($found->id));
DPTest::ok('a consumed token no longer resolves', $tokens->find($issued['token']) === null);

$multi = $tokens->issue($entitlementId, $versionId, 7, 24, false);
$multiRow = Capsule::table('mod_digitalproducts_download_tokens')->where('token_hash', hash('sha256', $multi['token']))->first();
DPTest::ok('multi-use tokens may be consumed repeatedly', $tokens->consume($multiRow->id) && $tokens->consume($multiRow->id));
DPTest::ok('a multi-use token still resolves after use', $tokens->find($multi['token']) !== null);

DPTest::section('token — expiry');
$expiring = $tokens->issue($entitlementId, $versionId, 7, 0);
DPTest::ok('expiry 0 means no expiry', $expiring['expires_at'] === null);
DPTest::ok('a non-expiring token resolves', $tokens->find($expiring['token']) !== null);

$past = $tokens->issue($entitlementId, $versionId, 7, 1);
Capsule::table('mod_digitalproducts_download_tokens')->where('id', $past['id'])->update(['expires_at' => date('Y-m-d H:i:s', time() - 5)]);
DPTest::ok('an expired token does not resolve', $tokens->find($past['token']) === null);

DPTest::section('token — purge');
$before = Capsule::table('mod_digitalproducts_download_tokens')->count();
$tokens->purge();
$after = Capsule::table('mod_digitalproducts_download_tokens')->count();
DPTest::ok('purge removes expired and spent tokens', $after < $before);
DPTest::ok('purge keeps live tokens', Capsule::table('mod_digitalproducts_download_tokens')->where('id', $expiring['id'])->exists());

DPTest::section('rate limiter');
Clock::freeze('2026-10-10 12:00:00');
DPTest::ok('first hit is allowed', RateLimiter::hit('test.action', 'bucket-a', 3, 60));
DPTest::ok('second hit is allowed', RateLimiter::hit('test.action', 'bucket-a', 3, 60));
DPTest::ok('third hit is allowed', RateLimiter::hit('test.action', 'bucket-a', 3, 60));
DPTest::ok('fourth hit is refused', !RateLimiter::hit('test.action', 'bucket-a', 3, 60));

DPTest::ok('a different bucket is unaffected', RateLimiter::hit('test.action', 'bucket-b', 3, 60));
DPTest::ok('a different action is unaffected', RateLimiter::hit('other.action', 'bucket-a', 3, 60));

Clock::freeze('2026-10-10 12:02:00');
DPTest::ok('the window resets after it elapses', RateLimiter::hit('test.action', 'bucket-a', 3, 60));
Clock::unfreeze();

Clock::freeze('2026-10-10 13:00:00');
DPTest::ok('hitOrFail allows a hit inside the budget', RateLimiter::hit('fail.action', 'fail-bucket', 1, 60));
try { RateLimiter::hitOrFail('fail.action', 'fail-bucket', 1, 60); DPTest::ok('hitOrFail throws when over the limit', false); }
catch (RateLimitException $e) { DPTest::ok('hitOrFail throws when over the limit', true); }
Clock::unfreeze();

DPTest::ok('buckets are keyed without the raw IP', strpos(RateLimiter::bucket(), 'ip:') === 0);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
DPTest::ok('the ip bucket does not contain the address', strpos(RateLimiter::bucket(), '203.0.113.9') === false);
DPTest::same('a client bucket is keyed by id', 'client:42', RateLimiter::bucket(42));

DPTest::section('rate limiter — fails closed');
$live = RateLimiter::hit('probe.action', 'probe-bucket', 100, 60);
Capsule::$pdo->exec('DROP TABLE mod_digitalproducts_rate_limits');
DPTest::ok('a missing rate-limit table fails closed', RateLimiter::hit('probe.action', 'probe-bucket', 100, 60) === false);
Capsule::schema()->create('mod_digitalproducts_rate_limits', function ($t) {
    $t->increments('id'); $t->string('action', 80); $t->string('bucket', 128);
    $t->integer('hits')->unsigned()->default(0); $t->dateTime('window_start');
});

DPTest::section('entitlement — permitted release');
// The rule that decides which release an entitlement may download must have a
// single definition, shared by the client area and the API.
$authorizer = new DownloadAuthorizer();
$currentProduct = DPDb::product(['status' => 'active', 'access_mode' => 'current_version']);
$v1 = DPDb::version($currentProduct, '1.0.0');
$v2 = DPDb::version($currentProduct, '2.0.0');
Capsule::table('mod_digitalproducts_products')->where('id', $currentProduct)->update(['current_version_id' => $v2]);
$curEntitlement = DPDb::entitlement(7, $currentProduct, 1, ['access_mode' => 'current_version', 'purchase_version_id' => $v1]);
DPTest::same('current_version mode follows the product', $v2,
    $authorizer->allowedVersionId(DPDb::row('mod_digitalproducts_entitlements', $curEntitlement),
        DPDb::row('mod_digitalproducts_products', $currentProduct)->current_version_id));

$lockedProduct = DPDb::product(['status' => 'active', 'access_mode' => 'purchase_version']);
$l1 = DPDb::version($lockedProduct, '1.0.0');
$l2 = DPDb::version($lockedProduct, '2.0.0');
Capsule::table('mod_digitalproducts_products')->where('id', $lockedProduct)->update(['current_version_id' => $l2]);
$lockEntitlement = DPDb::entitlement(7, $lockedProduct, 1, ['access_mode' => 'purchase_version', 'purchase_version_id' => $l1]);
DPTest::same('purchase_version mode stays on the bought release', $l1,
    $authorizer->allowedVersionId(DPDb::row('mod_digitalproducts_entitlements', $lockEntitlement),
        DPDb::row('mod_digitalproducts_products', $lockedProduct)->current_version_id));

$broken = DPDb::entitlement(7, $currentProduct, 2, ['access_mode' => 'purchase_version', 'purchase_version_id' => null]);
DPTest::same('a missing purchase id fails closed', null,
    $authorizer->allowedVersionId(DPDb::row('mod_digitalproducts_entitlements', $broken), 123));

// The client area and the API must refuse a requested release that is not the
// permitted one, rather than issuing a link download.php would reject.
DPTest::ok('a superseded release is not permitted under current_version',
    $authorizer->allowedVersionId(DPDb::row('mod_digitalproducts_entitlements', $curEntitlement),
        DPDb::row('mod_digitalproducts_products', $currentProduct)->current_version_id) !== $v1);
DPTest::ok('a newer release is not permitted under purchase_version',
    $authorizer->allowedVersionId(DPDb::row('mod_digitalproducts_entitlements', $lockEntitlement),
        DPDb::row('mod_digitalproducts_products', $lockedProduct)->current_version_id) !== $l2);

exit(DPTest::summary());
