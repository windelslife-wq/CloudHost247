<?php
/**
 * CloudHost247 Tools - database logging contract and privacy-switch tests.
 *
 * Uses a tiny Capsule fake so the test remains offline and does not need a
 * WHMCS database or schema. It verifies that Runner writes to the table and
 * columns created by the addon, and that logging can be disabled.
 */
namespace Illuminate\Database\Capsule {
    class Manager
    {
        public static $calls = [];

        public static function table($name)
        {
            self::$calls[] = ['table' => $name];
            return new \CloudHost247ToolsFakeQuery($name);
        }
    }
}

namespace {
    define('CLOUDHOST247_TOOLS', true);

    class CloudHost247ToolsFakeQuery
    {
        private $table;

        public function __construct($table)
        {
            $this->table = $table;
        }

        public function insert(array $row)
        {
            \Illuminate\Database\Capsule\Manager::$calls[] = [
                'insert_table' => $this->table,
                'row' => $row,
            ];
            return true;
        }
    }

    function CloudHost247_tools_get_setting($key, $default = '')
    {
        return array_key_exists('test_log_setting', $GLOBALS)
            ? $GLOBALS['test_log_setting']
            : $default;
    }

    require __DIR__ . '/../includes/Runner.php';

    $pass = 0;
    $fail = 0;
    function log_ok($label, $condition)
    {
        global $pass, $fail;
        if ($condition) {
            $pass++;
        } else {
            $fail++;
            echo "  FAIL $label\n";
        }
    }

    $method = new \ReflectionMethod('CloudHost247ToolsRunner', 'log');
    $method->setAccessible(true);

    // Explicitly disabled logging must not touch any log table.
    $GLOBALS['test_log_setting'] = 'off';
    \Illuminate\Database\Capsule\Manager::$calls = [];
    $method->invoke(null, ['slug' => 'test-tool'], ['domain' => 'example.com'], 'success', '');
    log_ok('disabled privacy switch writes no rows', \Illuminate\Database\Capsule\Manager::$calls === []);

    // Enabled logs use the installed plural table and its actual column names.
    $GLOBALS['test_log_setting'] = 'on';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SESSION['uid'] = 27;
    \Illuminate\Database\Capsule\Manager::$calls = [];
    $input = [
        'password' => 'must-not-be-stored',
        'csrf_token' => 'must-not-be-stored-either',
        'email' => 'person@example.com',
        'header' => 'Authorization: Bearer private-token',
        'text' => 'private pasted content',
        'bin' => '453201',
        'mac' => '00:1A:2B:3C:4D:5E',
        'domain' => 'example.com',
    ];
    $method->invoke(null, ['slug' => 'test-tool'], $input, 'success', '');

    $calls = \Illuminate\Database\Capsule\Manager::$calls;
    $insert = end($calls);
    log_ok('logs use installed table', ($insert['insert_table'] ?? null) === 'mod_CloudHost247_tools_logs');
    $row = $insert['row'] ?? [];
    log_ok('logs match installed schema', array_keys($row) === [
        'tool_id', 'input', 'ip_address', 'user_id', 'result', 'status', 'error_message', 'created_at',
    ]);
    log_ok('remote IP stored in schema column', ($row['ip_address'] ?? null) === '203.0.113.10');
    log_ok('user id stored in schema column', ($row['user_id'] ?? null) === 27);
    log_ok('tool result is not retained', array_key_exists('result', $row) && $row['result'] === null);
    $loggedInput = json_decode($row['input'] ?? '', true);
    log_ok('password is redacted', ($loggedInput['password'] ?? null) === '[redacted]');
    log_ok('email is redacted', ($loggedInput['email'] ?? null) === '[redacted]');
    log_ok('pasted headers are redacted', ($loggedInput['header'] ?? null) === '[redacted]');
    log_ok('free-form text is redacted', ($loggedInput['text'] ?? null) === '[redacted]');
    log_ok('BIN prefix is redacted', ($loggedInput['bin'] ?? null) === '[redacted]');
    log_ok('MAC address is redacted', ($loggedInput['mac'] ?? null) === '[redacted]');
    log_ok('CSRF token is omitted', !array_key_exists('csrf_token', (array) $loggedInput));
    log_ok('non-sensitive field remains', ($loggedInput['domain'] ?? null) === 'example.com');

    unset($_SERVER['REMOTE_ADDR'], $_SESSION['uid']);
    echo "\nPASS=$pass FAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
