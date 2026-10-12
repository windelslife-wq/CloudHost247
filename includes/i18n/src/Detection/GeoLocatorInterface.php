<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Contract for "which country is this IP in".
 *
 * The one rule that matters: an implementation may return null. A locator that
 * is not configured, that is unreachable, or that cannot make sense of the
 * answer must say "I don't know" rather than guess a country. Callers then
 * fall back to the configured default currency, which is always safe.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

interface GeoLocatorInterface
{
    /**
     * @param string $ip A syntactically valid IP address.
     *
     * @return GeoLocation|null Null when the country is unknown.
     */
    public function locate(string $ip);

    /**
     * Human-readable provenance for admin reporting and for the "why this
     * currency?" explanation shown to support staff.
     *
     * @return string
     */
    public function sourceName(): string;
}
