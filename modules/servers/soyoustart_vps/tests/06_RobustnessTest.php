<?php
/**
 * C-4: VS-8/VS-10/VS-12-class robustness: [] on DB failure, insert/update
 * rethrow prefix, no write-on-read custom fields, ACL checkbox rendering.
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) {
            $pass++;
        } else {
            $fail++;
            echo "FAIL: $label\n";
        }
    }
    $lib = 'WHMCS\\Module\\Server\\SoyoustartVps\\SoyoustartServer';
    $o = new $lib();

    echo "== service configs ==\n";
    c4_reset();
    $rows = [(object) ['optionid' => 1, 'qty' => 1, 'opname' => 'OS', 'optiontype' => '1', 'optionname' => 'debian']];
    \WHMCS\Database\Capsule::$handler = function ($table) use ($rows) {
        return new \SoyoustartVpsTest\FakeQuery(['getResult' => $rows]);
    };
    check('rows returned', $o->getServiceConfigs(11) === $rows);
    check('hosting options table queried', in_array('tblhostingconfigoptions', \WHMCS\Database\Capsule::$tables, true));
    \WHMCS\Database\Capsule::$handler = function ($table) {
        return new \SoyoustartVpsTest\FakeQuery(['throwOn' => 'get']);
    };
    check('VS-10: db failure yields []', $o->getServiceConfigs(11) === []);

    echo "== ip order insert ==\n";
    c4_reset();
    $q = new \SoyoustartVpsTest\FakeQuery();
    \WHMCS\Database\Capsule::$handler = function ($table) use ($q) {
        return $q;
    };
    check('insert ok', $o->InsertIpData('mod_soyoustart_ips_orders', ['a' => 1]) === true);
    check('row recorded', $q->inserted === [['a' => 1]]);
    \WHMCS\Database\Capsule::$handler = function ($table) {
        return new class() {
            public function __call($m, $a)
            {
                return $this;
            }
            public function insert($d)
            {
                throw new \Exception('nope');
            }
        };
    };
    try {
        $o->InsertIpData('t', []);
        check('insert failure rethrows', false);
    } catch (\Exception $e) {
        check('insert failure rethrows', strpos($e->getMessage(), 'Error in inserting/updating data: ') === 0);
    }

    echo "== custom fields (VS-8) ==\n";
    // createCustomFields loops 9 definitions in fixed order; script one row per get().
    $defs = [
        ['ovh_account|OVH Account Name', 'OVH Account Name.'],
        ['ovh_server_name|OVH Server Name', 'OVH Server Name.'],
        ['ovh_server_location|OVH Server Location', 'OVH Server Location.'],
        ['ovh_custom_hostname|OVH Custom Hostname', 'OVH Custom Hostname.'],
        ['ovh_ftp_custom_hostname|OVH FTP Custom Hostname', 'OVH FTP Custom Hostname.'],
        ['ovh_reveser_dns_custom_hostname|OVH Reveser DNS Custom Hostname', 'OVH Reveser DNS Custom Hostname.'],
        ['ovh_order_id|OVH Order Id', 'OVH Order Id.'],
        ['ovh_snapshot_order_id|OVH Snapshot Order Id', 'OVH Snapshot Order Id.'],
        ['ovh_automated_backup_order_id|OVH Automated Backup Order Id', 'OVH Automated Backup Order Id.'],
    ];
    $cfQuery = function ($script) {
        return new class($script) extends \SoyoustartVpsTest\FakeQuery {
            public $script = [];
            public $n = 0;
            public function __construct($script)
            {
                $this->script = $script;
            }
            public function get()
            {
                $row = isset($this->script[$this->n]) ? $this->script[$this->n] : null;
                $this->n++;
                return $row === null ? [] : [(object) ['id' => 5, 'fieldname' => $row[0], 'description' => $row[1]]];
            }
        };
    };
    c4_reset();
    $match = $cfQuery($defs);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($match) {
        return $match;
    };
    createCustomFields(3);
    check('matching fields: no insert', $match->inserted === []);
    check('matching fields: no update (no write-on-read)', $match->updated === []);
    $staleDefs = array_map(function ($d) {
        return [$d[0], 'stale'];
    }, $defs);
    $stale = $cfQuery($staleDefs);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($stale) {
        return $stale;
    };
    createCustomFields(3);
    check('stale fields: updated', count($stale->updated) === 9);
    check('stale fields: not inserted', $stale->inserted === []);
    $missing = $cfQuery([]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($missing) {
        return $missing;
    };
    createCustomFields(3);
    check('missing fields: inserted', count($missing->inserted) === 9);
    check('missing fields: not updated', $missing->updated === []);

    echo "== feature html ==\n";
    c4_reset();
    $acl = new \SoyoustartVpsTest\FakeQuery(['getResult' => [(object) ['key' => 'servicePageAclSettings_11', 'value' => json_encode(['Reboot_Server' => 'on'])]]]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($acl) {
        return $acl;
    };
    $html = $o->featureHtml(['Reboot Server', 'Burn"><X'], 11);
    check('enabled box checked', strpos($html, 'name="Reboot Server" checked') !== false);
    check('feature names escaped', strpos($html, 'Burn&quot;&gt;&lt;X') !== false && strpos($html, 'Burn"><X') === false);
    $empty = new \SoyoustartVpsTest\FakeQuery(['getResult' => []]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($empty) {
        return $empty;
    };
    $html = $o->featureHtml(['Reboot Server'], 11);
    check('missing settings: nothing checked', strpos($html, 'checked') === false);

    echo "\nrobustness: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
