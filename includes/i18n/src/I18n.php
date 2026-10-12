<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * The single entry point everything else talks to.
 *
 * Typical use inside WHMCS (the hook does this for you):
 *
 *     require_once __DIR__ . '/includes/i18n/autoload.php';
 *     $i18n = CloudHost247\I18n\I18n::boot([
 *         'root'            => __DIR__,
 *         'default_locale'  => 'en',
 *         'route_map'       => ['/hosting' => ['fr' => '/hebergement']],
 *     ]);
 *     $i18n->applyRequest($_GET);
 *     $i18n->translator()->trans('nav_hosting');
 *
 * The class is deliberately constructible with zero WHMCS involvement: pass a
 * StaticCurrencySource and an ArrayPreferenceStore and the whole subsystem
 * runs in a test, a cron, or the staging harness.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

use CloudHost247\I18n\Contracts\CurrencySourceInterface;
use CloudHost247\I18n\Contracts\PreferenceStoreInterface;
use CloudHost247\I18n\Contracts\RateSourceInterface;
use CloudHost247\I18n\Detection\ArrayGeoCache;
use CloudHost247\I18n\Detection\CachedGeoLocator;
use CloudHost247\I18n\Detection\ChainedGeoLocator;
use CloudHost247\I18n\Detection\ClientIp;
use CloudHost247\I18n\Detection\CountryCurrencyMap;
use CloudHost247\I18n\Detection\Detector;
use CloudHost247\I18n\Detection\FileGeoCache;
use CloudHost247\I18n\Detection\GeoLocatorInterface;
use CloudHost247\I18n\Detection\HeaderGeoLocator;
use CloudHost247\I18n\Detection\HttpGeoLocator;
use CloudHost247\I18n\Detection\RequestContext;
use CloudHost247\I18n\Preference\ArrayPreferenceStore;
use CloudHost247\I18n\Preference\ChainedPreferenceStore;
use CloudHost247\I18n\Preference\CookiePreferenceStore;
use CloudHost247\I18n\Preference\SessionPreferenceStore;
use CloudHost247\I18n\Rate\ArrayRateCache;
use CloudHost247\I18n\Rate\CachedRateSource;
use CloudHost247\I18n\Whmcs\WhmcsClientPreferenceStore;
use CloudHost247\I18n\Whmcs\WhmcsCurrencySource;
use CloudHost247\I18n\Whmcs\WhmcsRateSource;

final class I18n
{
    /** Relative location of the translation catalogs. */
    const CATALOG_DIR = 'lang/site';

    /** @var I18n|null */
    private static $instance;

    /** @var array<string,mixed> */
    private $config;

    /** @var CatalogRepository */
    private $catalogs;

    /** @var LocaleRegistry */
    private $locales;

    /** @var Translator */
    private $translator;

    /** @var CurrencyRegistry */
    private $currencies;

    /** @var CurrencySelector */
    private $currencySelector;

    /** @var Preferences */
    private $preferences;

    /** @var PreferenceStoreInterface */
    private $store;

    /** @var Detector|null */
    private $detector;

    /** @var RequestContext */
    private $request;

    /** @var RateSourceInterface|null */
    private $rateSource;

    /** @var RateTable|null */
    private $rateTable;

    /** @var PriceResolver|null */
    private $priceResolver;

    /** @var PageContent|null */
    private $pageContent;

    /** @var callable|null */
    private $logger;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->logger = isset($config['logger']) && is_callable($config['logger']) ? $config['logger'] : null;

        $defaultLocale = isset($config['default_locale']) ? (string) $config['default_locale'] : 'en';

        $this->catalogs = isset($config['catalogs']) && $config['catalogs'] instanceof CatalogRepository
            ? $config['catalogs']
            : new CatalogRepository($this->catalogDirectory());

        $extra = isset($config['locales']) && is_array($config['locales']) ? $config['locales'] : array();
        $this->locales = new LocaleRegistry($this->catalogs, $defaultLocale, $extra);

        $this->translator = new Translator(
            $this->locales,
            $this->catalogs,
            !isset($config['escape']) || (bool) $config['escape']
        );

        $source = isset($config['currency_source']) && $config['currency_source'] instanceof CurrencySourceInterface
            ? $config['currency_source']
            : new WhmcsCurrencySource(isset($config['currency_reader']) ? $config['currency_reader'] : null);
        $this->currencies = new CurrencyRegistry($source);

        $this->pageContent = isset($config['page_content']) && $config['page_content'] instanceof PageContent
            ? $config['page_content']
            : new PageContent($this->rootDirectory());

        $options = isset($config['detection']) && is_array($config['detection']) ? $config['detection'] : array();
        $this->request = isset($config['request']) && $config['request'] instanceof RequestContext
            ? $config['request']
            : RequestContext::fromGlobals($this->trustedProxies($options, $config));
        $this->store = $this->buildStore($config);
        $this->currencySelector = new CurrencySelector($this->currencies, $this->store);
        $this->preferences = new Preferences(
            $this->locales,
            $this->currencySelector,
            $this->store,
            $this->buildDetector($config, $this->request)
        );

        if (isset($config['rate_source'])) {
            $this->rateSource = $config['rate_source'] instanceof RateSourceInterface
                ? $config['rate_source']
                : null;
        } else {
            $this->rateSource = new CachedRateSource(
                new WhmcsRateSource(isset($config['rate_reader']) ? $config['rate_reader'] : null),
                isset($config['rate_cache']) ? $config['rate_cache'] : new ArrayRateCache(),
                isset($config['rate_ttl']) ? (int) $config['rate_ttl'] : RateTable::DEFAULT_TTL,
                isset($config['clock']) ? $config['clock'] : null,
                $this->logger
            );
        }
    }

    /**
     * Boot (or return) the shared instance.
     *
     * @param array $config
     *
     * @return self
     */
    public static function boot(array $config = array())
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * Replace the shared instance — used by tests and by the staging harness.
     *
     * @param array $config
     *
     * @return self
     */
    public static function reset(array $config = array())
    {
        self::$instance = new self($config);

        return self::$instance;
    }

    /**
     * @return void
     */
    public static function forget()
    {
        self::$instance = null;
    }

    // ------------------------------------------------------------- request --

    /**
     * Apply `?language=` / `?currency=` for this request.
     *
     * @param array $request
     *
     * @return array{language:SelectionResult|null,currency:SelectionResult|null}
     */
    public function applyRequest(array $request): array
    {
        // Explicit parameters first: they are the customer overriding
        // everything, and detection must not be able to undo them.
        $applied = $this->preferences->applyRequest($request);

        // Resolving both preferences now runs detection at most once per
        // request, in one place, before anything has been rendered.
        $this->translator->setLocale($this->preferences->getLanguage());
        $this->preferences->getCurrency();

        return $applied;
    }

    /**
     * The URI the switcher should build links against.
     *
     * @param array|null $server
     *
     * @return string
     */
    public function currentUri(array $server = null): string
    {
        $server = $server !== null ? $server : $_SERVER;
        $uri = isset($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : '/';

        return EquivalentUrl::canonical($uri);
    }

    // ------------------------------------------------------------ accessors --

    public function catalogs(): CatalogRepository
    {
        return $this->catalogs;
    }

    public function locales(): LocaleRegistry
    {
        return $this->locales;
    }

    public function translator(): Translator
    {
        return $this->translator;
    }

    public function currencies(): CurrencyRegistry
    {
        return $this->currencies;
    }

    public function currencySelector(): CurrencySelector
    {
        return $this->currencySelector;
    }

    public function preferences(): Preferences
    {
        return $this->preferences;
    }

    public function store(): PreferenceStoreInterface
    {
        return $this->store;
    }

    /**
     * The automatic-detection engine, or null when detection is disabled.
     *
     * @return Detector|null
     */
    public function detector()
    {
        return $this->detector;
    }

    /**
     * The captured request detection reads from.
     *
     * @return RequestContext
     */
    public function request(): RequestContext
    {
        return $this->request;
    }

    /**
     * Why this visitor is seeing this language and this currency.
     *
     * @return array<string,mixed>
     */
    public function detection(): array
    {
        return $this->preferences->explain();
    }

    /**
     * Send any cookies the preference stores queued.
     *
     * Must be called before output is produced — the hook does it at the end
     * of the ClientAreaPage handler.
     *
     * @return array<string,array<string,mixed>>
     */
    public function flushCookies(): array
    {
        return $this->preferences->flushCookies();
    }

    /**
     * @param string $localeCode
     *
     * @return Formatter
     */
    public function formatter(string $localeCode = null): Formatter
    {
        $localeCode = $localeCode !== null ? $localeCode : $this->preferences->getLanguage();

        return new Formatter($this->locales->resolve($localeCode)->getIntlTag());
    }

    /**
     * Rates, fetched at most once per request and cached across requests.
     *
     * @return RateTable|null
     */
    public function rates()
    {
        if ($this->rateTable === null && $this->rateSource !== null) {
            $this->rateTable = $this->rateSource->table();
        }

        return $this->rateTable;
    }

    /**
     * @return PriceResolver
     */
    public function prices(): PriceResolver
    {
        if ($this->priceResolver === null) {
            $this->priceResolver = new PriceResolver($this->currencies, $this->rates(), $this->logger);
        }

        return $this->priceResolver;
    }

    /** Forget the cached rate table after a WHMCS rate refresh. */
    public function clearRateCache(): void
    {
        $this->rateTable = null;
        if ($this->rateSource instanceof CachedRateSource) {
            $this->rateSource->invalidate();
        }
        $this->priceResolver = null;
    }

    /**
     * Translation layer for the content arrays the root landing pages build.
     *
     * @return PageContent
     */
    public function pages(): PageContent
    {
        return $this->pageContent;
    }

    /**
     * Build the view-model the switcher templates render.
     *
     * Convenience wrapper so a hook or a controller needs one call rather than
     * assembling SwitcherViewModel by hand.
     *
     * @param array          $routeMap            Localised slugs, e.g. ['/hosting' => ['fr' => '/hebergement']].
     * @param string         $currentUri          The clean URI to stay on.
     * @param Currency|null  $transactionCurrency Currency an invoice is locked to.
     *
     * @return array<string,mixed>
     */
    public function switcher(array $routeMap = array(), string $currentUri = '', Currency $transactionCurrency = null): array
    {
        $view = new SwitcherViewModel(
            $this->preferences(),
            $this->translator(),
            $this->currencySelector(),
            $currentUri !== '' ? $currentUri : $this->currentUri(),
            $routeMap ?: (isset($this->config['route_map']) ? $this->config['route_map'] : array()),
            $this->rates(),
            isset($this->config['language_value']) ? $this->config['language_value'] : null
        );

        return $view->toArray($transactionCurrency);
    }

    public function trans(string $key, array $params = array()): string
    {
        return $this->translator->trans($key, $params);
    }

    /**
     * @return string
     */
    public function catalogDirectory(): string
    {
        return $this->rootDirectory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::CATALOG_DIR);
    }

    /**
     * @return string
     */
    public function rootDirectory(): string
    {
        return isset($this->config['root']) ? rtrim((string) $this->config['root'], '/\\') : dirname(__DIR__, 3);
    }

    // ----------------------------------------------------------- internals --

    /**
     * Networks whose forwarded headers may be believed.
     *
     * An empty list means "trust nothing but REMOTE_ADDR", which is the safe
     * default and the reason a deployer has to opt in explicitly.
     *
     * @param array $options detection options
     * @param array $config  boot config
     *
     * @return array
     */
    private function trustedProxies(array $options, array $config): array
    {
        foreach (array($options, $config) as $source) {
            if (isset($source['trusted_proxies'])) {
                $proxies = $source['trusted_proxies'];
                if (is_string($proxies)) {
                    $proxies = preg_split('/[\s,]+/', trim($proxies)) ?: array();
                }

                return is_array($proxies) ? array_values(array_filter(array_map('strval', $proxies))) : array();
            }
        }

        return array();
    }

    /**
     * @param array $config
     *
     * @return PreferenceStoreInterface
     */
    private function buildStore(array $config): PreferenceStoreInterface
    {
        if (isset($config['store']) && $config['store'] instanceof PreferenceStoreInterface) {
            return $config['store'];
        }

        $session = new SessionPreferenceStore();
        $account = new WhmcsClientPreferenceStore(
            isset($config['client_id_resolver']) ? $config['client_id_resolver'] : null
        );
        $cookie = $this->buildCookieStore($config);

        // Reads: session (what I just clicked) -> account (what I saved on my
        // profile) -> cookie (what I chose on a previous visit).
        //
        // The account outranks the cookie on purpose: a returning customer who
        // is signed in is bound by their account preference, not by whatever
        // an anonymous browser picked up earlier.
        $stores = array($session, $account);
        if ($cookie !== null) {
            $stores[] = $cookie;
        }

        return new ChainedPreferenceStore($stores);
    }

    /**
     * @param array $config
     *
     * @return CookiePreferenceStore|null
     */
    private function buildCookieStore(array $config)
    {
        $options = isset($config['cookie']) && is_array($config['cookie']) ? $config['cookie'] : array();
        if (isset($options['enabled']) && !$options['enabled']) {
            return null;
        }
        if (isset($config['cookie_store']) && $config['cookie_store'] instanceof CookiePreferenceStore) {
            return $config['cookie_store'];
        }

        return new CookiePreferenceStore(
            $this->request->cookies(),
            $options,
            array(
                // Every value read back from a cookie is re-validated here,
                // against the same allowlists the switcher itself uses.
                'language' => function ($value) {
                    $locale = $this->locales->find((string) $value);

                    return $locale !== null && $locale->isSelectable() ? $locale->getCode() : null;
                },
                'currency' => function ($value) {
                    $currency = $this->currencies->find($value);

                    return $currency !== null ? (string) $currency->getId() : null;
                },
            ),
            isset($options['sender']) && is_callable($options['sender']) ? $options['sender'] : null
        );
    }

    /**
     * Build the detection engine, or return null when detection is off.
     *
     * @param array          $config
     * @param RequestContext $request
     *
     * @return Detector|null
     */
    private function buildDetector(array $config, RequestContext $request)
    {
        $options = isset($config['detection']) && is_array($config['detection']) ? $config['detection'] : array();

        if (isset($config['detector']) && $config['detector'] instanceof Detector) {
            return $this->detector = $config['detector'];
        }
        if (isset($options['enabled']) && !$options['enabled']) {
            return $this->detector = null;
        }

        $geo = $this->buildGeoLocator($options, $request);
        $map = isset($options['country_currency_map']) && $options['country_currency_map'] instanceof CountryCurrencyMap
            ? $options['country_currency_map']
            : new CountryCurrencyMap(
                null,
                isset($options['country_currency_overrides']) && is_array($options['country_currency_overrides'])
                    ? $options['country_currency_overrides']
                    : array()
            );

        return $this->detector = new Detector($this->locales, $this->currencies, $request, $geo, $map, $options, $this->logger);
    }

    /**
     * Geolocation is opt-in: with nothing configured, nothing is looked up and
     * the shop default currency is used.
     *
     * @param array          $options
     * @param RequestContext $request
     *
     * @return GeoLocatorInterface
     */
    private function buildGeoLocator(array $options, RequestContext $request)
    {
        if (isset($options['geo']) && $options['geo'] instanceof GeoLocatorInterface) {
            return $options['geo'];
        }

        $locators = array();

        // 1. The country a trusted CDN/edge already put on the request. Free,
        //    instant, no third party sees the address.
        $header = isset($options['country_header']) ? (string) $options['country_header'] : HeaderGeoLocator::DEFAULT_HEADER;
        $locators[] = new HeaderGeoLocator($request, $header);

        // 2. A server-side lookup, only when an administrator configured one.
        $http = new HttpGeoLocator(
            array(
                'endpoint'      => isset($options['geo_endpoint']) ? (string) $options['geo_endpoint'] : '',
                'field'         => isset($options['geo_field']) ? (string) $options['geo_field'] : 'country_code',
                'timeout'       => isset($options['geo_timeout']) ? (int) $options['geo_timeout'] : 2,
                'allowed_hosts' => isset($options['geo_allowed_hosts']) && is_array($options['geo_allowed_hosts'])
                    ? $options['geo_allowed_hosts']
                    : array(),
                'allow_http'    => !empty($options['geo_allow_http']),
            ),
            null,
            $this->logger
        );
        if ($http->isConfigured()) {
            $locators[] = $http;
        }

        $chain = new ChainedGeoLocator($locators, $this->logger);

        $cache = isset($options['geo_cache']) && $options['geo_cache'] instanceof GeoCacheInterface
            ? $options['geo_cache']
            : (isset($options['geo_cache_path']) && (string) $options['geo_cache_path'] !== ''
                ? new FileGeoCache((string) $options['geo_cache_path'])
                : new ArrayGeoCache());

        return new CachedGeoLocator(
            $chain,
            $cache,
            isset($options['geo_ttl']) ? (int) $options['geo_ttl'] : 86400
        );
    }
}
