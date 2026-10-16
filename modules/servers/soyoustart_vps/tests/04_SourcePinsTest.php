<?php
/**
 * C-4: source pins for fixes that only execute against live WHMCS/OVH.
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
    $mod = dirname(__DIR__);
    $entry = file_get_contents($mod . '/soyoustart_vps.php');
    $hooks = file_get_contents($mod . '/hooks.php');
    $libsrc = file_get_contents($mod . '/lib/SoyoustartServer.php');
    $tpl = file_get_contents($mod . '/templates/clientareanew.tpl');

    echo "== removed hazards ==\n";
    check('VS-2: debug gate gone', strpos($hooks, 'testettdttd') === false);
    check('VS-2: no $_GET left in InvoicePaid', strpos($hooks, '$_GET') === false);
    check('VS-6: ststus gone', strpos($entry, 'ststus') === false);
    check('VS-9: die("kk") gone', strpos($entry, 'die("kk")') === false);
    check('VS-23: undefined createIPsHtml call gone', strpos($entry, 'createIPsHtml') === false);
    check('VS-1: metadata typo gone', strpos($entry, 'soyostart_vps_MetaData') === false);
    check('VS-24: spaced currency keys gone', strpos($tpl, '" rate"') === false && strpos($tpl, '" prefix"') === false);
    check('lib modal raw array-prop read gone', strpos($libsrc, "' . \$iptInfo->ipAddress . '") === false);
    check('lib modal ships static empty data-ipAddress', strpos($libsrc, 'data-ipAddress=""') !== false);

    echo "== added guards present ==\n";
    check('InvoicePaid misconfig guard', strpos($hooks, 'product configuration is incomplete') !== false);
    check('real ip-details builder wired', strpos($entry, 'createIpDetailsHtmlvps($ipdetails, $ipforewall') !== false);
    check('backup charges autobackupprice', strpos($entry, 'autobackupprice') !== false);
    check('backup description fixed', strpos($entry, 'Ordering automated backup') !== false);
    check('invoice-failure guard', substr_count($entry, 'Invoice creation failed.') === 2);
    check('vpsLabelValue initialised', strpos($entry, '$vpsLabelValue = null;') !== false);
    check('disk error var initialised', strpos($libsrc, "\$diskErrors = '';") !== false);
    check('ip error var initialised', strpos($libsrc, "\$ipsErrors = '';") !== false);
    check('only the legitimate $diskid use remains', substr_count($entry, '$diskid["result"]->message') === 1);
    check('account id reads hardened x11', substr_count($entry, 'soyoustart_vps_cfgPart($params, "configoption3", 0, \'-\')') === 11);
    check('tpl escapes applied', substr_count($tpl, '|escape}') >= 15);
    $growl = file_get_contents(dirname(__DIR__) . '/assets/js/jquerygrowl.js');
    $script = file_get_contents(dirname(__DIR__) . '/assets/js/script.js');
    check('VS-17: jquerygrowl escapes title and message', strpos($growl, 'this.escapeHtml(this.settings.title)') !== false && strpos($growl, 'this.escapeHtml(this.settings.message)') !== false);
    check('VS-17: script.js uses .text() for customServerName and viewIpDetailsIpBlock', strpos($script, '$(".serverName").text(customServerName)') !== false && strpos($script, '$("#viewIpDetailsIpBlock").text(ipblock)') !== false);
    check('VS-18: entry enforces soyoustart_vps_verifyCsrf', substr_count($entry, 'soyoustart_vps_verifyCsrf()') >= 8);
    check('VS-19: entry enforces soyoustart_vps_isFeatureDisabled', substr_count($entry, 'soyoustart_vps_isFeatureDisabled(') >= 9);
    check('VS-20: sweetalert2 pinned to exact version in hooks and template', strpos($hooks, 'sweetalert2@11.10.5') !== false && strpos($tpl, 'sweetalert2@11.10.5') !== false && strpos($hooks, 'sweetalert2@11"') === false && strpos($tpl, 'sweetalert2@11"') === false);
    check('VS-22: ipinfo.io beacon removed from script.js', strpos($script, 'ipinfo.io') === false);

    echo "\nsource pins: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
