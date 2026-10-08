<?php
/**
 * CloudHost247 App Cloud — test harness.
 *
 * Boots the module against an in-memory SQLite database using the very same
 * migrations that ship to production, so the suites exercise the real schema,
 * the real services and the real state machines. WHMCS is replaced by the
 * recording FakeGateway, outbound HTTP (agent, WHM, UAPI, Kubernetes, DNS, ACME,
 * object storage) is replaced by a scripted transport fake, and the deployment
 * adapter can be swapped for the recording FakeAdapter — which is how the suite
 * asserts exactly what the platform would do to infrastructure without touching
 * any.
 *
 * No test touches the network, a real server, a real payment provider or the
 * filesystem outside a temp directory.
 *
 * @package Ch247Apps
 */

define('CH247APPS_TESTING', true);

require_once dirname(__DIR__) . '/autoload.php';

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Migrator;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\RateLimiter;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Whmcs;
use Ch247Apps\Integration\FakeGateway;
use Ch247Apps\Integration\Gateway;

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
        echo '  FAIL ' . $label . "\n";
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
        echo '  FAIL ' . $label . ': expected ' . self::dump($expected)
            . ' got ' . self::dump($actual) . "\n";
        return false;
    }

    public static function isnt($label, $notExpected, $actual)
    {
        return self::ok($label, $notExpected !== $actual);
    }

    public static function contains($label, $needle, $haystack)
    {
        if (is_array($haystack)) {
            $haystack = implode(' ', array_map(function ($v) {
                return is_scalar($v) ? (string) $v : json_encode($v);
            }, $haystack));
        }
        return self::ok($label . ' contains "' . $needle . '"', strpos((string) $haystack, (string) $needle) !== false);
    }

    public static function notContains($label, $needle, $haystack)
    {
        if (is_array($haystack)) {
            $haystack = implode(' ', array_map(function ($v) {
                return is_scalar($v) ? (string) $v : json_encode($v);
            }, $haystack));
        }
        return self::ok($label . ' does not contain "' . $needle . '"', strpos((string) $haystack, (string) $needle) === false);
    }

    /** Assert $fn throws $class (or a subclass). Returns the exception. */
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
            echo '  FAIL ' . $label . ': expected ' . $class . ' got ' . get_class($e)
                . ' (' . $e->getMessage() . ")\n";
            return null;
        }
        self::$fail++;
        self::$failures[] = self::$section . ' / ' . $label;
        echo '  FAIL ' . $label . ': expected ' . $class . ", nothing thrown\n";
        return null;
    }

    /** Assert $fn does not throw; returns its value. */
    public static function nothrow($label, callable $fn)
    {
        try {
            $result = $fn();
            self::$pass++;
            return $result;
        } catch (\Throwable $e) {
            self::$fail++;
            self::$failures[] = self::$section . ' / ' . $label;
            echo '  FAIL ' . $label . ': unexpected ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
            return null;
        }
    }

    public static function summary()
    {
        echo "\n";
        if (self::$fail > 0) {
            foreach (self::$failures as $failure) {
                echo '  - ' . $failure . "\n";
            }
        }
        echo 'PASS=' . self::$pass . ' FAIL=' . self::$fail . "\n";
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

function section($name)
{
    T::section($name);
}

/* ----------------------------------------------------------- environment -- */

class Harness
{
    /** @var FakeGateway */
    /** The AES-256-GCM key every harness boot uses; re-apply after resetOverrides(). */
    const ENCRYPTION_KEY = 'harness-encryption-key-0123456789abcdef';

    public static $gateway;

    /** @var array scripted outbound HTTP responses: url-substring => response */
    private static $httpScript = [];

    /** @var string */
    private static $tmp = '';

    /**
     * Fresh database, settings, identities and fakes.
     *
     * @param array $settings setting overrides applied before boot
     * @return FakeGateway
     */
    public static function boot(array $settings = [])
    {
        Db::reset();
        Settings::flush();
        Settings::resetOverrides();
        Crypto::reset();
        Clock::unfreeze();
        Identity::reset();
        Rbac::flush();
        Rbac::resetForced();
        Events::resetListeners();
        Logger::stopCapture();
        Http::resetOverrides();
        Http::setClientFake(null);
        Audit::resetHead();
        RateLimiter::resetConfiguration();
        Gateway::reset();
        Whmcs::reset();
        // A fake adapter from a previous scenario must never leak into this one.
        \Ch247Apps\Adapters\AdapterFactory::reset();
        \Ch247Apps\Infrastructure\ProviderRegistry::reset();
        \Ch247Apps\Infrastructure\ProviderBootstrap::reset();
        $_SESSION = [];
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.7', 'REQUEST_METHOD' => 'GET'];
        $_REQUEST = [];
        $_GET = [];
        $_POST = [];

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        Db::setPdo($pdo, 'sqlite');

        // Secrets come from the environment in production; the harness supplies
        // deterministic ones so encryption, signing and rotation are all real.
        Settings::override('encryption_key', self::ENCRYPTION_KEY);
        Settings::overrideMany(array_merge([
            'marketplace_enabled' => '1',
            'install_enabled' => '1',
            'worker_enabled' => '1',
            'install_requires_paid_order' => '1',
            'bootstrap_admin_id' => '1',
            'default_admin_role' => 'staff',
            'acme_email' => 'ssl@cloudhost247.test',
            'free_subdomain_suffix' => 'apps.cloudhost247.test',
            'backup_storage_provider' => 'local',
            'queue_max_attempts' => '3',
            'queue_backoff_base_seconds' => '1',
            'recovery_enabled' => '1',
            'grace_period_days' => '7',
            'past_due_suspend_after_days' => '3',
            'terminate_after_days' => '30',
            'agent_token_ttl_seconds' => '600',
            'agent_request_ttl_seconds' => '120',
            'debug_logging' => '1',
            'log_level' => 'debug',
        ], $settings));

        $migrator = new Migrator(dirname(__DIR__) . '/install/migrations');
        $migrator->migrate();
        Rbac::seedMatrix();

        self::$gateway = new FakeGateway();
        self::$gateway->addCurrency(['id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'rate' => 1.0, 'default' => true]);
        self::$gateway->setConfig('SystemURL', 'https://cloudhost247.test/whmcs/');
        self::$gateway->setConfig('CompanyName', 'CloudHost247');
        Gateway::set(self::$gateway);

        self::$httpScript = [];
        Http::setClientFake([__CLASS__, 'httpFake']);

        self::$tmp = sys_get_temp_dir() . '/ch247apps-' . bin2hex(random_bytes(4));
        @mkdir(self::$tmp, 0777, true);

        return self::$gateway;
    }

    /** Production rate limits are real; suites that write a lot relax them. */
    public static function relaxRateLimits()
    {
        RateLimiter::configure([
            'default' => [100000, 60],
            'app.browse' => [100000, 60],
            'install.create' => [100000, 3600],
            'install.action' => [100000, 3600],
            'deployment.view' => [100000, 60],
            'server.action' => [100000, 60],
            'domain.action' => [100000, 600],
            'ssl.action' => [100000, 3600],
            'backup.action' => [100000, 3600],
            'catalog.write' => [100000, 3600],
            'auth' => [100000, 900],
            'webhook' => [100000, 60],
            'agent' => [100000, 60],
            'logs.view' => [100000, 60],
        ]);
    }

    public static function tmpDir()
    {
        return self::$tmp;
    }

    /* ---------------------------------------------------------- HTTP fake -- */

    /**
     * Script an outbound response. Later scripts win, so a suite can override.
     *
     * @param array $response status, body, headers, error
     */
    public static function onHttp($urlContains, array $response)
    {
        self::$httpScript[] = ['match' => (string) $urlContains, 'response' => $response];
    }

    public static function httpFake($method, $url, array $options = [])
    {
        foreach (array_reverse(self::$httpScript) as $script) {
            if ($script['match'] === '*' || strpos($url, $script['match']) !== false) {
                $response = $script['response'];
                if (is_callable($response)) {
                    $response = call_user_func($response, $method, $url, $options);
                }
                return array_merge(['status' => 200, 'headers' => [], 'body' => '', 'error' => null], $response);
            }
        }
        return ['status' => 599, 'headers' => [], 'body' => '', 'error' => 'No HTTP script matched ' . $url];
    }

    /* ------------------------------------------------------------ fixtures -- */

    /** A customer in WHMCS plus a matching client record in the fake gateway. */
    public static function client(array $overrides = [])
    {
        $id = self::$gateway->addClient(array_merge([
            'firstname' => 'Ada', 'lastname' => 'Obi', 'email' => 'ada@example.test', 'status' => 'Active',
        ], $overrides));
        return $id;
    }

    public static function customerActor($clientId = null)
    {
        $clientId = $clientId ?: self::client();
        Identity::override(Actor::customer($clientId, 'Ada Obi', ['ip' => '203.0.113.7']));
        return Identity::current();
    }

    public static function adminActor($adminId = 1, $role = Actor::ROLE_SUPER_ADMIN)
    {
        Identity::override(Actor::admin($adminId, $role, 'Root Admin', ['ip' => '198.51.100.4']));
        return Identity::current();
    }

    public static function staffActor($adminId = 2, $role = Actor::ROLE_STAFF)
    {
        Identity::override(Actor::admin($adminId, $role, 'Support Staff', ['ip' => '198.51.100.5']));
        return Identity::current();
    }

    public static function systemActor()
    {
        return Actor::system('Worker');
    }

    /** A registered, online Docker server with an active agent. */
    public static function server(array $overrides = [])
    {
        // Registering infrastructure is an administrator action: the worker role
        // deliberately cannot do it, so the fixture must not either.
        $service = new \Ch247Apps\Servers\ServerService(
            Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Harness')
        );
        return $service->register(array_merge([
            'name' => 'app-node-01',
            'hostname' => 'app-node-01.cloudhost247.net',
            'ip_address' => '198.51.100.10',
            'server_type' => 'vps',
            'provider' => 'hetzner',
            'region' => 'eu-central',
            'cpu_cores' => 8,
            'memory_mb' => 16384,
            'storage_mb' => 204800,
            'docker_enabled' => true,
            'accepts_new_installs' => true,
            'agent_endpoint' => 'https://198.51.100.10:8443',
            'agent_secret' => 'harness-agent-secret-0123456789',
        ], $overrides));
    }

    /**
     * A server that has checked in: registered *and* online, so it is eligible
     * for scheduling. A freshly registered server is deliberately not.
     */
    public static function onlineServer(array $overrides = [])
    {
        $service = new \Ch247Apps\Servers\ServerService(
            Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Harness')
        );
        $server = self::server($overrides);
        $agents = $service->agents((int) $server['id']);
        if ($agents !== []) {
            $service->heartbeat($agents[0]['agent_uuid'], [
                'agent_version' => '1.4.2',
                'ip' => '198.51.100.10',
            ]);
        }
        return $service->present((int) $server['id']);
    }

    /** Import the shipped manifests and publish the validated set. */
    public static function catalog($publish = true)
    {
        // Catalog writes are an administrator action; the worker role cannot do
        // them, so the fixture must not either.
        $importer = new \Ch247Apps\Catalog\CatalogImporter(
            Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Harness')
        );
        $result = $importer->importFromDirectory(CH247APPS_MANIFEST_PATH, ['publish' => $publish]);
        return $result;
    }

    public static function application($slug = 'n8n')
    {
        return Db::first('applications', ['slug' => $slug]);
    }

    public static function version($slug = 'n8n')
    {
        $app = self::application($slug);
        if (!$app) {
            return null;
        }
        return Db::first('application_versions', [
            'application_id' => (int) $app['id'], 'status' => 'published',
        ], ['order' => 'id', 'dir' => 'desc']);
    }

    public static function plan(array $overrides = [])
    {
        // Plans are an administrator resource; the system/worker role deliberately
        // cannot manage billing, so the fixture must not either.
        $service = new \Ch247Apps\Billing\PlanService(
            Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Harness')
        );
        return $service->create(array_merge([
            'name' => 'App Cloud Starter',
            'slug' => 'app-cloud-starter',
            'billing_interval' => 'monthly',
            'cpu_millicores' => 2000,
            'memory_mb' => 4096,
            'storage_mb' => 51200,
            'bandwidth_mb' => 1024000,
            'price_minor' => 2500,
            'currency' => 'USD',
            'deployment_types' => ['docker-compose'],
            'server_types' => ['vps', 'dedicated'],
        ], $overrides));
    }

    /**
     * A paid, ready-to-deploy installation (or an unpaid one when $paid is
     * false — the billing gate suites need both).
     */
    public static function installation(array $overrides = [], $paid = true)
    {
        $clientId = isset($overrides['customer_id']) ? (int) $overrides['customer_id'] : self::client();
        $appSlug = isset($overrides['application_slug']) ? $overrides['application_slug'] : 'n8n';
        unset($overrides['application_slug']);

        $app = self::application($appSlug);
        $version = self::version($appSlug);
        if (!$app || !$version) {
            self::catalog();
            $app = self::application($appSlug);
            $version = self::version($appSlug);
        }
        $server = self::onlineServer();
        $plan = self::plan();

        $order = self::$gateway->createOrder([
            'clientid' => $clientId,
            'pid' => (int) $plan['whmcs_product_id'],
            'billingcycle' => 'Monthly',
            'domain' => isset($overrides['domain']) ? $overrides['domain'] : '',
        ]);
        if ($paid) {
            self::$gateway->payInvoice($order['invoice_id']);
        }

        $service = new \Ch247Apps\Deployments\InstallationService(Actor::customer($clientId, 'Ada Obi'));
        $installation = $service->create(array_merge([
            'customer_id' => $clientId,
            'application_id' => (int) $app['id'],
            'application_version_id' => (int) $version['id'],
            'server_id' => (int) $server['id'],
            'plan_id' => (int) $plan['id'],
            'name' => 'Harness ' . $appSlug,
            'domain' => $appSlug . '.example.test',
            'whmcs_order_id' => $order['order_id'],
            'whmcs_invoice_id' => $order['invoice_id'],
            'whmcs_service_id' => isset($order['service_id']) ? $order['service_id'] : null,
            'payment_status' => $paid ? 'paid' : 'unpaid',
            'environment' => ['TZ' => 'Africa/Lagos'],
        ], $overrides));

        if ($paid) {
            $billing = new \Ch247Apps\Billing\PaymentGate(Actor::system('Harness'));
            $billing->confirmInvoicePaid($order['invoice_id'], 'harness');
        }

        return $installation;
    }

    public static function shutdown()
    {
        if (self::$tmp !== '' && is_dir(self::$tmp)) {
            foreach (glob(self::$tmp . '/*') ?: [] as $file) {
                @is_dir($file) ? self::removeDir($file) : @unlink($file);
            }
            @rmdir(self::$tmp);
        }
        self::$tmp = '';
        Http::setClientFake(null);
        Gateway::reset();
        Db::reset();
    }

    private static function removeDir($dir)
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? self::removeDir($file) : @unlink($file);
        }
        @rmdir($dir);
    }
}
