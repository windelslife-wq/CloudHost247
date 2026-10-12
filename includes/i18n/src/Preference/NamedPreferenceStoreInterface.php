<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * A preference store that can say where its value came from.
 *
 * Detection needs provenance, not just a value: "the customer chose EUR" and
 * "EUR came from their account row" are different facts with different
 * authority. A store that implements this interface can be asked which one it
 * is, so the UI can say "saved on your account" instead of a vague "detected".
 *
 * Optional: stores that do not implement it are treated as anonymous.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Preference;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;

interface NamedPreferenceStoreInterface extends PreferenceStoreInterface
{
    /**
     * Stable identifier for this storage location, e.g. "session", "account",
     * "cookie". Used for provenance reporting only — never for logic.
     *
     * @return string
     */
    public function storeName(): string;
}
