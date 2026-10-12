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
use CloudHost247\I18n\Preference\ArrayPreferenceStore;
use CloudHost247\I18n\Preference\ChainedPreferenceStore;
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

    /** @var RateSourceInterface|null */
    private $rateSource;

    /** @var RateTable|null */
    private $rateTable;

    /** @var PriceResolver|null */
    private $priceResolver;

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

        $this->store = $this->buildStore($config);
        $this->currencySelector = new CurrencySelector($this->currencies, $this->store);
        $this->preferences = new Preferences($this->locales, $this->currencySelector, $this->store);

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
        $applied = $this->preferences->applyRequest($request);
        $this->translator->setLocale($this->preferences->getLanguage());

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

    /**
     * @param array  $routeMap
     * @param string $currentUri
     * @param Currency|null $transactionCurrency
     *
     * @return array<string,mixed>
     */
    /**
     * Drop the cached rate table so the next rates() call re-reads the source.
     *
     * Used by the admin "refresh rates" action and by crons/currencyRateRefresh.php
     * after WHMCS has updated its own feed.
     *
     * @return void
     */
    public function clearRateCache(): void
    {
        $this->rateTable = null;
        if ($this->rateSource instanceof CachedRateSource) {
            $this->rateSource->invalidate();
        }
        $this->priceResolver = null;
    }

    /**
     * @param array $routeMap, string $currentUri = '', Currency $transactionCurrency = null): array
    {
        $uri = $currentUri !== '' ? $currentUri : $this->currentUri();
        $map = $routeMap ? $routeMap : (isset($this->config['route_map']) && is_array($this->config['route_map'])
            ? $this->config['route_map']
            : array());

        $view = new SwitcherViewModel(
            $this->preferences,
            $this->translator,
            $this->currencySelector,
            $uri,
            $map,
            $this->rates()
        );

        return $view->toArray($transactionCurrency);
    }

    /**
     * Translate, using the active locale.
     *
     * @param string $key
     * @param array  $params
     *
     * @return string
     */
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
            $routeMap,
            $this->rates()
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
        $root = isset($this->config['root']) ? rtrim((string) $this->config['root'], '/\\') : dirname(__DIR__, 2);

        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::CATALOG_DIR);
    }

    // ----------------------------------------------------------- internals --

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

        return new ChainedPreferenceStore(array($session, $account));
    }
}
