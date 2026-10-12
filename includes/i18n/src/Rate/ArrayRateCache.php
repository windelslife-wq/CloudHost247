<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * In-memory rate cache: the default for tests, cron and any request where a
 * writable cache directory is unavailable.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Rate;

use CloudHost247\I18n\Contracts\RateCacheInterface;
use CloudHost247\I18n\RateTable;

final class ArrayRateCache implements RateCacheInterface
{
    /** @var RateTable|null */
    private $table = null;

    public function read()
    {
        return $this->table;
    }

    public function write(RateTable $table)
    {
        $this->table = $table;
    }

    public function clear()
    {
        $this->table = null;
    }
}
