<?php
/**
 * C-5: offline bootstrap for the soyoustart_vps (WGS OVH VPS) provisioning module.
 * WHMCS core stubs (hooks, logging, localAPI, Capsule) + the production load
 * chain: addon Helper -> server entry (pulls ApiCall, SafeLog, Configuration,
 * the sibling dedicated-server lib) -> this module's lib -> hooks.
 */
namespace WHMCS\Database {
    class Capsule
    {
        public static $handler = null;
        public static $tables = [];
        public static function table($name)
        {
            self::$tables[] = $name;
            if (self::$handler !== null) {
                return call_user_func(self::$handler, $name);
            }
            throw new \Exception('no Capsule handler for table ' . $name);
        }
        public static function reset()
        {
            self::$handler = null;
            self::$tables = [];
        }
    }
}
namespace SoyoustartTest {
    /** Chainable query double: where/join/select/leftJoin return $this. */
    class FakeQuery
    {
        public $firstResult = null;
        public $getResult = [];
        public $inserted = [];
        public $updated = [];
        public $throwOn = null;
        public function __construct(array $opts = [])
        {
            foreach ($opts as $k => $v) {
                $this->$k = $v;
            }
        }
        public function __call($method, $args)
        {
            return $this;
        }
        public function first()
        {
            if ($this->throwOn === 'first') {
                throw new \Exception('db down');
            }
            return $this->firstResult;
        }
        public function get()
        {
            if ($this->throwOn === 'get') {
                throw new \Exception('db down');
            }
            return $this->getResult;
        }
        public function insert($data)
        {
            $this->inserted[] = $data;
            return true;
        }
        public function update($data)
        {
            $this->updated[] = $data;
            return 1;
        }
    }
    /** Scriptable ApiCall stand-in for the HTML builders. */
    class FakeApiCall
    {
        public $getResult = ['httpcode' => 200, 'result' => null];
        public $detailsResult = ['httpcode' => 200, 'result' => null];
        public $calls = [];
        public function get($url, $accountId, $location, $action, $signed = false)
        {
            $this->calls[] = ['get', $url];
            return $this->getResult;
        }
        public function getIpDetails($location, $server, $accountId, $ip)
        {
            $this->calls[] = ['getIpDetails', $ip];
            return $this->detailsResult;
        }
    }
}
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }
    if (!defined('ROOTDIR')) {
        define('ROOTDIR', sys_get_temp_dir());
    }
    $GLOBALS['__c5_hooks'] = [];
    $GLOBALS['__c5_localapi'] = ['handler' => null, 'calls' => []];
    if (!function_exists('add_hook')) {
        function add_hook($point, $priority, $fn)
        {
            $GLOBALS['__c5_hooks'][$point][] = [$priority, $fn];
        }
    }
    if (!function_exists('logActivity')) {
        function logActivity($message, $userId = 0)
        {
        }
    }
    if (!function_exists('logModuleCall')) {
        function logModuleCall($module, $action, $request, $response, $data = '', $vars = [])
        {
        }
    }
    if (!function_exists('localAPI')) {
        function localAPI($command, $data = [])
        {
            $GLOBALS['__c5_localapi']['calls'][] = [$command, $data];
            $h = $GLOBALS['__c5_localapi']['handler'];
            if ($h !== null) {
                return call_user_func($h, $command, $data);
            }
            return ['result' => 'success'];
        }
    }
    function c5_root()
    {
        return dirname(__DIR__, 4);
    }
    function c5_reset()
    {
        \WHMCS\Database\Capsule::reset();
        $GLOBALS['__c5_localapi'] = ['handler' => null, 'calls' => []];
    }
    require_once c5_root() . '/modules/addons/soyoustart/lib/Helper.php';
    require_once dirname(__DIR__) . '/soyoustart.php';
    require_once dirname(__DIR__) . '/lib/SoyoustartServer.php';
    require_once dirname(__DIR__) . '/lib/AjaxAuth.php';
    require_once dirname(__DIR__) . '/hooks.php';
}
