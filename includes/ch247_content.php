<?php
/**
 * Landing-page translation helpers. English stays inline as the reference and
 * safe fallback; lang/pages/<locale>/<page>.php mirrors translatable array
 * fields. Catalogs cannot change ids, URLs, icons or customer-supplied data.
 *
 * Load after WHMCS init.php. No template engine or external translator is used.
 */
if (!defined('WHMCS') && !defined('CLIENTAREA')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/i18n/autoload.php';

if (!function_exists('ch247_i18n_instance')) {
    function ch247_i18n_instance()
    {
        try {
            // Reuse the production hook's configuration, especially its rate
            // cache and native WHMCS language-value adapter. Do not boot a
            // competing singleton before ClientAreaPage has run.
            if (function_exists('ch247_i18n_autoload')) {
                return ch247_i18n_autoload();
            }
            $instance = \CloudHost247\I18n\I18n::boot(array('root' => dirname(__DIR__)));
            $instance->applyRequest(isset($_GET) && is_array($_GET) ? $_GET : array());
            return $instance;
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('CloudHost247 page translation unavailable: ' . $e->getMessage());
            }
            return null;
        }
    }
}

if (!function_exists('ch247_content')) {
    function ch247_content(string $page, array $english, $locale = null): array
    {
        $i18n = ch247_i18n_instance();
        if ($i18n === null) {
            return $english;
        }
        try {
            $active = is_string($locale) && $locale !== '' ? $locale : $i18n->preferences()->getLanguage();
            return $i18n->pages()->content($page, $english, $active);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('CloudHost247 page catalog failed for ' . $page . ': ' . $e->getMessage());
            }
            return $english;
        }
    }
}

if (!function_exists('ch247_text')) {
    /** Plain-text message with explicit English fallback (titles, forms, SEO). */
    function ch247_text(string $key, string $english, array $params = array()): string
    {
        $i18n = ch247_i18n_instance();
        if ($i18n !== null && $i18n->translator()->has($key)) {
            return $i18n->translator()->transRaw($key, $params);
        }
        foreach ($params as $name => $value) {
            if (is_scalar($value)) {
                $english = str_replace('{' . $name . '}', (string) $value, $english);
            }
        }
        return $english;
    }
}

if (!function_exists('ch247_page_title')) {
    function ch247_page_title(string $page, string $english): string
    {
        return ch247_text('page_' . str_replace('-', '_', basename($page, '.php')) . '_title', $english);
    }
}

if (!function_exists('ch247_date')) {
    /** Use an ISO date, not a translated month string, as the date source. */
    function ch247_date(string $isoDate): string
    {
        $i18n = ch247_i18n_instance();
        return $i18n !== null ? $i18n->formatter()->date($isoDate, 'long') : $isoDate;
    }
}
