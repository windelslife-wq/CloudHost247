<?php
/** Exercise the legacy OVH transport offline through namespaced cURL shims. */
namespace WGSModule\Soyoustart\classes {
    function curl_init() { $GLOBALS['ovhCurlCalls']++; return new \stdClass(); }
    function curl_setopt($ch, $key, $value) { $GLOBALS['ovhCurlOptions'][$key] = $value; return true; }
    function curl_exec($ch) { return '{"ok":true}'; }
    function curl_getinfo($ch, $key) { return 200; }
    function curl_error($ch) { return ''; }
    function curl_close($ch) {}
}

namespace WHMCS\Module\Addon\Soyoustart {
    function curl_init() { return new \stdClass(); }
    function curl_setopt($ch, $key, $value) { $GLOBALS['catalogCurlOptions'][$key] = $value; return true; }
    function curl_exec($ch) { return '{"plans":[]}'; }
    function curl_getinfo($ch, $key) { return 200; }
    function curl_errno($ch) { return 0; }
    function curl_error($ch) { return ''; }
    function curl_close($ch) {}
}

namespace WHMCS\Database {
    class Capsule {
        public static function table($name) {
            return new class {
                public function where($key) { return $this; }
                public function first() { return null; }
            };
        }
    }
}

namespace {
    require_once __DIR__ . '/bootstrap.php';
    // php-wasm may not load ext/curl; the namespaced shims still need its option identifiers.
    foreach (['CURLOPT_URL', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_POSTFIELDS', 'CURLOPT_POST',
        'CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPGET', 'CURLOPT_TIMEOUT', 'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_MAXREDIRS', 'CURLOPT_HTTPHEADER', 'CURLOPT_TCP_KEEPALIVE', 'CURLOPT_HEADER',
        'CURLOPT_ENCODING', 'CURLOPT_HTTP_VERSION', 'CURLINFO_HTTP_CODE'] as $index => $constant) {
        if (!defined($constant)) define($constant, 1000 + $index);
    }
    if (!defined('WHMCS')) define('WHMCS', true);
    require_once '/soyoustart/lib/Helper.php';
    require_once '/soyoustart/classes/ApiCall.php';
    require_once '/soyoustart/lib/Admin/apicall.php';

    use WHMCS\Module\Addon\Soyoustart\TrustedEndpoint;
    use WGSModule\Soyoustart\classes\ApiCall;

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
    T::is('certificate chain checked', true, $GLOBALS['ovhCurlOptions'][CURLOPT_SSL_VERIFYPEER]);
    T::is('hostname checked', 2, $GLOBALS['ovhCurlOptions'][CURLOPT_SSL_VERIFYHOST]);
    T::is('redirects disabled', false, $GLOBALS['ovhCurlOptions'][CURLOPT_FOLLOWLOCATION]);

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
    exit(T::summary());
}
