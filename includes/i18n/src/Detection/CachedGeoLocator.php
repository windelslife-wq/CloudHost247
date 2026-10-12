<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Caching decorator around any geolocation source.
 *
 * Positive answers go through the configured cache (a file cache in
 * production, an array in tests), so the same visitor does not trigger an
 * outbound lookup on every page view.
 *
 * Negative answers are remembered for the current request only. They are not
 * written to the shared cache: "this IP is unknown" is cheap to re-derive and
 * is not worth a growing file of misses, while remembering it within one page
 * load still stops the switcher, the price presenter and the notice from each
 * re-running the lookup.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class CachedGeoLocator implements GeoLocatorInterface
{
    /** @var GeoLocatorInterface */
    private $inner;

    /** @var GeoCacheInterface */
    private $cache;

    /** @var int */
    private $ttl;

    /** @var array<string,bool> IPs already answered "unknown" this request. */
    private $misses = array();

    /**
     * @param GeoLocatorInterface $inner
     * @param GeoCacheInterface   $cache
     * @param int                 $ttl    Seconds a positive answer is reused.
     */
    public function __construct(GeoLocatorInterface $inner, GeoCacheInterface $cache, int $ttl = 86400)
    {
        $this->inner = $inner;
        $this->cache = $cache;
        $this->ttl = max(60, $ttl);
    }

    /**
     * @param string $ip
     *
     * @return GeoLocation|null
     */
    public function locate(string $ip)
    {
        if (isset($this->misses[$ip])) {
            return null;
        }

        $cached = $this->cache->read($ip);
        if ($cached !== null) {
            return $cached;
        }

        $location = $this->inner->locate($ip);
        if ($location === null) {
            $this->misses[$ip] = true;

            return null;
        }

        $this->cache->write($ip, $location, $this->ttl);

        return $location;
    }

    /**
     * @return string
     */
    public function sourceName(): string
    {
        return $this->inner->sourceName();
    }
}
