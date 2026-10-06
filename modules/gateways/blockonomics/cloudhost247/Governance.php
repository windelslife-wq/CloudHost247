<?php
/**
 * Authoritative runtime configuration for the Blockonomics gateway
 * (spec §6/§32/§33): the CloudHost247 governance store decides who can pay
 * with what. Legacy WHMCS tblpaymentgateways params are a compat source —
 * on first access an empty governance store is seeded from them (spec §31)
 * and afterwards governs exclusively.
 *
 * @package CloudHost247\Blockonomics
 */

namespace CloudHost247\Blockonomics;

class Governance
{
    const KEY_GATEWAY = 'gateway_enabled';
    const KEY_BTC     = 'btc_enabled';
    const KEY_USDT    = 'usdt_enabled';
    const KEY_CONF    = 'confirmations';
    const KEY_NETWORK = 'usdt_network';
    const KEY_SEEDED  = 'seeded_from_legacy';
    const KEY_ETHERSCAN = 'etherscan_api_key_present';

    /** @var GovernanceStoreInterface */
    private $store;

    public function __construct(GovernanceStoreInterface $store)
    {
        $this->store = $store;
        $this->store->ensureSchema();
    }

    public function store()
    {
        return $this->store;
    }

    /**
     * Effective state. Missing keys are fail-closed (false / safest value).
     */
    public function state()
    {
        return [
            'gateway_enabled' => $this->bool($this->store->get(self::KEY_GATEWAY)),
            'btc_enabled'     => $this->bool($this->store->get(self::KEY_BTC)),
            'usdt_enabled'    => $this->bool($this->store->get(self::KEY_USDT)),
            'confirmations'   => Policy::normalizeConfirmations(
                $this->store->get(self::KEY_CONF) === null ? 2 : (int) $this->store->get(self::KEY_CONF)
            ),
            'usdt_network'    => $this->network(),
        ];
    }

    /** Network string ('' when not configured). */
    public function network()
    {
        $n = (string) $this->store->get(self::KEY_NETWORK);
        return Policy::isSupportedNetwork($n) ? $n : ($n !== '' && $n !== '0' ? $n : '');
    }

    public function hasBeenSeeded()
    {
        return $this->store->get(self::KEY_SEEDED) !== null;
    }

    /**
     * One-way import from legacy WHMCS gateway params (spec §31).
     * Never runs twice; explicit `force` intentionally unsupported.
     */
    public function seedFromLegacy(array $legacy, $actorId = 0, $ipHash = '')
    {
        if ($this->hasBeenSeeded()) {
            return false;
        }
        $btc  = $this->legacyBool(isset($legacy['btcEnabled']) ? $legacy['btcEnabled'] : null);
        $usdt = $this->legacyBool(isset($legacy['usdtEnabled']) ? $legacy['usdtEnabled'] : null);
        // Master switch: an existing working install (API key present, at
        // least one currency on) keeps operating after upgrade.
        $master = ($btc || $usdt) && isset($legacy['ApiKey']) && trim((string) $legacy['ApiKey']) !== '';

        $conf = Policy::normalizeConfirmations(
            isset($legacy['Confirmations']) && $legacy['Confirmations'] !== '' ? (int) $legacy['Confirmations'] : 2
        );
        $network = '';
        if (isset($legacy['NetworkType']) && Policy::isSupportedNetwork($legacy['NetworkType'])) {
            $network = (string) $legacy['NetworkType'];
        }

        $this->store->set(self::KEY_GATEWAY, $master ? '1' : '0');
        $this->store->set(self::KEY_BTC, $btc ? '1' : '0');
        $this->store->set(self::KEY_USDT, $usdt ? '1' : '0');
        $this->store->set(self::KEY_CONF, (string) $conf);
        $this->store->set(self::KEY_NETWORK, $network);
        $this->store->set(self::KEY_SEEDED, date('c'));
        $this->writeAudit($actorId, 'governance.seeded', 'all', '', json_encode([
            'gateway' => $master, 'btc' => $btc, 'usdt' => $usdt,
            'confirmations' => $conf, 'network' => $network,
        ]), $ipHash);
        return true;
    }

    /**
     * Full save from the admin console with per-key audit lines (spec §28).
     * $input: ['gateway_enabled'=>bool,'btc_enabled'=>,'usdt_enabled'=>,
     *          'confirmations'=>int,'usdt_network'=>string]
     *
     * @throws \InvalidArgumentException on invalid combinations (spec §34)
     */
    public function save(array $input, $actorId = 0, $ipHash = '')
    {
        $before = $this->state();

        $gateway = !empty($input['gateway_enabled']);
        $btc = !empty($input['btc_enabled']);
        $usdt = !empty($input['usdt_enabled']);
        $conf = Policy::normalizeConfirmations(isset($input['confirmations']) ? $input['confirmations'] : 2);
        $network = isset($input['usdt_network']) ? (string) $input['usdt_network'] : '';

        if ($network !== '' && !Policy::isSupportedNetwork($network)) {
            throw new \InvalidArgumentException('Unsupported USDT network.');
        }

        // Readiness validation (spec §34): refuse an enabled-but-dead config.
        $problems = [];
        if ($btc || $gateway && $btc) {
            list($ok, $why) = Policy::btcReadiness(['api_key' => isset($input['effective_api_key']) ? $input['effective_api_key'] : '']);
            if ($btc && !$ok) {
                $problems[] = 'BTC: ' . $why;
            }
        }
        if ($usdt) {
            list($ok, $why) = Policy::usdtReadiness([
                'usdt_address'      => isset($input['usdt_address']) ? $input['usdt_address'] : '',
                'usdt_network'      => $network,
                'etherscan_api_key' => isset($input['etherscan_api_key']) ? $input['etherscan_api_key'] : '',
            ]);
            if (!$ok) {
                $problems[] = 'USDT: ' . $why;
            }
        }
        if (empty($input['effective_api_key']) && ($gateway || $btc || $usdt)) {
            $problems[] = 'Blockonomics: API key is required before the gateway can be enabled.';
        }
        if ($problems) {
            throw new \InvalidArgumentException(implode(' ', $problems));
        }

        $this->putAudited(self::KEY_GATEWAY, $before['gateway_enabled'], $gateway, $actorId, $ipHash, 'gateway');
        $this->putAudited(self::KEY_BTC, $before['btc_enabled'], $btc, $actorId, $ipHash, 'btc');
        $this->putAudited(self::KEY_USDT, $before['usdt_enabled'], $usdt, $actorId, $ipHash, 'usdt');
        if ((int) $before['confirmations'] !== $conf) {
            $this->auditSetting('confirmations.changed', 'confirmations', (string) $before['confirmations'], (string) $conf, $actorId, $ipHash);
        }
        $this->store->set(self::KEY_CONF, (string) $conf);

        $beforeNetwork = $before['usdt_network'] !== '' ? $before['usdt_network'] : '';
        if ($beforeNetwork !== $network) {
            $this->auditSetting('usdt.network_changed', 'usdt_network', $beforeNetwork, $network, $actorId, $ipHash);
        }
        $this->store->set(self::KEY_NETWORK, $network);
    }

    /** Single-flag flip convenience, audited. */
    public function flip($key, $enabled, $actorId = 0, $ipHash = '')
    {
        $current = $this->bool($this->store->get($key));
        $this->putAudited($key, $current, (bool) $enabled, $actorId, $ipHash, $key);
    }

    private function putAudited($key, $was, $now, $actorId, $ipHash, $label)
    {
        if ($was === $now) {
            $this->store->set($key, $now ? '1' : '0');
            return;
        }
        $this->store->set($key, $now ? '1' : '0');
        $action = $label . ($now ? '.enabled' : '.disabled');
        $this->auditSetting($action, $key, $was ? 'on' : 'off', $now ? 'on' : 'off', $actorId, $ipHash);
    }

    public function auditSetting($action, $setting, $oldValue, $newValue, $actorId, $ipHash)
    {
        $this->writeAudit($actorId, $action, $setting, $oldValue, $newValue, $ipHash);
    }

    /**
     * Never call this with secret material — redact first (Vault::AUDIT_REDACTED).
     */
    private function writeAudit($actorId, $action, $setting, $oldValue, $newValue, $ipHash)
    {
        $this->store->audit([
            'actor_id'  => (int) $actorId,
            'action'    => $action,
            'setting'   => $setting,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'ip_hash'   => $ipHash,
            'created_at' => time(),
        ]);
    }

    private function bool($v)
    {
        return $v === '1' || $v === 1 || $v === true || $v === 'true' || $v === 'on';
    }

    private function legacyBool($v)
    {
        return $v === 'on' || $v === '1' || $v === 1 || $v === true;
    }
}
