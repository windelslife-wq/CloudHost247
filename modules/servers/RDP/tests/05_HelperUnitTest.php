<?php
/**
 * C-1: real Helper class — construction, log masking, field/template/email
 * helpers. (Panel HTTP methods only set curl state; offline they are pinned
 * statically in 01 because curlCall performs live requests.)
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    require_once dirname(__DIR__) . '/lib/Helper.php';
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

    echo "== construction ==\n";
    rdp_reset();
    $h = new \WHMCS\Module\Server\RDP\Helper(['serveraccesshash' => 'TOK']);
    check('token header from params', $h->header === ['Content-Type: application/json', 'Cra-Token: TOK']);
    check('base URL pinned', $h->baseUrl === 'https://www.rdparena.com/payments/resellerapi.php');
    check('no db on the fast path', \WHMCS\Database\Capsule::$tables === []);
    rdp_reset();
    $q = new \RdpTest\FakeQuery(['valueResult' => 'FALLBACK']);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($q) {
        return $q;
    };
    $h = new \WHMCS\Module\Server\RDP\Helper([]);
    check('token falls back to tblservers', $h->header[1] === 'Cra-Token: FALLBACK');

    echo "== log masking ==\n";
    $in = (object) ['username' => 'u', 'password' => 'c2VjcmV0', 'nested' => ['password' => 'eHh4', 'ok' => 1], 'list' => [(object) ['password' => 'eXl5']]];
    $out = $h->maskSensitiveForLog($in);
    check('object password masked', $out->password === '***' && $out->username === 'u');
    check('nested array masked', $out->nested === ['password' => '***', 'ok' => 1]);
    check('object in list masked', $out->list[0]->password === '***');
    check('input not mutated', $in->password === 'c2VjcmV0' && $in->nested['password'] === 'eHh4');
    check('array root masked', $h->maskSensitiveForLog(['password' => 'x', 'a' => 'b']) === ['password' => '***', 'a' => 'b']);
    check('scalars pass through', $h->maskSensitiveForLog('s') === 's' && $h->maskSensitiveForLog(null) === null);
    check('near-miss keys kept', $h->maskSensitiveForLog(['passwordHint' => 'x']) === ['passwordHint' => 'x']);

    echo "== field values ==\n";
    rdp_reset();
    $fields = new \RdpTest\FakeQuery(['valueResult' => 9]);
    $values = new \RdpTest\FakeQuery(['firstResult' => null]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($fields, $values) {
        return $table === 'tblcustomfields' ? $fields : $values;
    };
    check('missing value inserts', $h->saveCustomFiledValue(2, 3, 'S1') === true && $values->inserted === [['fieldid' => 9, 'relid' => 3, 'value' => 'S1']]);
    rdp_reset();
    $fields = new \RdpTest\FakeQuery(['valueResult' => 9]);
    $values = new \RdpTest\FakeQuery(['firstResult' => (object) ['id' => 1]]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($fields, $values) {
        return $table === 'tblcustomfields' ? $fields : $values;
    };
    check('present value updates', $h->saveCustomFiledValue(2, 3, 'S2') === 1 && $values->updated === [['value' => 'S2']]);
    rdp_reset();
    $fields = new \RdpTest\FakeQuery(['valueResult' => null]);
    $values = new \RdpTest\FakeQuery();
    \WHMCS\Database\Capsule::$handler = function ($table) use ($fields, $values) {
        return $table === 'tblcustomfields' ? $fields : $values;
    };
    check('missing field refuses the write', $h->saveCustomFiledValue(2, 3, 'S3') === false && $values->inserted === [] && $values->updated === []);

    echo "== templates + fields + mail ==\n";
    rdp_reset();
    $tpl = new \RdpTest\FakeQuery(['countResult' => 0]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($tpl) {
        return $tpl;
    };
    $h->createTemplate();
    check('template created once', count($tpl->inserted) === 1 && $tpl->inserted[0]['name'] === 'Welcome RDP Credentials Email');
    rdp_reset();
    $tpl = new \RdpTest\FakeQuery(['countResult' => 1]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($tpl) {
        return $tpl;
    };
    $h->createTemplate();
    check('existing template left alone', $tpl->inserted === []);
    rdp_reset();
    $cf = new \RdpTest\FakeQuery(['getResult' => []]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($cf) {
        return $cf;
    };
    $h->CreateCustomFields(6);
    check('field created once', count($cf->inserted) === 1 && $cf->inserted[0]['fieldname'] === 'serviceid|Service id' && $cf->inserted[0]['relid'] === 6);
    rdp_reset();
    $cf = new \RdpTest\FakeQuery(['getResult' => [(object) ['id' => 1]]]);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($cf) {
        return $cf;
    };
    $h->CreateCustomFields(6);
    check('existing field left alone', $cf->inserted === []);
    rdp_reset();
    check('sendEmail proxies localAPI', $h->sendEmail(['a' => 'b']) === ['result' => 'success'] && $GLOBALS['__rdp_localapi_calls'] === [['SendEmail', ['a' => 'b']]]);
    rdp_reset();
    $GLOBALS['__rdp_localapi_throw'] = true;
    check('mail failure is logged, not fatal', $h->sendEmail([]) === null && count($GLOBALS['__rdp_activity']) === 1);

    echo "\nhelper: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
