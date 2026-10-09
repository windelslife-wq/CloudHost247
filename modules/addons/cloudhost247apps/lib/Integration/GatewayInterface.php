<?php
/**
 * CloudHost247 App Cloud — host application contract.
 *
 * Everything the platform needs from WHMCS (clients, products, orders, invoices,
 * services, domains, servers, email, tickets, activity log) is expressed here so
 * the services above stay host-agnostic and testable. WhmcsGateway implements it
 * against a live installation; FakeGateway implements it for the offline suite
 * and records every call so tests can assert what the platform would have done.
 *
 * Rule enforced by this boundary: WHMCS is the source of truth for identity and
 * money. This module never re-implements a customer record or an invoice, and it
 * never trusts a value that did not come back through this interface.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Integration;

interface GatewayInterface
{
    /* ------------------------------------------------------------ identity */

    /** @return array|null client record (id, email, firstname, lastname, status, currency, …) */
    public function getClient($clientId);

    /** @return array[] the client's contacts/sub-accounts */
    public function getClientContacts($clientId);

    /* ------------------------------------------------------------- catalog */

    /** @return array|null product record (id, name, type, gid, servermodule, …) */
    public function getProduct($productId);

    /** @return array[] products in a group */
    public function getProducts($groupId = null);

    /** @return array pricing for a product in a currency, per cycle */
    public function getProductPricing($productId, $currencyCode);

    /** @return array|null product group */
    public function getProductGroup($groupId);

    /* -------------------------------------------------------------- billing */

    /**
     * Create an order (and its invoice) through WHMCS.
     *
     * @param array $params clientid, pid, billingcycle, domain, configoptions,
     *                      customfields, paymentmethod, noinvoice, noemail,
     *                      invoiceref, notes
     * @return array{order_id:int, invoice_id:int, result:string}
     */
    public function createOrder(array $params);

    /** @return array|null order record */
    public function getOrder($orderId);

    /** @return array|null invoice record incl. status, total, subtotal, credit */
    public function getInvoice($invoiceId);

    /** @return array[] payments/transactions applied to an invoice */
    public function getInvoiceTransactions($invoiceId);

    /** Authoritative paid check — the only basis for provisioning. */
    public function isInvoicePaid($invoiceId);

    /** @return array[] unpaid invoices for a client */
    public function getClientUnpaidInvoices($clientId);

    /* ------------------------------------------------------------- services */

    /** @return array|null a hosting/service record (tblhosting) */
    public function getService($serviceId);

    /** @return array[] services for a client, optionally filtered by status */
    public function getClientServices($clientId, $status = null);

    /** Product-scoped WHMCS custom fields for one hosting service: fieldname/value pairs. */
    public function getServiceCustomFields($serviceId, $productId);

    public function suspendService($serviceId, $reason = '');

    public function unsuspendService($serviceId);

    public function terminateService($serviceId);

    /* -------------------------------------------------------------- domains */

    /** @return array[] domains owned by the client */
    public function getClientDomains($clientId, $domain = null);

    /* ------------------------------------------------------------ messaging */

    /** Send a WHMCS email template to a client. */
    public function sendTemplateEmail($clientId, $template, array $vars = []);

    /** Send a custom message (HTML) to a client. */
    public function sendEmail($clientId, $subject, $bodyHtml, array $options = []);

    /** Notify administrators. */
    public function sendAdminEmail($subject, $bodyHtml, array $options = []);

    public function openTicket(array $params);

    public function addTicketReply($ticketId, $message, array $options = []);

    public function logActivity($description, $clientId = 0);

    /* ---------------------------------------------------------- environment */

    /** @return array[] currencies: [id, code, prefix, suffix, rate] */
    public function getCurrencies();

    /** @return array the client's currency record */
    public function getClientCurrency($clientId);

    /** WHMCS configuration value (SystemURL, CompanyName, …) */
    public function config($setting, $default = null);

    /** @return array[] WHMCS server records (tblservers) */
    public function getServers($type = null);
}
