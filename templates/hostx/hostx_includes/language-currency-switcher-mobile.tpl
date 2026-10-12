{*
 * CloudHost247 — language / currency switcher, mobile and off-canvas variant.
 *
 * Same view-model and the same accessibility contract as
 * includes/language-currency-switcher.tpl. The difference is purely presentational:
 *
 *   - the two controls sit side by side on one full-width row so the off-canvas
 *     menu does not grow a second scroll region;
 *   - panels drop *below* the row and are constrained to the viewport width, so
 *     nothing can cause horizontal overflow on a 320px screen;
 *   - tap targets are at least 44px high.
 *
 * It is deliberately the same markup, not a second implementation: one set of
 * CSS and one JS enhancer serve both, and the a11y tests cover both because
 * they parse this file too.
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 *}

{if $ch247Switcher}
<div class="ch247-switcher ch247-switcher--mobile" data-ch247-switcher>

    <div class="ch247-switcher__group ch247-switcher__group--language" data-ch247-switcher-group="language">
        <button type="button"
                class="ch247-switcher__trigger"
                id="ch247-language-trigger-mobile"
                data-ch247-switcher-trigger
                aria-expanded="false"
                aria-controls="ch247-language-menu-mobile"
                aria-haspopup="true"
                aria-label="{$ch247Switcher.labels.switcher_language_button|escape:'html':'UTF-8'}">
            <span class="ch247-switcher__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false" width="18" height="18">
                    <path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm7.94 9h-3.03a15.6 15.6 0 0 0-1.2-5.35A8.03 8.03 0 0 1 19.94 11ZM12 4.13c.9 1.2 1.7 3.4 2.05 6.87h-4.1c.35-3.47 1.15-5.67 2.05-6.87ZM4.06 13h3.03c.26 1.94.7 3.8 1.2 5.35A8.03 8.03 0 0 1 4.06 13Zm3.03-2H4.06a8.03 8.03 0 0 1 4.23-5.35A15.6 15.6 0 0 0 7.09 11Zm2.02 0h5.78c-.37 3.62-1.19 6-2.89 6s-2.52-2.38-2.89-6Zm3.77 7.35c.5-1.55.94-3.41 1.2-5.35h3.03a8.03 8.03 0 0 1-4.23 5.35ZM8.29 18.35A15.6 15.6 0 0 1 7.09 13H4.06a8.03 8.03 0 0 0 4.23 5.35Z"/>
                </svg>
            </span>
            <span class="ch247-switcher__label">
                <span class="ch247-switcher__label-text" lang="{$ch247Switcher.language.intl_tag|escape:'html':'UTF-8'}"{if $ch247Switcher.language.direction eq 'rtl'} dir="rtl"{/if}>{$ch247Switcher.language.display_name|escape:'html':'UTF-8'}</span>
            </span>
            <span class="ch247-switcher__chevron" aria-hidden="true"></span>
        </button>

        <div class="ch247-switcher__panel"
             id="ch247-language-menu-mobile"
             data-ch247-switcher-panel
             role="group"
             aria-labelledby="ch247-language-trigger-mobile"
             hidden>
            <p class="ch247-switcher__heading" id="ch247-language-heading-mobile">{$ch247Switcher.labels.switcher_choose_language|escape:'html':'UTF-8'}</p>
            <ul class="ch247-switcher__list" aria-labelledby="ch247-language-heading-mobile">
                {foreach $ch247Switcher.language.options as $option}
                    <li class="ch247-switcher__item">
                        <a class="ch247-switcher__option{if $option.selected} is-selected{/if}"
                           href="{$option.url|escape:'html':'UTF-8'}"
                           lang="{$option.lang|escape:'html':'UTF-8'}"
                           hreflang="{$option.hreflang|escape:'html':'UTF-8'}"
                           {if $option.direction eq 'rtl'}dir="rtl"{/if}
                           {if $option.selected}aria-current="true"{/if}
                           data-ch247-switcher-option>
                            <span class="ch247-switcher__flag flag flag-{$option.flag|escape:'html':'UTF-8'}" aria-hidden="true"></span>
                            <span class="ch247-switcher__option-text">
                                <span class="ch247-switcher__option-native">{$option.native_name|escape:'html':'UTF-8'}</span>
                                <span class="ch247-switcher__option-english">{$option.name|escape:'html':'UTF-8'}</span>
                            </span>
                            {if $option.selected}
                                <span class="ch247-switcher__tick" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false" width="16" height="16"><path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                                </span>
                            {/if}
                        </a>
                    </li>
                {/foreach}
            </ul>
        </div>
    </div>

    <div class="ch247-switcher__group ch247-switcher__group--currency" data-ch247-switcher-group="currency">
        <button type="button"
                class="ch247-switcher__trigger"
                id="ch247-currency-trigger-mobile"
                data-ch247-switcher-trigger
                aria-expanded="false"
                aria-controls="ch247-currency-menu-mobile"
                aria-haspopup="true"
                aria-label="{$ch247Switcher.labels.switcher_currency_button|escape:'html':'UTF-8'}">
            <span class="ch247-switcher__icon ch247-switcher__icon--symbol" aria-hidden="true">{$ch247Switcher.currency.symbol|escape:'html':'UTF-8'}</span>
            <span class="ch247-switcher__label">
                <span class="ch247-switcher__label-text">{$ch247Switcher.currency.code|escape:'html':'UTF-8'}</span>
            </span>
            <span class="ch247-switcher__chevron" aria-hidden="true"></span>
        </button>

        <div class="ch247-switcher__panel"
             id="ch247-currency-menu-mobile"
             data-ch247-switcher-panel
             role="group"
             aria-labelledby="ch247-currency-trigger-mobile"
             hidden>
            <p class="ch247-switcher__heading" id="ch247-currency-heading-mobile">{$ch247Switcher.labels.switcher_choose_currency|escape:'html':'UTF-8'}</p>
            <ul class="ch247-switcher__list" aria-labelledby="ch247-currency-heading-mobile">
                {foreach $ch247Switcher.currency.options as $option}
                    <li class="ch247-switcher__item">
                        <a class="ch247-switcher__option{if $option.selected} is-selected{/if}{if $option.locked} is-locked{/if}"
                           href="{$option.url|escape:'html':'UTF-8'}"
                           {if $option.selected}aria-current="true"{/if}
                           data-ch247-switcher-option>
                            <span class="ch247-switcher__symbol" aria-hidden="true">{$option.symbol|escape:'html':'UTF-8'}</span>
                            <span class="ch247-switcher__option-text">
                                <span class="ch247-switcher__option-native">{$option.code|escape:'html':'UTF-8'}</span>
                                <span class="ch247-switcher__option-english">{$option.name|escape:'html':'UTF-8'}</span>
                            </span>
                            {if $option.is_default}
                                <span class="ch247-switcher__badge">{$ch247Switcher.labels.currency_default_badge|escape:'html':'UTF-8'}</span>
                            {/if}
                            {if $option.selected}
                                <span class="ch247-switcher__tick" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false" width="16" height="16"><path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                                </span>
                            {/if}
                        </a>
                    </li>
                {/foreach}
            </ul>
            {if $ch247Switcher.notice}
                <p class="ch247-switcher__hint"><span class="ch247-switcher__notice">{$ch247Switcher.notice.message|escape:'html':'UTF-8'}</span></p>
            {/if}
        </div>
    </div>

</div>
{/if}
