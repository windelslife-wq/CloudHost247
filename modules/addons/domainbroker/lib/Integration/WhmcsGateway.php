<?php
/**
 * Domain Broker — live WHMCS gateway.
 *
 * Uses WHMCS' documented local API for everything that creates or mutates
 * financial records (CreateInvoice, AddTransaction, RefundInvoice, …) so that
 * WHMCS' own hooks, tax engine, credit handling, currency conversion and
 * activity logging all run exactly as they do for any other product. Read-only
 * lookups use Capsule, which is cheaper and side-effect free.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Integration;

use DomainBroker\Core\DomainBrokerException;
use DomainBroker\Core\Logger;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;

class WhmcsGateway implements GatewayInterface
{
    /** @var string admin username used for localAPI calls (optional in WHMCS 7.2+). */
    protected $adminUser;

    public function __construct($adminUser = null)
    {
        $this->adminUser = $adminUser !== null ? $adminUser : (string) Settings::get('api_admin_user', '');
    }

    /* ------------------------------------------------------------ helpers */

    protected function api($command, array $data = [])
    {
        if (!function_exists('localAPI')) {
            throw new DomainBrokerException('WHMCS local API is not available in this context.');
        }
        $result = localAPI($command, $data, $this->adminUser !== '' ? $this->adminUser : null);
        if (!is_array($result)) {
            throw new DomainBrokerException('Unexpected response from WHMCS API ' . $command . '.');
        }
        if (isset($result['result']) && $result['result'] === 'error') {
            $message = isset($result['message']) ? $result['message'] : 'unknown error';
            Logger::error('WHMCS API error', ['command' => $command, 'message' => $message]);
            throw new DomainBrokerException('WHMCS ' . $command . ' failed: ' . $message);
        }
        return $result;
    }

    protected function capsule()
    {
        if (!class_exists('\WHMCS\Database\Capsule')) {
            throw new DomainBrokerException('WHMCS Capsule is not available in this context.');
        }
        return '\WHMCS\Database\Capsule';
    }

    protected function row($query)
    {
        $result = $query->first();
        return $result ? (array) $result : null;
    }

    /* ------------------------------------------------------------ clients */

    public function getClient($clientId)
    {
        $c = $this->capsule();
        return $this->row($c::table('tblclients')->where('id', (int) $clientId));
    }

    public function getClientCurrency($clientId)
    {
        $client = $this->getClient($clientId);
        if (!$client) {
            return $this->getDefaultCurrency();
        }
        $currencyId = (int) (isset($client['currency']) ? $client['currency'] : 0);
        if ($currencyId <= 0) {
            return $this->getDefaultCurrency();
        }
        $c = $this->capsule();
        $row = $this->row($c::table('tblcurrencies')->where('id', $currencyId));
        return $row ? $this->normaliseCurrency($row) : $this->getDefaultCurrency();
    }

    public function getCurrencies()
    {
        $c = $this->capsule();
        $out = [];
        foreach ($c::table('tblcurrencies')->orderBy('default', 'desc')->orderBy('code')->get() as $row) {
            $out[] = $this->normaliseCurrency((array) $row);
        }
        return $out;
    }

    public function getDefaultCurrency()
    {
        $c = $this->capsule();
        $row = $this->row($c::table('tblcurrencies')->where('default', 1));
        if (!$row) {
            $row = $this->row($c::table('tblcurrencies')->orderBy('id'));
        }
        return $row ? $this->normaliseCurrency($row) : null;
    }

    public function getCurrencyByCode($code)
    {
        $c = $this->capsule();
        $row = $this->row($c::table('tblcurrencies')->where('code', strtoupper((string) $code)));
        return $row ? $this->normaliseCurrency($row) : null;
    }

    protected function normaliseCurrency(array $row)
    {
        return [
            'id'     => (int) $row['id'],
            'code'   => strtoupper((string) $row['code']),
            'prefix' => isset($row['prefix']) ? (string) $row['prefix'] : '',
            'suffix' => isset($row['suffix']) ? (string) $row['suffix'] : '',
            'rate'   => isset($row['rate']) ? (float) $row['rate'] : 1.0,
            'default' => !empty($row['default']),
        ];
    }

    public function systemCurrencyCode()
    {
        $currency = $this->getDefaultCurrency();
        return $currency ? $currency['code'] : 'USD';
    }

    /* ----------------------------------------------------------- invoices */

    public function createInvoice(array $params)
    {
        $payload = [
            'userid'        => (int) $params['clientid'],
            'status'        => isset($params['status']) ? $params['status'] : 'Unpaid',
            'sendinvoice'   => !empty($params['sendinvoice']) ? '1' : '0',
            'paymentmethod' => isset($params['paymentmethod']) ? $params['paymentmethod'] : '',
            'date'          => isset($params['date']) ? $params['date'] : date('Y-m-d'),
            'duedate'       => isset($params['duedate']) ? $params['duedate'] : date('Y-m-d'),
            'notes'         => isset($params['notes']) ? Str::clip($params['notes'], 1000) : '',
        ];
        if (isset($params['taxrate'])) {
            $payload['taxrate'] = $params['taxrate'];
        }

        $i = 1;
        foreach ($params['items'] as $item) {
            $payload['itemdescription' . $i] = Str::clip($item['description'], 250);
            $payload['itemamount' . $i] = number_format((float) $item['amount'], 2, '.', '');
            $payload['itemtaxed' . $i] = !empty($item['taxed']) ? '1' : '0';
            $i++;
        }

        $result = $this->api('CreateInvoice', $payload);
        return [
            'invoiceid' => (int) (isset($result['invoiceid']) ? $result['invoiceid'] : 0),
            'status'    => isset($result['status']) ? $result['status'] : 'Unpaid',
        ];
    }

    public function getInvoice($invoiceId)
    {
        if (!$invoiceId) {
            return null;
        }
        try {
            $result = $this->api('GetInvoice', ['invoiceid' => (int) $invoiceId]);
        } catch (DomainBrokerException $e) {
            return null;
        }
        return $result;
    }

    public function getInvoiceTransactions($invoiceId)
    {
        try {
            $result = $this->api('GetTransactions', ['invoiceid' => (int) $invoiceId]);
        } catch (DomainBrokerException $e) {
            return [];
        }
        if (isset($result['transactions']['transaction'])) {
            return $result['transactions']['transaction'];
        }
        return [];
    }

    public function cancelInvoice($invoiceId)
    {
        $this->api('UpdateInvoice', ['invoiceid' => (int) $invoiceId, 'status' => 'Cancelled']);
        return true;
    }

    public function refundInvoice($invoiceId, $amount, $gateway = '', $note = '')
    {
        $payload = [
            'invoiceid' => (int) $invoiceId,
            'amount'    => number_format((float) $amount, 2, '.', ''),
            'noemail'   => false,
        ];
        if ($gateway !== '') {
            $payload['gateway'] = $gateway;
        }
        $payload['adminnote'] = Str::clip($note, 500);

        $result = $this->api('AddTransaction', [
            'invoiceid'   => (int) $invoiceId,
            'userid'      => 0,
            'description' => Str::clip('Domain Broker refund: ' . $note, 250),
            'amountout'   => number_format((float) $amount, 2, '.', ''),
            'paymentmethod' => $gateway !== '' ? $gateway : 'system',
            'date'        => date('Y-m-d'),
        ]);

        return [
            'transid' => isset($result['transid']) ? (string) $result['transid'] : '',
            'amount'  => number_format((float) $amount, 2, '.', ''),
        ];
    }

    /* ------------------------------------------------------------ domains */

    public function getDomain($domainId)
    {
        $c = $this->capsule();
        return $this->row($c::table('tbldomains')->where('id', (int) $domainId));
    }

    public function getClientDomains($clientId, $domain = null)
    {
        $c = $this->capsule();
        $query = $c::table('tbldomains')->where('userid', (int) $clientId);
        if ($domain) {
            $query->where('domain', $domain);
        }
        $out = [];
        foreach ($query->get() as $row) {
            $out[] = (array) $row;
        }
        return $out;
    }

    /* ------------------------------------------------------------ tickets */

    public function openTicket(array $params)
    {
        $deptId = (int) Settings::int('support_department_id', 0);
        if ($deptId <= 0) {
            return null;
        }
        $result = $this->api('OpenTicket', [
            'clientid'   => (int) $params['clientid'],
            'deptid'     => $deptId,
            'subject'    => Str::clip($params['subject'], 200),
            'message'    => $params['message'],
            'priority'   => isset($params['priority']) ? $params['priority'] : 'Medium',
            'markdown'   => false,
            'noemail'    => !empty($params['noemail']),
        ]);
        return [
            'ticketid' => (int) (isset($result['id']) ? $result['id'] : 0),
            'tid'      => isset($result['tid']) ? (string) $result['tid'] : '',
        ];
    }

    public function addTicketReply($ticketId, $message, array $options = [])
    {
        if (!$ticketId) {
            return false;
        }
        $this->api('AddTicketReply', array_merge([
            'ticketid' => (int) $ticketId,
            'message'  => $message,
            'markdown' => false,
        ], $options));
        return true;
    }

    /* -------------------------------------------------------------- comms */

    public function sendClientEmail($clientId, $subject, $bodyHtml, array $options = [])
    {
        try {
            $this->api('SendEmail', [
                'messagename' => isset($options['template']) ? $options['template'] : '',
                'id'          => (int) $clientId,
                'customtype'  => 'general',
                'customsubject' => Str::clip($subject, 200),
                'custommessage' => $bodyHtml,
            ]);
            return true;
        } catch (DomainBrokerException $e) {
            Logger::error('Client email failed', ['client_id' => $clientId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendAdminEmail($adminId, $subject, $bodyHtml, array $options = [])
    {
        if (!function_exists('sendAdminMessage')) {
            Logger::warning('sendAdminMessage unavailable; admin notification skipped.');
            return false;
        }
        try {
            sendAdminMessage('Domain Broker Notification', [
                'subject' => Str::clip($subject, 200),
                'message' => $bodyHtml,
            ], isset($options['admin_group']) ? $options['admin_group'] : '');
            return true;
        } catch (\Throwable $e) {
            Logger::error('Admin email failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /* -------------------------------------------------------------- staff */

    public function getAdmins()
    {
        $c = $this->capsule();
        $out = [];
        foreach ($c::table('tbladmins')->where('disabled', 0)->orderBy('firstname')->get() as $row) {
            $row = (array) $row;
            $out[] = [
                'id'        => (int) $row['id'],
                'username'  => isset($row['username']) ? $row['username'] : '',
                'firstname' => isset($row['firstname']) ? $row['firstname'] : '',
                'lastname'  => isset($row['lastname']) ? $row['lastname'] : '',
                'email'     => isset($row['email']) ? $row['email'] : '',
                'roleid'    => isset($row['roleid']) ? (int) $row['roleid'] : 0,
            ];
        }
        return $out;
    }

    public function getAdmin($adminId)
    {
        $c = $this->capsule();
        $row = $this->row($c::table('tbladmins')->where('id', (int) $adminId));
        if (!$row) {
            return null;
        }
        return [
            'id'        => (int) $row['id'],
            'username'  => isset($row['username']) ? $row['username'] : '',
            'firstname' => isset($row['firstname']) ? $row['firstname'] : '',
            'lastname'  => isset($row['lastname']) ? $row['lastname'] : '',
            'email'     => isset($row['email']) ? $row['email'] : '',
            'roleid'    => isset($row['roleid']) ? (int) $row['roleid'] : 0,
        ];
    }

    /* --------------------------------------------------------------- misc */

    public function logActivity($description, $clientId = 0)
    {
        if (function_exists('logActivity')) {
            logActivity('[Domain Broker] ' . Str::clip($description, 400), (int) $clientId);
            return true;
        }
        return false;
    }
}
