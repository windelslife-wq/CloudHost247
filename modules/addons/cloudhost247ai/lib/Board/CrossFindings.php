<?php
/**
 * Cross-department findings — the part that makes this a board rather than
 * nine separate reports.
 *
 * The brief's war-room example has the CFO observe failed payments, the CRO
 * note those customers' renewal value, and the COO note the matching support
 * load. Implemented literally that would be three agents asserting things
 * about each other's domains, which is exactly the fabrication the safety
 * rules forbid: no agent may state another agent's conclusion.
 *
 * So the correlation is computed, not conversed. Each finding below is ONE
 * SQL join across two departments' tables, carrying the query as its own
 * citation and naming the seats whose data it spans. Nothing here is an
 * opinion, and nothing here can disagree with the seat packs, because it is
 * drawn from the same rows.
 *
 * Any finding whose tables are absent is omitted and reported as skipped —
 * never estimated from a neighbouring table.
 *
 * @package Ch247Ai\Board
 */

namespace Ch247Ai\Board;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;

class CrossFindings
{
    /**
     * @return array{findings: array<int,array>, skipped: array<int,array>}
     */
    public static function all()
    {
        $findings = [];
        $skipped = [];
        foreach (self::definitions() as $def) {
            $missing = [];
            foreach ($def['tables'] as $t) {
                if (!Db::whmcsTableExists($t)) {
                    $missing[] = $t;
                }
            }
            if ($missing !== []) {
                $skipped[] = [
                    'key' => $def['key'],
                    'title' => $def['title'],
                    'seats' => $def['seats'],
                    'reason' => 'DATA_UNAVAILABLE: missing ' . implode(', ', $missing) . '.',
                ];
                continue;
            }
            $rows = Db::query($def['sql'], $def['params']);
            $count = $rows && isset($rows[0]['v']) ? (float) $rows[0]['v'] : 0.0;
            $amount = $rows && isset($rows[0]['amt']) ? (float) $rows[0]['amt'] : null;
            if ($count <= 0) {
                continue; // nothing correlated — say nothing rather than pad the report
            }
            $findings[] = [
                'key' => $def['key'],
                'title' => $def['title'],
                'seats' => $def['seats'],
                'severity' => $def['severity'],
                'count' => $count,
                'amount' => $amount,
                'text' => call_user_func($def['render'], $count, $amount),
                'sql' => $def['sql'],
                'params' => $def['params'],
            ];
        }
        return ['findings' => $findings, 'skipped' => $skipped];
    }

    /** @return array<int,array> */
    protected static function definitions()
    {
        $today = Clock::today();
        $weekAhead = gmdate('Y-m-d', Clock::time() + 7 * 86400);
        $monthAhead = gmdate('Y-m-d', Clock::time() + 30 * 86400);
        $monthAgo = gmdate('Y-m-d', Clock::time() - 30 * 86400);
        $money = function ($v) { return number_format((float) $v, 2, '.', ''); };

        return [
            [
                'key' => 'revenue_at_risk_renewals',
                'title' => 'Renewals owned by customers already in arrears',
                'seats' => ['cfo', 'coo'],
                'severity' => DepartmentPacks::SEV_CRITICAL,
                'tables' => ['tblinvoices', 'tblhosting'],
                'sql' => 'SELECT COUNT(DISTINCT h.id) AS v, COALESCE(SUM(h.amount),0) AS amt '
                    . 'FROM tblhosting h WHERE h.domainstatus = ? AND h.nextduedate >= ? AND h.nextduedate <= ? '
                    . 'AND h.userid IN (SELECT userid FROM tblinvoices WHERE status = ? AND duedate < ?)',
                'params' => ['Active', $today, $weekAhead, 'Unpaid', $today],
                'render' => function ($count, $amount) use ($money) {
                    return (int) $count . ' active service(s) worth ' . $money($amount)
                        . ' renew within 7 days and belong to customers who already have an overdue invoice.';
                },
            ],
            [
                'key' => 'debtors_in_support',
                'title' => 'Customers in arrears who are also in the support queue',
                'seats' => ['cfo', 'cco'],
                'severity' => DepartmentPacks::SEV_WARN,
                'tables' => ['tblinvoices', 'tbltickets'],
                'sql' => 'SELECT COUNT(DISTINCT t.userid) AS v FROM tbltickets t '
                    . 'WHERE t.status != ? AND t.userid > 0 '
                    . 'AND t.userid IN (SELECT userid FROM tblinvoices WHERE status = ? AND duedate < ?)',
                'params' => ['Closed', 'Unpaid', $today],
                'render' => function ($count) {
                    return (int) $count . ' customer(s) have both an overdue invoice and an open support ticket — '
                        . 'collections and support are contacting the same people.';
                },
            ],
            [
                'key' => 'suspended_with_open_tickets',
                'title' => 'Suspended services whose owner is waiting on support',
                'seats' => ['coo', 'cco'],
                'severity' => DepartmentPacks::SEV_WARN,
                'tables' => ['tblhosting', 'tbltickets'],
                'sql' => 'SELECT COUNT(DISTINCT h.id) AS v FROM tblhosting h '
                    . 'WHERE h.domainstatus = ? '
                    . 'AND h.userid IN (SELECT userid FROM tbltickets WHERE status != ? AND userid > 0)',
                'params' => ['Suspended', 'Closed'],
                'render' => function ($count) {
                    return (int) $count . ' suspended service(s) belong to customers with an open ticket.';
                },
            ],
            [
                'key' => 'expiring_domains_for_debtors',
                'title' => 'Domains expiring for customers in arrears',
                'seats' => ['cfo', 'coo'],
                'severity' => DepartmentPacks::SEV_WARN,
                'tables' => ['tbldomains', 'tblinvoices'],
                'sql' => 'SELECT COUNT(*) AS v FROM tbldomains d '
                    . 'WHERE d.expirydate != ? AND d.expirydate >= ? AND d.expirydate <= ? '
                    . 'AND d.userid IN (SELECT userid FROM tblinvoices WHERE status = ? AND duedate < ?)',
                'params' => ['0000-00-00', $today, $monthAhead, 'Unpaid', $today],
                'render' => function ($count) {
                    return (int) $count . ' domain(s) expire within 30 days for customers who have an overdue invoice — '
                        . 'non-payment risks losing the domain, not just the hosting.';
                },
            ],
            [
                'key' => 'new_clients_already_overdue',
                'title' => 'Newly acquired customers who are already overdue',
                'seats' => ['cro', 'cfo'],
                'severity' => DepartmentPacks::SEV_WARN,
                'tables' => ['tblclients', 'tblinvoices'],
                'sql' => 'SELECT COUNT(DISTINCT c.id) AS v FROM tblclients c '
                    . 'WHERE c.datecreated >= ? '
                    . 'AND c.id IN (SELECT userid FROM tblinvoices WHERE status = ? AND duedate < ?)',
                'params' => [$monthAgo, 'Unpaid', $today],
                'render' => function ($count) {
                    return (int) $count . ' customer(s) acquired in the last 30 days already have an overdue invoice — '
                        . 'acquisition quality or onboarding billing may need review.';
                },
            ],
            [
                'key' => 'pending_orders_with_paid_invoice',
                'title' => 'Paid orders still awaiting provisioning',
                'seats' => ['coo', 'cfo'],
                'severity' => DepartmentPacks::SEV_CRITICAL,
                'tables' => ['tblorders', 'tblinvoices'],
                'sql' => 'SELECT COUNT(*) AS v FROM tblorders o '
                    . 'WHERE o.status = ? AND o.invoiceid > 0 '
                    . 'AND o.invoiceid IN (SELECT id FROM tblinvoices WHERE status = ?)',
                'params' => ['Pending', 'Paid'],
                'render' => function ($count) {
                    return (int) $count . ' order(s) are paid but still Pending — the customer has been charged '
                        . 'and is not yet being served.';
                },
            ],
        ];
    }
}
