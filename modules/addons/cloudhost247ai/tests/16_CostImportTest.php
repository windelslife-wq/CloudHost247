<?php
/** Manual infrastructure cost CSV import: validation, all-or-nothing and safe summaries. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Core\Db;
use Ch247Ai\Cost\CostCsvImporter;
use function Ch247Ai\Tools\Readers\ch247ai_infrastructure_cost_summary;

ch247ai_boot();
ch247ai_freeze();
ch247ai_as_super_admin();

T::section('Valid invoice CSV imports atomically with provenance');
$csv = "provider,invoice_reference,period_start,period_end,amount,currency\n"
    . "DigitalOcean,INV-001,2026-09-01,2026-09-30,12.30,USD\n"
    . "DigitalOcean,INV-002,2026-09-01,2026-09-30,0.70,USD\n"
    . "Hetzner,INV-003,2026-09-01,2026-09-30,9.25,EUR\n";
T::eq('three rows imported', 3, CostCsvImporter::import($csv, 1));
T::eq('rows persisted', 3, Db::count('infrastructure_costs', []));
T::eq('admin provenance stored', 1, (int) Db::first('infrastructure_costs', ['invoice_reference' => 'INV-001'])['imported_by']);

T::section('Totals are exact and never mix currencies');
$summary = ch247ai_infrastructure_cost_summary(['limit' => 500]);
T::eq('three cost rows returned', 3, $summary['count']);
$totals = [];
foreach ($summary['summary'] as $row) {
    $totals[$row['currency']] = $row['total'];
}
T::eq('exact USD decimal sum', '13.0000', $totals['USD']);
T::eq('EUR remains separate', '9.2500', $totals['EUR']);
T::ok('provenance disclaimer included', strpos($summary['provenance'], 'not independently verify') !== false);

T::section('Admin costs page explains and exposes CSV import');
$_GET['action'] = 'costs';
$_SERVER['REQUEST_METHOD'] = 'GET';
$portal = new \Ch247Ai\Http\AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']);
$html = $portal->render();
T::ok('cost page reachable', strpos($html, 'Infrastructure costs') !== false);
T::ok('CSV upload form rendered for manager', strpos($html, 'name="cost_csv"') !== false);
T::ok('manual and unverified provenance explained', strpos($html, 'not independently verified') !== false);

T::section('Duplicate invoices rejected without partial writes');
T::throws('previously imported invoice rejected', function () {
    CostCsvImporter::import("provider,invoice_reference,period_start,period_end,amount,currency\nDigitalOcean,INV-001,2026-09-01,2026-09-30,12.30,USD\n", 1);
}, \Ch247Ai\Core\ValidationException::class);
T::eq('duplicate attempt did not insert', 3, Db::count('infrastructure_costs', []));
T::throws('duplicate references inside upload rejected', function () {
    CostCsvImporter::import("provider,invoice_reference,period_start,period_end,amount,currency\nX,INV-X,2026-09-01,2026-09-30,1,USD\nX,INV-X,2026-09-01,2026-09-30,2,USD\n", 1);
}, \Ch247Ai\Core\ValidationException::class);

T::section('Malformed or unsupported CSV fails before database writes');
foreach ([
    "wrong,headers\nX,INV-Y,2026-09-01,2026-09-30,1,USD\n",
    "provider,invoice_reference,period_start,period_end,amount,currency\nX,INV-Y,2026-02-30,2026-03-01,1,USD\n",
    "provider,invoice_reference,period_start,period_end,amount,currency\nX,INV-Y,2026-09-01,2026-09-30,-1,USD\n",
    "provider,invoice_reference,period_start,period_end,amount,currency\nX,INV-Y,2026-09-01,2026-09-30,1,USDX\n",
    "provider,invoice_reference,period_start,period_end,amount,currency\nX,INV-Y,2026-10-01,2026-09-30,1,USD\n",
] as $badCsv) {
    T::throws('invalid CSV refused', function () use ($badCsv) { CostCsvImporter::import($badCsv, 1); }, \Ch247Ai\Core\ValidationException::class);
}
T::eq('invalid rows left ledger unchanged', 3, Db::count('infrastructure_costs', []));
T::throws('file size capped', function () { CostCsvImporter::import(str_repeat('x', CostCsvImporter::MAX_BYTES + 1), 1); }, \Ch247Ai\Core\ValidationException::class);

T::finish();
