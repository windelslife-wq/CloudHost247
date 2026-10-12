<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * The locator used when no geolocation source is configured: it knows
 * nothing, and says so.
 *
 * This is the safe default. Detection without a trusted geolocation source
 * still works for language (from the browser header) and simply leaves the
 * currency on the shop default, which is exactly what the requirement asks
 * for — never an invented country, and never a guessed exchange rate.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class NullGeoLocator implements GeoLocatorInterface
{
    /**
     * @param string $ip
     *
     * @return GeoLocation|null
     */
    public function locate(string $ip)
    {
        return null;
    }

    /**
     * @return string
     */
    public function sourceName(): string
    {
        return 'not configured';
    }
}
