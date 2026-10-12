<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Cache contract for exchange-rate tables. Exists so the production file
 * cache can be swapped for an in-memory array in tests without touching the
 * caching policy itself.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Contracts;

use CloudHost247\I18n\RateTable;

interface RateCacheInterface
{
    /**
     * @return RateTable|null
     */
    public function read();

    /**
     * Persist a table so a later request can reuse it.
     *
     * @param RateTable $table
     *
     * @return void
     */
    public function write(RateTable $table);

    /**
     * Drop the cached table (used by the "refresh rates" admin action and by
     * tests that need a cold cache).
     *
     * @return void
     */
    public function clear();
}
