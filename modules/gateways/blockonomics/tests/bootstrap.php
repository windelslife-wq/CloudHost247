<?php
/**
 * Offline harness for the blockonomics gateway suite (C-7).
 *
 * The repo does not vendor WHMCS core, so the REAL gateway class is loaded
 * with its three top-of-file requires stripped (asserted exact-match: if
 * those lines change, every suite fails loudly rather than testing a lie).
 * Capsule, gateway params, Bridge, App and the WHMCS global functions are
 * in-memory fakes; Policy is the real file (pure, no I/O).
 */

namespace WHMCS\Database {
    class CapsuleFakeStore
    {
        public static $tables = [];
        public static $writes = [];
        public static $schemaTables = [];
        public static $schemaWrites = [];
        public static function reset()
        {
            self::$tables = [];
            self::$writes = [];
            self::$schemaTables = [];
            self::$schemaWrites = [];
        }
        public static function seed($table, array $rows) { self::$tables[$table] = $rows; }
    }
    class Capsule extends CapsuleFakeStore
    {
        public static function table($name) { return new FakeQuery($name); }
        public static function schema() { return new FakeSchema(); }
    }
    class FakeCollection implements \IteratorAggregate, \Countable
    {
        private $items;
        public function __construct(array $items) { $this->items = $items; }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }
        public function isEmpty() { return count($this->items) === 0; }
        public function count(): int { return count($this->items); }
    }
    class FakeBlueprint
    {
        public function __call($method, $args) { return $this; }
    }
    class FakeSchema
    {
        public function hasTable($t) { return isset(Capsule::$schemaTables[$t]); }
        public function hasColumn($t, $c) { return in_array($c, Capsule::$schemaTables[$t] ?? [], true); }
        public function create($t, $cb)
        {
            Capsule::$schemaWrites[] = ['create', $t];
            $cb(new FakeBlueprint());
            Capsule::$schemaTables[$t] = ['id_order', 'txid', 'timestamp', 'addr', 'status', 'value', 'bits', 'bits_payed', 'blockonomics_currency', 'basecurrencyamount'];
        }
        public function table($t, $cb)
        {
            Capsule::$schemaWrites[] = ['alter', $t];
            $cb(new FakeBlueprint());
            Capsule::$schemaTables[$t][] = 'basecurrencyamount';
        }
    }
    class FakeQuery
    {
        private $table;
        private $wheres = [];
        private $orderCol = null;
        private $orderDir = 'asc';
        public function __construct($table) { $this->table = $table; }
        public function where($col, $op = null, $val = null)
        {
            if (func_num_args() === 2) { $val = $op; $op = '='; }
            $this->wheres[] = [$col, $op, $val];
            return $this;
        }
        public function orderBy($col, $dir = 'asc')
        {
            $this->orderCol = $col;
            $this->orderDir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
            return $this;
        }
        private function rowMatches(array $row)
        {
            foreach ($this->wheres as $w) {
                list($col, $op, $val) = $w;
                $have = array_key_exists($col, $row) ? $row[$col] : null;
                $ok = false;
                switch ($op) {
                    case '=': $ok = ($have == $val); break;
                    case '>': $ok = ($have > $val); break;
                    case '<': $ok = ($have < $val); break;
                    case '>=': $ok = ($have >= $val); break;
                    case '<=': $ok = ($have <= $val); break;
                    default: $ok = ($have != $val);
                }
                if (!$ok) return false;
            }
            return true;
        }
        private function matching()
        {
            $rows = array_values(array_filter(
                Capsule::$tables[$this->table] ?? [],
                function ($r) { return $this->rowMatches($r); }
            ));
            if ($this->orderCol !== null) {
                $col = $this->orderCol;
                $dir = $this->orderDir;
                usort($rows, function ($a, $b) use ($col, $dir) {
                    $x = $a[$col] ?? null;
                    $y = $b[$col] ?? null;
                    if ($x == $y) return 0;
                    $cmp = ($x < $y) ? -1 : 1;
                    return $dir === 'desc' ? -$cmp : $cmp;
                });
            }
            return $rows;
        }
        public function first($cols = null)
        {
            $m = $this->matching();
            return $m ? (object) $m[0] : null;
        }
        public function get()
        {
            return new FakeCollection(array_map(function ($r) { return (object) $r; }, $this->matching()));
        }
        public function value($col)
        {
            $m = $this->matching();
            return $m ? ($m[0][$col] ?? null) : null;
        }
        public function update(array $data)
        {
            $count = 0;
            foreach ((Capsule::$tables[$this->table] ?? []) as $i => $row) {
                if ($this->rowMatches($row)) {
                    Capsule::$tables[$this->table][$i] = array_merge($row, $data);
                    $count++;
                }
            }
            Capsule::$writes[] = ['update', $this->table, $data, $this->wheres, $count];
            return $count;
        }
        public function insert(array $data)
        {
            // Simulate the addr PRIMARY KEY on blockonomics_orders.
            if ($this->table === 'blockonomics_orders' && isset($data['addr']) && $data['addr'] !== null) {
                foreach ((Capsule::$tables[$this->table] ?? []) as $row) {
                    if (($row['addr'] ?? null) === $data['addr']) {
                        throw new \Exception("Duplicate entry '{$data['addr']}' for key 'PRIMARY'");
                    }
                }
            }
            Capsule::$tables[$this->table][] = $data;
            Capsule::$writes[] = ['insert', $this->table, $data, [], 1];
            return true;
        }
    }
}

namespace WHMCS\Authentication {
    class CurrentUser
    {
        public static $isAdmin = false;
        public function isAuthenticatedAdmin() { return static::$isAdmin; }
    }
}

namespace CloudHost247\Blockonomics {
    require_once __DIR__ . '/../cloudhost247/Policy.php';
    class BridgeScript
    {
        public static $matrix = ['btc' => true, 'bch' => true, 'usdt' => true];
        public static $deny = [];
        public static $state = [];
        public static $apiKey = '';
        public static $etherscanKey = '';
        public static $seeded = 0;
        public static $throwOnState = false;
        public static function reset()
        {
            self::$matrix = ['btc' => true, 'bch' => true, 'usdt' => true];
            self::$deny = [];
            self::$state = [
                'gateway_enabled' => true, 'btc_enabled' => true,
                'bch_enabled' => true, 'usdt_enabled' => true,
                'usdt_network' => '', 'confirmations' => 2,
            ];
            self::$apiKey = '';
            self::$etherscanKey = '';
            self::$seeded = 0;
            self::$throwOnState = false;
        }
    }
    class Bridge extends BridgeScript
    {
        public static function ensureSeeded(array $legacy, $actorId = 0) { static::$seeded++; }
        public static function state(array $legacy = null)
        {
            if (static::$throwOnState) throw new \RuntimeException('governance down');
            return static::$state;
        }
        public static function isGatewayEnabled(array $legacy = null) { return !empty(static::state($legacy)['gateway_enabled']); }
        public static function isCheckoutAvailable(array $legacy = null) { return Policy::anyCurrencyAvailable(static::state($legacy)); }
        public static function availableCurrencies(array $legacy = null) { return static::$matrix; }
        public static function assertCurrencyAllowed($currency, array $legacy = null)
        {
            if (!empty(static::$deny[$currency]) || empty(static::$matrix[$currency])) {
                throw new PaymentUnavailableException('This payment method is currently unavailable.');
            }
        }
        public static function resolveApiKey($legacyValue = '')
        {
            return static::$apiKey !== '' ? static::$apiKey : trim((string) $legacyValue);
        }
        public static function resolveEtherscanKey($legacyValue = '')
        {
            return static::$etherscanKey !== '' ? static::$etherscanKey : trim((string) $legacyValue);
        }
    }
}

namespace Blockonomics {
    class TestRejectException extends \Exception
    {
    }
}

namespace {
    define('WHMCS', true);

    class App
    {
        public static $systemUrl = 'https://shop.test/';
        public static function getSystemURL() { return static::$systemUrl; }
        public static function reset() { static::$systemUrl = 'https://shop.test/'; }
    }

    class BnTestState
    {
        public static $gatewayParams = [];
        public static $transactions = [];
        public static $moduleCalls = [];
        public static $invoicePayments = [];
        public static $hooks = [];
        public static function reset()
        {
            self::$transactions = [];
            self::$moduleCalls = [];
            self::$invoicePayments = [];
            self::$hooks = [];
        }
    }

    function getGatewayVariables($name) { return BnTestState::$gatewayParams; }
    function logTransaction($gateway, $data, $status) { BnTestState::$transactions[] = [$gateway, $data, $status]; }
    function logModuleCall($mod, $action, $req, $resp) { BnTestState::$moduleCalls[] = [$mod, $action, $req, $resp]; }
    function checkCbInvoiceID($id, $name) { return $id; }
    function addInvoicePayment($a, $b, $c, $d, $e) { BnTestState::$invoicePayments[] = [$a, $b, $c, $d, $e]; }
    function add_hook($hook, $pri, $fn) { BnTestState::$hooks[] = [$hook, $pri, $fn]; }

    function bn_default_params()
    {
        return BnTestState::$gatewayParams = [
            'type' => true,
            'name' => 'blockonomics',
            'CallbackSecret' => 'testsecret0123456789abcdef0123456789abcd',
            'ApiKey' => 'legacy-key',
            'TimePeriod' => '10',
            'Margin' => '0',
            'Slack' => '0',
            'Confirmations' => '2',
            'btcEnabled' => 'on',
            'bchEnabled' => '',
            'usdtEnabled' => '',
            'UsdtAddress' => '0x1111111111111111111111111111111111111111',
            'EtherScanAPIKey' => 'etherscan-key',
            'NetworkType' => 'ethereum',
        ];
    }

    function bn_reset_all()
    {
        \WHMCS\Database\Capsule::reset();
        \CloudHost247\Blockonomics\Bridge::reset();
        \Blockonomics\TestBlockonomics::resetTest();
        BnTestState::reset();
        App::reset();
        \WHMCS\Authentication\CurrentUser::$isAdmin = false;
        $_SESSION = [];
        bn_default_params();
    }

    // Load the REAL gateway class with WHMCS-core requires stripped.
    $bnClassFile = __DIR__ . '/../blockonomics.php';
    $bnCode = (string) file_get_contents($bnClassFile);
    foreach ([
        "require_once __DIR__ . '/../../../includes/gatewayfunctions.php';",
        "require_once __DIR__ . '/../../../includes/invoicefunctions.php';",
        "require_once __DIR__ . '/cloudhost247/autoload.php';",
    ] as $req) {
        if (substr_count($bnCode, $req) !== 1) {
            echo "FAIL: harness strip anchor changed: $req\nFAIL=1\n";
            exit(1);
        }
        $bnCode = str_replace($req, '', $bnCode);
    }
    $bnCode = (string) preg_replace('/^<\\?php\\s/', '', $bnCode);
    eval($bnCode);
}

namespace Blockonomics {
    class TestBlockonomics extends Blockonomics
    {
        public static $calls = [];
        public static $curlCalls = [];
        public static $newAddressResponse = null;
        public static $priceContents = null;
        public static $curlResponses = [];
        public static $langPath = null;
        public static function resetTest()
        {
            self::$calls = [];
            self::$curlCalls = [];
            self::$newAddressResponse = null;
            self::$priceContents = null;
            self::$curlResponses = [];
            self::$langPath = null;
        }
        public function getNewAddress($currency = 'btc', $reset = false)
        {
            self::$calls[] = ['getNewAddress', $currency, $reset];
            return self::$newAddressResponse;
        }
        protected function fetchPrice($url)
        {
            self::$calls[] = ['fetchPrice', $url];
            return self::$priceContents;
        }
        public function doCurlCall($url, $post_content = '')
        {
            self::$curlCalls[] = [$url, $post_content];
            foreach (self::$curlResponses as $needle => $resp) {
                if (strpos($url, $needle) !== false) return $resp;
            }
            $o = new \stdClass();
            $o->data = null;
            $o->response_code = 500;
            return $o;
        }
        public function getLangFilePath($language = false) { return (string) self::$langPath; }
        protected function rejectInvalidOrderHash() { throw new TestRejectException('Invalid payment reference.'); }
        protected function rejectUnavailableCurrency() { throw new TestRejectException('This payment method is currently unavailable.'); }
    }
    class FakeCa
    {
        public $assigned = [];
        public $template = null;
        public function assign($k, $v) { $this->assigned[$k] = $v; }
        public function setTemplate($t) { $this->template = $t; }
    }
}
