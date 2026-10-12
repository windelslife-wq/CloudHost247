<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Exchange rates sourced from WHMCS itself.
 *
 * WHMCS maintains `tblcurrencies.rate` from its own configured feed
 * (Configuration → System Settings → Currencies → Update Exchange Rates, and
 * the `UpdateCurrencyRates` automation task). Reusing those values means:
 *
 *  - one source of truth for the whole platform;
 *  - no third-party API key to manage or leak;
 *  - no rate this module could be accused of inventing.
 *
 * Native WHMCS automation refreshes the feed. The optional addon cron only
 * warms a cache of this configured table; it does not invent an API action.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Whmcs;

use CloudHost247\I18n\Contracts\RateSourceInterface;
use CloudHost247\I18n\RateTable;

final class WhmcsRateSource implements RateSourceInterface
{
    /** @var callable|null fn(): iterable<array|object> */
    private $reader;

    /**
     * @param callable|null $reader Injectable row reader (tests, or a site
     *                              that keeps rates somewhere else).
     */
    public function __construct($reader = null)
    {
        $this->reader = $reader;
    }

    public function table(): RateTable
    {
        $rates = array();
        $defaultCode = '';
        $updatedAt = null;

        foreach ($this->read() as $row) {
            $row = $this->toArray($row);
            if ($row === null) {
                continue;
            }

            $code = strtoupper(trim(isset($row['code']) ? (string) $row['code'] : ''));
            if ($code === '') {
                continue;
            }

            $rate = isset($row['rate']) && $row['rate'] !== null ? (float) $row['rate'] : 0.0;
            if ($rate > 0) {
                $rates[$code] = $rate;
            }

            if (!empty($row['default'])) {
                $defaultCode = $code;
            }

            // WHMCS 8.x exposes an updated_at column; older installs do not,
            // in which case the cache's retrieved_at is the honest timestamp.
            if (!empty($row['updated_at'])) {
                $stamp = (string) $row['updated_at'];
                if ($updatedAt === null || strcmp($stamp, $updatedAt) > 0) {
                    $updatedAt = $stamp;
                }
            }
        }

        if ($defaultCode === '') {
            // Without a default currency there is no base, and therefore no
            // trustworthy conversion. Return an empty table so callers fall
            // back to catalogue prices rather than guessing a base.
            return new RateTable('', array(), $this->sourceName(), $updatedAt);
        }

        return new RateTable($defaultCode, $rates, $this->sourceName(), $updatedAt);
    }

    public function sourceName(): string
    {
        return 'WHMCS currency feed (tblcurrencies.rate)';
    }

    /**
     * @return array
     */
    private function read(): array
    {
        if ($this->reader !== null) {
            $rows = call_user_func($this->reader);

            return is_array($rows) ? $rows : iterator_to_array($rows);
        }

        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return array();
        }

        try {
            $rows = \WHMCS\Database\Capsule::table('tblcurrencies')->get();

            return is_array($rows) ? $rows : iterator_to_array($rows);
        } catch (\Throwable $e) {
            return array();
        }
    }

    /**
     * @param mixed $row
     *
     * @return array|null
     */
    private function toArray($row)
    {
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row)) {
            return get_object_vars($row);
        }

        return null;
    }
}
