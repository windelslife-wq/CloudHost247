<?php
/**
 * Admin transaction listing for Blockonomics orders (spec §25/§26).
 *
 * Read-only over the EXISTING blockonomics_orders table + WHMCS invoice /
 * client tables. No writes ever happen here. Status is normalized through
 * the shared Policy layer.
 *
 * @package Chs\Admin
 */

namespace Chs\Admin;

use CloudHost247\Blockonomics\Policy;
use Chs\Core\Db;

class BlockonomicsTransactions
{
    /** Page size for the admin listing. */
    const PAGE_SIZE = 50;

    /**
     * @param array $filters search|currency('btc','usdt','bch')|network|status|from|to|page
     * @return array{rows:array,total:int,page:int,pages:int}
     */
    public function query(array $filters)
    {
        $where = [];
        $bind = [];

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            // transaction id, invoice id, customer email, customer name
            $where[] = '(o.txid LIKE ? OR CAST(o.id_order AS CHAR) = ? OR c.email LIKE ?
                        OR CONCAT(c.firstname, " ", c.lastname) LIKE ?)';
            $like = '%' . $search . '%';
            $bind[] = $like;
            $bind[] = preg_replace('/\D/', '', $search) === '' ? -1 : (int) preg_replace('/\D/', '', $search);
            $bind[] = $like;
            $bind[] = $like;
        }

        $currency = isset($filters['currency']) ? strtolower(trim((string) $filters['currency'])) : '';
        if ($currency !== '') {
            $where[] = 'o.blockonomics_currency = ?';
            $bind[] = $currency;
        }

        $status = isset($filters['status']) ? trim((string) $filters['status']) : '';

        $from = isset($filters['from']) && $filters['from'] !== '' ? strtotime((string) $filters['from'] . ' 00:00:00') : 0;
        if ($from) {
            $where[] = 'o.timestamp >= ?';
            $bind[] = $from;
        }
        $to = isset($filters['to']) && $filters['to'] !== '' ? strtotime((string) $filters['to'] . ' 23:59:59') : 0;
        if ($to) {
            $where[] = 'o.timestamp <= ?';
            $bind[] = $to;
        }

        $page = max(1, (int) (isset($filters['page']) ? $filters['page'] : 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $baseSql = 'FROM blockonomics_orders o
            LEFT JOIN tblinvoices i ON i.id = o.id_order
            LEFT JOIN tblclients c ON c.id = i.userid
            ' . $whereSql;

        $rows = Db::query(
            'SELECT o.id_order, o.txid, o.timestamp, o.addr, o.status, o.value, o.bits, o.bits_payed,
                    o.blockonomics_currency, o.basecurrencyamount,
                    i.status AS invoice_status, c.id AS client_id,
                    c.firstname, c.lastname, c.email
             ' . $baseSql . ' ORDER BY o.timestamp DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
            $bind
        );
        $total = (int) (Db::query('SELECT COUNT(*) AS c ' . $baseSql, $bind)[0]['c']);

        $confirmations = isset($filters['confirmations']) ? (int) $filters['confirmations'] : 2;
        $timePeriod = isset($filters['time_period_min']) ? (int) $filters['time_period_min'] : 10;

        $out = [];
        foreach ($rows as $row) {
            $expired = Policy::isExpired((int) $row['timestamp'], $timePeriod);
            $normalized = Policy::mapStatus((int) $row['status'], $confirmations, $expired, (float) $row['bits_payed']);

            if ($status !== '' && $normalized !== $status) {
                continue; // normalized-status filtering happens post-normalize
            }

            $out[] = [
                'order_id'     => (int) $row['id_order'],
                'txid'         => (string) $row['txid'],
                'timestamp'    => (int) $row['timestamp'],
                'address_masked' => $this->maskAddress((string) $row['addr']),
                'status'       => $normalized,
                'status_raw'   => (int) $row['status'],
                'value'        => (float) $row['value'],
                'bits'         => (float) $row['bits'],
                'bits_payed'   => (float) $row['bits_payed'],
                'currency'     => (string) $row['blockonomics_currency'],
                'base_amount'  => (float) $row['basecurrencyamount'],
                'invoice_status' => (string) $row['invoice_status'],
                'client_id'    => (int) $row['client_id'],
                'client_name'  => trim($row['firstname'] . ' ' . $row['lastname']),
                'client_email' => (string) $row['email'],
            ];
        }

        // Network filter applies to USDT rows only and is display-driven.
        $network = isset($filters['network']) ? trim((string) $filters['network']) : '';
        if ($network !== '') {
            $out = array_values(array_filter($out, function ($row) use ($network) {
                return $row['currency'] === 'usdt';
            }));
        }

        return [
            'rows'  => $out,
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
        ];
    }

    /** Never print full wallet addresses in admin lists. */
    private function maskAddress($addr)
    {
        $addr = (string) $addr;
        $len = strlen($addr);
        if ($len <= 12) {
            return $addr === '' ? '' : substr($addr, 0, 3) . '…' . substr($addr, -3);
        }
        // USDT rows carry "addr-invoiceid" — mask only the address half.
        if (strpos($addr, '-') !== false) {
            list($a, $inv) = explode('-', $addr, 2);
            return substr($a, 0, 6) . '…' . substr($a, -4) . '-' . $inv;
        }
        return substr($addr, 0, 6) . '…' . substr($addr, -4);
    }
}
