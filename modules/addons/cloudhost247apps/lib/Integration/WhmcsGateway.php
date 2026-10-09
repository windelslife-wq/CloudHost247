<?php
/**
 * CloudHost247 App Cloud — WHMCS gateway.
 *
 * The real integration. Reads go through Capsule (fast, no API round trip) and
 * writes go through the WHMCS internal API so they respect WHMCS' own hooks,
 * activity log, email templates and automation. Nothing here caches money or
 * status: an invoice is re-read at the moment provisioning is decided.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Integration;

use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\Whmcs;

class WhmcsGateway implements GatewayInterface
{
    /* ------------------------------------------------------------ identity */

    public function getClient($clientId)
    {
        $row = Whmcs::row('tblclients', ['id' => (int) $clientId]);
        return $row ? $this->presentClient($row) : null;
    }

    public function getClientContacts($clientId)
    {
        return Whmcs::rows('tblcontacts', ['userid' => (int) $clientId]);
    }

    private function presentClient(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'email' => isset($row['email']) ? (string) $row['email'] : '',
            'firstname' => isset($row['firstname']) ? (string) $row['firstname'] : '',
            'lastname' => isset($row['lastname']) ? (string) $row['lastname'] : '',
            'companyname' => isset($row['companyname']) ? (string) $row['companyname'] : '',
            'status' => isset($row['status']) ? (string) $row['status'] : 'Active',
            'currency' => isset($row['currency']) ? (int) $row['currency'] : 0,
            'country' => isset($row['country']) ? (string) $row['country'] : '',
            'phonenumber' => isset($row['phonenumber']) ? (string) $row['phonenumber'] : '',
            'datecreated' => isset($row['datecreated']) ? (string) $row['datecreated'] : '',
        ];
    }

    /* ------------------------------------------------------------- catalog */

    public function getProduct($productId)
    {
        $row = Whmcs::row('tblproducts', ['id' => (int) $productId]);
        return $row ? $this->presentProduct($row) : null;
    }

    public function getProducts($groupId = null)
    {
        $where = $groupId === null ? [] : ['gid' => (int) $groupId];
        $out = [];
        foreach (Whmcs::rows('tblproducts', $where) as $row) {
            $out[] = $this->presentProduct($row);
        }
        return $out;
    }

    private function presentProduct(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'gid' => isset($row['gid']) ? (int) $row['gid'] : 0,
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'description' => isset($row['description']) ? (string) $row['description'] : '',
            'type' => isset($row['type']) ? (string) $row['type'] : 'other',
            'servermodule' => isset($row['servermodule']) ? (string) $row['servermodule'] : '',
            'paytype' => isset($row['paytype']) ? (string) $row['paytype'] : '',
            'hidden' => isset($row['hidden']) && $row['hidden'] === 'on',
            'autosetup' => isset($row['autosetup']) ? (string) $row['autosetup'] : '',
            'stockcontrol' => isset($row['stockcontrol']) ? (string) $row['stockcontrol'] : '',
            'qty' => isset($row['qty']) ? (int) $row['qty'] : 0,
        ];
    }

    public function getProductPricing($productId, $currencyCode)
    {
        $currency = $this->currencyRowByCode($currencyCode);
        $currencyId = $currency ? (int) $currency['id'] : 1;
        $row = Whmcs::row('tblpricing', ['relid' => (int) $productId, 'currency' => $currencyId, 'type' => 'product']);
        if (!$row) {
            return [];
        }
        $out = [];
        foreach (['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially', 'hourly'] as $cycle) {
            if (isset($row[$cycle]) && $row[$cycle] !== '' && (float) $row[$cycle] >= 0) {
                $out[$cycle] = (float) $row[$cycle];
            }
        }
        $out['setup'] = isset($row['msetupfee']) ? (float) $row['msetupfee'] : 0.0;
        return $out;
    }

    public function getProductGroup($groupId)
    {
        return Whmcs::row('tblproductgroups', ['id' => (int) $groupId]);
    }

    /* -------------------------------------------------------------- billing */

    public function createOrder(array $params)
    {
        $payload = [
            'clientid' => (int) (isset($params['clientid']) ? $params['clientid'] : 0),
            'pid' => (int) (isset($params['pid']) ? $params['pid'] : 0),
            'billingcycle' => isset($params['billingcycle']) ? (string) $params['billingcycle'] : 'Monthly',
            'domain' => isset($params['domain']) ? (string) $params['domain'] : '',
            'paymentmethod' => isset($params['paymentmethod']) ? (string) $params['paymentmethod'] : '',
            'noemail' => !empty($params['noemail']) ? true : false,
            'skipconfig' => !empty($params['skipconfig']) ? true : false,
        ];
        foreach (['configoptions', 'customfields', 'pricingoverride', 'promocode', 'notes', 'invoiceid'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $payload[$key] = $params[$key];
            }
        }

        $result = Whmcs::api('AddOrder', $payload);
        if (!Whmcs::ok($result)) {
            Logger::error('WHMCS AddOrder failed.', [
                'message' => Whmcs::errorMessage($result),
                'client' => $payload['clientid'],
                'product' => $payload['pid'],
            ]);
            return ['order_id' => 0, 'invoice_id' => 0, 'result' => 'error',
                'message' => Whmcs::errorMessage($result)];
        }
        return [
            'order_id' => isset($result['orderid']) ? (int) $result['orderid'] : 0,
            'invoice_id' => isset($result['invoiceid']) ? (int) $result['invoiceid'] : 0,
            'service_id' => isset($result['serviceid']) ? (int) $result['serviceid'] : 0,
            'result' => 'success',
        ];
    }

    public function getOrder($orderId)
    {
        return Whmcs::row('tblorders', ['id' => (int) $orderId]);
    }

    public function getInvoice($invoiceId)
    {
        $result = Whmcs::api('GetInvoice', ['invoiceid' => (int) $invoiceId]);
        if (!Whmcs::ok($result)) {
            // Fall back to the table so a read still works when the API is
            // restricted; the paid decision never relies on this path alone.
            return Whmcs::row('tblinvoices', ['id' => (int) $invoiceId]);
        }
        return [
            'id' => isset($result['id']) ? (int) $result['id'] : (int) $invoiceId,
            'userid' => isset($result['userid']) ? (int) $result['userid'] : 0,
            'status' => isset($result['status']) ? (string) $result['status'] : '',
            'subtotal' => isset($result['subtotal']) ? (string) $result['subtotal'] : '0.00',
            'total' => isset($result['total']) ? (string) $result['total'] : '0.00',
            'credit' => isset($result['credit']) ? (string) $result['credit'] : '0.00',
            'datepaid' => isset($result['datepaid']) ? (string) $result['datepaid'] : '',
            'duedate' => isset($result['duedate']) ? (string) $result['duedate'] : '',
            'currency' => isset($result['currency']) ? (string) $result['currency'] : '',
            'paymentmethod' => isset($result['paymentmethod']) ? (string) $result['paymentmethod'] : '',
        ];
    }

    public function getInvoiceTransactions($invoiceId)
    {
        return Whmcs::rows('tbltransactions', ['invoiceid' => (int) $invoiceId]);
    }

    public function isInvoicePaid($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return false;
        }
        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice || empty($invoice['status'])) {
            return false;
        }
        $status = strtolower((string) $invoice['status']);
        if ($status === 'paid') {
            return true;
        }
        // A zero-total invoice is settled without a payment record.
        if (in_array($status, ['unpaid', 'draft'], true)) {
            $total = (float) (isset($invoice['total']) ? $invoice['total'] : 0);
            $credit = (float) (isset($invoice['credit']) ? $invoice['credit'] : 0);
            if ($total <= 0.0000001) {
                return true;
            }
            return $credit >= $total && $total > 0;
        }
        return false;
    }

    public function getClientUnpaidInvoices($clientId)
    {
        return Whmcs::rows('tblinvoices', ['userid' => (int) $clientId, 'status' => 'Unpaid']);
    }

    /* ------------------------------------------------------------- services */

    public function getService($serviceId)
    {
        return Whmcs::row('tblhosting', ['id' => (int) $serviceId]);
    }

    public function getClientServices($clientId, $status = null)
    {
        $where = ['userid' => (int) $clientId];
        if ($status !== null) {
            $where['domainstatus'] = $status;
        }
        return Whmcs::rows('tblhosting', $where);
    }

    public function getServiceCustomFields($serviceId, $productId)
    {
        // WHMCS stores product custom-field definitions separately from each
        // service's values. Never read fields belonging to another product.
        $definitions = Whmcs::rows('tblcustomfields', ['type' => 'product', 'relid' => (int) $productId]);
        $names = [];
        foreach ($definitions as $field) {
            $names[(int) $field['id']] = (string) $field['fieldname'];
        }
        $out = [];
        foreach (Whmcs::rows('tblcustomfieldsvalues', ['relid' => (int) $serviceId]) as $value) {
            $fieldId = isset($value['fieldid']) ? (int) $value['fieldid'] : 0;
            if (isset($names[$fieldId])) {
                $out[] = ['fieldname' => $names[$fieldId], 'value' => (string) $value['value']];
            }
        }
        return $out;
    }

    public function suspendService($serviceId, $reason = '')
    {
        return Whmcs::api('ModuleSuspend', [
            'serviceid' => (int) $serviceId,
            'suspendreason' => Str::clip((string) $reason, 200),
        ]);
    }

    public function unsuspendService($serviceId)
    {
        return Whmcs::api('ModuleUnsuspend', ['serviceid' => (int) $serviceId]);
    }

    public function terminateService($serviceId)
    {
        return Whmcs::api('ModuleTerminate', ['serviceid' => (int) $serviceId]);
    }

    /* -------------------------------------------------------------- domains */

    public function getClientDomains($clientId, $domain = null)
    {
        $where = ['userid' => (int) $clientId];
        if ($domain !== null && $domain !== '') {
            $where['domain'] = $domain;
        }
        return Whmcs::rows('tbldomains', $where);
    }

    /* ------------------------------------------------------------ messaging */

    public function sendTemplateEmail($clientId, $template, array $vars = [])
    {
        $params = [
            'messagename' => (string) $template,
            'id' => (int) $clientId,
            'type' => 'general',
        ];
        if ($vars) {
            $params['customtype'] = 'general';
            $params['customsubject'] = isset($vars['subject']) ? (string) $vars['subject'] : '';
            $params['custommessage'] = isset($vars['message']) ? (string) $vars['message'] : '';
        }
        return Whmcs::api('SendEmail', $params);
    }

    public function sendEmail($clientId, $subject, $bodyHtml, array $options = [])
    {
        return Whmcs::api('SendEmail', [
            'messagename' => isset($options['template']) ? (string) $options['template'] : 'General Notification',
            'id' => (int) $clientId,
            'customtype' => 'general',
            'customsubject' => Str::clip((string) $subject, 200),
            'custommessage' => (string) $bodyHtml,
        ]);
    }

    public function sendAdminEmail($subject, $bodyHtml, array $options = [])
    {
        return Whmcs::api('SendAdminEmail', [
            'customtype' => 'general',
            'customsubject' => Str::clip((string) $subject, 200),
            'custommessage' => (string) $bodyHtml,
        ]);
    }

    public function openTicket(array $params)
    {
        return Whmcs::api('OpenTicket', array_merge([
            'clientid' => 0,
            'deptid' => 1,
            'subject' => '',
            'message' => '',
            'priority' => 'Medium',
        ], $params));
    }

    public function addTicketReply($ticketId, $message, array $options = [])
    {
        return Whmcs::api('AddTicketReply', array_merge([
            'ticketid' => (int) $ticketId,
            'message' => (string) $message,
        ], $options));
    }

    public function logActivity($description, $clientId = 0)
    {
        Whmcs::logActivity($description, $clientId);
    }

    /* ---------------------------------------------------------- environment */

    public function getCurrencies()
    {
        $out = [];
        foreach (Whmcs::rows('tblcurrencies') as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'code' => isset($row['code']) ? (string) $row['code'] : '',
                'prefix' => isset($row['prefix']) ? (string) $row['prefix'] : '',
                'suffix' => isset($row['suffix']) ? (string) $row['suffix'] : '',
                'rate' => isset($row['rate']) ? (float) $row['rate'] : 1.0,
                'default' => isset($row['default']) && (string) $row['default'] === '1',
            ];
        }
        return $out;
    }

    public function getClientCurrency($clientId)
    {
        $client = $this->getClient($clientId);
        $currencyId = $client ? (int) $client['currency'] : 0;
        if ($currencyId > 0) {
            $row = Whmcs::row('tblcurrencies', ['id' => $currencyId]);
            if ($row) {
                return $row;
            }
        }
        $row = Whmcs::row('tblcurrencies', ['default' => '1']);
        return $row ?: ['id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'rate' => 1];
    }

    private function currencyRowByCode($code)
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return Whmcs::row('tblcurrencies', ['default' => '1']);
        }
        return Whmcs::row('tblcurrencies', ['code' => $code]);
    }

    public function config($setting, $default = null)
    {
        $row = Whmcs::row('tblconfiguration', ['setting' => (string) $setting]);
        if ($row && array_key_exists('value', $row)) {
            return $row['value'];
        }
        return $default;
    }

    public function getServers($type = null)
    {
        $where = $type === null ? [] : ['type' => (string) $type];
        return Whmcs::rows('tblservers', $where);
    }
}
