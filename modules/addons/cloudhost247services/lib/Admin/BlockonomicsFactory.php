<?php
/**
 * Wiring for the Blockonomics admin console inside WHMCS.
 *
 * The factory requires the gateway governance layer from its canonical
 * location inside the gateway itself — there is exactly ONE Blockonomics
 * implementation and this addon reads/enhances it, never duplicates.
 *
 * @package Chs\Admin
 */

namespace Chs\Admin;

use CloudHost247\Blockonomics\CapsuleStore;
use CloudHost247\Blockonomics\ConnectionTester;
use CloudHost247\Blockonomics\Governance;
use CloudHost247\Blockonomics\Vault;

class BlockonomicsFactory
{
    /** Absolute path of the governance autoloader inside the gateway. */
    public static function governanceAutoloadPath()
    {
        // modules/addons/cloudhost247services/lib/Admin → modules/gateways/blockonomics/cloudhost247
        return dirname(__DIR__, 4) . '/gateways/blockonomics/cloudhost247/autoload.php';
    }

    /** Is the governance layer reachable in this install? */
    public static function available()
    {
        return is_file(self::governanceAutoloadPath());
    }

    public static function autoload()
    {
        require_once self::governanceAutoloadPath();
    }

    /** @return BlockonomicsAdmin configured for production WHMCS */
    public static function admin()
    {
        self::autoload();

        $store = new CapsuleStore();
        $governance = new Governance($store);

        $secret = isset($GLOBALS['cc_encryption_hash']) ? (string) $GLOBALS['cc_encryption_hash'] : '';
        $vault = new Vault($store, $secret);

        $legacyLoader = function () {
            if (!function_exists('getGatewayVariables')) {
                require_once dirname(__DIR__, 5) . '/includes/gatewayfunctions.php';
            }
            $params = getGatewayVariables('blockonomics');
            return is_array($params) ? $params : [];
        };

        return new BlockonomicsAdmin(
            $governance,
            $vault,
            $legacyLoader,
            ConnectionTester::withCurl()
        );
    }

    /** @return BlockonomicsTransactions */
    public static function transactions()
    {
        self::autoload();
        return new BlockonomicsTransactions();
    }

    /**
     * Effective confirmations + time period for normalized display.
     *
     * @return array{confirmations:int,time_period_min:int}
     */
    public static function displayPolicy(callable $legacyLoader)
    {
        self::autoload();
        $legacy = $legacyLoader();
        $conf = isset($legacy['Confirmations']) && $legacy['Confirmations'] !== '' ? (int) $legacy['Confirmations'] : 2;
        $period = isset($legacy['TimePeriod']) && $legacy['TimePeriod'] !== '' ? (int) $legacy['TimePeriod'] : 10;
        try {
            $state = (new Governance(new CapsuleStore()))->state();
            $conf = $state['confirmations'];
        } catch (\Throwable $ignored) {
        }
        return ['confirmations' => max(0, $conf), 'time_period_min' => max(1, $period)];
    }
}
