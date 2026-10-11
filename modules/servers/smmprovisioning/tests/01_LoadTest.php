<?php
/**
 * C-3: production load chain + entry-point surface (real files, no doubles).
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    require_once c3_root() . '/modules/addons/smmaddon/lib/Helper.php';
    require_once c3_root() . '/modules/addons/smmaddon/lib/ApiClient.php';
    require_once dirname(__DIR__) . '/smmprovisioning.php';
    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) {
            $pass++;
        } else {
            $fail++;
            echo "FAIL: $label\n";
        }
    }

    echo "== entry functions ==\n";
    foreach (['smmprovisioning_MetaData', 'smmprovisioning_ConfigOptions', 'smmprovisioning_TestConnection',
        'smmprovisioning_CreateAccount', 'smmprovisioning_SuspendAccount', 'smmprovisioning_UnsuspendAccount',
        'smmprovisioning_TerminateAccount', 'smmprovisioning_ClientArea',
        'smmprovisioning_apiEndpoint', 'smmprovisioning_matchField'] as $fn) {
        check("entry $fn exists", function_exists($fn));
    }

    echo "== classes ==\n";
    check('real SmmAddon Helper loads', class_exists('SmmAddon\\Helper'));
    check('real SmmAddon ApiClient loads', class_exists('SmmAddon\\ApiClient'));

    echo "== metadata ==\n";
    $meta = smmprovisioning_MetaData();
    check('DisplayName', $meta['DisplayName'] === 'SMM Panel Provisioning');
    check('APIVersion', $meta['APIVersion'] === '1.1');
    check('RequiresServer', $meta['RequiresServer'] === true);
    $opts = smmprovisioning_ConfigOptions();
    check('six config options', count($opts) === 6 && isset($opts['smm_service_id'], $opts['quantity_field']));
    check('unsuspend is a no-op success', smmprovisioning_UnsuspendAccount(['serviceid' => 1]) === 'success');

    echo "\nload: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
