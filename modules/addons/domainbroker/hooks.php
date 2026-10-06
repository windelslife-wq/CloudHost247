<?php
/**
 * Domain Broker — WHMCS hooks.
 *
 * The module plugs into the existing client area rather than replacing any of
 * it: a navigation entry, its own assets (loaded only on its own pages), an
 * optional homepage panel, and reactions to WHMCS billing events so an invoice
 * paid through the normal checkout immediately advances the acquisition.
 *
 * None of these hooks alter WHMCS' own domain registration or transfer
 * behaviour.
 *
 * @package DomainBroker
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use DomainBroker\Core\Actor;
use DomainBroker\Core\Db;
use DomainBroker\Core\Identity;
use DomainBroker\Core\Logger;
use DomainBroker\Core\Settings;
use DomainBroker\Services\NotificationService;
use DomainBroker\Services\PaymentService;
use DomainBroker\Services\RequestService;
use DomainBroker\Workflow\RequestStatus;

/* ------------------------------------------------------------- assets -- */

/**
 * Load the portal stylesheet and script only on Domain Broker pages so no
 * other page in the client area pays for them.
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    $module = isset($_GET['m']) ? (string) $_GET['m'] : '';
    $isLanding = isset($vars['templatefile']) && $vars['templatefile'] === 'domainbroker-landing';

    if ($module !== 'domainbroker' && !$isLanding) {
        return '';
    }

    $base = rtrim(isset($vars['WEB_ROOT']) ? $vars['WEB_ROOT'] : '', '/');
    $assets = $base . '/modules/addons/domainbroker/assets';

    return '<link rel="stylesheet" href="' . htmlspecialchars($assets . '/css/client.css', ENT_QUOTES, 'UTF-8') . '">'
        . '<link rel="stylesheet" href="' . htmlspecialchars($assets . '/css/client-rtl.css', ENT_QUOTES, 'UTF-8') . '">'
        . '<script src="' . htmlspecialchars($assets . '/js/domainbroker.js', ENT_QUOTES, 'UTF-8') . '" defer></script>';
});

/* --------------------------------------------------------- navigation -- */

/**
 * Add "Domain Broker" under the Domains menu, beside WHMCS' own domain tools,
 * so the service is discoverable where customers already look.
 */
add_hook('ClientAreaPrimaryNavbar', 1, function ($navbar) {
    if (!Settings::bool('service_enabled', true)) {
        return;
    }

    $parent = $navbar->getChild('Domains');
    if (!$parent) {
        $parent = $navbar->getChild('Services');
    }
    if (!$parent) {
        return;
    }

    $parent->addChild('DomainBroker', [
        'label' => 'Domain Broker Service',
        'uri' => 'index.php?m=domainbroker',
        'order' => 95,
        'icon' => 'fa-handshake-o',
    ]);
});

/**
 * A badge in the secondary sidebar when something is waiting on the customer.
 */
add_hook('ClientAreaSecondarySidebar', 1, function ($sidebar) {
    if (!Settings::bool('service_enabled', true) || empty($_SESSION['uid'])) {
        return;
    }
    if (!isset($_GET['m']) || $_GET['m'] !== 'domainbroker') {
        return;
    }

    try {
        $actor = Identity::current();
        if (!$actor->isCustomer()) {
            return;
        }
        $summary = (new RequestService())->customerSummary($actor);
    } catch (\Throwable $e) {
        return;
    }

    $panel = $sidebar->addChild('DomainBrokerSummary', [
        'label' => 'Acquisitions',
        'order' => 10,
    ]);
    $panel->addChild('db-active', [
        'label' => 'Active: ' . (int) $summary['active'],
        'uri' => 'index.php?m=domainbroker&action=requests',
    ]);
    $panel->addChild('db-offers', [
        'label' => 'Offers awaiting you: ' . (int) $summary['offers_awaiting'],
        'uri' => 'index.php?m=domainbroker&action=requests',
    ]);
    $panel->addChild('db-new', [
        'label' => 'New request',
        'uri' => 'index.php?m=domainbroker&action=new',
    ]);
});

/**
 * Offer the service from the domain search results when a domain is taken —
 * the moment a customer discovers they cannot register what they wanted.
 */
add_hook('ClientAreaPageDomainChecker', 1, function ($vars) {
    if (!Settings::bool('service_enabled', true)) {
        return [];
    }
    return [
        'domainBrokerEnabled' => true,
        'domainBrokerUrl' => 'index.php?m=domainbroker&action=new',
    ];
});

/* ----------------------------------------------------- billing events -- */

/**
 * An acquisition invoice paid through the normal WHMCS checkout must move the
 * acquisition forward immediately — the customer should not have to wait for
 * the cron. The payment service re-reads the invoice from WHMCS rather than
 * trusting the hook payload.
 */
add_hook('InvoicePaid', 1, function ($vars) {
    $invoiceId = isset($vars['invoiceid']) ? (int) $vars['invoiceid'] : 0;
    if ($invoiceId <= 0) {
        return;
    }

    try {
        $payment = Db::first('payments', ['whmcs_invoice_id' => $invoiceId]);
        if (!$payment) {
            return; // not one of ours — leave WHMCS alone
        }
        $payments = new PaymentService();
        $payments->syncWithBilling(Actor::system('Billing hook'), (int) $payment['id']);
    } catch (\Throwable $e) {
        Logger::error('Domain Broker could not process a paid invoice.', [
            'invoice' => $invoiceId,
            'message' => $e->getMessage(),
        ]);
    }
});

/**
 * Record a failed payment attempt so the risk engine can see repeated
 * failures, and so the customer is told rather than left guessing.
 */
add_hook('InvoicePaymentReminder', 1, function ($vars) {
    return; // reminders are WHMCS' own; nothing to do here
});

add_hook('LogTransaction', 1, function ($vars) {
    if (empty($vars['invoiceid']) || empty($vars['result'])) {
        return;
    }
    if (stripos((string) $vars['result'], 'success') !== false) {
        return;
    }

    try {
        $payment = Db::first('payments', ['whmcs_invoice_id' => (int) $vars['invoiceid']]);
        if (!$payment) {
            return;
        }
        (new PaymentService())->recordFailure(
            Actor::system('Billing hook'),
            (int) $payment['id'],
            'Gateway reported: ' . substr((string) $vars['result'], 0, 190)
        );
    } catch (\Throwable $e) {
        Logger::debug('Domain Broker could not record a payment failure.', ['message' => $e->getMessage()]);
    }
});

/**
 * Cancelling an invoice that backs a pending acquisition payment should not
 * leave the acquisition stuck at "payment pending" forever.
 */
add_hook('InvoiceCancelled', 1, function ($vars) {
    $invoiceId = isset($vars['invoiceid']) ? (int) $vars['invoiceid'] : 0;
    if ($invoiceId <= 0) {
        return;
    }

    try {
        $payment = Db::first('payments', ['whmcs_invoice_id' => $invoiceId]);
        if (!$payment) {
            return;
        }
        (new PaymentService())->recordFailure(
            Actor::system('Billing hook'),
            (int) $payment['id'],
            'The invoice was cancelled in WHMCS.'
        );
    } catch (\Throwable $e) {
        Logger::debug('Domain Broker could not handle a cancelled invoice.', ['message' => $e->getMessage()]);
    }
});

/* ------------------------------------------------------ client events -- */

/**
 * Closing a client account must not silently orphan live acquisitions: flag
 * them for an administrator instead of deleting anything.
 */
add_hook('ClientClose', 1, function ($vars) {
    $clientId = isset($vars['userid']) ? (int) $vars['userid'] : 0;
    if ($clientId <= 0) {
        return;
    }

    try {
        $live = Db::fetch('requests', [
            'client_id' => $clientId,
            'deleted_at' => null,
            'status' => ['notin', RequestStatus::TERMINAL],
        ]);
        if (!$live) {
            return;
        }
        (new NotificationService())->notify(
            'risk.flagged',
            $live[0],
            NotificationService::AUDIENCE_ADMIN,
            ['summary' => 'A client with ' . count($live) . ' live acquisition(s) has been closed.']
        );
    } catch (\Throwable $e) {
        Logger::debug('Domain Broker could not react to a client closure.', ['message' => $e->getMessage()]);
    }
});

/* ------------------------------------------------------------- admin -- */

/**
 * Admin-area styling for the module's own pages only.
 */
add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    if (!isset($_GET['module']) || $_GET['module'] !== 'domainbroker') {
        return '';
    }
    return '<link rel="stylesheet" href="../modules/addons/domainbroker/assets/css/admin.css">';
});

/**
 * Surface the desk's workload on the admin homepage.
 */
add_hook('AdminHomeWidgets', 1, function () {
    return new DomainBrokerAdminWidget();
});

/**
 * A small operational widget: what needs a human today.
 */
class DomainBrokerAdminWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'Domain Broker';
    protected $description = 'Acquisition desk workload';
    protected $weight = 60;
    protected $columns = 1;
    protected $cache = false;

    public function getData()
    {
        try {
            return [
                'unassigned' => Db::count('requests', [
                    'assigned_broker_id' => null,
                    'deleted_at' => null,
                    'status' => ['in', [RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW]],
                ]),
                'review' => Db::count('requests', ['manual_review' => 1, 'deleted_at' => null]),
                'payments' => Db::count('payments', [
                    'type' => 'acquisition',
                    'status' => ['in', ['pending', 'invoice_generated']],
                ]),
                'disputes' => Db::count('disputes', ['status' => 'open']),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function generateOutput($data)
    {
        if (!$data) {
            return '<div class="widget-content-padded">Domain Broker is not installed yet.</div>';
        }

        $link = 'addonmodules.php?module=domainbroker';
        $rows = [
            ['Unassigned requests', $data['unassigned'], $link . '&action=requests'],
            ['Held for manual review', $data['review'], $link . '&action=risk'],
            ['Payments outstanding', $data['payments'], $link . '&action=payments'],
            ['Open disputes', $data['disputes'], $link . '&action=disputes'],
        ];

        $html = '<div class="widget-content-padded"><table class="table table-condensed">';
        foreach ($rows as $row) {
            $html .= '<tr><td><a href="' . htmlspecialchars($row[2], ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8') . '</a></td>'
                . '<td class="text-right"><strong>' . (int) $row[1] . '</strong></td></tr>';
        }
        return $html . '</table></div>';
    }
}
