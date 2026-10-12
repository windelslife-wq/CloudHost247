<?php
/**
 * HostX Tools - Security Manager
 *
 * Handles CSRF protection, input sanitization, output escaping,
 * and rate limiting for all tools.
 *
 * @package    WHMCS
 * @author     HostX Tools Team
 * @copyright  Copyright (c) 2024
 * @license    MIT License
 */

namespace WHMCS\Module\Addon\HostXTools;

use Capsule;
use Exception;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

class SecurityManager
{
    /**
     * @var int Rate limit window in seconds (1 minute)
     */
    private $window = 60;
    
    /**
     * @var int Maximum requests per window
     */
    private $maxRequests;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $config = $this->getModuleConfig();
        $this->maxRequests = isset($config['rate_limit_requests']) ? (int)$config['rate_limit_requests'] : 30;
    }
    
    /**
     * Generate CSRF token
     *
     * @return string
     */
    public static function generateCsrfToken()
    {
        if (empty($_SESSION['hostx_tools_csrf'])) {
            $_SESSION['hostx_tools_csrf'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['hostx_tools_csrf'];
    }
    
    /**
     * Validate CSRF token
     *
     * @param string $token
     * @return bool
     */
    public static function validateCsrfToken($token)
    {
        if (empty($token) || empty($_SESSION['hostx_tools_csrf'])) {
            return false;
        }
        
        return hash_equals($_SESSION['hostx_tools_csrf'], $token);
    }
    
    /**
     * Check rate limit for current user
     *
     * @param string $tool Tool identifier
     * @return bool True if request is allowed
     */
    public function checkRateLimit($tool)
    {
        // Rate limiting disabled
        if ($this->maxRequests <= 0) {
            return true;
        }
        
        $ipAddress = $this->getClientIp();
        $windowStart = time() - $this->window;
        
        try {
            // Clean old entries
            Capsule::table('hostx_tools_rate_limit')
                ->where('window_start', '<', $windowStart)
                ->delete();
            
            // Get current count for this IP and tool
            $record = Capsule::table('hostx_tools_rate_limit')
                ->where('ip_address', $ipAddress)
                ->where('tool', $tool)
                ->where('window_start', '>', $windowStart)
                ->first();
            
            if ($record) {
                if ($record->requests >= $this->maxRequests) {
                    return false;
                }
                
                // Increment request count
                Capsule::table('hostx_tools_rate_limit')
                    ->where('id', $record->id)
                    ->update([
                        'requests' => $record->requests + 1,
                    ]);
                
                return true;
            }
            
            // Create new record
            Capsule::table('hostx_tools_rate_limit')->insert([
                'ip_address'   => $ipAddress,
                'tool'         => $tool,
                'requests'     => 1,
                'window_start' => time(),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            
            return true;
        } catch (Exception $e) {
            // If rate limiting fails, allow the request
            return true;
        }
    }
    
    /**
     * Get remaining requests for current user
     *
     * @param string $tool
     * @return int
     */
    public function getRemainingRequests($tool)
    {
        if ($this->maxRequests <= 0) {
            return -1; // Unlimited
        }
        
        $ipAddress = $this->getClientIp();
        $windowStart = time() - $this->window;
        
        try {
            $record = Capsule::table('hostx_tools_rate_limit')
                ->where('ip_address', $ipAddress)
                ->where('tool', $tool)
                ->where('window_start', '>', $windowStart)
                ->first();
            
            if ($record) {
                $remaining = $this->maxRequests - $record->requests;
                return max(0, $remaining);
            }
            
            return $this->maxRequests;
        } catch (Exception $e) {
            return $this->maxRequests;
        }
    }
    
    /**
     * Sanitize a DNS name. Public IP literals are accepted only for PTR lookups,
     * where they are converted to their reverse-DNS owner name.
     *
     * @param string $domain
     * @param bool $allowPublicPtr Whether a public IP literal/reverse owner is allowed
     * @return string|false Returns sanitized domain or false if invalid
     */
    public static function sanitizeDomain($domain, $allowPublicPtr = false)
    {
        if (empty($domain)) {
            return false;
        }

        $domain = strtolower(trim($domain));
        $plainIp = trim($domain, '[]');
        if ($allowPublicPtr && filter_var($plainIp, FILTER_VALIDATE_IP) !== false) {
            return self::publicIpToReverseName($plainIp);
        }

        // Remove protocol if present, then extract the host before normalizing.
        $domain = preg_replace('/^(https?:\\/\\/)?(www\\.)?/i', '', $domain);
        $domain = parse_url('http://' . $domain, PHP_URL_HOST) ?: $domain;
        $domain = trim($domain, '[]');

        if ($allowPublicPtr && filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return self::publicIpToReverseName($domain);
        }

        // Preserve the legacy normalization for ordinary domain names.
        $domain = preg_replace('/[^a-z0-9\\.\\-]/', '', $domain);
        if (strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP) !== false
            || preg_match('/^\\d{1,3}(?:\\.\\d{1,3}){3}$/', $domain)) {
            return false;
        }

        if (!preg_match('/^([a-z0-9]([a-z0-9\\-]{0,61}[a-z0-9])?\\.)+[a-z0-9][a-z0-9\\-]{0,61}[a-z0-9]$/i', $domain)) {
            // Allow IDN (internationalized domain names).
            if (!preg_match('/^([a-z0-9\\x{00a1}-\\x{ffff}]([a-z0-9\\x{00a1}-\\x{ffff}\\-]{0,61}[a-z0-9\\x{00a1}-\\x{ffff}])?\\.)+[a-z0-9\\x{00a1}-\\x{ffff}][a-z0-9\\x{00a1}-\\x{ffff}\\-]{0,61}[a-z0-9\\x{00a1}-\\x{ffff}]$/iu', $domain)) {
                return false;
            }
        }

        // Reverse namespaces are permitted only for explicit PTR requests; when
        // permitted, only a full owner for a public address is accepted.
        $isReverseNamespace = preg_match('/(?:^|\\.)in-addr\\.arpa$/', $domain)
            || preg_match('/(?:^|\\.)ip6\\.(?:arpa|int)$/', $domain);
        if ($isReverseNamespace && !$allowPublicPtr) {
            return false;
        }
        if ($allowPublicPtr) {
            $reverseName = self::validateReversePtrName($domain);
            if ($reverseName === false) {
                return false;
            }
            if ($reverseName !== null) {
                return $reverseName;
            }
        }

        // Do not disclose local, split-DNS, or special-use names to the host resolver.
        $blockedHosts = [
            'metadata', 'metadata.goog', 'metadata.google.internal', 'instance-data',
            'instance-data.ec2.internal', 'localhost.localdomain', 'home.arpa',
        ];
        if (in_array($domain, $blockedHosts, true)) {
            return false;
        }
        $blockedSuffixes = [
            '.localhost', '.local', '.localdomain', '.internal', '.intranet', '.lan',
            '.home', '.corp', '.private', '.test', '.example', '.invalid', '.onion',
            '.alt', '.home.arpa',
        ];
        foreach ($blockedSuffixes as $suffix) {
            if (substr($domain, -strlen($suffix)) === $suffix) {
                return false;
            }
        }

        return $domain;
    }

    /** True only when an address is outside common non-public/special-use ranges. */
    private static function isPublicPtrAddress($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ipLong = ip2long($ip);
            $blocked = [
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10],
                ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
                ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24],
                ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
                ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
            ];
            foreach ($blocked as $range) {
                $network = ip2long($range[0]);
                $mask = (-1 << (32 - $range[1])) & 0xFFFFFFFF;
                if (($ipLong & $mask) === ($network & $mask)) {
                    return false;
                }
            }
            return true;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        $blocked = [
            ['::', 128], ['::1', 128], ['::ffff:0:0', 96], ['64:ff9b::', 96],
            ['100::', 64], ['2001:db8::', 32], ['fc00::', 7], ['fe80::', 10],
            ['ff00::', 8],
        ];
        foreach ($blocked as $range) {
            $network = @inet_pton($range[0]);
            if ($network === false) {
                continue;
            }
            $bits = $range[1];
            $bytes = intdiv($bits, 8);
            $remainingBits = $bits % 8;
            if ($bytes > 0 && strncmp($packed, $network, $bytes) !== 0) {
                continue;
            }
            if ($remainingBits > 0) {
                $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
                if ((ord($packed[$bytes]) & $mask) !== (ord($network[$bytes]) & $mask)) {
                    continue;
                }
            }
            return false;
        }

        return true;
    }

    /** Convert only globally routable IP literals to reverse-DNS owner names. */
    private static function publicIpToReverseName($ip)
    {
        if (!self::isPublicPtrAddress($ip)) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
        }
        if (strlen($packed) === 16) {
            return implode('.', array_reverse(str_split(bin2hex($packed)))) . '.ip6.arpa';
        }

        return false;
    }

    /** Validate a full IPv4/IPv6 PTR owner and reject private/reserved addresses. */
    private static function validateReversePtrName($domain)
    {
        $domain = strtolower($domain);

        if (preg_match('/(?:^|\\.)in-addr\\.arpa$/', $domain)) {
            if (!preg_match('/^((?:\\d{1,3}\\.){4})in-addr\\.arpa$/', $domain, $matches)) {
                return false;
            }
            $octets = explode('.', rtrim($matches[1], '.'));
            $ip = implode('.', array_reverse($octets));
            if (!self::isPublicPtrAddress($ip)) {
                return false;
            }
            return $domain;
        }

        if (preg_match('/(?:^|\\.)ip6\\.(?:arpa|int)$/', $domain)) {
            if (!preg_match('/^((?:[0-9a-f]\\.){32})ip6\\.arpa$/', $domain, $matches)) {
                return false;
            }
            $reversedHex = str_replace('.', '', rtrim($matches[1], '.'));
            $hex = strrev($reversedHex);
            $packed = @hex2bin($hex);
            if ($packed === false || strlen($packed) !== 16) {
                return false;
            }
            $ip = @inet_ntop($packed);
            if ($ip === false || !self::isPublicPtrAddress($ip)) {
                return false;
            }
            return $domain;
        }

        return null;
    }

    /**
     * Sanitize IP address input
     *
     * @param string $ip
     * @return string|false Returns sanitized IP or false if invalid
     */
    public static function sanitizeIp($ip)
    {
        if (empty($ip)) {
            return false;
        }
        
        $ip = trim($ip);
        
        // Validate IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
        
        // Validate IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }
        
        // Check for "me" or empty to use client's IP
        if ($ip === 'me' || $ip === 'myip' || $ip === 'my') {
            return self::getClientIp();
        }
        
        return false;
    }
    
    /**
     * Sanitize DNS record type
     *
     * @param string $type
     * @return string
     */
    public static function sanitizeDnsType($type)
    {
        $validTypes = ['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA', 'PTR', 'SRV', 'CAA', 'ALL'];
        
        $type = strtoupper(trim($type));
        
        if (in_array($type, $validTypes, true)) {
            return $type;
        }
        
        return 'ALL';
    }
    
    /**
     * Escape output for HTML
     *
     * @param string $text
     * @return string
     */
    public static function escapeHtml($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Get client IP address
     *
     * @return string
     */
    public static function getClientIp()
    {
        // REMOTE_ADDR is authoritative. Client-supplied forwarding headers are read
        // only when REMOTE_ADDR is a configured trusted proxy
        // (CLOUDHOST247_TRUSTED_PROXIES, comma-separated). Trusting them lets a
        // caller choose a new rate-limit key on every request (audit H-1).
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        $trusted = getenv('CLOUDHOST247_TRUSTED_PROXIES');
        if ($trusted && filter_var($remote, FILTER_VALIDATE_IP)
            && in_array($remote, array_map('trim', explode(',', $trusted)), true)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                if (empty($_SERVER[$header])) {
                    continue;
                }
                $first = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '127.0.0.1';
    }

    /**
     * Get module configuration
     *
     * @return array
     */
    private function getModuleConfig()
    {
        $settings = [];
        
        try {
            $result = Capsule::table('tbladdonmodules')
                ->where('module', 'hostx_tools')
                ->get();
            
            foreach ($result as $row) {
                $settings[$row->setting] = $row->value;
            }
        } catch (Exception $e) {
            // Use defaults
        }
        
        return $settings;
    }
}
