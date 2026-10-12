<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Wraps a rate source with a TTL cache and a stale-while-error fallback.
 *
 * Behaviour, in order:
 *
 *  1. fresh cache hit          -> return it, no network/db work
 *  2. stale or empty cache     -> try the source
 *  3. source succeeds          -> store and return
 *  4. source fails and we have a stale table -> return the stale table,
 *                                because a slightly old configured rate is
 *                                better than no price at all
 *  5. nothing anywhere         -> an empty RateTable, never a guessed rate
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Rate;

use CloudHost247\I18n\Contracts\RateCacheInterface;
use CloudHost247\I18n\Contracts\RateSourceInterface;
use CloudHost247\I18n\RateTable;

final class CachedRateSource implements RateSourceInterface
{
    /** @var RateSourceInterface */
    private $inner;

    /** @var RateCacheInterface */
    private $cache;

    /** @var int */
    private $ttl;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $logger;

    /** @var bool */
    private $fetchedThisRequest = false;

    /**
     * @param RateSourceInterface  $inner
     * @param RateCacheInterface   $cache
     * @param int                  $ttl    Seconds a table stays fresh.
     * @param callable|null        $clock  fn(): int — injectable for tests.
     * @param callable|null        $logger fn(string): void
     */
    public function __construct(
        RateSourceInterface $inner,
        RateCacheInterface $cache,
        int $ttl = RateTable::DEFAULT_TTL,
        $clock = null,
        $logger = null
    ) {
        $this->inner = $inner;
        $this->cache = $cache;
        $this->ttl = $ttl > 0 ? $ttl : RateTable::DEFAULT_TTL;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function table(): RateTable
    {
        $now = $this->now();
        $cached = $this->cache->read();

        if ($cached instanceof RateTable && !$cached->isStale($this->ttl, $now)) {
            return $cached;
        }

        try {
            $fresh = $this->inner->table();
            $this->fetchedThisRequest = true;

            if (!$fresh->has($fresh->getBase())) {
                throw new \RuntimeException('rate source returned a table without its own base currency');
            }

            // Stamp the table with when *we* cached it so the TTL measures
            // cache age, not the source's own clock.
            $stamped = $fresh->withRetrievedAt($now);
            $this->cache->write($stamped);

            return $stamped;
        } catch (\Throwable $e) {
            $this->log('rate refresh failed: ' . $e->getMessage());

            if ($cached instanceof RateTable) {
                return $cached;
            }

            // Nothing trustworthy to show. An empty table means "cannot
            // convert", which the UI handles; inventing a rate does not.
            return new RateTable('', array(), 'unavailable', null, $now);
        }
    }

    /**
     * Force the next table() to bypass the cache (admin "refresh rates").
     *
     * @return void
     */
    public function invalidate(): void
    {
        $this->cache->clear();
    }

    /**
     * @return bool
     */
    public function fetchedThisRequest(): bool
    {
        return $this->fetchedThisRequest;
    }

    /**
     * @return int
     */
    private function now(): int
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
