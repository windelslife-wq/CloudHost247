<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Rate source built from an explicit table: used by tests, by the staging
 * harness, and by installs that maintain rates manually.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Rate;

use CloudHost247\I18n\Contracts\RateSourceInterface;
use CloudHost247\I18n\RateTable;

final class StaticRateSource implements RateSourceInterface
{
    /** @var RateTable */
    private $table;

    /** @var int */
    private $calls = 0;

    /**
     * @param string            $base
     * @param array             $rates  code => rate
     * @param string            $source
     * @param string|null       $asOf
     * @param int|null          $retrievedAt
     */
    public function __construct(string $base, array $rates, string $source = 'static fixture', $asOf = null, $retrievedAt = null)
    {
        $this->table = new RateTable($base, $rates, $source, $asOf, $retrievedAt);
    }

    public function table(): RateTable
    {
        $this->calls++;

        return $this->table;
    }

    /**
     * How many times the source was actually asked — lets a test prove the
     * cache is doing its job.
     *
     * @return int
     */
    public function callCount(): int
    {
        return $this->calls;
    }
}
