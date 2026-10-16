<?php
/** Read-only queue of orders WHMCS has already marked Fraud. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Db;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_fraud_review_tool()
{
    return new ToolDefinition(
        'read_fraud_review_queue',
        'read.orders',
        'READ',
        'List bounded WHMCS orders already marked Fraud. This is a review queue, not an automated fraud score or new risk determination.',
        [
            'limit' => ['type' => 'integer', 'description' => 'Maximum fraud-marked orders to return (1-100)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tblorders']);
            $limit = isset($args['limit']) ? max(1, min(100, (int) $args['limit'])) : 50;
            $sql = "SELECT id, ordernum, userid, status, amount, date, invoiceid FROM tblorders "
                . "WHERE status = ? ORDER BY id DESC LIMIT " . $limit;
            $bind = ['Fraud'];
            $rows = Db::query($sql, $bind);
            return [
                'orders' => $rows,
                'count' => count($rows),
                'interpretation' => 'These orders already carry WHMCS status Fraud. This reader does not calculate risk, determine whether a status is correct, or infer abuse.',
                '_citations' => ch247ai_cite($sql, $bind),
            ];
        },
        ['entity' => 'tblorders']
    );
}
