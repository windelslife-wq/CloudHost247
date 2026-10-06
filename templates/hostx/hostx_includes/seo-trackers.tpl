{* Verification metadata is necessary and does not set tracking cookies. Optional
   analytics/marketing providers are injected only after cookie consent. *}
{if !empty($hostx_theme_settings.google_verification_code)}
<meta name="google-site-verification" content="{$hostx_theme_settings.google_verification_code|escape}">
{/if}
{if !empty($hostx_theme_settings.yandex_verification_code)}
<meta name="yandex-verification" content="{$hostx_theme_settings.yandex_verification_code|escape}">
{/if}
{if !empty($hostx_theme_settings.baidu_pixel_code)}
<meta name="baidu-site-verification" content="{$hostx_theme_settings.baidu_pixel_code|escape}">
{/if}
{if !empty($hostx_theme_settings.bing_verification_code)}
<meta name="msvalidate.01" content="{$hostx_theme_settings.bing_verification_code|escape}">
{/if}
<script>
(function () {ldelim}
    'use strict';
    function consentGranted() {ldelim}
        var match = document.cookie.match(/(?:^|; )cookieconsent_status=([^;]*)/);
        if (!match) return false;
        var status = decodeURIComponent(match[1]);
        return status === 'allow';
    {rdelim}
    function once(id, callback) {ldelim}
        if (document.querySelector('[data-ch247-tracker="' + id + '"]')) return;
        var marker = document.createElement('meta');
        marker.setAttribute('data-ch247-tracker', id);
        document.head.appendChild(marker);
        callback();
    {rdelim}
    function loadOptionalTrackers() {ldelim}
        if (!consentGranted()) return;
        {if !empty($hostx_theme_settings.google_analytics_code)}
        once('analytics', function () {ldelim}
            window.dataLayer = window.dataLayer || [];
            window.gtag = window.gtag || function () {ldelim} window.dataLayer.push(arguments); {rdelim};
            window.gtag('js', new Date());
            window.gtag('config', '{$hostx_theme_settings.google_analytics_code|escape:'javascript'}');
            var ga = document.createElement('script');
            ga.async = true;
            ga.src = 'https://www.googletagmanager.com/gtag/js?id={$hostx_theme_settings.google_analytics_code|escape:'javascript'}';
            ga.setAttribute('data-ch247-tracker', 'analytics-script');
            document.head.appendChild(ga);
        {rdelim});
        {/if}
        {if !empty($hostx_theme_settings.facebook_pixel_code)}
        once('marketing', function () {ldelim}
            window.fbq = window.fbq || function () {ldelim} (window.fbq.q = window.fbq.q || []).push(arguments); {rdelim};
            window.fbq('init', '{$hostx_theme_settings.facebook_pixel_code|escape:'javascript'}');
            window.fbq('track', 'PageView');
            var pixel = document.createElement('script');
            pixel.async = true;
            pixel.src = 'https://connect.facebook.net/en_US/fbevents.js';
            pixel.setAttribute('data-ch247-tracker', 'facebook-script');
            document.head.appendChild(pixel);
        {rdelim});
        {/if}
        {if !empty($hostx_theme_settings.google_tag_manager_code)}
        once('tag-manager', function () {ldelim}
            var gtm = document.createElement('script');
            gtm.async = true;
            gtm.src = 'https://www.googletagmanager.com/gtm.js?id={$hostx_theme_settings.google_tag_manager_code|escape:'javascript'}';
            gtm.setAttribute('data-ch247-tracker', 'gtm-script');
            document.head.appendChild(gtm);
        {rdelim});
        {/if}
    {rdelim}
    window.addEventListener('DOMContentLoaded', loadOptionalTrackers);
    window.addEventListener('cloudhost247:consent', loadOptionalTrackers);
{rdelim}());
</script>
