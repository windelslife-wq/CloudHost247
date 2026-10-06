<?php
/**
 * Domain Broker — reporting and analytics.
 *
 * All figures are computed from the module's own append-only records, grouped
 * by currency (never summed across currencies, which would be meaningless),
 * and filtered by an explicit date range. Export is CSV with the values
 * escaped against spreadsheet formula injection.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Db;
use DomainBroker\Core\Money;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Str;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;
use DomainBroker\Workflow\TransferStatus;

class ReportService
{
    /**
     * Normalise a date filter.
     *
     * @return array{from:string, to:string, label:string}
     */
    public function range(array $filters = [])
    {
        $to = !empty($filters['to']) ? $this->endOfDay($filters['to']) : Clock::now();
        $from = !empty($filters['from'])
            ? $this->startOfDay($filters['from'])
            : gmdate('Y-m-d 00:00:00', Clock::timestamp() - (30 * 86400));

        if (strcmp($from, $to) > 0) {
            $tmp = $from;
            $from = $this->startOfDay($to);
            $to = $this->endOfDay($tmp);
        }
        return ['from' => $from, 'to' => $to, 'label' => substr($from, 0, 10) . ' → ' . substr($to, 0, 10)];
    }

    /**
     * The headline dashboard.
     */
    public function overview(Actor $actor, array $filters = [])
    {
        Rbac::assert($actor, Rbac::REPORT_VIEW);
        $range = $this->range($filters);
        $created = ['created_at' => ['>=', $range['from']]];
        $window = [
            'created_at' => ['>=', $range['from']],
        ];

        $total = $this->countRequests($range);
        $completed = $this->countRequests($range, [RequestStatus::COMPLETED]);
        $failed = $this->countRequests($range, [RequestStatus::FAILED, RequestStatus::REJECTED, RequestStatus::EXPIRED]);
        $cancelled = $this->countRequests($range, [RequestStatus::CANCELLED]);
        $active = $this->countRequests($range, $this->activeStatuses());

        return [
            'range' => $range,
            'requests' => [
                'total' => $total,
                'active' => $active,
                'completed' => $completed,
                'failed' => $failed,
                'cancelled' => $cancelled,
                'disputed' => $this->countRequests($range, [RequestStatus::DISPUTED]),
                'refunded' => $this->countRequests($range, [RequestStatus::REFUNDED]),
            ],
            'negotiations' => [
                'active' => $this->countRequests($range, [
                    RequestStatus::OWNER_CONTACTED, RequestStatus::NEGOTIATION,
                    RequestStatus::OFFER_RECEIVED, RequestStatus::COUNTEROFFER,
                ]),
                'offers_made' => $this->countOffers($range),
                'average_rounds' => $this->averageRounds($range),
            ],
            'conversion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0.0,
            'value' => $this->valueByCurrency($range),
            'revenue' => $this->revenueByCurrency($range),
            'durations' => [
                'negotiation_days' => $this->averageDurationDays($range, 'submitted_at', 'status_changed_at', [RequestStatus::OFFER_ACCEPTED]),
                'transfer_days' => $this->averageTransferDays($range),
                'end_to_end_days' => $this->averageDurationDays($range, 'submitted_at', 'completed_at', [RequestStatus::COMPLETED]),
            ],
            'pending' => [
                'payments' => Db::count('payments', [
                    'type' => 'acquisition',
                    'status' => ['in', [PaymentStatus::PENDING, PaymentStatus::INVOICE_GENERATED, PaymentStatus::FAILED]],
                ]),
                'transfers' => Db::count('transfers', [
                    'status' => ['in', [
                        TransferStatus::AUTH_PENDING, TransferStatus::AUTH_RECEIVED,
                        TransferStatus::INITIATED, TransferStatus::PENDING, TransferStatus::APPROVED,
                    ]],
                ]),
                'manual_review' => Db::count('requests', ['manual_review' => 1, 'deleted_at' => null]),
                'unassigned' => Db::count('requests', [
                    'assigned_broker_id' => null,
                    'deleted_at' => null,
                    'status' => ['in', [RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW]],
                ]),
            ],
            'disputes' => (new DisputeService())->statistics(),
            'refunds' => $this->refundsByCurrency($range),
            'status_breakdown' => $this->statusBreakdown($range),
            'top_tlds' => $this->topTlds($range),
            'generated_at' => Clock::now(),
        ];
    }

    /* ------------------------------------------------------------ money */

    /** Acquisition value of completed deals, grouped by currency. */
    public function valueByCurrency(array $range)
    {
        $rows = Db::select(
            'SELECT currency, COUNT(*) AS deals, SUM(agreed_amount_minor) AS total,'
            . ' MAX(agreed_amount_minor) AS largest'
            . ' FROM ' . Db::quoteIdentifier(Db::table('requests'))
            . ' WHERE deleted_at IS NULL AND status = ? AND completed_at >= ? AND completed_at <= ?'
            . ' GROUP BY currency',
            [RequestStatus::COMPLETED, $range['from'], $range['to']]
        );

        $out = [];
        foreach ($rows as $row) {
            $deals = (int) $row['deals'];
            $total = (int) $row['total'];
            $out[$row['currency']] = [
                'currency' => $row['currency'],
                'deals' => $deals,
                'total_minor' => $total,
                'total' => Money::toDecimalString($total, $row['currency']),
                'average_minor' => $deals > 0 ? (int) round($total / $deals) : 0,
                'average' => Money::toDecimalString($deals > 0 ? (int) round($total / $deals) : 0, $row['currency']),
                'largest_minor' => (int) $row['largest'],
                'largest' => Money::toDecimalString((int) $row['largest'], $row['currency']),
            ];
        }
        return $out;
    }

    /** Broker/service fee revenue actually settled, grouped by currency. */
    public function revenueByCurrency(array $range)
    {
        $rows = Db::select(
            'SELECT currency, COUNT(*) AS payments, SUM(fee_minor) AS fees, SUM(tax_minor) AS tax,'
            . ' SUM(total_minor) AS gross, SUM(refunded_minor) AS refunded'
            . ' FROM ' . Db::quoteIdentifier(Db::table('payments'))
            . ' WHERE type = ? AND status IN (?, ?, ?, ?) AND created_at >= ? AND created_at <= ?'
            . ' GROUP BY currency',
            [
                'acquisition',
                PaymentStatus::RECEIVED, PaymentStatus::FUNDS_SECURED,
                PaymentStatus::RELEASED, PaymentStatus::PARTIALLY_REFUNDED,
                $range['from'], $range['to'],
            ]
        );

        $out = [];
        foreach ($rows as $row) {
            $fees = (int) $row['fees'];
            $out[$row['currency']] = [
                'currency' => $row['currency'],
                'payments' => (int) $row['payments'],
                'fees_minor' => $fees,
                'fees' => Money::toDecimalString($fees, $row['currency']),
                'tax_minor' => (int) $row['tax'],
                'tax' => Money::toDecimalString((int) $row['tax'], $row['currency']),
                'gross_minor' => (int) $row['gross'],
                'gross' => Money::toDecimalString((int) $row['gross'], $row['currency']),
                'refunded_minor' => (int) $row['refunded'],
                'refunded' => Money::toDecimalString((int) $row['refunded'], $row['currency']),
                'net_minor' => $fees - (int) $row['refunded'],
            ];
        }
        return $out;
    }

    public function refundsByCurrency(array $range)
    {
        $rows = Db::select(
            'SELECT currency, COUNT(*) AS count, SUM(total_minor) AS total'
            . ' FROM ' . Db::quoteIdentifier(Db::table('payments'))
            . ' WHERE type IN (?, ?) AND created_at >= ? AND created_at <= ?'
            . ' GROUP BY currency',
            ['refund', 'partial_refund', $range['from'], $range['to']]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['currency']] = [
                'currency' => $row['currency'],
                'count' => (int) $row['count'],
                'total_minor' => (int) $row['total'],
                'total' => Money::toDecimalString((int) $row['total'], $row['currency']),
            ];
        }
        return $out;
    }

    /* ---------------------------------------------------------- brokers */

    /**
     * Per-broker performance table.
     */
    public function brokerPerformance(Actor $actor, array $filters = [])
    {
        Rbac::assert($actor, Rbac::REPORT_VIEW);
        $range = $this->range($filters);
        $requests = Db::quoteIdentifier(Db::table('requests'));
        $out = [];

        foreach (Db::fetch('brokers', ['deleted_at' => null], ['order' => 'display_name']) as $broker) {
            $base = [
                'assigned_broker_id' => (int) $broker['id'],
                'deleted_at' => null,
                'created_at' => ['>=', $range['from']],
            ];
            $assigned = Db::count('requests', $base);
            $completed = Db::count('requests', array_merge($base, ['status' => RequestStatus::COMPLETED]));
            $failed = Db::count('requests', array_merge($base, [
                'status' => ['in', [RequestStatus::FAILED, RequestStatus::REJECTED, RequestStatus::EXPIRED]],
            ]));

            $revenue = Db::select(
                'SELECT r.currency AS currency, SUM(r.broker_fee_minor) AS fees, SUM(r.agreed_amount_minor) AS value'
                . ' FROM ' . $requests . ' r'
                . ' WHERE r.deleted_at IS NULL AND r.assigned_broker_id = ? AND r.status = ?'
                . ' AND r.completed_at >= ? AND r.completed_at <= ?'
                . ' GROUP BY r.currency',
                [(int) $broker['id'], RequestStatus::COMPLETED, $range['from'], $range['to']]
            );

            $byCurrency = [];
            foreach ($revenue as $row) {
                $byCurrency[$row['currency']] = [
                    'fees_minor' => (int) $row['fees'],
                    'fees' => Money::toDecimalString((int) $row['fees'], $row['currency']),
                    'value_minor' => (int) $row['value'],
                    'value' => Money::toDecimalString((int) $row['value'], $row['currency']),
                ];
            }

            $out[] = [
                'broker_id' => (int) $broker['id'],
                'name' => $broker['display_name'],
                'status' => $broker['status'],
                'assigned' => $assigned,
                'completed' => $completed,
                'failed' => $failed,
                'active' => (int) $broker['active_count'],
                'success_rate' => $assigned > 0 ? round(($completed / $assigned) * 100, 2) : 0.0,
                'commission_percentage' => $broker['commission_percentage'] !== null
                    ? (float) $broker['commission_percentage'] : null,
                'revenue' => $byCurrency,
                'average_close_days' => $this->averageCloseDaysForBroker((int) $broker['id'], $range),
            ];
        }

        return ['range' => $range, 'brokers' => $out];
    }

    /* ------------------------------------------------------- breakdowns */

    public function statusBreakdown(array $range)
    {
        $rows = Db::select(
            'SELECT status, COUNT(*) AS count FROM ' . Db::quoteIdentifier(Db::table('requests'))
            . ' WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= ? GROUP BY status',
            [$range['from'], $range['to']]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'status' => $row['status'],
                'label' => RequestStatus::label($row['status']),
                'tone' => RequestStatus::tone($row['status']),
                'count' => (int) $row['count'],
            ];
        }
        return $out;
    }

    public function topTlds(array $range, $limit = 10)
    {
        $rows = Db::select(
            'SELECT tld, COUNT(*) AS count FROM ' . Db::quoteIdentifier(Db::table('requests'))
            . ' WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= ?'
            . ' GROUP BY tld ORDER BY count DESC LIMIT ' . max(1, (int) $limit),
            [$range['from'], $range['to']]
        );
        return array_map(function ($row) {
            return ['tld' => $row['tld'], 'count' => (int) $row['count']];
        }, $rows);
    }

    /** Requests per day, for the dashboard chart. */
    public function timeline(array $range)
    {
        $rows = Db::select(
            'SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS count'
            . ' FROM ' . Db::quoteIdentifier(Db::table('requests'))
            . ' WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= ?'
            . ' GROUP BY substr(created_at, 1, 10) ORDER BY day',
            [$range['from'], $range['to']]
        );
        return array_map(function ($row) {
            return ['day' => $row['day'], 'count' => (int) $row['count']];
        }, $rows);
    }

    /* ------------------------------------------------------------ export */

    /**
     * Export a dataset as CSV.
     *
     * @param string $dataset requests|payments|brokers|offers|disputes
     * @return array{filename:string, mime:string, body:string}
     */
    public function export(Actor $actor, $dataset, array $filters = [])
    {
        Rbac::assert($actor, Rbac::REPORT_EXPORT);
        $range = $this->range($filters);

        switch ($dataset) {
            case 'payments':
                $headers = ['Reference', 'Request', 'Domain', 'Type', 'Status', 'Currency',
                    'Acquisition', 'Fee', 'Tax', 'Total', 'Refunded', 'Invoice', 'Created'];
                $rows = $this->exportPayments($range);
                break;
            case 'brokers':
                $headers = ['Broker', 'Status', 'Assigned', 'Completed', 'Failed', 'Success %', 'Active'];
                $rows = $this->exportBrokers($actor, $range);
                break;
            case 'offers':
                $headers = ['Offer', 'Request', 'Domain', 'Round', 'Direction', 'Amount', 'Currency',
                    'Status', 'Created', 'Responded'];
                $rows = $this->exportOffers($range);
                break;
            case 'disputes':
                $headers = ['Reference', 'Request', 'Reason', 'Status', 'Severity', 'Opened', 'Resolved', 'Resolution'];
                $rows = $this->exportDisputes($range);
                break;
            case 'requests':
            default:
                $dataset = 'requests';
                $headers = ['Reference', 'Domain', 'Client', 'Status', 'Currency', 'Budget',
                    'Agreed', 'Fee', 'Tax', 'Total', 'Payment', 'Transfer', 'Broker', 'Created', 'Completed'];
                $rows = $this->exportRequests($range);
                break;
        }

        $body = $this->toCsv($headers, $rows);

        Audit::record($actor, 'report.exported', [
            'entity_type' => 'report',
            'entity_id' => 0,
            'new' => ['dataset' => $dataset, 'range' => $range['label'], 'rows' => count($rows)],
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return [
            'filename' => 'domain-broker-' . $dataset . '-' . substr($range['from'], 0, 10)
                . '-to-' . substr($range['to'], 0, 10) . '.csv',
            'mime' => 'text/csv; charset=UTF-8',
            'body' => $body,
        ];
    }

    protected function exportRequests(array $range)
    {
        $sql = 'SELECT r.*, b.display_name AS broker_name FROM ' . Db::quoteIdentifier(Db::table('requests')) . ' r'
            . ' LEFT JOIN ' . Db::quoteIdentifier(Db::table('brokers')) . ' b ON b.id = r.assigned_broker_id'
            . ' WHERE r.deleted_at IS NULL AND r.created_at >= ? AND r.created_at <= ? ORDER BY r.id';
        $rows = [];
        foreach (Db::select($sql, [$range['from'], $range['to']]) as $r) {
            $rows[] = [
                $r['reference'], $r['domain'], $r['client_id'], RequestStatus::label($r['status']), $r['currency'],
                Money::toDecimalString((int) $r['budget_minor'], $r['currency']),
                Money::toDecimalString((int) $r['agreed_amount_minor'], $r['currency']),
                Money::toDecimalString((int) $r['broker_fee_minor'], $r['currency']),
                Money::toDecimalString((int) $r['tax_minor'], $r['currency']),
                Money::toDecimalString((int) $r['total_minor'], $r['currency']),
                PaymentStatus::label($r['payment_status']),
                TransferStatus::label($r['transfer_status']),
                $r['broker_name'], $r['created_at'], $r['completed_at'],
            ];
        }
        return $rows;
    }

    protected function exportPayments(array $range)
    {
        $sql = 'SELECT p.*, r.reference AS request_reference, r.domain FROM '
            . Db::quoteIdentifier(Db::table('payments')) . ' p'
            . ' INNER JOIN ' . Db::quoteIdentifier(Db::table('requests')) . ' r ON r.id = p.request_id'
            . ' WHERE p.created_at >= ? AND p.created_at <= ? ORDER BY p.id';
        $rows = [];
        foreach (Db::select($sql, [$range['from'], $range['to']]) as $p) {
            $rows[] = [
                $p['reference'], $p['request_reference'], $p['domain'], $p['type'],
                PaymentStatus::label($p['status']), $p['currency'],
                Money::toDecimalString((int) $p['acquisition_minor'], $p['currency']),
                Money::toDecimalString((int) $p['fee_minor'], $p['currency']),
                Money::toDecimalString((int) $p['tax_minor'], $p['currency']),
                Money::toDecimalString((int) $p['total_minor'], $p['currency']),
                Money::toDecimalString((int) $p['refunded_minor'], $p['currency']),
                $p['whmcs_invoice_id'], $p['created_at'],
            ];
        }
        return $rows;
    }

    protected function exportOffers(array $range)
    {
        $sql = 'SELECT o.*, r.reference AS request_reference, r.domain FROM '
            . Db::quoteIdentifier(Db::table('offers')) . ' o'
            . ' INNER JOIN ' . Db::quoteIdentifier(Db::table('requests')) . ' r ON r.id = o.request_id'
            . ' WHERE o.created_at >= ? AND o.created_at <= ? ORDER BY o.id';
        $rows = [];
        foreach (Db::select($sql, [$range['from'], $range['to']]) as $o) {
            $rows[] = [
                $o['reference'], $o['request_reference'], $o['domain'], $o['round'], $o['direction'],
                Money::toDecimalString((int) $o['amount_minor'], $o['currency']), $o['currency'],
                $o['status'], $o['created_at'], $o['responded_at'],
            ];
        }
        return $rows;
    }

    protected function exportDisputes(array $range)
    {
        $sql = 'SELECT d.*, r.reference AS request_reference FROM '
            . Db::quoteIdentifier(Db::table('disputes')) . ' d'
            . ' INNER JOIN ' . Db::quoteIdentifier(Db::table('requests')) . ' r ON r.id = d.request_id'
            . ' WHERE d.created_at >= ? AND d.created_at <= ? ORDER BY d.id';
        $rows = [];
        foreach (Db::select($sql, [$range['from'], $range['to']]) as $d) {
            $rows[] = [
                $d['reference'], $d['request_reference'], $d['reason_code'], $d['status'], $d['severity'],
                $d['created_at'], $d['resolved_at'], Str::clip((string) $d['resolution'], 300),
            ];
        }
        return $rows;
    }

    protected function exportBrokers(Actor $actor, array $range)
    {
        $data = $this->brokerPerformance($actor, $range);
        $rows = [];
        foreach ($data['brokers'] as $b) {
            $rows[] = [$b['name'], $b['status'], $b['assigned'], $b['completed'], $b['failed'],
                $b['success_rate'], $b['active']];
        }
        return $rows;
    }

    /**
     * CSV with formula-injection protection: a cell starting with =, +, -, @
     * or a control character is prefixed with a single quote so spreadsheets
     * treat it as text.
     */
    public function toCsv(array $headers, array $rows)
    {
        $lines = [$this->csvLine($headers)];
        foreach ($rows as $row) {
            $lines[] = $this->csvLine($row);
        }
        // BOM so Excel reads UTF-8 correctly.
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    protected function csvLine(array $values)
    {
        $cells = [];
        foreach ($values as $value) {
            $value = $value === null ? '' : (string) $value;
            if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
                $value = "'" . $value;
            }
            $cells[] = '"' . str_replace('"', '""', $value) . '"';
        }
        return implode(',', $cells);
    }

    /* ---------------------------------------------------------- helpers */

    protected function activeStatuses()
    {
        return array_values(array_diff(RequestStatus::PIPELINE, [RequestStatus::COMPLETED]));
    }

    protected function countRequests(array $range, array $statuses = null)
    {
        $where = [
            'deleted_at' => null,
            'created_at' => ['>=', $range['from']],
        ];
        $rows = Db::select(
            'SELECT COUNT(*) AS c FROM ' . Db::quoteIdentifier(Db::table('requests'))
            . ' WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= ?'
            . ($statuses ? ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')' : ''),
            array_merge([$range['from'], $range['to']], $statuses ?: [])
        );
        return $rows ? (int) $rows[0]['c'] : 0;
    }

    protected function countOffers(array $range)
    {
        $rows = Db::select(
            'SELECT COUNT(*) AS c FROM ' . Db::quoteIdentifier(Db::table('offers'))
            . ' WHERE created_at >= ? AND created_at <= ?',
            [$range['from'], $range['to']]
        );
        return $rows ? (int) $rows[0]['c'] : 0;
    }

    protected function averageRounds(array $range)
    {
        $rows = Db::select(
            'SELECT AVG(negotiation_rounds) AS a FROM ' . Db::quoteIdentifier(Db::table('requests'))
            . ' WHERE deleted_at IS NULL AND negotiation_rounds > 0 AND created_at >= ? AND created_at <= ?',
            [$range['from'], $range['to']]
        );
        return $rows && $rows[0]['a'] !== null ? round((float) $rows[0]['a'], 2) : 0.0;
    }

    /**
     * Average elapsed days between two datetime columns. Computed in PHP so
     * the expression works identically on MySQL and SQLite.
     */
    protected function averageDurationDays(array $range, $fromColumn, $toColumn, array $statuses)
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $rows = Db::select(
            'SELECT ' . Db::quoteIdentifier($fromColumn) . ' AS a, ' . Db::quoteIdentifier($toColumn) . ' AS b'
            . ' FROM ' . Db::quoteIdentifier(Db::table('requests'))
            . ' WHERE deleted_at IS NULL AND status IN (' . $placeholders . ')'
            . ' AND created_at >= ? AND created_at <= ?'
            . ' AND ' . Db::quoteIdentifier($fromColumn) . ' IS NOT NULL'
            . ' AND ' . Db::quoteIdentifier($toColumn) . ' IS NOT NULL',
            array_merge($statuses, [$range['from'], $range['to']])
        );
        return $this->averageDays($rows);
    }

    protected function averageTransferDays(array $range)
    {
        $rows = Db::select(
            'SELECT initiated_at AS a, completed_at AS b FROM ' . Db::quoteIdentifier(Db::table('transfers'))
            . ' WHERE status = ? AND initiated_at IS NOT NULL AND completed_at IS NOT NULL'
            . ' AND created_at >= ? AND created_at <= ?',
            [TransferStatus::COMPLETED, $range['from'], $range['to']]
        );
        return $this->averageDays($rows);
    }

    protected function averageCloseDaysForBroker($brokerId, array $range)
    {
        $rows = Db::select(
            'SELECT assigned_at AS a, completed_at AS b FROM ' . Db::quoteIdentifier(Db::table('requests'))
            . ' WHERE deleted_at IS NULL AND assigned_broker_id = ? AND status = ?'
            . ' AND assigned_at IS NOT NULL AND completed_at IS NOT NULL'
            . ' AND completed_at >= ? AND completed_at <= ?',
            [(int) $brokerId, RequestStatus::COMPLETED, $range['from'], $range['to']]
        );
        return $this->averageDays($rows);
    }

    protected function averageDays(array $rows)
    {
        if (!$rows) {
            return 0.0;
        }
        $total = 0;
        $count = 0;
        foreach ($rows as $row) {
            $from = Clock::toTimestamp($row['a']);
            $to = Clock::toTimestamp($row['b']);
            if (!$from || !$to || $to < $from) {
                continue;
            }
            $total += ($to - $from);
            $count++;
        }
        return $count > 0 ? round(($total / $count) / 86400, 2) : 0.0;
    }

    protected function startOfDay($value)
    {
        $ts = Clock::toTimestamp($value);
        return $ts ? gmdate('Y-m-d 00:00:00', $ts) : gmdate('Y-m-d 00:00:00', Clock::timestamp());
    }

    protected function endOfDay($value)
    {
        $ts = Clock::toTimestamp($value);
        return $ts ? gmdate('Y-m-d 23:59:59', $ts) : Clock::now();
    }
}
