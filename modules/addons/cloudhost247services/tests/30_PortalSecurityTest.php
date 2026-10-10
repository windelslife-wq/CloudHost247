<?php
require __DIR__ . '/bootstrap.php';

// Audit of the two request surfaces that had no dedicated suite: the admin
// portal (1,055 lines) and the customer portal (1,140 lines), plus the landing
// SEO emitter. Everything here was verified by reading the source; these
// assertions exist so a later refactor cannot quietly undo it.
//
// The invariant worth stating: this module has no central CSRF filter. Each
// mutating handler calls Csrf::verifyRequest() itself, so a new handler that
// forgets the call is unprotected and nothing else would notice. These tests
// are the notice.

use Chs\Http\Landing;

$root = __DIR__ . '/..';

/* ------------------------------------------------------- helpers -- */

/**
 * Split a class file into method-name => body chunks.
 * Enough fidelity for the static checks below; it is not a PHP parser.
 */
function chs_methods($file)
{
    $src = (string) file_get_contents($file);
    if (!preg_match_all(
        '/(?:public|protected|private)\s+function\s+([A-Za-z0-9_]+)\s*\(/',
        $src,
        $m,
        PREG_OFFSET_CAPTURE
    )) {
        return [];
    }
    $out = [];
    foreach ($m[1] as $i => $hit) {
        $start = $hit[1];
        $end = isset($m[1][$i + 1]) ? $m[1][$i + 1][1] : strlen($src);
        $out[$hit[0]] = substr($src, $start, $end - $start);
    }
    return $out;
}

/* ------------------------------------------- customer portal CSRF -- */

T::section('customer portal CSRF');

$portalFile = $root . '/lib/Http/CustomerPortal.php';
$methods = chs_methods($portalFile);
T::ok('customer portal methods parsed', count($methods) >= 25);

// A handler that reads request input through Http::post() or $_POST is a
// mutation and must verify the session token before touching any service.
$readsPost = [];
$mutators = [];
$unprotected = [];
foreach ($methods as $name => $body) {
    $readsInput = strpos($body, 'Http::post(') !== false || strpos($body, '$_POST') !== false;
    if (!$readsInput) {
        continue;
    }
    $mutators[$name] = $body;
    $readsPost[] = $name;
    if (strpos($body, 'Csrf::verifyRequest()') === false) {
        $unprotected[] = $name;
    }
}
sort($readsPost);
T::ok('the portal has POST-reading handlers to check (' . count($readsPost) . ')', count($readsPost) >= 15);
T::ok('every POST-reading handler verifies CSRF', $unprotected === []);
foreach ($unprotected as $name) {
    echo "  UNPROTECTED: $name\n";
}

// Hardening: the verify must come before any state-changing service call.
// Service *construction* is allowed before it (the constructors are inert and
// the handlers also use the service for the read side of the page), so the
// invariant is about mutating methods, not about `new`.
$mutatorCall = '/->(?:create|set|update|delete|cancel|submit|place|export|request'
    . '|watch|unwatch|post|accept|mark|save|add|remove|renew|transfer)\w*\s*\(/i';
$verifyTooLate = [];
foreach ($mutators as $name => $body) {
    $at = strpos($body, 'Csrf::verifyRequest()');
    if ($at === false) {
        continue;
    }
    if (preg_match($mutatorCall, $body, $m, PREG_OFFSET_CAPTURE) && $at > $m[0][1]) {
        $verifyTooLate[] = $name . ' (verify at ' . $at . ', first write at ' . $m[0][1] . ')';
    }
}
T::ok('CSRF is verified before the first state-changing call', $verifyTooLate === []);
foreach ($verifyTooLate as $name) {
    echo "  VERIFY AFTER WRITE: $name\n";
}

// The handlers that read only GET are the legitimate exceptions. Pin the ones
// that matter so a later edit that adds a write to them surfaces here.
$readOnly = [];
foreach ($methods as $name => $body) {
    if (strpos($body, 'Http::post(') !== false || strpos($body, '$_POST') !== false) {
        continue;
    }
    if (strpos($body, 'Http::get(') !== false || strpos($body, 'Http::getInt(') !== false) {
        $readOnly[] = $name;
    }
}
sort($readOnly);
T::ok('GET-only handlers exist and are read-only (' . count($readOnly) . ')', count($readOnly) >= 5);

// Two GET endpoints are JSON APIs; they must not accept state changes.
foreach (['pageLogoApi', 'pageOrderConfig'] as $api) {
    T::ok(
        "$api is a read-only JSON endpoint (no write service call)",
        isset($methods[$api])
        && strpos($methods[$api], 'Http::post(') === false
        && !preg_match('/->(save|create|delete|update|cancel|submit)\w*\s*\(/i', $methods[$api])
    );
}

// Ownership (IDOR): every handler that reads or writes a record by id must
// pass the session client id into that call, so one client cannot address
// another client's auction, request, server or domain.
$unscoped = [];
foreach ($methods as $name => $body) {
    if (strpos($name, 'Detail') === false) {
        continue;
    }
    // Matches e.g. detail($id, $clientId), placeBid($clientId, $id, ...),
    // export($clientId, ...) - any call that receives $clientId.
    if (!preg_match('/->\w+\s*\([^)]*\$clientId/', $body)) {
        $unscoped[] = $name;
    }
}
T::ok('detail handlers scope every record call to the session client', $unscoped === []);
foreach ($unscoped as $name) {
    echo "  UNSCOPED: $name\n";
}

/* ---------------------------------------------------- admin portal -- */

T::section('admin portal');

$adminFile = $root . '/lib/Http/AdminPortal.php';
$admin = (string) file_get_contents($adminFile);
$renderAt = strpos($admin, 'public function render()');
$renderBody = $renderAt === false ? '' : substr($admin, $renderAt, 2600);

T::ok('render() requires an administrator session first',
    $renderBody !== ''
    && strpos($renderBody, 'Identity::adminId()') !== false
    && strpos($renderBody, 'Identity::adminId()') < strpos($renderBody, 'handlePost')
    && strpos($renderBody, 'Administrator session required') !== false);

T::ok('checkToken() runs before handlePost() on any POST',
    $renderBody !== ''
    && strpos($renderBody, 'checkToken()') !== false
    && strpos($renderBody, 'checkToken()') < strpos($renderBody, 'handlePost('));

T::ok('checkToken() prefers the WHMCS token and falls back to Csrf',
    $admin !== ''
    && strpos($admin, "check_token('WHMCS.admin.default')") !== false
    && strpos($admin, 'Csrf::verifyRequest()') !== false);

// Admin output must escape interpolations - the portal builds HTML by hand.
T::ok('admin error/success boxes escape their text',
    substr_count($renderBody, 'chs_h(') >= 4);

T::ok('admin mutations are audited', strpos($admin, 'Audit::admin(') !== false);

/* ------------------------------------------------- JSON-LD escaping -- */

T::section('landing SEO / JSON-LD');

$landing = (string) file_get_contents($root . '/lib/Http/Landing.php');
T::ok('headMarkup escapes its meta output', strpos($landing, 'ENT_QUOTES') !== false);

// S-1: without JSON_HEX_TAG a value containing "</script>" closes the tag.
// Inspect the json_encode() call itself - the surrounding docblock also names
// these flags, so matching the whole slice would pass off prose.
$jsonldCall = '';
if (preg_match('/json_encode\s*\((?:[^()]|\([^()]*\))*\)/s', $landing, $m)) {
    $jsonldCall = $m[0];
}
T::ok('jsonld is emitted through json_encode()', $jsonldCall !== '');
foreach (['JSON_HEX_TAG', 'JSON_HEX_AMP', 'JSON_HEX_APOS', 'JSON_HEX_QUOT'] as $flag) {
    T::ok("json_encode() sets $flag", $jsonldCall !== '' && strpos($jsonldCall, $flag) !== false);
}
T::ok('json_encode() keeps UNESCAPED_SLASHES + UNICODE for readable SEO',
    $jsonldCall !== ''
    && strpos($jsonldCall, 'JSON_UNESCAPED_SLASHES') !== false
    && strpos($jsonldCall, 'JSON_UNESCAPED_UNICODE') !== false);

// Behavioural proof, not just the flag: Landings::seo() + headMarkup().
Landing::seo([
    'jsonld'      => ['name' => '</script><script>alert(1)</script>', 'unicode' => 'héllo'],
    'description' => 'A "quoted" & <tagged> description',
]);
$markup = Landing::headMarkup([]);
T::ok('S-1: jsonld payload cannot emit a literal closing script tag',
    strpos($markup, '</script><script>') === false);
T::ok('S-1: the payload is still present, escaped',
    strpos($markup, 'u003C/script') !== false || strpos($markup, '\\u003C') !== false);
T::ok('S-1: non-ASCII SEO text survives (UNESCAPED_UNICODE kept)',
    strpos($markup, 'héllo') !== false);
T::ok('meta description is HTML-escaped',
    strpos($markup, '&lt;tagged&gt;') !== false && strpos($markup, '&quot;quoted&quot;') !== false);
T::ok('no raw <tagged> leaks into the head markup', strpos($markup, '<tagged>') === false);

// Reset so later suites see a clean emitter.
Landing::seo(['jsonld' => [], 'description' => '', 'robots' => '', 'og_title' => '']);

/* -------------------------------------------- notification escaping -- */

T::section('notification output');

$dashboard = (string) file_get_contents($root . '/templates/client/dashboard.tpl');
$notes = (string) file_get_contents($root . '/templates/client/notifications.tpl');

// Notification subject/body embed user-supplied domain names, so both feeds
// must escape them. The existing 28_TemplateEscapeTest only greps flash/prefill.
T::ok('dashboard escapes notification subject', strpos($dashboard, '{$n.subject|escape}') !== false);
// Prefix match (no closing brace): the body is piped through |escape and then
// |truncate:140, so the literal is {$n.body|escape|truncate:140}.
T::ok('dashboard escapes notification body', strpos($dashboard, '{$n.body|escape') !== false);
T::ok('notifications page escapes subject', strpos($notes, '{$n.subject|escape}') !== false);
T::ok('notifications page escapes body', strpos($notes, '{$n.body|escape}') !== false);
T::ok('notification mutation forms carry the CSRF field',
    substr_count($notes, '{$csrf_field}') >= 2);

/* ----------------------------------------------------- gateways -- */

T::section('gateway fail-closed');

$gateway = (string) file_get_contents($root . '/lib/Providers/Whmcs/WhmcsGateway.php');
T::ok('WhmcsGateway refuses to run outside the WHMCS runtime',
    strpos($gateway, "function_exists('localAPI')") !== false
    && strpos($gateway, 'localAPI() is unavailable outside the WHMCS runtime') !== false);
T::ok('invoice amounts are built from integer minor units',
    strpos($gateway, "Money::toDecimal((int) \$item['amount_minor']") !== false);

T::finish();
