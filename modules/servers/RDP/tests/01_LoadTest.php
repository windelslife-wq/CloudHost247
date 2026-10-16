<?php
/**
 * C-1: production load chain (real lib + entry + hooks), hook behaviour,
 * and static pins for the transport/template lines that cannot run offline.
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    require_once dirname(__DIR__) . '/lib/Helper.php';
    require_once dirname(__DIR__) . '/RDP.php';
    require_once dirname(__DIR__) . '/hooks.php';
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

    echo "== entry surface ==\n";
    foreach (['RDP_MetaData', 'RDP_ConfigOptions', 'RDP_CreateAccount', 'RDP_SuspendAccount',
        'RDP_UnsuspendAccount', 'RDP_TerminateAccount', 'RDP_Renew', 'RDP_ClientArea',
        'RDP_AdminServicesTabFields', 'RDP_TestConnection'] as $fn) {
        check("entry $fn exists", function_exists($fn));
    }
    check('real Helper class loads', class_exists('WHMCS\\Module\\Server\\RDP\\Helper'));
    check('mask helper exists', method_exists('WHMCS\\Module\\Server\\RDP\\Helper', 'maskSensitiveForLog'));

    echo "== metadata ==\n";
    $meta = RDP_MetaData();
    check('DisplayName', $meta['DisplayName'] === 'RDP');
    check('APIVersion', $meta['APIVersion'] === '1.1');
    check('RequiresServer', $meta['RequiresServer'] === true);
    check('SSO labels declared', isset($meta['ServiceSingleSignOnLabel'], $meta['AdminSingleSignOnLabel']));
    check('SSO handlers exist', function_exists('RDP_ServiceSingleSignOn') && function_exists('RDP_AdminSingleSignOn'));
    $ssoSvc = RDP_ServiceSingleSignOn([]);
    $ssoAdm = RDP_AdminSingleSignOn([]);
    check('SSO handlers fail gracefully without dead callback', $ssoSvc['success'] === false && !empty($ssoSvc['errorMsg']) && $ssoAdm['success'] === false && !empty($ssoAdm['errorMsg']));
    $redacted = RDP_redactParamsForLog(['serveraccesshash' => 'SECRET_TOK', 'serverpassword' => 'PW1', 'password' => 'PW2', 'serviceid' => 9]);
    check('RD-O3 log param redaction masks secrets', $redacted['serveraccesshash'] === '***' && $redacted['serverpassword'] === '***' && $redacted['password'] === '***' && $redacted['serviceid'] === 9);
    check('suspend is local-only true', RDP_SuspendAccount([]) === true);
    check('unsuspend is local-only true', RDP_UnsuspendAccount([]) === true);
    check('terminate is local-only true', RDP_TerminateAccount([]) === true);

    echo "== stock hook ==\n";
    check('hook registered', isset($GLOBALS['__rdp_hooks']['AdminProductConfigFieldsSave'][0]));
    $hook = $GLOBALS['__rdp_hooks']['AdminProductConfigFieldsSave'][0];
    rdp_reset();
    check('missing pid is a no-op', $hook([]) === null && \WHMCS\Database\Capsule::$tables === []);
    rdp_reset();
    $q = new \RdpTest\FakeQuery(['valueResult' => '9|50']);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($q) {
        return $q;
    };
    $hook(['pid' => 7]);
    check('stock quantity synced to product', $q->updated === [['qty' => '50']]);
    rdp_reset();
    $q = new \RdpTest\FakeQuery(['valueResult' => '9']);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($q) {
        return $q;
    };
    $hook(['pid' => 7]);
    check('config without stock leaves qty alone', $q->updated === []);
    rdp_reset();
    $q = new \RdpTest\FakeQuery(['valueResult' => null]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($q) {
        return $q;
    };
    $hook(['pid' => 7]);
    check('unconfigured product leaves qty alone', $q->updated === []);
    rdp_reset();
    \WHMCS\Database\Capsule::$handler = function ($table) {
        throw new \Exception('db down');
    };
    $hook(['pid' => 7]);
    check('hook db failure is logged, not fatal', count($GLOBALS['__rdp_modulelog']) === 1);

    echo "== static pins ==\n";
    $helperSrc = file_get_contents(dirname(__DIR__) . '/lib/Helper.php');
    $entrySrc = file_get_contents(dirname(__DIR__) . '/RDP.php');
    $tplSrc = file_get_contents(dirname(__DIR__) . '/templates/overview.tpl');
    $hookSrc = file_get_contents(dirname(__DIR__) . '/hooks.php');
    check('curl timeout is 30s', strpos($helperSrc, 'CURLOPT_TIMEOUT, 30') !== false);
    check('10000s timeout gone', strpos($helperSrc, '10000') === false);
    check('connect timeout set', strpos($helperSrc, 'CURLOPT_CONNECTTIMEOUT, 10') !== false);
    check('TLS peer verify pinned on', strpos($helperSrc, 'CURLOPT_SSL_VERIFYPEER, true') !== false);
    check('TLS host verify strict', strpos($helperSrc, 'CURLOPT_SSL_VERIFYHOST, 2') !== false);
    check('module log masks passwords', strpos($helperSrc, 'maskSensitiveForLog(json_decode($response))') !== false);
    check('panel base URL untouched', strpos($helperSrc, 'https://www.rdparena.com/payments/resellerapi.php') !== false);
    check('endpoint concatenation untouched', strpos($helperSrc, '$this->baseUrl . $this->endPoint') !== false);
    check('stock endpoint kept', substr_count($helperSrc, "'stock'") === 2);
    check('order endpoint kept', substr_count($helperSrc, "'order'") === 1);
    check('service endpoint kept', substr_count($helperSrc, "'service/'") === 2);
    check('credentials encrypted at rest', strpos($entrySrc, 'encrypt(base64_decode') !== false);
    check('clientarea catch renders overview', substr_count($entrySrc, "'templatefile' => \"templates/overview.tpl\"") === 2);
    check('wrong-shape catch keys gone', strpos($entrySrc, 'tabOverviewReplacementTemplate') === false);
    check('admin tab escapes panel fields', substr_count($entrySrc, 'htmlspecialchars($result[') === 6);
    check('all tpl outputs escaped', substr_count($tplSrc, '|escape') === 8);
    foreach (['hostname', 'ipaddress', 'username', 'encodedPassword', 'signupdate', 'duedate'] as $v) {
        check("tpl no bare \${$v}", strpos($tplSrc, '{$' . $v . '}') === false);
    }
    check('hook logs $vars', strpos($hookSrc, '$vars,') !== false && strpos($hookSrc, '$params,') === false);

    echo "\nload: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
