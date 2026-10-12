<?php
/**
 * CloudHost247 Cart Recovery — language for the public recovery page.
 *
 * `recover.php` renders its own document: no client-area hook runs there, so
 * nothing in the theme reaches it and `<html lang="en">` used to be hardcoded
 * with the page copy inline. A customer reading the whole site in French still
 * got an English page from the one email this module sends them.
 *
 * Which language is used is deliberately *not* decided here. When the i18n
 * overlay is deployed next to this addon it is asked, so the recovery page
 * obeys exactly the same rule as every other page:
 *
 *   explicit ?language=  >  account preference  >  remembered cookie  >  default
 *
 * Detection is switched off for this endpoint on purpose (see recover.php):
 * a token link is opened from a mailbox, not from the storefront, and a
 * language tag guessed from the network the customer happens to be on is a
 * worse answer than the language they chose, or English.
 *
 * When the overlay is not deployed the page falls back to English and is
 * byte-for-byte what it was before.
 *
 * The strings live in `lang/site/<locale>/cart-recovery.php` — the same
 * catalogue the rest of the site reads — so translating this module is adding
 * a file to an existing locale, not editing PHP.
 *
 * @package CloudHost247\CartRecovery
 */

declare(strict_types=1);

namespace CloudHost247\CartRecovery;

final class Locale
{
    /**
     * Shipped English.
     *
     * Two jobs: the fallback for an install with no `lang/site` beside this
     * addon, and the reference a test pins the English catalogue against, so
     * the wording cannot drift out from under the page.
     *
     * These are short, functional messages — status and error text, not
     * marketing voice or legal copy — which is why shipping them translated
     * is safe while the policy bodies are still listed as needing review.
     */
    const DEFAULTS = array(
        'recover_unsubscribed_title'         => 'You have been unsubscribed',
        'recover_unsubscribed_body'          => 'You will not receive any further abandoned-cart reminders from us. Your account and any existing orders are unaffected.',
        'recover_unsubscribe_invalid_title'  => 'This unsubscribe link is no longer valid',
        'recover_unsubscribe_invalid_body'   => 'The link may already have been used or has expired. If you keep receiving reminders, please contact our support team.',
        'recover_expired_title'              => 'This cart link has expired',
        'recover_expired_body'               => 'For security, saved-cart links are only valid for a limited time. You can start a new order from our store at any time.',
        'recover_converted_title'            => 'This cart has already been ordered',
        'recover_converted_body'             => 'An order was already placed for this cart, so there is nothing left to restore. You can review it in your client area.',
        'recover_unavailable_title'          => 'This saved cart is no longer available',
        'recover_unavailable_body'           => 'The cart it refers to has since been emptied or closed. Please start a new cart from our store.',
        'recover_invalid_title'              => 'This cart link is not valid',
        'recover_invalid_body'               => 'We could not restore a cart from this link. Please start a new cart from our store.',
        'recover_error_title'                => 'Something went wrong',
        'recover_error_body'                 => 'We could not process that link right now. Please try again shortly or start a new cart from our store.',
        'recover_return_to_store'            => 'Return to the store',
    );

    /** @var bool */
    private static $resolved = false;

    /** @var array<string,string> */
    private static $messages = array();

    /** @var string */
    private static $code = 'en';

    /** @var string BCP-47 tag for the `lang` attribute, e.g. "pt-BR". */
    private static $tag = 'en';

    /** @var string */
    private static $direction = 'ltr';

    /**
     * The active locale code.
     *
     * @return string
     */
    public static function code(): string
    {
        self::resolve();

        return self::$code;
    }

    /**
     * @return string
     */
    public static function tag(): string
    {
        self::resolve();

        return self::$tag;
    }

    /**
     * @return string
     */
    public static function direction(): string
    {
        self::resolve();

        return self::$direction;
    }

    /**
     * A translated string, falling back to the shipped English when the
     * catalogue (or the whole overlay) is not deployed.
     *
     * @param string $key
     *
     * @return string
     */
    public static function trans(string $key): string
    {
        self::resolve();

        if (isset(self::$messages[$key]) && self::$messages[$key] !== '') {
            return self::$messages[$key];
        }
        if (isset(self::DEFAULTS[$key])) {
            return self::DEFAULTS[$key];
        }

        return $key;
    }

    /**
     * Forget the resolved locale. Only useful to tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$resolved = false;
        self::$messages = array();
        self::$code = 'en';
        self::$tag = 'en';
        self::$direction = 'ltr';
    }

    /* ----------------------------------------------------------- internals -- */

    /**
     * @return void
     */
    private static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        $root = self::root();
        if ($root === null) {
            return;
        }

        // Ask the shared library which language this visitor reads. Anything
        // that goes wrong here means English, not a broken page.
        try {
            require_once $root . '/includes/i18n/autoload.php';
            $i18n = \CloudHost247\I18n\I18n::boot(array(
                'root'      => $root,
                'detection' => array('enabled' => false),
            ));
            $get = isset($_GET) && is_array($_GET) ? $_GET : array();
            $i18n->applyRequest($get);

            $locale = $i18n->preferences()->getLocale();
            self::$code = $locale->getCode();
            self::$tag = $locale->getIntlTag();
            self::$direction = $locale->getDirection();
        } catch (\Throwable $e) {
            return;
        }

        $messages = self::catalog(self::$code);
        if (!$messages && self::$code !== 'en') {
            // A locale with no catalogue file is not an error: show English
            // rather than a page of raw keys.
            $messages = self::catalog('en');
        }
        self::$messages = $messages;
    }

    /**
     * Read one catalogue fragment, tolerating a missing or malformed file.
     *
     * @param string $locale
     *
     * @return array<string,string>
     */
    private static function catalog(string $locale): array
    {
        $root = self::root();
        // Defence in depth: the code comes from the library's validated
        // registry, but it still ends up in a path.
        if ($root === null || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
            return array();
        }

        $file = $root . '/lang/site/' . $locale . '/cart-recovery.php';
        if (!is_file($file)) {
            return array();
        }

        try {
            $values = require $file;
        } catch (\Throwable $e) {
            return array();
        }
        if (!is_array($values)) {
            return array();
        }

        $out = array();
        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The WHMCS root — the directory holding `lang/site/` and `includes/i18n/`.
     *
     * Searched rather than assumed: this addon normally sits four levels below
     * it, but the offline test harness mounts the checkout elsewhere.
     *
     * @return string|null
     */
    private static function root()
    {
        static $root = false;
        if ($root !== false) {
            return $root;
        }
        $root = null;

        $dir = __DIR__;
        for ($depth = 0; $depth < 8; $depth++) {
            if (is_dir($dir . '/lang/site') && is_file($dir . '/includes/i18n/autoload.php')) {
                $root = $dir;
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return $root;
    }
}
