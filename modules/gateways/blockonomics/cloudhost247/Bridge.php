<?php
/**
 * Bridge between the stock Blockonomics WHMCS gateway and the CloudHost247
 * governance layer. Gateway code calls exactly these resolution points:
 *
 *   Bridge::state()                     effective governed state
 *   Bridge::assertCurrencyAllowed($c)   403-throw when the currency is off
 *   Bridge::isGatewayEnabled()          master switch
 *   Bridge::availableCurrencies()       filtered list for templates
 *   Bridge::resolveApiKey($legacy)      vault-first credential resolution
 *
 * On first use inside WHMCS, governance seeds itself from the legacy
 * tblpaymentgateways params (one-way, §31) and optionally mirrors governed
 * flags back so the stock `configgateways.php` page stays consistent.
 *
 * @package CloudHost247\Blockonomics
 */

namespace CloudHost247\Blockonomics;

class Bridge
{
    /** @var Governance|null */
    private static $governance;

    /** @var Vault|null */
    private static $vault;

    /** @var string */
    private static $installationSecret = '';

    /** Reset memoised instances (tests). */
    public static function reset()
    {
        self::$governance = null;
        self::$vault = null;
    }

    /** @internal store factory — overridable in tests */
    public static $storeFactory = null;

    private static function store()
    {
        if (is_callable(self::$storeFactory)) {
            return call_user_func(self::$storeFactory);
        }
        self::loadSecret();
        return new CapsuleStore();
    }

    private static function loadSecret()
    {
        if (self::$installationSecret !== '') {
            return;
        }
        // WHMCS exposes $cc_encryption_hash from configuration.php — the
        // standard installation-unique secret used by WHMCS 2-way encryption.
        if (isset($GLOBALS['cc_encryption_hash']) && $GLOBALS['cc_encryption_hash'] !== '') {
            self::$installationSecret = (string) $GLOBALS['cc_encryption_hash'];
        } elseif (defined('CHS_BLOCKONOMICS_SECRET')) {
            self::$installationSecret = (string) CHS_BLOCKONOMICS_SECRET;
        }
    }

    public static function governance()
    {
        if (self::$governance === null) {
            self::$governance = new Governance(self::store());
        }
        return self::$governance;
    }

    public static function vault()
    {
        if (self::$vault === null) {
            self::loadSecret();
            self::$vault = new Vault(self::governance()->store(), self::$installationSecret);
        }
        return self::$vault;
    }

    /**
     * Seed governance from legacy params once (spec §31). $legacy = result
     * of getGatewayVariables('blockonomics').
     */
    public static function ensureSeeded(array $legacy, $actorId = 0)
    {
        $gov = self::governance();
        if (!$gov->hasBeenSeeded()) {
            $gov->seedFromLegacy($legacy, (int) $actorId, self::ipHash());
            return;
        }
        // Store seeded before BCH came under governance: import its legacy
        // checkbox once so an install already taking BCH keeps taking it.
        $gov->backfillBch($legacy, (int) $actorId, self::ipHash());
    }

    /** Effective governed state (after auto-seed when legacy is given). */
    public static function state(array $legacy = null)
    {
        if ($legacy !== null) {
            self::ensureSeeded($legacy);
        }
        return self::governance()->state();
    }

    public static function isGatewayEnabled(array $legacy = null)
    {
        $state = self::state($legacy);
        return !empty($state['gateway_enabled']);
    }

    /**
     * Master switch AND at least one payable currency. This is the check
     * that decides whether checkout may be offered at all — `gateway on,
     * every currency off` is unavailable, not an empty picker.
     */
    public static function isCheckoutAvailable(array $legacy = null)
    {
        return Policy::anyCurrencyAvailable(self::state($legacy));
    }

    /**
     * Currencies customers may actually be offered right now.
     *
     * @return array<string,bool> e.g. ['btc'=>true,'bch'=>false,'usdt'=>false]
     */
    public static function availableCurrencies(array $legacy = null)
    {
        return Policy::availabilityMatrix(self::state($legacy));
    }

    /**
     * Fail-closed currency gate (spec §16). Callers convert the exception
     * to a 403 + safe message; it must never be caught and ignored.
     *
     * @throws PaymentUnavailableException
     */
    public static function assertCurrencyAllowed($currency, array $legacy = null)
    {
        Policy::assertPaymentAllowed(self::state($legacy), $currency);
    }

    /**
     * Vault-first API key resolution; legacy plaintext param is the
     * backward-compat fallback (spec §30). Return '' when unconfigured.
     */
    public static function resolveApiKey($legacyValue = '')
    {
        $key = self::vault()->read();
        if ($key !== '') {
            return $key;
        }
        return trim((string) $legacyValue);
    }

    /** Etherscan verification key — vault first, legacy param fallback. */
    public static function resolveEtherscanKey($legacyValue = '')
    {
        $key = self::vault()->readSlot('etherscan_api_key');
        if ($key !== '') {
            return $key;
        }
        return trim((string) $legacyValue);
    }

    /**
     * Mirror governed public flags into WHMCS tblpaymentgateways so the
     * stock gateway settings page and `getActiveCurrencies()` style legacy
     * reads keep telling the same truth (additive sync — never deletes).
     * Requires WHMCS Capsule; failures are logged, never fatal.
     */
    public static function mirrorToLegacy(array $state)
    {
        try {
            $map = [
                'btcEnabled'    => $state['btc_enabled'] ? 'on' : '',
                'bchEnabled'    => !empty($state['bch_enabled']) ? 'on' : '',
                'usdtEnabled'   => $state['usdt_enabled'] ? 'on' : '',
                'Confirmations' => (string) (int) $state['confirmations'],
                'NetworkType'   => (string) $state['usdt_network'],
            ];
            foreach ($map as $setting => $value) {
                $q = \WHMCS\Database\Capsule::table('tblpaymentgateways')
                    ->where('gateway', 'blockonomics')->where('setting', $setting);
                if ($q->count() > 0) {
                    $q->update(['value' => $value]);
                } else {
                    \WHMCS\Database\Capsule::table('tblpaymentgateways')
                        ->insert(['gateway' => 'blockonomics', 'setting' => $setting, 'value' => $value, 'order' => 0]);
                }
            }
        } catch (\Throwable $e) {
            error_log('cloudhost247 blockonomics mirror failed: ' . $e->getMessage());
        }
    }

    /** ip_hash per the module-wide convention (no raw IPs stored). */
    public static function ipHash()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli';
        return hash('sha256', $ip . self::$installationSecret);
    }
}
