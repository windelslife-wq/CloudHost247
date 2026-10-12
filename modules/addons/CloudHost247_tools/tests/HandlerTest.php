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

// ---------- outbound-network regression guards (no socket is opened) ----------
$r = CloudHost247_tools_curl('http://127.0.0.1/');
eq('legacy cURL helper preserves error envelope', $r['code'], 0);
ok('legacy cURL helper blocks loopback before transport', stripos($r['error'], 'reserved') !== false);
ok('HTTP headers blocks loopback', isset(CloudHost247_tool_http_headers(['url' => 'http://127.0.0.1/'])['error']));
ok('server OS tool propagates safe-fetch failure', isset(CloudHost247_tool_server_os_detector(['url' => 'http://127.0.0.1/'])['error']));
ok('port checker blocks loopback', isset(CloudHost247_tool_port_checker(['host' => '127.0.0.1', 'port' => 80])['error']));
ok('port checker rejects unlisted port before connecting', isset(CloudHost247_tool_port_checker(['host' => '8.8.8.8', 'port' => 31337])['error']));
ok('SMTP test blocks loopback', isset(CloudHost247_tool_smtp_test(['host' => '127.0.0.1', 'port' => 25])['error']));
ok('SMTP test rejects non-SMTP port before connecting', isset(CloudHost247_tool_smtp_test(['host' => '8.8.8.8', 'port' => 2525])['error']));
ok('TLS checker blocks loopback', isset(CloudHost247_tool_ssl_checker(['host' => '127.0.0.1', 'port' => 443])['error']));
ok('TLS checker rejects unlisted TLS port before connecting', isset(CloudHost247_tool_ssl_checker(['host' => '8.8.8.8', 'port' => 22])['error']));
ok('IPv4 ping helper blocks private addresses', isset(CloudHost247_tool_ping_ipv4(['host' => '10.0.0.1'])['error']));
ok('IPv6 ping helper blocks loopback', isset(CloudHost247_tool_ping_ipv6(['host' => '::1'])['error']));
ok('traceroute blocks private addresses', isset(CloudHost247_tool_traceroute(['host' => '192.168.1.1'])['error']));
eq('deprecated raw shell helper never executes', CloudHost247_tools_safe_exec('echo unsafe'), null);
ok('WHOIS blocks query line breaks before network access', isset(CloudHost247_tools_whois("example.com\r\nwhoami")['error']));
ok('WHOIS rejects URL/path input', isset(CloudHost247_tools_whois('https://example.com/path')['error']));
ok('WHOIS rejects caller-selected servers', isset(CloudHost247_tools_whois('example.com', '127.0.0.1')['error']));
ok('WHOIS rejects non-string server values', isset(CloudHost247_tools_whois('example.com', ['whois.example'])['error']));
ok('domain WHOIS rejects array input cleanly', isset(CloudHost247_tool_domain_whois(['domain' => ['example.com']])['error']));
ok('domain search rejects array input cleanly', isset(CloudHost247_tool_domain_availability(['domain' => ['example.com']])['error']));
eq('domain input normalizes one terminal root dot only', 'example.com', CloudHost247_tools_sanitize('Example.COM.', 'domain'));
eq('domain input preserves malformed characters for rejection', 'example.com<script>', CloudHost247_tools_sanitize('example.com<script>', 'domain'));
ok('domain validator accepts normalized ordinary domains', CloudHost247_tools_validate_domain('example.com'));
ok('domain validator accepts valid punycode TLD labels', CloudHost247_tools_validate_domain('example.xn--p1ai'));
ok('domain validator rejects a leading hyphen label', !CloudHost247_tools_validate_domain('-bad.example'));
ok('domain validator rejects repeated terminal dots', !CloudHost247_tools_validate_domain('example.com..'));
ok('domain validator rejects local-use .local suffixes', !CloudHost247_tools_validate_domain('mail.local'));
ok('domain validator rejects local-use .internal suffixes', !CloudHost247_tools_validate_domain('metadata.internal'));
eq('DNS helper refuses direct local-use query names without consulting the cache or resolver', [], CloudHost247_tools_dns_query('_dmarc.metadata.internal', 'TXT'));
ok('domain search rejects malformed punctuation instead of searching a changed name',
    isset(CloudHost247_tool_domain_availability(['domain' => 'example.com<script>'])['error']));
eq('IP input preserves invalid characters for strict validation', '2001:db8::1z', CloudHost247_tools_sanitize('2001:db8::1z', 'ip'));
ok('invalid IPv6 is not sanitized into a valid address', !CloudHost247_tools_validate_ipv6(CloudHost247_tools_sanitize('2001:db8::1z', 'ip')));
ok('IPv4 DNSBL checker rejects IPv6 instead of reporting false clean', isset(CloudHost247_tool_ip_blacklist(['ip' => '2001:4860:4860::8888'])['error']));
ok('IPv4 DNSBL checker rejects private ranges', isset(CloudHost247_tool_ip_blacklist(['ip' => '192.168.1.1'])['error']));
ok('IPv4 DNSBL checker rejects reserved ranges', isset(CloudHost247_tool_ip_blacklist(['ip' => '203.0.113.7'])['error']));
ok('reverse-IP tool rejects private addresses before PTR lookup', isset(CloudHost247_tool_reverse_ip_lookup(['ip' => '10.0.0.1'])['error']));
$oldRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$myIpResult = CloudHost247_tool_what_is_my_ip([]);
if ($oldRemoteAddr === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $oldRemoteAddr; }
ok('what-is-my-IP does not reverse-query a loopback client address', $myIpResult['hostname'] === null);
eq('email input preserves invalid punctuation for strict validation', 'ops+tag@example.com!', CloudHost247_tools_sanitize('ops+tag@example.com!', 'email'));
$r = CloudHost247_tool_dmarc_generator(['domain' => 'Example.COM.', 'policy' => 'reject', 'pct' => 50, 'rua' => 'dmarc@example.com', 'aspf' => 's', 'adkim' => 'r']);
eq('DMARC generator canonical domain', $r['domain'], 'example.com');
eq('DMARC generator valid record', $r['record'], 'v=DMARC1; p=reject; pct=50; rua=mailto:dmarc@example.com; aspf=s; adkim=r');
ok('DMARC generator rejects malformed domain', isset(CloudHost247_tool_dmarc_generator(['domain' => 'https://example.com'])['error']));
ok('DMARC generator rejects policy injection', isset(CloudHost247_tool_dmarc_generator(['domain' => 'example.com', 'policy' => 'none; rua=attacker@example.com'])['error']));
ok('DMARC generator rejects percentage outside the RFC range', isset(CloudHost247_tool_dmarc_generator(['domain' => 'example.com', 'pct' => 101])['error']));
ok('DMARC generator rejects a malformed report address without stripping it', isset(CloudHost247_tool_dmarc_generator(['domain' => 'example.com', 'rua' => 'dmarc@example.com<script>'])['error']));
ok('DKIM checker rejects selector path injection', isset(CloudHost247_tool_dkim_checker(['domain' => 'example.com', 'selector' => 'default._domainkey'])['error']));

// ---------- BIN data: strict input and explicit provenance, without network ----------
ok('BIN checker rejects a full card number before transport', isset(CloudHost247_tool_bin_checker(['bin' => '4111111111111111'])['error']));
ok('BIN checker rejects punctuation rather than stripping it', isset(CloudHost247_tool_bin_checker(['bin' => '411111<script>'])['error']));
ok('BIN checker rejects numeric JSON values that could lose leading zeroes', isset(CloudHost247_tool_bin_checker(['bin' => 411111])['error']));
$r = CloudHost247_tools_bin_lookup_result('411111', ['code' => 0, 'body' => '']);
eq('BIN local fallback is explicitly identified', $r['status'], 'local_fallback');
ok('BIN local fallback warns that coverage is partial', strpos($r['source'], 'limited') !== false && strpos($r['note'], 'not a complete') !== false);
$r = CloudHost247_tools_bin_lookup_result('411111', ['code' => 200, 'body' => json_encode([
    'scheme' => 'visa', 'type' => 'debit', 'bank' => ['name' => 'Example Bank'], 'country' => ['name' => 'Nigeria'],
])]);
eq('BIN provider data overrides heuristic network', $r['network'], 'Visa');
eq('BIN provider field source is visible', $r['sources']['bank'], 'Binlist API (first six digits only)');
$r = CloudHost247_tools_bin_lookup_result('999999', ['code' => 0, 'body' => '']);
eq('unknown BIN remains unknown without data', $r['status'], 'no_data');

$r = CloudHost247_tool_mac_lookup(['mac' => '00:00:0C:12:34:56']);
eq('MAC sample match remains labelled as a bundled sample', $r['status'], 'bundled_sample_match');
eq('MAC sample provenance is visible', $r['source'], 'Bundled local prefix sample (source/version not recorded)');
ok('MAC sample warns that unmatched OUIs may still be assigned', strpos($r['coverage_note'], 'partial and unversioned') !== false);
ok('MAC lookup rejects malformed characters rather than removing them', isset(CloudHost247_tool_mac_lookup(['mac' => '00:00:0C:12:34:56!'])['error']));
$r = CloudHost247_tool_mac_lookup(['mac' => '02:00:00:12:34:56']);
eq('MAC locally administered address has no vendor claim', $r['status'], 'local_address');

// ---------- provider-dependent statuses never invent availability ----------
$r = CloudHost247_tools_classify_domain_whois(['server' => 'whois.verisign-grs.com', 'result' => 'No match for "example.com".'], 'example.com');
eq('WHOIS no-match is labelled as a report, not guaranteed availability', $r['status'], 'no_match_reported');
ok('WHOIS no-match disclaimer is present', strpos($r['note'], 'does not guarantee') !== false);
$r = CloudHost247_tools_classify_domain_whois(['server' => 'whois.verisign-grs.com', 'result' => "Domain Name: EXAMPLE.COM\nRegistry Domain ID: 123"], 'example.com');
eq('WHOIS matching record is explicit', $r['status'], 'record_found');
$r = CloudHost247_tools_classify_domain_whois(['server' => 'whois.example', 'result' => 'unrecognised server response'], 'example.com');
eq('unrecognised WHOIS response stays unconfirmed', $r['status'], 'unconfirmed');
$r = CloudHost247_tools_classify_domain_whois(['error' => 'network failure'], 'example.com');
eq('WHOIS transport failure stays unconfirmed', $r['status'], 'unconfirmed');
$r = CloudHost247_tools_classify_domain_whois(['server' => 'whois.example', 'result' => 'No match for example.com', 'truncated' => true], 'example.com');
eq('truncated WHOIS response stays unconfirmed', $r['status'], 'unconfirmed');

$r = CloudHost247_tool_pagerank(['domain' => 'example.com']);
ok('PageRank never supplies a fabricated score', !array_key_exists('available', $r) && !array_key_exists('score', $r));
ok('PageRank explains retired metric', strpos($r['note'], 'retired') !== false);
$r = CloudHost247_tool_username_checker(['username' => 'alice_7']);
eq('username links remain manual checks', $r['platforms'][0]['status'], 'Manual check required');
ok('username checker emits no availability boolean', !array_key_exists('available', $r['platforms'][0]));
eq('username profile URL uses a fixed HTTPS host', $r['platforms'][2]['url'], 'https://github.com/alice_7');
ok('username checker does not claim provider availability', strpos($r['note'], 'does not query platform availability') !== false);
ok('username rejects path or markup characters', isset(CloudHost247_tool_username_checker(['username' => 'foo"><svg>'])['error']));
ok('username rejects leading whitespace instead of trimming into another name', isset(CloudHost247_tool_username_checker(['username' => ' alice'])['error']));
ok('username rejects trailing whitespace instead of trimming into another name', isset(CloudHost247_tool_username_checker(['username' => 'alice '])['error']));

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
$localEmailResult = CloudHost247_tool_email_verifier(['email' => 'user@metadata.internal']);
ok('email verifier reports local-use domains as unconfirmed without public DNS checks',
    $localEmailResult['verdict'] === 'Unconfirmed'
    && strpos($localEmailResult['note'], 'does not query local') !== false
    && !in_array('Domain resolves', array_column($localEmailResult['checks'], 'name'), true));

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
