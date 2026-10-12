<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Resolves the visitor's IP address from the request, and — critically —
 * decides whether a forwarded header may be believed.
 *
 * `X-Forwarded-For` and friends are attacker-controlled on any host that is
 * not behind a proxy that overwrites them. Believing them lets anyone claim to
 * be in any country. So:
 *
 *  - forwarded headers are read **only** when the peer that actually opened
 *    the connection (`REMOTE_ADDR`) is inside the configured trusted-proxy
 *    list (CIDR, IPv4 + IPv6);
 *  - with no trusted proxies configured, `REMOTE_ADDR` is the answer and every
 *    forwarded header is ignored, which is the safe default;
 *  - the X-Forwarded-For chain is walked from the right, skipping addresses
 *    appended by our own trusted proxies, and stops at the first untrusted
 *    address. That is the closest address we did not put there ourselves;
 *  - anything that is not a syntactically valid IP is discarded.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class ClientIp
{
    /**
     * Forwarded headers we are willing to read, highest priority first.
     *
     * `CF-Connecting-IP` leads because Cloudflare is the CDN this site runs
     * behind and it always carries exactly one address, whereas
     * `X-Forwarded-For` is a list any client can extend.
     */
    const DEFAULT_HEADERS = array(
        'HTTP_CF_CONNECTING_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_FLY_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
    );

    /** @var array<int,array{network:string,mask:string,bytes:int}> */
    private $trustedNetworks;

    /** @var string[] */
    private $headers;

    /** @var bool */
    private $anyProxy;

    /**
     * @param array       $trustedProxies CIDR list, e.g. ['127.0.0.1/32', '10.0.0.0/8', '2606:4700::/32'].
     *                                    An empty list disables forwarded headers entirely.
     * @param string[]|null $headers      Server keys to consult, in order.
     */
    public function __construct(array $trustedProxies = array(), array $headers = null)
    {
        $this->trustedNetworks = self::compileNetworks($trustedProxies);
        $this->headers = $headers !== null ? $headers : self::DEFAULT_HEADERS;
        $this->anyProxy = count($this->trustedNetworks) > 0;
    }

    /**
     * @param array $server Usually $_SERVER.
     *
     * @return string|null Null when the request carries nothing usable.
     */
    public function resolve(array $server)
    {
        $remote = self::normalise(self::value($server, 'REMOTE_ADDR'));

        // No trusted proxies configured: the peer that opened the socket is
        // the only address we can vouch for.
        if (!$this->anyProxy) {
            return $remote;
        }

        if ($remote === null || !$this->isTrusted($remote)) {
            // A direct, untrusted connection: forwarded headers may be forged
            // by that very client, so they are worthless.
            return $remote;
        }

        foreach ($this->headers as $header) {
            $raw = self::value($server, $header);
            if ($raw === null || $raw === '') {
                continue;
            }
            $candidate = $this->pickFromChain($raw);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return $remote;
    }

    /**
     * Whether the request reached us through a proxy we are allowed to trust.
     *
     * @param array $server
     *
     * @return bool
     */
    public function cameFromTrustedProxy(array $server): bool
    {
        if (!$this->anyProxy) {
            return false;
        }
        $remote = self::normalise(self::value($server, 'REMOTE_ADDR'));

        return $remote !== null && $this->isTrusted($remote);
    }

    /**
     * @param string $ip
     *
     * @return bool
     */
    public function isTrusted(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($this->trustedNetworks as $network) {
            if (strlen($packed) !== $network['bytes']) {
                continue;
            }
            $target = @inet_pton($network['network']);
            if ($target === false) {
                continue;
            }
            if (self::inSameNetwork($packed, $target, $network['mask'], $network['bytes'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk a comma separated chain from the right: everything our own proxies
     * appended is skipped, and the first address they did not write is the
     * client.
     *
     * @param string $raw
     *
     * @return string|null
     */
    private function pickFromChain(string $raw)
    {
        $parts = explode(',', $raw);
        $leftmost = null;

        for ($index = count($parts) - 1; $index >= 0; $index--) {
            // A proxy may append ", port" or obscure IPv6 with brackets.
            $candidate = self::normalise(trim($parts[$index]));
            if ($candidate === null) {
                continue;
            }
            if ($leftmost === null) {
                $leftmost = $candidate;
            }
            if (!$this->isTrusted($candidate)) {
                return $candidate;
            }
        }

        // Every hop is one of ours: the leftmost is the original client.
        return $leftmost;
    }

    /**
     * @param array  $server
     * @param string $key
     *
     * @return string|null
     */
    private static function value(array $server, string $key)
    {
        if (!isset($server[$key])) {
            return null;
        }
        if (is_array($server[$key])) {
            return null;
        }
        $value = trim((string) $server[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * Validate and canonicalise a single address, tolerating `[::1]:8080`
     * forms, an IPv4-mapped IPv6 string and stray whitespace.
     *
     * @param string|null $value
     *
     * @return string|null
     */
    private static function normalise($value)
    {
        if ($value === null) {
            return null;
        }
        $value = trim(str_replace(array("\r", "\n", "\0"), '', (string) $value));
        if ($value === '' || strlen($value) > 64) {
            return null;
        }

        // [2001:db8::1]:443 -> 2001:db8::1 ; 1.2.3.4:80 -> 1.2.3.4 (port
        // suffixes are not valid in REMOTE_ADDR but do appear in XFF).
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $value, $matches)) {
            $value = $matches[1];
        } elseif (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3}):\d+$/', $value, $matches)) {
            $value = $matches[1];
        }

        return filter_var($value, FILTER_VALIDATE_IP) === false ? null : $value;
    }

    /**
     * @param string[] $networks
     *
     * @return array<int,array{network:string,mask:string,bytes:int}>
     */
    private static function compileNetworks(array $networks): array
    {
        $compiled = array();

        foreach ($networks as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            $address = $entry;
            $bits = null;

            if (strpos($entry, '/') !== false) {
                list($address, $bits) = explode('/', $entry, 2);
                $address = trim($address);
                $bits = trim($bits);
                if (!ctype_digit($bits)) {
                    continue;
                }
                $bits = (int) $bits;
            }

            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $bits = $bits === null ? 32 : max(0, min(32, $bits));
                $compiled[] = array('network' => $address, 'mask' => (string) $bits, 'bytes' => 4);
                continue;
            }
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $bits = $bits === null ? 128 : max(0, min(128, $bits));
                $compiled[] = array('network' => $address, 'mask' => (string) $bits, 'bytes' => 16);
            }
        }

        return $compiled;
    }

    /**
     * Constant-time-ish CIDR comparison without bitwise string operators that
     * differ between 32- and 64-bit builds.
     *
     * @param string $a
     * @param string $b
     * @param string $bits
     * @param int    $bytes
     *
     * @return bool
     */
    private static function inSameNetwork(string $a, string $b, string $bits, int $bytes): bool
    {
        $bits = (int) $bits;
        for ($index = 0; $index < $bytes; $index++) {
            $mask = self::byteMask($bits - ($index * 8));
            if ($mask === 0) {
                return true;
            }
            if ((ord($a[$index]) & $mask) !== (ord($b[$index]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int $bits Bits of this byte that must match (0..8).
     *
     * @return int
     */
    private static function byteMask(int $bits): int
    {
        if ($bits <= 0) {
            return 0;
        }
        if ($bits >= 8) {
            return 0xFF;
        }

        return (0xFF << (8 - $bits)) & 0xFF;
    }
}
