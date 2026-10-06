<?php
/**
 * Admin services for the Blockonomics governance console.
 *
 * Fully dependency-injected: the admin portal wires the WHMCS-side pieces
 * (Capsule-backed store, WHMCS installation secret, cURL transport), tests
 * substitute fakes. Nothing in this class trusts request data directly —
 * all persistence flows through Governance/Vault and every mutation is
 * audited twice (module audit + governance audit where an actor exists).
 *
 * @package Chs\Admin
 */

namespace Chs\Admin;

use CloudHost247\Blockonomics\Bridge;
use CloudHost247\Blockonomics\ConnectionTester;
use CloudHost247\Blockonomics\Governance;
use CloudHost247\Blockonomics\Policy;
use CloudHost247\Blockonomics\Vault;
use Chs\Core\Audit;
use Chs\Services\NotificationService;

class BlockonomicsAdmin
{
    /** @var Governance */
    private $governance;

    /** @var Vault */
    private $vault;

    /** @var callable fn(): array legacy params (getGatewayVariables output) */
    private $legacyLoader;

    /** @var ConnectionTester */
    private $tester;

    /** @var callable fn(string $setting, string $value): void mirror to legacy tblpaymentgateways */
    private $legacyMirror;

    public function __construct(
        Governance $governance,
        Vault $vault,
        callable $legacyLoader,
        ConnectionTester $tester = null,
        callable $legacyMirror = null
    ) {
        $this->governance = $governance;
        $this->vault = $vault;
        $this->legacyLoader = $legacyLoader;
        $this->tester = $tester ?: new ConnectionTester(function () {
            return ['code' => 0, 'body' => '', 'errno' => 1, 'error' => 'transport not wired'];
        });
        $this->legacyMirror = $legacyMirror ?: function ($setting, $value) {
            Bridge::mirrorToLegacy([$setting => $value]);
        };
    }

    /** Legacy params array (raw from WHMCS). */
    public function legacy()
    {
        $legacy = call_user_func($this->legacyLoader);
        return is_array($legacy) ? $legacy : [];
    }

    /** Effective runtime state for the admin UI. */
    public function panelState()
    {
        $legacy = $this->legacy();
        $this->governance->seedFromLegacy($legacy);
        $state = $this->governance->state();

        $effectiveKey = $this->effectiveApiKey();
        $etherscanKey = $this->effectiveEtherscanKey();

        return [
            'gateway_enabled'  => $state['gateway_enabled'],
            'btc_enabled'      => $state['btc_enabled'],
            'usdt_enabled'     => $state['usdt_enabled'],
            'confirmations'    => $state['confirmations'],
            'usdt_network'     => $state['usdt_network'],
            'network_display'  => $state['usdt_network'] !== '' ? Policy::networkDisplay($state['usdt_network']) : '',
            'network_is_test'  => $state['usdt_network'] !== '' ? Policy::networkIsTest($state['usdt_network']) : false,
            'api_key_set'      => $effectiveKey !== '',
            'api_key_mask'     => $effectiveKey !== '' ? Vault::MASK : '',
            'api_key_source'   => $this->vault->configured() ? 'CloudHost247 vault' : ($effectiveKey !== '' ? 'Legacy gateway settings' : 'Not configured'),
            'etherscan_set'    => $etherscanKey !== '',
            'etherscan_mask'   => $etherscanKey !== '' ? Vault::MASK : '',
            'usdt_address'     => isset($legacy['UsdtAddress']) ? trim((string) $legacy['UsdtAddress']) : '',
            'audit'            => $this->governance->store()->auditList(25),
            'seeded'           => $this->governance->hasBeenSeeded(),
        ];
    }

    /**
     * Persist toggles + confirmations + network (spec §34 validation inside
     * Governance::save). Mirror to legacy tblpaymentgateways afterwards.
     *
     * @throws \InvalidArgumentException on invalid combinations
     */
    public function saveSettings(array $input, $staffId, $ipHash = '')
    {
        $legacy = $this->legacy();
        $this->governance->seedFromLegacy($legacy);

        $effectiveKey = $this->effectiveApiKey();
        $payload = [
            'gateway_enabled'  => !empty($input['gateway_enabled']),
            'btc_enabled'      => !empty($input['btc_enabled']),
            'usdt_enabled'     => !empty($input['usdt_enabled']),
            'confirmations'    => isset($input['confirmations']) ? (int) $input['confirmations'] : 2,
            'usdt_network'     => isset($input['usdt_network']) ? (string) $input['usdt_network'] : '',
            'effective_api_key' => $effectiveKey,
            'usdt_address'     => isset($legacy['UsdtAddress']) ? $legacy['UsdtAddress'] : '',
            'etherscan_api_key' => $this->effectiveEtherscanKey(),
        ];

        $this->governance->save($payload, (int) $staffId, (string) $ipHash);
        $state = $this->governance->state();

        // Keep stock configgateways.php consistent (additive, never destructive).
        $this->mirror('btcEnabled', $state['btc_enabled'] ? 'on' : '');
        $this->mirror('usdtEnabled', $state['usdt_enabled'] ? 'on' : '');
        $this->mirror('Confirmations', (string) $state['confirmations']);
        $this->mirror('NetworkType', (string) $state['usdt_network']);

        Audit::admin($staffId, 'blockonomics.settings_saved', [
            'gateway' => $state['gateway_enabled'], 'btc' => $state['btc_enabled'],
            'usdt' => $state['usdt_enabled'], 'confirmations' => $state['confirmations'],
            'network' => $state['usdt_network'],
        ]);
        return 'Blockonomics settings saved.';
    }

    /** Replace the API key (write-only). */
    public function replaceApiKey($newKey, $staffId, $ipHash = '')
    {
        $newKey = trim((string) $newKey);
        if ($newKey === '') {
            throw new \InvalidArgumentException('API key cannot be empty.');
        }
        $this->vault->save($newKey);
        $this->governance->auditSetting('credentials.replaced', 'api_key', Vault::AUDIT_REDACTED, Vault::AUDIT_REDACTED, $staffId, $ipHash);
        Audit::admin($staffId, 'blockonomics.api_key_replaced', []);
        return 'API key replaced and stored encrypted.';
    }

    /** Replace the Etherscan verification key. */
    public function replaceEtherscanKey($newKey, $staffId, $ipHash = '')
    {
        $newKey = trim((string) $newKey);
        if ($newKey === '') {
            throw new \InvalidArgumentException('Etherscan API key cannot be empty.');
        }
        $this->vault->saveSlot('etherscan_api_key', $newKey);
        $this->governance->auditSetting('credentials.replaced', 'etherscan_api_key', Vault::AUDIT_REDACTED, Vault::AUDIT_REDACTED, $staffId, $ipHash);
        Audit::admin($staffId, 'blockonomics.etherscan_key_replaced', []);
        return 'Etherscan API key replaced and stored encrypted.';
    }

    /** Update USDT receiving address (public by nature, audited). */
    public function updateUsdtAddress($address, $staffId, $ipHash = '')
    {
        $address = trim((string) $address);
        if ($address !== '' && !preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            throw new \InvalidArgumentException('USDT address must be a valid 0x… Ethereum-style address.');
        }
        $this->mirror('UsdtAddress', $address);
        $this->governance->auditSetting('usdt.address_changed', 'usdt_address', '', $address !== '' ? '0x…' . substr($address, -4) : '(cleared)', $staffId, $ipHash);
        Audit::admin($staffId, 'blockonomics.usdt_address_changed', ['suffix' => $address !== '' ? substr($address, -4) : '']);
        return 'USDT receiving address updated.';
    }

    /**
     * Server-side connection test with sanitized output + audit (spec §14).
     */
    public function testConnection($staffId, $ipHash = '')
    {
        $key = $this->effectiveApiKey();
        $result = $this->tester->test($key);
        $this->governance->auditSetting('connection.tested', 'connection', '', $result['category'], $staffId, $ipHash);
        Audit::admin($staffId, 'blockonomics.connection_tested', ['result' => $result['category']]);
        return $result;
    }

    /** Effective key: vault first, legacy fallback (never echoed by callers). */
    public function effectiveApiKey()
    {
        $key = $this->vault->read();
        if ($key !== '') {
            return $key;
        }
        $legacy = $this->legacy();
        return isset($legacy['ApiKey']) ? trim((string) $legacy['ApiKey']) : '';
    }

    public function effectiveEtherscanKey()
    {
        $key = $this->vault->readSlot('etherscan_api_key');
        if ($key !== '') {
            return $key;
        }
        $legacy = $this->legacy();
        return isset($legacy['EtherScanAPIKey']) ? trim((string) $legacy['EtherScanAPIKey']) : '';
    }

    private function mirror($setting, $value)
    {
        try {
            call_user_func($this->legacyMirror, $setting, $value);
        } catch (\Throwable $e) {
            \Chs\Core\Logger::error('Blockonomics legacy mirror failed', ['message' => $e->getMessage()]);
        }
    }
}
