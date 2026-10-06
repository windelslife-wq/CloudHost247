<?php
/**
 * Domain Broker — the WHMCS boundary.
 *
 * Everything the module needs from the host billing system goes through this
 * one interface: clients, currencies, invoices, transactions, refunds,
 * domains, support tickets, email and the admin roster. Keeping the boundary
 * explicit is what makes the service testable without a WHMCS install, and
 * what guarantees the module never reaches around WHMCS to write financial
 * records directly into its tables.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Integration;

interface GatewayInterface
{
    /* ------------------------------------------------------------ clients */

    /** @return array|null WHMCS client record (id, firstname, email, …). */
    public function getClient($clientId);

    /** @return array{id:int,code:string,prefix:string,suffix:string,rate:float}|null */
    public function getClientCurrency($clientId);

    /** @return array list of active currencies. */
    public function getCurrencies();

    /** @return array|null */
    public function getDefaultCurrency();

    /** @return array|null currency row for an ISO code. */
    public function getCurrencyByCode($code);

    /* ----------------------------------------------------------- invoices */

    /**
     * Raise an invoice.
     *
     * @param array $params clientid, paymentmethod, duedate, notes,
     *                      items => [['description','amount','taxed']]
     * @return array{invoiceid:int, status:string}
     */
    public function createInvoice(array $params);

    /** @return array|null invoice record incl. status and total. */
    public function getInvoice($invoiceId);

    /** @return array transactions recorded against an invoice. */
    public function getInvoiceTransactions($invoiceId);

    /** Cancel / void an unpaid invoice. */
    public function cancelInvoice($invoiceId);

    /**
     * Issue a refund against a paid invoice through WHMCS.
     *
     * @return array{transid:string, amount:string}
     */
    public function refundInvoice($invoiceId, $amount, $gateway = '', $note = '');

    /* ------------------------------------------------------------ domains */

    /** @return array|null tbldomains row. */
    public function getDomain($domainId);

    /** @return array domains owned by a client. */
    public function getClientDomains($clientId, $domain = null);

    /* ------------------------------------------------------------ tickets */

    /** @return array{ticketid:int, tid:string}|null */
    public function openTicket(array $params);

    /** Append a reply to an existing ticket. */
    public function addTicketReply($ticketId, $message, array $options = []);

    /* ------------------------------------------------------------- comms  */

    /**
     * Send a client email through WHMCS so it is logged against the client
     * and respects the operator's mail configuration.
     *
     * @return bool
     */
    public function sendClientEmail($clientId, $subject, $bodyHtml, array $options = []);

    /** Send an email to a staff address. */
    public function sendAdminEmail($adminId, $subject, $bodyHtml, array $options = []);

    /* ------------------------------------------------------------- staff  */

    /** @return array active WHMCS administrators. */
    public function getAdmins();

    /** @return array|null */
    public function getAdmin($adminId);

    /* ------------------------------------------------------------- misc   */

    /** Write a line to the WHMCS activity log. */
    public function logActivity($description, $clientId = 0);

    /** ISO code of the system (default) currency. */
    public function systemCurrencyCode();
}
