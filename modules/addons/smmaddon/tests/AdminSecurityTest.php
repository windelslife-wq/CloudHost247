<?php
/**
 * Offline tests for admin CSRF checks and template output escaping.
 * Run with run.mjs (php-wasm). Prints FAIL=<n> on completion.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION = [];

require_once __DIR__ . '/../lib/AdminDispatcher.php';

$pass = 0;
$fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
}

// ---- CSRF ----
$d = new SmmAddon\AdminDispatcher();
$tokenM = new ReflectionMethod($d, 'csrfToken');
$validM = new ReflectionMethod($d, 'csrfValid');
$tokenM->setAccessible(true);
$validM->setAccessible(true);

$_POST = [];
check('csrf: POST without token rejected', !$validM->invoke($d));

$token = $tokenM->invoke($d);
check('csrf: token is 64 hex chars', strlen($token) === 64 && ctype_xdigit($token));
check('csrf: token stable within session', $tokenM->invoke($d) === $token);

$_POST = ['csrf_token' => $token];
check('csrf: matching token accepted', $validM->invoke($d));

$_POST = ['csrf_token' => str_repeat('0', 64)];
check('csrf: wrong token rejected', !$validM->invoke($d));

$_POST = ['csrf_token' => ''];
check('csrf: empty token rejected', !$validM->invoke($d));

$_SESSION = [];
$_POST = ['csrf_token' => $token];
check('csrf: token rejected when no session token exists', !$validM->invoke($d));

// ---- Template escaping ----
function render($template, array $vars) {
    extract($vars, EXTR_SKIP);
    ob_start();
    include $template;
    return ob_get_clean();
}
$tpl = __DIR__ . '/../templates/admin/';
$xss = '<script>alert(1)</script>';
$img = '"><img src=x onerror=alert(2)>';

$order = (object) [
    'id' => 1, 'smm_order_id' => $xss, 'smm_service_id' => 'S1', 'quantity' => 10,
    'link' => $xss, 'status' => 'pending', 'last_check' => null, 'created_at' => '2026-10-10',
];
$html = render($tpl . 'dashboard.php', [
    'flash' => ['type' => 'error', 'message' => $img],
    'modulelink' => 'addonmodules.php?module=smmaddon',
    'csrf' => 'tok',
    'stats' => ['total_services' => 1, 'active_services' => 1, 'total_orders' => 1,
                'pending_orders' => 1, 'processing_orders' => 0, 'completed_orders' => 0],
    'recentOrders' => [$order],
]);
check('dashboard: order link script escaped', strpos($html, '<script>alert(1)</script>') === false);
check('dashboard: escaped link visible', strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false);
check('dashboard: flash message escaped', strpos($html, '<img src=x') === false);

$log = (object) [
    'id' => 1, 'action' => $xss, 'endpoint' => 'https://x', 'request' => '', 'response' => '', 'http_code' => 500,
    'error' => $img, 'created_at' => '2026-10-10',
];
$html = render($tpl . 'logs.php', [
    'flash' => ['type' => 'success', 'message' => 'ok'],
    'modulelink' => 'addonmodules.php?module=smmaddon',
    'csrf' => 'tok',
    'logs' => [$log],
]);
check('logs: provider error escaped', strpos($html, '<img src=x') === false);
check('logs: action escaped', strpos($html, '<script>alert(1)</script>') === false);

// ---- Forms carry the token ----
$html = render($tpl . 'logs.php', [
    'flash' => ['type' => 'success', 'message' => 'ok'],
    'modulelink' => 'addonmodules.php?module=smmaddon',
    'csrf' => 'TOKEN123',
    'logs' => [],
]);
check('logs: clear-logs form carries csrf_token', strpos($html, 'name="csrf_token" value="TOKEN123"') !== false);

echo "\nadmin security: PASS=$pass FAIL=$fail\n";
echo "FAIL=$fail\n";
