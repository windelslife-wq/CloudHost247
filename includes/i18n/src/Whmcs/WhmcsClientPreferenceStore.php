<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Uses WHMCS's native account language and stores the browsing currency
 * separately from the account's billing currency. NEVER write tblclients.currency:
 * WHMCS historical invoice currency is tied to that field. A switcher must not
 * reinterpret old invoices, services or transactions by changing it.
 *
 * The additive mod_cloudhost247_i18n_preferences table is created on addon
 * activation. If it is absent/read-only, the session preference still works.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Whmcs;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;

final class WhmcsClientPreferenceStore implements PreferenceStoreInterface
{
    /** @var callable|null fn(): int|null — current client id. */
    private $clientIdResolver;

    /** @var callable|null fn(string $table, array $values, int $id): void */
    private $writer;

    /** @var callable|null fn(int $id): array|object|null */
    private $reader;

    /** @var int|null */
    private $resolvedClientId;

    /** @var bool */
    private $resolved = false;

    /**
     * @param callable|null $clientIdResolver
     * @param callable|null $reader           fn(int $id): row|null
     * @param callable|null $writer           fn(int $id, array $values): void
     */
    public function __construct($clientIdResolver = null, $reader = null, $writer = null)
    {
        $this->clientIdResolver = $clientIdResolver;
        $this->reader = $reader;
        $this->writer = $writer;
    }

    public function get(string $key, $default = null)
    {
        $clientId = $this->clientId();
        if ($clientId === null) {
            return $default;
        }

        $row = $this->read($clientId);
        if ($row === null) {
            return $default;
        }

        if ($key === 'language') {
            return isset($row['language']) && $row['language'] !== '' ? WhmcsLocale::toCode((string) $row['language']) : $default;
        }
        if ($key === 'currency') {
            return !empty($row['display_currency']) ? (int) $row['display_currency']
                : (!empty($row['currency']) ? (int) $row['currency'] : $default);
        }

        return $default;
    }

    public function set(string $key, $value)
    {
        $clientId = $this->clientId();
        if ($clientId === null) {
            return;
        }

        if ($key === 'language') {
            $this->write($clientId, array('language' => WhmcsLocale::toWhmcsName((string) $value)));
        } elseif ($key === 'currency') {
            $this->write($clientId, array('display_currency' => (int) $value));
        }
    }

    public function isWritable(): bool
    {
        return $this->clientId() !== null;
    }

    /**
     * @return int|null
     */
    private function clientId()
    {
        if ($this->resolved) {
            return $this->resolvedClientId;
        }
        $this->resolved = true;

        if ($this->clientIdResolver !== null) {
            $id = call_user_func($this->clientIdResolver);
        } else {
            $id = null;
            if (class_exists('WHMCS\\Authentication\\CurrentUser')) {
                try {
                    $user = \WHMCS\Authentication\CurrentUser::get();
                    if ($user !== null && $user->client !== null) {
                        $id = (int) $user->client->id;
                    }
                } catch (\Throwable $e) {
                    $id = null;
                }
            }
            if ($id === null && isset($_SESSION['uid']) && is_numeric($_SESSION['uid'])) {
                $id = (int) $_SESSION['uid'];
            }
        }

        $this->resolvedClientId = $id !== null && $id > 0 ? (int) $id : null;

        return $this->resolvedClientId;
    }

    /**
     * @param int $clientId
     *
     * @return array|null
     */
    private function read(int $clientId)
    {
        if ($this->reader !== null) {
            $row = call_user_func($this->reader, $clientId);

            return is_array($row) ? $row : (is_object($row) ? get_object_vars($row) : null);
        }

        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return null;
        }

        try {
            $row = \WHMCS\Database\Capsule::table('tblclients')
                ->select(array('id', 'language', 'currency'))
                ->where('id', $clientId)
                ->first();

            if ($row === null) {
                return null;
            }
            $values = get_object_vars($row);
            try {
                $preference = \WHMCS\Database\Capsule::table('mod_cloudhost247_i18n_preferences')
                    ->where('client_id', $clientId)->first();
                if ($preference && !empty($preference->display_currency)) {
                    $values['display_currency'] = (int) $preference->display_currency;
                }
            } catch (\Throwable $e) {
                // Addon not activated yet: do not create tables on a page view.
            }
            return $values;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param int   $clientId
     * @param array $values
     *
     * @return void
     */
    private function write(int $clientId, array $values)
    {
        if ($this->writer !== null) {
            call_user_func($this->writer, $clientId, $values);

            return;
        }

        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return;
        }

        try {
            if (isset($values['language'])) {
                \WHMCS\Database\Capsule::table('tblclients')->where('id', $clientId)
                    ->update(array('language' => $values['language']));
            }
            if (isset($values['display_currency'])) {
                \WHMCS\Database\Capsule::table('mod_cloudhost247_i18n_preferences')
                    ->updateOrInsert(array('client_id' => $clientId), array('display_currency' => $values['display_currency']));
            }
        } catch (\Throwable $e) {
            // A read-only database must never break page rendering; the
            // session store still carries the preference for this visit.
        }
    }
}
