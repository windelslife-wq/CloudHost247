<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Fixture locator: a fixed IP (or CIDR) -> country table. Used by the test
 * suite, by the staging preview and by sites that pin a known network (an
 * office, a point-of-sale range) to a country.
 *
 * Never used in production unless a deployer deliberately configures it.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class ArrayGeoLocator implements GeoLocatorInterface
{
    /** @var array<string,string> Exact IP => country. */
    private $addresses;

    /** @var array<string,string> CIDR => country. */
    private $networks;

    /** @var string */
    private $source;

    /** @var int */
    private $lookups;

    /**
     * @param array  $map    ['203.0.113.10' => 'NG', '198.51.100.0/24' => 'GB'].
     * @param string $source
     */
    public function __construct(array $map, string $source = 'fixture')
    {
        $this->addresses = array();
        $this->networks = array();
        $this->source = $source;

        foreach ($map as $key => $country) {
            $country = strtoupper(trim((string) $country));
            if (strpos((string) $key, '/') !== false) {
                $this->networks[(string) $key] = $country;
            } else {
                $this->addresses[(string) $key] = $country;
            }
        }
    }

    /**
     * @param string $ip
     *
     * @return GeoLocation|null
     */
    public function locate(string $ip)
    {
        $this->lookups++;

        if (isset($this->addresses[$ip])) {
            return GeoLocation::fromValue($this->addresses[$ip], $this->source, 0.9);
        }

        foreach ($this->networks as $cidr => $country) {
            if (self::inRange($ip, (string) $cidr)) {
                return GeoLocation::fromValue($country, $this->source, 0.9);
            }
        }

        return null;
    }

    /**
     * @return string
     */
    public function sourceName(): string
    {
        return $this->source;
    }

    /**
     * How many times locate() was called — lets a test prove the cache is
     * actually short-circuiting the network call.
     *
     * @return int
     */
    public function lookupCount(): int
    {
        return $this->lookups;
    }

    /**
     * @param string $ip
     * @param string $cidr
     *
     * @return bool
     */
    private static function inRange(string $ip, string $cidr): bool
    {
        list($subnet, $bits) = array_pad(explode('/', $cidr, 2), 2, null);
        $ipPacked = @inet_pton($ip);
        $subnetPacked = @inet_pton((string) $subnet);
        if ($ipPacked === false || $subnetPacked === false) {
            return false;
        }
        $bytes = strlen($subnetPacked);
        if (strlen($ipPacked) !== $bytes) {
            return false;
        }
        $bits = $bits === null || !ctype_digit((string) $bits) ? $bytes * 8 : (int) $bits;

        for ($index = 0; $index < $bytes; $index++) {
            $mask = max(0, min(8, $bits - ($index * 8)));
            if ($mask === 0) {
                return true;
            }
            if ($mask === 8) {
                if ($ipPacked[$index] !== $subnetPacked[$index]) {
                    return false;
                }
                continue;
            }
            $byte = (0xFF << (8 - $mask)) & 0xFF;
            if ((ord($ipPacked[$index]) & $byte) !== (ord($subnetPacked[$index]) & $byte)) {
                return false;
            }
        }

        return true;
    }
}
