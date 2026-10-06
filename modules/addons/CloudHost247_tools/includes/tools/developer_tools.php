<?php
/**
 * CloudHost247 Tools - Developer Tools Implementation
 */

if (!defined("WHMCS") && !defined("CLOUDHOST247_TOOLS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/../functions.php';

function CloudHost247_tool_http_headers($post)
{
    $url = CloudHost247_tools_sanitize($post['url'] ?? '', 'url');
    if (!CloudHost247_tools_validate_url($url)) {
        return ['error' => 'Invalid URL'];
    }

    $result = CloudHost247_tools_curl($url, null, [], 15);
    if (!empty($result['error'])) {
        return ['error' => 'Could not fetch URL: ' . $result['error']];
    }

    $headers = [];
    if (function_exists('curl_getinfo')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $headerStr = curl_exec($ch);
        curl_close($ch);

        $lines = explode("\n", $headerStr);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (strpos($line, 'HTTP/') === 0) {
                $headers[] = ['status' => $line];
            } elseif (strpos($line, ':') !== false) {
                list($key, $val) = explode(':', $line, 2);
                $headers[] = ['name' => trim($key), 'value' => trim($val)];
            }
        }
    }

    return [
        'url' => $url,
        'http_code' => $result['code'],
        'headers' => $headers,
    ];
}

function CloudHost247_tool_server_os_detector($post)
{
    $url = CloudHost247_tools_sanitize($post['url'] ?? '', 'url');
    if (!CloudHost247_tools_validate_url($url)) {
        return ['error' => 'Invalid URL'];
    }

    $result = CloudHost247_tools_curl($url, null, [], 15);
    $os = 'Unknown';
    $server = 'Unknown';

    if ($result['code'] > 0) {
        preg_match('/Server:\s*([^\r\n]+)/i', $result['body'], $serverMatch);
        if ($serverMatch) {
            $server = trim($serverMatch[1]);
            if (stripos($server, 'win') !== false) $os = 'Windows';
            elseif (stripos($server, 'apache') !== false) $os = 'Linux/Unix';
            elseif (stripos($server, 'nginx') !== false) $os = 'Linux/Unix';
            elseif (stripos($server, 'iis') !== false) $os = 'Windows';
        }

        // Try more headers
        $headers = CloudHost247_tool_http_headers(['url' => $url]);
        if (isset($headers['headers'])) {
            foreach ($headers['headers'] as $h) {
                if (isset($h['name'])) {
                    if (strtolower($h['name']) === 'x-powered-by') {
                        if (stripos($h['value'], 'win') !== false) $os = 'Windows';
                    }
                }
            }
        }
    }

    return ['url' => $url, 'server' => $server, 'os_guess' => $os];
}

function CloudHost247_tool_md5_base64($post)
{
    $input = $_POST['input'] ?? '';
    $mode = CloudHost247_tools_sanitize($post['mode'] ?? 'md5', 'string');

    if (empty($input)) {
        return ['error' => 'Please enter text to hash'];
    }

    switch ($mode) {
        case 'md5':
            return ['input' => $input, 'md5' => md5($input), 'type' => 'MD5'];
        case 'sha1':
            return ['input' => $input, 'sha1' => sha1($input), 'type' => 'SHA1'];
        case 'sha256':
            return ['input' => $input, 'sha256' => hash('sha256', $input), 'type' => 'SHA256'];
        case 'base64_encode':
            return ['input' => $input, 'base64' => base64_encode($input), 'type' => 'Base64 Encode'];
        case 'base64_decode':
            $decoded = base64_decode($input, true);
            if ($decoded === false) {
                return ['error' => 'Invalid Base64 string'];
            }
            return ['input' => $input, 'decoded' => $decoded, 'type' => 'Base64 Decode'];
        default:
            return ['error' => 'Unknown mode'];
    }
}

function CloudHost247_tool_multi_url_opener($post)
{
    $urls = $_POST['urls'] ?? '';
    $lines = array_filter(array_map('trim', explode("\n", $urls)));
    $validUrls = [];

    foreach ($lines as $line) {
        $url = CloudHost247_tools_sanitize($line, 'url');
        if (CloudHost247_tools_validate_url($url)) {
            $validUrls[] = $url;
        }
    }

    return ['urls' => $validUrls, 'count' => count($validUrls)];
}

function CloudHost247_tool_smtp_test($post)
{
    $host = CloudHost247_tools_sanitize($post['host'] ?? '', 'domain');
    $port = (int) ($post['port'] ?? 25);
    $timeout = 10;

    if (empty($host)) {
        return ['error' => 'Please enter SMTP server address'];
    }

    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['host' => $host, 'port' => $port, 'reachable' => false, 'error' => $errstr];
    }

    $banner = fgets($socket, 512);
    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return [
        'host' => $host,
        'port' => $port,
        'reachable' => true,
        'banner' => trim($banner),
    ];
}

function CloudHost247_tool_htaccess_generator($post)
{
    $type = CloudHost247_tools_sanitize($post['redirect_type'] ?? '301', 'string');
    $from = CloudHost247_tools_sanitize($post['from'] ?? '', 'string');
    $to = CloudHost247_tools_sanitize($post['to'] ?? '', 'string');

    if (empty($from) || empty($to)) {
        return ['error' => 'Please enter both from and to URLs'];
    }

    $code = "RewriteEngine On\n";
    $code .= "RewriteRule ^" . ltrim($from, '/') . "$ " . $to . " [R=" . $type . ",L]";

    $alt = "Redirect " . $type . " " . $from . " " . $to;

    return [
        'rewrite_rule' => $code,
        'redirect_directive' => $alt,
        'type' => $type,
    ];
}

function CloudHost247_tool_url_rewrite($post)
{
    // Alias to htaccess generator
    return CloudHost247_tool_htaccess_generator($post);
}

function CloudHost247_tool_broken_link_checker($post)
{
    $url = CloudHost247_tools_sanitize($post['url'] ?? '', 'url');
    if (!CloudHost247_tools_validate_url($url)) {
        return ['error' => 'Invalid URL'];
    }

    $result = CloudHost247_tools_curl($url, null, [], 20);
    if (empty($result['body'])) {
        return ['error' => 'Could not fetch page'];
    }

    // Extract links
    preg_match_all('/href=["\']([^"\']+)["\']/i', $result['body'], $matches);
    $links = array_unique($matches[1]);
    $checked = [];
    $broken = 0;

    $maxCheck = min(count($links), 20); // Limit to 20 links
    foreach (array_slice($links, 0, $maxCheck) as $link) {
        if (strpos($link, '#') === 0 || strpos($link, 'javascript:') === 0 || strpos($link, 'mailto:') === 0) continue;
        if (strpos($link, 'http') !== 0) {
            $parsed = parse_url($url);
            $base = $parsed['scheme'] . '://' . $parsed['host'];
            $link = $base . (strpos($link, '/') === 0 ? '' : '/') . $link;
        }

        $res = CloudHost247_tools_curl($link, null, [], 10);
        $ok = $res['code'] >= 200 && $res['code'] < 400;
        if (!$ok) $broken++;
        $checked[] = ['url' => $link, 'status' => $res['code'], 'ok' => $ok];
    }

    return [
        'checked' => count($checked),
        'broken' => $broken,
        'links' => $checked,
    ];
}

function CloudHost247_tool_open_graph($post)
{
    $title = CloudHost247_tools_sanitize($post['title'] ?? '', 'string');
    $description = CloudHost247_tools_sanitize($post['description'] ?? '', 'string');
    $url = CloudHost247_tools_sanitize($post['url'] ?? '', 'url');
    $image = CloudHost247_tools_sanitize($post['image'] ?? '', 'url');
    $type = CloudHost247_tools_sanitize($post['type'] ?? 'website', 'string');

    $tags = "<!-- Open Graph / Facebook -->\n";
    if ($title) $tags .= '<meta property="og:title" content="' . htmlspecialchars($title) . '" />' . "\n";
    if ($description) $tags .= '<meta property="og:description" content="' . htmlspecialchars($description) . '" />' . "\n";
    if ($url) $tags .= '<meta property="og:url" content="' . htmlspecialchars($url) . '" />' . "\n";
    if ($image) $tags .= '<meta property="og:image" content="' . htmlspecialchars($image) . '" />' . "\n";
    $tags .= '<meta property="og:type" content="' . htmlspecialchars($type) . '" />' . "\n\n";

    $tags .= "<!-- Twitter -->\n";
    if ($title) $tags .= '<meta property="twitter:title" content="' . htmlspecialchars($title) . '" />' . "\n";
    if ($description) $tags .= '<meta property="twitter:description" content="' . htmlspecialchars($description) . '" />' . "\n";
    if ($url) $tags .= '<meta property="twitter:url" content="' . htmlspecialchars($url) . '" />' . "\n";
    if ($image) $tags .= '<meta property="twitter:image" content="' . htmlspecialchars($image) . '" />' . "\n";

    return ['tags' => $tags, 'preview_title' => $title, 'preview_desc' => $description];
}

function CloudHost247_tool_raid_calculator($post)
{
    $drives = (int) ($post['drives'] ?? 2);
    $size = (float) ($post['size'] ?? 1000); // GB
    $raid = (int) ($post['raid'] ?? 1);

    $usable = 0;
    $faultTolerance = 0;

    switch ($raid) {
        case 0:
            $usable = $drives * $size;
            $faultTolerance = 0;
            break;
        case 1:
            $usable = $size;
            $faultTolerance = $drives - 1;
            break;
        case 5:
            $usable = ($drives - 1) * $size;
            $faultTolerance = 1;
            break;
        case 6:
            $usable = ($drives - 2) * $size;
            $faultTolerance = 2;
            break;
        case 10:
            $usable = ($drives / 2) * $size;
            $faultTolerance = $drives / 2;
            break;
        default:
            return ['error' => 'Unsupported RAID level'];
    }

    return [
        'drives' => $drives,
        'drive_size_gb' => $size,
        'raid_level' => $raid,
        'usable_capacity_gb' => round($usable, 2),
        'fault_tolerance' => $faultTolerance,
        'efficiency' => round(($usable / ($drives * $size)) * 100, 1),
    ];
}

function CloudHost247_tool_binary_text($post)
{
    $input = $_POST['input'] ?? '';
    $mode = CloudHost247_tools_sanitize($post['mode'] ?? 'text_to_binary', 'string');

    if (empty($input)) {
        return ['error' => 'Please enter input'];
    }

    if ($mode === 'text_to_binary') {
        $binary = '';
        for ($i = 0; $i < strlen($input); $i++) {
            $binary .= str_pad(decbin(ord($input[$i])), 8, '0', STR_PAD_LEFT) . ' ';
        }
        return ['input' => $input, 'output' => trim($binary), 'mode' => 'Text to Binary'];
    } elseif ($mode === 'binary_to_text') {
        $bytes = explode(' ', trim($input));
        $text = '';
        foreach ($bytes as $byte) {
            if (preg_match('/^[01]{1,8}$/', $byte)) {
                $text .= chr(bindec($byte));
            }
        }
        return ['input' => $input, 'output' => $text, 'mode' => 'Binary to Text'];
    } elseif ($mode === 'text_to_hex') {
        return ['input' => $input, 'output' => bin2hex($input), 'mode' => 'Text to Hex'];
    } elseif ($mode === 'hex_to_text') {
        $text = @hex2bin(preg_replace('/[^a-fA-F0-9]/', '', $input));
        if ($text === false) {
            return ['error' => 'Invalid hex string'];
        }
        return ['input' => $input, 'output' => $text, 'mode' => 'Hex to Text'];
    }

    return ['error' => 'Unknown conversion mode'];
}

function CloudHost247_tool_json_formatter($post)
{
    $input = $_POST['json'] ?? '';
    $mode = CloudHost247_tools_sanitize($post['mode'] ?? 'format', 'string');

    if (empty($input)) {
        return ['error' => 'Please enter JSON'];
    }

    $decoded = json_decode($input);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
    }

    if ($mode === 'format') {
        return ['formatted' => json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'valid' => true];
    } elseif ($mode === 'minify') {
        return ['formatted' => json_encode($decoded), 'valid' => true];
    } elseif ($mode === 'tree') {
        return ['tree' => print_r($decoded, true), 'valid' => true];
    }

    return ['formatted' => json_encode($decoded, JSON_PRETTY_PRINT), 'valid' => true];
}

// ---------------------------------------------------------------------
//  Text / binary conversion
// ---------------------------------------------------------------------

/**
 * Text to Binary (and back) - UTF-8 safe, with several bases.
 */
function CloudHost247_tool_text_to_binary($post)
{
    $mode      = strtolower(trim((string) ($post['mode'] ?? 'encode')));
    $base      = strtolower(trim((string) ($post['base'] ?? 'binary')));
    $separator = (string) ($post['separator'] ?? ' ');
    $text      = (string) ($post['text'] ?? '');

    if ($text === '') {
        return ['error' => 'Please enter some text to convert.'];
    }
    if (strlen($text) > 100000) {
        return ['error' => 'Input is too long (limit 100,000 characters).'];
    }

    $bases = [
        'binary'      => ['base' => 2,  'pad' => 8],
        'octal'       => ['base' => 8,  'pad' => 3],
        'decimal'     => ['base' => 10, 'pad' => 0],
        'hexadecimal' => ['base' => 16, 'pad' => 2],
    ];
    if (!isset($bases[$base])) {
        return ['error' => 'Unsupported base. Choose binary, octal, decimal or hexadecimal.'];
    }
    $cfg = $bases[$base];

    if ($mode === 'decode') {
        $tokens = preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            return ['error' => 'No values found to decode.'];
        }
        $bytes = '';
        foreach ($tokens as $i => $token) {
            $token = ltrim($token, '0x0b0o');
            if ($token === '') {
                $token = '0';
            }
            $valid = [
                2  => '/^[01]+$/', 8 => '/^[0-7]+$/',
                10 => '/^[0-9]+$/', 16 => '/^[0-9a-fA-F]+$/',
            ][$cfg['base']];
            if (!preg_match($valid, $token)) {
                return ['error' => 'Value "' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
                    . '" at position ' . ($i + 1) . ' is not valid ' . $base . '.'];
            }
            $value = intval($token, $cfg['base']);
            if ($value < 0 || $value > 255) {
                return ['error' => 'Value "' . $token . '" is outside the byte range 0-255.'];
            }
            $bytes .= chr($value);
        }
        $decoded = $bytes;
        $isUtf8  = mb_check_encoding($decoded, 'UTF-8');

        return [
            'mode'      => 'decode',
            'base'      => $base,
            'result'    => $isUtf8 ? $decoded : bin2hex($decoded),
            'valid_utf8'=> $isUtf8,
            'bytes'     => strlen($decoded),
            'note'      => $isUtf8
                ? 'Decoded ' . strlen($decoded) . ' bytes of valid UTF-8 text.'
                : 'The decoded bytes are not valid UTF-8, so the raw hex is shown instead.',
        ];
    }

    // Encode
    $out   = [];
    $bytes = str_split($text);
    foreach ($bytes as $byte) {
        $value = ord($byte);
        switch ($cfg['base']) {
            case 2:  $token = str_pad(decbin($value), 8, '0', STR_PAD_LEFT); break;
            case 8:  $token = str_pad(decoct($value), 3, '0', STR_PAD_LEFT); break;
            case 16: $token = strtoupper(str_pad(dechex($value), 2, '0', STR_PAD_LEFT)); break;
            default: $token = (string) $value;
        }
        $out[] = $token;
    }

    // Per-character breakdown (multibyte aware) for the results table.
    $breakdown = [];
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (array_slice($chars, 0, 200) as $char) {
        $cb = [];
        foreach (str_split($char) as $b) {
            $cb[] = str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $breakdown[] = [
            'char'      => $char,
            'codepoint' => 'U+' . strtoupper(str_pad(dechex(mb_ord($char, 'UTF-8')), 4, '0', STR_PAD_LEFT)),
            'bytes'     => strlen($char),
            'binary'    => implode(' ', $cb),
        ];
    }

    return [
        'mode'       => 'encode',
        'base'       => $base,
        'result'     => implode($separator === '' ? '' : $separator, $out),
        'characters' => count($chars),
        'bytes'      => strlen($text),
        'breakdown'  => $breakdown,
        'truncated'  => count($chars) > 200,
        'note'       => 'Text is encoded as UTF-8 first, so characters outside ASCII correctly produce multiple bytes.',
    ];
}

// ---------------------------------------------------------------------
//  Email verification
// ---------------------------------------------------------------------

/**
 * Email Verifier - syntax, domain, MX and deliverability signals.
 *
 * CloudHost247 deliberately does NOT perform an SMTP RCPT TO probe: most
 * providers answer with a catch-all accept, so the result would be
 * misleading, and the practice gets mail servers blacklisted. Every signal
 * reported here is actually measured.
 */
function CloudHost247_tool_email_verifier($post)
{
    $email = trim((string) ($post['email'] ?? ''));
    if ($email === '') {
        return ['error' => 'Please enter an email address.'];
    }
    if (strlen($email) > 254) {
        return ['error' => 'Email addresses cannot exceed 254 characters (RFC 5321).'];
    }

    $checks = [];
    $add = function ($name, $status, $detail) use (&$checks) {
        $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    };

    // 1. Syntax
    $syntaxOk = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    $add('Syntax (RFC 5322)', $syntaxOk ? 'pass' : 'fail',
        $syntaxOk ? 'The address is syntactically valid.' : 'The address is not syntactically valid.');
    if (!$syntaxOk) {
        return [
            'email'   => $email,
            'verdict' => 'Invalid',
            'score'   => 0,
            'checks'  => $checks,
            'note'    => 'No further checks were run because the syntax is invalid.',
        ];
    }

    $atPos  = strrpos($email, '@');
    $local  = substr($email, 0, $atPos);
    $domain = strtolower(substr($email, $atPos + 1));

    // 2. Local part length
    $localOk = strlen($local) <= 64 && $local !== '';
    $add('Local part length', $localOk ? 'pass' : 'fail',
        'Local part is ' . strlen($local) . ' characters (limit 64).');

    // 3. Domain resolves
    $a    = @dns_get_record($domain, DNS_A) ?: [];
    $aaaa = @dns_get_record($domain, DNS_AAAA) ?: [];
    $domainOk = (bool) ($a || $aaaa);
    $add('Domain resolves', $domainOk ? 'pass' : 'fail',
        $domainOk ? 'The domain has address records.' : 'The domain does not resolve to any IP address.');

    // 4. MX records
    $mx = @dns_get_record($domain, DNS_MX) ?: [];
    usort($mx, function ($x, $y) {
        return ($x['pri'] ?? 0) <=> ($y['pri'] ?? 0);
    });
    $mxHosts = [];
    foreach ($mx as $record) {
        if (!empty($record['target'])) {
            $mxHosts[] = ['host' => $record['target'], 'priority' => (int) ($record['pri'] ?? 0)];
        }
    }
    $add('MX records', $mxHosts ? 'pass' : ($domainOk ? 'warn' : 'fail'),
        $mxHosts
            ? 'Found ' . count($mxHosts) . ' mail exchanger(s); highest priority is ' . $mxHosts[0]['host'] . '.'
            : ($domainOk
                ? 'No MX record. Mail may still be delivered to the A record, but most senders treat this as undeliverable.'
                : 'No MX record and the domain does not resolve.'));

    // 5. Role-based address
    $roles = ['admin', 'administrator', 'postmaster', 'hostmaster', 'webmaster', 'abuse',
        'info', 'support', 'sales', 'contact', 'help', 'noreply', 'no-reply', 'donotreply',
        'billing', 'marketing', 'office', 'team', 'hello', 'enquiries', 'inquiries', 'security'];
    $isRole = in_array(strtolower($local), $roles, true);
    $add('Role-based address', $isRole ? 'warn' : 'pass',
        $isRole
            ? 'This is a shared role address, not an individual mailbox. Marketing lists usually exclude these.'
            : 'This looks like an individual mailbox rather than a shared role address.');

    // 6. Disposable provider
    $disposable = ['mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com',
        'temp-mail.org', 'throwawaymail.com', 'yopmail.com', 'trashmail.com', 'getnada.com',
        'sharklasers.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com', 'mintemail.com',
        'mailnesia.com', 'tempinbox.com', 'spamgourmet.com', 'tempr.email', 'emailondeck.com',
        'moakt.com', 'mohmal.com', 'burnermail.io', 'anonaddy.me', 'tuta.io'];
    $isDisposable = in_array($domain, $disposable, true);
    $add('Disposable provider', $isDisposable ? 'fail' : 'pass',
        $isDisposable
            ? 'This domain is a known disposable/temporary mail provider.'
            : 'The domain is not on the known disposable-provider list. The list cannot be exhaustive.');

    // 7. Free provider
    $free = ['gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'hotmail.com', 'outlook.com',
        'live.com', 'msn.com', 'aol.com', 'icloud.com', 'me.com', 'mail.com', 'gmx.com', 'gmx.net',
        'yandex.com', 'yandex.ru', 'zoho.com', 'protonmail.com', 'proton.me', 'tutanota.com'];
    $isFree = in_array($domain, $free, true);
    $add('Provider type', 'info',
        $isFree ? 'Free consumer mailbox provider.' : 'Custom or business domain.');

    // 8. SPF
    $txt = @dns_get_record($domain, DNS_TXT) ?: [];
    $spf = null;
    foreach ($txt as $record) {
        $value = $record['txt'] ?? (isset($record['entries']) ? implode('', $record['entries']) : '');
        if (stripos($value, 'v=spf1') === 0) {
            $spf = $value;
            break;
        }
    }
    $add('SPF record', $spf ? 'pass' : 'warn',
        $spf ? 'SPF policy published: ' . $spf : 'No SPF record found on the domain.');

    // 9. DMARC
    $dmarcRecords = @dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [];
    $dmarc = null;
    foreach ($dmarcRecords as $record) {
        $value = $record['txt'] ?? (isset($record['entries']) ? implode('', $record['entries']) : '');
        if (stripos($value, 'v=DMARC1') === 0) {
            $dmarc = $value;
            break;
        }
    }
    $add('DMARC record', $dmarc ? 'pass' : 'warn',
        $dmarc ? 'DMARC policy published: ' . $dmarc : 'No DMARC record found on the domain.');

    // 10. Gmail dot/plus normalisation - useful for dedupe.
    $normalised = $email;
    if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
        $base = explode('+', $local)[0];
        $normalised = str_replace('.', '', $base) . '@gmail.com';
    } elseif (strpos($local, '+') !== false) {
        $normalised = explode('+', $local)[0] . '@' . $domain;
    }

    $passCount = count(array_filter($checks, function ($c) { return $c['status'] === 'pass'; }));
    $failCount = count(array_filter($checks, function ($c) { return $c['status'] === 'fail'; }));
    $scored    = count(array_filter($checks, function ($c) { return $c['status'] !== 'info'; }));
    $score     = $scored ? (int) round(($passCount / $scored) * 100) : 0;

    if ($failCount > 0) {
        $verdict = $isDisposable ? 'Disposable - do not use' : 'Undeliverable';
    } elseif (!$mxHosts) {
        $verdict = 'Risky';
    } elseif ($isRole) {
        $verdict = 'Valid (role address)';
    } else {
        $verdict = 'Valid';
    }

    return [
        'email'       => $email,
        'local_part'  => $local,
        'domain'      => $domain,
        'normalised'  => $normalised,
        'verdict'     => $verdict,
        'score'       => $score,
        'mx'          => $mxHosts,
        'is_role'     => $isRole,
        'is_free'     => $isFree,
        'is_disposable' => $isDisposable,
        'checks'      => $checks,
        'note'        => 'CloudHost247 does not perform an SMTP mailbox probe. Most mail servers accept any recipient at the RCPT stage (catch-all), so a probe would produce a false "valid" result, and repeated probing gets the probing server blacklisted. These checks confirm the address is well-formed and the domain is genuinely able to receive mail.',
    ];
}
