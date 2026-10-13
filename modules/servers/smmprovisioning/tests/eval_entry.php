<?php
/**
 * C-3: behaviour harness. Scriptable SmmAddon doubles, then the real entry
 * file eval'd with its two require_once lines stripped so the doubles bind.
 */
namespace SmmAddon {
    class Helper
    {
        public static $config = [];
        public static $orderByService = null;
        public static $serverConfigs = [];
        public static $apiLogs = [];
        public static function reset()
        {
            self::$config = [];
            self::$orderByService = null;
            self::$serverConfigs = [];
            self::$apiLogs = [];
        }
        public static function getConfig($key = null, $default = null)
        {
            if ($key === null) {
                return self::$config;
            }
            return isset(self::$config[$key]) ? self::$config[$key] : $default;
        }
        public static function setServerConfig($serverId, $apiUrl, $apiKey)
        {
            self::$serverConfigs[] = [$serverId, $apiUrl, $apiKey];
            return true;
        }
        public static function getOrderByServiceId($serviceId)
        {
            return self::$orderByService;
        }
        public static function logApi($action, $endpoint, $request, $response, $httpCode = null, $error = null)
        {
            self::$apiLogs[] = $action;
        }
    }
    class ApiClient
    {
        public static $constructed = [];
        public static $balanceResult = ['balance' => '10.00'];
        public static $placeResult = ['order' => 12345];
        public static $cancelResult = ['cancel' => 'ok'];
        public static $throwOn = null;
        public $apiUrl;
        public $apiKey;
        public $debug;
        public static function reset()
        {
            self::$constructed = [];
            self::$balanceResult = ['balance' => '10.00'];
            self::$placeResult = ['order' => 12345];
            self::$cancelResult = ['cancel' => 'ok'];
            self::$throwOn = null;
        }
        public function __construct($apiUrl, $apiKey, $debug = false)
        {
            $this->apiUrl = $apiUrl;
            $this->apiKey = $apiKey;
            $this->debug = $debug;
            self::$constructed[] = [$apiUrl, $apiKey, $debug];
        }
        public function getBalance()
        {
            if (self::$throwOn === 'getBalance') {
                throw new \Exception('panel down');
            }
            return self::$balanceResult;
        }
        public function placeOrder($serviceId, $link, $quantity)
        {
            if (self::$throwOn === 'placeOrder') {
                throw new \Exception('panel down');
            }
            self::$constructed[] = ['placeOrder', $serviceId, $link, $quantity];
            return self::$placeResult;
        }
        public function cancelOrder($orderId)
        {
            if (self::$throwOn === 'cancelOrder') {
                throw new \Exception('panel down');
            }
            self::$constructed[] = ['cancelOrder', $orderId];
            return self::$cancelResult;
        }
    }
}
namespace {
    require_once __DIR__ . '/bootstrap.php';
    require_once c3_root() . '/modules/addons/smmaddon/lib/OrderQuantity.php';
    $src = file_get_contents(dirname(__DIR__) . '/smmprovisioning.php');
    $stripped = preg_replace('/^require_once __DIR__ .*$/m', '', $src);
    if ($stripped === null || substr_count($src, 'require_once __DIR__') !== 3) {
        throw new \Exception('eval harness: expected 3 require lines to strip');
    }
    $stripped = preg_replace('/^<\?php/', '', $stripped, 1);
    eval($stripped);
    function c3_full_reset()
    {
        c3_reset();
        \SmmAddon\Helper::reset();
        \SmmAddon\ApiClient::reset();
    }
}
