<?php
/**
 * CloudHost247 AI — test harness.
 *
 * Boots the module against an in-memory SQLite database using the very same
 * migrations that ship to production, seeds WHMCS-shaped fixture tables, and
 * replaces the model provider with a scripted fake — so the suite asserts
 * exactly what the runtime would do, without a WHMCS install or a model API.
 *
 * No test touches the network or a real model endpoint.
 *
 * @package Ch247Ai
 */

define('CH247AI_TESTING', true);

require_once dirname(__DIR__) . '/autoload.php';

use Ch247Ai\Agents\AgentRuntime;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\Migrator;
use Ch247Ai\Core\Settings;
use Ch247Ai\Tools\Bootstrap as ToolBootstrap;

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
 * Fresh database + registries for each test file. Call again mid-file to
 * reset between groups.
 */
function ch247ai_boot()
{
    Db::reset();
    Settings::resetOverrides();
    Clock::freeze(null);
    Identity::setClient(null);
    Identity::setAdmin(null);
    AgentRuntime::setModelFake(null);
    AgentRuntime::resetState();
    \Ch247Ai\Tools\ToolExecutor::resetAllowedAgents();
    \Ch247Ai\Core\Whmcs::setApiFake(null);
    \Ch247Ai\Core\Rbac::setForcedRole(1, null);
    $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $pdo = new PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    Db::setPdo($pdo, 'sqlite');

    $migrator = new Migrator(dirname(__DIR__) . '/install/migrations');
    $migrator->migrate();

    ch247ai_fixture_tables($pdo);

    ToolBootstrap::register();
    return $pdo;
}

/** Minimal WHMCS-shaped tables the AI tools read (read-only, like production). */
function ch247ai_fixture_tables(PDO $pdo)
{
    $ddl = [
        "CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT DEFAULT '', email TEXT, phonenumber TEXT DEFAULT '', country TEXT DEFAULT '', status TEXT DEFAULT 'Active', datecreated TEXT, clienttype TEXT DEFAULT 'Individual', credit TEXT DEFAULT '0.00')",
        "CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INTEGER, date TEXT, description TEXT, amount TEXT DEFAULT '0.00')",
        "CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT DEFAULT 'Unpaid', total TEXT DEFAULT '0.00', credit TEXT DEFAULT '0.00', date TEXT, duedate TEXT, datepaid TEXT, paymentmethod TEXT DEFAULT '')",
        "CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INTEGER, gateway TEXT, date TEXT, amount_in TEXT DEFAULT '0.00', amount_out TEXT DEFAULT '0.00', fees TEXT DEFAULT '0.00', currency INTEGER DEFAULT 1, invoiceid INTEGER, transid TEXT DEFAULT '')",
        "CREATE TABLE tblorders (id INTEGER PRIMARY KEY, ordernum TEXT, userid INTEGER, status TEXT DEFAULT 'Pending', amount TEXT DEFAULT '0.00', date TEXT, invoiceid INTEGER)",
        "CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INTEGER, packageid INTEGER, domain TEXT, domainstatus TEXT DEFAULT 'Active', regdate TEXT, nextduedate TEXT, billingcycle TEXT DEFAULT 'Monthly', amount TEXT DEFAULT '0.00', server INTEGER DEFAULT 0)",
        "CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, gid INTEGER, type TEXT DEFAULT 'other', name TEXT, hidden INTEGER DEFAULT 0, retired INTEGER DEFAULT 0, paytype TEXT DEFAULT 'regular')",
        "CREATE TABLE tblproductgroups (id INTEGER PRIMARY KEY, name TEXT)",
        "CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INTEGER, relid INTEGER, monthly TEXT, annually TEXT)",
        "CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, userid INTEGER, did INTEGER DEFAULT 1, title TEXT, status TEXT DEFAULT 'Open', urgency TEXT DEFAULT 'Medium', lastreply TEXT, date TEXT)",
        "CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INTEGER, domain TEXT, registrar TEXT DEFAULT '', status TEXT DEFAULT 'Active', expirydate TEXT, nextduedate TEXT, donotrenew INTEGER DEFAULT 0, ispremium INTEGER DEFAULT 0)",
        "CREATE TABLE tbladmins (id INTEGER PRIMARY KEY, username TEXT, roleid INTEGER DEFAULT 1)",
        "CREATE TABLE tbladdonmodules (id INTEGER PRIMARY KEY, module TEXT, setting TEXT, value TEXT)",
        "CREATE TABLE tbladminroles (id INTEGER PRIMARY KEY, name TEXT)",
        // Write-target tables (Phase 2). Empty by default: the write tests
        // assert on the delta they cause, not on preloaded rows.
        "CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY AUTOINCREMENT, tid INTEGER, userid INTEGER DEFAULT 0, name TEXT DEFAULT '', message TEXT, admin TEXT DEFAULT '', date TEXT)",
        "CREATE TABLE tblticketnotes (id INTEGER PRIMARY KEY AUTOINCREMENT, tid INTEGER, admin TEXT DEFAULT '', message TEXT, created_at TEXT)",
        "CREATE TABLE tblemails (id INTEGER PRIMARY KEY AUTOINCREMENT, userid INTEGER, subject TEXT, message TEXT, date TEXT)",
    ];
    foreach ($ddl as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO tbladmins (id, username, roleid) VALUES (1, 'root', 1), (2, 'sam', 2), (3, 'nia', 3)");
    $pdo->exec("INSERT INTO tbladminroles (id, name) VALUES (1, 'Super Admin'), (2, 'Support'), (3, 'Billing')");

    $pdo->exec("INSERT INTO tblclients (id, firstname, lastname, email, status, datecreated) VALUES
        (11, 'Ava', 'River', 'ava@example.test', 'Active', '2026-10-01'),
        (22, 'Ben', 'Stone', 'ben@example.test', 'Active', '2026-09-20'),
        (33, 'Cy', 'Nix', 'cy@example.test', 'Inactive', '2026-08-15')");

    $pdo->exec("INSERT INTO tblinvoices (id, userid, status, total, credit, date, duedate, datepaid) VALUES
        (101, 11, 'Paid', '120.00', '0.00', '2026-10-05', '2026-10-12', '2026-10-05'),
        (102, 22, 'Unpaid', '80.50', '0.00', '2026-09-28', '2026-10-05', ''),
        (103, 33, 'Unpaid', '45.00', '5.00', '2026-09-10', '2026-09-20', ''),
        (104, 11, 'Unpaid', '12.00', '0.00', '2026-10-06', '2026-10-13', '')");

    $pdo->exec("INSERT INTO tblaccounts (id, userid, gateway, date, amount_in, amount_out, fees, invoiceid) VALUES
        (501, 11, 'blockonomics', '2026-10-05', '120.00', '0.00', '0.30', 101),
        (502, 22, 'stripe', '2026-10-06', '30.00', '0.00', '0.20', 102)");

    $pdo->exec("INSERT INTO tblorders (id, ordernum, userid, status, amount, date, invoiceid) VALUES
        (201, 'ORD-201', 11, 'Active', '120.00', '2026-10-05', 101),
        (202, 'ORD-202', 22, 'Pending', '80.50', '2026-09-28', 102)");

    $pdo->exec("INSERT INTO tblhosting (id, userid, packageid, domain, domainstatus, nextduedate, amount) VALUES
        (301, 11, 1, 'ava.test', 'Active', '2026-11-05', '25.00'),
        (302, 22, 2, 'ben.test', 'Suspended', '2026-10-08', '18.00')");

    $pdo->exec("INSERT INTO tblproducts (id, gid, type, name) VALUES (1, 1, 'hostingaccount', 'Cloud Starter'), (2, 1, 'hostingaccount', 'Cloud Pro')");
    $pdo->exec("INSERT INTO tblproductgroups (id, name) VALUES (1, 'Cloud Hosting')");
    $pdo->exec("INSERT INTO tblpricing (id, type, currency, relid, monthly, annually) VALUES (1, 'product', 1, 1, '25.00', '250.00'), (2, 'product', 1, 2, '18.00', '180.00')");

    $pdo->exec("INSERT INTO tbltickets (id, tid, userid, did, title, status, urgency, lastreply, date) VALUES
        (401, 'TK-401', 11, 1, 'Cannot reach site', 'Open', 'High', '2026-10-06 09:00:00', '2026-10-06 08:00:00'),
        (402, 'TK-402', 22, 1, 'Invoice question', 'Answered', 'Medium', '2026-10-05 15:00:00', '2026-10-05 14:00:00')");

    $pdo->exec("INSERT INTO tbldomains (id, userid, domain, registrar, status, expirydate, nextduedate) VALUES
        (601, 11, 'avariver.com', 'registrar-a', 'Active', '2026-10-20', '2026-10-13'),
        (602, 22, 'benstone.io', 'registrar-b', 'Active', '2027-01-15', '2026-12-20')");
}

/** Freeze the clock to a deterministic moment. */
function ch247ai_freeze($datetime = '2026-10-06 12:00:00')
{
    Clock::freeze(strtotime($datetime . ' UTC'));
}

/**
 * Scripted model fake: returns queued replies in order.
 * Each reply: ['content' => string, 'tokens_in' => n, 'tokens_out' => n].
 */
function ch247ai_scripted_model(array $replies)
{
    $i = 0;
    return function (array $messages, array $opts) use (&$i, $replies) {
        $reply = isset($replies[$i]) ? $replies[$i] : ['content' => '(no more scripted replies)', 'tokens_in' => 1, 'tokens_out' => 1];
        $i++;
        return ['content' => $reply['content'], 'tokens_in' => isset($reply['tokens_in']) ? $reply['tokens_in'] : 10, 'tokens_out' => isset($reply['tokens_out']) ? $reply['tokens_out'] : 10];
    };
}

/** A model reply that calls a tool (JSON action protocol). */
function ch247ai_tool_call($tool, array $arguments = [])
{
    return '```json' . "\n" . json_encode(['tool' => $tool, 'arguments' => $arguments]) . "\n" . '```';
}

/** Become super admin #1 in the session. */
function ch247ai_as_super_admin()
{
    Identity::setAdmin(1);
    $_SESSION['adminid'] = 1;
}
