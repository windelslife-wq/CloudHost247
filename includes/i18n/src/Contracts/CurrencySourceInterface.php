<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Contract for "where do the currencies come from". The production
 * implementation reads WHMCS's own `tblcurrencies` table so the switcher can
 * never offer a currency that WHMCS cannot actually transact in.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Contracts;

use CloudHost247\I18n\Currency;

interface CurrencySourceInterface
{
    /**
     * Every currency row known to the source, keyed by id, in display order.
     *
     * @return Currency[]
     */
    public function all(): array;

    /**
     * Currencies the shop actually sells in. A currency with an empty/invalid
     * exchange rate is still sellable (WHMCS stores per-currency prices), so
     * this is "not deleted", not "has a rate".
     *
     * @return Currency[]
     */
    public function enabled(): array;

    /**
     * Look up by numeric id.
     *
     * @param int $id
     *
     * @return Currency|null
     */
    public function findById(int $id);

    /**
     * Look up by ISO 4217 code (case-insensitive).
     *
     * @param string $code
     *
     * @return Currency|null
     */
    public function findByCode(string $code);

    /**
     * The currency WHMCS will charge in when nothing else is selected.
     *
     * @return Currency
     */
    public function default(): Currency;

    /**
     * Human-readable provenance string, surfaced in the switcher tooltip and
     * in the "rates last updated" note.
     *
     * @return string
     */
    public function sourceName(): string;
}
