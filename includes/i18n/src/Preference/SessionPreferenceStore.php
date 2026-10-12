<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Session-backed preference store.
 *
 * Two independent keys are used (`ch247_language` and `ch247_currency`) and
 * writing one never touches the other — a customer may perfectly reasonably
 * want English with NGN, or French with USD.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Preference;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;

final class SessionPreferenceStore implements PreferenceStoreInterface, NamedPreferenceStoreInterface
{
    /** @var string Namespace so we never collide with WHMCS session keys. */
    const NAMESPACE = 'ch247_i18n';

    /** @var array<string,mixed>|null */
    private $fallback;

    public function __construct()
    {
        // Fall back to an array when no session is running (CLI, cron, tests)
        // so the store degrades instead of warning.
        $this->fallback = array();
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        if (!$this->sessionActive()) {
            return array_key_exists($key, $this->fallback) ? $this->fallback[$key] : $default;
        }
        $bucket = $this->bucket();
        if (array_key_exists($key, $bucket)) {
            return $bucket[$key];
        }
        if ($key === 'language' && empty($_SESSION['uid']) && !empty($_SESSION['Language'])) {
            return \CloudHost247\I18n\Whmcs\WhmcsLocale::toCode((string) $_SESSION['Language']);
        }
        // WHMCS uses this id to price a guest's real cart.
        if ($key === 'currency' && empty($_SESSION['uid']) && isset($_SESSION['currency'])) {
            return $_SESSION['currency'];
        }
        return $default;
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function set(string $key, $value)
    {
        if (!$this->sessionActive()) {
            if ($value === null) {
                unset($this->fallback[$key]);
            } else {
                $this->fallback[$key] = $value;
            }

            return;
        }

        if (!isset($_SESSION[self::NAMESPACE]) || !is_array($_SESSION[self::NAMESPACE])
            || (int) ($_SESSION[self::NAMESPACE]['client_id'] ?? 0) !== (int) ($_SESSION['uid'] ?? 0)) {
            $_SESSION[self::NAMESPACE] = array('client_id' => (int) ($_SESSION['uid'] ?? 0));
        }
        if ($value === null) {
            unset($_SESSION[self::NAMESPACE][$key]);
        } else {
            $_SESSION[self::NAMESPACE][$key] = $value;
            if ($key === 'language') {
                $_SESSION['Language'] = \CloudHost247\I18n\Whmcs\WhmcsLocale::toWhmcsName((string) $value);
            } elseif ($key === 'currency' && empty($_SESSION['uid'])) {
                $_SESSION['currency'] = (int) $value;
            }
        }
    }

    public function isWritable(): bool
    {
        return $this->sessionActive();
    }

    public function storeName(): string
    {
        return 'session';
    }

    /**
     * @return bool
     */
    private function sessionActive(): bool
    {
        return isset($_SESSION) && is_array($_SESSION);
    }

    /**
     * @return array<string,mixed>
     */
    private function bucket(): array
    {
        $bucket = isset($_SESSION[self::NAMESPACE]) && is_array($_SESSION[self::NAMESPACE])
            ? $_SESSION[self::NAMESPACE] : array();
        // A guest preference or another client's account must not override the
        // saved preference after sign-in / WHMCS account switching.
        if ((int) ($bucket['client_id'] ?? 0) !== (int) ($_SESSION['uid'] ?? 0)) {
            return array();
        }
        return $bucket;
    }
}
