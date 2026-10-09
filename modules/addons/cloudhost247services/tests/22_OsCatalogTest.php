<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeInfrastructureProvider.php';

use Chs\Core\Db;
use Chs\Core\DuplicateOperationException;
use Chs\Core\NotFoundException;
use Chs\Core\Secrets;
use Chs\Core\ValidationException;
use Chs\Services\OsCatalogService;

$gateway = chs_boot();
chs_freeze();
chs_seed_clients($gateway);
chs_seed_server_products($gateway);

T::section('OsCatalog: seeded catalog');
$catalog = new OsCatalogService();
$oses = $catalog->listAdmin();
T::eq('12 OSes seeded', 12, count($oses));
T::ok('Ubuntu seeded', (bool) $catalog->getOsBySlug('ubuntu'));
T::ok('openSUSE seeded', (bool) $catalog->getOsBySlug('opensuse'));
$ubuntu = $catalog->getOsBySlug('ubuntu');
$versions = $catalog->versions((int) $ubuntu['id']);
T::eq('Ubuntu has 3 versions', 3, count($versions));
T::eq('one default version', 1, count(array_filter($versions, function ($v) {
    return (int) $v['is_default'] === 1;
})));
$eol = array_values(array_filter($versions, function ($v) {
    return $v['status'] === 'EOL';
}));
T::ok('EOL version present (lifecycle seed)', count($eol) === 1);

T::section('OsCatalog: OS CRUD');
$osId = $catalog->saveOs(0, [
    'name' => 'Rocky Test', 'slug' => 'rocky-test', 'vendor' => 'Test',
    'description' => 'temp', 'logo_url' => 'x.svg', 'status' => 'ACTIVE',
    'sort_order' => 5, 'is_vps_supported' => 1,
]);
T::ok('OS created', $osId > 0);
T::throws('duplicate slug refused', function () use ($catalog) {
    $catalog->saveOs(0, ['name' => 'Rocky Again', 'slug' => 'rocky-test', 'status' => 'ACTIVE']);
}, DuplicateOperationException::class);
T::throws('empty name refused', function () use ($catalog) {
    $catalog->saveOs(0, ['name' => '', 'slug' => 'x', 'status' => 'ACTIVE']);
}, ValidationException::class);
T::throws('bad status refused', function () use ($catalog) {
    $catalog->saveOs(0, ['name' => 'X', 'slug' => 'x-y', 'status' => 'NOPE']);
}, ValidationException::class);
$catalog->saveOs($osId, ['name' => 'Rocky Test 2', 'slug' => 'rocky-test', 'status' => 'DISABLED', 'is_dedicated_supported' => 1]);
$reloaded = $catalog->findOs($osId);
T::eq('OS updated', 'Rocky Test 2', $reloaded['name']);
T::eq('OS disabled', 'DISABLED', $reloaded['status']);
T::eq('dedicated flag on', 1, (int) $reloaded['is_dedicated_supported']);
T::eq('vps flag off', 0, (int) $reloaded['is_vps_supported']);

T::section('OsCatalog: safe delete');
T::throws('delete refused with versions', function () use ($catalog, $ubuntu) {
    $catalog->deleteOs((int) $ubuntu['id']);
}, DuplicateOperationException::class);
T::ok('delete works without versions', $catalog->deleteOs($osId));
T::ok('deleted OS gone', !$catalog->findOs($osId));

T::section('OsCatalog: versions CRUD + lifecycle');
$debian = $catalog->getOsBySlug('debian');
$vId = $catalog->saveVersion((int) $debian['id'], 0, [
    'version' => '99', 'display_name' => 'Debian 99', 'architectures' => ['x86_64', 'arm64'],
    'status' => 'ACTIVE', 'is_default' => 1, 'is_lts' => 1, 'release_date' => '2026-01-01',
]);
T::ok('version created', $vId > 0);
$row = $catalog->versionRow($vId);
T::eq('architectures stored', ['x86_64', 'arm64'], $row['architectures']);
T::eq('new version is the default', 1, (int) $row['is_default']);
T::eq('previous default cleared', 0, (int) $catalog->versionRow(
    Db::first('operating_system_versions', ['operating_system_id' => (int) $debian['id'], 'version' => '13'])['id']
)['is_default']);
T::throws('duplicate version refused', function () use ($catalog, $debian) {
    $catalog->saveVersion((int) $debian['id'], 0, ['version' => '99', 'display_name' => 'Dup', 'architectures' => ['x86_64'], 'status' => 'ACTIVE']);
}, DuplicateOperationException::class);
T::throws('no architecture refused', function () use ($catalog, $debian) {
    $catalog->saveVersion((int) $debian['id'], 0, ['version' => '98', 'display_name' => 'X', 'architectures' => [], 'status' => 'ACTIVE']);
}, ValidationException::class);
T::throws('bogus architecture refused', function () use ($catalog, $debian) {
    $catalog->saveVersion((int) $debian['id'], 0, ['version' => '97', 'display_name' => 'X', 'architectures' => ['mips'], 'status' => 'ACTIVE']);
}, ValidationException::class);
$catalog->retireVersion($vId);
T::eq('retired = EOL', 'EOL', $catalog->versionRow($vId)['status']);
T::ok('EOL not selectable', !in_array($catalog->versionRow($vId)['status'], OsCatalogService::SELECTABLE_VERSION_STATUSES, true));
$catalog->archiveVersion($vId);
T::eq('archived', 'ARCHIVED', $catalog->versionRow($vId)['status']);

T::section('OsCatalog: image mapping validation');
$infra = chs_seed_infra();
T::throws('image without provider refused', function () use ($catalog, $infra) {
    $catalog->saveImage(0, [
        'provider_id' => 0, 'operating_system_version_id' => $infra['version_id'],
        'architecture' => 'x86_64', 'provider_image_id' => 'x',
    ]);
}, ValidationException::class);
T::throws('image without image id refused', function () use ($catalog, $infra) {
    $catalog->saveImage(0, [
        'provider_id' => $infra['provider_id'], 'operating_system_version_id' => $infra['version_id'],
        'architecture' => 'x86_64',
    ]);
}, ValidationException::class);
T::throws('arch outside version support refused', function () use ($catalog, $infra) {
    // Arch (rolling) supports x86_64 only
    $arch = $catalog->getOsBySlug('arch');
    $archV = Db::first('operating_system_versions', ['operating_system_id' => (int) $arch['id']]);
    $catalog->saveImage(0, [
        'provider_id' => $infra['provider_id'], 'operating_system_version_id' => (int) $archV['id'],
        'architecture' => 'arm64', 'provider_image_id' => 'x',
    ]);
}, ValidationException::class);
T::throws('region from another provider refused', function () use ($catalog, $infra) {
    $other = Db::insert('infrastructure_providers', [
        'name' => 'Other', 'slug' => 'other', 'type' => 'http', 'base_url' => 'https://other.example.test',
        'endpoints' => '{}', 'capabilities' => '{}', 'is_enabled' => 1, 'is_default' => 0,
        'credentials_enc' => '', 'created_at' => \Chs\Core\Clock::now(), 'updated_at' => \Chs\Core\Clock::now(),
    ]);
    $catalog->saveImage(0, [
        'provider_id' => $other, 'operating_system_version_id' => $infra['version_id'],
        'architecture' => 'x86_64', 'provider_image_id' => 'x', 'region_id' => $infra['region_id'],
    ]);
}, ValidationException::class);
$imgId = $catalog->saveImage(0, [
    'provider_id' => $infra['provider_id'], 'operating_system_version_id' => $infra['version_id'],
    'architecture' => 'x86_64', 'provider_image_id' => 'img-ubuntu-2404-b', 'region_id' => 0,
]);
T::ok('image created', $imgId > 0);
T::eq('new image starts disabled', 'disabled', $catalog->imageRow($imgId)['status']);

T::section('OsCatalog: image enable requires configuration + passed test');
// The seeded provider row has no sealed credentials → its HTTP provider is
// not configured → enabling must fail closed.
T::throws('enable refused without configured provider', function () use ($catalog, $imgId) {
    $catalog->setImageStatus($imgId, 'active');
}, ValidationException::class);
// Wire a configured fake provider via the seam and test the image.
$fake = chs_fake_infra_provider($infra['provider_id']);
$fake->images['img-ubuntu-2404-b'] = ['id' => 'img-ubuntu-2404-b', 'name' => 'Ubuntu 24.04', 'architecture' => 'x86_64', 'region' => ''];
$result = $catalog->testImage($imgId);
T::eq('test ok', 'ok', $result['status']);
// The registry used inside OsCatalogService is a fresh instance — the static
// seam applies to it too.
T::ok('image enabled after passed test', $catalog->setImageStatus($imgId, 'active'));
T::eq('image active', 'active', $catalog->imageRow($imgId)['status']);
$missing = $catalog->saveImage(0, [
    'provider_id' => $infra['provider_id'], 'operating_system_version_id' => $infra['version_id'],
    'architecture' => 'arm64', 'provider_image_id' => 'img-not-at-provider',
]);
$result2 = $catalog->testImage($missing);
T::eq('test fails for missing image', 'failed', $result2['status']);
T::throws('enable refused after failed test', function () use ($catalog, $missing) {
    $catalog->setImageStatus($missing, 'active');
}, ValidationException::class);

T::section('OsCatalog: delete image safety');
$jobId = Db::insert('provisioning_jobs', [
    'job_key' => 'test:img-ref', 'type' => 'PROVISION', 'client_id' => 11,
    'module_server_id' => null, 'hosting_id' => null, 'invoice_id' => null,
    'provider_id' => $infra['provider_id'], 'os_image_id' => $imgId,
    'operating_system_version_id' => $infra['version_id'], 'architecture' => 'x86_64',
    'region_id' => null, 'hostname' => 'h.example.test', 'ssh_key_id' => null,
    'action' => '', 'status' => 'FAILED', 'stage' => '', 'attempts' => 1, 'max_attempts' => 5,
    'provider_server_id' => '', 'ip_address' => '', 'error_code' => 'x', 'error_message' => 'x',
    'retryable' => 0, 'logs' => '[]', 'correlation_id' => 'c', 'created_at' => \Chs\Core\Clock::now(),
    'updated_at' => \Chs\Core\Clock::now(),
]);
T::throws('delete refused while jobs reference image', function () use ($catalog, $imgId) {
    $catalog->deleteImage($imgId);
}, DuplicateOperationException::class);
Db::delete('provisioning_jobs', ['id' => $jobId]);
T::ok('image deleted once unreferenced', $catalog->deleteImage($imgId));

T::section('OsCatalog: availability matrix');
$matrix = $catalog->catalogFor('vps', 0, 0, '');
$names = array_map(function ($o) {
    return $o['slug'];
}, $matrix);
T::ok('Ubuntu in vps matrix', in_array('ubuntu', $names, true));
T::ok('EOL-only filtering: every listed version selectable', (function () use ($matrix) {
    foreach ($matrix as $os) {
        foreach ($os['versions'] as $v) {
            if (!in_array($v['display_name'], ['Ubuntu 24.04 LTS', 'Ubuntu 22.04 LTS'], true)) {
                return false;
            }
        }
    }
    return true;
})());
// arm64 filter: only images mapped for arm64 appear
$matrixArm = $catalog->catalogFor('vps', 0, 0, 'arm64');
T::ok('arm64 matrix excludes unmapped arch', (function () use ($matrixArm) {
    foreach ($matrixArm as $os) {
        foreach ($os['versions'] as $v) {
            if (!isset($v['architectures']['arm64'])) {
                return false;
            }
        }
    }
    return true;
})());
// region filter: image is region-scoped to Frankfurt
$matrixOtherRegion = $catalog->catalogFor('vps', 0, 9999, '');
T::eq('no images in unknown region', [], $matrixOtherRegion);
// dedicated type: alpine is not dedicated-supported → absent
$matrixDedi = $catalog->catalogFor('dedicated', 0, 0, '');
T::ok('alpine excluded from dedicated', !in_array('alpine', array_map(function ($o) {
    return $o['slug'];
}, $matrixDedi), true));

T::section('OsCatalog: product rules + configurationFor');
$catalog->saveProductRule(101, ['server_type' => 'vps', 'provider_id' => $infra['provider_id'], 'region_id' => 0]);
$rule = $catalog->ruleForProduct(101);
T::eq('rule saved', 'vps', $rule['server_type']);
T::eq('rule provider pin', $infra['provider_id'], (int) $rule['provider_id']);
$config = $catalog->configurationFor(101, 0, '');
T::eq('config product', 'Cloud VPS', $config['product']['name']);
T::ok('config has regions', count($config['regions']) >= 1);
T::ok('config has OSes', count($config['operatingSystems']) >= 1);
T::ok('config has architectures', in_array('x86_64', $config['architectures'], true));
T::throws('configurationFor rejects non-server product', function () use ($catalog) {
    $catalog->configurationFor(103);
}, NotFoundException::class);
T::throws('rule for non-server product refused', function () use ($catalog) {
    $catalog->saveProductRule(103, ['server_type' => 'vps']);
}, ValidationException::class);
T::ok('rule deleted', $catalog->deleteProductRule(101));
T::ok('no rule after delete', !$catalog->ruleForProduct(101));

T::finish();
