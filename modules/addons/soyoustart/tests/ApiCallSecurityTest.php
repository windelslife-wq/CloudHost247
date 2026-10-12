<?php
/** Offline regression coverage for the in-tree OVH API header and OS-path fixes. */
namespace WGSModule\Soyoustart\classes {
    function curl_init() {
        $GLOBALS['soyouCurlCalls']++;
        return new \stdClass();
    }
    function curl_setopt($handle, $option, $value) {
        $GLOBALS['soyouCurlOptions'][$option] = $value;
        return true;
    }
    function curl_exec($handle) { return '{"ok":true}'; }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}

namespace WHMCS\Database {
    class Capsule
    {
        public static $accounts = [];

        public static function table($table)
        {
            return new FakeQuery($table);
        }
    }

    class FakeQuery
    {
        private $table;
        private $conditions = [];
        private $order = null;

        public function __construct($table) { $this->table = $table; }

        public function where($column, $operator = null, $value = null)
        {
            if (is_array($column)) {
                foreach ($column as $key => $expected) {
                    $this->conditions[$key] = $expected;
                }
                return $this;
            }
            if (func_num_args() === 2) {
                $value = $operator;
            }
            $this->conditions[$column] = $value;
            return $this;
        }

        public function orderBy($column, $direction = 'asc')
        {
            $this->order = [$column, strtolower((string) $direction)];
            return $this;
        }

        public function first()
        {
            if ($this->table !== 'mod_soyoustart') {
                return null;
            }
            $accounts = Capsule::$accounts;
            if ($this->order !== null && $this->order[0] === 'id') {
                $direction = $this->order[1] === 'desc' ? -1 : 1;
                usort($accounts, function ($a, $b) use ($direction) {
                    return ((int) $a->id <=> (int) $b->id) * $direction;
                });
            }
            foreach ($accounts as $account) {
                $matches = true;
                foreach ($this->conditions as $field => $expected) {
                    if (!isset($account->$field) || (string) $account->$field !== (string) $expected) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    return $account;
                }
            }
            return null;
        }
    }
}

namespace {
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }
    foreach ([
        'CURLOPT_POSTFIELDS', 'CURLOPT_POST', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPGET',
        'CURLOPT_URL', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_TIMEOUT', 'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_MAXREDIRS', 'CURLOPT_HTTPHEADER', 'CURLOPT_TCP_KEEPALIVE', 'CURLINFO_HTTP_CODE',
    ] as $index => $name) {
        if (!defined($name)) {
            define($name, 2000 + $index);
        }
    }

    require_once dirname(__DIR__) . '/lib/Helper.php';
    require_once dirname(__DIR__) . '/classes/ApiCall.php';

    $pass = 0;
    $fail = 0;
    $failures = [];
    $GLOBALS['soyouCurlCalls'] = 0;
    $GLOBALS['soyouCurlOptions'] = [];

    function soyouCheck($label, $condition)
    {
        global $pass, $fail, $failures;
        if ($condition) {
            $pass++;
            return;
        }
        $fail++;
        $failures[] = $label;
        echo "FAIL: {$label}\n";
    }

    function soyouHeaderValue($prefix)
    {
        foreach (($GLOBALS['soyouCurlOptions'][CURLOPT_HTTPHEADER] ?? []) as $header) {
            if (strpos($header, $prefix) === 0) {
                return substr($header, strlen($prefix));
            }
        }
        return null;
    }

    function soyouAttempt(callable $callback)
    {
        try {
            $callback();
            return null;
        } catch (\Throwable $error) {
            return $error;
        }
    }

    $api = new \WGSModule\Soyoustart\classes\ApiCall();
    \WHMCS\Database\Capsule::$accounts = [
        (object) ['id' => 9, 'status' => '1', 'location' => 'canada', 'application_key' => 'configured-ca-key'],
        (object) ['id' => 2, 'status' => '1', 'location' => 'europe', 'application_key' => 'configured-eu-key'],
        (object) ['id' => 11, 'status' => '0', 'location' => 'europe', 'application_key' => 'inactive-key'],
    ];

    echo "== configured application headers ==\n";
    $GLOBALS['soyouCurlOptions'] = [];
    $api->get('/partner', 9, 'canada', 'Configured app-key GET');
    soyouCheck('unsigned GET uses the selected account application key',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-ca-key');

    $GLOBALS['soyouCurlOptions'] = [];
    $api->post('/order/cart', 2, 'europe', [], 'Configured app-key POST');
    soyouCheck('unsigned POST uses the selected account application key',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-eu-key');

    $GLOBALS['soyouCurlOptions'] = [];
    $api->put('/order/cart', 2, 'europe', [], 'Configured app-key PUT');
    soyouCheck('unsigned PUT uses the selected account application key',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-eu-key');

    $GLOBALS['soyouCurlOptions'] = [];
    $api->__delete('/order/cart', 2, 'europe', [], 'Configured app-key DELETE');
    soyouCheck('unsigned DELETE uses the selected account application key',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-eu-key');

    $GLOBALS['soyouCurlOptions'] = [];
    $api->addItemInCart('/order/cart/abc/vps', 9, ['quantity' => 1], 'canada');
    soyouCheck('cart item request uses configured application key',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-ca-key');

    $GLOBALS['soyouCurlOptions'] = [];
    $api->addConfigOptInCart('/order/cart/abc/options', 2, ['quantity' => 1], 'europe');
    soyouCheck('cart configuration request uses configured application key',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-eu-key');

    $GLOBALS['soyouCurlOptions'] = [];
    $api->createCart('canada', 'test cart', 'CA', 9);
    soyouCheck('cart creation uses explicitly supplied account application key',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-ca-key');

    $GLOBALS['soyouCurlOptions'] = [];
    $api->createCart('europe', 'legacy-compatible cart', 'EU');
    soyouCheck('legacy createCart callers resolve the key by their explicit region',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-eu-key');

    $before = $GLOBALS['soyouCurlCalls'];
    $missingAccount = soyouAttempt(function () use ($api) {
        $api->get('/partner', 99, 'europe', 'Unknown account');
    });
    soyouCheck('unknown account fails closed instead of using another application key',
        $missingAccount instanceof \Throwable && $GLOBALS['soyouCurlCalls'] === $before);

    \WHMCS\Database\Capsule::$accounts = [
        (object) ['id' => 9, 'status' => '1', 'location' => 'canada', 'application_key' => "bad-key\r\nX-Evil: yes"],
    ];
    $before = $GLOBALS['soyouCurlCalls'];
    $invalidKey = soyouAttempt(function () use ($api) {
        $api->get('/partner', 9, 'canada', 'Invalid key');
    });
    soyouCheck('control characters in a configured key fail before cURL',
        $invalidKey instanceof \Throwable && $GLOBALS['soyouCurlCalls'] === $before);

    echo "== OS catalog path validation ==\n";
    \WHMCS\Database\Capsule::$accounts = [
        (object) ['id' => 12, 'status' => '1', 'location' => 'canada', 'application_key' => 'later-ca-key'],
        (object) ['id' => 9, 'status' => '1', 'location' => 'canada', 'application_key' => 'configured-ca-key'],
        (object) ['id' => 2, 'status' => '1', 'location' => 'europe', 'application_key' => 'configured-eu-key'],
    ];

    $GLOBALS['soyouCurlOptions'] = [];
    $api->getOs();
    soyouCheck('no-argument OS listing keeps the collection endpoint',
        ($GLOBALS['soyouCurlOptions'][CURLOPT_URL] ?? null)
            === 'https://ca.api.ovh.com/1.0/dedicated/installationTemplate/');
    soyouCheck('account-less OS listing uses a configured key for the Canadian API region',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-ca-key');

    $GLOBALS['soyouCurlOptions'] = [];
    $api->getOs('ubuntu_22.04', 9);
    soyouCheck('valid OS template ID reaches exactly one encoded path segment',
        ($GLOBALS['soyouCurlOptions'][CURLOPT_URL] ?? null)
            === 'https://ca.api.ovh.com/1.0/dedicated/installationTemplate/ubuntu_22.04');
    soyouCheck('OS detail request uses its configured account key',
        soyouHeaderValue('X-Ovh-Application: ') === 'configured-ca-key');

    $before = $GLOBALS['soyouCurlCalls'];
    foreach ([
        '../partner', 'ubuntu?query=1', 'ubuntu#fragment', 'ubuntu/other', '', "ubuntu\nother", 123, new \stdClass(),
    ] as $invalidTemplate) {
        $error = soyouAttempt(function () use ($api, $invalidTemplate) {
            $api->getOs($invalidTemplate);
        });
        soyouCheck('invalid OS template identifier is rejected',
            $error instanceof \InvalidArgumentException);
    }
    soyouCheck('invalid OS template identifiers never open cURL',
        $GLOBALS['soyouCurlCalls'] === $before);

    \WHMCS\Database\Capsule::$accounts = [];
    $before = $GLOBALS['soyouCurlCalls'];
    $noCredential = soyouAttempt(function () use ($api) {
        $api->getOs();
    });
    soyouCheck('OS listing fails closed when no active application is configured',
        $noCredential instanceof \Throwable && $GLOBALS['soyouCurlCalls'] === $before);

    \WHMCS\Database\Capsule::$accounts = [
        (object) ['id' => 2, 'status' => '1', 'location' => 'europe', 'application_key' => 'europe-only-key'],
    ];
    $wrongRegion = soyouAttempt(function () use ($api) {
        $api->getOs();
    });
    soyouCheck('Canadian OS listing never borrows an unrelated regional key',
        $wrongRegion instanceof \Throwable && $GLOBALS['soyouCurlCalls'] === $before);

    echo "== source regression guards ==\n";
    $source = file_get_contents(dirname(__DIR__) . '/classes/ApiCall.php');
    soyouCheck('no former hardcoded application keys remain in ApiCall',
        strpos($source, 't7r8jC5iiznmTNNm') === false
            && strpos($source, 'iE3vL3mgAtLZg00l') === false);
    soyouCheck('existing account-less OS listing call is preserved',
        strpos(file_get_contents(dirname(__DIR__) . '/lib/Admin/controller.php'), '$apiCall->getOs();') !== false);

    $dedicatedSource = file_get_contents(dirname(__DIR__, 3) . '/servers/soyoustart/soyoustart.php');
    $dedicatedCartCalls = [];
    preg_match_all('/\\$apiCall->createCart\\([^;]*?\\);/s', $dedicatedSource, $dedicatedCartCalls);
    soyouCheck('dedicated cart calls pass their account id',
        count($dedicatedCartCalls[0]) === 2
            && strpos($dedicatedSource, '$apiCall->createCart($location, $planCode, $subsidiaryLocation, $ovhAccountData[0])') !== false
            && strpos($dedicatedSource, '$apiCall->createCart($location, $planCode . "add Additional IPs", $subsidiaryLocation, $accountId)') !== false);
    soyouCheck('dedicated config-cart calls no longer discard the account id',
        strpos($dedicatedSource, "'/configuration', '', \$postData") === false);

    $vpsSource = file_get_contents(dirname(__DIR__, 3) . '/servers/soyoustart_vps/soyoustart_vps.php');
    $vpsCartCalls = [];
    preg_match_all('/\\$apiCall->createCart\\([^;]*?\\);/s', $vpsSource, $vpsCartCalls);
    soyouCheck('VPS cart calls pass their account id',
        count($vpsCartCalls[0]) === 4
            && strpos($vpsSource, '$apiCall->createCart($location, $planCode, $subsidiaryLocation, $ovhAccountData[0])') !== false
            && strpos($vpsSource, '$apiCall->createCart($location, $planCode . "add Additional IPs", $subsidiaryLocation, $accountId)') !== false
            && substr_count($vpsSource, '$apiCall->createCart($location, $planCode . "creating cart", $subsidiaryLocation, $accountId)') === 2);
    soyouCheck('VPS config-cart calls no longer discard the account id',
        strpos($vpsSource, "'/configuration', '', \$postData") === false);

    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    echo "PASS={$pass} FAIL={$fail}\n";
    exit($fail === 0 ? 0 : 1);
}
