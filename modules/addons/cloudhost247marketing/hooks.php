<?php
/**
 * WHMCS hooks for CloudHost247 Marketing.
 *
 * Three jobs, nothing more:
 *   1. Keep subscriber records in step with WHMCS clients (email changes,
 *      closures) so unsubscribes and suppressions cannot be bypassed.
 *   2. Feed automation triggers from real WHMCS events.
 *   3. Provide a fallback worker tick on WHMCS's own cron, for installs
 *      without a dedicated system crontab entry.
 *
 * Every handler is wrapped: a marketing module must never be the reason an
 * order fails to provision.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Core\Logger;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Delivery\ComplianceService;
use Ch247Mkt\Delivery\DeliveryService;

/** Run $fn, swallow everything, log once. */
function ch247m_hook_guard($name, callable $fn)
{
    try {
        if (!Settings::bool('service_enabled', true)) {
            return;
        }
        $fn();
    } catch (\Throwable $e) {
        Logger::error('hook failed', ['hook' => $name, 'reason' => get_class($e), 'message' => $e->getMessage()]);
    }
}

/** Fire an automation trigger if the automation layer is installed. */
function ch247m_trigger($event, array $context)
{
    if (!class_exists(\Ch247Mkt\Automation\AutomationService::class)) {
        return;
    }
    \Ch247Mkt\Automation\AutomationService::trigger($event, $context);
}

/* ------------------------------------------------- client lifecycle -- */

add_hook('ClientEdit', 1, function ($vars) {
    ch247m_hook_guard('ClientEdit', function () use ($vars) {
        $clientId = (int) ($vars['userid'] ?? $vars['client_id'] ?? 0);
        if ($clientId <= 0) {
            return;
        }
        SubscriberService::syncFromClient($clientId, [
            'email'      => $vars['email'] ?? null,
            'first_name' => $vars['firstname'] ?? null,
            'last_name'  => $vars['lastname'] ?? null,
            'company'    => $vars['companyname'] ?? null,
        ]);
    });
});

add_hook('ClientAdd', 1, function ($vars) {
    ch247m_hook_guard('ClientAdd', function () use ($vars) {
        $clientId = (int) ($vars['userid'] ?? $vars['client_id'] ?? 0);
        if ($clientId <= 0) {
            return;
        }
        // WHMCS has its own marketing-email opt-in checkbox on signup. Honour
        // it exactly: no checkbox, no subscriber record. Silence is not consent.
        $optedIn = !empty($vars['marketingoptin']) || !empty($vars['marketing_emails_opt_in']);
        if (!$optedIn) {
            return;
        }
        SubscriberService::upsert([
            'email'          => $vars['email'] ?? '',
            'first_name'     => $vars['firstname'] ?? '',
            'last_name'      => $vars['lastname'] ?? '',
            'company'        => $vars['companyname'] ?? '',
            'client_id'      => $clientId,
            'consent_source' => 'whmcs_signup_optin',
            'ip'             => $_SERVER['REMOTE_ADDR'] ?? '',
        ], ['actor' => 'hook']);
        ch247m_trigger('client.created', ['client_id' => $clientId]);
    });
});

add_hook('ClientClose', 1, function ($vars) {
    ch247m_hook_guard('ClientClose', function () use ($vars) {
        $clientId = (int) ($vars['userid'] ?? $vars['client_id'] ?? 0);
        if ($clientId > 0) {
            SubscriberService::deactivateForClient($clientId, 'client closed');
        }
    });
});

add_hook('ClientDelete', 1, function ($vars) {
    ch247m_hook_guard('ClientDelete', function () use ($vars) {
        $clientId = (int) ($vars['userid'] ?? $vars['client_id'] ?? 0);
        if ($clientId > 0) {
            // Erase personal data but keep the suppression hash, so a deleted
            // client who had opted out is never silently re-added by an import.
            SubscriberService::forgetClient($clientId);
        }
    });
});

/* ----------------------------------------- marketing opt-in changes -- */

add_hook('ClientAreaSavedetails', 1, function ($vars) {
    ch247m_hook_guard('ClientAreaSavedetails', function () use ($vars) {
        $clientId = (int) ($vars['userid'] ?? 0);
        if ($clientId <= 0) {
            return;
        }
        if (array_key_exists('marketingoptin', $vars)) {
            if (!empty($vars['marketingoptin'])) {
                SubscriberService::optInClient($clientId, 'client area preference');
            } else {
                SubscriberService::optOutClient($clientId, 'client area preference');
            }
        }
    });
});

/* -------------------------------------------- automation triggers --- */

$ch247mTriggers = [
    'AfterShoppingCartCheckout' => ['order.placed',       ['userid', 'orderid']],
    'InvoicePaid'               => ['invoice.paid',       ['invoiceid']],
    'InvoiceCreated'            => ['invoice.created',    ['invoiceid']],
    'AfterModuleCreate'         => ['service.activated',  ['userid', 'serviceid', 'params']],
    'AfterModuleSuspend'        => ['service.suspended',  ['userid', 'serviceid', 'params']],
    'AfterModuleUnsuspend'      => ['service.unsuspended', ['userid', 'serviceid', 'params']],
    'AfterModuleTerminate'      => ['service.terminated', ['userid', 'serviceid', 'params']],
    'CancellationRequest'       => ['service.cancel_requested', ['relid', 'userid']],
    'TicketOpen'                => ['ticket.opened',      ['userid', 'ticketid']],
    'AffiliateActivation'       => ['affiliate.activated', ['userid', 'affiliateid']],
    'DomainTransferCompleted'   => ['domain.transferred', ['userid', 'domainid']],
];

foreach ($ch247mTriggers as $hookName => $definition) {
    [$event, $keys] = $definition;
    add_hook($hookName, 50, function ($vars) use ($hookName, $event, $keys) {
        ch247m_hook_guard($hookName, function () use ($vars, $event, $keys) {
            $context = [];
            foreach ($keys as $key) {
                if (isset($vars[$key]) && !is_array($vars[$key])) {
                    $context[$key] = $vars[$key];
                }
            }
            if (isset($vars['params']['clientsdetails']['userid'])) {
                $context['userid'] = (int) $vars['params']['clientsdetails']['userid'];
            }
            if (isset($vars['params']['domain'])) {
                $context['domain'] = (string) $vars['params']['domain'];
            }
            if (isset($vars['params']['serviceid'])) {
                $context['serviceid'] = (int) $vars['params']['serviceid'];
            }
            ch247m_trigger($event, $context);
        });
    });
}

/* ------------------------------------------------ abandoned carts --- */

add_hook('CartItemsTax', 1, function ($vars) {
    // Marker only: records that a logged-in client had items in a cart, which
    // the abandoned-cart automation reads. Never modifies the cart.
    ch247m_hook_guard('CartItemsTax', function () {
        $clientId = (int) ($_SESSION['uid'] ?? 0);
        if ($clientId > 0 && class_exists(\Ch247Mkt\Automation\AutomationService::class)) {
            \Ch247Mkt\Automation\AutomationService::touchCart($clientId);
        }
    });
    return [];
});

add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    ch247m_hook_guard('AfterShoppingCartCheckout.clearCart', function () use ($vars) {
        $clientId = (int) ($vars['userid'] ?? 0);
        if ($clientId > 0 && class_exists(\Ch247Mkt\Automation\AutomationService::class)) {
            \Ch247Mkt\Automation\AutomationService::clearCart($clientId);
        }
    });
});

/* ---------------------------------------------- fallback cron tick -- */

add_hook('AfterCronJob', 1, function () {
    ch247m_hook_guard('AfterCronJob', function () {
        if (!Settings::bool('cron_fallback_enabled', true)) {
            return;
        }
        // A dedicated crontab entry is strongly preferred — it runs every few
        // minutes rather than once a day. This exists so a fresh install still
        // drains its queue instead of silently sitting on it.
        $summary = DeliveryService::processBatch(null, DeliveryService::workerId());
        if ($summary['sent'] > 0 || $summary['failed'] > 0) {
            Logger::info('fallback cron tick', $summary);
        }
    });
});
