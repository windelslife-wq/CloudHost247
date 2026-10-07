<?php
/**
 * Executive board department packs.
 *
 * Each board seat is a deterministic SQL pack over live WHMCS tables — never
 * a model, never an "agent opinion". A seat returns either:
 *
 *   status = 'ok'                 metrics + findings, every figure carrying
 *                                 the exact SQL that produced it, or
 *   status = 'data_unavailable'   the named collector that is missing.
 *
 * A seat that cannot read its data says so. It does not estimate, infer from
 * a neighbouring table, or let the narrator fill the gap — that is the whole
 * point of the board being deterministic.
 *
 * @package Ch247Ai\Board
 */

namespace Ch247Ai\Board;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;

class DepartmentPacks
{
    /** Severity ordering for roll-ups. */
    const SEV_INFO = 'info';
    const SEV_WARN = 'warn';
    const SEV_CRITICAL = 'critical';

    /** Seats in board presentation order. */
    const SEATS = ['cfo', 'coo', 'cro', 'cco', 'crisk', 'cto', 'ciso', 'cmo', 'cpo'];

    /**
     * Every seat, in board order.
     *
     * @return array<int,array> seat packs
     */
    public static function all()
    {
        $out = [];
        foreach (self::SEATS as $seat) {
            $out[] = self::seat($seat);
        }
        return $out;
    }

    /** One seat by key. */
    public static function seat($seat)
    {
        switch ($seat) {
            case 'cfo':   return self::finance();
            case 'coo':   return self::operations();
            case 'cro':   return self::growth();
            case 'cco':   return self::customer();
            case 'crisk': return self::risk();
            case 'cto':   return self::technology();
            case 'ciso':  return self::security();
            case 'cmo':   return self::marketing();
            case 'cpo':   return self::product();
        }
        return self::unavailable($seat, 'Unknown seat', 'This seat is not defined.');
    }

    /* ----------------------------------------------------------- seats -- */

    /** CFO — money that actually moved, and money that is owed. */
    protected static function finance()
    {
        $need = ['tblinvoices', 'tblaccounts'];
        if (($missing = self::missingTables($need)) !== '') {
            return self::unavailable('cfo', 'Finance (CFO)', $missing);
        }
        $today = Clock::today();
        $weekAgo = self::daysAgo(7);
        $monthAgo = self::daysAgo(30);

        $metrics = [
            self::metric('paid_today_amount', 'SELECT COALESCE(SUM(amount_in - amount_out),0) AS v FROM tblaccounts WHERE date >= ?', [$today]),
            self::metric('paid_7d_amount', 'SELECT COALESCE(SUM(amount_in - amount_out),0) AS v FROM tblaccounts WHERE date >= ?', [$weekAgo]),
            self::metric('paid_30d_amount', 'SELECT COALESCE(SUM(amount_in - amount_out),0) AS v FROM tblaccounts WHERE date >= ?', [$monthAgo]),
            self::metric('refunds_30d_amount', 'SELECT COALESCE(SUM(amount_out),0) AS v FROM tblaccounts WHERE date >= ?', [$monthAgo]),
            self::metric('invoices_unpaid_total', 'SELECT COUNT(*) AS v FROM tblinvoices WHERE status = ?', ['Unpaid']),
            self::metric('invoices_unpaid_amount', 'SELECT COALESCE(SUM(total - credit),0) AS v FROM tblinvoices WHERE status = ?', ['Unpaid']),
            self::metric('invoices_overdue_total', 'SELECT COUNT(*) AS v FROM tblinvoices WHERE status = ? AND duedate < ?', ['Unpaid', $today]),
            self::metric('invoices_overdue_amount', 'SELECT COALESCE(SUM(total - credit),0) AS v FROM tblinvoices WHERE status = ? AND duedate < ?', ['Unpaid', $today]),
            self::metric('invoices_cancelled_30d', 'SELECT COUNT(*) AS v FROM tblinvoices WHERE status = ? AND date >= ?', ['Cancelled', $monthAgo]),
        ];
        $by = self::byName($metrics);

        $findings = [];
        if ($by['invoices_overdue_amount'] > 0) {
            $findings[] = self::finding(
                $by['invoices_overdue_amount'] >= max(1.0, $by['paid_30d_amount']) ? self::SEV_CRITICAL : self::SEV_WARN,
                'Overdue receivables stand at ' . self::money($by['invoices_overdue_amount'])
                . ' across ' . (int) $by['invoices_overdue_total'] . ' invoices.',
                'invoices_overdue_amount'
            );
        }
        if ($by['refunds_30d_amount'] > 0 && $by['paid_30d_amount'] > 0
            && $by['refunds_30d_amount'] / max($by['paid_30d_amount'], 0.01) > 0.1) {
            $findings[] = self::finding(self::SEV_WARN,
                'Refunds in the last 30 days are ' . self::money($by['refunds_30d_amount'])
                . ', more than 10% of gross receipts.', 'refunds_30d_amount');
        }
        if ($findings === []) {
            $findings[] = self::finding(self::SEV_INFO, 'No finance thresholds breached.', 'invoices_overdue_amount');
        }
        return self::ok('cfo', 'Finance (CFO)', $metrics, $findings);
    }

    /** COO — the operational queue: tickets, services, renewals. */
    protected static function operations()
    {
        $need = ['tbltickets', 'tblhosting'];
        if (($missing = self::missingTables($need)) !== '') {
            return self::unavailable('coo', 'Operations (COO)', $missing);
        }
        $today = Clock::today();
        $weekAhead = self::daysAhead(7);
        $monthAhead = self::daysAhead(30);

        $metrics = [
            self::metric('tickets_open_total', 'SELECT COUNT(*) AS v FROM tbltickets WHERE status != ?', ['Closed']),
            self::metric('tickets_awaiting_staff', 'SELECT COUNT(*) AS v FROM tbltickets WHERE status = ?', ['Customer-Reply']),
            self::metric('tickets_opened_today', 'SELECT COUNT(*) AS v FROM tbltickets WHERE date >= ?', [$today]),
            self::metric('services_active_total', 'SELECT COUNT(*) AS v FROM tblhosting WHERE domainstatus = ?', ['Active']),
            self::metric('services_suspended_total', 'SELECT COUNT(*) AS v FROM tblhosting WHERE domainstatus = ?', ['Suspended']),
            self::metric('services_pending_total', 'SELECT COUNT(*) AS v FROM tblhosting WHERE domainstatus = ?', ['Pending']),
            self::metric('services_due_7d', 'SELECT COUNT(*) AS v FROM tblhosting WHERE nextduedate != ? AND nextduedate <= ? AND nextduedate >= ?', ['0000-00-00', $weekAhead, $today]),
        ];
        if (Db::whmcsTableExists('tbldomains')) {
            $metrics[] = self::metric('domains_expiring_30d', 'SELECT COUNT(*) AS v FROM tbldomains WHERE expirydate != ? AND expirydate <= ? AND expirydate >= ?', ['0000-00-00', $monthAhead, $today]);
        }
        $by = self::byName($metrics);

        $findings = [];
        if ($by['services_pending_total'] > 0) {
            $findings[] = self::finding(self::SEV_WARN,
                (int) $by['services_pending_total'] . ' service(s) are still Pending — provisioning may be stuck.',
                'services_pending_total');
        }
        if ($by['tickets_awaiting_staff'] > 0) {
            $findings[] = self::finding(self::SEV_WARN,
                (int) $by['tickets_awaiting_staff'] . ' ticket(s) are awaiting a staff reply.',
                'tickets_awaiting_staff');
        }
        if (isset($by['domains_expiring_30d']) && $by['domains_expiring_30d'] > 0) {
            $findings[] = self::finding(self::SEV_INFO,
                (int) $by['domains_expiring_30d'] . ' domain(s) expire within 30 days.', 'domains_expiring_30d');
        }
        if ($findings === []) {
            $findings[] = self::finding(self::SEV_INFO, 'Operational queues are clear.', 'tickets_open_total');
        }
        return self::ok('coo', 'Operations (COO)', $metrics, $findings);
    }

    /** CRO — acquisition and order flow. */
    protected static function growth()
    {
        $need = ['tblclients', 'tblorders'];
        if (($missing = self::missingTables($need)) !== '') {
            return self::unavailable('cro', 'Revenue & Growth (CRO)', $missing);
        }
        $today = Clock::today();
        $weekAgo = self::daysAgo(7);
        $monthAgo = self::daysAgo(30);

        $metrics = [
            self::metric('new_clients_today', 'SELECT COUNT(*) AS v FROM tblclients WHERE datecreated >= ?', [$today]),
            self::metric('new_clients_7d', 'SELECT COUNT(*) AS v FROM tblclients WHERE datecreated >= ?', [$weekAgo]),
            self::metric('new_clients_30d', 'SELECT COUNT(*) AS v FROM tblclients WHERE datecreated >= ?', [$monthAgo]),
            self::metric('clients_active_total', 'SELECT COUNT(*) AS v FROM tblclients WHERE status = ?', ['Active']),
            self::metric('orders_today', 'SELECT COUNT(*) AS v FROM tblorders WHERE date >= ?', [$today]),
            self::metric('orders_30d', 'SELECT COUNT(*) AS v FROM tblorders WHERE date >= ?', [$monthAgo]),
            self::metric('orders_pending_total', 'SELECT COUNT(*) AS v FROM tblorders WHERE status = ?', ['Pending']),
            self::metric('orders_fraud_30d', 'SELECT COUNT(*) AS v FROM tblorders WHERE status = ? AND date >= ?', ['Fraud', $monthAgo]),
        ];
        $by = self::byName($metrics);

        $findings = [];
        if ($by['orders_pending_total'] > 0) {
            $findings[] = self::finding(self::SEV_WARN,
                (int) $by['orders_pending_total'] . ' order(s) are Pending and not yet converted.', 'orders_pending_total');
        }
        if ($by['new_clients_30d'] == 0) {
            $findings[] = self::finding(self::SEV_WARN, 'No new clients registered in the last 30 days.', 'new_clients_30d');
        } else {
            $findings[] = self::finding(self::SEV_INFO,
                (int) $by['new_clients_30d'] . ' new client(s) in the last 30 days.', 'new_clients_30d');
        }
        return self::ok('cro', 'Revenue & Growth (CRO)', $metrics, $findings);
    }

    /** Chief Customer Officer — support experience signals. */
    protected static function customer()
    {
        if (($missing = self::missingTables(['tbltickets'])) !== '') {
            return self::unavailable('cco', 'Customer (CCO)', $missing);
        }
        $weekAgo = self::daysAgo(7);
        $monthAgo = self::daysAgo(30);

        $metrics = [
            self::metric('tickets_opened_7d', 'SELECT COUNT(*) AS v FROM tbltickets WHERE date >= ?', [$weekAgo]),
            self::metric('tickets_opened_30d', 'SELECT COUNT(*) AS v FROM tbltickets WHERE date >= ?', [$monthAgo]),
            self::metric('tickets_closed_30d', 'SELECT COUNT(*) AS v FROM tbltickets WHERE status = ? AND date >= ?', ['Closed', $monthAgo]),
            self::metric('tickets_open_total', 'SELECT COUNT(*) AS v FROM tbltickets WHERE status != ?', ['Closed']),
            // Repeat contact: distinct clients holding more than one open ticket.
            self::metric('clients_multi_open_tickets',
                'SELECT COUNT(*) AS v FROM (SELECT userid FROM tbltickets WHERE status != ? AND userid > 0 GROUP BY userid HAVING COUNT(*) > 1) AS t',
                ['Closed']),
        ];
        $by = self::byName($metrics);

        $findings = [];
        if ($by['clients_multi_open_tickets'] > 0) {
            $findings[] = self::finding(self::SEV_WARN,
                (int) $by['clients_multi_open_tickets'] . ' customer(s) have more than one open ticket — possible unresolved repeat contact.',
                'clients_multi_open_tickets');
        }
        if ($by['tickets_opened_30d'] > 0) {
            $resolution = $by['tickets_closed_30d'] / max($by['tickets_opened_30d'], 1);
            if ($resolution < 0.5) {
                $findings[] = self::finding(self::SEV_WARN,
                    'Fewer than half of the tickets opened in the last 30 days are closed ('
                    . (int) $by['tickets_closed_30d'] . ' of ' . (int) $by['tickets_opened_30d'] . ').',
                    'tickets_closed_30d');
            }
        }
        if ($findings === []) {
            $findings[] = self::finding(self::SEV_INFO, 'No customer-experience thresholds breached.', 'tickets_open_total');
        }
        return self::ok('cco', 'Customer (CCO)', $metrics, $findings);
    }

    /** Chief Risk & Compliance — exposure concentration and flagged activity. */
    protected static function risk()
    {
        if (($missing = self::missingTables(['tblinvoices'])) !== '') {
            return self::unavailable('crisk', 'Risk & Compliance', $missing);
        }
        $today = Clock::today();
        $metrics = [
            self::metric('clients_overdue_total',
                'SELECT COUNT(*) AS v FROM (SELECT userid FROM tblinvoices WHERE status = ? AND duedate < ? AND userid > 0 GROUP BY userid) AS t',
                ['Unpaid', $today]),
            self::metric('largest_single_overdue',
                'SELECT COALESCE(MAX(total - credit),0) AS v FROM tblinvoices WHERE status = ? AND duedate < ?',
                ['Unpaid', $today]),
            self::metric('invoices_overdue_amount',
                'SELECT COALESCE(SUM(total - credit),0) AS v FROM tblinvoices WHERE status = ? AND duedate < ?',
                ['Unpaid', $today]),
        ];
        if (Db::whmcsTableExists('tblorders')) {
            $metrics[] = self::metric('orders_fraud_total', 'SELECT COUNT(*) AS v FROM tblorders WHERE status = ?', ['Fraud']);
        }
        $by = self::byName($metrics);

        $findings = [];
        $total = $by['invoices_overdue_amount'];
        if ($total > 0 && $by['largest_single_overdue'] / max($total, 0.01) > 0.5) {
            $findings[] = self::finding(self::SEV_WARN,
                'Receivable risk is concentrated: a single invoice is more than half of all overdue value ('
                . self::money($by['largest_single_overdue']) . ' of ' . self::money($total) . ').',
                'largest_single_overdue');
        }
        if (isset($by['orders_fraud_total']) && $by['orders_fraud_total'] > 0) {
            $findings[] = self::finding(self::SEV_WARN,
                (int) $by['orders_fraud_total'] . ' order(s) are flagged Fraud by the billing system.', 'orders_fraud_total');
        }
        if ($findings === []) {
            $findings[] = self::finding(self::SEV_INFO, 'No risk concentration thresholds breached.', 'invoices_overdue_amount');
        }
        return self::ok('crisk', 'Risk & Compliance', $metrics, $findings);
    }

    /* --------------------------------------------- seats without a feed -- */

    protected static function technology()
    {
        return self::unavailable('cto', 'Technology (CTO)',
            'No application or infrastructure telemetry is collected: there is no deployment record, '
            . 'build/release history, uptime feed or performance metric source in this platform. '
            . 'Required first: a server telemetry collector and a deployment event stream.');
    }

    protected static function security()
    {
        return self::unavailable('ciso', 'Security (CISO)',
            'No security event stream exists. tblactivitylog holds raw login records but is unindexed '
            . 'and too noisy for detection. Required first: a derived, indexed auth-event table.');
    }

    /** CMO reads the marketing module when it is installed; otherwise it abstains. */
    protected static function marketing()
    {
        $campaigns = Db::whmcsTableExists('mod_ch247m_campaigns');
        $subscribers = Db::whmcsTableExists('mod_ch247m_subscribers');
        if (!$campaigns || !$subscribers) {
            return self::unavailable('cmo', 'Marketing (CMO)',
                'The CloudHost247 Email Marketing module is not installed or not activated, so no campaign '
                . 'or subscriber data exists to report. Activate cloudhost247marketing to populate this seat.');
        }
        $monthAgo = self::daysAgo(30);
        $metrics = [
            self::metric('campaigns_sent_30d', 'SELECT COUNT(*) AS v FROM mod_ch247m_campaigns WHERE status = ? AND updated_at >= ?', ['sent', $monthAgo]),
            self::metric('campaigns_scheduled', 'SELECT COUNT(*) AS v FROM mod_ch247m_campaigns WHERE status = ?', ['scheduled']),
            self::metric('subscribers_total', 'SELECT COUNT(*) AS v FROM mod_ch247m_subscribers', []),
            self::metric('subscribers_subscribed', 'SELECT COUNT(*) AS v FROM mod_ch247m_subscribers WHERE status = ?', ['subscribed']),
        ];
        $by = self::byName($metrics);
        $findings = [];
        if ($by['subscribers_total'] > 0 && $by['subscribers_subscribed'] / max($by['subscribers_total'], 1) < 0.5) {
            $findings[] = self::finding(self::SEV_WARN,
                'Fewer than half of known subscribers are currently subscribed.', 'subscribers_subscribed');
        }
        if ($findings === []) {
            $findings[] = self::finding(self::SEV_INFO,
                (int) $by['campaigns_sent_30d'] . ' campaign(s) sent in the last 30 days.', 'campaigns_sent_30d');
        }
        return self::ok('cmo', 'Marketing (CMO)', $metrics, $findings);
    }

    protected static function product()
    {
        return self::unavailable('cpo', 'Product (CPO)',
            'No product usage or adoption telemetry is collected. Order and service counts exist, but '
            . 'feature adoption, plan utilisation and in-product behaviour do not. '
            . 'Required first: a usage-metering layer.');
    }

    /* --------------------------------------------------------- helpers -- */

    /** @return string '' when all present, else the DATA_UNAVAILABLE reason */
    protected static function missingTables(array $tables)
    {
        $missing = [];
        foreach ($tables as $t) {
            if (!Db::whmcsTableExists($t)) {
                $missing[] = $t;
            }
        }
        if ($missing === []) {
            return '';
        }
        return 'DATA_UNAVAILABLE: required WHMCS table(s) not present in this installation: ' . implode(', ', $missing) . '.';
    }

    protected static function metric($label, $sql, array $bind)
    {
        $rows = Db::query($sql, $bind);
        $value = $rows && isset($rows[0]['v']) ? (float) $rows[0]['v'] : 0.0;
        return ['metric' => $label, 'value' => $value, 'sql' => $sql, 'params' => $bind];
    }

    protected static function byName(array $metrics)
    {
        $out = [];
        foreach ($metrics as $m) {
            $out[$m['metric']] = $m['value'];
        }
        return $out;
    }

    protected static function finding($severity, $text, $evidenceMetric)
    {
        return ['severity' => $severity, 'text' => $text, 'evidence' => $evidenceMetric];
    }

    protected static function ok($seat, $title, array $metrics, array $findings)
    {
        return [
            'seat' => $seat,
            'title' => $title,
            'status' => 'ok',
            'reason' => '',
            'metrics' => $metrics,
            'findings' => $findings,
        ];
    }

    protected static function unavailable($seat, $title, $reason)
    {
        return [
            'seat' => $seat,
            'title' => $title,
            'status' => 'data_unavailable',
            'reason' => $reason,
            'metrics' => [],
            'findings' => [],
        ];
    }

    protected static function money($v)
    {
        return number_format((float) $v, 2, '.', '');
    }

    protected static function daysAgo($n)
    {
        return gmdate('Y-m-d', Clock::time() - ((int) $n * 86400));
    }

    protected static function daysAhead($n)
    {
        return gmdate('Y-m-d', Clock::time() + ((int) $n * 86400));
    }
}
