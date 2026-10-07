<?php
/**
 * CloudHost247 Marketing — test harness.
 *
 * Boots the module against an in-memory SQLite database using the very same
 * migrations that ship to production, seeds WHMCS-shaped fixture tables, and
 * swaps the mail transport for a recording fake.
 *
 * No test opens a socket, sends an email, or needs a WHMCS install.
 *
 * @package Ch247Mkt
 */

define('CH247M_TESTING', true);

require_once dirname(__DIR__) . '/autoload.php';

use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Identity;
use Ch247Mkt\Core\Migrator;
use Ch247Mkt\Core\Rbac;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Whmcs;
use Ch247Mkt\Transport\NullTransport;
use Ch247Mkt\Transport\TransportFactory;

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
            echo '  expected: ' . var_export($expected, true) . "\n"
                . '  actual  : ' . var_export($actual, true) . "\n";
        }
        return self::ok($label, $same);
    }

    public static function contains($label, $needle, $haystack)
    {
        $found = strpos((string) $haystack, (string) $needle) !== false;
        if (!$found) {
            echo '  missing: ' . var_export($needle, true) . "\n";
        }
        return self::ok($label, $found);
    }

    public static function notContains($label, $needle, $haystack)
    {
        $found = strpos((string) $haystack, (string) $needle) !== false;
        if ($found) {
            echo '  unexpectedly present: ' . var_export($needle, true) . "\n";
        }
        return self::ok($label, !$found);
    }

    public static function throws($label, callable $fn, $exceptionClass)
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                return self::ok($label, true);
            }
            echo '  threw ' . get_class($e) . ' instead: ' . $e->getMessage() . "\n";
            return self::ok($label, false);
        }
        echo "  no exception thrown\n";
        return self::ok($label, false);
    }

    public static function finish()
    {
        echo "\n──────────────────────────────────\n";
        echo 'PASS=' . self::$pass . ' FAIL=' . self::$fail . "\n";
        if (self::$fail > 0) {
            foreach (self::$failures as $f) {
                echo "  - {$f}\n";
            }
        }
        exit(self::$fail > 0 ? 1 : 0);
    }
}

/* ----------------------------------------------------------- environment -- */

/** Fresh database + settings for each test group. */
function ch247m_boot()
{
    Db::reset();
    Settings::resetOverrides();
    Clock::freeze(null);
    Identity::setClient(null);
    Identity::setAdmin(null);
    Whmcs::setApiFake(null);
    Rbac::setForcedRole(1, null);
    TransportFactory::setOverride(null);
    NullTransport::reset();
    $_SESSION = [];
    $_POST = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Safari/537.36';

    $pdo = new PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    Db::setPdo($pdo, 'sqlite');

    (new Migrator(dirname(__DIR__) . '/install/migrations'))->migrate();
    ch247m_fixture_tables($pdo);

    // A usable, fully configured sender by default. Individual tests take
    // pieces away to prove the pre-flight blocks what it should.
    Settings::override('sending_enabled', '1');
    Settings::override('transport', 'null');
    Settings::override('from_name', 'CloudHost247');
    Settings::override('from_email', 'hello@cloudhost247.test');
    Settings::override('reply_to', 'support@cloudhost247.test');
    Settings::override('company_name', 'CloudHost247 Ltd');
    Settings::override('physical_address', "12 Marina Road\nLagos, Nigeria");
    Settings::override('tracking_base_url', 'https://billing.cloudhost247.test/modules/addons/cloudhost247marketing/track.php');

    ch247m_freeze();
    return $pdo;
}

/** WHMCS-shaped tables the segmentation engine and merge tags read. */
function ch247m_fixture_tables(PDO $pdo)
{
    $ddl = [
        "CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT DEFAULT '',
            email TEXT, address1 TEXT DEFAULT '', city TEXT DEFAULT '', state TEXT DEFAULT '', postcode TEXT DEFAULT '',
            country TEXT DEFAULT '', phonenumber TEXT DEFAULT '', status TEXT DEFAULT 'Active', datecreated TEXT,
            lastlogin TEXT DEFAULT '', credit TEXT DEFAULT '0.00', language TEXT DEFAULT '', currency INTEGER DEFAULT 1,
            groupid INTEGER DEFAULT 0, marketing_emails_opt_in INTEGER DEFAULT 0)",
        "CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INTEGER, packageid INTEGER, server INTEGER DEFAULT 0,
            domain TEXT DEFAULT '', domainstatus TEXT DEFAULT 'Active', regdate TEXT, nextduedate TEXT,
            billingcycle TEXT DEFAULT 'Monthly', amount TEXT DEFAULT '0.00')",
        "CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, gid INTEGER, type TEXT DEFAULT 'hostingaccount', name TEXT,
            hidden INTEGER DEFAULT 0, retired INTEGER DEFAULT 0)",
        "CREATE TABLE tblproductgroups (id INTEGER PRIMARY KEY, name TEXT)",
        "CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INTEGER, domain TEXT, registrar TEXT DEFAULT '',
            status TEXT DEFAULT 'Active', expirydate TEXT, nextduedate TEXT, recurringamount TEXT DEFAULT '0.00')",
        "CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, invoicenum TEXT DEFAULT '',
            status TEXT DEFAULT 'Unpaid', total TEXT DEFAULT '0.00', date TEXT, duedate TEXT, datepaid TEXT DEFAULT '')",
        "CREATE TABLE tblorders (id INTEGER PRIMARY KEY, ordernum TEXT, userid INTEGER, status TEXT DEFAULT 'Pending',
            amount TEXT DEFAULT '0.00', date TEXT, invoiceid INTEGER DEFAULT 0)",
        "CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, userid INTEGER, did INTEGER DEFAULT 1, title TEXT,
            status TEXT DEFAULT 'Open', urgency TEXT DEFAULT 'Medium', lastreply TEXT, date TEXT)",
        "CREATE TABLE tblaffiliates (id INTEGER PRIMARY KEY, clientid INTEGER, date TEXT, visitors INTEGER DEFAULT 0,
            balance TEXT DEFAULT '0.00')",
        "CREATE TABLE tbladmins (id INTEGER PRIMARY KEY, username TEXT, firstname TEXT DEFAULT '', lastname TEXT DEFAULT '', roleid INTEGER DEFAULT 1)",
        "CREATE TABLE tbladminroles (id INTEGER PRIMARY KEY, name TEXT)",
        "CREATE TABLE tbladdonmodules (id INTEGER PRIMARY KEY, module TEXT, setting TEXT, value TEXT)",
        "CREATE TABLE tblconfiguration (id INTEGER PRIMARY KEY, setting TEXT, value TEXT)",
    ];
    foreach ($ddl as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO tbladmins (id, username, firstname, lastname, roleid) VALUES
        (1, 'root', 'Root', 'Admin', 1), (2, 'sam', 'Sam', 'Marketing', 2), (3, 'nia', 'Nia', 'Support', 3)");
    $pdo->exec("INSERT INTO tbladminroles (id, name) VALUES (1, 'Super Admin'), (2, 'Marketing'), (3, 'Support')");
    $pdo->exec("INSERT INTO tblconfiguration (id, setting, value) VALUES
        (1, 'SystemURL', 'https://billing.cloudhost247.test'),
        (2, 'MailType', 'smtp'), (3, 'SMTPHost', 'mail.example.test'), (4, 'SMTPPort', '587'),
        (5, 'SMTPUsername', 'postmaster'), (6, 'SMTPPassword', 'ENCRYPTED'), (7, 'SMTPSSL', 'tls'),
        (8, 'SystemEmailsFromName', 'CloudHost247'), (9, 'SystemEmailsFromEmail', 'noreply@cloudhost247.test')");

    // Four clients: active Nigerian hosting customer, active Swiss VPS
    // customer, an inactive one, and a closed account.
    $pdo->exec("INSERT INTO tblclients (id, firstname, lastname, companyname, email, city, country, status, datecreated, marketing_emails_opt_in) VALUES
        (11, 'Ada',  'Obi',   'Obi Media',   'ada@example.test',  'Lagos',  'NG', 'Active',   '2026-01-10', 1),
        (22, 'Luca', 'Meier', 'Meier GmbH',  'luca@example.test', 'Zurich', 'CH', 'Active',   '2026-03-02', 1),
        (33, 'Sam',  'Idris', '',            'sam@example.test',  'Abuja',  'NG', 'Inactive', '2025-11-20', 0),
        (44, 'Kemi', 'Ade',   'Ade Ltd',     'kemi@example.test', 'Lagos',  'NG', 'Closed',   '2025-06-01', 1)");

    $pdo->exec("INSERT INTO tblproductgroups (id, name) VALUES (1, 'Web Hosting'), (2, 'VPS')");
    $pdo->exec("INSERT INTO tblproducts (id, gid, type, name) VALUES
        (1, 1, 'hostingaccount', 'Cloud Starter Hosting'),
        (2, 2, 'server',         'Business VPS'),
        (3, 1, 'hostingaccount', 'Cloud Pro Hosting')");

    $pdo->exec("INSERT INTO tblhosting (id, userid, packageid, domain, domainstatus, regdate, nextduedate, billingcycle, amount) VALUES
        (301, 11, 1, 'obimedia.test',  'Active',    '2026-01-10', '2026-11-10', 'Monthly', '12.00'),
        (302, 22, 2, 'meier.test',     'Active',    '2026-03-02', '2026-12-02', 'Annually', '240.00'),
        (303, 33, 1, 'samidris.test',  'Suspended', '2025-11-20', '2026-10-01', 'Monthly', '12.00')");

    $pdo->exec("INSERT INTO tbldomains (id, userid, domain, status, expirydate, nextduedate) VALUES
        (601, 11, 'obimedia.test', 'Active', '2027-01-10', '2026-12-10'),
        (602, 22, 'meier.test',    'Active', '2027-03-02', '2027-02-02')");

    $pdo->exec("INSERT INTO tblinvoices (id, userid, invoicenum, status, total, date, duedate) VALUES
        (101, 11, 'INV-101', 'Unpaid', '12.00', '2026-10-01', '2026-10-08'),
        (102, 22, 'INV-102', 'Paid',   '240.00', '2026-03-02', '2026-03-09')");

    $pdo->exec("INSERT INTO tblorders (id, ordernum, userid, status, amount, date, invoiceid) VALUES
        (201, 'ORD-201', 11, 'Active', '12.00', '2026-01-10', 101),
        (202, 'ORD-202', 22, 'Active', '240.00', '2026-03-02', 102)");

    $pdo->exec("INSERT INTO tbltickets (id, tid, userid, did, title, status, urgency, lastreply, date) VALUES
        (401, 'TK-401', 11, 1, 'DNS help', 'Open', 'Medium', '2026-10-05 10:00:00', '2026-10-05 09:00:00')");

    $pdo->exec("INSERT INTO tblaffiliates (id, clientid, date, visitors, balance) VALUES (1, 22, '2026-04-01', 120, '35.00')");
}

/** Freeze the clock to a deterministic moment. */
function ch247m_freeze($datetime = '2026-10-06 12:00:00')
{
    Clock::freeze(strtotime($datetime . ' UTC'));
}

/** Move the frozen clock forward. */
function ch247m_advance($seconds)
{
    Clock::freeze(Clock::time() + (int) $seconds);
}

/** Become super admin #1. */
function ch247m_as_super_admin()
{
    Identity::setAdmin(1);
    $_SESSION['adminid'] = 1;
}

/** A minimal but valid design document. */
function ch247m_design($extraHtml = '')
{
    return [
        'settings' => ['backgroundColor' => '#f4f5f7', 'contentWidth' => 600],
        'rows' => [
            [
                'id' => 'r1', 'layout' => 'col-1', 'widths' => [1], 'props' => [],
                'cells' => [[
                    ['id' => 'b1', 'type' => 'heading', 'props' => ['text' => 'Hello {{first_name}}']],
                    ['id' => 'b2', 'type' => 'text', 'props' => ['html' => '<p>Thanks for being with us.' . $extraHtml . '</p>']],
                    ['id' => 'b3', 'type' => 'button', 'props' => ['text' => 'View plans', 'href' => 'https://cloudhost247.test/plans']],
                    ['id' => 'b4', 'type' => 'footer', 'props' => ['showUnsubscribe' => true]],
                ]],
            ],
        ],
    ];
}

/** Create a subscriber quickly. */
function ch247m_subscriber($email, array $extra = [])
{
    return \Ch247Mkt\Audience\SubscriberService::upsert(array_merge([
        'email'          => $email,
        'first_name'     => 'Test',
        'last_name'      => 'Person',
        'consent_source' => 'unit test',
    ], $extra), ['actor' => 'system']);
}

/** Create a ready-to-send campaign targeting one list. */
function ch247m_campaign(array $listIds = [], array $overrides = [])
{
    // A "design" override is applied at the update step, not at create.
    $design = isset($overrides['design']) && is_array($overrides['design']) ? $overrides['design'] : ch247m_design();
    unset($overrides['design']);

    $campaign = \Ch247Mkt\Campaign\CampaignService::create(array_merge([
        'name'    => 'Test campaign',
        'subject' => 'Hello {{first_name}}',
    ], $overrides), 1);

    \Ch247Mkt\Campaign\CampaignService::update((int) $campaign['id'], [
        'design'   => $design,
        'audience' => ['lists' => $listIds],
    ], 1);

    return \Ch247Mkt\Campaign\CampaignService::find((int) $campaign['id']);
}
