<?php
/**
 * Metrics READ tool — the deterministic numbers behind the Briefing Composer.
 * Every figure is one SQL COUNT/SUM over live WHMCS tables with the exact
 * query attached; the composer can therefore narrate without a model.
 */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_metric($label, $sql, array $bind)
{
    $rows = Db::query($sql, $bind);
    $value = $rows && isset($rows[0]['v']) ? (float) $rows[0]['v'] : 0.0;
    return ['metric' => $label, 'value' => $value, 'sql' => $sql, 'params' => $bind];
}

function ch247ai_platform_metrics(array $windows = ['today', '7d', '30d'])
{
    if (!Db::whmcsTableExists('tblinvoices') || !Db::whmcsTableExists('tblclients')) {
        return ['metrics' => [], 'reason' => 'DATA_UNAVAILABLE: WHMCS billing tables are not present in this installation.'];
    }
    $today = Clock::today();
    $weekAgo = gmdate('Y-m-d', Clock::time() - 7 * 86400);
    $monthAgo = gmdate('Y-m-d', Clock::time() - 30 * 86400);
    $out = [];
    if (in_array('today', $windows, true)) {
        $out[] = ch247ai_metric('new_clients_today', 'SELECT COUNT(*) AS v FROM tblclients WHERE datecreated >= ?', [$today]);
        $out[] = ch247ai_metric('orders_today', 'SELECT COUNT(*) AS v FROM tblorders WHERE date >= ?', [$today]);
        $out[] = ch247ai_metric('paid_today_amount', 'SELECT COALESCE(SUM(amount_in - amount_out),0) AS v FROM tblaccounts WHERE date >= ?', [$today]);
        $out[] = ch247ai_metric('tickets_opened_today', 'SELECT COUNT(*) AS v FROM tbltickets WHERE date >= ?', [$today]);
    }
    if (in_array('7d', $windows, true)) {
        $out[] = ch247ai_metric('new_clients_7d', 'SELECT COUNT(*) AS v FROM tblclients WHERE datecreated >= ?', [$weekAgo]);
        $out[] = ch247ai_metric('orders_7d', 'SELECT COUNT(*) AS v FROM tblorders WHERE date >= ?', [$weekAgo]);
        $out[] = ch247ai_metric('paid_7d_amount', 'SELECT COALESCE(SUM(amount_in - amount_out),0) AS v FROM tblaccounts WHERE date >= ?', [$weekAgo]);
    }
    if (in_array('30d', $windows, true)) {
        $out[] = ch247ai_metric('new_clients_30d', 'SELECT COUNT(*) AS v FROM tblclients WHERE datecreated >= ?', [$monthAgo]);
        $out[] = ch247ai_metric('paid_30d_amount', 'SELECT COALESCE(SUM(amount_in - amount_out),0) AS v FROM tblaccounts WHERE date >= ?', [$monthAgo]);
    }
    $out[] = ch247ai_metric('clients_active_total', 'SELECT COUNT(*) AS v FROM tblclients WHERE status = ?', ['Active']);
    $out[] = ch247ai_metric('invoices_unpaid_total', 'SELECT COUNT(*) AS v FROM tblinvoices WHERE status = ?', ['Unpaid']);
    $out[] = ch247ai_metric('invoices_unpaid_amount', 'SELECT COALESCE(SUM(total - credit),0) AS v FROM tblinvoices WHERE status = ?', ['Unpaid']);
    $out[] = ch247ai_metric('services_active_total', Db::driver() === 'sqlite'
        ? 'SELECT COUNT(*) AS v FROM tblhosting WHERE domainstatus = ?'
        : 'SELECT COUNT(*) AS v FROM tblhosting WHERE domainstatus = ?', ['Active']);
    $out[] = ch247ai_metric('tickets_open_total', 'SELECT COUNT(*) AS v FROM tbltickets WHERE status != ?', ['Closed']);
    $out[] = ch247ai_metric('domains_expiring_30d', 'SELECT COUNT(*) AS v FROM tbldomains WHERE expirydate != ? AND expirydate <= ? AND expirydate >= ?', ['0000-00-00', gmdate('Y-m-d', Clock::time() + 30 * 86400), $today]);
    $out[] = ch247ai_metric('services_due_7d', 'SELECT COUNT(*) AS v FROM tblhosting WHERE nextduedate != ? AND nextduedate <= ? AND nextduedate >= ?', ['0000-00-00', gmdate('Y-m-d', Clock::time() + 7 * 86400), $today]);
    return ['metrics' => $out];
}

function ch247ai_metrics_tool()
{
    return new ToolDefinition(
        'read_metrics',
        'read.metrics',
        'READ',
        'Deterministic platform metrics (counts and sums over live WHMCS tables) with the exact SQL used. Use for briefings and status questions.',
        [
            'windows' => ['type' => 'string', 'description' => 'Comma list: today,7d,30d', 'required' => false],
        ],
        function (array $args, array $ctx) {
            $windows = ['today', '7d', '30d'];
            if (!empty($args['windows'])) {
                $windows = array_values(array_intersect(['today', '7d', '30d'], array_map('trim', explode(',', (string) $args['windows']))));
                if ($windows === []) {
                    $windows = ['today'];
                }
            }
            $result = ch247ai_platform_metrics($windows);
            $citations = [];
            foreach ($result['metrics'] as $metric) {
                $citations[] = $metric['sql'];
            }
            return $result + ['_citations' => $citations];
        },
        ['entity' => 'whmcs_billing_tables']
    );
}
