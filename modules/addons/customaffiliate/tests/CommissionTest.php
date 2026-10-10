<?php
/**
 * Offline tests for the Custom Affiliate commission rules, run against an
 * in-memory Capsule shim (CapsuleShim.php). No WHMCS install is needed.
 */
define('WHMCS', true);
require __DIR__ . '/CapsuleShim.php';
require __DIR__ . '/../lib/CommissionManager.php';

use WHMCS\Database\Capsule;
use CustomAffiliate\CommissionManager;

$pass = 0; $fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "FAIL $label\n"; }
}
function eq($label, $want, $got) {
    global $pass, $fail;
    if ($want === $got) { $pass++; } else { $fail++; echo "FAIL $label: want " . var_export($want, true) . " got " . var_export($got, true) . "\n"; }
}

/** Fresh fixture: hosting group 5, 50% first / 20% recurring, logging off. */
function fixture() {
    Capsule::reset();
    Capsule::$tables['tbladdonmodules'] = [
        ['module' => 'customaffiliate', 'setting' => 'product_group_id', 'value' => '5'],
        ['module' => 'customaffiliate', 'setting' => 'first_commission_percent', 'value' => '50'],
        ['module' => 'customaffiliate', 'setting' => 'recurring_commission_percent', 'value' => '20'],
        ['module' => 'customaffiliate', 'setting' => 'enable_logging', 'value' => '0'],
    ];
    Capsule::$tables['tblproducts'] = [['id' => 10, 'gid' => 5], ['id' => 11, 'gid' => 9]];
    Capsule::$tables['tblhosting'] = [['id' => 1, 'userid' => 7, 'packageid' => 10, 'domain' => 'a.test', 'domainstatus' => 'Active']];
    Capsule::$tables['tblclients'] = [['id' => 7, 'affiliateid' => 3], ['id' => 8, 'affiliateid' => 0]];
    Capsule::$tables['tblaffiliates'] = [['id' => 3, 'clientid' => 50], ['id' => 4, 'clientid' => 8]];
    Capsule::$tables['tblinvoices'] = [];
    Capsule::$tables['tblinvoiceitems'] = [];
}
function invoice($id, $amount, $status = 'Paid', $userid = 7, $serviceId = 1, $type = 'Hosting') {
    Capsule::$tables['tblinvoices'][] = ['id' => $id, 'userid' => $userid, 'status' => $status, 'total' => $amount];
    Capsule::$tables['tblinvoiceitems'][] = ['id' => $id * 10, 'invoiceid' => $id, 'type' => $type, 'relid' => $serviceId, 'amount' => $amount, 'description' => 'Hosting'];
}
function commissionRow($serviceId = 1, $affiliateId = 3) {
    foreach (Capsule::$tables['mod_customaffiliate_commissions'] ?? [] as $r) {
        if ((int) $r['service_id'] === $serviceId && (int) $r['affiliate_id'] === $affiliateId) return (object) $r;
    }
    return null;
}
function logRows($action = null, $invoiceId = null) {
    $out = [];
    foreach (Capsule::$tables['mod_customaffiliate_log'] ?? [] as $r) {
        if ($action !== null && $r['action'] !== $action) continue;
        if ($invoiceId !== null && (int) $r['invoice_id'] !== (int) $invoiceId) continue;
        $out[] = (object) $r;
    }
    return $out;
}

echo "== end-to-end: first then recurring ==\n";
fixture();
invoice(100, 100.00);
$m = new CommissionManager();
$first = $m->processInvoicePaid(100);
eq('first payment is 50% of 100', 50.0, $first[0]['commission_amount']);
eq('first commission type', 'first', $first[0]['commission_type']);
$m->recordCommission($first[0]);
$row = commissionRow();
ok('commission row created with first flag', $row && (int) $row->first_commission_paid === 1);

invoice(101, 200.00);
$second = (new CommissionManager())->processInvoicePaid(101);
eq('renewal is 20% of 200', 40.0, $second[0]['commission_amount']);
eq('renewal type', 'recurring', $second[0]['commission_type']);
(new CommissionManager())->recordCommission($second[0]);
$row = commissionRow();
eq('recurring total accumulates', 40.0, round((float) $row->total_recurring_commission, 2));
eq('recurring count is 1', 1, (int) $row->recurring_count);

echo "== notes survive when they were NULL (CONCAT(NULL) regression) ==\n";
fixture();
invoice(200, 100.00);
Capsule::$tables['mod_customaffiliate_commissions'] = [[
    'id' => 1, 'service_id' => 1, 'affiliate_id' => 3, 'client_id' => 7, 'product_id' => 10,
    'first_commission_paid' => 1, 'total_recurring_commission' => 0, 'recurring_count' => 0, 'notes' => null,
]];
(new CommissionManager())->recordCommission(['service_id' => 1, 'affiliate_id' => 3, 'client_id' => 7, 'product_id' => 10,
    'invoice_id' => 200, 'commission_type' => 'recurring', 'commission_percent' => 20, 'commission_amount' => 20.0, 'item_amount' => 100.0]);
$row = commissionRow();
ok('recurring note written onto a NULL-notes row', $row->notes !== null && strpos($row->notes, 'Recurring commission recorded: 20.00') !== false);

echo "== self-referral is refused ==\n";
fixture();
// Client 8 owns affiliate account 4 but has no referrer (affiliateid 0).
Capsule::$tables['tblhosting'][] = ['id' => 2, 'userid' => 8, 'packageid' => 10, 'domain' => 'b.test', 'domainstatus' => 'Active'];
invoice(300, 100.00, 'Paid', 8, 2);
eq('no commission to own affiliate account', false, (new CommissionManager())->processInvoicePaid(300));

echo "== refund of the first payment ==\n";
fixture();
invoice(400, 100.00);
$m = new CommissionManager();
$c = $m->processInvoicePaid(400);
$m->recordCommission($c[0]);
(new CommissionManager())->handleInvoiceRefund(400);
$row = commissionRow();
eq('first flag reset by refund', 0, (int) $row->first_commission_paid);
eq('one refund log row for the first commission', 1, count(logRows('refund', 400)));
eq('refund log amount is negative first commission', -50.0, (float) logRows('refund', 400)[0]->amount);

echo "== refund of a recurring payment reverses it ==\n";
fixture();
invoice(500, 100.00);
$m = new CommissionManager();
$m->recordCommission($m->processInvoicePaid(500)[0]);
invoice(501, 200.00);
$m = new CommissionManager();
$m->recordCommission($m->processInvoicePaid(501)[0]);
$before = commissionRow();
eq('precondition: recurring recorded', 40.0, round((float) $before->total_recurring_commission, 2));
(new CommissionManager())->handleInvoiceRefund(501);
$after = commissionRow();
eq('recurring total reversed', 0.0, round((float) $after->total_recurring_commission, 2));
eq('recurring count reversed', 0, (int) $after->recurring_count);
eq('first flag untouched by a recurring refund', 1, (int) $after->first_commission_paid);
eq('recurring reversal logged once', 1, count(logRows('refund_recurring', 501)));

echo "== repeated refund hook does not reverse twice ==\n";
(new CommissionManager())->handleInvoiceRefund(501);
eq('still zero after second refund', 0.0, round((float) commissionRow()->total_recurring_commission, 2));
eq('still one reversal entry', 1, count(logRows('refund_recurring', 501)));

echo "== duplicate detection ==\n";
fixture();
invoice(600, 100.00);
$m = new CommissionManager();
$c = $m->processInvoicePaid(600);
$m->recordCommission($c[0]);
ok('invoice already recorded is a duplicate', (new CommissionManager())->isDuplicateCommission(600, 1, 3));
ok('unrecorded invoice is not a duplicate', !(new CommissionManager())->isDuplicateCommission(601, 1, 3));

echo "== non-hosting product is ignored ==\n";
fixture();
Capsule::$tables['tblhosting'][] = ['id' => 9, 'userid' => 7, 'packageid' => 11, 'domain' => 'c.test', 'domainstatus' => 'Active'];
invoice(700, 15.00, 'Paid', 7, 9);
eq('domain-group product earns nothing', false, (new CommissionManager())->processInvoicePaid(700));

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
