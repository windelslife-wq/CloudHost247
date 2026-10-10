<?php
/**
 * Upload validation against real archives, plus static regression checks that
 * keep the security-relevant wiring from being silently removed.
 */
require_once __DIR__ . '/bootstrap.php';

use DigitalProducts\Core\Settings;
use DigitalProducts\Security\UploadValidator;
use DigitalProducts\Core\ValidationException;
use WHMCS\Database\Capsule;

DPDb::reset();

$root = sys_get_temp_dir() . '/dp-upload-' . bin2hex(random_bytes(3));
@mkdir($root, 0700, true);

/** Build a zip on disk and return its path. */
function dp_zip($path, array $entries)
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) $zip->addFromString($name, $content);
    $zip->close();
    return $path;
}

/** A minimal ZIP whose central directory declares an absurd uncompressed size. */
function dp_zip_bomb($path, $declaredUncompressed)
{
    $name = 'bomb.txt';
    $data = 'small';
    $crc = crc32($data);
    $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, strlen($data), $declaredUncompressed, strlen($name), 0) . $name . $data;
    $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, strlen($data), $declaredUncompressed, strlen($name), 0, 0, 0, 0, 0, 0) . $name;
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
    file_put_contents($path, $local . $central . $end);
    return $path;
}

$validator = new UploadValidator();
$safeFile = $root . '/release.zip';
dp_zip($safeFile, ['plugin/index.php' => '<?php echo 1;', 'plugin/readme.txt' => 'docs']);

DPTest::section('upload — happy path');
$ok = $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'release.zip', 'tmp_name' => $safeFile, 'size' => filesize($safeFile)]);
DPTest::same('a clean archive is accepted', 'zip', $ok['extension']);
DPTest::same('the stored checksum is computed', hash_file('sha256', $safeFile), $ok['sha256']);
DPTest::same('the original name is kept as a basename', 'release.zip', $ok['original_name']);

DPTest::section('upload — filename rules');
$bad = ['../escape.zip' => 'parent traversal', '..\\..\\evil.zip' => 'backslash traversal', 'a/../b.zip' => 'nested traversal',
        "null\0.zip" => 'null byte', 'evil.exe' => 'disallowed extension', 'noext' => 'missing extension',
        '.zip' => 'empty basename', ' script.php ' => 'padded php'];
foreach ($bad as $name => $label) {
    DPTest::throws("{$label} is refused", ValidationException::class, function () use ($validator, $safeFile, $name) {
        $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => $name, 'tmp_name' => $safeFile, 'size' => filesize($safeFile)]);
    });
}

DPTest::section('upload — size and error rules');
DPTest::throws('an upload error is refused', ValidationException::class, function () use ($validator, $safeFile) {
    $validator->validate(['error' => UPLOAD_ERR_PARTIAL, 'name' => 'a.zip', 'tmp_name' => $safeFile, 'size' => 10]);
});
DPTest::throws('an empty file is refused', ValidationException::class, function () use ($validator, $safeFile) {
    $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'a.zip', 'tmp_name' => $safeFile, 'size' => 0]);
});
DPTest::throws('an oversized file is refused', ValidationException::class, function () use ($validator, $safeFile) {
    $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'a.zip', 'tmp_name' => $safeFile, 'size' => 99999999999]);
});
DPTest::throws('a missing file is refused', ValidationException::class, function () use ($validator, $root) {
    $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'a.zip', 'tmp_name' => $root . '/nope.zip', 'size' => 10]);
});

DPTest::section('upload — archive contents');
$traversal = dp_zip($root . '/traversal.zip', ['../../evil.php' => 'x']);
DPTest::throws('parent traversal inside a zip is refused', ValidationException::class, function () use ($validator, $traversal) {
    $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'traversal.zip', 'tmp_name' => $traversal, 'size' => filesize($traversal)]);
});

$absolute = dp_zip($root . '/absolute.zip', ['/etc/cron.d/evil' => 'x']);
DPTest::throws('an absolute path inside a zip is refused', ValidationException::class, function () use ($validator, $absolute) {
    $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'absolute.zip', 'tmp_name' => $absolute, 'size' => filesize($absolute)]);
});

$windows = dp_zip($root . '/windows.zip', ['C:/windows/evil.php' => 'x']);
DPTest::throws('a windows drive path inside a zip is refused', ValidationException::class, function () use ($validator, $windows) {
    $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'windows.zip', 'tmp_name' => $windows, 'size' => filesize($windows)]);
});

// The symlink guard reads ZipArchive::statIndex()['external_attributes'], which
// this PHP build does not expose. Assert it only where it is observable, and
// say so loudly where it is not — never claim a defence we cannot see.
$symlink = $root . '/symlink.zip';
$zip = new ZipArchive();
$zip->open($symlink, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('link.txt', '/etc/passwd');
$zip->setExternalAttributesName('link.txt', ZipArchive::OPSYS_UNIX, (0120777 << 16));
$zip->close();
$probe = new ZipArchive();
$probe->open($symlink);
$stat = $probe->statIndex(0);
if (isset($stat['external_attributes'])) {
    DPTest::throws('a symlink entry inside a zip is refused', ValidationException::class, function () use ($validator, $symlink) {
        $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'symlink.zip', 'tmp_name' => $symlink, 'size' => filesize($symlink)]);
    });
} else {
    echo "SKIP symlink guard: statIndex() exposes no external_attributes on this PHP build\n";
}
$probe->close();

$bomb = dp_zip_bomb($root . '/bomb.zip', 2 * 1024 * 1024 * 1024);
DPTest::throws('a declared zip bomb is refused', ValidationException::class, function () use ($validator, $bomb) {
    $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'bomb.zip', 'tmp_name' => $bomb, 'size' => filesize($bomb)]);
});

$corrupt = $root . '/corrupt.zip';
file_put_contents($corrupt, 'PK\x03\x04 this is not a real zip');
DPTest::throws('a corrupt zip is refused', ValidationException::class, function () use ($validator, $corrupt) {
    $validator->validate(['error' => UPLOAD_ERR_OK, 'name' => 'corrupt.zip', 'tmp_name' => $corrupt, 'size' => filesize($corrupt)]);
});

DPTest::section('settings — extension allowlist');
Settings::override('allowed_extensions', 'zip, tar.gz, PDF, .js, <script>, ../evil, x/y');
$clean = Settings::extensions();
DPTest::same('hostile tokens are dropped', ['zip', 'tar.gz', 'pdf', 'js'], $clean);
Settings::clearCache();

/* -------------------------------------------------------- static checks -- */

DPTest::section('static — security wiring');
$module = dirname(__DIR__);
$read = function ($path) use ($module) { return (string) file_get_contents($module . '/' . $path); };

$client = $read('lib/Client.php');
$api = $read('api.php');
$download = $read('download.php');

DPTest::ok('the client area resolves the release through the shared rule', strpos($client, 'allowedVersionId') !== false);
DPTest::ok('the API resolves the release through the shared rule', strpos($api, 'allowedVersionId') !== false);
DPTest::ok('the client area no longer trusts a requested version id', strpos($client, 'access_mode === \'purchase_version\' ? (int)') === false);
DPTest::ok('the API no longer trusts a requested version id', strpos($api, 'access_mode === \'purchase_version\' ? (int)') === false);

DPTest::ok('the API rejects a token passed in the query string', strpos($api, 'query_auth_not_allowed') !== false);
DPTest::ok('the API emits no wildcard CORS header', strpos($api, 'Access-Control-Allow-Origin') === false);
DPTest::ok('the API sets nosniff', strpos($api, 'X-Content-Type-Options: nosniff') !== false);

DPTest::ok('download.php validates the token shape first', strpos($download, "preg_match('/^[a-f0-9]{64}\$/i'") !== false);
DPTest::ok('download.php consumes the token before claiming the limit',
    strpos($download, '$tokens->consume(') < strpos($download, '$authorizer->claim('));
DPTest::ok('download.php streams privately with nosniff', strpos($download, 'X-Content-Type-Options: nosniff') !== false);
DPTest::ok('download.php refuses to cache the payload', strpos($download, 'no-store') !== false);

DPTest::section('static — output escaping');
$downloadsTpl = $read('templates/client/downloads.tpl');
$productTpl = $read('templates/client/product.tpl');
// `|escape` may be followed by further modifiers (nl2br, substr), so match the
// opening of the modifier chain rather than a whole tag.
foreach (['product_name', 'current_version', 'purchased_version', 'purchase_date', 'changelog', 'checksum', 'license_key'] as $field) {
    DPTest::ok("downloads.tpl escapes item.{$field}", strpos($downloadsTpl, '{$item.' . $field . '|escape') !== false);
}
foreach (['version', 'checksum_sha256', 'release_notes', 'min_php', 'required_extensions'] as $field) {
    DPTest::ok("product.tpl escapes version.{$field}", strpos($productTpl, '{$version.' . $field) !== false && strpos($productTpl, '|escape') !== false);
}
DPTest::ok('product.tpl escapes the product description', strpos($productTpl, '{$product.description|escape') !== false);
DPTest::ok('product.tpl escapes the license key', strpos($productTpl, '{$license.key|escape}') !== false);

$admin = $read('lib/Admin.php');
DPTest::ok('Admin.php defines an escaping helper', strpos($admin, 'function e($value)') !== false);
DPTest::ok('Admin.php escapes the license prefix', strpos($admin, '$this->e($r->license_prefix') !== false);
// A legacy 'disabled' release must be publishable, or it can never be served.
DPTest::ok('Admin.php offers Publish for every non-live, non-retired release',
    strpos($admin, "if (\$v->status !== 'active' && \$v->status !== 'retired')") !== false);
DPTest::ok('Admin.php no longer limits Publish to drafts only',
    strpos($admin, "if (\$v->status === 'draft')") === false);

DPTest::section('static — no raw SQL from user input');
foreach ([['lib/Client.php', 'Client'], ['lib/Core.php', 'Core'], ['lib/License.php', 'License'],
          ['lib/Services/EntitlementService.php', 'EntitlementService'], ['lib/Services/VersionService.php', 'VersionService']] as [$file, $label]) {
    $source = $read($file);
    DPTest::ok("{$label} builds no SQL from raw request data", !preg_match('/whereRaw|selectRaw|orderByRaw|DB::raw|Capsule::raw\(\s*\$/', $source));
}
DPTest::ok('EntitlementService no longer concatenates notes in SQL', strpos($read('lib/Services/EntitlementService.php'), 'CONCAT(') === false);

DPTest::section('static — endpoint hygiene');
DPTest::ok('download.php requires the WHMCS bootstrap', strpos($download, "require_once __DIR__ . '/../../../init.php'") !== false);
DPTest::ok('api.php requires the WHMCS bootstrap', strpos($api, "require_once __DIR__ . '/../../../init.php'") !== false);
DPTest::ok('cron guards on the WHMCS bootstrap being present', strpos($read('cron/digitalproducts.php'), 'is_file($init)') !== false);

/** php-wasm has no proc_open, so clean up in PHP rather than shelling out. */
function dp_rmtree($dir)
{
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
        $path = $dir . '/' . $entry;
        is_dir($path) ? dp_rmtree($path) : @unlink($path);
    }
    @rmdir($dir);
}
dp_rmtree($root);

exit(DPTest::summary());
