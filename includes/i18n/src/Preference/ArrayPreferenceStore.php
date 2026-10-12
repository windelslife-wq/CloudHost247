<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * In-memory preference store. Used by the test suite and as a safe default
 * when no session is available (CLI, cron).
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Preference;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;

final class ArrayPreferenceStore implements PreferenceStoreInterface
{
    /** @var array<string,mixed> */
    private $values;

    /** @var bool */
    private $writable;

    /**
     * @param array $values
     * @param bool  $writable
     */
    public function __construct(array $values = array(), bool $writable = true)
    {
        $this->values = $values;
        $this->writable = $writable;
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function set(string $key, $value)
    {
        if ($value === null) {
            unset($this->values[$key]);

            return;
        }
        $this->values[$key] = $value;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
