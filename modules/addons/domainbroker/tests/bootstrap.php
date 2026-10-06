<?php
/**
 * Domain Broker — test harness.
 *
 * Boots the module against an in-memory SQLite database using the very same
 * migrations that ship to production, so the tests exercise the real schema,
 * the real services and the real state machines. WHMCS itself is replaced by
 * the recording FakeGateway, which lets the suite assert exactly which WHMCS
 * API calls the module would make (invoice creation, transactions, tickets,
 * emails) without needing a WHMCS install.
 *
 * No test touches the network, the filesystem outside a temp dir, or a real
 * payment provider.
 *
 * @package DomainBroker
 */

define('DOMAINBROKER_TESTING', true);

require_once dirname(__DIR__) . '/autoload.php';

use DomainBroker\Core\Actor;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Db;
use DomainBroker\Core\Http;
use DomainBroker\Core\Migrator;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Integration\Gateway;
use DomainBroker\Integration\FakeGateway;
use DomainBroker\Services\DomainIntelService;

/* ------------------------------------------------------------- assertions */

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

    public static function is($label, $expected, $actual)
    {
        if ($expected === $actual) {
            self::$pass++;
            return true;
        }
        self::$fail++;
        self::$failures[] = self::$section . ' / ' . $label;
        echo "  FAIL {$label}: expected " . self::dump($expected) . ' got ' . self::dump($actual) . "\n";
        return false;
    }

    public static function isnt($label, $notExpected, $actual)
    {
        return self::ok($label, $notExpected !== $actual);
    }

    public static function contains($label, $needle, $haystack)
    {
        $haystack = is_array($haystack) ? implode(' ', array_map('strval', $haystack)) : (string) $haystack;
        return self::ok($label . ' contains ' . $needle, strpos($haystack, (string) $needle) !== false);
    }

    /** Assert that $fn throws $class (or a subclass). */
    public static function throws($label, $class, callable $fn)
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                self::$pass++;
                return $e;
            }
            self::$fail++;
            self::$failures[] = self::$section . ' / ' . $label;
            echo "  FAIL {$label}: expected {$class} got " . get_class($e) . ' (' . $e->getMessage() . ")\n";
            return null;
        }
        self::$fail++;
        self::$failures[] = self::$section . ' / ' . $label;
        echo "  FAIL {$label}: expected {$class}, nothing thrown\n";
        return null;
    }

    /** Assert that $fn does NOT throw. */
    public static function nothrow($label, callable $fn)
    {
        try {
            $result = $fn();
            self::$pass++;
            return $result;
        } catch (\Throwable $e) {
            self::$fail++;
            self::$failures[] = self::$section . ' / ' . $label;
            echo "  FAIL {$label}: unexpected " . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
            return null;
        }
    }

    public static function summary()
    {
        echo "\nPASS=" . self::$pass . ' FAIL=' . self::$fail . "\n";
        return self::$fail === 0 ? 0 : 1;
    }

    protected static function dump($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return var_export($value, true);
    }
}

/* ------------------------------------------------------------- harness --- */

class Harness
{
    /** @var \PDO */
    public static $pdo;

    /** @var FakeGateway */
    public static $gateway;

    /** @var string */
    public static $storage;

    /**
     * Fresh database + fresh settings for each test file (and between cases
     * that need isolation).
     */
    public static function boot(array $settings = [])
    {
        putenv('DOMAINBROKER_ENCRYPTION_KEY=' . str_repeat('t3st-key-material', 4));

        self::$pdo = new \PDO('sqlite::memory:');
        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        Db::setPdo(self::$pdo);

        Crypto::reset();
        \DomainBroker\Core\RateLimiter::resetConfiguration();
        Settings::clearOverrides();
        Rbac::flush();
        Clock::unfreeze();
        Http::overrideIp('198.51.100.20');

        self::$storage = sys_get_temp_dir() . '/domainbroker-tests-' . bin2hex(random_bytes(4));
        @mkdir(self::$storage, 0700, true);

        Settings::overrideMany(array_merge([
            'document_storage_path' => self::$storage,
            'default_currency'      => 'USD',
            'allowed_currencies'    => 'USD,EUR,GBP',
            'rdap_enabled'          => '0',
            'notifications_email'   => '1',
            'notifications_inapp'   => '1',
        ], $settings));

        $migrator = new Migrator();
        $migrator->migrate();

        self::$gateway = new FakeGateway();
        Gateway::set(self::$gateway);
        DomainIntelService::setResolver(null);

        return self::$gateway;
    }

    /**
     * Widen the rate-limit buckets for suites that drive many operations in a
     * row. The limits themselves are covered by 01_CoreTest.
     */
    public static function relaxRateLimits()
    {
        \DomainBroker\Core\RateLimiter::configure([
            'request.create'  => [5000, 3600],
            'request.update'  => [5000, 3600],
            'offer.action'    => [5000, 3600],
            'offer.create'    => [5000, 3600],
            'message.send'    => [5000, 3600],
            'document.upload' => [5000, 3600],
            'domain.lookup'   => [5000, 60],
            'payment.action'  => [5000, 3600],
            'dispute.open'    => [5000, 86400],
            'api.read'        => [5000, 60],
            'api.write'       => [5000, 60],
        ]);
    }

    public static function shutdown()
    {
        Db::reset();
        Gateway::reset();
        Settings::clearOverrides();
        if (self::$storage && is_dir(self::$storage)) {
            self::rrmdir(self::$storage);
        }
    }

    protected static function rrmdir($dir)
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /* ----------------------------------------------------------- fixtures */

    public static function client($id = 1, $name = 'Ada Lovelace', array $extra = [])
    {
        self::$gateway->addClient(array_merge([
            'id' => $id, 'firstname' => 'Ada', 'lastname' => 'Lovelace',
            'companyname' => 'Analytical Engines Ltd',
            'email' => 'ada+' . $id . '@example.com',
            'currency' => 1, 'currency_code' => 'USD', 'status' => 'Active',
            'country' => 'GB',
        ], $extra));
        return Actor::customer($id, $name, ['ip' => '198.51.100.20', 'userAgent' => 'PHPUnitLike/1.0']);
    }

    public static function broker($displayName = 'Grace Hopper', $role = 'broker', $adminId = 10)
    {
        // Idempotent: fixtures call this repeatedly for the same staff member.
        $existing = \DomainBroker\Core\Db::first('brokers', [
            'whmcs_admin_id' => (int) $adminId, 'deleted_at' => null,
        ]);
        if ($existing) {
            return $existing;
        }

        $svc = new \DomainBroker\Services\BrokerDirectoryService();
        $admin = self::admin(1, 'admin_super');
        $broker = $svc->create($admin, [
            'display_name' => $displayName,
            'whmcs_admin_id' => $adminId,
            'email' => strtolower(str_replace(' ', '.', $displayName)) . '@brokers.example',
            'role' => $role,
            'commission_percentage' => '20',
        ]);
        return $broker;
    }

    public static function brokerActor(array $broker)
    {
        return Actor::broker($broker['id'], $broker['role'], $broker['display_name'], [
            'ip' => '203.0.113.9', 'adminId' => (int) $broker['whmcs_admin_id'],
        ]);
    }

    public static function admin($id = 1, $role = 'admin_super', $name = 'Root Admin')
    {
        return Actor::admin($id, $role, $name, ['ip' => '203.0.113.1']);
    }

    /**
     * Drive a request all the way to "offer accepted" using the real
     * services, so downstream suites start from a genuinely reachable state.
     *
     * @return array{request:array, customer:Actor, broker:Actor, brokerRow:array, admin:Actor, offer:array}
     */
    public static function acceptedRequest($domain = 'acquire-me.com', $clientId = 1, array $options = [])
    {
        $requests = new \DomainBroker\Services\RequestService();
        $assignments = new \DomainBroker\Services\AssignmentService();
        $negotiation = new \DomainBroker\Services\NegotiationService();

        $customer = self::client($clientId, 'Customer ' . $clientId);
        $admin = self::admin(1, 'admin_super');
        $brokerRow = isset($options['brokerRow'])
            ? $options['brokerRow']
            : self::broker('Grace Hopper', 'broker', 11);
        $broker = self::brokerActor($brokerRow);

        $request = $requests->create($customer, [
            'domain' => $domain,
            'budget' => isset($options['budget']) ? $options['budget'] : '10000.00',
            'currency' => isset($options['currency']) ? $options['currency'] : 'USD',
        ]);
        $request = $assignments->assign($admin, $request['id'], $brokerRow['id']);
        $negotiation->recordOwnerContact($broker, $request['id'], ['summary' => 'Approached the registrant.']);

        $offer = $negotiation->createOffer($broker, $request['id'], [
            'amount' => isset($options['amount']) ? $options['amount'] : '9000.00',
            'direction' => \DomainBroker\Workflow\OfferStatus::DIR_TO_CUSTOMER,
            'message' => 'The registrant will accept this figure.',
        ]);
        $offer = $negotiation->acceptOffer($customer, $offer['id']);

        return [
            'request' => $requests->findRow($request['id']),
            'customer' => $customer,
            'broker' => $broker,
            'brokerRow' => $brokerRow,
            'admin' => $admin,
            'offer' => $offer,
        ];
    }

    /**
     * As acceptedRequest(), then actually invoice the customer and settle the
     * invoice through the gateway so the request reaches "payment secured".
     *
     * @return array the acceptedRequest() payload plus 'payment'
     */
    public static function securedRequest($domain = 'secured.com', $clientId = 1, array $options = [])
    {
        $fixture = self::acceptedRequest($domain, $clientId, $options);
        $paymentService = new \DomainBroker\Services\PaymentService();
        $payment = $paymentService->generateInvoice($fixture['customer'], $fixture['request']['id']);
        self::$gateway->payInvoice((int) $payment['whmcs_invoice_id']);
        $payment = $paymentService->syncWithBilling($fixture['admin'], $payment['id']);

        $fixture['payment'] = $payment;
        $fixture['request'] = (new \DomainBroker\Services\RequestService())->findRow($fixture['request']['id']);
        return $fixture;
    }
}

/* Convenience global so test files read cleanly. */
function section($name)
{
    T::section($name);
}
