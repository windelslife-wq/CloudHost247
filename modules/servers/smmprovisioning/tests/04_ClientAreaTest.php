<?php
/**
 * C-3: ClientArea vars (order mapping, status colours, error shapes).
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

    echo "== client area ==\n";
    c3_full_reset();
    \SmmAddon\Helper::$orderByService = null;
    $res = smmprovisioning_ClientArea(['serviceid' => 11]);
    check('templatefile', $res['templatefile'] === 'clientarea');
    check('no-order error vars', $res['vars']['error'] === 'No SMM order found for this service.' && $res['vars']['serviceid'] === 11);

    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'status' => 'completed', 'smm_order_id' => '777', 'link' => 'https://social.example/u1', 'quantity' => 500];
    $res = smmprovisioning_ClientArea(['serviceid' => 11]);
    check('order cast to array', $res['vars']['order'] === ['id' => 9, 'status' => 'completed', 'smm_order_id' => '777', 'link' => 'https://social.example/u1', 'quantity' => 500]);
    check('completed is success', $res['vars']['status_color'] === 'success');
    check('serviceid passed', $res['vars']['serviceid'] === 11);

    $colors = ['pending' => 'warning', 'processing' => 'info', 'inprogress' => 'info', 'canceled' => 'danger', 'partial' => 'warning', 'refunded' => 'default', 'error' => 'danger'];
    foreach ($colors as $status => $color) {
        c3_full_reset();
        \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'status' => $status];
        $res = smmprovisioning_ClientArea(['serviceid' => 11]);
        check("status $status maps to $color", $res['vars']['status_color'] === $color);
    }
    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'status' => 'weird'];
    $res = smmprovisioning_ClientArea(['serviceid' => 11]);
    check('unknown status defaults', $res['vars']['status_color'] === 'default');

    echo "\nclientarea: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
