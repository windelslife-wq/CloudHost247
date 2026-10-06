<?php
/**
 * CloudHost247 Tools - IP Tools Implementation
 */

if (!defined("WHMCS") && !defined("CLOUDHOST247_TOOLS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/../functions.php';

function CloudHost247_tool_ping_ipv4($post)
{
    $host = CloudHost247_tools_sanitize($post['host'] ?? '', 'domain');
    if (empty($host)) {
        return ['error' => 'Please enter a host or IP address'];
    }
    return CloudHost247_tools_ping($host, false, 4, 3);
}

function CloudHost247_tool_ping_ipv6($post)
{
    $host = CloudHost247_tools_sanitize($post['host'] ?? '', 'domain');
    if (empty($host)) {
        return ['error' => 'Please enter a host or IP address'];
    }
    return CloudHost247_tools_ping($host, true, 4, 3);
}

function CloudHost247_tool_what_is_my_ip($post)
{
    $ip = CloudHost247_tools_get_client_ip();
    $hostname = gethostbyaddr($ip) ?: null;

    return [
        'ip' => $ip,
        'hostname' => $hostname,
        'is_ipv6' => CloudHost247_tools_validate_ipv6($ip),
    ];
}

function CloudHost247_tool_traceroute($post)
{
    $host = CloudHost247_tools_sanitize($post['host'] ?? '', 'domain');
    if (empty($host)) {
        return ['error' => 'Please enter a host or IP address'];
    }
    return CloudHost247_tools_traceroute($host, 30, 5);
}

function CloudHost247_tool_ip_location($post)
{
    $ip = CloudHost247_tools_sanitize($post['ip'] ?? '', 'ip');
    if (empty($ip)) {
        $ip = CloudHost247_tools_get_client_ip();
    }
    if (!CloudHost247_tools_validate_ip($ip)) {
        return ['error' => 'Invalid IP address'];
    }

    $cacheKey = 'geoip_' . md5($ip);
    $cached = CloudHost247_tools_cache_get($cacheKey);
    if ($cached !== null) {
        return array_merge($cached, ['cached' => true]);
    }

    // Try free ip-api.com first
    $result = CloudHost247_tools_curl("http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,mobile,proxy,hosting", null, [], 10);

    if ($result['code'] === 200) {
        $data = json_decode($result['body'], true);
        if ($data && $data['status'] === 'success') {
            $output = [
                'ip' => $ip,
                'country' => $data['country'] ?? '',
                'country_code' => $data['countryCode'] ?? '',
                'region' => $data['regionName'] ?? '',
                'city' => $data['city'] ?? '',
                'zip' => $data['zip'] ?? '',
                'latitude' => $data['lat'] ?? 0,
                'longitude' => $data['lon'] ?? 0,
                'timezone' => $data['timezone'] ?? '',
                'isp' => $data['isp'] ?? '',
                'organization' => $data['org'] ?? '',
                'asn' => $data['as'] ?? '',
                'mobile' => $data['mobile'] ?? false,
                'proxy' => $data['proxy'] ?? false,
                'hosting' => $data['hosting'] ?? false,
            ];
            CloudHost247_tools_cache_set($cacheKey, $output, 15);
            return $output;
        }
    }

    return ['error' => 'Could not retrieve location data'];
}

function CloudHost247_tool_email_header_analyzer($post)
{
    $header = $_POST['header'] ?? '';
    if (empty($header)) {
        return ['error' => 'Please paste an email header'];
    }

    $header = trim($header);
    $results = [];

    // Extract Received headers
    preg_match_all('/Received:\s*([^\n]+(?:\n\s+[^\n]+)*)/i', $header, $received);
    if ($received[1]) {
        $results['received'] = array_reverse(array_map('trim', $received[1]));
    }

    // Extract From
    if (preg_match('/From:\s*([^\n]+)/i', $header, $match)) {
        $results['from'] = trim($match[1]);
    }

    // Extract To
    if (preg_match('/To:\s*([^\n]+)/i', $header, $match)) {
        $results['to'] = trim($match[1]);
    }

    // Extract Subject
    if (preg_match('/Subject:\s*([^\n]+)/i', $header, $match)) {
        $results['subject'] = trim($match[1]);
    }

    // Extract Date
    if (preg_match('/Date:\s*([^\n]+)/i', $header, $match)) {
        $results['date'] = trim($match[1]);
    }

    // Extract Message-ID
    if (preg_match('/Message-ID:\s*<([^>]+)>/i', $header, $match)) {
        $results['message_id'] = trim($match[1]);
    }

    // Extract SPF/DKIM/DMARC results
    if (preg_match('/spf=([a-z]+)/i', $header, $match)) {
        $results['spf_result'] = $match[1];
    }
    if (preg_match('/dkim=([a-z]+)/i', $header, $match)) {
        $results['dkim_result'] = $match[1];
    }
    if (preg_match('/dmarc=([a-z]+)/i', $header, $match)) {
        $results['dmarc_result'] = $match[1];
    }

    // Check for suspicious patterns
    $warnings = [];
    if (stripos($header, 'X-Originating-IP') !== false) {
        $warnings[] = 'Contains originating IP (possible privacy concern)';
    }
    if (isset($results['spf_result']) && $results['spf_result'] !== 'pass') {
        $warnings[] = 'SPF check did not pass';
    }
    if (isset($results['dkim_result']) && $results['dkim_result'] !== 'pass') {
        $warnings[] = 'DKIM check did not pass';
    }

    return array_merge($results, ['warnings' => $warnings]);
}

function CloudHost247_tool_ip_blacklist($post)
{
    $ip = CloudHost247_tools_sanitize($post['ip'] ?? '', 'ip');
    if (!CloudHost247_tools_validate_ip($ip)) {
        return ['error' => 'Invalid IP address'];
    }

    $rbls = [
        'zen.spamhaus.org',
        'bl.spamcop.net',
        'b.barracudacentral.org',
        'dnsbl.sorbs.net',
        'spam.dnsbl.sorbs.net',
        'bl.spameatingmonkey.net',
        'dnsbl.justspam.org',
        'bl.mailspike.net',
        'dnsbl-1.uceprotect.net',
        'dnsbl-2.uceprotect.net',
        'dnsbl-3.uceprotect.net',
    ];

    $results = [];
    $listedCount = 0;

    foreach ($rbls as $rbl) {
        $reversed = implode('.', array_reverse(explode('.', $ip)));
        $lookup = $reversed . '.' . $rbl;
        $listed = gethostbyname($lookup) !== $lookup;
        if ($listed) $listedCount++;
        $results[] = ['rbl' => $rbl, 'listed' => $listed];
    }

    return [
        'ip' => $ip,
        'total_rbls' => count($rbls),
        'listed_count' => $listedCount,
        'clean' => $listedCount === 0,
        'results' => $results,
    ];
}

function CloudHost247_tool_ip_to_decimal($post)
{
    $ip = CloudHost247_tools_sanitize($post['ip'] ?? '', 'ip');
    if (!CloudHost247_tools_validate_ipv4($ip)) {
        return ['error' => 'Invalid IPv4 address'];
    }

    $parts = explode('.', $ip);
    $decimal = ($parts[0] * 16777216) + ($parts[1] * 65536) + ($parts[2] * 256) + $parts[3];
    $hex = strtoupper(dechex($decimal));
    $binary = str_pad(decbin($decimal), 32, '0', STR_PAD_LEFT);
    $octal = decoct($decimal);

    return [
        'ip' => $ip,
        'decimal' => $decimal,
        'hex' => $hex,
        'binary' => $binary,
        'octal' => $octal,
    ];
}

function CloudHost247_tool_ip_to_hostname($post)
{
    return CloudHost247_tool_reverse_ip_lookup($post);
}

function CloudHost247_tool_ip_whois($post)
{
    $ip = CloudHost247_tools_sanitize($post['ip'] ?? '', 'ip');
    if (!CloudHost247_tools_validate_ipv4($ip)) {
        return ['error' => 'Invalid IPv4 address'];
    }

    $result = CloudHost247_tools_whois($ip, 'whois.arin.net');
    return ['ip' => $ip, 'whois' => $result['result'] ?? '', 'server' => $result['server'] ?? ''];
}

function CloudHost247_tool_ipv6_whois($post)
{
    $ip = CloudHost247_tools_sanitize($post['ip'] ?? '', 'ip');
    if (!CloudHost247_tools_validate_ipv6($ip)) {
        return ['error' => 'Invalid IPv6 address'];
    }

    // Try whois.ripe.net for IPv6
    $result = CloudHost247_tools_whois($ip, 'whois.ripe.net');
    return ['ip' => $ip, 'whois' => $result['result'] ?? '', 'server' => $result['server'] ?? ''];
}

function CloudHost247_tool_ipv4_ipv6_converter($post)
{
    $input = CloudHost247_tools_sanitize($post['input'] ?? '', 'string');
    $direction = CloudHost247_tools_sanitize($post['direction'] ?? 'v4_to_v6', 'string');

    if ($direction === 'v4_to_v6') {
        if (!CloudHost247_tools_validate_ipv4($input)) {
            return ['error' => 'Invalid IPv4 address'];
        }
        $parts = array_map('intval', explode('.', $input));
        $hexParts = array_map(function ($p) {
            return str_pad(dechex($p), 2, '0', STR_PAD_LEFT);
        }, $parts);
        $ipv6 = '::ffff:' . implode('', array_slice($hexParts, 0, 2)) . ':' . implode('', array_slice($hexParts, 2, 2));
        return ['ipv4' => $input, 'ipv6' => $ipv6, 'direction' => 'IPv4 to IPv6 Mapped'];
    } else {
        if (!CloudHost247_tools_validate_ipv6($input)) {
            return ['error' => 'Invalid IPv6 address'];
        }
        // Try to extract IPv4 from IPv4-mapped IPv6
        if (preg_match('/::ffff:([a-f0-9]{1,4}):([a-f0-9]{1,4})/i', $input, $matches) ||
            preg_match('/::ffff:([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/i', $input, $matches)) {
            if (isset($matches[1]) && strpos($matches[1], '.') === false) {
                $p1 = hexdec($matches[1]);
                $p2 = hexdec($matches[2]);
                $ipv4 = (($p1 >> 8) & 0xFF) . '.' . ($p1 & 0xFF) . '.' . (($p2 >> 8) & 0xFF) . '.' . ($p2 & 0xFF);
            } else {
                $ipv4 = $matches[1];
            }
            return ['ipv6' => $input, 'ipv4' => $ipv4 ?? 'N/A', 'direction' => 'IPv6 to IPv4'];
        }
        return ['ipv6' => $input, 'ipv4' => 'Not an IPv4-mapped address', 'direction' => 'IPv6 to IPv4'];
    }
}

function CloudHost247_tool_ipv6_generator($post)
{
    $count = min((int) ($post['count'] ?? 5), 20);
    $addresses = [];
    for ($i = 0; $i < $count; $i++) {
        $parts = [];
        for ($j = 0; $j < 8; $j++) {
            $parts[] = str_pad(dechex(mt_rand(0, 65535)), 4, '0', STR_PAD_LEFT);
        }
        $addresses[] = implode(':', $parts);
    }
    return ['count' => $count, 'addresses' => $addresses];
}

function CloudHost247_tool_ipv6_cidr($post)
{
    $ipv6 = CloudHost247_tools_sanitize($post['ipv6'] ?? '', 'ip');
    $prefix = (int) ($post['prefix'] ?? 64);
    if (!CloudHost247_tools_validate_ipv6($ipv6)) {
        return ['error' => 'Invalid IPv6 address'];
    }
    if ($prefix < 1 || $prefix > 128) {
        return ['error' => 'Prefix must be between 1 and 128'];
    }

    // Simple calculation
    $totalAddresses = gmp_strval(gmp_pow(2, 128 - $prefix));

    return [
        'ipv6' => $ipv6,
        'prefix' => $prefix,
        'total_addresses' => $totalAddresses,
        'network_size' => CloudHost247_tools_bytes_to_human(gmp_intval(gmp_div(gmp_pow(2, 128 - $prefix), 1))),
    ];
}

function CloudHost247_tool_ipv6_compress($post)
{
    $ipv6 = CloudHost247_tools_sanitize($post['ipv6'] ?? '', 'ip');
    $mode = CloudHost247_tools_sanitize($post['mode'] ?? 'compress', 'string');

    if (!CloudHost247_tools_validate_ipv6($ipv6)) {
        return ['error' => 'Invalid IPv6 address'];
    }

    if ($mode === 'compress') {
        $compressed = inet_ntop(inet_pton($ipv6));
        return ['original' => $ipv6, 'compressed' => $compressed];
    } else {
        $expanded = inet_ntop(inet_pton($ipv6));
        // Re-expand to full form
        $parts = explode(':', $expanded);
        $fullParts = [];
        foreach ($parts as $part) {
            $fullParts[] = str_pad($part, 4, '0', STR_PAD_LEFT);
        }
        return ['original' => $ipv6, 'expanded' => implode(':', $fullParts)];
    }
}

function CloudHost247_tool_subnet_calculator($post)
{
    $ip = CloudHost247_tools_sanitize($post['ip'] ?? '', 'ip');
    $mask = (int) ($post['mask'] ?? 24);
    if (!CloudHost247_tools_validate_ipv4($ip)) {
        return ['error' => 'Invalid IPv4 address'];
    }
    if ($mask < 1 || $mask > 32) {
        return ['error' => 'Subnet mask must be 1-32'];
    }

    $ipLong = ip2long($ip);
    $maskLong = -1 << (32 - $mask);
    $network = long2ip($ipLong & $maskLong);
    $broadcast = long2ip($ipLong | (~$maskLong));
    $wildcard = long2ip(~$maskLong);

    $hostBits = 32 - $mask;
    $totalHosts = pow(2, $hostBits);
    $usableHosts = $totalHosts > 2 ? $totalHosts - 2 : 0;
    $firstUsable = long2ip((ip2long($network) & $maskLong) + 1);
    $lastUsable = long2ip((ip2long($network) | (~$maskLong)) - 1);

    return [
        'ip' => $ip,
        'cidr' => $ip . '/' . $mask,
        'subnet_mask' => long2ip($maskLong),
        'wildcard' => $wildcard,
        'network' => $network,
        'broadcast' => $broadcast,
        'first_usable' => $firstUsable,
        'last_usable' => $lastUsable,
        'total_hosts' => $totalHosts,
        'usable_hosts' => $usableHosts,
    ];
}

function CloudHost247_tool_isp_checker($post)
{
    $ip = CloudHost247_tools_sanitize($post['ip'] ?? '', 'ip');
    if (empty($ip)) {
        $ip = CloudHost247_tools_get_client_ip();
    }
    if (!CloudHost247_tools_validate_ip($ip)) {
        return ['error' => 'Invalid IP address'];
    }

    $location = CloudHost247_tool_ip_location(['ip' => $ip]);
    if (isset($location['error'])) {
        return $location;
    }

    return [
        'ip' => $ip,
        'isp' => $location['isp'] ?? 'Unknown',
        'organization' => $location['organization'] ?? 'Unknown',
        'country' => $location['country'] ?? 'Unknown',
        'city' => $location['city'] ?? 'Unknown',
        'asn' => $location['asn'] ?? 'Unknown',
    ];
}

function CloudHost247_tool_domain_to_ip($post)
{
    $domain = CloudHost247_tools_sanitize($post['domain'] ?? '', 'domain');
    if (!CloudHost247_tools_validate_domain($domain)) {
        return ['error' => 'Invalid domain name'];
    }

    $ipv4 = gethostbyname($domain);
    $ipv6 = null;

    // Try to get AAAA record
    $aaaa = dns_get_record($domain, DNS_AAAA);
    if (!empty($aaaa)) {
        $ipv6 = $aaaa[0]['ipv6'] ?? null;
    }

    return [
        'domain' => $domain,
        'ipv4' => $ipv4 !== $domain ? $ipv4 : null,
        'ipv6' => $ipv6,
    ];
}

// ---------------------------------------------------------------------
//  IPv6 utilities
// ---------------------------------------------------------------------

/**
 * Expand a 16-byte packed address to the full 8-group hex notation.
 */
function CloudHost247_tools_ipv6_expand_packed($packed)
{
    $hex = bin2hex($packed);
    $groups = str_split($hex, 4);

    return implode(':', $groups);
}

/** Increment a packed binary address by one. */
function CloudHost247_tools_ip_inc($packed)
{
    for ($i = strlen($packed) - 1; $i >= 0; $i--) {
        $byte = ord($packed[$i]);
        if ($byte < 255) {
            $packed[$i] = chr($byte + 1);

            return $packed;
        }
        $packed[$i] = chr(0);
    }

    return $packed; // wrapped
}

/** Compare two packed addresses of equal length. */
function CloudHost247_tools_ip_cmp($a, $b)
{
    return strcmp($a, $b);
}

/** Count trailing zero bits in a packed address. */
function CloudHost247_tools_ip_trailing_zeros($packed)
{
    $bits = strlen($packed) * 8;
    $count = 0;
    for ($i = strlen($packed) - 1; $i >= 0; $i--) {
        $byte = ord($packed[$i]);
        if ($byte === 0) {
            $count += 8;
            continue;
        }
        for ($b = 0; $b < 8; $b++) {
            if ($byte & (1 << $b)) {
                return $count + $b;
            }
        }
    }

    return $bits;
}

/** Apply a prefix mask to a packed address (returns network address). */
function CloudHost247_tools_ip_mask($packed, $prefix)
{
    $len = strlen($packed);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $bitsLeft = $prefix - ($i * 8);
        if ($bitsLeft >= 8) {
            $out .= $packed[$i];
        } elseif ($bitsLeft <= 0) {
            $out .= chr(0);
        } else {
            $mask = (0xFF << (8 - $bitsLeft)) & 0xFF;
            $out .= chr(ord($packed[$i]) & $mask);
        }
    }

    return $out;
}

/** Last address of a prefix (broadcast equivalent). */
function CloudHost247_tools_ip_prefix_end($packed, $prefix)
{
    $len = strlen($packed);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $bitsLeft = $prefix - ($i * 8);
        if ($bitsLeft >= 8) {
            $out .= $packed[$i];
        } elseif ($bitsLeft <= 0) {
            $out .= chr(255);
        } else {
            $mask = (0xFF >> $bitsLeft) & 0xFF;
            $out .= chr(ord($packed[$i]) | $mask);
        }
    }

    return $out;
}

/**
 * IPv6 Range to CIDR - convert a start/end address pair into the minimal
 * set of CIDR blocks that exactly covers it.
 */
function CloudHost247_tool_ipv6_range_to_cidr($post)
{
    $start = trim((string) ($post['start'] ?? $post['start_ip'] ?? ''));
    $end   = trim((string) ($post['end'] ?? $post['end_ip'] ?? ''));

    if ($start === '' || $end === '') {
        return ['error' => 'Please enter both a start and an end IPv6 address.'];
    }
    if (!filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return ['error' => 'The start address is not a valid IPv6 address.'];
    }
    if (!filter_var($end, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return ['error' => 'The end address is not a valid IPv6 address.'];
    }

    $s = inet_pton($start);
    $e = inet_pton($end);

    if (CloudHost247_tools_ip_cmp($s, $e) > 0) {
        return ['error' => 'The start address must not be greater than the end address.'];
    }

    $cidrs = [];
    $guard = 0;

    while (CloudHost247_tools_ip_cmp($s, $e) <= 0) {
        if (++$guard > 512) {
            return ['error' => 'That range needs more than 512 CIDR blocks. Please narrow the range.'];
        }

        // The largest block that can start at $s is bounded by the number of
        // trailing zero bits; shrink it until it no longer overruns $e.
        $best = 128;
        for ($p = 128 - CloudHost247_tools_ip_trailing_zeros($s); $p <= 128; $p++) {
            if (CloudHost247_tools_ip_cmp(CloudHost247_tools_ip_prefix_end($s, $p), $e) <= 0) {
                $best = $p;
                break;
            }
        }

        $blockEnd = CloudHost247_tools_ip_prefix_end($s, $best);
        $cidrs[] = [
            'cidr'      => inet_ntop($s) . '/' . $best,
            'first'     => inet_ntop($s),
            'last'      => inet_ntop($blockEnd),
            'addresses' => CloudHost247_tools_ipv6_count($best),
        ];

        if (CloudHost247_tools_ip_cmp($blockEnd, $e) >= 0) {
            break;
        }
        $s = CloudHost247_tools_ip_inc($blockEnd);
    }

    return [
        'start'       => inet_ntop(inet_pton($start)),
        'end'         => inet_ntop(inet_pton($end)),
        'block_count' => count($cidrs),
        'cidrs'       => $cidrs,
        'cidr_list'   => implode("\n", array_column($cidrs, 'cidr')),
        'note'        => 'This is the minimal set of CIDR blocks that covers the range exactly, with no extra addresses included.',
    ];
}

/** Human-readable address count for an IPv6 prefix. */
function CloudHost247_tools_ipv6_count($prefix)
{
    $hostBits = 128 - $prefix;
    if ($hostBits <= 62) {
        return number_format(pow(2, $hostBits));
    }

    return '2^' . $hostBits;
}

/**
 * IPv6 Expand / Compress - show every notation for an address.
 */
function CloudHost247_tool_ipv6_expand($post)
{
    $ip = trim((string) ($post['ip'] ?? $post['ipv6'] ?? ''));
    if ($ip === '') {
        return ['error' => 'Please enter an IPv6 address.'];
    }
    $ip = trim($ip, '[]');
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return ['error' => 'That is not a valid IPv6 address.'];
    }

    $packed   = inet_pton($ip);
    $expanded = CloudHost247_tools_ipv6_expand_packed($packed);
    $compressed = inet_ntop($packed);

    // Leading-zero-trimmed but still 8 groups.
    $short = implode(':', array_map(function ($g) {
        return ltrim($g, '0') ?: '0';
    }, explode(':', $expanded)));

    $hex = bin2hex($packed);

    // Reverse DNS (ip6.arpa) pointer.
    $arpa = implode('.', array_reverse(str_split($hex))) . '.ip6.arpa';

    $reason = CloudHost247ToolsSecurity::blockedIpReason($ip);

    return [
        'input'        => $ip,
        'expanded'     => $expanded,
        'compressed'   => $compressed,
        'short'        => $short,
        'hex'          => '0x' . $hex,
        'groups'       => explode(':', $expanded),
        'arpa'         => $arpa,
        'is_public'    => $reason === false,
        'scope'        => $reason === false ? 'global unicast (public)' : $reason,
        'binary'       => implode(' ', array_map(function ($b) {
            return str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }, str_split($packed))),
    ];
}

/**
 * IPv6 to IPv4 - extract an embedded IPv4 address where one exists.
 */
function CloudHost247_tool_ipv6_to_ipv4($post)
{
    $ip = trim((string) ($post['ip'] ?? $post['ipv6'] ?? ''));
    if ($ip === '') {
        return ['error' => 'Please enter an IPv6 address.'];
    }
    $ip = trim($ip, '[]');
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return ['error' => 'That is not a valid IPv6 address.'];
    }

    $packed = inet_pton($ip);
    $hex    = bin2hex($packed);
    $result = [
        'input'      => inet_ntop($packed),
        'expanded'   => CloudHost247_tools_ipv6_expand_packed($packed),
        'ipv4'       => null,
        'mechanism'  => null,
        'explanation'=> '',
    ];

    $bytesToIpv4 = function ($b) {
        return ord($b[0]) . '.' . ord($b[1]) . '.' . ord($b[2]) . '.' . ord($b[3]);
    };

    if (strncmp($packed, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
        $result['ipv4']       = $bytesToIpv4(substr($packed, 12, 4));
        $result['mechanism']  = 'IPv4-mapped IPv6 (::ffff:0:0/96)';
        $result['explanation'] = 'This address carries an IPv4 address inside an IPv6 container. It is used by dual-stack sockets to represent IPv4 peers.';
    } elseif (strncmp($packed, "\x20\x02", 2) === 0) {
        $result['ipv4']        = $bytesToIpv4(substr($packed, 2, 4));
        $result['mechanism']   = '6to4 (2002::/16)';
        $result['explanation'] = 'A 6to4 address embeds the IPv4 address of the tunnel endpoint in bytes 3-6. 6to4 is deprecated (RFC 7526) but still seen in legacy networks.';
    } elseif (strncmp($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8), 12) === 0) {
        $result['ipv4']        = $bytesToIpv4(substr($packed, 12, 4));
        $result['mechanism']   = 'NAT64 well-known prefix (64:ff9b::/96)';
        $result['explanation'] = 'NAT64 translators use this prefix to represent IPv4 destinations to IPv6-only clients.';
    } elseif (strncmp($packed, "\x20\x01\x00\x00", 4) === 0) {
        // Teredo: 2001:0000:<server v4>:<flags>:<port>:<client v4 obfuscated>
        $server = $bytesToIpv4(substr($packed, 4, 4));
        $clientRaw = substr($packed, 12, 4);
        $client = implode('.', array_map(function ($c) {
            return 255 - ord($c);
        }, str_split($clientRaw)));
        $port = 65535 - ((ord($packed[10]) << 8) | ord($packed[11]));
        $result['ipv4']        = $client;
        $result['mechanism']   = 'Teredo (2001:0::/32)';
        $result['teredo']      = ['server_ipv4' => $server, 'client_ipv4' => $client, 'client_port' => $port];
        $result['explanation'] = 'Teredo tunnels IPv6 over UDP/IPv4. The client IPv4 address and port are stored obfuscated (bitwise inverted) in the last 48 bits.';
    } elseif (strncmp($packed, str_repeat("\0", 12), 12) === 0 && $packed !== str_repeat("\0", 16)) {
        $result['ipv4']        = $bytesToIpv4(substr($packed, 12, 4));
        $result['mechanism']   = 'IPv4-compatible IPv6 (::/96, deprecated)';
        $result['explanation'] = 'IPv4-compatible addresses are deprecated by RFC 4291 and should not be used in new deployments.';
    } else {
        $result['explanation'] = 'This address does not embed an IPv4 address. Native IPv6 addresses have no IPv4 equivalent — IPv6 and IPv4 are separate address spaces, and a host can only be reached over IPv4 if it also has an A record or a translation service in front of it.';
    }

    $result['hex'] = '0x' . $hex;

    return $result;
}

/**
 * IPv6 Compatibility Checker - does this domain actually work over IPv6?
 *
 * Checks real AAAA records, nameserver IPv6 reachability and whether the
 * site answers over IPv6. No results are invented: anything that cannot be
 * measured is reported as unknown.
 */
function CloudHost247_tool_ipv6_compatibility($post)
{
    $domain = trim((string) ($post['domain'] ?? $post['host'] ?? ''));
    if ($domain === '') {
        return ['error' => 'Please enter a domain name.'];
    }

    try {
        $domain = CloudHost247ToolsSecurity::validateDomain($domain);
    } catch (CloudHost247ToolsSecurityException $e) {
        return ['error' => $e->getMessage()];
    }

    $checks = [];

    // 1. AAAA records on the apex.
    $aaaa = @dns_get_record($domain, DNS_AAAA) ?: [];
    $aaaaIps = array_values(array_filter(array_column($aaaa, 'ipv6')));
    $checks[] = [
        'name'   => 'AAAA record (apex)',
        'status' => $aaaaIps ? 'pass' : 'fail',
        'detail' => $aaaaIps
            ? 'Found ' . count($aaaaIps) . ' AAAA record(s): ' . implode(', ', $aaaaIps)
            : 'No AAAA record. IPv6-only clients cannot reach this domain directly.',
        'values' => $aaaaIps,
    ];

    // 2. AAAA on www.
    $wwwAaaa = @dns_get_record('www.' . $domain, DNS_AAAA) ?: [];
    $wwwIps  = array_values(array_filter(array_column($wwwAaaa, 'ipv6')));
    $www     = @dns_get_record('www.' . $domain, DNS_A + DNS_CNAME) ?: [];
    $checks[] = [
        'name'   => 'AAAA record (www)',
        'status' => $wwwIps ? 'pass' : ($www ? 'fail' : 'na'),
        'detail' => $wwwIps
            ? 'www resolves over IPv6: ' . implode(', ', $wwwIps)
            : ($www ? 'www exists but has no AAAA record.' : 'No www hostname is published.'),
        'values' => $wwwIps,
    ];

    // 3. Nameservers reachable over IPv6.
    $ns = @dns_get_record($domain, DNS_NS) ?: [];
    $nsV6 = [];
    foreach ($ns as $record) {
        if (empty($record['target'])) {
            continue;
        }
        $recs = @dns_get_record($record['target'], DNS_AAAA) ?: [];
        if ($recs) {
            $nsV6[$record['target']] = array_values(array_filter(array_column($recs, 'ipv6')));
        }
    }
    $checks[] = [
        'name'   => 'IPv6-reachable nameservers',
        'status' => $nsV6 ? (count($nsV6) === count($ns) ? 'pass' : 'warn') : 'fail',
        'detail' => $nsV6
            ? count($nsV6) . ' of ' . count($ns) . ' nameservers have AAAA records.'
            : 'No nameserver has an AAAA record, so IPv6-only resolvers cannot look this domain up without a translator.',
        'values' => $nsV6,
    ];

    // 4. Mail servers over IPv6.
    $mx = @dns_get_record($domain, DNS_MX) ?: [];
    $mxV6 = [];
    foreach ($mx as $record) {
        if (empty($record['target'])) {
            continue;
        }
        $recs = @dns_get_record($record['target'], DNS_AAAA) ?: [];
        if ($recs) {
            $mxV6[$record['target']] = array_values(array_filter(array_column($recs, 'ipv6')));
        }
    }
    $checks[] = [
        'name'   => 'IPv6-reachable mail servers',
        'status' => $mx ? ($mxV6 ? 'pass' : 'warn') : 'na',
        'detail' => $mx
            ? ($mxV6 ? count($mxV6) . ' of ' . count($mx) . ' MX hosts have AAAA records.'
                     : 'No MX host has an AAAA record.')
            : 'No MX records published for this domain.',
        'values' => $mxV6,
    ];

    // 5. Does the site actually answer over IPv6?
    $httpStatus = 'unknown';
    $httpDetail = 'CloudHost247 could not test an IPv6 HTTP connection from this server.';
    if ($aaaaIps) {
        try {
            $res = CloudHost247ToolsSecurity::fetch('https://' . $domain . '/', ['method' => 'HEAD', 'timeout' => 10]);
            $httpStatus = $res['status'] > 0 ? 'pass' : 'fail';
            $httpDetail = 'HTTPS responded with status ' . $res['status'] . '.';
        } catch (\Exception $e) {
            $httpStatus = 'warn';
            $httpDetail = 'AAAA records exist but the HTTPS request did not complete: ' . $e->getMessage();
        }
    } else {
        $httpStatus = 'na';
        $httpDetail = 'Skipped - there is no AAAA record to connect to.';
    }
    $checks[] = ['name' => 'HTTPS over IPv6', 'status' => $httpStatus, 'detail' => $httpDetail, 'values' => []];

    $passes = count(array_filter($checks, function ($c) {
        return $c['status'] === 'pass';
    }));
    $applicable = count(array_filter($checks, function ($c) {
        return $c['status'] !== 'na';
    }));
    $score = $applicable ? (int) round(($passes / $applicable) * 100) : 0;

    if ($aaaaIps && $score >= 80) {
        $verdict = 'IPv6 ready';
    } elseif ($aaaaIps) {
        $verdict = 'Partially IPv6 ready';
    } else {
        $verdict = 'Not reachable over IPv6';
    }

    return [
        'domain'   => $domain,
        'verdict'  => $verdict,
        'score'    => $score,
        'checks'   => $checks,
        'aaaa'     => $aaaaIps,
        'note'     => 'Checks are performed from the CloudHost247 server. A domain can still be unreachable for a particular IPv6 client because of routing or firewall policy between you and the host.',
    ];
}
