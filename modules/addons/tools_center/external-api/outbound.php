<?php
/**
 * Outbound request guard for the Tools Center external API.
 *
 * Several tools fetch or connect to a caller-supplied host. Without a guard the
 * API becomes a server-side request forgery proxy into private networks and
 * cloud metadata endpoints. Every outbound fetch or socket must go through the
 * helpers here, which:
 *   - accept only http/https URLs on ports 80/443, with no embedded credentials;
 *   - resolve the host and refuse if ANY resolved address is not public;
 *   - pin the connection to the validated address (no second DNS lookup, so no
 *     DNS-rebinding window);
 *   - follow redirects manually, re-validating every hop, with a hop limit.
 */

if (!function_exists('tc_cidr_contains')) {
    /** True when binary address $bin lies inside $net/$bits (same family only). */
    function tc_cidr_contains($bin, $net, $bits)
    {
        $nb = inet_pton($net);
        if ($nb === false || strlen($nb) !== strlen($bin)) {
            return false;
        }
        $full = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($full > 0 && substr($bin, 0, $full) !== substr($nb, 0, $full)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($bin[$full]) & $mask) === (ord($nb[$full]) & $mask);
    }
}

if (!function_exists('tc_is_public_ip')) {
    /** Deny-list check. Returns true only for a routable public address. */
    function tc_is_public_ip($ip)
    {
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }

        // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible forms: judge the embedded IPv4.
        if (strlen($bin) === 16) {
            $prefix = substr($bin, 0, 12);
            if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === str_repeat("\0", 12)) {
                return tc_is_public_ip(inet_ntop(substr($bin, 12)));
            }
        }

        $denyV4 = [
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
            ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
            ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
            ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
        ];
        $denyV6 = [
            ['::', 128], ['::1', 128], ['64:ff9b::', 96], ['100::', 64], ['2001:db8::', 32],
            ['fc00::', 7], ['fe80::', 10], ['ff00::', 8],
        ];

        if (strlen($bin) === 4) {
            foreach ($denyV4 as [$net, $bits]) {
                if (tc_cidr_contains($bin, $net, $bits)) return false;
            }
            return true;
        }
        foreach ($denyV6 as [$net, $bits]) {
            if (tc_cidr_contains($bin, $net, $bits)) return false;
        }
        return true;
    }
}

if (!function_exists('tc_resolve_public_ips')) {
    /**
     * Resolve a host to its addresses. Throws unless every address is public.
     * Returns a non-empty list of IP strings.
     */
    function tc_resolve_public_ips($host)
    {
        $host = strtolower(rtrim((string) $host, '.'));
        if ($host === '' || strlen($host) > 253) {
            throw new Exception('Invalid host');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [trim($host, '[]')];
        } else {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) {
                throw new Exception('Invalid host');
            }
            if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local' || strpos($host, '.') === false) {
                throw new Exception('Target address is not public');
            }
            $ips = [];
            foreach ((array) dns_get_record($host, DNS_A) as $r) {
                if (!empty($r['ip'])) $ips[] = $r['ip'];
            }
            foreach ((array) dns_get_record($host, DNS_AAAA) as $r) {
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
            if (!$ips) {
                $g = gethostbyname($host);
                if ($g !== $host && filter_var($g, FILTER_VALIDATE_IP)) $ips[] = $g;
            }
        }
        if (!$ips) {
            throw new Exception('Host could not be resolved');
        }
        foreach ($ips as $ip) {
            if (!tc_is_public_ip($ip)) {
                throw new Exception('Target address is not public');
            }
        }
        return array_values(array_unique($ips));
    }
}

if (!function_exists('tc_validate_outbound_url')) {
    /** Validate a URL for outbound fetching. Returns scheme, host, port and the pinned IP. */
    function tc_validate_outbound_url($url)
    {
        $parts = parse_url((string) $url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new Exception('Invalid URL');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new Exception('Only http and https URLs are allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new Exception('URLs with credentials are not allowed');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port !== 80 && $port !== 443) {
            throw new Exception('Only ports 80 and 443 are allowed');
        }
        $ips = tc_resolve_public_ips($parts['host']);
        return ['scheme' => $scheme, 'host' => strtolower($parts['host']), 'port' => $port, 'ip' => $ips[0], 'ips' => $ips];
    }
}

if (!function_exists('tc_fetch_url')) {
    /**
     * Fetch a URL with the guard applied to every hop.
     * Options: timeout (int seconds, max 30), nobody (bool), max_redirects (int, max 5).
     * Returns ['status', 'headers' (raw), 'body', 'final_url', 'redirects', 'info'].
     */
    function tc_fetch_url($url, array $opts = [])
    {
        $timeout = min(30, max(1, (int) ($opts['timeout'] ?? 30)));
        $maxRedirects = min(5, max(0, (int) ($opts['max_redirects'] ?? 5)));
        $current = (string) $url;
        $redirects = 0;

        while (true) {
            $v = tc_validate_outbound_url($current);
            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => !empty($opts['nobody']),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$v['host'] . ':' . $v['port'] . ':' . (strpos($v['ip'], ':') !== false ? '[' . $v['ip'] . ']' : $v['ip'])],
                CURLOPT_USERAGENT => $opts['user_agent'] ?? 'Mozilla/5.0 (compatible; CloudHost247-ToolsCenter/1.0)',
            ]);
            $raw = curl_exec($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new Exception('Failed to fetch URL');
            }

            $headerSize = (int) ($info['header_size'] ?? 0);
            $headers = substr($raw, 0, $headerSize);
            $body = (string) substr($raw, $headerSize);
            $status = (int) ($info['http_code'] ?? 0);

            if ($status >= 300 && $status < 400 && preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
                if ($redirects >= $maxRedirects) {
                    throw new Exception('Too many redirects');
                }
                $redirects++;
                $current = tc_resolve_redirect($current, trim($m[1]));
                continue;
            }

            return ['status' => $status, 'headers' => $headers, 'body' => $body,
                    'final_url' => $current, 'redirects' => $redirects, 'info' => $info];
        }
    }
}

if (!function_exists('tc_resolve_redirect')) {
    /** Resolve a Location header against the current URL. */
    function tc_resolve_redirect($base, $location)
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strpos($location, '//') === 0) {
            return $b['scheme'] . ':' . $location;
        }
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
        return $origin . $dir . $location;
    }
}

if (!function_exists('tc_open_public_socket')) {
    /**
     * Open a TCP connection to a public host. The address is validated first and
     * the socket is opened to that validated address. Returns a stream or false.
     * Throws when the host is not public.
     */
    function tc_open_public_socket($host, $port, $timeout, &$errno = 0, &$errstr = '')
    {
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new Exception('Invalid port');
        }
        $ips = tc_resolve_public_ips($host);
        $ip = $ips[0];
        $target = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        return @fsockopen($target, $port, $errno, $errstr, $timeout);
    }
}
