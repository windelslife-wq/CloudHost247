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

    echo "== IP ownership, ACL, and CSRF helpers (VS-18, VS-19) ==\n";
    $owned = ['httpcode' => 200, 'result' => ['198.51.100.10', '2001:db8::1']];
    check('exact IP belongs to server', soyoustart_vps_ipBelongsToServer($owned, '198.51.100.10') === true);
    check('CIDR for owned IP belongs to server', soyoustart_vps_ipBelongsToServer($owned, '198.51.100.10/32') === true);
    check('foreign IP rejected', soyoustart_vps_ipBelongsToServer($owned, '198.51.100.99/32') === false);
    check('non-200 owned response rejected', soyoustart_vps_ipBelongsToServer(['httpcode' => 500, 'result' => ['198.51.100.10']], '198.51.100.10') === false);

    check('ACL enabled when neither service nor addon disables', soyoustart_vps_isFeatureDisabled([], [], 'Reboot', 'reboot') === false);
    check('ACL disabled by service setting', soyoustart_vps_isFeatureDisabled(['Reboot' => 'on'], [], 'Reboot', 'reboot') === true);
    check('ACL disabled by addon setting', soyoustart_vps_isFeatureDisabled([], ['reboot'], 'Reboot', 'reboot') === true);

    $_SESSION = [];
    $tok = soyoustart_vps_csrfToken();
    check('CSRF token minted with 64 hex chars', is_string($tok) && strlen($tok) === 64);
    check('CSRF valid token accepted', soyoustart_vps_verifyCsrf($tok, $_SESSION) === true);
    check('CSRF wrong token rejected', soyoustart_vps_verifyCsrf('wrong-token', $_SESSION) === false);
    check('CSRF empty token rejected', soyoustart_vps_verifyCsrf('', $_SESSION) === false);

    echo "\nvalidators: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
