<?php
/**
 * CloudHost247 Tools - Core Helper Functions
 */

if (!defined("WHMCS") && !defined("CLOUDHOST247_TOOLS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;

require_once __DIR__ . '/Security.php';

/**
 * Sanitize user input
 */
function CloudHost247_tools_sanitize($input, $type = 'string')
{
    switch ($type) {
        case 'domain':
            // Normalize only a DNS-equivalent final root dot and ASCII case;
            // leave all other characters intact so validation rejects bad
            // input instead of silently querying a different domain.
            if (!is_string($input) && !is_numeric($input)) {
                return '';
            }
            $input = (string) $input;
            if (substr($input, -1) === '.' && substr($input, -2) !== '..') {
                $input = substr($input, 0, -1);
            }
            return strtolower($input);
        case 'ip':
        case 'email':
            // These values have strict validators at their call sites. Keep the
            // original bytes so invalid punctuation cannot be removed into a
            // different, valid address or identifier.
            if (!is_string($input) && !is_numeric($input)) {
                return '';
            }
            return (string) $input;
        case 'url':
            $input = trim($input);
            if (!preg_match('/^https?:\/\//i', $input)) {
                $input = 'https://' . $input;
            }
            return filter_var($input, FILTER_SANITIZE_URL);
        case 'int':
            return (int) $input;
        case 'json':
            return json_encode($input);
        case 'html':
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        case 'string':
        default:
            return htmlspecialchars(trim(strip_tags($input)), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Validate domain name
 */
function CloudHost247_tools_validate_domain($domain)
{
    if (!is_string($domain) || $domain === '' || strlen($domain) > 253 || trim($domain) !== $domain) {
        return false;
    }
    $labels = explode('.', $domain);
    if (count($labels) < 2) {
        return false;
    }
    foreach ($labels as $label) {
        if ($label === '' || strlen($label) > 63
            || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/iD', $label)) {
            return false;
        }
    }
    $tld = end($labels);
    if (strlen($tld) < 2
        || !preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59}[a-z0-9])$/iD', $tld)) {
        return false;
    }
    try {
        CloudHost247ToolsSecurity::validateDomain($domain);
    } catch (CloudHost247ToolsSecurityException $e) {
        return false;
    }
    return true;
}

/**
 * Validate IPv4 address
 */
function CloudHost247_tools_validate_ipv4($ip)
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

/**
 * Validate IPv6 address
 */
function CloudHost247_tools_validate_ipv6($ip)
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

/**
 * Validate IP address (v4 or v6)
 */
function CloudHost247_tools_validate_ip($ip)
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

/**
 * Validate URL
 */
function CloudHost247_tools_validate_url($url)
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Validate email
 */
function CloudHost247_tools_validate_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate CSRF token
 */
function CloudHost247_tools_generate_csrf()
{
    if (empty($_SESSION['CloudHost247_tools_csrf'])) {
        $_SESSION['CloudHost247_tools_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['CloudHost247_tools_csrf'];
}

/**
 * Verify CSRF token
 */
function CloudHost247_tools_verify_csrf($token)
{
    return isset($_SESSION['CloudHost247_tools_csrf']) && hash_equals($_SESSION['CloudHost247_tools_csrf'], $token);
}

/**
 * Check rate limit
 */
function CloudHost247_tools_check_rate_limit($ip, $maxRequests = 60)
{
    $window = 60; // 1 minute window
    $now = date('Y-m-d H:i:s');

    // Clean old entries
    Capsule::table('mod_CloudHost247_tools_rate_limit')
        ->where('window_start', '<', date('Y-m-d H:i:s', strtotime("-$window seconds")))
        ->delete();

    $record = Capsule::table('mod_CloudHost247_tools_rate_limit')
        ->where('ip_address', $ip)
        ->where('window_start', '>=', date('Y-m-d H:i:s', strtotime("-$window seconds")))
        ->first();

    if ($record) {
        if ($record->request_count >= $maxRequests) {
            return false;
        }
        Capsule::table('mod_CloudHost247_tools_rate_limit')
            ->where('id', $record->id)
            ->update([
                'request_count' => $record->request_count + 1,
            ]);
    } else {
        Capsule::table('mod_CloudHost247_tools_rate_limit')->insert([
            'ip_address' => $ip,
            'request_count' => 1,
            'window_start' => $now,
        ]);
    }

    return true;
}

/**
 * Get module setting
 */
function CloudHost247_tools_get_setting($key, $default = '')
{
    $setting = Capsule::table('tbladdonmodules')
        ->where('module', 'CloudHost247_tools')
        ->where('setting', $key)
        ->value('value');

    return $setting !== null ? $setting : $default;
}

/**
 * Log tool usage
 */
function CloudHost247_tools_log($toolId, $input, $result, $status = 'success', $error = '')
{
    $enableLogs = CloudHost247_tools_get_setting('enable_logs', 'on');
    if ($enableLogs !== 'on') return;

    $userId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // This legacy helper has no current call sites, but keep it safe for
    // extensions that may still call it: never persist opaque free-form input
    // or tool results, and redact keyed values when the shared Runner exists.
    if (is_array($input) && class_exists('CloudHost247ToolsRunner')) {
        $safeInput = CloudHost247ToolsRunner::redact($input);
    } elseif (is_array($input)) {
        $safeInput = array_fill_keys(array_map('strval', array_keys($input)), '[redacted]');
    } else {
        $safeInput = '[redacted]';
    }
    $encodedInput = json_encode($safeInput, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    Capsule::table('mod_CloudHost247_tools_logs')->insert([
        'tool_id' => substr((string) $toolId, 0, 100),
        'input' => substr($encodedInput === false ? '{}' : $encodedInput, 0, 500),
        'ip_address' => substr((string) $ip, 0, 50),
        'user_id' => $userId,
        'result' => null,
        'status' => substr((string) $status, 0, 20),
        'error_message' => substr((string) $error, 0, 500),
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    // Increment usage count
    Capsule::table('mod_CloudHost247_tools_status')
        ->where('tool_id', $toolId)
        ->increment('usage_count');
}

/**
 * JSON response helper
 */
function CloudHost247_tools_json_response($data, $success = true, $message = '')
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

/**
 * Deprecated compatibility shim. Never run caller-supplied shell commands.
 * Diagnostics must use the allowlisted CloudHost247ToolsSecurity::runCommand()
 * interface so user input cannot select a binary or inject shell syntax.
 */
function CloudHost247_tools_safe_exec($command, $timeout = 10)
{
    return null;
}

/**
 * cURL helper
 */
function CloudHost247_tools_curl($url, $postData = null, $headers = [], $timeout = 30)
{
    // Keep the legacy return shape, but route every visitor-influenced URL
    // through the shared SSRF guard (public DNS validation, pinned address,
    // verified TLS, re-validated redirects and a hard response-size limit).
    if (!class_exists('CloudHost247ToolsSecurity')) {
        require_once __DIR__ . '/Security.php';
    }

    $options = [
        'method' => $postData === null ? 'GET' : 'POST',
        'headers' => is_array($headers) ? $headers : [],
        'timeout' => $timeout,
    ];
    if ($postData !== null) {
        $options['body'] = $postData;
    }

    try {
        $response = CloudHost247ToolsSecurity::fetch($url, $options);

        return [
            'error' => '',
            'code' => $response['status'],
            'body' => $response['body'],
            'headers' => $response['headers'],
            'url' => $response['url'],
            'redirects' => $response['redirects'],
        ];
    } catch (CloudHost247ToolsSecurityException $e) {
        return ['error' => $e->getMessage(), 'code' => 0, 'body' => ''];
    }
}

/**
 * Cache helper - get
 */
function CloudHost247_tools_cache_get($key)
{
    $cache = Capsule::table('mod_CloudHost247_tools_cache')
        ->where('cache_key', $key)
        ->where('expires_at', '>', date('Y-m-d H:i:s'))
        ->first();

    if ($cache) {
        return json_decode($cache->cache_value, true);
    }
    return null;
}

/**
 * Cache helper - set
 */
function CloudHost247_tools_cache_set($key, $value, $minutes = null)
{
    if ($minutes === null) {
        $minutes = (int) CloudHost247_tools_get_setting('cache_duration', '10');
    }

    $expires = date('Y-m-d H:i:s', strtotime("+$minutes minutes"));

    Capsule::table('mod_CloudHost247_tools_cache')
        ->updateOrInsert(
            ['cache_key' => $key],
            [
                'cache_value' => json_encode($value),
                'expires_at' => $expires,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
}

/**
 * Get client IP
 */
function CloudHost247_tools_get_client_ip()
{
    // Same rule as CloudHost247ToolsSecurity::clientIp(): the connection address
    // is authoritative. Forwarded headers are read only when REMOTE_ADDR is a
    // configured trusted proxy (CLOUDHOST247_TRUSTED_PROXIES, comma-separated).
    // Trusting client-supplied headers let a caller pick a new IP per request
    // and bypass the per-IP rate limit.
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $trusted = getenv('CLOUDHOST247_TRUSTED_PROXIES');
    if ($trusted && filter_var($remote, FILTER_VALIDATE_IP)
        && in_array($remote, array_map('trim', explode(',', $trusted)), true)) {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

/**
 * Get enabled tools
 */
function CloudHost247_tools_get_enabled_tools()
{
    $allTools = CloudHost247_tools_get_all_tools();
    $enabled = Capsule::table('mod_CloudHost247_tools_status')
        ->where('enabled', 1)
        ->pluck('tool_id')
        ->toArray();

    $result = [];
    foreach ($allTools as $category => $tools) {
        foreach ($tools as $toolId => $toolData) {
            if (in_array($toolId, $enabled)) {
                $result[$category][$toolId] = $toolData;
            }
        }
    }
    return $result;
}

/**
 * Get tool info
 */
function CloudHost247_tools_get_tool_info($toolId)
{
    $allTools = CloudHost247_tools_get_all_tools();
    foreach ($allTools as $category => $tools) {
        if (isset($tools[$toolId])) {
            return array_merge($tools[$toolId], ['id' => $toolId, 'category' => $category]);
        }
    }
    return null;
}

/**
 * Check if tool is enabled
 */
function CloudHost247_tools_is_tool_enabled($toolId)
{
    $status = Capsule::table('mod_CloudHost247_tools_status')
        ->where('tool_id', $toolId)
        ->value('enabled');
    return (int) $status === 1;
}

/**
 * Pretty print JSON
 */
function CloudHost247_tools_pretty_json($json)
{
    $decoded = json_decode($json);
    if (json_last_error() === JSON_ERROR_NONE) {
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    return $json;
}

/**
 * Convert bytes to human readable
 */
function CloudHost247_tools_bytes_to_human($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unitIndex = 0;
    while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
        $bytes /= 1024;
        $unitIndex++;
    }
    return round($bytes, 2) . ' ' . $units[$unitIndex];
}

/**
 * Parse the portable packet summary and per-echo details from system ping.
 * Returns null for unrecognised output instead of inventing measurements.
 */
function CloudHost247_tools_parse_ping_output($output, $requestedCount)
{
    $output = (string) $output;
    $requestedCount = max(1, (int) $requestedCount);
    $summaryPattern = '/(\d+)\s+packets transmitted,\s*(\d+)\s+(?:packets\s+)?received(?:,\s*([0-9]+(?:\.[0-9]+)?)%\s*packet loss)?/i';
    if (!preg_match($summaryPattern, $output, $summary)) {
        return null;
    }

    $sent = (int) $summary[1];
    $received = (int) $summary[2];
    if ($sent < 1 || $sent > $requestedCount || $received > $sent) {
        return null;
    }

    $computedLoss = (($sent - $received) / $sent) * 100;
    $loss = isset($summary[3]) && $summary[3] !== ''
        ? (float) $summary[3]
        : round($computedLoss, 1);
    if ($loss < 0 || $loss > 100) {
        return null;
    }
    if (isset($summary[3]) && $summary[3] !== ''
        && min(abs($loss - round($computedLoss)), abs($loss - round($computedLoss, 1))) > 0.11) {
        return null;
    }

    $replies = [];
    if (preg_match_all('/icmp_seq=(\d+).*?\btime(=|<)\s*([0-9]+(?:\.[0-9]+)?)\s*ms/i', $output, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $seq = (int) $match[1];
            if ($seq < 1 || $seq > $sent) {
                continue;
            }
            $lessThan = $match[2] === '<';
            $replies[$seq] = [
                'time' => $lessThan ? null : (float) $match[3],
                'time_display' => ($lessThan ? '<' : '') . $match[3] . ' ms',
            ];
        }
    }

    $detailsAvailable = count($replies) === $received;
    $results = [];
    $times = [];
    if ($detailsAvailable) {
        for ($seq = 1; $seq <= $sent; $seq++) {
            if (isset($replies[$seq])) {
                $reply = $replies[$seq];
                $results[] = [
                    'seq' => $seq,
                    'status' => 'success',
                    'time' => $reply['time'],
                    'time_display' => $reply['time_display'],
                ];
                if ($reply['time'] !== null) {
                    $times[] = $reply['time'];
                }
            } else {
                $results[] = ['seq' => $seq, 'status' => 'timeout', 'time' => null, 'time_display' => 'No reply'];
            }
        }
    }

    return [
        'packets_sent' => $sent,
        'packets_received' => $received,
        'packet_loss' => $loss,
        'min_time' => $times ? min($times) : null,
        'max_time' => $times ? max($times) : null,
        'avg_time' => $times ? round(array_sum($times) / count($times), 2) : null,
        'results' => $results,
        'reply_details_available' => $detailsAvailable,
        'reply_details_note' => $detailsAvailable ? null : 'Per-reply timing could not be parsed; only the packet summary is available.',
    ];
}

/**
 * ICMP echo helper using the existing allowlisted system ping command.
 */
function CloudHost247_tools_ping($host, $ipv6 = false, $count = 4, $timeout = 3, $commandRunner = null)
{
    if ($commandRunner !== null && !is_callable($commandRunner)) {
        return ['error' => 'The ping command adapter is invalid.'];
    }
    try {
        $target = CloudHost247ToolsSecurity::assertPublicHost($host);
    } catch (CloudHost247ToolsSecurityException $e) {
        return ['error' => $e->getMessage()];
    }

    $count = max(1, min(4, (int) $count));
    $timeout = max(1, min(3, (int) $timeout));
    $wantedVersion = $ipv6 ? 6 : 4;
    $addresses = array_values(array_filter($target['ips'], function ($address) use ($wantedVersion) {
        return $wantedVersion === 6
            ? CloudHost247ToolsSecurity::isIpv6($address)
            : CloudHost247ToolsSecurity::isIpv4($address);
    }));
    if (!$addresses) {
        return ['error' => 'The host does not resolve to a public IPv' . $wantedVersion . ' address.'];
    }

    $ip = $addresses[0];
    $args = ['-n'];
    if ($ipv6) {
        $args[] = '-6';
    }
    $args[] = '-c';
    $args[] = (string) $count;
    $args[] = '-W';
    $args[] = (string) $timeout;
    $args[] = $ip;
    $commandTimeout = min(20, max(3, ($count * ($timeout + 1)) + 2));

    $probe = $commandRunner !== null
        ? call_user_func($commandRunner, 'ping', $args, $commandTimeout)
        : CloudHost247ToolsSecurity::runCommand('ping', $args, $commandTimeout);
    if (!is_array($probe) || empty($probe['available'])) {
        return ['error' => 'The ICMP ping utility is unavailable on this server.'];
    }

    $parsed = CloudHost247_tools_parse_ping_output($probe['output'] ?? '', $count);
    if ($parsed === null) {
        return ['error' => 'The ping command returned no recognizable summary; no ping result is reported.'];
    }

    return array_merge([
        'host' => $target['host'],
        'ip' => $ip,
        'measurement' => $ipv6 ? 'ICMPv6 echo using the system ping utility.' : 'ICMPv4 echo using the system ping utility.',
    ], $parsed);
}

/**
 * Parse numbered hop lines from a numeric system traceroute response.
 * Private and reserved intermediate hops are deliberately redacted.
 */
function CloudHost247_tools_parse_traceroute_output($output, $maxHops, $destination)
{
    $maxHops = max(1, (int) $maxHops);
    $hops = [];
    $reached = false;
    foreach (preg_split('/\r?\n/', (string) $output) as $line) {
        if (!preg_match('/^\s*(\d+)\s+(.*)$/', $line, $match)) {
            continue;
        }
        $hopNumber = (int) $match[1];
        if ($hopNumber < 1 || $hopNumber > $maxHops) {
            continue;
        }

        $lineBody = trim($match[2]);
        $ip = null;
        $redacted = false;
        foreach (preg_split('/\s+/', $lineBody) as $token) {
            $candidate = trim($token, '()[],');
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (CloudHost247ToolsSecurity::blockedIpReason($candidate) !== false) {
                $redacted = true;
            } else {
                $ip = $candidate;
            }
            break;
        }

        $time = null;
        $timeDisplay = null;
        if (preg_match('/(?:^|\s)(<\s*)?([0-9]+(?:\.[0-9]+)?)\s*ms\b/i', $lineBody, $timeMatch)) {
            $time = !empty($timeMatch[1]) ? null : (float) $timeMatch[2];
            $timeDisplay = (!empty($timeMatch[1]) ? '<' : '') . $timeMatch[2] . ' ms';
        }

        if ($redacted) {
            $status = 'redacted';
            $time = null;
            $timeDisplay = null;
        } elseif ($ip !== null) {
            $status = 'success';
            if ($ip === $destination) {
                $reached = true;
            }
        } elseif (preg_match('/(?:^|\s)\*(?:\s|$)/', $lineBody)) {
            $status = 'timeout';
        } else {
            $status = 'unresolved';
        }

        $hops[] = [
            'hop' => $hopNumber,
            'ip' => $ip,
            'hostname' => null,
            'time_ms' => $time,
            'time_display' => $timeDisplay,
            'status' => $status,
        ];
    }

    if (!$hops) {
        return null;
    }
    usort($hops, function ($a, $b) { return $a['hop'] - $b['hop']; });
    return ['hops' => $hops, 'reached_destination' => $reached];
}

/**
 * Traceroute helper using the existing allowlisted system traceroute command.
 */
function CloudHost247_tools_traceroute($host, $maxHops = 30, $timeout = 5, $commandRunner = null)
{
    if ($commandRunner !== null && !is_callable($commandRunner)) {
        return ['error' => 'The traceroute command adapter is invalid.'];
    }
    try {
        $target = CloudHost247ToolsSecurity::assertPublicHost($host);
    } catch (CloudHost247ToolsSecurityException $e) {
        return ['error' => $e->getMessage()];
    }

    $ipv4 = array_values(array_filter($target['ips'], function ($address) {
        return CloudHost247ToolsSecurity::isIpv4($address);
    }));
    if (!$ipv4) {
        return ['error' => 'This traceroute tool supports public IPv4 destinations only.'];
    }

    $ip = $ipv4[0];
    $maxHops = max(1, min(20, (int) $maxHops));
    // One probe per hop and a bounded wait keep this within the Runner budget.
    $args = ['-n', '-m', (string) $maxHops, '-q', '1', '-w', '1', $ip];
    $commandTimeout = min(24, max(5, $maxHops + 4));
    $probe = $commandRunner !== null
        ? call_user_func($commandRunner, 'traceroute', $args, $commandTimeout)
        : CloudHost247ToolsSecurity::runCommand('traceroute', $args, $commandTimeout);
    if (!is_array($probe) || empty($probe['available'])) {
        return ['error' => 'The system traceroute utility is unavailable on this server.'];
    }

    $parsed = CloudHost247_tools_parse_traceroute_output($probe['output'] ?? '', $maxHops, $ip);
    if ($parsed === null) {
        return ['error' => 'The traceroute command returned no recognizable hop output; no route result is reported.'];
    }

    return [
        'host' => $target['host'],
        'ip' => $ip,
        'hops' => $parsed['hops'],
        'reached_destination' => $parsed['reached_destination'],
        'measurement' => 'UDP-based system traceroute with one probe per hop; private and reserved hop addresses are redacted.',
    ];
}

/**
 * Get DNS record with cache
 */
function CloudHost247_tools_dns_query($domain, $type = 'A')
{
    if (!is_string($domain) || CloudHost247ToolsSecurity::isBlockedDnsName($domain)) {
        return [];
    }

    $cacheKey = 'dns_' . md5($domain . $type);
    $cached = CloudHost247_tools_cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $records = @dns_get_record($domain, constant('DNS_' . strtoupper($type)));
    if ($records === false) {
        $records = [];
    }

    CloudHost247_tools_cache_set($cacheKey, $records);
    return $records;
}

/**
 * Classify WHOIS text conservatively; unrecognised or truncated responses stay unconfirmed.
 */
function CloudHost247_tools_classify_domain_whois(array $whois, $domain)
{
    $result = isset($whois['result']) && is_string($whois['result']) ? $whois['result'] : '';
    $server = isset($whois['server']) && is_string($whois['server']) ? $whois['server'] : 'Unknown';
    $status = 'unconfirmed';
    $note = 'WHOIS did not provide a clear matching record or no-match response. Availability is unconfirmed; check with a registrar.';

    if (isset($whois['error']) || $result === '' || !empty($whois['truncated'])) {
        return [
            'status' => $status,
            'whois_server' => $server,
            'note' => 'The WHOIS lookup was unavailable or incomplete. No availability result can be confirmed.',
        ];
    }

    $quotedDomain = preg_quote((string) $domain, '/');
    $noMatchPatterns = [
        '/^\s*No match for\s+["\']?' . $quotedDomain . '["\']?\s*\.?\s*$/im',
        '/^\s*NOT FOUND\s*$/im',
        '/^\s*No entries found(?:\s+for\s+.*)?\s*\.?\s*$/im',
        '/^\s*Domain Status:\s*free\b.*$/im',
    ];
    foreach ($noMatchPatterns as $pattern) {
        if (preg_match($pattern, $result)) {
            $status = 'no_match_reported';
            $note = 'The WHOIS server reported no matching record. This does not guarantee that the domain can be registered; confirm with an accredited registrar.';
            return ['status' => $status, 'whois_server' => $server, 'note' => $note];
        }
    }

    $recordPatterns = [
        '/^\s*Domain Name:\s*' . $quotedDomain . '\s*$/im',
        '/^\s*Domain:\s*' . $quotedDomain . '\s*$/im',
    ];
    foreach ($recordPatterns as $pattern) {
        if (preg_match($pattern, $result)) {
            $status = 'record_found';
            $note = 'The WHOIS response contains a matching domain record. This is not a registrar availability, status, or pricing guarantee.';
            break;
        }
    }

    return ['status' => $status, 'whois_server' => $server, 'note' => $note];
}

/**
 * Query only a vetted public WHOIS server on TCP/43, with bounded input/output.
 */
function CloudHost247_tools_whois($domain, $server = null)
{
    if (!is_string($domain) && !is_numeric($domain)) {
        return ['error' => 'Invalid WHOIS query.'];
    }
    $domain = trim((string) $domain);
    if ($domain === '' || strlen($domain) > 253 || preg_match('/[\r\n\x00\s]/', $domain)) {
        return ['error' => 'Invalid WHOIS query.'];
    }

    if (!CloudHost247ToolsSecurity::isIp($domain)) {
        $normalized = CloudHost247ToolsSecurity::normaliseHostname($domain);
        if ($normalized === false || $normalized !== strtolower(rtrim($domain, '.'))) {
            return ['error' => 'Enter a valid domain name or IP address for WHOIS.'];
        }
        $domain = $normalized;
    }

    $servers = [
        'com' => 'whois.verisign-grs.com',
        'net' => 'whois.verisign-grs.com',
        'org' => 'whois.pir.org',
        'info' => 'whois.afilias.info',
        'biz' => 'whois.biz',
        'io' => 'whois.nic.io',
        'co' => 'whois.nic.co',
        'uk' => 'whois.nic.uk',
        'de' => 'whois.denic.de',
        'fr' => 'whois.nic.fr',
    ];
    $allowedServers = array_values($servers);
    $allowedServers[] = 'whois.iana.org';
    $allowedServers[] = 'whois.arin.net';
    $allowedServers[] = 'whois.ripe.net';

    if ($server !== null && !is_string($server)) {
        return ['error' => 'WHOIS server is not on the built-in allowlist.'];
    }
    if ($server === null || $server === '') {
        $tld = substr(strrchr($domain, '.'), 1);
        $server = $servers[strtolower((string) $tld)] ?? 'whois.iana.org';
    }
    $server = strtolower(trim((string) $server));
    if (!in_array($server, $allowedServers, true)) {
        return ['error' => 'WHOIS server is not on the built-in allowlist.'];
    }
    if (!function_exists('stream_socket_client')) {
        return ['error' => 'WHOIS sockets are not available on this server.'];
    }

    try {
        $target = CloudHost247ToolsSecurity::publicConnectTarget($server, 43);
    } catch (CloudHost247ToolsSecurityException $e) {
        return ['error' => 'The configured WHOIS server could not be safely resolved.'];
    }

    $socket = false;
    foreach (array_slice($target['ips'], 0, 3) as $ip) {
        $socketHost = CloudHost247ToolsSecurity::isIpv6($ip) ? '[' . $ip . ']' : $ip;
        $socket = @stream_socket_client(
            'tcp://' . $socketHost . ':43',
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT
        );
        if (is_resource($socket)) {
            break;
        }
    }
    if (!is_resource($socket)) {
        return ['error' => 'Could not connect to the WHOIS server.'];
    }

    stream_set_timeout($socket, 10);
    if (@fwrite($socket, $domain . "\r\n") === false) {
        fclose($socket);
        return ['error' => 'Could not send the WHOIS query.'];
    }

    $response = '';
    $maxBytes = 262144;
    $truncated = false;
    while (!feof($socket) && strlen($response) < $maxBytes) {
        $chunk = @fread($socket, min(4096, $maxBytes - strlen($response)));
        if ($chunk === false) {
            $truncated = true;
            break;
        }
        if ($chunk === '') {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out']) || !feof($socket)) {
                $truncated = true;
            }
            break;
        }
        $response .= $chunk;
    }
    $meta = stream_get_meta_data($socket);
    $truncated = $truncated || !empty($meta['timed_out']) || strlen($response) >= $maxBytes;
    fclose($socket);

    return [
        'server' => $server,
        'result' => $response,
        'truncated' => $truncated,
    ];
}
