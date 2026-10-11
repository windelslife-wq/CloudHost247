<?php
/**
 * C-4: VS-4/VS-13/VS-14/VS-16 input validators + billing-cycle map.
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

    echo "== power action allowlist ==\n";
    check('start allowed', soyoustart_vps_powerAction('start') === 'start');
    check('stop allowed', soyoustart_vps_powerAction('stop') === 'stop');
    check('case/space normalised', soyoustart_vps_powerAction(' STOP ') === 'stop');
    check('reboot rejected', soyoustart_vps_powerAction('reboot') === null);
    check('sub-resource rejected', soyoustart_vps_powerAction('start/x') === null);
    check('traversal rejected', soyoustart_vps_powerAction('../stop') === null);
    check('empty rejected', soyoustart_vps_powerAction('') === null);
    check('null rejected', soyoustart_vps_powerAction(null) === null);
    check('non-string rejected', soyoustart_vps_powerAction(0) === null);

    echo "== first ip ==\n";
    check('v4 block', soyoustart_vps_firstIp('1.2.3.4/32') === '1.2.3.4');
    check('bare v4', soyoustart_vps_firstIp('1.2.3.4') === '1.2.3.4');
    check('v6 block', soyoustart_vps_firstIp('2001:db8::1/64') === '2001:db8::1');
    check('invalid rejected', soyoustart_vps_firstIp('999.1.1.1') === null);
    check('hostname rejected', soyoustart_vps_firstIp('example.com') === null);
    check('empty rejected', soyoustart_vps_firstIp('') === null);
    check('null rejected', soyoustart_vps_firstIp(null) === null);
    check('non-string rejected', soyoustart_vps_firstIp([]) === null);

    echo "== config part ==\n";
    check('plan code', soyoustart_vps_cfgPart(['c2' => 'group@vps-1'], 'c2', 1, '@') === 'vps-1');
    check('group', soyoustart_vps_cfgPart(['c2' => 'group@vps-1'], 'c2', 0, '@') === 'group');
    check('trims', soyoustart_vps_cfgPart(['c3' => ' 1 - canada '], 'c3', 1, '-') === 'canada');
    check('missing key', soyoustart_vps_cfgPart([], 'c2', 1, '@') === '');
    check('missing index', soyoustart_vps_cfgPart(['c2' => 'nogroup'], 'c2', 1, '@') === '');
    check('non-array params', soyoustart_vps_cfgPart(null, 'c2', 0, '@') === '');
    check('non-string value', soyoustart_vps_cfgPart(['c' => 5], 'c', 0, '-') === '5');

    echo "== billing cycles ==\n";
    $lib = 'WHMCS\\Module\\Server\\SoyoustartVps\\SoyoustartServer';
    $o = new $lib();
    $cases = [
        'Monthly' => ['P1M', 'default'],
        'Quarterly' => ['P3M', 'default'],
        'Semi-Annually' => ['P6M', 'default'],
        'Annually' => ['P1Y', 'degressivity12'],
        'Biennially' => ['P2Y', 'degressivity24'],
        'One Time' => ['P1M', 'default'],
    ];
    foreach ($cases as $cycle => $want) {
        $got = $o->formateBillingCycle($cycle);
        check("$cycle duration", $got['durationVal'] === $want[0]);
        check("$cycle pricing", $got['pricingModeVal'] === $want[1]);
    }

    echo "\nvalidators: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
