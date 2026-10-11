<?php
/**
 * CloudHost247 Tools - handler coverage and offline handler behaviour.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/Catalog.php';
require __DIR__ . '/../includes/Security.php';
require __DIR__ . '/../includes/RateLimiter.php';
foreach (glob(__DIR__ . '/../includes/tools/*_tools.php') as $file) {
    require_once $file;
}

$pass = 0;
$fail = 0;
function ok($label, $cond)
{
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL $label\n"; }
}
function eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; echo "  FAIL $label\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n"; }
}

// ---------- every catalog tool has a callable handler ----------
$missing = [];
foreach (CloudHost247ToolsCatalog::tools() as $slug => $tool) {
    if (!function_exists('CloudHost247_tool_' . $tool['handler'])) {
        $missing[] = $slug . ' (' . $tool['handler'] . ')';
    }
}
ok('all 91 handlers exist (' . count($missing) . ' missing: ' . implode(', ', array_slice($missing, 0, 8)) . ')',
    empty($missing));
eq('handler count', count(CloudHost247ToolsCatalog::tools()), 91);

// ---------- text_to_binary ----------
$r = CloudHost247_tool_text_to_binary(['text' => 'Hi']);
eq('encode binary', $r['result'], '01001000 01101001');
eq('encode char count', $r['characters'], 2);
$r = CloudHost247_tool_text_to_binary(['text' => 'Hi', 'base' => 'hexadecimal']);
eq('encode hex', $r['result'], '48 69');
$r = CloudHost247_tool_text_to_binary(['text' => 'Hi', 'base' => 'decimal']);
eq('encode decimal', $r['result'], '72 105');
$r = CloudHost247_tool_text_to_binary(['text' => 'Hi', 'base' => 'octal']);
eq('encode octal', $r['result'], '110 151');
$r = CloudHost247_tool_text_to_binary(['text' => '01001000 01101001', 'mode' => 'decode']);
eq('decode binary', $r['result'], 'Hi');
ok('decode flags utf8', $r['valid_utf8'] === true);
$r = CloudHost247_tool_text_to_binary(['text' => '48 69', 'mode' => 'decode', 'base' => 'hexadecimal']);
eq('decode hex', $r['result'], 'Hi');
// UTF-8 multibyte round trip
$r = CloudHost247_tool_text_to_binary(['text' => 'é']);
eq('utf8 two bytes', $r['bytes'], 2);
eq('utf8 one char', $r['characters'], 1);
$back = CloudHost247_tool_text_to_binary(['text' => $r['result'], 'mode' => 'decode']);
eq('utf8 round trip', $back['result'], 'é');
eq('codepoint', $r['breakdown'][0]['codepoint'], 'U+00E9');
ok('empty rejected', isset(CloudHost247_tool_text_to_binary([])['error']));
ok('bad base rejected', isset(CloudHost247_tool_text_to_binary(['text' => 'x', 'base' => 'base99'])['error']));
ok('invalid digit rejected', isset(CloudHost247_tool_text_to_binary(['text' => '2222', 'mode' => 'decode'])['error']));
ok('out of range rejected', isset(CloudHost247_tool_text_to_binary(['text' => '999', 'mode' => 'decode', 'base' => 'decimal'])['error']));

// ---------- email_verifier (syntax paths only; no network) ----------
$r = CloudHost247_tool_email_verifier(['email' => 'not-an-email']);
eq('bad syntax verdict', $r['verdict'], 'Invalid');
eq('bad syntax score', $r['score'], 0);
ok('empty rejected', isset(CloudHost247_tool_email_verifier([])['error']));
ok('overlong rejected', isset(CloudHost247_tool_email_verifier(['email' => str_repeat('a', 250) . '@x.com'])['error']));

// ---------- runic_translator ----------
$r = CloudHost247_tool_runic_translator(['text' => 'thor']);
eq('digraph th wins', mb_substr($r['result'], 0, 1), 'ᚦ');
eq('elder futhark thor', $r['result'], 'ᚦᛟᚱ');
$r = CloudHost247_tool_runic_translator(['text' => 'a b']);
eq('space becomes word divider', $r['result'], 'ᚨ᛫ᛒ');
eq('plain restores spaces', $r['plain'], 'ᚨ ᛒ');
$r = CloudHost247_tool_runic_translator(['text' => 'THOR']);
eq('case insensitive', $r['result'], 'ᚦᛟᚱ');
$r = CloudHost247_tool_runic_translator(['text' => 'ᚦᛟᚱ', 'mode' => 'decode']);
eq('decode round trip', $r['result'], 'thor');
$r = CloudHost247_tool_runic_translator(['text' => 'thor', 'alphabet' => 'younger_futhark']);
ok('younger futhark differs', $r['result'] !== 'ᚦᛟᚱ');
eq('younger futhark name', $r['alphabet'], 'Younger Futhark (long-branch)');
$r = CloudHost247_tool_runic_translator(['text' => 'hi', 'alphabet' => 'anglo_saxon']);
eq('anglo saxon', $r['result'], 'ᚻᛁ');
ok('unknown alphabet rejected', isset(CloudHost247_tool_runic_translator(['text' => 'x', 'alphabet' => 'klingon'])['error']));
ok('empty rejected', isset(CloudHost247_tool_runic_translator(['text' => '  '])['error']));
$r = CloudHost247_tool_runic_translator(['text' => 'a1!']);
ok('digits/punctuation preserved', strpos($r['result'], '1') !== false && strpos($r['result'], '!') !== false);

// ---------- invisible_character ----------
$r = CloudHost247_tool_invisible_character(['mode' => 'generate', 'character' => 'U+200B', 'count' => 3]);
eq('generate 3 zwsp', $r['result'], "\u{200B}\u{200B}\u{200B}");
eq('byte length', $r['bytes'], 9);
eq('html entities', $r['html'], '&#x200B;&#x200B;&#x200B;');
ok('catalogue present', count($r['catalogue']) >= 15);
ok('count 0 rejected', isset(CloudHost247_tool_invisible_character(['mode' => 'generate', 'count' => 0])['error']));
ok('count 9999 rejected', isset(CloudHost247_tool_invisible_character(['mode' => 'generate', 'count' => 9999])['error']));
ok('unknown code rejected', isset(CloudHost247_tool_invisible_character(['mode' => 'generate', 'character' => 'U+FFFF'])['error']));

$dirty = "he\u{200B}llo\u{00A0}world\u{200B}";
$r = CloudHost247_tool_invisible_character(['mode' => 'detect', 'text' => $dirty]);
eq('detect hidden count', $r['hidden_count'], 3);
eq('detect distinct kinds', count($r['found']), 2);
eq('cleaned output', $r['cleaned'], 'helloworld');
ok('not clean', $r['is_clean'] === false);
$r = CloudHost247_tool_invisible_character(['mode' => 'detect', 'text' => 'clean text']);
ok('clean text detected as clean', $r['is_clean'] === true);
eq('clean hidden count', $r['hidden_count'], 0);
ok('detect empty rejected', isset(CloudHost247_tool_invisible_character(['mode' => 'detect', 'text' => ''])['error']));

// ---------- wifi_qr_scanner ----------
$r = CloudHost247_tool_wifi_qr_scanner(['mode' => 'build', 'ssid' => 'MyNet', 'password' => 'supersecret1', 'auth' => 'WPA']);
eq('build uri', $r['uri'], 'WIFI:T:WPA;S:MyNet;P:supersecret1;;');
$r = CloudHost247_tool_wifi_qr_scanner(['mode' => 'build', 'ssid' => 'Guest;Net', 'password' => 'passwordxx', 'auth' => 'WPA', 'hidden' => 1]);
eq('build escapes semicolon', $r['uri'], 'WIFI:T:WPA;S:Guest\;Net;P:passwordxx;H:true;;');
$r = CloudHost247_tool_wifi_qr_scanner(['mode' => 'build', 'ssid' => 'Open', 'auth' => 'NOPASS']);
eq('open network uri', $r['uri'], 'WIFI:T:NOPASS;S:Open;;');
ok('short wpa password rejected', isset(CloudHost247_tool_wifi_qr_scanner(['mode' => 'build', 'ssid' => 'x', 'password' => 'short', 'auth' => 'WPA'])['error']));
ok('missing ssid rejected', isset(CloudHost247_tool_wifi_qr_scanner(['mode' => 'build', 'ssid' => ''])['error']));
ok('long ssid rejected', isset(CloudHost247_tool_wifi_qr_scanner(['mode' => 'build', 'ssid' => str_repeat('a', 40), 'auth' => 'NOPASS'])['error']));

$r = CloudHost247_tool_wifi_qr_scanner(['mode' => 'parse', 'uri' => 'WIFI:T:WPA;S:MyNet;P:supersecret1;;']);
eq('parse ssid', $r['ssid'], 'MyNet');
eq('parse password', $r['password'], 'supersecret1');
eq('parse auth label', $r['auth_label'], 'WPA/WPA2 Personal');
$r = CloudHost247_tool_wifi_qr_scanner(['mode' => 'parse', 'uri' => 'WIFI:T:WPA;S:Guest\;Net;P:passwordxx;H:true;;']);
eq('parse unescapes', $r['ssid'], 'Guest;Net');
ok('parse hidden flag', $r['hidden'] === true);
$r = CloudHost247_tool_wifi_qr_scanner(['mode' => 'parse', 'uri' => 'WIFI:T:WEP;S:Old;P:1234567890;;']);
ok('wep warned', count($r['warnings']) >= 1);
$r = CloudHost247_tool_wifi_qr_scanner(['mode' => 'parse', 'uri' => 'WIFI:T:NOPASS;S:Open;;']);
ok('open network warned', count($r['warnings']) >= 1);
ok('non-wifi payload rejected', isset(CloudHost247_tool_wifi_qr_scanner(['mode' => 'parse', 'uri' => 'https://example.com'])['error']));
ok('parse empty rejected', isset(CloudHost247_tool_wifi_qr_scanner(['mode' => 'parse', 'uri' => ''])['error']));
ok('missing ssid in payload rejected', isset(CloudHost247_tool_wifi_qr_scanner(['mode' => 'parse', 'uri' => 'WIFI:T:WPA;P:x;;'])['error']));

// round trip with tricky characters
$tricky = 'Caf\'e: "A,B"';
$built = CloudHost247_tool_wifi_qr_scanner(['mode' => 'build', 'ssid' => $tricky, 'auth' => 'NOPASS']);
$parsed = CloudHost247_tool_wifi_qr_scanner(['mode' => 'parse', 'uri' => $built['uri']]);
eq('wifi escape round trip', $parsed['ssid'], $tricky);

// ---------- speed_test ----------
$r = CloudHost247_tool_speed_test(['action' => 'config']);
ok('config has phases', isset($r['phases']['latency'], $r['phases']['download'], $r['phases']['upload']));
ok('config has honest disclaimer', stripos($r['note'], 'single-server') !== false);
$r = CloudHost247_tool_speed_test(['action' => 'download', 'size' => 99999999]);
eq('download size clamped', $r['size'], 26214400);
$r = CloudHost247_tool_speed_test(['action' => 'download', 'size' => 1]);
eq('download size floored', $r['size'], 65536);
$r = CloudHost247_tool_speed_test(['action' => 'ping', 'sequence' => 4]);
eq('ping echoes sequence', $r['sequence'], 4);
ok('ping has server time', $r['server_time'] > 0);
$r = CloudHost247_tool_speed_test(['action' => 'upload', 'payload' => str_repeat('x', 1000)]);
eq('upload counts bytes', $r['bytes_received'], 1000);

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
