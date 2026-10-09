<?php
/**
 * CloudHost247 Services — test harness.
 *
 * Boots the module against an in-memory SQLite database using the very same
 * migrations that ship to production, and replaces the host platform with the
 * recording FakeGateway, so suites assert exactly which WHMCS calls the
 * module would make — without a WHMCS install.
 *
 * No test touches the network, the filesystem outside a temp dir, or a real
 * payment provider.
 *
 * @package Chs
 */

define('CHS_TESTING', true);

require_once dirname(__DIR__) . '/autoload.php';

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Identity;
use Chs\Core\Migrator;
use Chs\Core\Platform;
use Chs\Core\Settings;
use Chs\Providers\Whmcs\FakeGateway;

/* ------------------------------------------------------------ assertions -- */

class T
{
    public static $pass = 0;
    public static $fail = 0;
    public static $section = '';
    public static $failures = [];

    public static function section($name)
    {
        self::$section = $name;
        echo "\n== {$name} ==\n";
    }

    public static function ok($label, $condition)
    {
        if ($condition) {
            self::$pass++;
            return true;
        }
        self::$fail++;
        self::$failures[] = self::$section . ' / ' . $label;
        echo "  FAIL {$label}\n";
        return false;
    }

    public static function eq($label, $expected, $actual, $strict = true)
    {
        $same = $strict ? $expected === $actual : $expected == $actual;
        if (!$same) {
            echo "  expected: " . var_export($expected, true) . "\n"
                . "  actual  : " . var_export($actual, true) . "\n";
        }
        return self::ok($label, $same);
    }

    public static function throws($label, callable $fn, $exceptionClass)
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                return self::ok($label, true);
            }
            echo "  threw " . get_class($e) . " instead: " . $e->getMessage() . "\n";
            return self::ok($label, false);
        }
        echo "  no exception thrown\n";
        return self::ok($label, false);
    }

    public static function finish()
    {
        echo "\n──────────────────────────────────\n";
        echo "PASS=" . self::$pass . " FAIL=" . self::$fail . "\n";
        if (self::$fail > 0) {
            foreach (self::$failures as $f) {
                echo "  - {$f}\n";
            }
        }
        exit(self::$fail > 0 ? 1 : 0);
    }
}

/* ----------------------------------------------------------- environment -- */

/**
 * Fresh database + gateway + clock for each test file (or call again
 * mid-file to reset between groups).
 *
 * @return FakeGateway
 */
function chs_boot()
{
    Db::reset();
    Settings::resetOverrides();
    Clock::freeze(null);
    Identity::setClient(null);
    Identity::setAdmin(null);

    $pdo = new PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON'); // production MySQL enforces FKs; mirror it
    Db::setPdo($pdo, 'sqlite');

    $migrator = new Migrator(dirname(__DIR__) . '/install/migrations');
    $migrator->migrate();

    chs_fixture_tables($pdo);

    $gateway = new FakeGateway();
    Platform::setGateway($gateway);
    return $gateway;
}

/** Minimal WHMCS-shaped tables the services read (read-only). */
function chs_fixture_tables(PDO $pdo)
{
    $ddl = [
        "CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, email TEXT, currency INTEGER DEFAULT 1)",
        "CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, `default` INTEGER DEFAULT 0)",
        "CREATE TABLE tbladdonmodules (id INTEGER PRIMARY KEY, module TEXT, setting TEXT, value TEXT)",
        "CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT DEFAULT 'Unpaid', total TEXT DEFAULT '0.00')",
        "CREATE TABLE tbldomainpricing (id INTEGER PRIMARY KEY, extension TEXT, autoreg TEXT DEFAULT '', dnsmanagement INTEGER DEFAULT 0, emailforwarding INTEGER DEFAULT 0, idprotection INTEGER DEFAULT 0, eppcode INTEGER DEFAULT 0)",
        "CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INTEGER, relid INTEGER, msetupfee TEXT, qsetupfee TEXT, ssetupfee TEXT, asetupfee TEXT, bsetupfee TEXT, monthly TEXT, quarterly TEXT, semiannually TEXT, annually TEXT, biennially TEXT, triennially TEXT)",
        "CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INTEGER, domain TEXT, status TEXT DEFAULT 'Active', nextduedate TEXT, expirydate TEXT, registrar TEXT DEFAULT '', autorenew INTEGER DEFAULT 0, donotrenew INTEGER DEFAULT 0)",
        "CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, userid INTEGER, name TEXT, email TEXT, subject TEXT, message TEXT, status TEXT DEFAULT 'Open', urgency TEXT DEFAULT 'Medium', date TEXT, lastreply TEXT, flag INTEGER DEFAULT 0, admin TEXT DEFAULT '')",
        "CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INTEGER, userid INTEGER DEFAULT 0, admin TEXT DEFAULT '', name TEXT DEFAULT '', message TEXT, date TEXT)",
        "CREATE TABLE tbladmins (id INTEGER PRIMARY KEY, username TEXT, firstname TEXT, lastname TEXT)",
        "CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)",
        "CREATE TABLE tblproductgroups (id INTEGER PRIMARY KEY, name TEXT, order_value INTEGER DEFAULT 0)",
        "CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, gid INTEGER DEFAULT 0, type TEXT DEFAULT '', name TEXT, description TEXT, paytype TEXT DEFAULT 'recurring', servertype TEXT DEFAULT '', hidden TEXT DEFAULT '', `order` INTEGER DEFAULT 0)",
        "CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INTEGER, orderid INTEGER DEFAULT 0, packageid INTEGER DEFAULT 0, regdate TEXT, domain TEXT DEFAULT '', server INTEGER DEFAULT 0, status TEXT DEFAULT 'Pending', nextduedate TEXT)",
        "CREATE TABLE tblorders (id INTEGER PRIMARY KEY, userid INTEGER, invoiceid INTEGER DEFAULT 0)",
        "CREATE TABLE tblservers (id INTEGER PRIMARY KEY, name TEXT DEFAULT '', ipaddress TEXT DEFAULT '', hostname TEXT DEFAULT '', username TEXT DEFAULT '', password TEXT DEFAULT '', type TEXT DEFAULT '', assignedips TEXT DEFAULT '', ns1 TEXT DEFAULT '', ns2 TEXT DEFAULT '')",
    ];
    foreach ($ddl as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO tblcurrencies (id, code, `default`) VALUES (1, 'USD', 1), (2, 'EUR', 0), (3, 'GBP', 0)");
    $pdo->exec("INSERT INTO tblconfiguration (setting, value) VALUES ('SystemURL', 'https://billing.example.test/')");

    // Client fixtures
    $pdo->exec("INSERT INTO tblclients (id, firstname, lastname, email, currency) VALUES
        (11, 'Ava', 'River', 'ava@example.test', 1),
        (22, 'Ben', 'Stone', 'ben@example.test', 2),
        (33, 'Cy', 'Nix', 'cy@example.test', 1)");

    // Admin fixture
    $pdo->exec("INSERT INTO tbladmins (id, username, firstname, lastname) VALUES (1, 'root', 'Root', 'Admin'), (2, 'sam', 'Sam', 'Support')");

    // Server-product fixtures (mirrored in FakeGateway for seam-based tests)
    $pdo->exec("INSERT INTO tblproductgroups (id, name, order_value) VALUES (1, 'Cloud Servers', 1)");
    $pdo->exec("INSERT INTO tblproducts (id, gid, type, name, description, paytype, servertype, hidden) VALUES
        (101, 1, 'server', 'Cloud VPS', 'Managed cloud VPS', 'recurring', '', ''),
        (102, 1, 'server', 'Dedicated Server', 'Bare-metal dedicated', 'recurring', '', ''),
        (103, 1, 'hosting', 'Web Hosting', 'Shared hosting (not a server product)', 'recurring', '', '')");
}

/** Seed the recording FakeGateway with server products + pricing. */
function chs_seed_server_products(FakeGateway $gateway)
{
    $gateway->products = [
        101 => ['id' => 101, 'gid' => 1, 'type' => 'server', 'name' => 'Cloud VPS', 'description' => 'Managed cloud VPS', 'paytype' => 'recurring', 'servertype' => '', 'hidden' => '', 'group_name' => 'Cloud Servers'],
        102 => ['id' => 102, 'gid' => 1, 'type' => 'server', 'name' => 'Dedicated Server', 'description' => 'Bare-metal dedicated', 'paytype' => 'recurring', 'servertype' => '', 'hidden' => '', 'group_name' => 'Cloud Servers'],
        103 => ['id' => 103, 'gid' => 1, 'type' => 'hosting', 'name' => 'Web Hosting', 'description' => 'Shared hosting (not a server product)', 'paytype' => 'recurring', 'servertype' => '', 'hidden' => '', 'group_name' => 'Cloud Servers'],
    ];
    $gateway->productPricing = [
        101 => ['monthly' => 999, 'quarterly' => 2699, 'annually' => 9999],
        102 => ['monthly' => 8900, 'annually' => 89000],
    ];
}

/** Populate FakeGateway clients to match the SQL fixtures. */
function chs_seed_clients(FakeGateway $gateway)
{
    $gateway->clients = [
        11 => ['id' => 11, 'name' => 'Ava River', 'email' => 'ava@example.test', 'currency' => 'USD'],
        22 => ['id' => 22, 'name' => 'Ben Stone', 'email' => 'ben@example.test', 'currency' => 'EUR'],
        33 => ['id' => 33, 'name' => 'Cy Nix',    'email' => 'cy@example.test',    'currency' => 'USD'],
    ];
}

/** Domain catalogue fixtures: 4 TLDs with pricing. */
function chs_seed_tld_fixtures(PDO $pdo = null)
{
    $pdo = $pdo ?: Db::pdo();
    $pdo->exec("INSERT INTO tbldomainpricing (id, extension, autoreg, dnsmanagement, emailforwarding, idprotection, eppcode) VALUES
        (1, '.com', 'opensrs', 1, 1, 1, 1),
        (2, '.net', 'opensrs', 1, 1, 1, 1),
        (3, '.io', '', 0, 0, 0, 1),
        (4, '.co.uk', '', 0, 0, 0, 0)");
    $zeroCols = "'0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00'";
    // register rows (1yr price in msetupfee)
    $pdo->exec("INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially)
        VALUES ('domainregister', 1, 1, '12.99','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00'),
               ('domainregister', 1, 2, '14.99','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00'),
               ('domainregister', 1, 3, '39.99','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00'),
               ('domainregister', 2, 1, '11.99','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00'),
               ('domainrenew',    1, 1, '15.99','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00'),
               ('domaintransfer', 1, 1, '9.99','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00','0.00')");
}

/** Freeze the clock to a pleasant, deterministic moment. */
function chs_freeze($datetime = '2026-10-06 12:00:00')
{
    if (preg_match('/^[+-]\d+\s*(second|minute|hour|day)s?$/', trim($datetime), $m)) {
        Clock::freeze(Clock::time() + (int) $datetime * ['second' => 1, 'minute' => 60, 'hour' => 3600, 'day' => 86400][$m[1]]);
        return;
    }
    Clock::freeze(strtotime($datetime . ' UTC'));
}

/**
 * Infrastructure fixtures: one enabled provider, one active region, and one
 * ACTIVE image mapping Ubuntu 24.04 (x86_64) in that region.
 *
 * @return array{provider_id:int, region_id:int, os_id:int, version_id:int, image_id:int}
 */
function chs_seed_infra()
{
    $now = Clock::now();
    $providerId = Db::insert('infrastructure_providers', [
        'name' => 'Fake Cloud', 'slug' => 'fake-cloud', 'type' => 'http',
        'base_url' => 'https://infra.example.test', 'endpoints' => '{}',
        'capabilities' => json_encode(array_fill_keys(\Chs\Providers\Infrastructure\InfraProviderRegistry::CAPABILITY_KEYS, true)),
        'is_enabled' => 1, 'is_default' => 1, 'health_status' => 'unknown',
        'credentials_enc' => '', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $regionId = Db::insert('infrastructure_regions', [
        'provider_id' => $providerId, 'code' => 'fra', 'name' => 'Frankfurt',
        'datacenter' => 'DC-1', 'is_active' => 1, 'sort_order' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $ubuntu = Db::first('operating_systems', ['slug' => 'ubuntu']);
    $version = Db::first('operating_system_versions', [
        'operating_system_id' => (int) $ubuntu['id'], 'version' => '24.04',
    ]);
    $imageId = Db::insert('server_os_images', [
        'provider_id' => $providerId,
        'operating_system_version_id' => (int) $version['id'],
        'provider_image_id' => 'img-ubuntu-2404',
        'provider_template_id' => '',
        'architecture' => 'x86_64',
        'region_id' => $regionId,
        'status' => 'active',
        'metadata' => '{}',
        'test_result' => 'ok',
        'last_tested_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return [
        'provider_id' => $providerId,
        'region_id'   => $regionId,
        'os_id'       => (int) $ubuntu['id'],
        'version_id'  => (int) $version['id'],
        'image_id'    => $imageId,
    ];
}

/**
 * Wire a FakeInfrastructureProvider into the registry seam (call after
 * requiring tests/FakeInfrastructureProvider.php).
 *
 * @return FakeInfrastructureProvider
 */
function chs_fake_infra_provider($providerId)
{
    $fake = new FakeInfrastructureProvider($providerId, 'Fake Cloud');
    \Chs\Providers\Infrastructure\InfraProviderRegistry::$instanceOverride = [$providerId => $fake];
    return $fake;
}
