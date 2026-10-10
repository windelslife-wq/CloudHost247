<?php
/**
 * CloudHost247 Digital Products — offline test harness.
 *
 * Boots the module against an in-memory SQLite database using the very same
 * migrations that ship to production, so the suites exercise the real schema,
 * the real joins and the real services. WHMCS core tables (tblhosting,
 * tblorders, tblproducts, tblclients, tblconfiguration, tbladdonmodules) are
 * recreated with the columns this module actually reads.
 *
 * No test touches the network, a real WHMCS install or the filesystem outside
 * a temporary directory.
 */
define('DIGITALPRODUCTS_TESTING', true);

require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/CapsuleShim.php';

use WHMCS\Database\Capsule;

/* ------------------------------------------------------------- assertions */

class DPTest
{
    public static $pass = 0; public static $fail = 0; public static $failures = []; public static $section = '';

    public static function section($name) { self::$section = $name; echo "\n== {$name} ==\n"; }
    public static function ok($name, $value) { if ($value) self::$pass++; else { self::$fail++; self::$failures[] = self::$section . ' / ' . $name; echo "FAIL {$name}\n"; } }
    public static function same($name, $expected, $actual) { self::ok($name, $expected === $actual); if ($expected !== $actual) echo '  expected ' . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n"; }
    public static function throws($name, $class, callable $fn) { try { $fn(); self::ok($name, false); } catch (\Throwable $e) { self::ok($name, $e instanceof $class); } }
    public static function summary() { echo 'PASS=' . self::$pass . ' FAIL=' . self::$fail . "\n"; return self::$fail ? 1 : 0; }
}

/* ------------------------------------------------------------- test schema */

class DPDb
{
    /** Recreate every table from the shipped migrations. */
    public static function reset()
    {
        Capsule::connect();
        self::whmcsCore();
        (new \DigitalProducts\Core\Migrator())->migrate();
        return Capsule::$pdo;
    }

    /** The WHMCS core tables this module reads, with the columns it reads. */
    public static function whmcsCore()
    {
        $pdo = Capsule::$pdo;
        $pdo->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY AUTOINCREMENT, firstname TEXT, lastname TEXT, email TEXT)');
        $pdo->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, type TEXT, hidden INTEGER DEFAULT 0, paytype TEXT)');
        $pdo->exec('CREATE TABLE tblhosting (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            userid INTEGER, orderid INTEGER, packageid INTEGER,
            domain TEXT, domainstatus TEXT, regdate TEXT
        )');
        $pdo->exec('CREATE TABLE tblorders (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER, status TEXT)');
        $pdo->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec('CREATE TABLE tbladdonmodules (module TEXT, setting TEXT, value TEXT)');
        Capsule::table('tblconfiguration')->insert(['setting' => 'SystemURL', 'value' => 'https://portal.example.test']);
    }

    /* --------------------------------------------------------- fixtures -- */

    protected static $seq = 0;

    public static function product(array $overrides = [])
    {
        $now = \DigitalProducts\Core\Clock::now();
        $seq = ++self::$seq;
        $values = array_merge([
            'product_id' => 900 + $seq, 'whmcs_product_id' => 900 + $seq, 'name' => 'Test Product ' . $seq, 'product_name' => 'Test Product ' . $seq,
            'slug' => 'test-product-' . $seq, 'status' => 'active', 'product_type' => 'software',
            'download_limit' => 0, 'link_expiry_hours' => 48, 'download_expiry_hours' => 48,
            'access_mode' => 'current_version', 'license_enabled' => 1, 'current_version_id' => null,
            'created_at' => $now, 'updated_at' => $now,
        ], $overrides);
        return Capsule::table('mod_digitalproducts_products')->insertGetId($values);
    }

    public static function version($productId, $version, array $overrides = [])
    {
        $now = \DigitalProducts\Core\Clock::now();
        $values = array_merge([
            'product_id' => (int) $productId, 'version' => (string) $version, 'storage_key' => 'product-' . $productId . '/version-x/' . bin2hex(random_bytes(6)) . '.zip',
            'original_filename' => 'release-' . $version . '.zip', 'file_size' => 2048,
            'checksum_sha256' => hash('sha256', 'release-' . $version), 'status' => 'active',
            'download_count' => 0, 'release_date' => $now, 'created_at' => $now, 'updated_at' => $now,
        ], $overrides);
        return Capsule::table('mod_digitalproducts_versions')->insertGetId($values);
    }

    public static function service($clientId, $packageId, array $overrides = [])
    {
        return Capsule::table('tblhosting')->insertGetId(array_merge([
            'userid' => (int) $clientId, 'orderid' => 500, 'packageid' => (int) $packageId,
            'domain' => 'client.example.test', 'domainstatus' => 'Active', 'regdate' => \DigitalProducts\Core\Clock::now(),
        ], $overrides));
    }

    public static function entitlement($clientId, $productId, $serviceId, array $overrides = [])
    {
        $now = \DigitalProducts\Core\Clock::now();
        return Capsule::table('mod_digitalproducts_entitlements')->insertGetId(array_merge([
            'product_id' => (int) $productId, 'whmcs_product_id' => 900, 'order_id' => 500,
            'service_id' => (int) $serviceId, 'client_id' => (int) $clientId,
            'purchase_version_id' => null, 'access_mode' => 'current_version', 'status' => 'active',
            'download_limit' => 0, 'downloads_used' => 0, 'purchased_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ], $overrides));
    }

    public static function row($table, $id)
    {
        return Capsule::table($table)->where('id', (int) $id)->first();
    }
}
