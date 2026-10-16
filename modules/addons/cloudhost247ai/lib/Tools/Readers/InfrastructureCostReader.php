<?php
/** Read-only summaries of manually imported infrastructure invoices. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_cost_table_exists()
{
    if (Db::driver() === 'sqlite') {
        return Db::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [Db::t('infrastructure_costs')]) !== [];
    }
    return Db::query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [Db::t('infrastructure_costs')]) !== [];
}

/** Add non-negative decimal values with four-place precision without floats. */
function ch247ai_cost_add_decimal($left, $right)
{
    $toDigits = function ($value) {
        $parts = explode('.', (string) $value, 2);
        return ltrim($parts[0] . str_pad(isset($parts[1]) ? $parts[1] : '', 4, '0'), '0') ?: '0';
    };
    $a = $toDigits($left);
    $b = $toDigits($right);
    $i = strlen($a) - 1;
    $j = strlen($b) - 1;
    $carry = 0;
    $out = '';
    while ($i >= 0 || $j >= 0 || $carry > 0) {
        $sum = ($i >= 0 ? (int) $a[$i--] : 0) + ($j >= 0 ? (int) $b[$j--] : 0) + $carry;
        $out = (string) ($sum % 10) . $out;
        $carry = (int) floor($sum / 10);
    }
    $out = str_pad($out, 5, '0', STR_PAD_LEFT);
    $whole = substr($out, 0, -4);
    $fraction = substr($out, -4);
    return $whole . '.' . $fraction;
}

function ch247ai_infrastructure_cost_summary(array $args = [])
{
    if (!ch247ai_cost_table_exists()) {
        throw new ServiceUnavailableException('DATA_UNAVAILABLE: manual infrastructure cost ledger is not installed.');
    }
    $limit = isset($args['limit']) ? (int) $args['limit'] : 200;
    $limit = max(1, min(500, $limit));
    $sql = 'SELECT provider, invoice_reference, period_start, period_end, amount, currency '
        . 'FROM ' . Db::t('infrastructure_costs') . ' ORDER BY period_start DESC, id DESC LIMIT ' . $limit;
    $rows = Db::query($sql, []);
    $groups = [];
    foreach ($rows as $row) {
        $key = $row['provider'] . "\0" . $row['currency'] . "\0" . $row['period_start'] . "\0" . $row['period_end'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'provider' => $row['provider'], 'currency' => $row['currency'],
                'period_start' => $row['period_start'], 'period_end' => $row['period_end'],
                'invoice_count' => 0, 'total' => '0.0000',
            ];
        }
        $groups[$key]['invoice_count']++;
        $groups[$key]['total'] = ch247ai_cost_add_decimal($groups[$key]['total'], $row['amount']);
    }
    return [
        'costs' => $rows,
        'summary' => array_values($groups),
        'count' => count($rows),
        'provenance' => 'Manually imported invoice-level cost data; the platform does not independently verify provider invoices or foreign-exchange rates.',
        '_citations' => [$sql . ' | params=[]'],
    ];
}

function ch247ai_infrastructure_cost_tool()
{
    return new ToolDefinition(
        'read_infrastructure_costs',
        'read.metrics',
        'READ',
        'Read manually imported provider invoice costs and deterministic totals grouped by provider, currency, and billing period. Values are not independently provider-verified.',
        [
            'limit' => ['type' => 'integer', 'description' => 'Maximum cost records to include (1-500)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            return ch247ai_infrastructure_cost_summary($args);
        },
        ['entity' => 'infrastructure_costs']
    );
}
