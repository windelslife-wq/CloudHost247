<?php
/**
 * CloudHost247 Cart Recovery — the public response document.
 *
 * `recover.php` is a bare endpoint: no client-area hook runs there, so no
 * theme, no `{$LANG}` and no shared head reach it. It therefore has to render
 * its own document — and has to resolve the reader's language itself, because
 * the alternative is an English page landing in the inbox of a customer who
 * chose French everywhere else.
 *
 * The language is *not* chosen here: Locale asks the shared i18n subsystem, so
 * the same precedence applies as on the storefront (an explicit ?language=
 * beats a saved account preference beats the remembered cookie, and detection
 * is off for this endpoint — see Locale for why).
 *
 * @package CloudHost247\CartRecovery
 */

declare(strict_types=1);

namespace CloudHost247\CartRecovery;

final class RecoveryPage
{
    /**
     * Emit the page and set its status code.
     *
     * @param string $titleKey   Catalogue key, e.g. 'recover_expired_title'.
     * @param string $messageKey Catalogue key, e.g. 'recover_expired_body'.
     * @param int    $httpStatus
     *
     * @return void
     */
    public static function render(string $titleKey, string $messageKey, int $httpStatus = 200): void
    {
        $companyName = EmailService::companyName();
        $home = RecoveryService::baseUrl();
        $title = Locale::trans($titleKey);
        $message = Locale::trans($messageKey);

        $escape = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        // Guarded: an endpoint that has already flushed output must still
        // print a readable page rather than warn its way through two
        // "headers already sent" notices.
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
        }

        // Declare the language the page is written in, so a screen reader
        // pronounces it with the right rules instead of reading French as
        // English.
        $lang = '<html lang="' . $escape(Locale::tag()) . '"'
            . (Locale::direction() === 'rtl' ? ' dir="rtl"' : '') . '>';

        echo '<!doctype html>' . $lang . '<head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . $escape($title) . ' &middot; ' . $escape($companyName) . '</title>'
            . '<style>body{margin:0;background:#f5f7fb;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",'
            . 'Roboto,Arial,sans-serif;color:#1d2433}'
            . '.wrap{max-width:620px;margin:10vh auto;padding:0 20px}.card{background:#fff;border-radius:10px;'
            . 'padding:36px;box-shadow:0 8px 30px rgba(16,32,64,.08)}h1{font-size:24px;margin:0 0 12px}'
            . 'a.btn{display:inline-block;margin-top:22px;background:#0b5fff;color:#fff;text-decoration:none;'
            . 'padding:12px 22px;border-radius:6px}'
            . '.brand{font-weight:700;color:#0b5fff;margin-bottom:18px}</style></head>'
            . '<body><div class="wrap"><div class="card">'
            . '<div class="brand">' . $escape($companyName) . '</div>'
            . '<h1>' . $escape($title) . '</h1><p>' . $escape($message) . '</p>'
            . ($home ? '<a class="btn" href="' . $escape($home) . '">'
                . $escape(Locale::trans('recover_return_to_store')) . '</a>' : '')
            . '</div></div></body></html>';
    }
}
