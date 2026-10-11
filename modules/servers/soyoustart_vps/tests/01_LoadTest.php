<?php
/**
 * C-4: module load chain + WHMCS entry-point surface.
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
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
    $entries = [
        'soyoustart_vps_MetaData',
        'soyoustart_vps_ConfigOptions',
        'soyoustart_vps_CreateAccount',
        'soyoustart_vps_Renew',
        'soyoustart_vps_SuspendAccount',
        'soyoustart_vps_UnsuspendAccount',
        'soyoustart_vps_TerminateAccount',
        'soyoustart_vps_AdminServicesTabFields',
        'soyoustart_vps_AdminServicesTabFieldsSave',
        'soyoustart_vps_AdminCustomButtonArray',
        'soyoustart_vps_rescuereboot',
        'soyoustart_vps_rebootServer',
        'soyoustart_vps_console',
        'soyoustart_vps_poweron',
        'soyoustart_vps_poweroff',
        'soyoustart_vps_ClientArea',
        'createCustomFields',
    ];
    foreach ($entries as $fn) {
        check("entry $fn exists", function_exists($fn));
    }
    check('VS-1: typo soyostart_vps_MetaData is gone', !function_exists('soyostart_vps_MetaData'));

    echo "== helpers ==\n";
    foreach (['soyoustart_vps_cfgPart', 'soyoustart_vps_powerAction', 'soyoustart_vps_firstIp', 'soyoustart_vps_consoleScript'] as $fn) {
        check("helper $fn exists", function_exists($fn));
    }

    echo "== metadata values ==\n";
    $meta = soyoustart_vps_MetaData();
    check('DisplayName', $meta['DisplayName'] === 'soyoustart_vps');
    check('APIVersion', $meta['APIVersion'] === '1.0.1');
    check('RequiresServer', $meta['RequiresServer'] === true);
    $buttons = soyoustart_vps_AdminCustomButtonArray();
    check('admin buttons intact', $buttons === [
        'Reboot Server' => 'rebootServer',
        'Console' => 'console',
        'Rescue Reboot' => 'rescuereboot',
        'Power On' => 'poweron',
        'Power Off' => 'poweroff',
    ]);

    echo "== classes ==\n";
    check('C-4 lib class loads', class_exists('WHMCS\\Module\\Server\\SoyoustartVps\\SoyoustartServer'));
    check('sibling C-5 lib class loads', class_exists('WHMCS\\Module\\Server\\Soyoustart\\SoyoustartServer'));
    check('addon ApiCall loads', class_exists('WGSModule\\Soyoustart\\classes\\ApiCall'));
    check('addon Configuration loads', class_exists('WGSModule\\Soyoustart\\classes\\Configuration'));
    check('addon Helper loads', class_exists('WHMCS\\Module\\Addon\\Soyoustart\\Helper'));
    check('addon SafeLog loads', class_exists('WHMCS\\Module\\Addon\\Soyoustart\\SafeLog'));

    echo "== lib methods ==\n";
    $lib = 'WHMCS\\Module\\Server\\SoyoustartVps\\SoyoustartServer';
    foreach (['escapeHtml', 'getServiceConfigs', 'serviceInfoHtml', 'serviceMonitoringHtml',
        'snapshotInfoHtml', 'diskInfoHtml', 'ipInfoHtml', 'featureHtml', 'getDiskData', 'getipData',
        'cretateBackupHtml', 'cretateSnapshotHtml', 'createIpListHtml', 'createIpDetailsHtmlvps',
        'formateBillingCycle', 'createDiskListHtml', 'createAdditionalIpInvoice', 'InsertIpData',
        'createInvoice'] as $m) {
        check("lib method $m exists", method_exists($lib, $m));
    }

    echo "== hooks ==\n";
    check('AdminAreaHeadOutput registered', isset($GLOBALS['__c4_hooks']['AdminAreaHeadOutput']));
    check('InvoicePaid registered', isset($GLOBALS['__c4_hooks']['InvoicePaid']));

    echo "\nload: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
