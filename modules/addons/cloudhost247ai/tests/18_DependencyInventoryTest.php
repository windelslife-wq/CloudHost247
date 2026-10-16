<?php
/** Local lockfile inventory parsing and scope guard coverage. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Tools\ToolExecutor;
use function Ch247Ai\Tools\Readers\ch247ai_parse_composer_lock;
use function Ch247Ai\Tools\Readers\ch247ai_parse_npm_lock;

ch247ai_boot();
ch247ai_freeze();
ch247ai_as_super_admin();

T::section('Composer inventory reads only package name/version metadata');
$composer = ch247ai_parse_composer_lock([
    'packages' => [
        ['name' => 'vendor/runtime', 'version' => '1.2.3', 'source' => ['url' => 'https://secret.invalid/repo']],
    ],
    'packages-dev' => [
        ['name' => 'vendor/tester', 'version' => '2.0.0'],
        ['name' => 'bad/missing-version'],
    ],
], 'addons/example', 'addons/example/composer.lock');
T::eq('only valid Composer packages included', 2, count($composer));
T::eq('package name preserved', 'vendor/runtime', $composer[0]['name']);
T::eq('package version preserved', '1.2.3', $composer[0]['version']);
T::eq('development packages marked', true, $composer[1]['development']);
T::ok('source URLs are not returned', strpos(json_encode($composer), 'secret.invalid') === false);

T::section('npm v2 and legacy lockfile formats are supported');
$npmV2 = ch247ai_parse_npm_lock([
    'packages' => [
        '' => ['name' => 'root-app', 'version' => '1.0.0'],
        'node_modules/@scope/runtime' => ['name' => '@scope/runtime', 'version' => '3.4.5', 'dev' => false],
    ],
], 'addons/example', 'addons/example/package-lock.json');
T::eq('npm root package excluded', 1, count($npmV2));
T::eq('scoped npm name preserved', '@scope/runtime', $npmV2[0]['name']);
$npmV1 = ch247ai_parse_npm_lock(['dependencies' => [
    'runtime' => ['version' => '1.0.0', 'dependencies' => ['nested' => ['version' => '2.0.0']]],
]], 'addons/example', 'addons/example/package-lock.json');
T::eq('nested legacy dependencies included', 2, count($npmV1));

T::section('Reader scans bounded local lockfiles and makes no vulnerability claims');
$result = ToolExecutor::execute('admin_copilot', 'read_dependency_inventory', ['limit' => 500]);
T::ok('repository lockfile discovered', in_array('addons/cloudhost247ai/package-lock.json', $result->data['lockfiles_scanned'], true));
T::ok('at least one declared package inventoried', $result->data['package_count'] > 0);
T::eq('returned count respects inventory size', min(500, $result->data['package_count']), $result->data['returned_count']);
T::ok('no CVE or safety assertion is made', strpos($result->data['limitations'], 'does not') !== false && strpos($result->data['limitations'], 'safe') !== false);
T::ok('local path citations attached', count($result->citations) > 0);
$short = ToolExecutor::execute('admin_copilot', 'read_dependency_inventory', ['limit' => 1]);
T::eq('limit is clamped and applied', 1, $short->data['returned_count']);
T::eq('truncation disclosed', true, $short->data['truncated']);

T::finish();
