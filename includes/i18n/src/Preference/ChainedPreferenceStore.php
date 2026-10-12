<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Reads from several stores in priority order and writes to the first
 * writable one.
 *
 * The production chain is: session (what I just clicked) → client account
 * (what I saved on my profile) → nothing (fall back to the system default).
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Preference;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;

final class ChainedPreferenceStore implements PreferenceStoreInterface
{
    /** Sentinel returned by get() when no store in the chain has a value. */
    const NOT_FOUND = "\0ch247-not-found\0";

    /** @var PreferenceStoreInterface[] */
    private $stores;

    /**
     * @param PreferenceStoreInterface[] $stores Highest priority first.
     */
    public function __construct(array $stores)
    {
        $this->stores = array_values(array_filter($stores, function ($store) {
            return $store instanceof PreferenceStoreInterface;
        }));
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        foreach ($this->stores as $store) {
            $value = $store->get($key, self::NOT_FOUND);
            if ($value !== self::NOT_FOUND && $value !== null && $value !== '') {
                return $value;
            }
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
        $written = false;
        foreach ($this->stores as $store) {
            if ($store->isWritable()) {
                $store->set($key, $value);
                $written = true;
            }
        }

        // With nothing writable (CLI/cron) keep the value in memory so the
        // current request still behaves consistently.
        if (!$written) {
            foreach ($this->stores as $store) {
                $store->set($key, $value);
            }
        }
    }

    public function isWritable(): bool
    {
        foreach ($this->stores as $store) {
            if ($store->isWritable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return PreferenceStoreInterface[]
     */
    public function getStores(): array
    {
        return $this->stores;
    }
}
