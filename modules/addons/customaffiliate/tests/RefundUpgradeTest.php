<?php
/**
 * Offline tests for proportional refunds and automatic upgrade regrouping
 * (B-9), run against the in-memory Capsule shim. No WHMCS install needed.
 */
define('WHMCS', true);
require __DIR__ . '/CapsuleShim.php';
require __DIR__ . '/../lib/CommissionManager.php';
require __DIR__ . '/../lib/UpgradeHandler.php';

use WHMCS\Database\Capsule;
use CustomAffiliate\CommissionManager;
use CustomAffiliate\UpgradeHandler;

$pass = 0; $fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "FAIL $label\n"; }
}
function eq($label, $want, $got) {
    global $pass, $fail;
    if ($want === $got) { $pass++; } else { $fail++; echo "FAIL $label: want " . var_export($want, true) . " got " . var_export($got, true) . "\n"; }
}

/** Fresh fixture: hosting group 5 (products 10, 12), others group 9 (11, 13). */
function fixture() {
    Capsule::reset();
    Capsule::$tables['tbladdonmodules'] = [
        ['module' => 'customaffiliate', 'setting' => 'product_group_id', 'value' => '5'],
        ['module' => 'customaffiliate', 'setting' => 'first_commission_percent', 'value' => '50'],
        ['module' => 'customaffiliate', 'setting' => 'recurring_commission_percent', 'value' => '20'],
        ['module' => 'customaffiliate', 'setting' => 'enable_logging', 'value' => '0'],
    ];
    Capsule::$tables['tblproducts'] = [['id' => 10, 'gid' => 5], ['id' => 12, 'gid' => 5], ['id' => 11, 'gid' => 9], ['id' => 13, 'gid' => 9]];
    Capsule::$tables['tblhosting'] = [['id' => 1, 'userid' => 7, 'packageid' => 10, 'domain' => 'a.test', 'domainstatus' => 'Active']];
    Capsule::$tables['tblclients'] = [['id' => 7, 'affiliateid' => 3]];
    Capsule::$tables['tblinvoices'] = [];
    Capsule::$tables['tblinvoiceitems'] = [];
    Capsule::$tables['tblaccounts'] = [];
}
function invoice($id, $amount, $status = 'Paid', $userid = 7, $serviceId = 1) {
    Capsule::$tables['tblinvoices'][] = ['id' => $id, 'userid' => $userid, 'status' => $status, 'total' => $amount];
    Capsule::$tables['tblinvoiceitems'][] = ['id' => $id * 10, 'invoiceid' => $id, 'type' => 'Hosting', 'relid' => $serviceId, 'amount' => $amount, 'description' => 'Hosting'];
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
function earnFirst($invoiceId, $amount, $serviceId = 1) {
    invoice($invoiceId, $amount, 'Paid', 7, $serviceId);
    $m = new CommissionManager();
    $c = $m->processInvoicePaid($invoiceId);
    $m->recordCommission($c[0]);
}

echo "== partial refund of first commission ==\n";
fixture();
earnFirst(100, 100.00);
(new CommissionManager())->handleInvoiceRefund(100, 50.00);
eq('half refund reverses half the first commission', -25.0, (float) logRows('refund', 100)[0]->amount);
eq('flag stays paid after a partial refund', 1, (int) commissionRow()->first_commission_paid);
eq('invoice link kept after a partial refund', 100, (int) commissionRow()->first_commission_invoice_id);
ok('note records the partial reversal', strpos(commissionRow()->notes, 'partially reversed') !== false);
(new CommissionManager())->handleInvoiceRefund(100, 50.00);
eq('second half completes the reversal', 2, count(logRows('refund', 100)));
eq('flag resets once fully reversed', 0, (int) commissionRow()->first_commission_paid);
eq('invoice link cleared once fully reversed', null, commissionRow()->first_commission_invoice_id);
(new CommissionManager())->handleInvoiceRefund(100, 50.00);
eq('third refund adds no dust rows', 2, count(logRows('refund', 100)));

echo "== over-refund is capped at what was paid ==\n";
fixture();
earnFirst(110, 100.00);
(new CommissionManager())->handleInvoiceRefund(110, 200.00);
eq('over-refund reverses exactly the commission', -50.0, (float) logRows('refund', 110)[0]->amount);
eq('over-refund resets the flag', 0, (int) commissionRow()->first_commission_paid);

echo "== partial refund of recurring ==\n";
fixture();
earnFirst(120, 100.00);
invoice(121, 200.00);
$m = new CommissionManager();
$m->recordCommission($m->processInvoicePaid(121)[0]);
(new CommissionManager())->handleInvoiceRefund(121, 100.00);
eq('half refund reverses half the recurring total', 20.0, round((float) commissionRow()->total_recurring_commission, 2));
eq('recurring count kept until fully reversed', 1, (int) commissionRow()->recurring_count);
eq('partial recurring reversal logged', -20.0, (float) logRows('refund_recurring', 121)[0]->amount);
(new CommissionManager())->handleInvoiceRefund(121, 100.00);
eq('recurring total fully reversed', 0.0, round((float) commissionRow()->total_recurring_commission, 2));
eq('recurring count dropped once fully reversed', 0, (int) commissionRow()->recurring_count);
eq('two recurring reversal rows', 2, count(logRows('refund_recurring', 121)));

echo "== refund amount falls back to transactions, then full ==\n";
fixture();
earnFirst(130, 100.00);
Capsule::$tables['tblaccounts'][] = ['invoiceid' => 130, 'amountout' => 25.00];
(new CommissionManager())->handleInvoiceRefund(130);
eq('booked money-out drives the share', -12.5, (float) logRows('refund', 130)[0]->amount);
fixture();
earnFirst(131, 100.00);
(new CommissionManager())->handleInvoiceRefund(131);
eq('unknown amount keeps the full reversal', -50.0, (float) logRows('refund', 131)[0]->amount);
eq('unknown amount resets the flag', 0, (int) commissionRow()->first_commission_paid);
fixture();
Capsule::$tables['mod_customaffiliate_commissions'][] = ['id' => 1, 'service_id' => 1, 'affiliate_id' => 3, 'client_id' => 7, 'product_id' => 10,
    'first_commission_amount' => 50.0, 'first_commission_paid' => 1, 'first_commission_invoice_id' => 190, 'notes' => null,
    'total_recurring_commission' => 0, 'recurring_count' => 0];
Capsule::$tables['tblinvoices'][] = ['id' => 190, 'userid' => 7, 'status' => 'Refunded', 'total' => 0.00];
(new CommissionManager())->handleInvoiceRefund(190, 10.00);
eq('zero-total invoice falls back to full (no div-zero)', -50.0, (float) logRows('refund', 190)[0]->amount);

echo "== partial refund across two services on one invoice ==\n";
fixture();
Capsule::$tables['tblhosting'][] = ['id' => 2, 'userid' => 7, 'packageid' => 10, 'domain' => 'b.test', 'domainstatus' => 'Active'];
Capsule::$tables['tblinvoices'][] = ['id' => 140, 'userid' => 7, 'status' => 'Paid', 'total' => 200.00];
Capsule::$tables['tblinvoiceitems'][] = ['id' => 1400, 'invoiceid' => 140, 'type' => 'Hosting', 'relid' => 1, 'amount' => 100.00, 'description' => 'Hosting'];
Capsule::$tables['tblinvoiceitems'][] = ['id' => 1401, 'invoiceid' => 140, 'type' => 'Hosting', 'relid' => 2, 'amount' => 100.00, 'description' => 'Hosting'];
$m = new CommissionManager();
foreach ($m->processInvoicePaid(140) as $c) { $m->recordCommission($c); }
(new CommissionManager())->handleInvoiceRefund(140, 100.00);
$rows = logRows('refund', 140);
eq('each service reversed its own share', 2, count($rows));
eq('service 1 share halved', -25.0, (float) $rows[0]->amount);
eq('service 2 share halved', -25.0, (float) $rows[1]->amount);
ok('service 1 flag stays paid', (int) commissionRow(1)->first_commission_paid === 1);
ok('service 2 flag stays paid', (int) commissionRow(2)->first_commission_paid === 1);

echo "== upgrade within hosting regroups and preserves ==\n";
fixture();
earnFirst(150, 100.00);
Capsule::table('tblhosting')->where('id', 1)->update(['packageid' => 12]);
(new CommissionManager())->handleServiceChange(1, 'upgrade');
eq('stored product regrouped', 12, (int) commissionRow()->product_id);
eq('first flag preserved within hosting', 1, (int) commissionRow()->first_commission_paid);
ok('note records the within-group move', strpos(commissionRow()->notes, 'within hosting group') !== false);

echo "== upgrade out of hosting blocks future commissions ==\n";
fixture();
earnFirst(160, 100.00);
Capsule::table('tblhosting')->where('id', 1)->update(['packageid' => 11]);
(new CommissionManager())->handleServiceChange(1, 'upgrade');
eq('stored product regrouped out', 11, (int) commissionRow()->product_id);
eq('record kept (flag untouched)', 1, (int) commissionRow()->first_commission_paid);
invoice(161, 100.00);
eq('future commission blocked outside hosting', false, (new CommissionManager())->processInvoicePaid(161));

echo "== move into hosting resets tracking ==\n";
fixture();
earnFirst(170, 100.00);
Capsule::table('tblhosting')->where('id', 1)->update(['packageid' => 11]);
(new CommissionManager())->handleServiceChange(1, 'downgrade');
Capsule::table('tblhosting')->where('id', 1)->update(['packageid' => 10]);
(new CommissionManager())->handleServiceChange(1, 'upgrade');
eq('flag reset on move into hosting', 0, (int) commissionRow()->first_commission_paid);
eq('product regrouped back', 10, (int) commissionRow()->product_id);
invoice(171, 100.00);
eq('next payment re-earns the first rate', 'first', (new CommissionManager())->processInvoicePaid(171)[0]['commission_type']);

echo "== change outside hosting regroups without payout impact ==\n";
fixture();
Capsule::$tables['mod_customaffiliate_commissions'][] = ['id' => 1, 'service_id' => 1, 'affiliate_id' => 3, 'client_id' => 7, 'product_id' => 11,
    'first_commission_amount' => 50.0, 'first_commission_paid' => 1, 'first_commission_invoice_id' => 180, 'notes' => null,
    'total_recurring_commission' => 0, 'recurring_count' => 0];
Capsule::table('tblhosting')->where('id', 1)->update(['packageid' => 13]);
(new CommissionManager())->handleServiceChange(1, 'upgrade');
eq('outside-hosting product regrouped', 13, (int) commissionRow()->product_id);
eq('flag untouched outside hosting', 1, (int) commissionRow()->first_commission_paid);

echo "== config-only change notes without regrouping ==\n";
fixture();
earnFirst(182, 100.00);
(new CommissionManager())->handleServiceChange(1, 'upgrade');
ok('note records the unchanged product', strpos(commissionRow()->notes, 'product unchanged: 10') !== false);
eq('product untouched', 10, (int) commissionRow()->product_id);

echo "== service without records is a quiet no-op ==\n";
fixture();
(new CommissionManager())->handleServiceChange(1, 'upgrade');
eq('no rows created for recordless service', 0, count(Capsule::$tables['mod_customaffiliate_commissions'] ?? []));

echo "== UpgradeHandler delegates to the manager ==\n";
fixture();
earnFirst(184, 100.00);
Capsule::table('tblhosting')->where('id', 1)->update(['packageid' => 12]);
$result = (new UpgradeHandler())->processPackageChange(1, 10, 12);
eq('delegate preserves within hosting', 'preserved', $result['action_taken']);
eq('delegate regroups the product', 12, (int) commissionRow()->product_id);

echo "== audit regroups stale products ==\n";
fixture();
earnFirst(186, 100.00);
Capsule::table('tblhosting')->where('id', 1)->update(['packageid' => 11]);
$stats = (new UpgradeHandler())->auditAllServices();
eq('audit regroups the stale product', 11, (int) commissionRow()->product_id);
ok('audit notes the regrouping', strpos(commissionRow()->notes, 'regrouped') !== false);
ok('audit counts the fix', $stats['fixed'] >= 1);
Capsule::$tables['mod_customaffiliate_commissions'][] = ['id' => 9, 'service_id' => 99, 'affiliate_id' => 3, 'client_id' => 7, 'product_id' => 10,
    'first_commission_amount' => 0, 'first_commission_paid' => 0, 'first_commission_invoice_id' => null, 'notes' => null,
    'total_recurring_commission' => 0, 'recurring_count' => 0];
(new UpgradeHandler())->auditAllServices();
ok('audit marks services missing from tblhosting', strpos(commissionRow(99, 3)->notes, 'no longer exists') !== false);

echo "== hooks delegate to the manager ==\n";
$hooks = (string) file_get_contents(__DIR__ . '/../hooks.php');
ok('refund hook passes the refunded amount', strpos($hooks, 'handleInvoiceRefund($invoiceId, $refundAmount') !== false);
eq('both upgrade hooks route through handleServiceChange', 2, substr_count($hooks, 'handleServiceChange($serviceId'));

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
