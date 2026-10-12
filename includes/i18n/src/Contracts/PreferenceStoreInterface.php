<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Storage contract for the two *independent* customer preferences this
 * subsystem owns: the display language and the display currency.
 *
 * Implementations are deliberately tiny so the same selector logic can run
 * against a real WHMCS session, a client account row, or a plain array in a
 * test. Nothing here may assume a WHMCS bootstrap.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Contracts;

interface PreferenceStoreInterface
{
    /**
     * Read a stored preference.
     *
     * @param string $key    Preference key, e.g. "language" or "currency".
     * @param mixed  $default Value returned when nothing is stored.
     *
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Write a preference. Passing null clears it.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function set(string $key, $value);

    /**
     * Whether this store is authoritative enough to be written back to.
     *
     * Read-only sources (for example the WHMCS system default, or the
     * client's account row when nobody is logged in) return false so the
     * preference manager keeps looking for somewhere to persist the choice.
     *
     * @return bool
     */
    public function isWritable(): bool;
}
