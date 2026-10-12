<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Tries several geolocation sources in order and returns the first country
 * any of them can vouch for.
 *
 * Every source is optional and independently fallible: a locator that throws,
 * times out or returns nonsense is skipped, and the chain still works. The
 * whole chain returning null is a normal, expected outcome — it means "use the
 * configured default currency".
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class ChainedGeoLocator implements GeoLocatorInterface
{
    /** @var GeoLocatorInterface[] */
    private $locators;

    /** @var callable|null */
    private $logger;

    /** @var string[] Sources that failed, for admin reporting. */
    private $failures = array();

    /**
     * @param GeoLocatorInterface[] $locators Highest priority first.
     * @param callable|null         $logger   fn(string $message): void
     */
    public function __construct(array $locators, $logger = null)
    {
        $this->locators = array_values(array_filter($locators, function ($locator) {
            return $locator instanceof GeoLocatorInterface;
        }));
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /**
     * @param string $ip
     *
     * @return GeoLocation|null
     */
    public function locate(string $ip)
    {
        foreach ($this->locators as $locator) {
            try {
                $location = $locator->locate($ip);
            } catch (\Throwable $e) {
                // One broken provider must never break the page: record it and
                // fall through to the next source (or to the default currency).
                $this->failures[] = $locator->sourceName();
                $this->log($locator->sourceName() . ' failed: ' . $e->getMessage());

                continue;
            }
            if ($location !== null) {
                return $location;
            }
        }

        return null;
    }

    /**
     * @return string
     */
    public function sourceName(): string
    {
        $names = array();
        foreach ($this->locators as $locator) {
            $names[] = $locator->sourceName();
        }

        return implode(', ', $names);
    }

    /**
     * @return string[]
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
