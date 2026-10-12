<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Builds the view-model the switcher templates render.
 *
 * Everything the template needs is computed here — translated labels, per
 * option URLs that keep the customer on the same page, aria attributes, and
 * the "this price is indicative" notice — so the Smarty templates stay
 * declarative and contain no business logic.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

use CloudHost247\I18n\Detection\DetectionResult;

final class SwitcherViewModel
{
    /** @var Preferences */
    private $preferences;

    /** @var Translator */
    private $translator;

    /** @var CurrencySelector */
    private $currencySelector;

    /** @var string */
    private $currentUri;

    /** @var array */
    private $routeMap;

    /** @var RateTable|null */
    private $rates;

    /** @var callable|null Converts a locale code into a platform language value. */
    private $languageValue;

    /**
     * @param Preferences      $preferences
     * @param Translator       $translator
     * @param CurrencySelector $currencySelector
     * @param string           $currentUri
     * @param array            $routeMap
     * @param RateTable|null   $rates
     */
    public function __construct(
        Preferences $preferences,
        Translator $translator,
        CurrencySelector $currencySelector,
        string $currentUri,
        array $routeMap = array(),
        RateTable $rates = null,
        $languageValue = null
    ) {
        $this->preferences = $preferences;
        $this->translator = $translator;
        $this->currencySelector = $currencySelector;
        $this->currentUri = $currentUri;
        $this->routeMap = $routeMap;
        $this->rates = $rates;
        $this->languageValue = is_callable($languageValue) ? $languageValue : null;
    }

    /**
     * @param Currency|null $transactionCurrency Currency an invoice/order is
     *                                           locked to, if any.
     *
     * @return array<string,mixed>
     */
    public function toArray(Currency $transactionCurrency = null): array
    {
        $locale = $this->preferences->getLocale();
        $currency = $this->preferences->getCurrency();

        $languageOptions = array();
        foreach ($this->preferences->getSelectableLocales() as $option) {
            $languageOptions[] = array(
                'code' => $option->getCode(),
                'name' => $option->getEnglishName(),
                'native_name' => $option->getNativeName(),
                'display_name' => $option->getDisplayName(),
                'flag' => $option->getFlagCode(),
                'direction' => $option->getDirection(),
                'selected' => $option->getCode() === $locale->getCode(),
                'url' => $this->languageUrl($option->getCode()),
                'lang' => $option->getIntlTag(),
                'hreflang' => str_replace('_', '-', $option->getIntlTag()),
            );
        }

        $currencyOptions = $this->currencySelector->options($this->currentUri, $transactionCurrency);

        return array(
            'language' => array(
                'param' => EquivalentUrl::LANGUAGE_PARAM,
                'code' => $locale->getCode(),
                'name' => $locale->getEnglishName(),
                'native_name' => $locale->getNativeName(),
                'display_name' => $locale->getDisplayName(),
                'flag' => $locale->getFlagCode(),
                'direction' => $locale->getDirection(),
                'intl_tag' => $locale->getIntlTag(),
                'options' => $languageOptions,
                'count' => count($languageOptions),
            ),
            'currency' => array(
                'param' => EquivalentUrl::CURRENCY_PARAM,
                'id' => $currency->getId(),
                'code' => $currency->getCode(),
                'symbol' => $currency->getSymbol(),
                'name' => $currency->getName(),
                'label' => $currency->getLabel(),
                'is_default' => $currency->isDefault(),
                'options' => $currencyOptions,
                'count' => count($currencyOptions),
                'source' => $this->currencySelector->getSourceName(),
            ),
            'labels' => $this->labels(),
            'notice' => $this->notice($transactionCurrency),
            'detection' => $this->detection(),
            'canonical' => EquivalentUrl::canonical($this->currentUri),
        );
    }

    /**
     * What automatic detection contributed, for the switcher's own hint and
     * for the `data-ch247-detection` attribute the browser tests read.
     *
     * Only an *automatic* choice is announced. A stored preference or a
     * default is not "detected", and saying so would be a lie about where the
     * setting came from.
     *
     * @return array<string,mixed>
     */
    private function detection(): array
    {
        $language = $this->preferences->resolveLanguage();
        $currency = $this->preferences->resolveCurrency();

        $languageMessage = null;
        if ($language->isDetected() && $language->getSource() === DetectionResult::SOURCE_BROWSER) {
            $languageMessage = $this->translator->trans('detection_auto_language', array(
                'name' => $language->getValue()->getNativeName(),
            ));
        }

        $currencyMessage = null;
        if ($currency->isDetected()
            && in_array($currency->getSource(), array(DetectionResult::SOURCE_GEOLOCATION, DetectionResult::SOURCE_REGION_HINT), true)
        ) {
            $currencyMessage = $this->translator->trans('detection_auto_currency', array(
                'code' => $currency->getValue()->getCode(),
            ));
        }

        $messages = array_values(array_filter(array($languageMessage, $currencyMessage)));

        return array(
            'enabled'     => $this->preferences->detector() !== null,
            'detected'    => $language->isDetected() || $currency->isDetected(),
            'language'    => array(
                'code'     => $language->getValue()->getCode(),
                'source'   => $language->getSource(),
                'detected' => $language->isDetected(),
                'explicit' => $language->isExplicit(),
            ),
            'currency'    => array(
                'code'     => $currency->getValue()->getCode(),
                'id'       => $currency->getValue()->getId(),
                'source'   => $currency->getSource(),
                'detected' => $currency->isDetected(),
                'explicit' => $currency->isExplicit(),
            ),
            'country'     => $this->countryCode(),
            'language_message' => $languageMessage,
            'currency_message' => $currencyMessage,
            'messages'    => $messages,
            'message'     => $messages ? implode(' ', $messages) : null,
            'hint'        => $messages ? $this->translator->trans('detection_change_hint') : null,
        );
    }

    /**
     * @return string|null
     */
    private function countryCode()
    {
        $detector = $this->preferences->detector();
        if ($detector === null) {
            return null;
        }
        $country = $detector->country();

        return $country !== null ? $country->getCountry() : null;
    }

    private function languageUrl(string $code): string
    {
        $url = EquivalentUrl::forLanguage($this->currentUri, $code, $this->routeMap);
        if ($this->languageValue === null) {
            return $url;
        }
        $value = (string) call_user_func($this->languageValue, $code);
        return preg_replace('/([?&]language=)[^&#]*/', '$1' . rawurlencode($value), $url);
    }

    /**
     * Translated labels and accessible names for the switcher.
     *
     * @return array<string,string>
     */
    private function labels(): array
    {
        $keys = array(
            'switcher_group_label',
            'switcher_language_label',
            'switcher_language_button',
            'switcher_choose_language',
            'switcher_language_selected',
            'switcher_currency_label',
            'switcher_currency_button',
            'switcher_choose_currency',
            'currency_default_badge',
            'switcher_currency_selected',
            'switcher_open',
            'switcher_close',
            'switcher_no_options',
            'switcher_current_language',
            'switcher_current_currency',
            'currency_indicative_help',
            'currency_locked_help',
        );

        $labels = array();
        foreach ($keys as $key) {
            $labels[$key] = $this->translator->transRaw($key);
        }

        $labels['switcher_language_button'] .= ' — ' . $this->translator->transRaw('switcher_current_language', array('name' => $this->preferences->getLocale()->getNativeName()));
        $labels['switcher_currency_button'] .= ' — ' . $this->translator->transRaw('switcher_current_currency', array('code' => $this->preferences->getCurrency()->getCode()));
        return $labels;
    }

    /**
     * The "this is an indicative conversion" notice, or null when the
     * displayed currency is the one that will be charged.
     *
     * @param Currency|null $transactionCurrency
     *
     * @return array<string,mixed>|null
     */
    private function notice(Currency $transactionCurrency = null): ?array
    {
        if ($transactionCurrency === null) {
            return null;
        }
        $currency = $this->preferences->getCurrency();

        if ($transactionCurrency->getId() === $currency->getId()) {
            return null;
        }

        return array(
            'show' => true,
            'charged_in' => $transactionCurrency->getCode(),
            'displayed_in' => $currency->getCode(),
            'rate' => $this->rates !== null ? $this->rates->rate($currency->getCode()) : null,
            'rate_as_of' => $this->rates !== null ? $this->rates->getAsOf() : null,
            'rate_source' => $this->rates !== null ? $this->rates->getSource() : null,
            'message' => $this->translator->trans('currency_indicative_notice', array(
                'displayed' => $currency->getCode(),
                'charged' => $transactionCurrency->getCode(),
            )),
        );
    }

    /**
     * @return string
     */
    public function getSourceName(): string
    {
        return $this->currencySelector->getSourceName();
    }
}
