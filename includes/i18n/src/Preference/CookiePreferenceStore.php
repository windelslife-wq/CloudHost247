<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Remembers an *explicit* language / currency choice in a cookie, so a
 * returning visitor who never created an account keeps the language and
 * currency they picked.
 *
 * Rules this store enforces, all of them deliberate:
 *
 *  - Only the two explicit preference keys are stored. Detected values live in
 *    the session only: baking an IP guess into a year-long cookie would freeze
 *    a possibly-wrong answer in place and make it look like a choice.
 *  - Every value read back is re-validated against a server-side allowlist. A
 *    hand-edited cookie containing `language=../../etc/passwd` or
 *    `currency=99999` is ignored exactly like no cookie at all.
 *  - The cookie is HttpOnly (no JavaScript needs it), SameSite=Lax (so a
 *    cross-site navigation still carries it but a form POST from another site
 *    does not) and Secure whenever the request is HTTPS.
 *  - An invalid or cleared value deletes the cookie rather than storing a
 *    broken one.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Preference;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;

final class CookiePreferenceStore implements
    PreferenceStoreInterface,
    NamedPreferenceStoreInterface,
    FlushablePreferenceStoreInterface
{
    /** Cookie name prefix, so the switcher never collides with WHMCS. */
    const PREFIX = 'ch247_';

    /** Default lifetime: 180 days. */
    const DEFAULT_TTL = 15552000;

    /** @var array<string,string> */
    private $cookies;

    /** @var string[] Keys this store is allowed to persist. */
    private $keys;

    /** @var array<string,callable> */
    private $validators;

    /** @var array<string,mixed> */
    private $options;

    /** @var array<string,array{value:?string,delete:bool}> */
    private $pending = array();

    /** @var array<string,string> Values written this request, for immediate reads. */
    private $memory = array();

    /** @var callable|null */
    private $sender;

    /** @var bool */
    private $flushed = false;

    /**
     * @param array                $cookies    Usually $_COOKIE.
     * @param array                $options    ttl, path, domain, secure,
     *                                         httponly, samesite, keys.
     * @param array<string,callable> $validators key => fn(mixed): string|null
     * @param callable|null        $sender     fn(name, value, options): bool;
     *                                         defaults to PHP's setcookie().
     */
    public function __construct(array $cookies = array(), array $options = array(), array $validators = array(), $sender = null)
    {
        $this->cookies = $cookies;
        $this->options = $options + array(
            'ttl'      => self::DEFAULT_TTL,
            'path'     => '/',
            'domain'   => '',
            'secure'   => null,
            'httponly' => true,
            'samesite' => 'Lax',
        );
        $this->keys = isset($options['keys']) && is_array($options['keys'])
            ? array_values($options['keys'])
            : array('language', 'currency');
        $this->validators = $validators;
        $this->sender = is_callable($sender) ? $sender : null;
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        if (!in_array($key, $this->keys, true)) {
            return $default;
        }
        if (array_key_exists($key, $this->memory)) {
            return $this->memory[$key];
        }

        $name = $this->name($key);
        if (!isset($this->cookies[$name]) || is_array($this->cookies[$name])) {
            return $default;
        }

        $value = $this->validate($key, $this->cookies[$name]);
        if ($value === null) {
            // A tampered cookie is not a preference. Drop it so the browser
            // stops sending it.
            $this->queue($key, null);

            return $default;
        }

        return $value;
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function set(string $key, $value): void
    {
        if (!in_array($key, $this->keys, true)) {
            return; // Detected values are not cookie material.
        }

        if ($value === null || $value === '') {
            unset($this->memory[$key]);
            $this->queue($key, null);

            return;
        }

        $valid = $this->validate($key, $value);
        if ($valid === null) {
            // Never write a value the server would reject on the next request.
            unset($this->memory[$key]);
            $this->queue($key, null);

            return;
        }

        $this->memory[$key] = $valid;
        $this->queue($key, $valid);
    }

    /**
     * @return bool
     */
    public function isWritable(): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function storeName(): string
    {
        return 'cookie';
    }

    /**
     * Send the queued cookies.
     *
     * @return array<string,array<string,mixed>>
     */
    public function flush(): array
    {
        $sent = array();

        if ($this->flushed) {
            return $sent;
        }
        $this->flushed = true;

        // With a custom transport the caller owns delivery (tests, or a host
        // that buffers its own headers). With PHP's own setcookie() there is
        // nothing to be done once the response has started: the in-memory
        // values keep the rest of this request consistent, and the next page
        // writes the cookie.
        if ($this->sender === null && headers_sent()) {
            return $sent;
        }

        foreach ($this->pending as $key => $instruction) {
            $name = $this->name($key);
            $options = $this->cookieOptions($instruction['value'] === null);
            if ($this->sender !== null) {
                $ok = (bool) call_user_func($this->sender, $name, $instruction['value'], $options);
            } else {
                $ok = $this->send($name, $instruction['value'], $options);
            }
            if ($ok) {
                $sent[$name] = array('value' => $instruction['value'], 'options' => $options);
            }
        }

        $this->pending = array();

        return $sent;
    }

    /**
     * What would be sent, without sending it. Used by tests and by the admin
     * report.
     *
     * @return array<string,array{value:?string,options:array<string,mixed>}>
     */
    public function pending(): array
    {
        $out = array();
        foreach ($this->pending as $key => $instruction) {
            $out[$this->name($key)] = array(
                'value'   => $instruction['value'],
                'options' => $this->cookieOptions($instruction['value'] === null),
            );
        }

        return $out;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public function name(string $key): string
    {
        return self::PREFIX . $key;
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return string|null The sanitised value, or null when unacceptable.
     */
    private function validate(string $key, $value)
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 64) {
            return null;
        }
        // No control characters, no path separators, no markup: a cookie is
        // untrusted input even though we set it ourselves.
        if (preg_match('/[\x00-\x1F\x7F<>"\'\\\\]/', $value) === 1) {
            return null;
        }
        if (isset($this->validators[$key]) && is_callable($this->validators[$key])) {
            $checked = call_user_func($this->validators[$key], $value);

            return $checked === null ? null : (string) $checked;
        }

        return $value;
    }

    /**
     * @param string      $key
     * @param string|null $value
     *
     * @return void
     */
    private function queue(string $key, $value): void
    {
        $this->pending[$key] = array('value' => $value, 'delete' => $value === null);
    }

    /**
     * @param bool $expire
     *
     * @return array<string,mixed>
     */
    private function cookieOptions(bool $expire): array
    {
        $secure = $this->options['secure'];
        if ($secure === null) {
            // Follow the request: a Secure cookie set over plain HTTP is
            // silently dropped by the browser.
            $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
            $secure = $https;
        }

        return array(
            'expires'  => $expire ? time() - 3600 : time() + max(60, (int) $this->options['ttl']),
            'path'     => (string) $this->options['path'],
            'domain'   => (string) $this->options['domain'],
            'secure'   => (bool) $secure,
            'httponly' => (bool) $this->options['httponly'],
            'samesite' => (string) $this->options['samesite'],
        );
    }

    /**
     * @param string      $name
     * @param string|null $value
     * @param array       $options
     *
     * @return bool
     */
    private function send(string $name, $value, array $options): bool
    {
        if ($value === null) {
            return (bool) @setcookie($name, '', $options);
        }

        return (bool) @setcookie($name, $value, $options);
    }
}
