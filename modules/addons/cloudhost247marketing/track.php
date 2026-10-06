<?php
/**
 * Public tracking endpoint.
 *
 *   track.php?t=o&r=TOKEN              open pixel
 *   track.php?t=c&r=TOKEN&l=LINKTOKEN  click redirect
 *   track.php?t=u&r=TOKEN              unsubscribe (GET page, POST = one-click)
 *   track.php?t=p&r=TOKEN              preference centre
 *   track.php?t=v&r=TOKEN              view this email in a browser
 *
 * The only credential is the per-recipient token. There is no session here.
 */

$init = dirname(__DIR__, 3) . '/init.php';
if (is_file($init)) {
    require_once $init;
}
require_once __DIR__ . '/autoload.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

// A tracking pixel must never render a PHP warning into someone's inbox.
@ini_set('display_errors', '0');

try {
    (new \Ch247Mkt\Http\TrackingEndpoint())->handle();
} catch (\Throwable $e) {
    if (function_exists('logActivity')) {
        logActivity('CloudHost247 Marketing tracking endpoint failed: ' . $e->getMessage());
    }
    http_response_code(200);
    header('Content-Type: image/gif');
    echo base64_decode(\Ch247Mkt\Http\TrackingEndpoint::PIXEL);
}
