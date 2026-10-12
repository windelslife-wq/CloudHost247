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

final class SessionPreferenceStore implements PreferenceStoreInterface
{
    /** @var string Namespace so we never collide with WHMCS session keys. */
    const NAMESPACE = 'ch247_i18n';

    /** @var array<string,mixed>|null */
    private $fallback;

    public function __construct()
    {
        // Fall back to an array when no session is running (CLI, cron, tests)
        // so the store degrades instead of warning.
        $this->fallback = PHP_SAPI === 'cli' ? array() : null;
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

        return array_key_exists($key, $bucket) ? $bucket[$key] : $default;
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

        if (!isset($_SESSION[self::NAMESPACE]) || !is_array($_SESSION[self::NAMESPACE])) {
            $_SESSION[self::NAMESPACE] = array();
        }
        if ($value === null) {
            unset($_SESSION[self::NAMESPACE][$key]);
        } else {
            $_SESSION[self::NAMESPACE][$key] = $value;
        }
    }

    public function isWritable(): bool
    {
        return $this->sessionActive();
    }

    /**
     * @return bool
     */
    private function sessionActive(): bool
    {
        return $this->fallback === null && isset($_SESSION) && is_array($_SESSION);
    }

    /**
     * @return array<string,mixed>
     */
    private function bucket(): array
    {
        return isset($_SESSION[self::NAMESPACE]) && is_array($_SESSION[self::NAMESPACE])
            ? $_SESSION[self::NAMESPACE]
            : array();
    }
}
