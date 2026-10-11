<?php
/**
 * C-1: offline bootstrap for the RDP server module.
 * WHMCS stubs + an Illuminate Capsule fake. Behaviour suites use doubles.php
 * (scriptable Helper stand-in); 01/05 load the real lib class instead, so the
 * fake is deliberately NOT defined here (one Helper per process).
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
namespace RdpTest {
    class FakeQuery
    {
        public $valueResult = null;
        public $firstResult = null;
        public $getResult = [];
        public $countResult = 0;
        public $updateReturn = 1;
        public $throwOn = null;
        public $wheres = [];
        public $inserted = [];
        public $updated = [];
        public function __construct(array $opts = [])
        {
            foreach ($opts as $k => $v) {
                $this->$k = $v;
            }
        }
        public function where($col, $op = null, $val = null)
        {
            $this->wheres[] = [$col, $op, $val];
            return $this;
        }
        public function value($col)
        {
            if ($this->throwOn === 'value') {
                throw new \Exception('db down');
            }
            return $this->valueResult;
        }
        public function first()
        {
            return $this->firstResult;
        }
        public function get()
        {
            return $this->getResult;
        }
        public function count()
        {
            return $this->countResult;
        }
        public function insert($data)
        {
            $this->inserted[] = $data;
            return true;
        }
        public function update($data)
        {
            $this->updated[] = $data;
            return $this->updateReturn;
        }
    }
}
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }
    $GLOBALS['__rdp_modulelog'] = [];
    $GLOBALS['__rdp_activity'] = [];
    $GLOBALS['__rdp_hooks'] = [];
    $GLOBALS['__rdp_currency'] = ['prefix' => '$', 'suffix' => 'USD'];
    $GLOBALS['__rdp_localapi'] = ['result' => 'success'];
    $GLOBALS['__rdp_localapi_calls'] = [];
    $GLOBALS['__rdp_localapi_throw'] = false;
    $GLOBALS['__rdp_encrypt_calls'] = [];
    if (!function_exists('getCurrency')) {
        function getCurrency()
        {
            return $GLOBALS['__rdp_currency'];
        }
    }
    if (!function_exists('localAPI')) {
        function localAPI($command, $postData = [])
        {
            $GLOBALS['__rdp_localapi_calls'][] = [$command, $postData];
            if ($GLOBALS['__rdp_localapi_throw']) {
                throw new \Exception('localapi down');
            }
            return $GLOBALS['__rdp_localapi'];
        }
    }
    if (!function_exists('logModuleCall')) {
        function logModuleCall($module, $action, $request, $response, $data = null)
        {
            $GLOBALS['__rdp_modulelog'][] = [$module, $action, $request, $response];
        }
    }
    if (!function_exists('logActivity')) {
        function logActivity($message, $userId = 0)
        {
            $GLOBALS['__rdp_activity'][] = $message;
        }
    }
    if (!function_exists('encrypt')) {
        function encrypt($s)
        {
            $GLOBALS['__rdp_encrypt_calls'][] = $s;
            return 'ENC:' . $s;
        }
    }
    if (!function_exists('add_hook')) {
        function add_hook($point, $priority, $fn)
        {
            $GLOBALS['__rdp_hooks'][$point][] = $fn;
        }
    }
    function rdp_root()
    {
        return dirname(__DIR__, 4);
    }
    function rdp_reset()
    {
        \WHMCS\Database\Capsule::reset();
        $GLOBALS['__rdp_modulelog'] = [];
        $GLOBALS['__rdp_activity'] = [];
        $GLOBALS['__rdp_localapi'] = ['result' => 'success'];
        $GLOBALS['__rdp_localapi_calls'] = [];
        $GLOBALS['__rdp_localapi_throw'] = false;
        $GLOBALS['__rdp_encrypt_calls'] = [];
        $GLOBALS['CONFIG'] = ['SystemURL' => 'https://shop.test'];
        unset($_REQUEST['id']);
    }
}
