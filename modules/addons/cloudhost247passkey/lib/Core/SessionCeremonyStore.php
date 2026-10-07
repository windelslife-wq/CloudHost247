<?php
/** Server-side, per-session options needed to reconstruct library validator input. */

namespace CloudHost247\Passkey\Core;

class SessionCeremonyStore
{
    const SESSION_KEY = 'mod_ch247pk_webauthn_ceremonies';
    const MAX_PENDING = 8;

    public static function put($challengeHash, array $state)
    {
        SessionBinding::currentHash();
        self::assertHash($challengeHash);
        $ceremonies = self::readAll();
        $now = time();
        foreach ($ceremonies as $key => $entry) {
            if (!is_array($entry) || !isset($entry['expires_epoch']) || (int) $entry['expires_epoch'] <= $now) {
                unset($ceremonies[$key]);
            }
        }
        $state['expires_epoch'] = (int) ($state['expires_epoch'] ?? 0);
        if ($state['expires_epoch'] <= $now || !isset($state['options_json'])
            || !is_string($state['options_json']) || $state['options_json'] === ''
            || strlen($state['options_json']) > 262144) {
            throw new \InvalidArgumentException('Invalid or expired WebAuthn session state.');
        }
        $optionsData = json_decode($state['options_json'], true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($optionsData)) {
            throw new \InvalidArgumentException('WebAuthn session options are not valid JSON.');
        }
        $ceremonies[$challengeHash] = $state;
        while (count($ceremonies) > self::MAX_PENDING) {
            $oldestKey = null;
            $oldestCreated = PHP_INT_MAX;
            foreach ($ceremonies as $key => $entry) {
                $created = isset($entry['created_epoch']) ? (int) $entry['created_epoch'] : 0;
                if ($created < $oldestCreated) {
                    $oldestCreated = $created;
                    $oldestKey = $key;
                }
            }
            if ($oldestKey === null) {
                break;
            }
            unset($ceremonies[$oldestKey]);
        }
        $_SESSION[self::SESSION_KEY] = $ceremonies;
    }

    public static function peek($challengeHash)
    {
        SessionBinding::currentHash();
        self::assertHash($challengeHash);
        $ceremonies = self::readAll();
        if (!isset($ceremonies[$challengeHash]) || !is_array($ceremonies[$challengeHash])) {
            return null;
        }
        $state = $ceremonies[$challengeHash];
        if (!isset($state['expires_epoch']) || (int) $state['expires_epoch'] <= time()) {
            unset($ceremonies[$challengeHash]);
            $_SESSION[self::SESSION_KEY] = $ceremonies;
            return null;
        }
        if (!isset($state['options_json']) || !is_string($state['options_json'])
            || $state['options_json'] === '' || strlen($state['options_json']) > 262144) {
            unset($ceremonies[$challengeHash]);
            $_SESSION[self::SESSION_KEY] = $ceremonies;
            return null;
        }
        $optionsData = json_decode($state['options_json'], true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($optionsData)) {
            unset($ceremonies[$challengeHash]);
            $_SESSION[self::SESSION_KEY] = $ceremonies;
            return null;
        }
        return $state;
    }

    public static function remove($challengeHash)
    {
        SessionBinding::currentHash();
        self::assertHash($challengeHash);
        $ceremonies = self::readAll();
        unset($ceremonies[$challengeHash]);
        $_SESSION[self::SESSION_KEY] = $ceremonies;
    }

    private static function readAll()
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return [];
        }
        if (!is_array($_SESSION[self::SESSION_KEY])) {
            throw new \RuntimeException('Passkey session state is malformed.');
        }
        return $_SESSION[self::SESSION_KEY];
    }

    private static function assertHash($hash)
    {
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \InvalidArgumentException('Invalid Passkey challenge session key.');
        }
    }
}
