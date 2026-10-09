<?php
/** Exercise the legacy OVH transport offline through namespaced cURL shims. */
namespace WGSModule\Soyoustart\classes {
    function curl_init() { $GLOBALS['ovhCurlCalls']++; return new \stdClass(); }
    function curl_setopt($ch, $key, $value) { $GLOBALS['ovhCurlOptions'][$key] = $value; return true; }
    function curl_exec($ch) {
        return isset($GLOBALS['ovhReply']) ? $GLOBALS['ovhReply'] : '{"ok":true}';
    }
    function curl_getinfo($ch, $key) { return isset($GLOBALS['ovhStatus']) ? $GLOBALS['ovhStatus'] : 200; }
    function curl_error($ch) { return ''; }
    function curl_close($ch) {}
}

namespace WHMCS\Module\Addon\Soyoustart {
    function curl_init($url = null) { $GLOBALS['catalogCurlInitUrl'] = $url; return new \stdClass(); }
    function curl_setopt($ch, $key, $value) { $GLOBALS['catalogCurlOptions'][$key] = $value; return true; }
    function curl_exec($ch) {
        if (strpos((string) $GLOBALS['catalogCurlInitUrl'], 'getAvailableProducts.php') !== false) {
            $chunk = isset($GLOBALS['feedReply']) ? $GLOBALS['feedReply'] : '';
            $writer = $GLOBALS['catalogCurlOptions'][CURLOPT_WRITEFUNCTION];
            return $writer($ch, $chunk) === strlen($chunk);
        }
        return '{"plans":[]}';
    }
    function curl_getinfo($ch, $key) { return isset($GLOBALS['feedStatus']) ? $GLOBALS['feedStatus'] : 200; }
    function curl_errno($ch) { return 0; }
    function curl_error($ch) { return ''; }
    function curl_close($ch) {}
}

namespace WHMCS\Database {
    class Capsule {
        public static function table($name) {
            return new class($name) {
                private $table;
                public function __construct($table) { $this->table = $table; }
                public function where($key) { return $this; }
                public function first() {
                    if ($this->table === 'mod_soyoustart') {
                        return (object) ['secret_key' => 'test-secret',
                            'consumer_key' => 'test-consumer', 'application_key' => 'test-application'];
                    }
                    return !empty($GLOBALS['enableLegacyModuleLog'])
                        ? (object) ['id' => 1, 'value' => '{"moduleLogstatus":"on"}'] : null;
                }
                public function insert($row) { $GLOBALS['legacyLogRow'] = $row; }
            };
        }
    }
}

namespace {
    function logModuleCall($module, $action, $request, $response) {
        $GLOBALS['safeModuleCall'] = compact('module', 'action', 'request', 'response');
    }
    require_once __DIR__ . '/bootstrap.php';
    // php-wasm may not load ext/curl; the namespaced shims still need its option identifiers.
    foreach (['CURLOPT_URL', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_POSTFIELDS', 'CURLOPT_POST',
        'CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPGET', 'CURLOPT_TIMEOUT', 'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_MAXREDIRS', 'CURLOPT_HTTPHEADER', 'CURLOPT_TCP_KEEPALIVE', 'CURLOPT_HEADER',
        'CURLOPT_ENCODING', 'CURLOPT_HTTP_VERSION', 'CURLINFO_HTTP_CODE',
        'CURLOPT_WRITEFUNCTION'] as $index => $constant) {
        if (!defined($constant)) define($constant, 1000 + $index);
    }
    if (!defined('WHMCS')) define('WHMCS', true);
    require_once '/soyoustart/lib/Helper.php';
    require_once '/soyoustart/classes/ApiCall.php';
    require_once '/soyoustart/classes/Configuration.php';
    require_once '/soyoustart/lib/SafeLog.php';
    require_once '/soyoustart/lib/Admin/apicall.php';

    use WHMCS\Module\Addon\Soyoustart\TrustedEndpoint;
    use WGSModule\Soyoustart\classes\ApiCall;
    use WHMCS\Module\Addon\Soyoustart\SafeLog;

    section('Only trusted HTTPS OVH endpoints may receive signed headers');
    foreach (['api.ovh.com', 'eu.api.ovh.com', 'ca.api.ovh.com',
        'api.ca.ovhcloud.com', 'api.us.ovhcloud.com'] as $host) {
        T::nothrow('accept official API host ' . $host, function () use ($host) {
            TrustedEndpoint::assertOvhUrl('https://' . $host . '/1.0/vps', ['X-Ovh-Signature: signed']);
        });
    }
    T::nothrow('accept official public product catalog', function () {
        TrustedEndpoint::assertOvhUrl('https://www.ovh.com/engine/apiv6/order/catalog/public/eco?ovhSubsidiary=FR');
    });
    foreach ([
        'http://api.ovh.com/1.0/vps', 'https://api.ovh.com.evil.test/1.0/vps',
        'https://api.ovh.com@evil.test/1.0/vps', 'https://u:p@api.ovh.com/1.0/vps',
        'https://api.ovh.com:444/1.0/vps', 'https://api.ovh.com/1.0/vps#fragment',
        'https://127.0.0.1/1.0/vps', 'https://www.ovh.com/admin',
    ] as $url) {
        T::throws('reject untrusted URL ' . $url, \InvalidArgumentException::class, function () use ($url) {
            TrustedEndpoint::assertOvhUrl($url, ['X-Ovh-Signature: signed']);
        });
    }
    T::throws('catalog cannot receive any signed headers', \InvalidArgumentException::class, function () {
        TrustedEndpoint::assertOvhUrl('https://ca.ovh.com/engine/apiv6/order/catalog/public/vps',
            ['X-Ovh-Application: secret']);
    });

    section('Legacy API transport verifies TLS and never follows signed redirects');
    $GLOBALS['ovhCurlCalls'] = 0;
    $api = new ApiCall();
    T::throws('untrusted destination fails before cURL', \InvalidArgumentException::class, function () use ($api) {
        $api->__curlCall('GET', [], 'https://evil.test/1.0/vps', ['X-Ovh-Signature: signed']);
    });
    T::is('no cURL handle for rejected destination', 0, $GLOBALS['ovhCurlCalls']);
    $GLOBALS['ovhCurlOptions'] = [];
    $result = $api->__curlCall('GET', [], 'https://api.ovh.com/1.0/vps',
        ['X-Ovh-Signature: signed']);
    T::is('valid endpoint returns provider response', 200, $result['httpcode']);
    $GLOBALS['enableLegacyModuleLog'] = true;
    $GLOBALS['legacyLogRow'] = null;
    $api->__curlCall('POST', ['password' => 'private-fake-secret'],
        'https://api.ovh.com/1.0/vps', ['X-Ovh-Signature: private-fake-signature'], 'Test action');
    T::is('legacy module log hides the request', '[redacted]', $GLOBALS['legacyLogRow']['request']);
    T::is('legacy module log retains only HTTP status', '{"httpcode":200}',
        $GLOBALS['legacyLogRow']['response']);
    T::notContains('module log never stores signed headers or secrets', 'private-fake-',
        json_encode($GLOBALS['legacyLogRow']));
    $GLOBALS['enableLegacyModuleLog'] = false;
    T::is('certificate chain checked', true, $GLOBALS['ovhCurlOptions'][CURLOPT_SSL_VERIFYPEER]);
    T::is('hostname checked', 2, $GLOBALS['ovhCurlOptions'][CURLOPT_SSL_VERIFYHOST]);
    T::is('redirects disabled', false, $GLOBALS['ovhCurlOptions'][CURLOPT_FOLLOWLOCATION]);

    section('Narrow read-back uses only the legacy account and a signed GET');
    $GLOBALS['ovhReply'] = '{"name":"vps-123.ovh.net","state":"running","password":"never-projected"}';
    $GLOBALS['ovhStatus'] = 200;
    $GLOBALS['ovhCurlOptions'] = [];
    $before = $GLOBALS['ovhCurlCalls'];
    $identity = $api->getVpsIdentity('europe', 'vps-123.ovh.net', 4);
    T::is('only name and state are returned', ['name' => 'vps-123.ovh.net', 'state' => 'running'], $identity);
    T::is('exactly one signed provider read', $before + 1, $GLOBALS['ovhCurlCalls']);
    T::is('GET method selected', true, $GLOBALS['ovhCurlOptions'][CURLOPT_HTTPGET]);
    T::ok('no provider purchase or mutation method selected',
        !isset($GLOBALS['ovhCurlOptions'][CURLOPT_POST])
        && !isset($GLOBALS['ovhCurlOptions'][CURLOPT_CUSTOMREQUEST]));
    T::is('read uses fixed legacy OVH endpoint', 'https://eu.api.ovh.com/1.0/vps/vps-123.ovh.net',
        $GLOBALS['ovhCurlOptions'][CURLOPT_URL]);
    $headers = $GLOBALS['ovhCurlOptions'][CURLOPT_HTTPHEADER];
    $timestamp = null;
    $signature = null;
    foreach ($headers as $header) {
        if (strpos($header, 'X-Ovh-Timestamp:') === 0) { $timestamp = substr($header, strlen('X-Ovh-Timestamp:')); }
        if (strpos($header, 'X-Ovh-Signature:') === 0) { $signature = substr($header, strlen('X-Ovh-Signature:')); }
    }
    T::is('signature uses the transmitted timestamp', '$1$' . sha1('test-secret+test-consumer+GET+'
        . 'https://eu.api.ovh.com/1.0/vps/vps-123.ovh.net++' . $timestamp), $signature);
    T::notContains('response never contains provider-only data', 'never-projected', json_encode($identity));
    $GLOBALS['ovhReply'] = '{"name":"another.ovh.net","state":"running"}';
    T::throws('wrong provider identity fails closed', \RuntimeException::class,
        function () use ($api) { $api->getVpsIdentity('europe', 'vps-123.ovh.net', 4); });
    $GLOBALS['ovhReply'] = '{"name":"vps-123.ovh.net","state":"running"}';
    $GLOBALS['ovhStatus'] = 404;
    T::throws('missing provider resource fails closed', \RuntimeException::class,
        function () use ($api) { $api->getVpsIdentity('europe', 'vps-123.ovh.net', 4); });
    $GLOBALS['ovhStatus'] = 200;
    $before = $GLOBALS['ovhCurlCalls'];
    T::throws('path injection rejected before network', \InvalidArgumentException::class,
        function () use ($api) { $api->getVpsIdentity('europe', '../other', 4); });
    T::throws('untrusted region rejected before network', \InvalidArgumentException::class,
        function () use ($api) { $api->getVpsIdentity('evil.test', 'vps-123.ovh.net', 4); });
    T::is('invalid inputs never touched provider', $before, $GLOBALS['ovhCurlCalls']);
    unset($GLOBALS['ovhReply'], $GLOBALS['ovhStatus']);

    section('Unsigned product catalog uses the same TLS protections');
    $GLOBALS['catalogCurlOptions'] = [];
    $catalog = (new \WHMCS\Module\Addon\Soyoustart\Helper())->getProductApiRequest(
        'https://ca.ovh.com/engine/apiv6/order/catalog/public/vps?ovhSubsidiary=CA');
    T::ok('catalog JSON parsed', is_object($catalog));
    T::is('catalog TLS verifies certificate', true, $GLOBALS['catalogCurlOptions'][CURLOPT_SSL_VERIFYPEER]);
    T::is('catalog TLS verifies hostname', 2, $GLOBALS['catalogCurlOptions'][CURLOPT_SSL_VERIFYHOST]);
    T::is('catalog does not follow redirects', false, $GLOBALS['catalogCurlOptions'][CURLOPT_FOLLOWLOCATION]);
    T::throws('catalog rejects attacker host', \InvalidArgumentException::class, function () {
        (new \WHMCS\Module\Addon\Soyoustart\Helper())->getProductApiRequest('https://evil.test/catalog');
    });
    T::throws('Google OAuth rejects attacker host', \InvalidArgumentException::class, function () {
        TrustedEndpoint::assertGoogleUrl('https://evil.test/oauth2/token');
    });
    $GLOBALS['catalogCurlOptions'] = [];
    (new \WHMCS\Module\Addon\Soyoustart\Helper())->getAccessToken(
        'https://oauth2.googleapis.com/token', ['grant_type' => 'authorization_code']);
    T::is('Google OAuth verifies TLS certificate', true, $GLOBALS['catalogCurlOptions'][CURLOPT_SSL_VERIFYPEER]);
    T::is('Google OAuth verifies TLS hostname', 2, $GLOBALS['catalogCurlOptions'][CURLOPT_SSL_VERIFYHOST]);
    T::is('Google OAuth refuses redirects', false, $GLOBALS['catalogCurlOptions'][CURLOPT_FOLLOWLOCATION]);
    section('Legacy billing and provisioning logs contain only non-secret metadata');
    foreach (['/soyoustart_vps/soyoustart_vps.php', '/soyoustart_vps/hooks.php',
        '/soyoustart/classes/ExistingServer.php', '/soyoustart/lib/SafeLog.php'] as $file) {
        T::nothrow('changed legacy PHP parses: ' . basename($file), function () use ($file) {
            token_get_all(file_get_contents($file), TOKEN_PARSE);
        });
    }
    T::notContains('server lifecycle does not log raw WHMCS params', 'logModuleCall(',
        file_get_contents('/soyoustart_vps/soyoustart_vps.php'));
    SafeLog::failure('soyoustart_vps', 'CreateAccount',
        ['serviceid' => 123, 'password' => 'fake-super-secret'],
        new \Exception('fake-secret-in-error'));
    T::is('service ID retained for audit', 123, $GLOBALS['safeModuleCall']['request']['serviceid']);
    T::notContains('exception message and WHMCS passwords never logged', 'fake-',
        json_encode($GLOBALS['safeModuleCall']));
    SafeLog::orderResult('Soyoustart', ['configoptions' => 'fake-encoded-secret'],
        ['result' => 'success', 'secret' => 'fake-secret-result']);
    T::is('order outcome retained', 'success', $GLOBALS['safeModuleCall']['response']['status']);
    T::notContains('order payload and response never logged', 'fake-',
        json_encode($GLOBALS['safeModuleCall']));

    section('Legacy third-party availability feed fails closed without a deployment secret');
    putenv('SOYOUSTART_AVAILABILITY_FEED_SECRET');
    $helper = new \WHMCS\Module\Addon\Soyoustart\Helper();
    $GLOBALS['catalogCurlInitUrl'] = null;
    T::throws('missing environment secret blocks requests', \RuntimeException::class,
        function () use ($helper) { $helper->getAvailableProduts(); });
    T::is('no cURL handle opened for an unconfigured feed', null, $GLOBALS['catalogCurlInitUrl']);
    putenv('SOYOUSTART_AVAILABILITY_FEED_SECRET=' . str_repeat('X', 48));
    $GLOBALS['feedReply'] = '{"status":"success","data":{"VPS":{"US":["vps-plan"]}}}';
    $GLOBALS['feedStatus'] = 200;
    $GLOBALS['catalogCurlOptions'] = [];
    T::is('configured feed parses bounded JSON', 'vps-plan',
        $helper->getAvailableProduts()['data']['VPS']['US'][0]);
    T::is('feed cert verified', true, $GLOBALS['catalogCurlOptions'][CURLOPT_SSL_VERIFYPEER]);
    T::is('feed hostname verified', 2, $GLOBALS['catalogCurlOptions'][CURLOPT_SSL_VERIFYHOST]);
    T::is('feed redirects disabled', false, $GLOBALS['catalogCurlOptions'][CURLOPT_FOLLOWLOCATION]);
    T::ok('feed request signature does not expose the secret',
        strpos(json_encode($GLOBALS['catalogCurlOptions'][CURLOPT_HTTPHEADER]), str_repeat('X', 48)) === false);
    $GLOBALS['feedReply'] = str_repeat('x', 1048577);
    T::throws('oversized reply rejected before JSON parsing', \RuntimeException::class,
        function () use ($helper) { $helper->getAvailableProduts(); });
    $GLOBALS['feedReply'] = '{"status":"fail","message":"untrusted response"}';
    T::throws('feed error never becomes a trusted product list', \RuntimeException::class,
        function () use ($helper) { $helper->getAvailableProduts(); });
    $GLOBALS['feedReply'] = '{"status":"success","data":{"VPS":{"US":["vps-plan"]}}}';
    $GLOBALS['feedStatus'] = 503;
    T::throws('HTTP failure rejects the feed', \RuntimeException::class,
        function () use ($helper) { $helper->getAvailableProduts(); });
    $config = (new \ReflectionClass(\WGSModule\Soyoustart\classes\Configuration::class))
        ->newInstanceWithoutConstructor();
    $GLOBALS['feedStatus'] = 200;
    $GLOBALS['feedReply'] = '{"status":"success","data":{"VPS":{"US":[]}}}';
    T::throws('empty regional inventory cannot mark every plan available', \RuntimeException::class,
        function () use ($config) { $config->getAvailableProducts('VPS', 'US'); });
    $GLOBALS['feedReply'] = '{"status":"success","data":{"VPS":{"US":["vps-plan"]}}}';
    T::is('configured availability only allows explicit plan codes', ['vps-plan'],
        $config->getAvailableProducts('VPS', 'US'));
    putenv('SOYOUSTART_AVAILABILITY_FEED_SECRET');
    exit(T::summary());
}
