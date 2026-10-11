<?php
/**
 * Offline tests for v1 credential encryption and the legacy migration (H-4/H-6).
 * Run with tests/run.mjs (php-wasm) or directly under php. Prints FAIL=<n>.
 */

namespace WHMCS\Database {
    class HXFakeTable
    {
        private $rows;
        private $wheres = [];
        private $whereIns = [];
        public function __construct(array &$rows) { $this->rows = &$rows; }
        public function where($col, $val) { $this->wheres[] = [$col, $val]; return $this; }
        public function whereIn($col, array $vals) { $this->whereIns[] = [$col, $vals]; return $this; }
        private function match($row)
        {
            foreach ($this->wheres as $w) { if (($row[$w[0]] ?? null) != $w[1]) return false; }
            foreach ($this->whereIns as $w) { if (!in_array($row[$w[0]] ?? null, $w[1])) return false; }
            return true;
        }
        public function first() { foreach ($this->rows as $row) { if ($this->match($row)) return (object) $row; } return null; }
        public function get() { $out = []; foreach ($this->rows as $row) { if ($this->match($row)) $out[] = (object) $row; } return $out; }
        public function value($col) { $r = $this->first(); return $r ? $r->$col : null; }
        public function update(array $vals) { $n = 0; foreach ($this->rows as &$row) { if ($this->match($row)) { foreach ($vals as $k => $v) $row[$k] = $v; $n++; } } return $n; }
    }
    class Capsule
    {
        public static $tables = [];
        public static function table($name) { if (!isset(self::$tables[$name])) self::$tables[$name] = []; return new HXFakeTable(self::$tables[$name]); }
        public static function seed($name, array $rows) { self::$tables[$name] = $rows; }
    }
}

namespace {

use WHMCS\Database\Capsule;

define('WHMCS', true);

// Stand-ins for the WHMCS core encrypt()/decrypt(). decrypt() yields '' for
// anything it cannot unwrap (real core either returns '' or throws; the
// migration driver tolerates both).
if (!function_exists('encrypt')) {
    function encrypt($s) { return 'WHMCSENC:' . base64_encode((string) $s); }
}
if (!function_exists('decrypt')) {
    function decrypt($s)
    {
        if (!is_string($s) || substr($s, 0, 9) !== 'WHMCSENC:') return '';
        $d = base64_decode(substr($s, 9), true);
        return $d === false ? '' : $d;
    }
}

require_once __DIR__ . '/../functions.php';

$pass = 0;
$fail = 0;
function check($label, $cond)
{
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
}
function checkThrows($label, $class, callable $fn)
{
    try { $fn(); check($label, false); }
    catch (\Throwable $e) { check($label, $e instanceof $class); }
}

// Frozen copy of the pre-v1 algorithm: the migration must keep reading rows
// that were written by exactly this code.
function hx_legacy_encrypt($plain, $systemUrl)
{
    $key = hash('sha256', $systemUrl . 'HostXEmail_v1.0.0');
    $iv = random_bytes(16);
    return base64_encode($iv . openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
}

$GLOBALS['cc_encryption_hash'] = 'test-only-whmcs-secret-0123456789abcdef';
$systemUrl = 'https://portal.example.test';
Capsule::seed('tblconfiguration', [['setting' => 'SystemURL', 'value' => $systemUrl]]);

echo "== environment ==\n";
check('AES-256-GCM is available', in_array('aes-256-gcm', openssl_get_cipher_methods(), true));

echo "== v1 round-trip ==\n";
$v1 = hostx_email_encrypt('api-key-1');
check('v1 ciphertext carries the version prefix', substr($v1, 0, 8) === 'hxem:v1:');
check('v1 decrypts to the original', hostx_email_decrypt($v1) === 'api-key-1');
check('two encryptions differ (random nonce)', hostx_email_encrypt('api-key-1') !== $v1);
check('fresh v1 verifies as current', hostx_email_is_current($v1));

echo "== v1 authentication ==\n";
$flipped = substr($v1, 0, -1) . (substr($v1, -1) === 'A' ? 'B' : 'A');
check('tampered v1 decrypts to empty', hostx_email_decrypt($flipped) === '');
check('truncated v1 decrypts to empty', hostx_email_decrypt(substr($v1, 0, -4)) === '');
$savedHash = $GLOBALS['cc_encryption_hash'];
$GLOBALS['cc_encryption_hash'] = 'wrong-secret';
check('v1 under the wrong key decrypts to empty', hostx_email_decrypt($v1) === '');
check('v1 under the wrong key is not current', !hostx_email_is_current($v1));
$GLOBALS['cc_encryption_hash'] = $savedHash;

echo "== legacy dual-read ==\n";
$legacy = hx_legacy_encrypt('tenant-1', $systemUrl);
check('legacy rows still decrypt', hostx_email_decrypt($legacy) === 'tenant-1');
check('legacy rows are not current', !hostx_email_is_current($legacy));
Capsule::seed('tblconfiguration', [['setting' => 'SystemURL', 'value' => 'https://other.example.test']]);
$changedFails = hostx_email_decrypt($legacy) === '';
Capsule::seed('tblconfiguration', [['setting' => 'SystemURL', 'value' => $systemUrl]]);
check('legacy reads still honour the SystemURL key', $changedFails && hostx_email_decrypt($legacy) === 'tenant-1');

echo "== empty and failure contract ==\n";
check('encrypt of empty is empty', hostx_email_encrypt('') === '');
check('decrypt of empty is empty', hostx_email_decrypt('') === '');
check('decrypt of null is empty', hostx_email_decrypt(null) === '');
check('decrypt of garbage is empty', hostx_email_decrypt('!!!') === '');

echo "== no-secret fail-closed ==\n";
unset($GLOBALS['cc_encryption_hash']);
checkThrows('encrypt without a secret throws', 'RuntimeException', function () { hostx_email_encrypt('x'); });
check('v1 decrypt without a secret yields empty', hostx_email_decrypt($v1) === '');
checkThrows('migrate_value without a secret throws', 'RuntimeException', function () use ($legacy) { hostx_email_migrate_value($legacy); });
$GLOBALS['cc_encryption_hash'] = $savedHash;

echo "== migrate_value ==\n";
$upgraded = hostx_email_migrate_value($legacy);
check('legacy upgrades to verifying v1', $upgraded !== $legacy && hostx_email_is_current($upgraded) && hostx_email_decrypt($upgraded) === 'tenant-1');
check('v1 passes through identical', hostx_email_migrate_value($v1) === $v1);
check('empty passes through', hostx_email_migrate_value('') === '');
check('garbage passes through for manual review', hostx_email_migrate_value('!!!') === '!!!');

echo "== full migration (dry run) ==\n";
$svcLegacy = hx_legacy_encrypt('svc-secret', $systemUrl);
$tenantLegacy = hx_legacy_encrypt('tenant-1', $systemUrl);
$tenant13Legacy = hx_legacy_encrypt('tenant-13', $systemUrl);
Capsule::seed('tblhosting', [
    ['id' => 1, 'servertype' => 'hostx_email', 'server' => 11, 'password' => encrypt($svcLegacy)],
    ['id' => 2, 'servertype' => 'hostx_email', 'server' => 0, 'password' => ''],
    ['id' => 3, 'servertype' => 'other', 'server' => 12, 'password' => encrypt($svcLegacy)],
    ['id' => 4, 'servertype' => 'hostx_email', 'server' => 13, 'password' => ''],
]);
Capsule::seed('tblservers', [
    ['id' => 11, 'type' => 'hostx_email', 'username' => encrypt($tenantLegacy), 'password' => encrypt($v1)],
    ['id' => 12, 'type' => 'cpanel', 'username' => encrypt($tenantLegacy), 'password' => encrypt($svcLegacy)],
    ['id' => 13, 'type' => '', 'username' => $tenant13Legacy, 'password' => ''],
]);
Capsule::seed('tblproducts', [
    ['id' => 21, 'servertype' => 'hostx_email', 'configoption7' => ''],
    ['id' => 22, 'servertype' => 'hostx_email', 'configoption7' => 'garbage-not-crypto'],
    ['id' => 23, 'servertype' => 'other', 'configoption7' => $tenantLegacy],
]);
$before = Capsule::table('tblhosting')->where('id', 1)->value('password');
$dry = hostx_email_migrate_credentials(['dry_run' => true]);
check('dry run scans 9 cells', $dry['scanned'] === 9);
check('dry run would migrate 3 cells', $dry['migrated'] === 3);
check('dry run sees 1 current cell', $dry['current'] === 1);
check('dry run sees 4 empty cells', $dry['empty'] === 4);
check('dry run flags the garbage cell', $dry['manual'] === ['tblproducts:22:configoption7']);
check('dry run reports no errors', $dry['errors'] === []);
check('dry run writes nothing', Capsule::table('tblhosting')->where('id', 1)->value('password') === $before);
check('other modules are untouched', Capsule::table('tblhosting')->where('id', 3)->value('password') === encrypt($svcLegacy));

echo "== full migration (live) ==\n";
$live = hostx_email_migrate_credentials(['dry_run' => false]);
check('live run migrates 3 with no errors', $live['migrated'] === 3 && $live['errors'] === [] && $live['manual'] === ['tblproducts:22:configoption7']);
$svcCell = Capsule::table('tblhosting')->where('id', 1)->value('password');
check('migrated cell unwraps to verifying v1', hostx_email_is_current(decrypt($svcCell)));
check('wrapped columns stay wrapped', substr($svcCell, 0, 9) === 'WHMCSENC:');
$linkedCell = Capsule::table('tblservers')->where('id', 13)->value('username');
check('unwrapped columns stay unwrapped', substr($linkedCell, 0, 8) === 'hxem:v1:');
check('linked-server secret survives byte-identical', hostx_email_decrypt($linkedCell) === 'tenant-13');
$rerun = hostx_email_migrate_credentials(['dry_run' => false]);
check('re-run is a no-op (idempotent)', $rerun['migrated'] === 0 && $rerun['current'] === 4 && $rerun['errors'] === []);

echo "== cli wrapper ==\n";
require_once __DIR__ . '/../upgrade/migrate-credentials.php';
check('wrapper defines the cli entry point', function_exists('hostx_email_migrate_cli_main'));
ob_start();
$helpRc = hostx_email_migrate_cli_main(['migrate-credentials.php', '--help']);
$helpText = ob_get_clean();
check('--help works without WHMCS', $helpRc === 0 && strpos($helpText, '--confirm') !== false);
ob_start();
$noBootRc = hostx_email_migrate_cli_main(['migrate-credentials.php']);
ob_end_clean();
check('missing WHMCS bootstrap fails closed', $noBootRc === 1);

echo "\ncrypto migration: PASS=$pass FAIL=$fail\n";
echo "FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
}
