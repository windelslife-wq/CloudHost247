<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Cache contract for geolocation answers.
 *
 * Geolocation results change slowly (a home broadband IP is stable for weeks)
 * and every uncached lookup costs an outbound request on the visitor's first
 * page load, so the answers are cached. A cache miss is never an error: the
 * locator is simply asked again.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

interface GeoCacheInterface
{
    /**
     * @param string $key Cache key (an IP, hashed by the implementation).
     *
     * @return GeoLocation|null
     */
    public function read(string $key);

    /**
     * @param string       $key
     * @param GeoLocation  $location
     * @param int          $ttl Seconds.
     *
     * @return void
     */
    public function write(string $key, GeoLocation $location, int $ttl);

    /**
     * @return void
     */
    public function clear();
}
