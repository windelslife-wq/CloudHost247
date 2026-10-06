<?php
/**
 * Domain Broker — recording gateway for the test suite.
 *
 * Behaves like WHMCS closely enough to exercise the real code paths (invoices
 * get ids, statuses change, transactions accumulate) while recording every
 * call so the tests can assert *what the module asked WHMCS to do*. It is in
 * lib/ rather than tests/ so the module's own CLI diagnostics can also run
 * against it without a WHMCS bootstrap.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Integration;

class FakeGateway implements GatewayInterface
{
    /** @var array call log: [['method'=>..., 'args'=>[...]], …] */
    public $calls = [];

    /** @var array */
    public $clients = [];

    /** @var array */
    public $invoices = [];

    /** @var array */
    public $transactions = [];

    /** @var array */
    public $tickets = [];

    /** @var array */
    public $emails = [];

    /** @var array */
    public $domains = [];

    /** @var array */
    public $admins = [];

    /** @var array */
    public $currencies = [
        ['id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'rate' => 1.0, 'default' => true],
        ['id' => 2, 'code' => 'EUR', 'prefix' => '€', 'suffix' => '', 'rate' => 0.92, 'default' => false],
        ['id' => 3, 'code' => 'GBP', 'prefix' => '£', 'suffix' => '', 'rate' => 0.79, 'default' => false],
    ];

    /** @var int */
    protected $nextInvoiceId = 5000;

    /** @var int */
    protected $nextTransactionId = 9000;

    /** @var int */
    protected $nextTicketId = 300;

    /** @var bool when true, createInvoice throws (failure-path tests). */
    public $failInvoiceCreation = false;

    /** @var bool */
    public $failRefunds = false;

    protected function record($method, array $args = [])
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }

    public function callsTo($method)
    {
        return array_values(array_filter($this->calls, function ($c) use ($method) {
            return $c['method'] === $method;
        }));
    }

    public function callCount($method)
    {
        return count($this->callsTo($method));
    }

    public function reset()
    {
        $this->calls = [];
        $this->emails = [];
    }

    /* ---------------------------------------------------------- fixtures */

    public function addClient(array $client)
    {
        $this->clients[(int) $client['id']] = $client;
        return $this;
    }

    public function addAdmin(array $admin)
    {
        $this->admins[(int) $admin['id']] = $admin;
        return $this;
    }

    public function addDomain(array $domain)
    {
        $this->domains[] = $domain;
        return $this;
    }

    /** Simulate the customer paying an invoice through any gateway. */
    public function payInvoice($invoiceId, $gateway = 'stripe')
    {
        if (!isset($this->invoices[$invoiceId])) {
            return false;
        }
        $this->invoices[$invoiceId]['status'] = 'Paid';
        $this->invoices[$invoiceId]['datepaid'] = gmdate('Y-m-d H:i:s');
        $this->invoices[$invoiceId]['paymentmethod'] = $gateway;
        $transId = 'TX-' . (++$this->nextTransactionId);
        $this->transactions[$invoiceId][] = [
            'id' => $this->nextTransactionId,
            'transid' => $transId,
            'gateway' => $gateway,
            'amountin' => $this->invoices[$invoiceId]['total'],
            'amountout' => '0.00',
            'date' => gmdate('Y-m-d H:i:s'),
        ];
        return $transId;
    }

    public function failInvoicePayment($invoiceId, $reason = 'card_declined')
    {
        $this->record('paymentFailed', ['invoiceid' => $invoiceId, 'reason' => $reason]);
        return true;
    }

    /* ----------------------------------------------------------- clients */

    public function getClient($clientId)
    {
        $this->record('getClient', ['clientid' => (int) $clientId]);
        return isset($this->clients[(int) $clientId]) ? $this->clients[(int) $clientId] : null;
    }

    public function getClientCurrency($clientId)
    {
        $client = isset($this->clients[(int) $clientId]) ? $this->clients[(int) $clientId] : null;
        if ($client && isset($client['currency_code'])) {
            return $this->getCurrencyByCode($client['currency_code']);
        }
        return $this->getDefaultCurrency();
    }

    public function getCurrencies()
    {
        return $this->currencies;
    }

    public function getDefaultCurrency()
    {
        foreach ($this->currencies as $c) {
            if (!empty($c['default'])) {
                return $c;
            }
        }
        return $this->currencies[0];
    }

    public function getCurrencyByCode($code)
    {
        foreach ($this->currencies as $c) {
            if ($c['code'] === strtoupper((string) $code)) {
                return $c;
            }
        }
        return null;
    }

    public function systemCurrencyCode()
    {
        $c = $this->getDefaultCurrency();
        return $c ? $c['code'] : 'USD';
    }

    /* ---------------------------------------------------------- invoices */

    public function createInvoice(array $params)
    {
        $this->record('createInvoice', $params);
        if ($this->failInvoiceCreation) {
            throw new \DomainBroker\Core\DomainBrokerException('Simulated WHMCS invoice failure.');
        }
        $id = ++$this->nextInvoiceId;
        $total = 0.0;
        foreach ($params['items'] as $item) {
            $total += (float) $item['amount'];
        }
        $this->invoices[$id] = [
            'invoiceid' => $id,
            'userid'    => (int) $params['clientid'],
            'status'    => isset($params['status']) ? $params['status'] : 'Unpaid',
            'total'     => number_format($total, 2, '.', ''),
            'subtotal'  => number_format($total, 2, '.', ''),
            'duedate'   => isset($params['duedate']) ? $params['duedate'] : date('Y-m-d'),
            'items'     => $params['items'],
            'notes'     => isset($params['notes']) ? $params['notes'] : '',
            'datepaid'  => null,
        ];
        return ['invoiceid' => $id, 'status' => $this->invoices[$id]['status']];
    }

    public function getInvoice($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        $this->record('getInvoice', ['invoiceid' => $invoiceId]);
        return isset($this->invoices[$invoiceId]) ? $this->invoices[$invoiceId] : null;
    }

    public function getInvoiceTransactions($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        return isset($this->transactions[$invoiceId]) ? $this->transactions[$invoiceId] : [];
    }

    public function cancelInvoice($invoiceId)
    {
        $this->record('cancelInvoice', ['invoiceid' => (int) $invoiceId]);
        if (isset($this->invoices[(int) $invoiceId])) {
            $this->invoices[(int) $invoiceId]['status'] = 'Cancelled';
        }
        return true;
    }

    public function refundInvoice($invoiceId, $amount, $gateway = '', $note = '')
    {
        $this->record('refundInvoice', [
            'invoiceid' => (int) $invoiceId, 'amount' => $amount, 'gateway' => $gateway, 'note' => $note,
        ]);
        if ($this->failRefunds) {
            throw new \DomainBroker\Core\DomainBrokerException('Simulated refund failure.');
        }
        $transId = 'RF-' . (++$this->nextTransactionId);
        $this->transactions[(int) $invoiceId][] = [
            'id' => $this->nextTransactionId,
            'transid' => $transId,
            'gateway' => $gateway,
            'amountin' => '0.00',
            'amountout' => number_format((float) $amount, 2, '.', ''),
            'date' => gmdate('Y-m-d H:i:s'),
        ];
        return ['transid' => $transId, 'amount' => number_format((float) $amount, 2, '.', '')];
    }

    /* ----------------------------------------------------------- domains */

    public function getDomain($domainId)
    {
        foreach ($this->domains as $d) {
            if ((int) $d['id'] === (int) $domainId) {
                return $d;
            }
        }
        return null;
    }

    public function getClientDomains($clientId, $domain = null)
    {
        $out = [];
        foreach ($this->domains as $d) {
            if ((int) $d['userid'] !== (int) $clientId) {
                continue;
            }
            if ($domain !== null && $d['domain'] !== $domain) {
                continue;
            }
            $out[] = $d;
        }
        return $out;
    }

    /* ----------------------------------------------------------- tickets */

    public function openTicket(array $params)
    {
        $this->record('openTicket', $params);
        $id = ++$this->nextTicketId;
        $this->tickets[$id] = array_merge($params, ['id' => $id, 'replies' => []]);
        return ['ticketid' => $id, 'tid' => 'DB-' . $id];
    }

    public function addTicketReply($ticketId, $message, array $options = [])
    {
        $this->record('addTicketReply', ['ticketid' => $ticketId, 'message' => $message]);
        if (isset($this->tickets[(int) $ticketId])) {
            $this->tickets[(int) $ticketId]['replies'][] = $message;
        }
        return true;
    }

    /* ------------------------------------------------------------- comms */

    public function sendClientEmail($clientId, $subject, $bodyHtml, array $options = [])
    {
        $this->record('sendClientEmail', ['clientid' => $clientId, 'subject' => $subject]);
        $this->emails[] = [
            'to' => 'client:' . (int) $clientId,
            'subject' => $subject,
            'body' => $bodyHtml,
            'options' => $options,
        ];
        return true;
    }

    public function sendAdminEmail($adminId, $subject, $bodyHtml, array $options = [])
    {
        $this->record('sendAdminEmail', ['adminid' => $adminId, 'subject' => $subject]);
        $this->emails[] = [
            'to' => 'admin:' . (int) $adminId,
            'subject' => $subject,
            'body' => $bodyHtml,
            'options' => $options,
        ];
        return true;
    }

    public function emailSubjects()
    {
        return array_column($this->emails, 'subject');
    }

    /* ------------------------------------------------------------- staff */

    public function getAdmins()
    {
        return array_values($this->admins);
    }

    public function getAdmin($adminId)
    {
        return isset($this->admins[(int) $adminId]) ? $this->admins[(int) $adminId] : null;
    }

    /* -------------------------------------------------------------- misc */

    public function logActivity($description, $clientId = 0)
    {
        $this->record('logActivity', ['description' => $description, 'clientid' => $clientId]);
        return true;
    }
}
