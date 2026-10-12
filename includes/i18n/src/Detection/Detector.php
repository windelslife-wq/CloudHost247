<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * The automatic half of localisation.
 *
 * This class answers two questions from the request alone:
 *
 *   - which language does this visitor most likely read?
 *   - which currency should prices be shown in?
 *
 * It is deliberately *pure*: it never looks at stored preferences and never
 * writes anything. Choosing between a stored preference and a detected value
 * is Preferences' job, which keeps the precedence rule in one place:
 *
 *   explicit choice  >  account preference  >  detection  >  default
 *
 * Everything here degrades. No header, no geolocation source, an unknown
 * country, a country whose currency the shop does not sell in, a provider that
 * is down — each of those produces the next-best answer, and the last one is
 * the platform default. Detection never throws and never blocks a page load.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

use CloudHost247\I18n\Currency;
use CloudHost247\I18n\CurrencyRegistry;
use CloudHost247\I18n\Locale;
use CloudHost247\I18n\LocaleRegistry;

final class Detector
{
    /** @var LocaleRegistry */
    private $locales;

    /** @var CurrencyRegistry */
    private $currencies;

    /** @var RequestContext */
    private $request;

    /** @var GeoLocatorInterface */
    private $geo;

    /** @var CountryCurrencyMap */
    private $map;

    /** @var array<string,mixed> */
    private $options;

    /** @var callable|null */
    private $logger;

    /** @var GeoLocation|null */
    private $country;

    /** @var bool */
    private $countryResolved = false;

    /** @var DetectionResult|null */
    private $language;

    /** @var DetectionResult|null */
    private $currency;

    /**
     * @param LocaleRegistry       $locales
     * @param CurrencyRegistry     $currencies
     * @param RequestContext       $request
     * @param GeoLocatorInterface|null $geo     Null = geolocation disabled.
     * @param CountryCurrencyMap|null  $map
     * @param array                $options  use_region_hint (bool), log (bool).
     * @param callable|null        $logger
     */
    public function __construct(
        LocaleRegistry $locales,
        CurrencyRegistry $currencies,
        RequestContext $request,
        GeoLocatorInterface $geo = null,
        CountryCurrencyMap $map = null,
        array $options = array(),
        $logger = null
    ) {
        $this->locales = $locales;
        $this->currencies = $currencies;
        $this->request = $request;
        $this->geo = $geo !== null ? $geo : new NullGeoLocator();
        $this->map = $map !== null ? $map : new CountryCurrencyMap();
        $this->options = $options;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /* ------------------------------------------------------------- language */

    /**
     * The language this visitor most likely reads.
     *
     * @return DetectionResult Value is a Locale (never null): the default
     *                         locale when nothing supported was detected.
     */
    public function detectLanguage(): DetectionResult
    {
        if ($this->language !== null) {
            return $this->language;
        }

        $header = $this->request->acceptLanguage();
        $result = null;

        if ($header !== '') {
            $locale = AcceptLanguage::match($header, $this->locales);
            if ($locale !== null) {
                $result = new DetectionResult(
                    $locale,
                    DetectionResult::SOURCE_BROWSER,
                    'accept_language_match',
                    array(
                        'header'     => substr($header, 0, 200),
                        'matched'    => $locale->getCode(),
                        'preferred'  => array_slice(AcceptLanguage::preferredTags($header), 0, 6),
                    )
                );
            } else {
                $result = $this->defaultLanguage('no_supported_language', array(
                    'header'    => substr($header, 0, 200),
                    'preferred' => array_slice(AcceptLanguage::preferredTags($header), 0, 6),
                ));
            }
        } else {
            $result = $this->defaultLanguage('no_accept_language_header');
        }

        $this->language = $result;

        return $result;
    }

    /* ------------------------------------------------------------- currency */

    /**
     * The currency prices should be shown in.
     *
     * Priority, after stored preferences (which are applied by Preferences,
     * before detection ever runs):
     *
     *   1. the visitor's country, from a trusted geolocation source, mapped
     *      through the country/currency table and validated against the
     *      currencies WHMCS actually has enabled;
     *   2. optionally, the region subtag of the browser language (`en-GB`);
     *   3. the shop's configured default currency.
     *
     * @return DetectionResult Value is a Currency (never null).
     */
    public function detectCurrency(): DetectionResult
    {
        if ($this->currency !== null) {
            return $this->currency;
        }

        $result = $this->currencyFromCountry();

        if ($result === null && !empty($this->options['use_region_hint'])) {
            $result = $this->currencyFromRegionHint();
        }

        if ($result === null) {
            $result = new DetectionResult(
                $this->currencies->default(),
                DetectionResult::SOURCE_DEFAULT,
                $this->defaultCurrencyReason(),
                array(
                    'country'    => $this->country !== null ? $this->country->getCountry() : null,
                    'candidates' => $this->country !== null ? $this->map->candidates($this->country->getCountry()) : array(),
                )
            );
        }

        $this->currency = $result;

        return $result;
    }

    /* -------------------------------------------------------------- country */

    /**
     * The country geolocation produced, if any.
     *
     * @return GeoLocation|null
     */
    public function country()
    {
        if ($this->countryResolved) {
            return $this->country;
        }
        $this->countryResolved = true;

        $ip = $this->request->clientIp();
        if ($ip === null || $ip === '') {
            return null;
        }

        try {
            $this->country = $this->geo->locate($ip);
        } catch (\Throwable $e) {
            // A broken geolocation provider must never break a page.
            $this->country = null;
            $this->log('geolocation failed: ' . $e->getMessage());
        }

        return $this->country;
    }

    /* ---------------------------------------------------------- explanation */

    /**
     * Everything known about how this request was localised.
     *
     * Surfaced in the switcher markup (`data-ch247-detection`), in the admin
     * report and in tests.
     *
     * @return array<string,mixed>
     */
    public function explain(): array
    {
        $language = $this->detectLanguage();
        $currency = $this->detectCurrency();
        $country = $this->country();

        return array(
            'language' => array_merge($language->toArray(), array(
                'code' => $language->getValue() instanceof Locale ? $language->getValue()->getCode() : null,
            )),
            'currency' => array_merge($currency->toArray(), array(
                'code' => $currency->getValue() instanceof Currency ? $currency->getValue()->getCode() : null,
                'id'   => $currency->getValue() instanceof Currency ? $currency->getValue()->getId() : null,
            )),
            'country'  => $country !== null ? $country->toArray() : null,
            'ip'       => $this->hashedIp(),
            'trusted_proxy' => $this->request->cameFromTrustedProxy(),
            'geo_source'    => $this->geo->sourceName(),
            'accept_language' => substr($this->request->acceptLanguage(), 0, 200),
        );
    }

    /**
     * A short, non-reversible identifier for the client IP.
     *
     * Logs and admin reports need to tell two visitors apart; they do not need
     * the address itself. Hashing keeps this feature from turning into a
     * location log.
     *
     * @return string|null
     */
    public function hashedIp()
    {
        $ip = $this->request->clientIp();

        return $ip === null ? null : substr(hash('sha256', 'ch247-detection|' . $ip), 0, 12);
    }

    /* ------------------------------------------------------------- internals */

    /**
     * @return DetectionResult|null
     */
    private function currencyFromCountry()
    {
        $country = $this->country();
        if ($country === null) {
            return null;
        }

        $candidates = $this->map->candidates($country->getCountry());
        $currency = $this->map->resolve($country->getCountry(), $this->currencies);

        if ($currency === null) {
            // Known country, but the shop does not sell in its currency: fall
            // through to the default rather than guess a neighbour's currency.
            $this->log(sprintf(
                'country %s maps to %s, none of which are enabled',
                $country->getCountry(),
                $candidates ? implode('/', $candidates) : '(no currency)'
            ));

            return null;
        }

        return new DetectionResult(
            $currency,
            DetectionResult::SOURCE_GEOLOCATION,
            'country_currency',
            array(
                'country'    => $country->getCountry(),
                'candidates' => $candidates,
                'confidence' => $country->getConfidence(),
                'ip_hash'    => $this->hashedIp(),
            )
        );
    }

    /**
     * `en-GB` also tells us something about the currency, weakly.
     *
     * Off by default: it sits *below* geolocation in the documented priority
     * order and is only enabled deliberately, because a language tag is a
     * statement about language, not about where the visitor is paying from.
     *
     * @return DetectionResult|null
     */
    private function currencyFromRegionHint()
    {
        $region = AcceptLanguage::preferredRegion($this->request->acceptLanguage());
        if ($region === null) {
            return null;
        }

        $currency = $this->map->resolve($region, $this->currencies);
        if ($currency === null) {
            return null;
        }

        return new DetectionResult(
            $currency,
            DetectionResult::SOURCE_REGION_HINT,
            'locale_region_hint',
            array('region' => $region, 'candidates' => $this->map->candidates($region))
        );
    }

    /**
     * Why the default currency is in use — the one line an administrator needs
     * when a visitor reports "it showed the wrong currency".
     *
     * @return string
     */
    private function defaultCurrencyReason(): string
    {
        if ($this->country === null) {
            return $this->request->clientIp() === null ? 'no_client_ip' : 'no_geolocation';
        }
        if (!$this->map->has($this->country->getCountry())) {
            return 'no_currency_for_country';
        }

        return 'no_enabled_currency_for_country';
    }

    /**
     * @param string               $reason
     * @param array<string,mixed>  $evidence
     *
     * @return DetectionResult
     */
    private function defaultLanguage(string $reason, array $evidence = array()): DetectionResult
    {
        return new DetectionResult($this->locales->getDefault(), DetectionResult::SOURCE_DEFAULT, $reason, $evidence);
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
