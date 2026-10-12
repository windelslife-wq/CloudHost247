<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Owns the two customer-facing preferences, the fact that they are
 * *independent*, and the precedence rule that decides which value wins.
 *
 * Independence is half the point of this class. Language and currency are
 * stored under separate keys, validated separately, and switching one never
 * reads, writes or resets the other. English + NGN and French + USD are both
 * first-class combinations.
 *
 * Precedence is the other half. For each of the two:
 *
 *   1. an explicit choice made on this request           (?language=, ?currency=)
 *   2. a saved choice — account record, then cookie
 *   3. an automatic detection result (session-only)
 *   4. the platform default
 *
 * Detection is only ever consulted when steps 1 and 2 produce nothing, and a
 * detected value is stored separately from an explicit one. That is what makes
 * "do not silently overwrite an explicit selection with browser or IP
 * detection" true by construction rather than by convention.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;
use CloudHost247\I18n\Detection\DetectionResult;
use CloudHost247\I18n\Detection\Detector;
use CloudHost247\I18n\Preference\ChainedPreferenceStore;
use CloudHost247\I18n\Preference\FlushablePreferenceStoreInterface;
use CloudHost247\I18n\Preference\NamedPreferenceStoreInterface;

final class Preferences
{
    const LANGUAGE_KEY = 'language';
    const CURRENCY_KEY = 'currency';

    /**
     * Where a detected value is remembered for the rest of the visit.
     *
     * Deliberately NOT the same key as the explicit preference, and
     * deliberately never written to the customer's account: an IP guess is a
     * suggestion, and it must stay overwritable by a real choice.
     */
    const DETECTED_LANGUAGE_KEY = 'detected_language';
    const DETECTED_CURRENCY_KEY = 'detected_currency';

    /** @var LocaleRegistry */
    private $locales;

    /** @var CurrencySelector */
    private $currencySelector;

    /** @var PreferenceStoreInterface */
    private $store;

    /** @var Detector|null */
    private $detector;

    /** @var string|null Explicit language chosen this request. */
    private $language;

    /** @var DetectionResult|null */
    private $languageResult;

    /** @var DetectionResult|null */
    private $currencyResult;

    /** @var array<string,SelectionResult> */
    private $lastResults = array();

    /**
     * @param LocaleRegistry           $locales
     * @param CurrencySelector         $currencySelector
     * @param PreferenceStoreInterface $store
     * @param Detector|null            $detector Enables automatic detection.
     */
    public function __construct(
        LocaleRegistry $locales,
        CurrencySelector $currencySelector,
        PreferenceStoreInterface $store,
        Detector $detector = null
    ) {
        $this->locales = $locales;
        $this->currencySelector = $currencySelector;
        $this->store = $store;
        $this->detector = $detector;

        if ($detector !== null) {
            // Let the selector answer "what is selected?" with the detected
            // currency too, so every consumer of the selector — including the
            // ones that never go through this class — agrees on the answer.
            $this->currencySelector->setDetectedResolver(function () {
                return $this->resolveCurrency()->getValue();
            });
        }
    }

    /**
     * @param Detector|null $detector
     *
     * @return void
     */
    public function setDetector(Detector $detector = null): void
    {
        $this->detector = $detector;
        $this->languageResult = null;
        $this->currencyResult = null;

        if ($detector !== null) {
            $this->currencySelector->setDetectedResolver(function () {
                return $this->resolveCurrency()->getValue();
            });
        } else {
            $this->currencySelector->setDetectedResolver(null);
        }
    }

    /**
     * @return Detector|null
     */
    public function detector()
    {
        return $this->detector;
    }

    /* -------------------------------------------------------------- reading */

    /**
     * The active language code.
     *
     * Resolving here (rather than on demand in every consumer) means detection
     * runs at most once per request and every caller sees the same answer.
     *
     * @return string
     */
    public function getLanguage(): string
    {
        return $this->resolveLanguage()->getValue()->getCode();
    }

    /**
     * @return Locale
     */
    public function getLocale(): Locale
    {
        return $this->resolveLanguage()->getValue();
    }

    /**
     * @return Currency
     */
    public function getCurrency(): Currency
    {
        return $this->resolveCurrency()->getValue();
    }

    /**
     * The language together with where it came from.
     *
     * @return DetectionResult
     */
    public function resolveLanguage(): DetectionResult
    {
        if ($this->languageResult !== null) {
            return $this->languageResult;
        }

        // 1. An explicit choice made earlier in this very request.
        if ($this->language !== null) {
            return $this->languageResult = new DetectionResult(
                $this->locales->resolve($this->language),
                DetectionResult::SOURCE_EXPLICIT,
                'request'
            );
        }

        // 2. A saved preference: the customer's account record, then the
        //    cookie they got the last time they chose.
        $stored = $this->store->get(self::LANGUAGE_KEY);
        $locale = $stored !== null && $stored !== '' ? $this->findLocale((string) $stored) : null;

        if ($locale !== null && $locale->isSelectable()) {
            $this->language = $locale->getCode();

            return $this->languageResult = new DetectionResult(
                $locale,
                $this->sourceForStoreName($this->storeNameFor(self::LANGUAGE_KEY)),
                'saved_preference',
                array('store' => $this->storeNameFor(self::LANGUAGE_KEY))
            );
        }

        // 3. A value detected on an earlier request in this session.
        $detected = $this->store->get(self::DETECTED_LANGUAGE_KEY);
        $locale = $detected !== null && $detected !== '' ? $this->findLocale((string) $detected) : null;

        if ($locale !== null && $locale->isSelectable()) {
            $this->language = $locale->getCode();

            return $this->languageResult = new DetectionResult(
                $locale,
                DetectionResult::SOURCE_BROWSER,
                'detected_previous_request',
                array('code' => $locale->getCode())
            );
        }

        // 4. Detect now, if detection is configured.
        if ($this->detector !== null) {
            $result = $this->detector->detectLanguage();
            $this->applyDetectedLanguage($result);

            return $this->languageResult = $result;
        }

        // 5. The platform default.
        $default = $this->locales->getDefault();

        return $this->languageResult = new DetectionResult(
            $default,
            DetectionResult::SOURCE_DEFAULT,
            'detection_disabled'
        );
    }

    /**
     * The currency together with where it came from.
     *
     * @return DetectionResult
     */
    public function resolveCurrency(): DetectionResult
    {
        if ($this->currencyResult !== null) {
            return $this->currencyResult;
        }

        // 1. An explicit choice made earlier in this request.
        $explicit = $this->currencySelector->resolvedExplicit();
        if ($explicit !== null) {
            return $this->currencyResult = new DetectionResult($explicit, DetectionResult::SOURCE_EXPLICIT, 'request');
        }

        // 2. A saved preference (account, then cookie), validated against the
        //    currencies WHMCS actually has enabled. A stale id — a currency an
        //    administrator has since removed — is ignored, not honoured.
        $stored = $this->store->get(self::CURRENCY_KEY);
        $currency = $this->currencySelector->find($stored);

        if ($currency !== null) {
            $this->currencySelector->override($currency);

            return $this->currencyResult = new DetectionResult(
                $currency,
                $this->sourceForStoreName($this->storeNameFor(self::CURRENCY_KEY)),
                'saved_preference',
                array('store' => $this->storeNameFor(self::CURRENCY_KEY))
            );
        }

        // 3. A detection result from earlier in this session.
        $detected = $this->store->get(self::DETECTED_CURRENCY_KEY);
        $currency = $this->currencySelector->find($detected);

        if ($currency !== null) {
            $this->currencySelector->override($currency);

            return $this->currencyResult = new DetectionResult(
                $currency,
                DetectionResult::SOURCE_GEOLOCATION,
                'detected_previous_request',
                array('code' => $currency->getCode())
            );
        }

        // 4. Detect now.
        if ($this->detector !== null) {
            $result = $this->detector->detectCurrency();
            $this->applyDetectedCurrency($result);

            return $this->currencyResult = $result;
        }

        // 5. The configured default.
        $default = $this->currencySelector->defaultCurrency();

        return $this->currencyResult = new DetectionResult($default, DetectionResult::SOURCE_DEFAULT, 'detection_disabled');
    }

    /**
     * @return string
     */
    public function getLanguageSource(): string
    {
        return $this->resolveLanguage()->getSource();
    }

    /**
     * @return string
     */
    public function getCurrencySource(): string
    {
        return $this->resolveCurrency()->getSource();
    }

    /**
     * Locales the switcher may offer: complete catalogs only.
     *
     * @return Locale[]
     */
    public function getSelectableLocales(): array
    {
        return $this->locales->selectable();
    }

    /* -------------------------------------------------------------- writing */

    /**
     * Switch language. Returns a result describing whether the requested
     * locale was actually selectable.
     *
     * @param string|null $code
     *
     * @return SelectionResult
     */
    public function setLanguage($code): SelectionResult
    {
        if ($code !== null && !is_scalar($code)) {
            return $this->rejectLanguage(SelectionResult::ERROR_UNKNOWN, null);
        }
        if ($code === null || trim((string) $code) === '') {
            return $this->rejectLanguage(SelectionResult::ERROR_EMPTY, $code);
        }

        $locale = $this->findLocale((string) $code);

        if ($locale === null) {
            return $this->rejectLanguage(SelectionResult::ERROR_UNKNOWN, $code);
        }

        if (!$locale->isSelectable()) {
            // Registered but unfinished: not an error the customer can act on,
            // but we must not silently serve a half-translated site either.
            return $this->rejectLanguage(SelectionResult::ERROR_DISABLED, $code);
        }

        $this->language = $locale->getCode();
        $this->languageResult = new DetectionResult($locale, DetectionResult::SOURCE_EXPLICIT, 'switcher');
        // An explicit choice supersedes any stored detection result.
        $this->store->set(self::DETECTED_LANGUAGE_KEY, null);
        $this->store->set(self::LANGUAGE_KEY, $locale->getCode());

        return $this->remember('language', SelectionResult::success($locale->getCode(), $code));
    }

    /**
     * Switch currency. Fully independent of setLanguage().
     *
     * @param int|string|null $value
     *
     * @return SelectionResult
     */
    public function setCurrency($value): SelectionResult
    {
        $result = $this->currencySelector->select($value);

        if ($result->isOk()) {
            // Explicit: drop any detected currency so detection cannot put it
            // back on a later request.
            $this->store->set(self::DETECTED_CURRENCY_KEY, null);
            $this->currencyResult = new DetectionResult($result->getValue(), DetectionResult::SOURCE_EXPLICIT, 'switcher');
        } else {
            $this->currencyResult = new DetectionResult(
                $result->getValue(),
                DetectionResult::SOURCE_DEFAULT,
                'invalid_selection'
            );
        }

        return $this->remember('currency', $result);
    }

    /**
     * Apply `?language=` / `?currency=` from a request.
     *
     * Missing or invalid parameters are ignored rather than clearing a stored
     * preference: a plain visit to a page must not reset the customer's
     * choice.
     *
     * Any other parameter — `?country=`, `?lang=`, `?cur=` — is ignored
     * entirely. Only the two documented parameters are honoured, and their
     * values are validated against the server-side allowlists.
     *
     * @param array $request Usually $_GET / $_REQUEST.
     *
     * @return array{language:SelectionResult|null,currency:SelectionResult|null}
     */
    public function applyRequest(array $request): array
    {
        $applied = array('language' => null, 'currency' => null);

        if (array_key_exists(EquivalentUrl::LANGUAGE_PARAM, $request)) {
            $applied['language'] = $this->setLanguage($request[EquivalentUrl::LANGUAGE_PARAM]);
        }
        if (array_key_exists(EquivalentUrl::CURRENCY_PARAM, $request)) {
            $applied['currency'] = $this->setCurrency($request[EquivalentUrl::CURRENCY_PARAM]);
        }

        return $applied;
    }

    /**
     * @param string $key
     *
     * @return SelectionResult|null
     */
    public function lastResult(string $key)
    {
        return isset($this->lastResults[$key]) ? $this->lastResults[$key] : null;
    }

    /**
     * Persist the current pair explicitly (used by the account-settings form).
     *
     * @return void
     */
    public function persist(): void
    {
        $this->store->set(self::LANGUAGE_KEY, $this->getLanguage());
        $this->store->set(self::CURRENCY_KEY, $this->getCurrency()->getId());
    }

    /**
     * Forget an automatic detection result and restore the default.
     *
     * Used when an administrator disables detection, and by tests.
     *
     * @return void
     */
    public function clearDetection(): void
    {
        $this->store->set(self::DETECTED_LANGUAGE_KEY, null);
        $this->store->set(self::DETECTED_CURRENCY_KEY, null);
        $this->languageResult = null;
        $this->currencyResult = null;
    }

    /**
     * Send any queued cookies. Safe to call repeatedly.
     *
     * @return array<string,array<string,mixed>>
     */
    public function flushCookies(): array
    {
        return $this->flush($this->store);
    }

    /* ---------------------------------------------------------------- state */

    /**
     * Everything a template, an admin report or a test needs to know about why
     * this visitor sees this language and this currency.
     *
     * @return array<string,mixed>
     */
    public function explain(): array
    {
        $language = $this->resolveLanguage();
        $currency = $this->resolveCurrency();
        $detector = $this->detector;

        return array(
            'language' => array(
                'code'   => $language->getValue()->getCode(),
                'source' => $language->getSource(),
                'reason' => $language->getReason(),
                'explicit' => $language->isExplicit(),
                'detected' => $language->isDetected(),
                'evidence' => $language->getEvidence(),
            ),
            'currency' => array(
                'code'   => $currency->getValue()->getCode(),
                'id'     => $currency->getValue()->getId(),
                'source' => $currency->getSource(),
                'reason' => $currency->getReason(),
                'explicit' => $currency->isExplicit(),
                'detected' => $currency->isDetected(),
                'evidence' => $currency->getEvidence(),
            ),
            'detection_enabled' => $detector !== null,
            'request'           => $detector !== null ? $detector->explain() : null,
        );
    }

    /* ------------------------------------------------------------ internals */

    /**
     * @param DetectionResult $result
     *
     * @return void
     */
    private function applyDetectedLanguage(DetectionResult $result): void
    {
        $locale = $result->getValue();
        if (!$locale instanceof Locale) {
            return;
        }
        $this->language = $locale->getCode();
        // Remembered under its own key so an explicit choice always outranks
        // it, and never written to the customer's account.
        $this->store->set(self::DETECTED_LANGUAGE_KEY, $locale->getCode());
    }

    /**
     * @param DetectionResult $result
     *
     * @return void
     */
    private function applyDetectedCurrency(DetectionResult $result): void
    {
        $currency = $result->getValue();
        if (!$currency instanceof Currency) {
            return;
        }
        $this->currencySelector->override($currency);
        $this->store->set(self::DETECTED_CURRENCY_KEY, $currency->getId());
    }

    /**
     * Resolve a requested locale, tolerating region tags that have no catalog
     * of their own.
     *
     * `de-DE`, `de_DE` and `de` all select German when only a `de` catalog
     * ships; once someone adds `de-AT`, that exact tag wins and plain `de`
     * still resolves to German.
     *
     * @param string $code
     *
     * @return Locale|null
     */
    private function findLocale(string $code): ?Locale
    {
        $code = \CloudHost247\I18n\Whmcs\WhmcsLocale::toCode($code);

        // Reject anything that is not shaped like a language tag before it
        // reaches the registry. Looking a value up in a table is only safe
        // while the value is known to be well formed: `en-US" onload="x`
        // would otherwise normalise to something whose base language is a
        // real, supported one.
        if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,3}$/', $code) !== 1) {
            return null;
        }

        $locale = $this->locales->find($code);
        if ($locale !== null) {
            return $locale;
        }

        $normalised = Locale::normalise($code);
        $base = explode('-', $normalised);
        $base = $base[0];

        if ($base !== '' && $base !== $normalised) {
            return $this->locales->find($base);
        }

        return null;
    }

    /**
     * Which store in the chain supplied a key — for provenance only.
     *
     * @param string $key
     *
     * @return string
     */
    private function storeNameFor(string $key): string
    {
        $stores = $this->store instanceof ChainedPreferenceStore
            ? $this->store->getStores()
            : array($this->store);

        foreach ($stores as $store) {
            $value = $store->get($key, ChainedPreferenceStore::NOT_FOUND);
            if ($value !== ChainedPreferenceStore::NOT_FOUND && $value !== null && $value !== '') {
                return $store instanceof NamedPreferenceStoreInterface ? $store->storeName() : 'store';
            }
        }

        return '';
    }

    /**
     * @param string $storeName
     *
     * @return string
     */
    private function sourceForStoreName(string $storeName): string
    {
        return $storeName === 'account'
            ? DetectionResult::SOURCE_ACCOUNT
            : DetectionResult::SOURCE_STORED;
    }

    /**
     * @param PreferenceStoreInterface $store
     *
     * @return array<string,array<string,mixed>>
     */
    private function flush($store): array
    {
        $sent = array();

        if ($store instanceof FlushablePreferenceStoreInterface) {
            $sent = $store->flush();
        }
        if ($store instanceof ChainedPreferenceStore) {
            foreach ($store->getStores() as $child) {
                foreach ($this->flush($child) as $name => $details) {
                    $sent[$name] = $details;
                }
            }
        }

        return $sent;
    }

    /**
     * Record that a requested language could not be honoured.
     *
     * The customer keeps whatever they were already reading; the rejected
     * value is reported back so the UI can explain itself. Detection is
     * re-run if needed so the answer is the same as it would have been had
     * nobody asked.
     *
     * @param string $error
     * @param mixed  $requested
     *
     * @return SelectionResult
     */
    private function rejectLanguage(string $error, $requested): SelectionResult
    {
        $this->languageResult = null;
        $code = $this->getLanguage();
        $this->language = $code;
        $result = SelectionResult::failure($code, $error, $requested);
        $this->languageResult = new DetectionResult(
            $this->locales->resolve($code),
            DetectionResult::SOURCE_DEFAULT,
            'invalid_selection',
            array('error' => $error)
        );

        return $this->remember('language', $result);
    }

    /**
     * @param string          $key
     * @param SelectionResult $result
     *
     * @return SelectionResult
     */
    private function remember(string $key, SelectionResult $result): SelectionResult
    {
        $this->lastResults[$key] = $result;

        return $result;
    }
}
