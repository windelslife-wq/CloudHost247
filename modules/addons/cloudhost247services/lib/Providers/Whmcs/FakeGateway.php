<?php
/**
 * Deterministic recording double of the host platform, used by the offline
 * test suite (never referenced by production code).
 *
 * Two jobs:
 *   1. Answer every GatewayInterface call from in-memory fixtures the test
 *      seeds directly on public properties ($clients, $availability…).
 *   2. Record every mutating call so tests can assert exactly which invoices
 *      would have been raised, which emails sent, which replies posted.
 *
 * @package Chs\Providers\Whmcs
 */

namespace Chs\Providers\Whmcs;

class FakeGateway implements GatewayInterface
{
    /** @var array<int,array{id:int,name:string,email:string,currency:string}> */
    public $clients = [];
    /** @var array<string,array{status:string,available:bool}> */
    public $availability = [];
    /** @var array<int,string> invoice id => status ('Unpaid','Paid','Cancelled',…) */
    public $invoices = [];
    /** @var array<int,array<string,mixed>> invoice id => creation meta */
    public $invoiceMeta = [];
    /** @var array<int,array<int,array<string,mixed>>> */
    public $clientDomains = [];
    /** @var array<int,array<string,mixed>> */
    public $conversations = [];
    /** @var array<int,array<int,array<string,mixed>>> */
    public $ticketMessages = [];
    /** @var array<int,array<string,mixed>> */
    public $adminConversations = [];
    /** @var string */
    public $defaultCurrencyCode = 'USD';

    /** @var array<string,array<int,array<string,mixed>>> method => call list */
    private $calls = [];
    /** @var int */
    private $nextInvoiceId = 9001;
    /** @var int */
    private $nextReplyId = 1;

    /** Call log query for tests. */
    public function callsTo($method)
    {
        return isset($this->calls[$method]) ? $this->calls[$method] : [];
    }

    private function record($method, array $args)
    {
        if (!isset($this->calls[$method])) {
            $this->calls[$method] = [];
        }
        $this->calls[$method][] = $args;
    }

    public function reset()
    {
        $this->calls = [];
    }

    /* ------------------------------------------------------------ clients -- */

    public function clientExists($clientId)
    {
        return isset($this->clients[(int) $clientId]);
    }

    public function clientCurrency($clientId)
    {
        $c = (int) $clientId;
        return isset($this->clients[$c]) ? $this->clients[$c]['currency'] : $this->defaultCurrencyCode;
    }

    public function defaultCurrency()
    {
        return $this->defaultCurrencyCode;
    }

    public function clientSummary($clientId)
    {
        $c = (int) $clientId;
        return isset($this->clients[$c]) ? $this->clients[$c] : null;
    }

    /* ------------------------------------------------------------ billing -- */

    public function createInvoice($clientId, array $items, $currency, $dueDays, $notes = '')
    {
        $id = $this->nextInvoiceId++;
        $this->invoices[$id] = 'Unpaid';
        $this->invoiceMeta[$id] = [
            'currency'  => (string) $currency,
            'due_days'  => (int) $dueDays,
            'notes'     => (string) $notes,
            'client_id' => (int) $clientId,
            'items'     => $items,
        ];
        $this->record('createInvoice', [
            'invoice' => $id, 'client' => (int) $clientId,
            'items' => $items, 'currency' => (string) $currency,
            'due_days' => (int) $dueDays, 'notes' => (string) $notes,
        ]);
        return $id;
    }

    public function cancelInvoice($invoiceId)
    {
        $id = (int) $invoiceId;
        if (isset($this->invoices[$id])) {
            $this->invoices[$id] = 'Cancelled';
        }
        $this->record('cancelInvoice', ['invoice' => $id]);
    }

    public function invoiceStatus($invoiceId)
    {
        $id = (int) $invoiceId;
        return isset($this->invoices[$id]) ? $this->invoices[$id] : null;
    }

    public function invoiceUrl($invoiceId)
    {
        return 'https://billing.example.test/viewinvoice.php?id=' . (int) $invoiceId;
    }

    /* ------------------------------------------------------------- email -- */

    public function sendEmail($clientId, $subject, $plainBody)
    {
        $this->record('sendEmail', [
            'client_id' => (int) $clientId,
            'subject'   => (string) $subject,
            'body'      => (string) $plainBody,
        ]);
    }

    /* ------------------------------------------------------------ domains -- */

    public function domainAvailability($domain)
    {
        $d = strtolower((string) $domain);
        if (isset($this->availability[$d])) {
            return $this->availability[$d];
        }
        return ['status' => 'unknown', 'available' => false];
    }

    public function clientDomains($clientId)
    {
        $c = (int) $clientId;
        return isset($this->clientDomains[$c]) ? array_values($this->clientDomains[$c]) : [];
    }

    /* --------------------------------------------------------- ticketing -- */

    public function inboxConversations($clientId, $limit = 100)
    {
        unset($clientId, $limit); // fixtures are already scoped by the test
        return array_values($this->conversations);
    }

    public function inboxMessages($ticketId)
    {
        $t = (int) $ticketId;
        return isset($this->ticketMessages[$t]) ? $this->ticketMessages[$t] : [];
    }

    public function inboxReply($ticketId, $body, $authorType, $authorId)
    {
        $id = $this->nextReplyId++;
        $this->record('inboxReply', [
            'ticket_id'  => (int) $ticketId,
            'body'       => (string) $body,
            'authorType' => (string) $authorType,
            'authorId'   => (int) $authorId,
            'reply_id'   => $id,
        ]);
        $t = (int) $ticketId;
        if (!isset($this->ticketMessages[$t])) {
            $this->ticketMessages[$t] = [];
        }
        $this->ticketMessages[$t][] = [
            'id' => $id, 'author' => (string) $authorId, 'authorType' => (string) $authorType,
            'body' => (string) $body, 'date' => \Chs\Core\Clock::now(),
        ];
        return $id;
    }

    public function inboxAdminList($limit = 200, $onlyUnassigned = false)
    {
        unset($limit);
        $rows = array_values($this->adminConversations);
        if ($onlyUnassigned) {
            $rows = array_values(array_filter($rows, function ($r) {
                return empty($r['flag']);
            }));
        }
        return $rows;
    }

    public function inboxAssign($ticketId, $adminId)
    {
        $this->record('inboxAssign', ['ticket_id' => (int) $ticketId, 'admin_id' => (int) $adminId]);
    }

    public function inboxSetStatus($ticketId, $status)
    {
        $this->record('inboxSetStatus', ['ticket_id' => (int) $ticketId, 'status' => (string) $status]);
    }

    /* ------------------------------------------- servers, products, orders -- */

    /** @var array<int,array<string,mixed>> product id => product row */
    public $products = [];
    /** @var array<int,array<string,array<string,int>>> product id => cycle => minor */
    public $productPricing = [];
    /** @var array<int,array<string,mixed>> order id => order row */
    public $orders = [];
    /** @var array<int,array<string,mixed>> hosting id => hosting row */
    public $hosting = [];
    /** @var array<int,array<string,mixed>> server id => tblservers row */
    public $serverRecords = [];
    /** @var int */
    private $nextOrderId = 7001;
    /** @var int */
    private $nextHostingId = 6001;
    /** @var int */
    private $nextServerId = 3001;

    public function serverProducts()
    {
        $out = [];
        foreach ($this->products as $p) {
            if ((string) $p['type'] !== 'server') {
                continue;
            }
            if (isset($p['hidden']) && in_array((string) $p['hidden'], ['on', '1'], true)) {
                continue;
            }
            $pricing = $this->productPricing((int) $p['id'], $this->defaultCurrency());
            $amount = null;
            if ($pricing['cycles'] !== []) {
                $cycle = (string) $p['paytype'] === 'onetime' ? 'onetime'
                    : ((string) $p['paytype'] === 'free' ? 'free' : 'monthly');
                $amount = isset($pricing['cycles'][$cycle]) ? $pricing['cycles'][$cycle] : reset($pricing['cycles']);
            }
            $p['price_minor'] = $amount === null ? null : (int) $amount + (int) $pricing['setup_minor'];
            $p['currency'] = $this->defaultCurrency();
            $out[] = $p;
        }
        return $out;
    }

    public function productDetail($productId)
    {
        $id = (int) $productId;
        return isset($this->products[$id]) ? $this->products[$id] : null;
    }

    public function productPricing($productId, $currency)
    {
        unset($currency);
        $id = (int) $productId;
        $cycles = isset($this->productPricing[$id]) ? $this->productPricing[$id] : [];
        return ['setup_minor' => 0, 'cycles' => $cycles];
    }

    public function createOrder($clientId, $productId, $billingCycle, $hostname, $paymentMethod = '')
    {
        $product = $this->productDetail($productId);
        if (!$product || (string) $product['type'] !== 'server') {
            throw new \InvalidArgumentException('Product is not a server product.');
        }
        $orderId = $this->nextOrderId++;
        $invoiceId = $this->nextInvoiceId++;
        $hostingId = $this->nextHostingId++;
        $this->orders[$orderId] = [
            'id' => $orderId, 'userid' => (int) $clientId, 'invoiceid' => $invoiceId,
        ];
        $this->invoices[$invoiceId] = 'Unpaid';
        $this->invoiceMeta[$invoiceId] = [
            'currency' => $this->defaultCurrency(), 'due_days' => 7, 'notes' => '',
            'client_id' => (int) $clientId, 'items' => [],
        ];
        $this->hosting[$hostingId] = [
            'id' => $hostingId, 'userid' => (int) $clientId, 'packageid' => (int) $productId,
            'orderid' => $orderId, 'domain' => (string) $hostname, 'server' => 0,
            'status' => 'Pending', 'nextduedate' => null, 'regdate' => \Chs\Core\Clock::now(),
        ];
        $this->record('createOrder', [
            'order_id' => $orderId, 'invoice_id' => $invoiceId, 'hosting_id' => $hostingId,
            'client_id' => (int) $clientId, 'product_id' => (int) $productId,
            'billing_cycle' => (string) $billingCycle, 'hostname' => (string) $hostname,
            'payment_method' => (string) $paymentMethod,
        ]);
        return ['order_id' => $orderId, 'invoice_id' => $invoiceId];
    }

    public function serviceForInvoice($invoiceId)
    {
        foreach ($this->orders as $order) {
            if ((int) $order['invoiceid'] === (int) $invoiceId) {
                foreach ($this->hosting as $h) {
                    if ((int) $h['orderid'] === (int) $order['id']) {
                        return $h;
                    }
                }
            }
        }
        return null;
    }

    public function hostingDetail($hostingId)
    {
        $id = (int) $hostingId;
        if (!isset($this->hosting[$id])) {
            return null;
        }
        $h = $this->hosting[$id];
        $product = $this->productDetail(isset($h['packageid']) ? (int) $h['packageid'] : 0);
        $h['product_name'] = $product ? (string) $product['name'] : '';
        $h['product_type'] = $product ? (string) $product['type'] : '';
        $h['paytype'] = $product ? (string) $product['paytype'] : '';
        return $h;
    }

    public function clientServices($clientId)
    {
        $out = [];
        foreach ($this->hosting as $h) {
            if ((int) $h['userid'] === (int) $clientId) {
                $out[] = $this->hostingDetail((int) $h['id']);
            }
        }
        return $out;
    }

    public function updateService($hostingId, array $fields)
    {
        $id = (int) $hostingId;
        if (!isset($this->hosting[$id])) {
            return false;
        }
        foreach (['domain', 'server', 'status', 'nextduedate'] as $column) {
            if (array_key_exists($column, $fields)) {
                $this->hosting[$id][$column] = $fields[$column];
            }
        }
        $this->record('updateService', ['hosting_id' => $id, 'fields' => $fields]);
        return true;
    }

    public function createServerRecord(array $fields)
    {
        $id = $this->nextServerId++;
        $row = ['id' => $id];
        foreach (['name', 'ipaddress', 'hostname', 'username', 'password', 'type', 'assignedips', 'ns1', 'ns2'] as $column) {
            $row[$column] = isset($fields[$column]) ? $fields[$column] : '';
        }
        $this->serverRecords[$id] = $row;
        $this->record('createServerRecord', ['server_id' => $id, 'fields' => $fields]);
        return $id;
    }

    public function updateServerRecord($serverId, array $fields)
    {
        $id = (int) $serverId;
        if (!isset($this->serverRecords[$id])) {
            return false;
        }
        foreach (['name', 'ipaddress', 'hostname', 'username', 'password', 'type', 'assignedips', 'ns1', 'ns2'] as $column) {
            if (array_key_exists($column, $fields)) {
                $this->serverRecords[$id][$column] = $fields[$column];
            }
        }
        $this->record('updateServerRecord', ['server_id' => $id, 'fields' => $fields]);
        return true;
    }

    public function serverRecord($serverId)
    {
        $id = (int) $serverId;
        return isset($this->serverRecords[$id]) ? $this->serverRecords[$id] : null;
    }
}
