<?php
/**
 * C-3: endpoint/matcher helpers + CreateAccount (idempotency, fields, money).
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/eval_entry.php';
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
    function mkparams(array $over = [])
    {
        return array_merge([
            'serviceid' => 11, 'pid' => 22, 'orderid' => 33,
            'clientsdetails' => ['userid' => 44],
            'configoption1' => '1001', 'configoption2' => 'Followers',
            'configoption3' => '100', 'configoption4' => '10000',
            'configoption5' => 'Link', 'configoption6' => 'Quantity',
            'serverhostname' => 'panel.example.com', 'serverip' => '',
            'serverpassword' => 'KEY', 'domain' => '',
            'customfields' => ['Link' => 'https://social.example/u1', 'Quantity' => '500'],
        ], $over);
    }
    function db(array $tables)
    {
        \Illuminate\Database\Capsule\Manager::$handler = function ($table) use ($tables) {
            if (!isset($tables[$table])) {
                throw new \Exception('unexpected table ' . $table);
            }
            return $tables[$table];
        };
    }

    echo "== apiEndpoint ==\n";
    check('host preferred', smmprovisioning_apiEndpoint(['serverhostname' => 'h', 'serverip' => 'i', 'serverpassword' => 'k']) === ['https://h', 'k', null]);
    check('ip fallback', smmprovisioning_apiEndpoint(['serverhostname' => '', 'serverip' => 'i', 'serverpassword' => 'k'])[0] === 'https://i');
    check('missing host+ip errors', smmprovisioning_apiEndpoint(['serverpassword' => 'k'])[2] === 'API URL and API Key are required.');
    check('missing key errors', smmprovisioning_apiEndpoint(['serverhostname' => 'h'])[2] === 'API URL and API Key are required.');
    check('https kept', smmprovisioning_apiEndpoint(['serverhostname' => 'https://h', 'serverpassword' => 'k'])[0] === 'https://h');
    check('explicit http kept (admin intent)', smmprovisioning_apiEndpoint(['serverhostname' => 'http://lan/', 'serverpassword' => 'k'])[0] === 'http://lan/');
    check('uppercase scheme not doubled', smmprovisioning_apiEndpoint(['serverhostname' => 'HTTP://h', 'serverpassword' => 'k'])[0] === 'HTTP://h');

    echo "== matchField ==\n";
    check('exact wins', smmprovisioning_matchField([['About', 'x'], ['Link', 'L']], 'Link', ['link']) === 'L');
    check('exact before pipe-label', smmprovisioning_matchField([['Link|Your profile link', 'L']], 'Link', ['zzz']) === 'L');
    check('exact case-insensitive', smmprovisioning_matchField([['QUANTITY', '5']], 'Quantity', []) === '5');
    check('exact beats earlier fuzzy', smmprovisioning_matchField([['Backlink', 'bad'], ['Link', 'good']], 'Link', ['link']) === 'good');
    check('fuzzy first hit', smmprovisioning_matchField([['Profile URL', 'u']], 'Link', ['link', 'url']) === 'u');
    check('no match is null', smmprovisioning_matchField([['Name', 'n']], 'Link', ['link']) === null);
    check('empty needle skipped', smmprovisioning_matchField([['Name', 'n']], 'Link', ['']) === null);

    echo "== idempotency (M-4 mirror) ==\n";
    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'status' => 'pending'];
    check('existing non-error order short-circuits', smmprovisioning_CreateAccount(mkparams()) === 'success');
    check('no panel call on short-circuit', \SmmAddon\ApiClient::$constructed === []);
    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'status' => 'error'];
    $svc = new \SmmProvTest\FakeQuery(['firstResult' => (object) ['id' => 1]]);
    $cf = new \SmmProvTest\FakeQuery(['getResult' => []]);
    $ord = new \SmmProvTest\FakeQuery();
    $host = new \SmmProvTest\FakeQuery();
    db(['mod_smm_services' => $svc, 'tblcustomfieldsvalues' => $cf, 'mod_smm_orders' => $ord, 'tblhosting' => $host]);
    check('error-status row retries', smmprovisioning_CreateAccount(mkparams()) === 'success');
    check('retry places panel order', count(array_filter(\SmmAddon\ApiClient::$constructed, function ($c) {
        return isset($c[0]) && $c[0] === 'placeOrder';
    })) === 1);

    echo "== config errors ==\n";
    c3_full_reset();
    check('missing service id errors', smmprovisioning_CreateAccount(mkparams(['configoption1' => ''])) === 'SMM Service ID is not configured for this product.');
    $svc = new \SmmProvTest\FakeQuery(['firstResult' => null]);
    db(['mod_smm_services' => $svc]);
    check('unmapped service errors', smmprovisioning_CreateAccount(mkparams()) === 'SMM Service ID 1001 not found or not mapped.');

    echo "== field extraction ==\n";
    $ok = function ($params, $cfRows = []) {
        c3_full_reset();
        $svc = new \SmmProvTest\FakeQuery(['firstResult' => (object) ['id' => 1]]);
        $cf = new \SmmProvTest\FakeQuery(['getResult' => $cfRows]);
        $ord = new \SmmProvTest\FakeQuery();
        $host = new \SmmProvTest\FakeQuery();
        db(['mod_smm_services' => $svc, 'tblcustomfieldsvalues' => $cf, 'mod_smm_orders' => $ord, 'tblhosting' => $host]);
        $res = smmprovisioning_CreateAccount($params);
        return [$res, $ord, $host];
    };
    list($res) = $ok(mkparams());
    check('params exact fields place', $res === 'success');
    $placed = null;
    foreach (\SmmAddon\ApiClient::$constructed as $c) {
        if ($c[0] === 'placeOrder') {
            $placed = $c;
        }
    }
    check('panel gets service/link/qty', $placed === ['placeOrder', '1001', 'https://social.example/u1', 500]);
    list($res) = $ok(mkparams(['customfields' => ['Profile URL' => 'https://social.example/u2', 'Follower count' => '250']]));
    check('fuzzy fields place', $res === 'success');
    list($res) = $ok(mkparams(['customfields' => []]), [(object) ['fieldname' => 'Link|Profile', 'value' => 'https://social.example/u3'], (object) ['fieldname' => 'Quantity', 'value' => '300']]);
    check('db fallback places', $res === 'success');
    list($res) = $ok(mkparams(['customfields' => [], 'domain' => 'https://social.example/u4']), [(object) ['fieldname' => 'Quantity', 'value' => '300']]);
    check('domain fallback for link', $res === 'success');
    list($res) = $ok(mkparams(['customfields' => ['Name' => 'x']]), []);
    check('missing fields error', $res === 'Missing required fields: Link/URL and Quantity must be provided.');
    list($res) = $ok(mkparams(['customfields' => ['Link' => 'https://social.example/u1', 'Quantity' => '0']]), []);
    check('zero quantity errors', $res === 'Missing required fields: Link/URL and Quantity must be provided.');

    echo "== clamps ==\n";
    list($res) = $ok(mkparams(['customfields' => ['Link' => 'https://social.example/u1', 'Quantity' => '5']]));
    $placed = null;
    foreach (\SmmAddon\ApiClient::$constructed as $c) {
        if ($c[0] === 'placeOrder') {
            $placed = $c;
        }
    }
    check('below min raised to min', $res === 'success' && $placed[3] === 100);
    list($res) = $ok(mkparams(['customfields' => ['Link' => 'https://social.example/u1', 'Quantity' => '99999']]));
    $placed = null;
    foreach (\SmmAddon\ApiClient::$constructed as $c) {
        if ($c[0] === 'placeOrder') {
            $placed = $c;
        }
    }
    check('above max lowered to max', $res === 'success' && $placed[3] === 10000);
    list($res) = $ok(mkparams(['configoption4' => '0', 'customfields' => ['Link' => 'https://social.example/u1', 'Quantity' => '99999']]));
    $placed = null;
    foreach (\SmmAddon\ApiClient::$constructed as $c) {
        if ($c[0] === 'placeOrder') {
            $placed = $c;
        }
    }
    check('max 0 refuses unbounded client quantity (M-5 fixed)', strpos($res, 'Quantity validation failed:') === 0 && $placed === null);
    list($res) = $ok(mkparams(['configoptions' => ['Quantity' => 1500], 'customfields' => ['Link' => 'https://social.example/u1', 'Quantity' => '99999']]));
    $placed = null;
    foreach (\SmmAddon\ApiClient::$constructed as $c) {
        if ($c[0] === 'placeOrder') {
            $placed = $c;
        }
    }
    check('paid configoptions caps inflated client customfield (M-5)', $res === 'success' && $placed !== null && $placed[3] === 1500);

    echo "== success row + notes ==\n";
    list($res, $ord, $host) = $ok(mkparams());
    check('returns success', $res === 'success');
    check('one order row', count($ord->inserted) === 1);
    $row = $ord->inserted[0];
    check('row whmcs ids', $row['whmcs_order_id'] === 33 && $row['whmcs_service_id'] === 11 && $row['whmcs_user_id'] === 44);
    check('row panel fields', $row['smm_order_id'] === '12345' && $row['smm_service_id'] === '1001' && $row['quantity'] === 500 && $row['status'] === 'pending');
    check('row api response json', $row['api_response'] === json_encode(['order' => 12345]));
    check('notes updated once', count($host->updated) === 1 && $host->updated[0]['notes'] === 'SMM Order ID: 12345');
    $host2 = new \SmmProvTest\FakeQuery(['valueResult' => 'Do not touch']);
    c3_full_reset();
    $svc = new \SmmProvTest\FakeQuery(['firstResult' => (object) ['id' => 1]]);
    $cf = new \SmmProvTest\FakeQuery(['getResult' => []]);
    $ord = new \SmmProvTest\FakeQuery();
    db(['mod_smm_services' => $svc, 'tblcustomfieldsvalues' => $cf, 'mod_smm_orders' => $ord, 'tblhosting' => $host2]);
    smmprovisioning_CreateAccount(mkparams());
    check('prior notes preserved', $host2->updated[0]['notes'] === "Do not touch\nSMM Order ID: 12345");

    echo "== panel error + endpoint ==\n";
    c3_full_reset();
    \SmmAddon\ApiClient::$placeResult = ['error' => 'Insufficient funds'];
    $svc = new \SmmProvTest\FakeQuery(['firstResult' => (object) ['id' => 1]]);
    $cf = new \SmmProvTest\FakeQuery(['getResult' => []]);
    $ord = new \SmmProvTest\FakeQuery();
    $host = new \SmmProvTest\FakeQuery();
    db(['mod_smm_services' => $svc, 'tblcustomfieldsvalues' => $cf, 'mod_smm_orders' => $ord, 'tblhosting' => $host]);
    check('panel error surfaces', smmprovisioning_CreateAccount(mkparams()) === 'Order placement failed: Insufficient funds');
    check('error row stored', count($ord->inserted) === 1 && $ord->inserted[0]['status'] === 'error' && $ord->inserted[0]['smm_order_id'] === '');
    check('notes untouched on error', $host->updated === []);
    c3_full_reset();
    $svc = new \SmmProvTest\FakeQuery(['firstResult' => (object) ['id' => 1]]);
    $cf = new \SmmProvTest\FakeQuery(['getResult' => []]);
    db(['mod_smm_services' => $svc, 'tblcustomfieldsvalues' => $cf]);
    check('missing endpoint errors fast', smmprovisioning_CreateAccount(mkparams(['serverhostname' => '', 'serverip' => ''])) === 'API URL and API Key are required.');
    check('no panel call without endpoint', \SmmAddon\ApiClient::$constructed === []);

    echo "== client construction ==\n";
    c3_full_reset();
    \SmmAddon\Helper::$config = ['debug_mode' => 'on'];
    $svc = new \SmmProvTest\FakeQuery(['firstResult' => (object) ['id' => 1]]);
    $cf = new \SmmProvTest\FakeQuery(['getResult' => []]);
    $ord = new \SmmProvTest\FakeQuery();
    $host = new \SmmProvTest\FakeQuery();
    db(['mod_smm_services' => $svc, 'tblcustomfieldsvalues' => $cf, 'mod_smm_orders' => $ord, 'tblhosting' => $host]);
    smmprovisioning_CreateAccount(mkparams());
    $ctor = \SmmAddon\ApiClient::$constructed[0];
    check('client gets normalised url + key + debug', $ctor === ['https://panel.example.com', 'KEY', true]);

    echo "\ncreate: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
