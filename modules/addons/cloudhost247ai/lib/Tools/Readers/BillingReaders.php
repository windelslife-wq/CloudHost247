<?php
/**
 * READ tools over real WHMCS state. Every tool:
 *   - fails closed when the underlying WHMCS table is absent (staging/overlay),
 *   - returns rows shaped for the model plus SQL citations, never prose claims,
 *   - enforces client isolation in the WHERE clause when ctx scope is client.
 *
 * Phase 1 registers only 'read.*' tools. No write tool exists.
 */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Core\Settings;
use Ch247Ai\Core\Validator;
use Ch247Ai\Tools\ToolDefinition;

/** Force a query to error loudly when the source table is missing. */
function ch247ai_require_tables(array $tables)
{
    foreach ($tables as $table) {
        if (!Db::whmcsTableExists($table)) {
            throw new ServiceUnavailableException('DATA_UNAVAILABLE: table ' . $table . ' does not exist in this WHMCS installation; refusing to answer from memory.');
        }
    }
}

/** Client isolation guard — returns the forced client id or 0 for admin scope. */
function ch247ai_scope_client(array $ctx)
{
    if (isset($ctx['scope']) && $ctx['scope'] === 'client') {
        return (int) $ctx['client_id'];
    }
    return 0;
}

function ch247ai_clamp_limit($value)
{
    return Validator::clampInt($value, 1, 50, 10);
}

function ch247ai_cite($sql, array $bind)
{
    return [$sql . ' | params=' . json_encode($bind, JSON_UNESCAPED_SLASHES)];
}

function ch247ai_public_client(array $row)
{
    // Admins may see contact fields; client scope only ever sees own rows.
    $keep = ['id', 'firstname', 'lastname', 'companyname', 'email', 'phonenumber', 'country', 'status', 'datecreated', 'clienttype'];
    $out = [];
    foreach ($keep as $key) {
        if (array_key_exists($key, $row)) {
            $out[$key] = $row[$key];
        }
    }
    return $out;
}

function ch247ai_clients_tool()
{
    return new ToolDefinition(
        'read_clients',
        'read.clients',
        'READ',
        'Search WHMCS clients (tblclients). Returns id, name, email, status. Use when asked about customers.',
        [
            'query' => ['type' => 'string', 'description' => 'Search term against email, first/last name, or company', 'required' => false],
            'status' => ['type' => 'string', 'description' => 'Filter by status (Active, Inactive, Closed)', 'required' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tblclients']);
            $limit = ch247ai_clamp_limit(isset($args['limit']) ? $args['limit'] : 10);
            $bind = [];
            $where = [];
            $q = trim((string) (isset($args['query']) ? $args['query'] : ''));
            if ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = '(email LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR companyname LIKE ?)';
                array_push($bind, $like, $like, $like, $like);
            }
            if (!empty($args['status'])) {
                $where[] = 'status = ?';
                $bind[] = (string) $args['status'];
            }
            $client = ch247ai_scope_client($ctx);
            if ($client) {
                $where[] = 'id = ?';
                $bind[] = $client;
            }
            $sql = 'SELECT * FROM tblclients' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . $limit;
            $rows = Db::query($sql, $bind);
            $out = [];
            foreach ($rows as $row) {
                $out[] = ch247ai_public_client($row);
            }
            return ['clients' => $out, 'count' => count($out), '_citations' => ch247ai_cite($sql, $bind)];
        },
        ['entity' => 'tblclients']
    );
}

function ch247ai_client_details_tool()
{
    return new ToolDefinition(
        'read_client_details',
        'read.client_details',
        'READ',
        'Full profile of one WHMCS client by id: contact data, status, dates, currency. Includes counts of services and invoices fetched separately.',
        [
            'client_id' => ['type' => 'integer', 'description' => 'WHMCS client id'],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tblclients']);
            $client = ch247ai_scope_client($ctx);
            $id = (int) $args['client_id'];
            if ($client && $client !== $id) {
                throw new NotFoundException('Client not found.'); // never confirm others exist
            }
            $rows = Db::query('SELECT * FROM tblclients WHERE id = ?', [$id]);
            if (!$rows) {
                throw new NotFoundException('Client ' . $id . ' does not exist in tblclients.');
            }
            return ['client' => ch247ai_public_client($rows[0]), '_citations' => ch247ai_cite('SELECT * FROM tblclients WHERE id = ?', [$id])];
        },
        ['entity' => 'tblclients'],
        true // client-bound: reader forces id = session client
    );
}

function ch247ai_invoices_tool()
{
    return new ToolDefinition(
        'read_invoices',
        'read.billing',
        'READ',
        'List WHMCS invoices (tblinvoices) with status, totals, dates. Filter by client and/or status.',
        [
            'client_id' => ['type' => 'integer', 'description' => 'Limit to one client', 'required' => false],
            'status' => ['type' => 'string', 'description' => 'Unpaid, Paid, Cancelled, Refunded, Collections', 'required' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tblinvoices']);
            $limit = ch247ai_clamp_limit(isset($args['limit']) ? $args['limit'] : 10);
            $where = [];
            $bind = [];
            $client = ch247ai_scope_client($ctx);
            if ($client) {
                $where[] = 'userid = ?';
                $bind[] = $client;
            } elseif (!empty($args['client_id'])) {
                $where[] = 'userid = ?';
                $bind[] = (int) $args['client_id'];
            }
            if (!empty($args['status'])) {
                $where[] = 'status = ?';
                $bind[] = (string) $args['status'];
            }
            $sql = 'SELECT id, userid, status, total, credit, date, duedate, datepaid, paymentmethod FROM tblinvoices'
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . $limit;
            $rows = Db::query($sql, $bind);
            return ['invoices' => $rows, 'count' => count($rows), '_citations' => ch247ai_cite($sql, $bind)];
        },
        ['entity' => 'tblinvoices'],
        true // client-bound
    );
}

function ch247ai_payments_tool()
{
    return new ToolDefinition(
        'read_payments',
        'read.billing',
        'READ',
        'Recent payments (tblaccounts): date, amount, fees, gateway, invoice id.',
        [
            'client_id' => ['type' => 'integer', 'description' => 'Limit to one client', 'required' => false],
            'days' => ['type' => 'integer', 'description' => 'Look-back window in days (1-365)', 'required' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tblaccounts']);
            $limit = ch247ai_clamp_limit(isset($args['limit']) ? $args['limit'] : 10);
            $days = Validator::clampInt(isset($args['days']) ? $args['days'] : 30, 1, 365, 30);
            $where = ['date >= ?'];
            $bind = [date('Y-m-d', \Ch247Ai\Core\Clock::time() - $days * 86400)];
            $client = ch247ai_scope_client($ctx);
            if ($client) {
                $where[] = 'userid = ?';
                $bind[] = $client;
            } elseif (!empty($args['client_id'])) {
                $where[] = 'userid = ?';
                $bind[] = (int) $args['client_id'];
            }
            $sql = 'SELECT id, userid, date, amount_in AS amount_in, amount_out AS amount_out, fees, gateway, invoiceid, transid FROM tblaccounts WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $limit;
            $rows = Db::query($sql, $bind);
            return ['payments' => $rows, 'count' => count($rows), '_citations' => ch247ai_cite($sql, $bind)];
        },
        ['entity' => 'tblaccounts'],
        true // client-bound
    );
}

function ch247ai_orders_tool()
{
    return new ToolDefinition(
        'read_orders',
        'read.orders',
        'READ',
        'WHMCS orders (tblorders): ordernum, client, status, amount, dates, invoice link.',
        [
            'client_id' => ['type' => 'integer', 'description' => 'Limit to one client', 'required' => false],
            'status' => ['type' => 'string', 'description' => 'Pending, Active, Completed, Cancelled, Fraud', 'required' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tblorders']);
            $limit = ch247ai_clamp_limit(isset($args['limit']) ? $args['limit'] : 10);
            $where = [];
            $bind = [];
            $client = ch247ai_scope_client($ctx);
            if ($client) {
                $where[] = 'userid = ?';
                $bind[] = $client;
            } elseif (!empty($args['client_id'])) {
                $where[] = 'userid = ?';
                $bind[] = (int) $args['client_id'];
            }
            if (!empty($args['status'])) {
                $where[] = 'status = ?';
                $bind[] = (string) $args['status'];
            }
            $sql = 'SELECT id, ordernum, userid, status, amount, date, invoiceid FROM tblorders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . $limit;
            $rows = Db::query($sql, $bind);
            return ['orders' => $rows, 'count' => count($rows), '_citations' => ch247ai_cite($sql, $bind)];
        },
        ['entity' => 'tblorders'],
        true // client-bound
    );
}

function ch247ai_services_tool()
{
    return new ToolDefinition(
        'read_services',
        'read.services',
        'READ',
        'Client services (tblhosting): product, domain, status, renewal, next due, server. "Server data" here is the WHMCS product assignment, NOT live server telemetry (none is collected).',
        [
            'client_id' => ['type' => 'integer', 'description' => 'Limit to one client', 'required' => false],
            'status' => ['type' => 'string', 'description' => 'Active, Suspended, Terminated, Cancelled, Fraud', 'required' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tblhosting']);
            $limit = ch247ai_clamp_limit(isset($args['limit']) ? $args['limit'] : 10);
            $where = [];
            $bind = [];
            $client = ch247ai_scope_client($ctx);
            if ($client) {
                $where[] = 'tblhosting.userid = ?';
                $bind[] = $client;
            } elseif (!empty($args['client_id'])) {
                $where[] = 'tblhosting.userid = ?';
                $bind[] = (int) $args['client_id'];
            }
            if (!empty($args['status'])) {
                $where[] = 'tblhosting.domainstatus = ?';
                $bind[] = (string) $args['status'];
            }
            $sql = 'SELECT tblhosting.id, tblhosting.userid, tblhosting.packageid, tblproducts.name AS product, tblhosting.domain, tblhosting.domainstatus AS status, tblhosting.regdate, tblhosting.nextduedate, tblhosting.billingcycle, tblhosting.amount, tblhosting.server FROM tblhosting LEFT JOIN tblproducts ON tblproducts.id = tblhosting.packageid'
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY tblhosting.id DESC LIMIT ' . $limit;
            $rows = Db::query($sql, $bind);
            return [
                'services' => $rows,
                'count' => count($rows),
                'note' => 'Server column reflects WHMCS assignment only; no live server telemetry exists in this platform.',
                '_citations' => ch247ai_cite($sql, $bind),
            ];
        },
        ['entity' => 'tblhosting'],
        true // client-bound
    );
}

function ch247ai_tickets_tool()
{
    return new ToolDefinition(
        'read_tickets',
        'read.support',
        'READ',
        'Support tickets (tbltickets): subject, status, department, priority, last reply. Use reply counts to gauge activity.',
        [
            'client_id' => ['type' => 'integer', 'description' => 'Limit to one client', 'required' => false],
            'status' => ['type' => 'string', 'description' => 'Open, Answered, Customer-Reply, Closed', 'required' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tbltickets']);
            $limit = ch247ai_clamp_limit(isset($args['limit']) ? $args['limit'] : 10);
            $where = [];
            $bind = [];
            $client = ch247ai_scope_client($ctx);
            if ($client) {
                $where[] = 'userid = ?';
                $bind[] = $client;
            } elseif (!empty($args['client_id'])) {
                $where[] = 'userid = ?';
                $bind[] = (int) $args['client_id'];
            }
            if (!empty($args['status'])) {
                $where[] = 'status = ?';
                $bind[] = (string) $args['status'];
            }
            $sql = 'SELECT id, tid, userid, did AS department, title, status, urgency, lastreply, date FROM tbltickets' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY lastreply DESC LIMIT ' . $limit;
            $rows = Db::query($sql, $bind);
            return ['tickets' => $rows, 'count' => count($rows), '_citations' => ch247ai_cite($sql, $bind)];
        },
        ['entity' => 'tbltickets'],
        true // client-bound
    );
}

function ch247ai_domains_tool()
{
    return new ToolDefinition(
        'read_domains',
        'read.domains',
        'READ',
        'Registered domains (tbldomains): domain, registrar, status, expiry, next due, auto-renew flag.',
        [
            'client_id' => ['type' => 'integer', 'description' => 'Limit to one client', 'required' => false],
            'domain' => ['type' => 'string', 'description' => 'Exact domain name', 'required' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tbldomains']);
            $limit = ch247ai_clamp_limit(isset($args['limit']) ? $args['limit'] : 10);
            $where = [];
            $bind = [];
            $client = ch247ai_scope_client($ctx);
            if ($client) {
                $where[] = 'userid = ?';
                $bind[] = $client;
            } elseif (!empty($args['client_id'])) {
                $where[] = 'userid = ?';
                $bind[] = (int) $args['client_id'];
            }
            if (!empty($args['domain'])) {
                $where[] = 'domain = ?';
                $bind[] = strtolower(trim((string) $args['domain']));
            }
            $sql = 'SELECT id, userid, domain, registrar, status, expirydate, nextduedate, donotrenew, ispremium FROM tbldomains' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY expirydate ASC LIMIT ' . $limit;
            $rows = Db::query($sql, $bind);
            return ['domains' => $rows, 'count' => count($rows), '_citations' => ch247ai_cite($sql, $bind)];
        },
        ['entity' => 'tbldomains'],
        true // client-bound
    );
}

function ch247ai_products_tool()
{
    return new ToolDefinition(
        'read_products',
        'read.services',
        'READ',
        'Product catalogue (tblproducts + tblpricing) — name, group, type, active status and first currency pricing.',
        [
            'gid' => ['type' => 'integer', 'description' => 'Product group id', 'required' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tblproducts']);
            $limit = ch247ai_clamp_limit(isset($args['limit']) ? $args['limit'] : 20);
            $where = [];
            $bind = [];
            if (!empty($args['gid'])) {
                $where[] = 'tblproducts.gid = ?';
                $bind[] = (int) $args['gid'];
            }
            $sql = 'SELECT tblproducts.id, tblproducts.gid, tblproductgroups.name AS group_name, tblproducts.type, tblproducts.name, tblproducts.hidden, tblproducts.retired, tblproducts.paytype, tblpricing.monthly, tblpricing.annually FROM tblproducts LEFT JOIN tblproductgroups ON tblproductgroups.id = tblproducts.gid LEFT JOIN tblpricing ON tblpricing.type = \'product\' AND tblpricing.relid = tblproducts.id AND tblpricing.currency = 1'
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY tblproducts.id ASC LIMIT ' . $limit;
            $rows = Db::query($sql, $bind);
            return ['products' => $rows, 'count' => count($rows), '_citations' => ch247ai_cite($sql, $bind)];
        },
        ['entity' => 'tblproducts']
    );
}

function ch247ai_credits_tool()
{
    return new ToolDefinition(
        'read_credit',
        'read.billing',
        'READ',
        'Client credit balance and recent credit ledger (tblclients.credit + tblcredit).',
        [
            'client_id' => ['type' => 'integer', 'description' => 'WHMCS client id'],
        ],
        function (array $args, array $ctx) {
            ch247ai_require_tables(['tblclients', 'tblcredit']);
            $client = ch247ai_scope_client($ctx);
            $id = (int) $args['client_id'];
            if ($client && $client !== $id) {
                throw new NotFoundException('Client not found.');
            }
            $rows = Db::query('SELECT id, credit FROM tblclients WHERE id = ?', [$id]);
            if (!$rows) {
                throw new NotFoundException('Client ' . $id . ' does not exist.');
            }
            $ledger = Db::query('SELECT date, description, amount FROM tblcredit WHERE clientid = ? ORDER BY id DESC LIMIT 10', [$id]);
            return [
                'client_id' => $id,
                'credit_balance' => (float) $rows[0]['credit'],
                'recent_credits' => $ledger,
                '_citations' => array_merge(
                    ch247ai_cite('SELECT id, credit FROM tblclients WHERE id = ?', [$id]),
                    ch247ai_cite('SELECT date, description, amount FROM tblcredit WHERE clientid = ? ORDER BY id DESC LIMIT 10', [$id])
                ),
            ];
        },
        ['entity' => 'tblclients,tblcredit'],
        true // client-bound
    );
}

/** @return ToolDefinition[] */
function ch247ai_billing_readers()
{
    return [
        ch247ai_clients_tool(),
        ch247ai_client_details_tool(),
        ch247ai_invoices_tool(),
        ch247ai_payments_tool(),
        ch247ai_orders_tool(),
        ch247ai_services_tool(),
        ch247ai_tickets_tool(),
        ch247ai_domains_tool(),
        ch247ai_products_tool(),
        ch247ai_credits_tool(),
    ];
}
