<?php
/**
 * Live WHMCS adapter (production GatewayInterface implementation).
 *
 * Reads go through Capsule queries against WHMCS-owned tables (read-only —
 * never mutated); writes go through localAPI where WHMCS defines the workflow
 * (invoices, email) and through guarded UPDATEs where WHMCS offers no API
 * (ticket flag/status used by the unified inbox).
 *
 * Convention mirrors the domainbroker module already running on this host.
 *
 * @package Chs\Providers\Whmcs
 */

namespace Chs\Providers\Whmcs;

use Chs\Core\Db;
use Chs\Core\Money;
use Chs\Core\Settings;

class WhmcsGateway implements GatewayInterface
{
    /* ------------------------------------------------------------ clients -- */

    public function clientExists($clientId)
    {
        $row = Db::query('SELECT id FROM tblclients WHERE id = ?', [(int) $clientId]);
        return $row !== [];
    }

    public function clientCurrency($clientId)
    {
        $row = Db::query(
            'SELECT cur.code FROM tblclients cl JOIN tblcurrencies cur ON cur.id = cl.currency WHERE cl.id = ?',
            [(int) $clientId]
        );
        return isset($row[0]['code']) ? strtoupper((string) $row[0]['code']) : $this->defaultCurrency();
    }

    public function defaultCurrency()
    {
        $setting = strtoupper(trim(Settings::string('default_currency', '')));
        if ($setting !== '') {
            return $setting;
        }
        $row = Db::query('SELECT code FROM tblcurrencies WHERE `default` = 1 LIMIT 1');
        return isset($row[0]['code']) ? strtoupper((string) $row[0]['code']) : 'USD';
    }

    public function clientSummary($clientId)
    {
        $row = Db::query(
            'SELECT cl.id, cl.firstname, cl.lastname, cl.email, cur.code AS currency
             FROM tblclients cl LEFT JOIN tblcurrencies cur ON cur.id = cl.currency WHERE cl.id = ?',
            [(int) $clientId]
        );
        if (!$row) {
            return null;
        }
        $r = $row[0];
        return [
            'id'       => (int) $r['id'],
            'name'     => trim((string) $r['firstname'] . ' ' . (string) $r['lastname']),
            'email'    => (string) $r['email'],
            'currency' => $r['currency'] !== null ? strtoupper((string) $r['currency']) : $this->defaultCurrency(),
        ];
    }

    /* ------------------------------------------------------------ billing -- */

    public function createInvoice($clientId, array $items, $currency, $dueDays, $notes = '')
    {
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('localAPI() is unavailable outside the WHMCS runtime.');
        }
        $payload = [
            'userid'      => (int) $clientId,
            'status'      => 'Unpaid',
            'duedate'     => date('Y-m-d', time() + max(1, (int) $dueDays) * 86400),
            'sendinvoice' => true,
        ];
        if ($notes !== '') {
            $payload['notes'] = $notes;
        }
        $i = 1;
        foreach ($items as $item) {
            $payload['itemdescription' . $i] = (string) $item['description'];
            $payload['itemamount' . $i]      = Money::toDecimal((int) $item['amount_minor'], $currency);
            $payload['itemtaxed' . $i]       = !empty($item['taxed']) ? '1' : '0';
            $i++;
        }
        $res = localAPI('CreateInvoice', $payload, (int) \Chs\Core\Identity::adminId() ?: null);
        if (!isset($res['result']) || $res['result'] !== 'success' || !isset($res['invoiceid'])) {
            throw new \RuntimeException('CreateInvoice returned an error: ' . substr(chs_json($res), 0, 200));
        }
        return (int) $res['invoiceid'];
    }

    public function cancelInvoice($invoiceId)
    {
        Db::exec("UPDATE tblinvoices SET status = 'Cancelled' WHERE id = ?", [(int) $invoiceId]);
    }

    public function invoiceStatus($invoiceId)
    {
        $row = Db::query('SELECT status FROM tblinvoices WHERE id = ?', [(int) $invoiceId]);
        return isset($row[0]['status']) ? (string) $row[0]['status'] : null;
    }

    public function invoiceUrl($invoiceId)
    {
        return rtrim($this->systemUrl(), '/') . '/viewinvoice.php?id=' . (int) $invoiceId;
    }

    /* ------------------------------------------------------------- email -- */

    public function sendEmail($clientId, $subject, $plainBody)
    {
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('localAPI() is unavailable outside the WHMCS runtime.');
        }
        $res = localAPI('SendEmail', [
            'id'            => (int) $clientId,
            'customtype'    => 'general',
            'customsubject' => (string) $subject,
            'custommessage' => nl2br(chs_h((string) $plainBody)),
        ]);
        if (isset($res['result']) && $res['result'] === 'error') {
            throw new \RuntimeException(isset($res['message']) ? (string) $res['message'] : 'SendEmail failed.');
        }
    }

    /* ------------------------------------------------------------ domains -- */

    public function domainAvailability($domain)
    {
        if (!function_exists('localAPI')) {
            return ['status' => 'unavailable_lookup', 'available' => false];
        }
        $res = localAPI('DomainWhois', ['domain' => (string) $domain]);
        if (isset($res['result']) && $res['result'] === 'success' && isset($res['status'])) {
            $status = strtolower((string) $res['status']);
            return [
                'status'    => $status,
                'available' => $status === 'available',
            ];
        }
        return ['status' => 'unknown', 'available' => false];
    }

    public function clientDomains($clientId)
    {
        $rows = Db::query(
            "SELECT domain, expirydate, status FROM tbldomains WHERE userid = ? ORDER BY domain",
            [(int) $clientId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'domain' => (string) $row['domain'],
                'expiry' => (string) $row['expirydate'],
                'status' => (string) $row['status'],
            ];
        }
        return $out;
    }

    /* --------------------------------------------------------- ticketing -- */

    public function inboxConversations($clientId, $limit = 100)
    {
        return Db::query(
            'SELECT t.id, t.tid, t.subject, t.status, t.urgency, t.date, t.lastreply,
                    (SELECT COUNT(*) FROM tblticketreplies r WHERE r.tid = t.id) AS replies
             FROM tbltickets t
             WHERE t.userid = ? AND t.merged_ticket_id = 0
             ORDER BY t.lastreply DESC, t.id DESC
             LIMIT ' . (int) $limit,
            [(int) $clientId]
        );
    }

    public function inboxMessages($ticketId)
    {
        $ticket = Db::query('SELECT id, userid, name, message, date FROM tbltickets WHERE id = ?', [(int) $ticketId]);
        if (!$ticket) {
            return [];
        }
        $out = [[
            'id'         => (int) $ticket[0]['id'],
            'author'     => (string) $ticket[0]['name'],
            'authorType' => 'client',
            'body'       => (string) $ticket[0]['message'],
            'date'       => (string) $ticket[0]['date'],
        ]];
        foreach (Db::query('SELECT id, admin, name, message, date FROM tblticketreplies WHERE tid = ? ORDER BY id', [(int) $ticketId]) as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'author'     => $row['admin'] !== '' ? (string) $row['admin'] : (string) $row['name'],
                'authorType' => $row['admin'] !== '' ? 'staff' : 'client',
                'body'       => (string) $row['message'],
                'date'       => (string) $row['date'],
            ];
        }
        return $out;
    }

    public function inboxReply($ticketId, $body, $authorType, $authorId)
    {
        if ($authorType !== 'client') {
            throw new \InvalidArgumentException('Client replies only from the client inbox.');
        }
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('localAPI() is unavailable outside the WHMCS runtime.');
        }
        $res = localAPI('AddTicketReply', [
            'ticketid' => (int) $ticketId,
            'clientid' => (int) $authorId,
            'message'  => (string) $body,
        ]);
        if (!isset($res['result']) || $res['result'] !== 'success') {
            throw new \RuntimeException('AddTicketReply returned an error: ' . substr(chs_json($res), 0, 200));
        }
        // WHMCS does not return the reply id — read it back.
        $row = Db::query('SELECT MAX(id) AS m FROM tblticketreplies WHERE tid = ?', [(int) $ticketId]);
        return $row && $row[0]['m'] !== null ? (int) $row[0]['m'] : 0;
    }

    public function inboxAdminList($limit = 200, $onlyUnassigned = false)
    {
        $sql = 'SELECT id, tid, subject, status, urgency, name, email, date, lastreply, flag, admin
                FROM tbltickets WHERE merged_ticket_id = 0'
            . ($onlyUnassigned ? ' AND (flag = 0 OR flag IS NULL)' : '')
            . ' ORDER BY lastreply DESC, id DESC LIMIT ' . (int) $limit;
        return Db::query($sql);
    }

    public function inboxAssign($ticketId, $adminId)
    {
        $name = '';
        $row = Db::query('SELECT username FROM tbladmins WHERE id = ?', [(int) $adminId]);
        if ($row) {
            $name = (string) $row[0]['username'];
        }
        Db::exec('UPDATE tbltickets SET flag = ?, admin = ? WHERE id = ?', [(int) $adminId, $name, (int) $ticketId]);
    }

    public function inboxSetStatus($ticketId, $status)
    {
        Db::exec('UPDATE tbltickets SET status = ? WHERE id = ?', [(string) $status, (int) $ticketId]);
    }

    /* ------------------------------------------- servers, products, orders -- */

    public function serverProducts()
    {
        $rows = Db::query(
            "SELECT p.id, p.name, p.description, p.paytype, p.servertype, p.gid,
                    g.name AS group_name
             FROM tblproducts p
             LEFT JOIN tblproductgroups g ON g.id = p.gid
             WHERE p.type = 'server' AND (p.hidden IS NULL OR p.hidden = '' OR p.hidden = '0')
             ORDER BY p.name"
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $pricing = $this->productPricing((int) $row['id'], $this->defaultCurrency());
            $amount = null;
            if ($pricing['cycles'] !== []) {
                $cycle = (string) $row['paytype'] === 'onetime' ? 'onetime'
                    : ((string) $row['paytype'] === 'free' ? 'free' : 'monthly');
                $amount = isset($pricing['cycles'][$cycle]) ? $pricing['cycles'][$cycle] : reset($pricing['cycles']);
            }
            $row['price_minor'] = $amount === null ? null : (int) $amount + (int) $pricing['setup_minor'];
            $row['currency'] = $this->defaultCurrency();
            $out[] = $row;
        }
        return $out;
    }

    public function productDetail($productId)
    {
        $rows = Db::query('SELECT * FROM tblproducts WHERE id = ?', [(int) $productId]);
        return $rows ? $rows[0] : null;
    }

    public function productPricing($productId, $currency)
    {
        $currency = strtoupper((string) $currency);
        $rows = Db::query(
            'SELECT pr.*, c.code AS currency_code
             FROM tblpricing pr JOIN tblcurrencies c ON c.id = pr.currency
             WHERE pr.type = \'product\' AND pr.relid = ?',
            [(int) $productId]
        );
        $cycles = [];
        $setup = 0;
        foreach ($rows ?: [] as $row) {
            if (strtoupper((string) $row['currency_code']) !== $currency) {
                continue;
            }
            foreach (['onetime', 'monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'] as $cycle) {
                $value = isset($row[$cycle]) ? (string) $row[$cycle] : '';
                if ($value === '' || $value === '-1.00') {
                    continue; // -1.00 = disabled cycle in WHMCS
                }
                $minor = Money::fromDecimal($value, $currency);
                if ($minor > 0 || $value === '0.00') {
                    $cycles[$cycle] = $minor;
                }
            }
            $setup = max($setup, Money::fromDecimal((string) $row['msetupfee'], $currency));
        }
        return ['setup_minor' => $setup, 'cycles' => $cycles];
    }

    public function createOrder($clientId, $productId, $billingCycle, $hostname, $paymentMethod = '')
    {
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('localAPI() is unavailable outside the WHMCS runtime.');
        }
        $product = $this->productDetail($productId);
        if (!$product || (string) $product['type'] !== 'server') {
            throw new \InvalidArgumentException('Product is not a server product.');
        }
        $payload = [
            'userid'        => (int) $clientId,
            'pid'           => (int) $productId,
            'paymentmethod' => (string) $paymentMethod,
            'billingcycle'  => (string) $billingCycle,
            'domain'        => (string) $hostname,
        ];
        $res = localAPI('AddOrder', $payload, (int) \Chs\Core\Identity::adminId() ?: null);
        if (!isset($res['result']) || $res['result'] !== 'success' || !isset($res['orderid'])) {
            throw new \RuntimeException('AddOrder returned an error: ' . substr(chs_json($res), 0, 200));
        }
        return [
            'order_id'   => (int) $res['orderid'],
            'invoice_id' => isset($res['invoiceid']) ? (int) $res['invoiceid'] : 0,
        ];
    }

    public function serviceForInvoice($invoiceId)
    {
        $rows = Db::query(
            'SELECT h.* FROM tblhosting h
             JOIN tblorders o ON o.id = h.orderid
             WHERE o.invoiceid = ? ORDER BY h.id ASC LIMIT 1',
            [(int) $invoiceId]
        );
        return $rows ? $rows[0] : null;
    }

    public function hostingDetail($hostingId)
    {
        $rows = Db::query(
            'SELECT h.*, p.name AS product_name, p.type AS product_type, p.paytype
             FROM tblhosting h LEFT JOIN tblproducts p ON p.id = h.packageid
             WHERE h.id = ?',
            [(int) $hostingId]
        );
        return $rows ? $rows[0] : null;
    }

    public function clientServices($clientId)
    {
        return Db::query(
            'SELECT h.id, h.userid, h.packageid, h.orderid, h.domain, h.server, h.status,
                    h.nextduedate, h.regdate, p.name AS product_name
             FROM tblhosting h LEFT JOIN tblproducts p ON p.id = h.packageid
             WHERE h.userid = ? ORDER BY h.id DESC',
            [(int) $clientId]
        ) ?: [];
    }

    public function updateService($hostingId, array $fields)
    {
        $allowed = ['domain' => true, 'server' => true, 'status' => true, 'nextduedate' => true];
        $set = [];
        $bind = [];
        foreach ($allowed as $column => $ok) {
            if (array_key_exists($column, $fields)) {
                $set[] = '`' . $column . '` = ?';
                $bind[] = $fields[$column];
            }
        }
        if (!$set) {
            return false;
        }
        $bind[] = (int) $hostingId;
        return Db::exec('UPDATE tblhosting SET ' . implode(', ', $set) . ' WHERE id = ?', $bind) > 0;
    }

    public function createServerRecord(array $fields)
    {
        $allowed = [
            'name' => '', 'ipaddress' => '', 'hostname' => '', 'username' => '',
            'password' => '', 'type' => '', 'assignedips' => '', 'ns1' => '', 'ns2' => '',
        ];
        $cols = [];
        $bind = [];
        foreach ($allowed as $column => $default) {
            if (array_key_exists($column, $fields)) {
                $cols[$column] = $fields[$column];
            }
        }
        if (!$cols) {
            throw new \InvalidArgumentException('No server record fields supplied.');
        }
        $columns = implode(', ', array_map(function ($c) {
            return '`' . $c . '`';
        }, array_keys($cols)));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        Db::exec(
            'INSERT INTO tblservers (' . $columns . ') VALUES (' . $placeholders . ')',
            array_values($cols)
        );
        $row = Db::query('SELECT MAX(id) AS m FROM tblservers');
        return $row && $row[0]['m'] !== null ? (int) $row[0]['m'] : 0;
    }

    public function updateServerRecord($serverId, array $fields)
    {
        $allowed = [
            'name' => true, 'ipaddress' => true, 'hostname' => true, 'username' => true,
            'password' => true, 'type' => true, 'assignedips' => true, 'ns1' => true, 'ns2' => true,
        ];
        $set = [];
        $bind = [];
        foreach ($allowed as $column => $ok) {
            if (array_key_exists($column, $fields)) {
                $set[] = '`' . $column . '` = ?';
                $bind[] = $fields[$column];
            }
        }
        if (!$set) {
            return false;
        }
        $bind[] = (int) $serverId;
        return Db::exec('UPDATE tblservers SET ' . implode(', ', $set) . ' WHERE id = ?', $bind) > 0;
    }

    public function serverRecord($serverId)
    {
        $rows = Db::query('SELECT * FROM tblservers WHERE id = ?', [(int) $serverId]);
        return $rows ? $rows[0] : null;
    }

    /* -------------------------------------------------------- internals -- */

    private function systemUrl()
    {
        $setting = trim(Settings::string('system_url', ''));
        if ($setting !== '') {
            return $setting;
        }
        $row = Db::query("SELECT value FROM tblconfiguration WHERE setting = 'SystemURL'");
        return isset($row[0]['value']) ? trim((string) $row[0]['value']) : '';
    }
}
