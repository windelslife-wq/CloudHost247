<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * The outcome of "switch to this language / currency".
 *
 * Invalid input is a normal, expected event — a customer can bookmark a URL
 * with a currency that has since been disabled — so it is modelled as data
 * rather than an exception. `ok === false` always carries a reason and a
 * currency, so the caller can keep rendering.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class SelectionResult
{
    const ERROR_NONE = '';
    const ERROR_UNKNOWN = 'unknown';
    const ERROR_DISABLED = 'disabled';
    const ERROR_EMPTY = 'empty';

    /** @var bool */
    private $ok;

    /** @var mixed */
    private $value;

    /** @var string */
    private $error;

    /** @var bool */
    private $fallbackUsed;

    /** @var mixed What the customer asked for, verbatim. */
    private $requested;

    /**
     * @param bool   $ok
     * @param mixed  $value
     * @param string $error
     * @param bool   $fallbackUsed
     * @param mixed  $requested
     */
    public function __construct(bool $ok, $value, string $error = self::ERROR_NONE, bool $fallbackUsed = false, $requested = null)
    {
        $this->ok = $ok;
        $this->value = $value;
        $this->error = $error;
        $this->fallbackUsed = $fallbackUsed;
        $this->requested = $requested;
    }

    /**
     * @param mixed  $value
     * @param mixed  $requested
     *
     * @return self
     */
    public static function success($value, $requested = null)
    {
        return new self(true, $value, self::ERROR_NONE, false, $requested);
    }

    /**
     * @param mixed  $value      The safe value used instead.
     * @param string $error
     * @param mixed  $requested
     *
     * @return self
     */
    public static function failure($value, string $error, $requested = null)
    {
        return new self(false, $value, $error, true, $requested);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function usedFallback(): bool
    {
        return $this->fallbackUsed;
    }

    /**
     * @return mixed
     */
    public function getRequested()
    {
        return $this->requested;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'ok' => $this->ok,
            'error' => $this->error,
            'fallback_used' => $this->fallbackUsed,
            'requested' => $this->requested,
        );
    }
}
