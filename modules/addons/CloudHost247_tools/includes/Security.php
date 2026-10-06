<?php
/**
 * CloudHost247 Tools - Security guard.
 *
 * Central input validation, SSRF protection and safe outbound fetching for
 * every server-side tool. Any tool that causes the CloudHost247 server to make
 * an outbound connection on behalf of a visitor MUST route through
 * CloudHost247ToolsSecurity::assertPublicHost() or ::fetch().
 *
 * @package CloudHost247\Tools
 */

if (!defined('WHMCS') && !defined('CLOUDHOST247_TOOLS')) {
    die('This file cannot be accessed directly');
}

class CloudHost247ToolsSecurityException extends \Exception
{
}

class CloudHost247ToolsSecurity
{
    /** Maximum bytes we will ever pull from a remote URL. */
    const MAX_FETCH_BYTES = 2097152; // 2 MB

    /** Maximum redirects we will follow (each one re-validated). */
    const MAX_REDIRECTS = 4;

    /** Ports a user may ask the port checker to probe. */
    const ALLOWED_PROBE_PORTS = [
        20, 21, 22, 23, 25, 53, 67, 68, 69, 80, 110, 119, 123, 143, 161, 194,
        389, 443, 445, 465, 514, 587, 636, 873, 993, 995, 1194, 1433, 1521,
        1723, 2082, 2083, 2086, 2087, 2095, 2096, 2222, 3000, 3128, 3306,
        3389, 5060, 5061, 5222, 5432, 5672, 5900, 6379, 8000, 8008, 8080,
        8443, 8888, 9000, 9090, 9200, 10000, 11211, 25565, 27017,
    ];

    /**
     * IPv4 / IPv6 ranges that must never be reachable through these tools.
     * Each entry: [network, prefix length, label].
     */
    protected static $blockedV4 = [
        ['0.0.0.0', 8,       'this network'],
        ['10.0.0.0', 8,      'RFC1918 private'],
        ['100.64.0.0', 10,   'carrier-grade NAT'],
        ['127.0.0.0', 8,     'loopback'],
        ['169.254.0.0', 16,  'link-local / cloud metadata'],
        ['172.16.0.0', 12,   'RFC1918 private'],
        ['192.0.0.0', 24,    'IETF protocol assignments'],
        ['192.0.2.0', 24,    'documentation'],
        ['192.88.99.0', 24,  '6to4 relay anycast'],
        ['192.168.0.0', 16,  'RFC1918 private'],
        ['198.18.0.0', 15,   'benchmarking'],
        ['198.51.100.0', 24, 'documentation'],
        ['203.0.113.0', 24,  'documentation'],
        ['224.0.0.0', 4,     'multicast'],
        ['240.0.0.0', 4,     'reserved'],
    ];

    protected static $blockedV6 = [
        ['::', 128,       'unspecified'],
        ['::1', 128,      'loopback'],
        ['::ffff:0:0', 96, 'IPv4-mapped'],
        ['64:ff9b::', 96, 'NAT64'],
        ['100::', 64,     'discard-only'],
        ['2001:db8::', 32, 'documentation'],
        ['fc00::', 7,     'unique local'],
        ['fe80::', 10,    'link-local'],
        ['ff00::', 8,     'multicast'],
    ];

    /** Hostnames that are never resolvable through the tools. */
    protected static $blockedHosts = [
        'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback',
        'metadata', 'metadata.google.internal', 'metadata.goog',
        'instance-data', 'instance-data.ec2.internal',
    ];

    /** Hostname suffixes that indicate an internal name. */
    protected static $blockedSuffixes = [
        '.local', '.localhost', '.internal', '.intranet', '.lan', '.home',
        '.corp', '.private', '.test', '.example', '.invalid', '.onion',
    ];

    // -----------------------------------------------------------------
    //  Primitive validators
    // -----------------------------------------------------------------

    public static function isIpv4($ip)
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }

    public static function isIpv6($ip)
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    }

    public static function isIp($ip)
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP);
    }

    /**
     * Validate a hostname, accepting IDN and normalising to lowercase ASCII.
     *
     * @return string|false Normalised hostname, or false when invalid.
     */
    public static function normaliseHostname($host)
    {
        $host = trim((string) $host);
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        $host = rtrim($host, '.');
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];

        // Strip userinfo and port.
        if (strpos($host, '@') !== false) {
            $host = substr($host, strrpos($host, '@') + 1);
        }
        if (substr($host, 0, 1) === '[') {
            // bracketed IPv6 literal
            $close = strpos($host, ']');
            if ($close === false) {
                return false;
            }
            $inner = substr($host, 1, $close - 1);

            return self::isIpv6($inner) ? strtolower($inner) : false;
        }
        if (substr_count($host, ':') === 1) {
            $host = explode(':', $host)[0];
        }

        if (self::isIp($host)) {
            return strtolower($host);
        }

        // IDN -> punycode where the extension is available.
        if (preg_match('/[^\x20-\x7f]/', $host) && function_exists('idn_to_ascii')) {
            $converted = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($converted) {
                $host = $converted;
            }
        }

        $host = strtolower($host);

        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return false;
        }

        return $host;
    }

    /**
     * Validate a hostname that must be a registrable domain.
     */
    public static function validateDomain($domain)
    {
        $host = self::normaliseHostname($domain);
        if ($host === false || self::isIp($host)) {
            throw new CloudHost247ToolsSecurityException('Please enter a valid domain name, for example example.com');
        }

        return $host;
    }

    public static function validateIp($ip, $version = null)
    {
        $ip = trim((string) $ip);
        if ($version === 4 && !self::isIpv4($ip)) {
            throw new CloudHost247ToolsSecurityException('Please enter a valid IPv4 address.');
        }
        if ($version === 6 && !self::isIpv6($ip)) {
            throw new CloudHost247ToolsSecurityException('Please enter a valid IPv6 address.');
        }
        if ($version === null && !self::isIp($ip)) {
            throw new CloudHost247ToolsSecurityException('Please enter a valid IP address.');
        }

        return strtolower($ip);
    }

    public static function validatePort($port)
    {
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new CloudHost247ToolsSecurityException('Port must be between 1 and 65535.');
        }

        return $port;
    }

    /**
     * Ports the public port checker is permitted to probe.
     */
    public static function validateProbePort($port)
    {
        $port = self::validatePort($port);
        if (!in_array($port, self::ALLOWED_PROBE_PORTS, true)) {
            throw new CloudHost247ToolsSecurityException(
                'Port ' . $port . ' is not on the diagnostic allowlist. '
                . 'CloudHost247 only probes well-known service ports to prevent this tool being used for network scanning.'
            );
        }

        return $port;
    }

    // -----------------------------------------------------------------
    //  SSRF protection
    // -----------------------------------------------------------------

    /**
     * Is this literal IP address inside a blocked range?
     *
     * @return string|false Reason string when blocked, false when public.
     */
    public static function blockedIpReason($ip)
    {
        if (self::isIpv4($ip)) {
            $long = ip2long($ip);
            foreach (self::$blockedV4 as [$net, $bits, $label]) {
                $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
                if ((ip2long($net) & $mask) === ($long & $mask)) {
                    return $label;
                }
            }

            return false;
        }

        if (self::isIpv6($ip)) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return 'unparseable address';
            }
            foreach (self::$blockedV6 as [$net, $bits, $label]) {
                $netBin = inet_pton($net);
                if ($netBin === false) {
                    continue;
                }
                $bytes = intdiv($bits, 8);
                $rem   = $bits % 8;
                if ($bytes > 0 && strncmp($bin, $netBin, $bytes) !== 0) {
                    continue;
                }
                if ($rem > 0) {
                    $mask = (0xFF << (8 - $rem)) & 0xFF;
                    if ((ord($bin[$bytes]) & $mask) !== (ord($netBin[$bytes]) & $mask)) {
                        continue;
                    }
                }

                return $label;
            }

            return false;
        }

        return 'not an IP address';
    }

    /**
     * Reject hostnames that look internal before we even resolve them.
     */
    protected static function assertHostnameAllowed($host)
    {
        if (in_array($host, self::$blockedHosts, true)) {
            throw new CloudHost247ToolsSecurityException(
                'For security reasons CloudHost247 tools cannot target internal or loopback hosts.'
            );
        }
        foreach (self::$blockedSuffixes as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                throw new CloudHost247ToolsSecurityException(
                    'For security reasons CloudHost247 tools cannot target internal hostnames (' . $suffix . ').'
                );
            }
        }
        if (strpos($host, '.') === false && !self::isIp($host)) {
            throw new CloudHost247ToolsSecurityException('Please enter a fully qualified hostname.');
        }
    }

    /**
     * Resolve a host and guarantee every address it maps to is public.
     *
     * This is the core SSRF gate: it defeats DNS entries that point at
     * private space, and returns the vetted addresses so callers can pin the
     * connection to an address that was actually checked.
     *
     * @return array{host:string, ips:array}
     */
    public static function assertPublicHost($host)
    {
        $host = self::normaliseHostname($host);
        if ($host === false) {
            throw new CloudHost247ToolsSecurityException('That does not look like a valid hostname or IP address.');
        }

        if (self::isIp($host)) {
            $reason = self::blockedIpReason($host);
            if ($reason !== false) {
                throw new CloudHost247ToolsSecurityException(
                    'That address is in a reserved range (' . $reason . ') and cannot be targeted by CloudHost247 tools.'
                );
            }

            return ['host' => $host, 'ips' => [$host]];
        }

        self::assertHostnameAllowed($host);

        $ips = [];
        foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $records) {
            $res = @dns_get_record($host, $records);
            if (is_array($res)) {
                foreach ($res as $r) {
                    if (!empty($r['ip'])) {
                        $ips[] = $r['ip'];
                    }
                    if (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }

        if (empty($ips)) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }

        $ips = array_values(array_unique($ips));

        if (empty($ips)) {
            throw new CloudHost247ToolsSecurityException('That hostname does not resolve to any IP address.');
        }

        foreach ($ips as $ip) {
            $reason = self::blockedIpReason($ip);
            if ($reason !== false) {
                self::logSecurityEvent('ssrf_blocked', $host, $ip . ' (' . $reason . ')');
                throw new CloudHost247ToolsSecurityException(
                    'That hostname resolves into a reserved range (' . $reason . ') and cannot be targeted by CloudHost247 tools.'
                );
            }
        }

        return ['host' => $host, 'ips' => $ips];
    }

    /**
     * Validate a user-supplied URL for outbound fetching.
     *
     * @return array{url:string, host:string, scheme:string, port:int, ips:array}
     */
    public static function assertPublicUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            throw new CloudHost247ToolsSecurityException('Please enter a URL.');
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new CloudHost247ToolsSecurityException('That URL could not be parsed.');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new CloudHost247ToolsSecurityException('Only http:// and https:// URLs are supported.');
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new CloudHost247ToolsSecurityException('URLs containing credentials are not accepted.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443, 8080, 8443, 8000, 3000], true)) {
            throw new CloudHost247ToolsSecurityException('Only standard web ports may be fetched.');
        }

        $checked = self::assertPublicHost($parts['host']);

        $rebuilt = $scheme . '://' . $checked['host']
            . (isset($parts['port']) ? ':' . $port : '')
            . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [
            'url'    => $rebuilt,
            'host'   => $checked['host'],
            'scheme' => $scheme,
            'port'   => $port,
            'ips'    => $checked['ips'],
        ];
    }

    /**
     * SSRF-safe outbound HTTP fetch.
     *
     * Redirects are followed manually so every hop is re-validated, the
     * response body is hard-capped, and nothing in private space is reachable.
     *
     * @return array{status:int,headers:array,body:string,url:string,redirects:array,time:float}
     */
    public static function fetch($url, array $options = [])
    {
        $method     = strtoupper($options['method'] ?? 'GET');
        $timeout    = min(20, max(1, (int) ($options['timeout'] ?? 12)));
        $maxBytes   = min(self::MAX_FETCH_BYTES, (int) ($options['max_bytes'] ?? self::MAX_FETCH_BYTES));
        $userAgent  = $options['user_agent'] ?? 'CloudHost247-Tools/3.0 (+https://www.cloudhost247.com/tools)';
        $extraHead  = $options['headers'] ?? [];

        $redirects = [];
        $current   = self::assertPublicUrl($url);
        $started   = microtime(true);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $current['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_NOBODY         => $method === 'HEAD',
                CURLOPT_CUSTOMREQUEST  => $method,
                // We follow redirects ourselves so each hop is re-validated.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
                CURLOPT_USERAGENT      => $userAgent,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING       => '',
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER     => $extraHead,
                CURLOPT_BUFFERSIZE     => 16384,
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_PROGRESSFUNCTION => function ($res, $dlTotal, $dlNow) use ($maxBytes) {
                    return $dlNow > $maxBytes ? 1 : 0;
                },
            ]);

            // Pin the connection to an address we already validated, so a DNS
            // rebind between validation and connect cannot reach private space.
            if (!empty($current['ips'][0])) {
                $ip = $current['ips'][0];
                curl_setopt($ch, CURLOPT_RESOLVE, [
                    $current['host'] . ':' . $current['port'] . ':' . $ip,
                ]);
            }

            $raw      = curl_exec($ch);
            $errNo    = curl_errno($ch);
            $error    = curl_error($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($raw === false) {
                if ($errNo === CURLE_ABORTED_BY_CALLBACK) {
                    throw new CloudHost247ToolsSecurityException('The remote response exceeded the size limit CloudHost247 will download.');
                }
                throw new CloudHost247ToolsSecurityException('Could not reach that URL: ' . ($error ?: 'connection failed'));
            }

            $headerBlob = substr($raw, 0, $headSize);
            $body       = substr($raw, $headSize);
            $headers    = self::parseHeaders($headerBlob);

            if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
                $next = $headers['location'];
                if (is_array($next)) {
                    $next = end($next);
                }
                $next = self::resolveRelative($current['url'], $next);
                $redirects[] = ['from' => $current['url'], 'to' => $next, 'status' => $status];
                // Re-validate the redirect target - this is where open
                // redirects would otherwise become an SSRF.
                $current = self::assertPublicUrl($next);
                continue;
            }

            return [
                'status'    => $status,
                'headers'   => $headers,
                'body'      => $body,
                'url'       => $current['url'],
                'host'      => $current['host'],
                'redirects' => $redirects,
                'time'      => round((microtime(true) - $started) * 1000, 2),
            ];
        }

        throw new CloudHost247ToolsSecurityException('Too many redirects (limit ' . self::MAX_REDIRECTS . ').');
    }

    protected static function resolveRelative($base, $target)
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $target)) {
            return $target;
        }
        $b = parse_url($base);
        $root = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '')
            . (isset($b['port']) ? ':' . $b['port'] : '');
        if (substr($target, 0, 1) === '/') {
            return $root . $target;
        }
        $path = $b['path'] ?? '/';
        $dir  = substr($path, 0, strrpos($path, '/') + 1);

        return $root . $dir . $target;
    }

    public static function parseHeaders($blob)
    {
        $headers = [];
        // Keep only the final header block (after any 1xx / proxy blocks).
        $blocks = preg_split("/\r?\n\r?\n/", trim($blob));
        $last   = end($blocks);
        foreach (preg_split("/\r?\n/", (string) $last) as $line) {
            if (strpos($line, ':') === false) {
                if (stripos($line, 'HTTP/') === 0) {
                    $headers['_status_line'] = trim($line);
                }
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            $v = trim($v);
            if (isset($headers[$k])) {
                $headers[$k] = is_array($headers[$k]) ? array_merge($headers[$k], [$v]) : [$headers[$k], $v];
            } else {
                $headers[$k] = $v;
            }
        }

        return $headers;
    }

    // -----------------------------------------------------------------
    //  Shell safety
    // -----------------------------------------------------------------

    /**
     * Run an allowlisted diagnostic binary with escaped arguments.
     *
     * No user data ever reaches a shell unescaped, and only these binaries
     * may be invoked.
     */
    public static function runCommand($binary, array $args, $timeout = 15)
    {
        $allowed = ['ping', 'ping6', 'traceroute', 'traceroute6', 'dig', 'host', 'whois', 'openssl'];
        if (!in_array($binary, $allowed, true)) {
            throw new CloudHost247ToolsSecurityException('Command not permitted.');
        }

        $path = self::which($binary);
        if ($path === null) {
            return ['available' => false, 'output' => '', 'code' => 127];
        }

        $cmd = escapeshellcmd($path);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes, null, null);
        if (!is_resource($process)) {
            return ['available' => false, 'output' => '', 'code' => 127];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output   = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            usleep(50000);
            if (strlen($output) > 262144) {
                break;
            }
        }

        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($process);

        return [
            'available' => true,
            'output'    => $output,
            'code'      => isset($status['exitcode']) ? $status['exitcode'] : 0,
        ];
    }

    protected static function which($binary)
    {
        static $cache = [];
        if (array_key_exists($binary, $cache)) {
            return $cache[$binary];
        }
        $cache[$binary] = null;
        foreach (['/usr/bin/', '/bin/', '/usr/sbin/', '/sbin/', '/usr/local/bin/'] as $dir) {
            if (@is_executable($dir . $binary)) {
                $cache[$binary] = $dir . $binary;
                break;
            }
        }

        return $cache[$binary];
    }

    // -----------------------------------------------------------------
    //  Misc
    // -----------------------------------------------------------------

    /**
     * Escape for HTML output.
     */
    public static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Bounded text input.
     */
    public static function text($value, $maxLength = 20000)
    {
        $value = (string) $value;
        if (strlen($value) > $maxLength) {
            throw new CloudHost247ToolsSecurityException('Input is too long (limit ' . number_format($maxLength) . ' characters).');
        }

        return $value;
    }

    public static function clientIp()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Only trust forwarded headers when a trusted proxy is configured.
        $trusted = getenv('CLOUDHOST247_TRUSTED_PROXIES');
        if ($trusted && in_array($ip, array_map('trim', explode(',', $trusted)), true)) {
            $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($fwd) {
                $first = trim(explode(',', $fwd)[0]);
                if (self::isIp($first)) {
                    return $first;
                }
            }
        }

        return self::isIp($ip) ? $ip : '0.0.0.0';
    }

    public static function logSecurityEvent($event, $target, $detail = '')
    {
        if (!class_exists('\Illuminate\Database\Capsule\Manager')) {
            return;
        }
        try {
            \Illuminate\Database\Capsule\Manager::table('mod_CloudHost247_tools_security_log')->insert([
                'event'      => substr($event, 0, 64),
                'ip'         => self::clientIp(),
                'target'     => substr((string) $target, 0, 255),
                'detail'     => substr((string) $detail, 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Logging must never break a tool.
        }
    }
}
