<?php
/**
 * CloudHost247 App Cloud — offline gateway.
 *
 * The test double for GatewayInterface. It holds fixtures (clients, products,
 * invoices, services, domains, currencies), records every call the platform
 * makes, and lets a suite flip an invoice to Paid or a service to Suspended —
 * which is how the billing gate and the subscription lifecycle are proven
 * without a WHMCS installation or a payment provider.
 *
 * It never invents a successful result: an unknown invoice is not paid, an
 * unknown client does not exist. That matters, because the platform's rule is to
 * report UNKNOWN rather than fabricate state.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Integration;

use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Str;

class FakeGateway implements GatewayInterface
{
    /** @var array<string, array[]> */
    private $fixtures = [
        'clients' => [],
        'products' => [],
        'pricing' => [],
        'invoices' => [],
        'transactions' => [],
        'orders' => [],
        'services' => [],
        'domains' => [],
        'currencies' => [],
        'servers' => [],
        'config' => [],
    ];

    /** @var array[] recorded calls */
    private $calls = [];

    /** @var array<string,string> sent emails: subject => body */
    private $emails = [];

    /** @var array[] */
    private $adminEmails = [];

    /** @var array[] */
    private $activity = [];

    /** @var array[] */
    private $tickets = [];

    /** @var int */
    private $nextId = 1000;

    /** @var bool */
    private $offline = false;

    /** @var array forced API failures: command => message */
    private $failures = [];

    public function setOffline($offline)
    {
        $this->offline = (bool) $offline;
        return $this;
    }

    public function isOffline()
    {
        return $this->offline;
    }

    /** Make a WHMCS API command fail, to test error handling. */
    public function failCommand($command, $message = 'Simulated failure')
    {
        $this->failures[(string) $command] = (string) $message;
        return $this;
    }

    public function record($method, array $params = [])
    {
        $this->calls[] = ['method' => $method, 'params' => $params, 'at' => Clock::now()];
    }

    /** @return array[] */
    public function callsTo($method)
    {
        return array_values(array_filter($this->calls, function ($call) use ($method) {
            return $call['method'] === $method;
        }));
    }

    public function callCount($method = null)
    {
        return $method === null ? count($this->calls) : count($this->callsTo($method));
    }

    public function calls()
    {
        return $this->calls;
    }

    public function reset()
    {
        foreach ($this->fixtures as $key => $unused) {
            $this->fixtures[$key] = [];
        }
        $this->calls = [];
        $this->emails = [];
        $this->adminEmails = [];
        $this->activity = [];
        $this->tickets = [];
        $this->failures = [];
        $this->nextId = 1000;
        return $this;
    }

    /* ------------------------------------------------------------- fixtures */

    public function addClient(array $client)
    {
        $client += [
            'id' => ++$this->nextId, 'email' => 'client' . $this->nextId . '@example.test',
            'firstname' => 'Test', 'lastname' => 'Client', 'companyname' => '',
            'status' => 'Active', 'currency' => 1, 'country' => 'NG', 'phonenumber' => '',
            'datecreated' => Clock::now(),
        ];
        $this->fixtures['clients'][(int) $client['id']] = $client;
        return (int) $client['id'];
    }

    public function addProduct(array $product)
    {
        $product += [
            'id' => ++$this->nextId, 'gid' => 1, 'name' => 'Product', 'description' => '',
            'type' => 'hostingaccount', 'servermodule' => '', 'paytype' => 'recurring',
            'hidden' => false, 'autosetup' => 'payment', 'stockcontrol' => '', 'qty' => 0,
        ];
        $this->fixtures['products'][(int) $product['id']] = $product;
        return (int) $product['id'];
    }

    public function setPricing($productId, array $pricing)
    {
        $this->fixtures['pricing'][(int) $productId] = $pricing;
        return $this;
    }

    public function addInvoice(array $invoice)
    {
        $invoice += [
            'id' => ++$this->nextId, 'userid' => 0, 'status' => 'Unpaid', 'subtotal' => '10.00',
            'total' => '10.00', 'credit' => '0.00', 'datepaid' => '', 'duedate' => Clock::now(),
            'currency' => 'USD', 'paymentmethod' => 'banktransfer',
        ];
        $this->fixtures['invoices'][(int) $invoice['id']] = $invoice;
        return (int) $invoice['id'];
    }

    /** Flip an invoice to Paid exactly as a gateway callback would. */
    public function payInvoice($invoiceId, $gateway = 'stripe')
    {
        $invoiceId = (int) $invoiceId;
        if (!isset($this->fixtures['invoices'][$invoiceId])) {
            return false;
        }
        $invoice = $this->fixtures['invoices'][$invoiceId];
        $invoice['status'] = 'Paid';
        $invoice['datepaid'] = Clock::now();
        $invoice['paymentmethod'] = $gateway;
        $this->fixtures['invoices'][$invoiceId] = $invoice;
        $this->fixtures['transactions'][] = [
            'invoiceid' => $invoiceId, 'gateway' => $gateway, 'amount' => $invoice['total'],
            'date' => Clock::now(), 'transid' => 'txn_' . $invoiceId,
        ];
        return true;
    }

    public function failInvoicePayment($invoiceId, $reason = 'card_declined')
    {
        $invoiceId = (int) $invoiceId;
        if (!isset($this->fixtures['invoices'][$invoiceId])) {
            return false;
        }
        $this->fixtures['invoices'][$invoiceId]['status'] = 'Unpaid';
        $this->fixtures['invoices'][$invoiceId]['failure_reason'] = $reason;
        return true;
    }

    public function addService(array $service)
    {
        $service += [
            'id' => ++$this->nextId, 'userid' => 0, 'packageid' => 0, 'domain' => '',
            'domainstatus' => 'Active', 'nextduedate' => Clock::inDays(30),
            'billingcycle' => 'Monthly', 'server' => 0, 'regdate' => Clock::now(),
            'suspendreason' => '',
        ];
        $this->fixtures['services'][(int) $service['id']] = $service;
        return (int) $service['id'];
    }

    public function setServiceStatus($serviceId, $status)
    {
        if (isset($this->fixtures['services'][(int) $serviceId])) {
            $this->fixtures['services'][(int) $serviceId]['domainstatus'] = (string) $status;
        }
        return $this;
    }

    /** Change the WHMCS service owner to exercise ownership-transfer gates. */
    public function setServiceOwner($serviceId, $clientId)
    {
        if (isset($this->fixtures['services'][(int) $serviceId])) {
            $this->fixtures['services'][(int) $serviceId]['userid'] = (int) $clientId;
        }
        return $this;
    }

    public function addDomain(array $domain)
    {
        $domain += [
            'id' => ++$this->nextId, 'userid' => 0, 'domain' => 'example.test',
            'status' => 'Active', 'expirydate' => Clock::inDays(365), 'registrar' => '',
        ];
        $this->fixtures['domains'][] = $domain;
        return (int) $domain['id'];
    }

    public function addCurrency(array $currency)
    {
        $currency += ['id' => count($this->fixtures['currencies']) + 1, 'code' => 'USD',
            'prefix' => '$', 'suffix' => '', 'rate' => 1.0, 'default' => true];
        $this->fixtures['currencies'][] = $currency;
        return $this;
    }

    public function addServer(array $server)
    {
        $this->fixtures['servers'][] = $server;
        return $this;
    }

    public function setConfig($setting, $value)
    {
        $this->fixtures['config'][(string) $setting] = $value;
        return $this;
    }

    /* ------------------------------------------------------- introspection */

    public function emails()
    {
        return $this->emails;
    }

    public function emailSubjects()
    {
        return array_keys($this->emails);
    }

    public function adminEmails()
    {
        return $this->adminEmails;
    }

    public function activityLog()
    {
        return $this->activity;
    }

    public function tickets()
    {
        return $this->tickets;
    }

    public function invoice($invoiceId)
    {
        return isset($this->fixtures['invoices'][(int) $invoiceId])
            ? $this->fixtures['invoices'][(int) $invoiceId] : null;
    }

    public function service($serviceId)
    {
        return isset($this->fixtures['services'][(int) $serviceId])
            ? $this->fixtures['services'][(int) $serviceId] : null;
    }

    /* ------------------------------------------------------------ interface */

    public function getClient($clientId)
    {
        $this->record('getClient', ['clientId' => (int) $clientId]);
        return isset($this->fixtures['clients'][(int) $clientId])
            ? $this->fixtures['clients'][(int) $clientId] : null;
    }

    public function getClientContacts($clientId)
    {
        $this->record('getClientContacts', ['clientId' => (int) $clientId]);
        return [];
    }

    public function getProduct($productId)
    {
        $this->record('getProduct', ['productId' => (int) $productId]);
        return isset($this->fixtures['products'][(int) $productId])
            ? $this->fixtures['products'][(int) $productId] : null;
    }

    public function getProducts($groupId = null)
    {
        $this->record('getProducts', ['groupId' => $groupId]);
        $out = [];
        foreach ($this->fixtures['products'] as $product) {
            if ($groupId === null || (int) $product['gid'] === (int) $groupId) {
                $out[] = $product;
            }
        }
        return $out;
    }

    public function getProductPricing($productId, $currencyCode)
    {
        $this->record('getProductPricing', ['productId' => (int) $productId, 'currency' => $currencyCode]);
        return isset($this->fixtures['pricing'][(int) $productId])
            ? $this->fixtures['pricing'][(int) $productId] : [];
    }

    public function getProductGroup($groupId)
    {
        $this->record('getProductGroup', ['groupId' => (int) $groupId]);
        return null;
    }

    public function createOrder(array $params)
    {
        $this->record('createOrder', $params);
        if (isset($this->failures['createOrder'])) {
            return ['order_id' => 0, 'invoice_id' => 0, 'result' => 'error', 'message' => $this->failures['createOrder']];
        }
        $orderId = ++$this->nextId;
        $invoiceId = $this->addInvoice([
            'userid' => isset($params['clientid']) ? (int) $params['clientid'] : 0,
            'status' => 'Unpaid',
        ]);
        $serviceId = $this->addService([
            'userid' => isset($params['clientid']) ? (int) $params['clientid'] : 0,
            'packageid' => isset($params['pid']) ? (int) $params['pid'] : 0,
            'domain' => isset($params['domain']) ? (string) $params['domain'] : '',
            'orderid' => $orderId,
            'domainstatus' => 'Pending',
        ]);
        $this->fixtures['orders'][$orderId] = [
            'id' => $orderId, 'userid' => isset($params['clientid']) ? (int) $params['clientid'] : 0,
            'status' => 'Pending', 'invoiceid' => $invoiceId, 'date' => Clock::now(),
        ];
        return ['order_id' => $orderId, 'invoice_id' => $invoiceId, 'service_id' => $serviceId, 'result' => 'success'];
    }

    public function getOrder($orderId)
    {
        $this->record('getOrder', ['orderId' => (int) $orderId]);
        return isset($this->fixtures['orders'][(int) $orderId]) ? $this->fixtures['orders'][(int) $orderId] : null;
    }

    public function getInvoice($invoiceId)
    {
        $this->record('getInvoice', ['invoiceId' => (int) $invoiceId]);
        return $this->invoice($invoiceId);
    }

    public function getInvoiceTransactions($invoiceId)
    {
        $this->record('getInvoiceTransactions', ['invoiceId' => (int) $invoiceId]);
        return array_values(array_filter($this->fixtures['transactions'], function ($row) use ($invoiceId) {
            return (int) $row['invoiceid'] === (int) $invoiceId;
        }));
    }

    public function isInvoicePaid($invoiceId)
    {
        $this->record('isInvoicePaid', ['invoiceId' => (int) $invoiceId]);
        $invoice = $this->invoice($invoiceId);
        if (!$invoice) {
            return false;
        }
        if (strtolower((string) $invoice['status']) === 'paid') {
            return true;
        }
        $total = (float) $invoice['total'];
        return $total <= 0.0000001;
    }

    public function getClientUnpaidInvoices($clientId)
    {
        $this->record('getClientUnpaidInvoices', ['clientId' => (int) $clientId]);
        $out = [];
        foreach ($this->fixtures['invoices'] as $invoice) {
            if ((int) $invoice['userid'] === (int) $clientId && $invoice['status'] === 'Unpaid') {
                $out[] = $invoice;
            }
        }
        return $out;
    }

    public function getService($serviceId)
    {
        $this->record('getService', ['serviceId' => (int) $serviceId]);
        return $this->service($serviceId);
    }

    public function getClientServices($clientId, $status = null)
    {
        $this->record('getClientServices', ['clientId' => (int) $clientId, 'status' => $status]);
        $out = [];
        foreach ($this->fixtures['services'] as $service) {
            if ((int) $service['userid'] !== (int) $clientId) {
                continue;
            }
            if ($status !== null && $service['domainstatus'] !== $status) {
                continue;
            }
            $out[] = $service;
        }
        return $out;
    }

    public function suspendService($serviceId, $reason = '')
    {
        $this->record('suspendService', ['serviceId' => (int) $serviceId, 'reason' => $reason]);
        if (isset($this->failures['suspendService'])) {
            return ['result' => 'error', 'message' => $this->failures['suspendService']];
        }
        $this->setServiceStatus($serviceId, 'Suspended');
        if (isset($this->fixtures['services'][(int) $serviceId])) {
            $this->fixtures['services'][(int) $serviceId]['suspendreason'] = Str::clip((string) $reason, 200);
        }
        return ['result' => 'success'];
    }

    public function unsuspendService($serviceId)
    {
        $this->record('unsuspendService', ['serviceId' => (int) $serviceId]);
        $this->setServiceStatus($serviceId, 'Active');
        return ['result' => 'success'];
    }

    public function terminateService($serviceId)
    {
        $this->record('terminateService', ['serviceId' => (int) $serviceId]);
        $this->setServiceStatus($serviceId, 'Terminated');
        return ['result' => 'success'];
    }

    public function getClientDomains($clientId, $domain = null)
    {
        $this->record('getClientDomains', ['clientId' => (int) $clientId, 'domain' => $domain]);
        $out = [];
        foreach ($this->fixtures['domains'] as $row) {
            if ((int) $row['userid'] !== (int) $clientId) {
                continue;
            }
            if ($domain !== null && $domain !== '' && strtolower($row['domain']) !== strtolower((string) $domain)) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    public function sendTemplateEmail($clientId, $template, array $vars = [])
    {
        $this->record('sendTemplateEmail', ['clientId' => (int) $clientId, 'template' => $template, 'vars' => $vars]);
        $subject = isset($vars['subject']) ? (string) $vars['subject'] : ('template:' . $template);
        $this->emails[$subject] = isset($vars['message']) ? (string) $vars['message'] : '';
        return ['result' => 'success'];
    }

    public function sendEmail($clientId, $subject, $bodyHtml, array $options = [])
    {
        $this->record('sendEmail', ['clientId' => (int) $clientId, 'subject' => $subject, 'options' => $options]);
        $this->emails[(string) $subject] = (string) $bodyHtml;
        return ['result' => 'success'];
    }

    public function sendAdminEmail($subject, $bodyHtml, array $options = [])
    {
        $this->record('sendAdminEmail', ['subject' => $subject, 'options' => $options]);
        $this->adminEmails[] = ['subject' => (string) $subject, 'body' => (string) $bodyHtml];
        return ['result' => 'success'];
    }

    public function openTicket(array $params)
    {
        $this->record('openTicket', $params);
        $id = ++$this->nextId;
        $this->tickets[] = array_merge(['id' => $id], $params);
        return ['result' => 'success', 'id' => $id];
    }

    public function addTicketReply($ticketId, $message, array $options = [])
    {
        $this->record('addTicketReply', ['ticketId' => (int) $ticketId, 'message' => Str::clip($message, 80)]);
        foreach ($this->tickets as &$ticket) {
            if ((int) $ticket['id'] === (int) $ticketId) {
                $ticket['replies'][] = $message;
            }
        }
        unset($ticket);
        return ['result' => 'success'];
    }

    public function logActivity($description, $clientId = 0)
    {
        $this->record('logActivity', ['description' => $description, 'clientId' => (int) $clientId]);
        $this->activity[] = (string) $description;
    }

    public function getCurrencies()
    {
        $this->record('getCurrencies', []);
        return $this->fixtures['currencies'] ?: [
            ['id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'rate' => 1.0, 'default' => true],
        ];
    }

    public function getClientCurrency($clientId)
    {
        $this->record('getClientCurrency', ['clientId' => (int) $clientId]);
        $currencies = $this->getCurrencies();
        return $currencies[0];
    }

    public function config($setting, $default = null)
    {
        $this->record('config', ['setting' => (string) $setting]);
        return array_key_exists((string) $setting, $this->fixtures['config'])
            ? $this->fixtures['config'][(string) $setting] : $default;
    }

    public function getServers($type = null)
    {
        $this->record('getServers', ['type' => $type]);
        if ($type === null) {
            return $this->fixtures['servers'];
        }
        return array_values(array_filter($this->fixtures['servers'], function ($row) use ($type) {
            return isset($row['type']) && $row['type'] === (string) $type;
        }));
    }
}
