<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Per-request geolocation cache. Stops one page load asking the same question
 * twice (the switcher, the price presenter and the notice all ask) and is the
 * cache the test suite uses.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class ArrayGeoCache implements GeoCacheInterface
{
    /** @var array<string,array{location:GeoLocation,expires:int}> */
    private $values = array();

    /** @var int */
    private $hits = 0;

    /** @var int */
    private $misses = 0;

    /**
     * @param string $key
     *
     * @return GeoLocation|null
     */
    public function read(string $key)
    {
        if (!isset($this->values[$key])) {
            $this->misses++;

            return null;
        }
        if ($this->values[$key]['expires'] < time()) {
            unset($this->values[$key]);
            $this->misses++;

            return null;
        }
        $this->hits++;

        return $this->values[$key]['location'];
    }

    /**
     * @param string      $key
     * @param GeoLocation $location
     * @param int         $ttl
     *
     * @return void
     */
    public function write(string $key, GeoLocation $location, int $ttl): void
    {
        $this->values[$key] = array('location' => $location, 'expires' => time() + max(1, $ttl));
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->values = array();
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * @return int
     */
    public function hits(): int
    {
        return $this->hits;
    }

    /**
     * @return int
     */
    public function misses(): int
    {
        return $this->misses;
    }
}
