<?php
/**
 * C-2: offline bootstrap for the CloudHost247 LTE Proxy server module.
 *
 * WHMCS stubs + an Illuminate Capsule fake. Behaviour suites load the REAL
 * lib classes (ApiClient's static HTTP seam keeps everything offline) and
 * use doubles.php (scriptable ApiClient subclass) for the ajax handlers.
 *
 * ch247_eval_main() runs an ajax/ endpoint file with its require_once lines
 * stripped (the suite already loaded the real classes) inside a FRESH
 * namespace per scenario, so the endpoint's helper functions never collide
 * across scenarios while the top-level request flow executes for real.
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
namespace Ch247Test {
    class FakeQuery
    {
        public $valueResult = null;
        public $firstResult = null;
        public $getResult = [];
        public $countResult = 0;
        public $updateReturn = 1;
        public $wheres = [];
        public $updated = [];
        public $inserted = [];
        public function __construct(array $opts = [])
        {
            foreach ($opts as $k => $v) {
                $this->$k = $v;
            }
        }
        public function join($table, $one, $op = null, $two = null)
        {
            return $this;
        }
        public function leftJoin($table, $one, $op = null, $two = null)
        {
            return $this;
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
        public function orderBy($col, $dir = 'asc')
        {
            return $this;
        }
        public function limit($n)
        {
            return $this;
        }
        public function value($col)
        {
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
    // CURLE_* fallback so retry-path tests also run on builds without ext-curl.
    $ch247CurlConsts = [
        'CURLE_COULDNT_CONNECT' => 7,
        'CURLE_OPERATION_TIMEDOUT' => 28,
        'CURLE_GOT_NOTHING' => 52,
        'CURLE_RECV_ERROR' => 56,
        'CURLE_SEND_ERROR' => 55,
        'CURLE_SSL_CONNECT_ERROR' => 35,
    ];
    foreach ($ch247CurlConsts as $ch247Name => $ch247Val) {
        if (!defined($ch247Name)) {
            define($ch247Name, $ch247Val);
        }
    }
    unset($ch247CurlConsts, $ch247Name, $ch247Val);
    $GLOBALS['__ch247_activity'] = [];
    $GLOBALS['__ch247_hooks'] = [];
    $GLOBALS['__ch247_localapi'] = ['result' => 'success'];
    $GLOBALS['__ch247_localapi_calls'] = [];
    if (!function_exists('localAPI')) {
        function localAPI($command, $postData = [])
        {
            $GLOBALS['__ch247_localapi_calls'][] = [$command, $postData];
            return $GLOBALS['__ch247_localapi'];
        }
    }
    if (!function_exists('logActivity')) {
        function logActivity($message, $userId = 0)
        {
            $GLOBALS['__ch247_activity'][] = $message;
        }
    }
    if (!function_exists('add_hook')) {
        function add_hook($point, $priority, $fn)
        {
            $GLOBALS['__ch247_hooks'][$point][] = $fn;
        }
    }
    function ch247_root()
    {
        return dirname(__DIR__, 4);
    }
    function ch247_mod()
    {
        return dirname(__DIR__);
    }
    function ch247_reset()
    {
        \WHMCS\Database\Capsule::reset();
        $GLOBALS['__ch247_activity'] = [];
        $GLOBALS['__ch247_localapi'] = ['result' => 'success'];
        $GLOBALS['__ch247_localapi_calls'] = [];
        $GLOBALS['CH247_TEST_JSON_THROW'] = false;
        \CloudHost247\LTEProxy\ApiClient::setHttpHandler(null);
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
    }
    function ch247_tmpdir($tag)
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/ch247_' . $tag . '_' . uniqid();
        mkdir($dir, 0777, true);
        mkdir($dir . '/logs', 0777, true);
        mkdir($dir . '/cache', 0777, true);
        return $dir;
    }
    function ch247_rmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                ch247_rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
    function ch247_require_lib()
    {
        $lib = ch247_mod() . '/lib';
        require_once $lib . '/ApiException.php';
        require_once $lib . '/Logger.php';
        require_once $lib . '/Cache.php';
        require_once $lib . '/Helpers.php';
        require_once $lib . '/RateLimiter.php';
        require_once $lib . '/ApiClient.php';
    }
    /**
     * Execute an ajax/ endpoint's request flow with its require_once lines
     * stripped (the suite pre-loaded the real classes). Each call uses a
     * fresh namespace so helper functions never redeclare. The endpoint
     * always terminates via the CH247_TEST_JSON_THROW seam — callers must
     * set it and catch JsonResponseException.
     */
    function ch247_eval_main($name, $tag)
    {
        $file = ch247_mod() . '/ajax/' . $name;
        $src = file_get_contents($file);
        $src = preg_replace('/^<\?php\s*/', '', $src);
        $src = preg_replace('/^declare\s*\(strict_types=1\);\s*/m', '', $src);
        $before = $src;
        $src = preg_replace('/^\s*require_once[^\n]*\n/m', '', $src);
        if ($src === $before) {
            throw new \Exception('ch247_eval_main: no require lines stripped in ' . $name);
        }
        eval('namespace Ch247Main_' . $tag . ";\n" . $src);
        throw new \Exception('ch247_eval_main: endpoint ' . $name . ' returned instead of responding');
    }
}
