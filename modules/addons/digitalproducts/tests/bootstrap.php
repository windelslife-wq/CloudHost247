<?php
define('DIGITALPRODUCTS_TESTING', true);
require_once dirname(__DIR__) . '/autoload.php';
class DPTest
{
    public static $pass = 0; public static $fail = 0; public static $failures = [];
    public static function ok($name, $value) { if ($value) self::$pass++; else { self::$fail++; self::$failures[] = $name; echo "FAIL {$name}\n"; } }
    public static function same($name, $expected, $actual) { self::ok($name, $expected === $actual); if ($expected !== $actual) echo '  expected ' . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n"; }
    public static function throws($name, $class, callable $fn) { try { $fn(); self::ok($name, false); } catch (\Throwable $e) { self::ok($name, $e instanceof $class); } }
    public static function summary() { echo 'PASS=' . self::$pass . ' FAIL=' . self::$fail . "\n"; return self::$fail ? 1 : 0; }
}
