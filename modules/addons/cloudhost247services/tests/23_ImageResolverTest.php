<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Providers\Infrastructure\ProviderFailure;
use Chs\Services\ImageResolverService;

$gateway = chs_boot();
chs_freeze();
chs_seed_clients($gateway);

T::section('ImageResolver: resolution');
$infra = chs_seed_infra();
$resolver = new ImageResolverService();

$image = $resolver->resolve($infra['version_id'], 'x86_64');
T::eq('resolves seeded image', 'img-ubuntu-2404', $image['provider_image_id']);
T::eq('resolver returns provider id', $infra['provider_id'], (int) $image['provider_id']);
T::eq('provider ref is image id', 'img-ubuntu-2404', $resolver->providerImageRef($image));

T::section('ImageResolver: region + provider scoping');
T::throws('wrong architecture → IMAGE_UNAVAILABLE', function () use ($resolver, $infra) {
    $resolver->resolve($infra['version_id'], 'ppc64le');
}, ProviderFailure::class);
try {
    $resolver->resolve($infra['version_id'], 'arm64');
    T::ok('arm64 unavailable throws', false);
} catch (ProviderFailure $e) {
    T::eq('machine code', 'IMAGE_UNAVAILABLE', $e->machineCode());
    T::ok('permanent', !$e->isRetryable());
}
T::throws('wrong region → IMAGE_UNAVAILABLE', function () use ($resolver, $infra) {
    $resolver->resolve($infra['version_id'], 'x86_64', 0, 9999);
}, ProviderFailure::class);
T::throws('wrong provider → IMAGE_UNAVAILABLE', function () use ($resolver, $infra) {
    $resolver->resolve($infra['version_id'], 'x86_64', 4242);
}, ProviderFailure::class);
$regionAgnostic = $resolver->resolve($infra['version_id'], 'x86_64', 0, $infra['region_id']);
T::eq('selected region resolves', 'img-ubuntu-2404', $regionAgnostic['provider_image_id']);

T::section('ImageResolver: region-specific beats region-agnostic; default provider wins');
$now = Clock::now();
// Second provider (default) + a region-agnostic image on each provider.
$providerB = Db::insert('infrastructure_providers', [
    'name' => 'Cloud B', 'slug' => 'cloud-b', 'type' => 'http',
    'base_url' => 'https://b.example.test', 'endpoints' => '{}',
    'capabilities' => '{}', 'is_enabled' => 1, 'is_default' => 1,
    'credentials_enc' => '', 'created_at' => $now, 'updated_at' => $now,
]);
Db::update('infrastructure_providers', ['id' => $infra['provider_id']], ['is_default' => 0]);
Db::insert('server_os_images', [
    'provider_id' => $providerB, 'operating_system_version_id' => $infra['version_id'],
    'provider_image_id' => 'img-b-agnostic', 'provider_template_id' => '',
    'architecture' => 'x86_64', 'region_id' => null, 'status' => 'active',
    'metadata' => '{}', 'created_at' => $now, 'updated_at' => $now,
]);
Db::insert('server_os_images', [
    'provider_id' => $infra['provider_id'], 'operating_system_version_id' => $infra['version_id'],
    'provider_image_id' => 'img-a-agnostic', 'provider_template_id' => '',
    'architecture' => 'x86_64', 'region_id' => null, 'status' => 'active',
    'metadata' => '{}', 'created_at' => $now, 'updated_at' => $now,
]);
// No region + only region-agnostic images left: the default provider wins.
Db::update('server_os_images', ['id' => $infra['image_id']], ['status' => 'disabled']);
$noRegion = $resolver->resolve($infra['version_id'], 'x86_64');
T::eq('default provider preferred', 'img-b-agnostic', $noRegion['provider_image_id']);
// Frankfurt selected: the region-specific image on A beats the agnostic ones.
Db::update('server_os_images', ['id' => $infra['image_id']], ['status' => 'active']);
$inRegion = $resolver->resolve($infra['version_id'], 'x86_64', 0, $infra['region_id']);
T::eq('region-specific preferred', 'img-ubuntu-2404', $inRegion['provider_image_id']);
// Explicit provider request is honoured even against the default.
$explicit = $resolver->resolve($infra['version_id'], 'x86_64', $providerB);
T::eq('explicit provider honoured', 'img-b-agnostic', $explicit['provider_image_id']);

T::section('ImageResolver: disabled image / disabled provider are not deployable');
Db::update('server_os_images', ['provider_image_id' => 'img-b-agnostic'], ['status' => 'disabled']);
$resolver2 = new ImageResolverService();
$fallback = $resolver2->resolve($infra['version_id'], 'x86_64', 0, $infra['region_id']);
T::eq('disabled image skipped', 'img-ubuntu-2404', $fallback['provider_image_id']);
Db::update('infrastructure_providers', ['id' => $infra['provider_id']], ['is_enabled' => 0]);
T::throws('all providers disabled → unavailable', function () use ($resolver2, $infra) {
    $resolver2->resolve($infra['version_id'], 'x86_64', 0, $infra['region_id']);
}, ProviderFailure::class);
Db::update('infrastructure_providers', ['id' => $infra['provider_id']], ['is_enabled' => 1]);

T::section('ImageResolver: isAvailable');
T::ok('available combination', $resolver->isAvailable($infra['version_id'], 'x86_64'));
T::ok('unavailable combination', !$resolver->isAvailable($infra['version_id'], 'arm64'));

T::finish();
