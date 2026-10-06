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
}
