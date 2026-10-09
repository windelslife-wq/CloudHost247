<?php
/**
 * The seam between this module and the host platform (WHMCS). Everything the
 * module needs from the outside world — invoices, email, availability, client
 * records — goes through this interface, so the host can evolve and tests can
 * record calls without a live WHMCS install.
 *
 * @package Chs\Providers\Whmcs
 */

namespace Chs\Providers\Whmcs;

interface GatewayInterface
{
    /* ------------------------------------------------------------ clients -- */

    public function clientExists($clientId);

    /** @return string ISO currency code for the client (or platform default) */
    public function clientCurrency($clientId);

    /** @return string platform default ISO currency code */
    public function defaultCurrency();

    /** @return array{id:int,name:string,email:string}|null */
    public function clientSummary($clientId);

    /* ------------------------------------------------------------ billing -- */

    /**
     * Create an invoice for the client.
     *
     * @param array[] $items each: ['description' => string, 'amount_minor' => int, 'taxed' => bool]
     * @return int new invoice id
     */
    public function createInvoice($clientId, array $items, $currency, $dueDays, $notes = '');

    public function cancelInvoice($invoiceId);

    /** @return string|null Paid|Unpaid|Cancelled|Refunded|... */
    public function invoiceStatus($invoiceId);

    /** @return string|null public URL for the invoice (client view) */
    public function invoiceUrl($invoiceId);

    /* ------------------------------------------------------------- email -- */

    public function sendEmail($clientId, $subject, $plainBody);

    /* ------------------------------------------------------------ domains -- */

    /**
     * Live availability check through the platform's configured lookup
     * provider. @return array{status:string,available:bool}
     */
    public function domainAvailability($domain);

    /**
     * Domains currently held by the client in the platform, e.g.
     * [['domain'=>..., 'expiry'=>..., 'status'=>...], ...]
     */
    public function clientDomains($clientId);

    /* --------------------------------------------------------- ticketing -- */

    /**
     * Conversations for the unified inbox (tickets channel).
     * @return array[]
     */
    public function inboxConversations($clientId, $limit = 100);

    /** @return array[] messages oldest→newest */
    public function inboxMessages($ticketId);

    /** @return int new message/reply id */
    public function inboxReply($ticketId, $body, $authorType, $authorId);

    /** @return array[] admin-side conversation list */
    public function inboxAdminList($limit = 200, $onlyUnassigned = false);

    /** Assign to an admin (ticket flag). */
    public function inboxAssign($ticketId, $adminId);

    public function inboxSetStatus($ticketId, $status);

    /* ------------------------------------------- servers, products, orders -- */

    /**
     * Visible WHMCS server products (type = server).
     * @return array[] rows: id, name, description, paytype, servertype, group_name,
     *                 price_minor (monthly or onetime in the default currency)
     */
    public function serverProducts();

    /** @return array|null tblproducts row (id, name, type, paytype, servertype, hidden…) */
    public function productDetail($productId);

    /**
     * Price map for one product in one currency.
     * @return array{setup_minor:int, cycles:array<string,int>} cycle => amount_minor
     */
    public function productPricing($productId, $currency);

    /**
     * Place a real WHMCS order (AddOrder): order + invoice + pending service.
     * @return array{order_id:int, invoice_id:int}
     */
    public function createOrder($clientId, $productId, $billingCycle, $hostname, $paymentMethod = '');

    /** @return array|null the tblhosting service attached to an invoice */
    public function serviceForInvoice($invoiceId);

    /** @return array|null hosting row + product_name + product_type */
    public function hostingDetail($hostingId);

    /** @return array[] the client's services (hosting rows + product_name) */
    public function clientServices($clientId);

    /**
     * Guarded service update (whitelisted columns only: domain, server,
     * status, nextduedate).
     */
    public function updateService($hostingId, array $fields);

    /**
     * Create a WHMCS server record (tblservers) — the provider connection
     * record for a provisioned server. @return int new server id
     */
    public function createServerRecord(array $fields);

    /** Guarded tblservers update (whitelisted columns only). */
    public function updateServerRecord($serverId, array $fields);

    /** @return array|null tblservers row */
    public function serverRecord($serverId);
}
