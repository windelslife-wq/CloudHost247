<?php
/**
 * C-3: offline bootstrap for smmprovisioning.
 * WHMCS stubs + an Illuminate Capsule fake. Does NOT load the entry file:
 * 01_LoadTest requires the real files; behaviour suites use eval_entry.php
 * (entry eval'd with its require lines stripped, SmmAddon doubles bound).
 */
namespace Illuminate\Database\Capsule {
    class Manager
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
namespace SmmProvTest {
    class FakeQuery
    {
        public $firstResult = null;
        public $getResult = [];
        public $valueResult = null;
        public $inserted = [];
        public $updated = [];
        public $throwOn = null;
        public $wheres = [];
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
        public function select()
        {
            return $this;
        }
        public function join($table, $a, $op = null, $b = null)
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
        public function value($col)
        {
            return $this->valueResult;
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
}
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }
    $GLOBALS['__c3_activity'] = [];
    if (!function_exists('logActivity')) {
        function logActivity($message, $userId = 0)
        {
            $GLOBALS['__c3_activity'][] = $message;
        }
    }
    function c3_root()
    {
        return dirname(__DIR__, 4);
    }
    function c3_reset()
    {
        \Illuminate\Database\Capsule\Manager::reset();
        $GLOBALS['__c3_activity'] = [];
    }
}
