<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Contract for "where do exchange rates come from".
 *
 * The only rule that really matters: an implementation may return an empty
 * table when it has nothing trustworthy to say. It may never invent,
 * interpolate or guess a rate. Callers treat a missing rate as "cannot
 * convert" and fall back to the catalogue price in the transaction currency.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Contracts;

use CloudHost247\I18n\RateTable;

interface RateSourceInterface
{
    /**
     * Fetch the current rate table.
     *
     * Implementations must be cheap when the underlying data has not changed
     * (wrap them in CachedRateSource). The returned table carries its own
     * provenance and timestamp so the UI can say where a conversion came from
     * and how old it is.
     *
     * @return RateTable
     */
    public function table(): RateTable;
}
