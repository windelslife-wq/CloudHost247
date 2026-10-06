<?php
/**
 * WHMCS hook wiring. ONE rule above all: hooks only INSERT durable event
 * rows — no model calls, no tool execution, no agent runs inside a web
 * request. The cron (cron/cloudhost247ai.php) drains the events table.
 *
 * "No model call in a web-request hook" is enforced twice: here by only
 * calling EventBus::capture(), and in AgentRuntime which refuses
 * source=hook outright.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use Ch247Ai\Event\EventBus;
use Ch247Ai\Tools\Bootstrap as ToolBootstrap;

// Register tool definitions so capture-side code and any admin rendering
// can see the registry (no tool ever RUNS from a hook).
ToolBootstrap::register();

/**
 * Attach one capture hook. $payload picks entity/client ids out of the hook
 * variables WHMCS passes; everything else is stored redacted.
 */
function ch247ai_attach_capture($hookName, $mapper)
{
    add_hook($hookName, 1, function ($vars) use ($hookName, $mapper) {
        try {
            EventBus::capture($hookName, $mapper($vars));
        } catch (\Throwable $e) {
            // never break a customer-facing request because of the AI layer
            if (function_exists('logActivity')) {
                logActivity('CloudHost247 AI event capture failed for ' . $hookName . ': ' . $e->getMessage());
            }
        }
    });
}

ch247ai_attach_capture('InvoiceCreated', function ($v) {
    return ['entity_type' => 'invoice', 'entity_id' => (int) ($v['invoiceid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0), 'status' => (string) ($v['status'] ?? '')];
});
ch247ai_attach_capture('InvoicePaid', function ($v) {
    return ['entity_type' => 'invoice', 'entity_id' => (int) ($v['invoiceid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0)];
});
ch247ai_attach_capture('InvoicePaymentReminder', function ($v) {
    return ['entity_type' => 'invoice', 'entity_id' => (int) ($v['invoiceid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0)];
});
ch247ai_attach_capture('InvoiceCancelled', function ($v) {
    return ['entity_type' => 'invoice', 'entity_id' => (int) ($v['invoiceid'] ?? 0)];
});
ch247ai_attach_capture('TicketOpen', function ($v) {
    return ['entity_type' => 'ticket', 'entity_id' => (int) ($v['ticketid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0), 'subject' => (string) ($v['subject'] ?? ''), 'deptid' => (int) ($v['deptid'] ?? 0)];
});
ch247ai_attach_capture('TicketUserReply', function ($v) {
    return ['entity_type' => 'ticket', 'entity_id' => (int) ($v['ticketid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0)];
});
ch247ai_attach_capture('TicketClose', function ($v) {
    return ['entity_type' => 'ticket', 'entity_id' => (int) ($v['ticketid'] ?? 0)];
});
ch247ai_attach_capture('ClientAdd', function ($v) {
    return ['entity_type' => 'client', 'entity_id' => (int) ($v['clientid'] ?? ($v['userid'] ?? 0))];
});
ch247ai_attach_capture('ClientEdit', function ($v) {
    return ['entity_type' => 'client', 'entity_id' => (int) ($v['clientid'] ?? ($v['userid'] ?? 0)), 'changed' => isset($v['changed']) && is_array($v['changed']) ? array_keys($v['changed']) : []];
});
ch247ai_attach_capture('ClientDelete', function ($v) {
    return ['entity_type' => 'client', 'entity_id' => (int) ($v['clientid'] ?? ($v['userid'] ?? 0))];
});
ch247ai_attach_capture('OrderAdd', function ($v) {
    return ['entity_type' => 'order', 'entity_id' => (int) ($v['orderid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0), 'status' => (string) ($v['status'] ?? '')];
});
ch247ai_attach_capture('PostModuleCreate', function ($v) {
    return ['entity_type' => 'service', 'entity_id' => (int) ($v['serviceid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0)];
});
ch247ai_attach_capture('PostModuleSuspend', function ($v) {
    return ['entity_type' => 'service', 'entity_id' => (int) ($v['serviceid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0)];
});
ch247ai_attach_capture('PostModuleTerminate', function ($v) {
    return ['entity_type' => 'service', 'entity_id' => (int) ($v['serviceid'] ?? 0)];
});
ch247ai_attach_capture('DomainRegister', function ($v) {
    return ['entity_type' => 'domain', 'entity_id' => (int) ($v['domainid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0), 'domain' => (string) ($v['domain'] ?? '')];
});
ch247ai_attach_capture('DomainTransfer', function ($v) {
    return ['entity_type' => 'domain', 'entity_id' => (int) ($v['domainid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0), 'domain' => (string) ($v['domain'] ?? '')];
});
ch247ai_attach_capture('DomainRenew', function ($v) {
    return ['entity_type' => 'domain', 'entity_id' => (int) ($v['domainid'] ?? 0), 'client_id' => (int) ($v['userid'] ?? 0), 'domain' => (string) ($v['domain'] ?? '')];
});
ch247ai_attach_capture('DailyCron', function ($v) {
    return ['entity_type' => 'cron', 'entity_id' => 0];
});

/* --------------------------------------------------- admin page styling -- */

add_hook('AdminAreaHeaderOutput', 1, function ($vars) {
    // Only inject on our own module pages.
    if (!isset($vars['modulelink']) || strpos((string) $vars['modulelink'], 'module=cloudhost247ai') === false) {
        if (!isset($_GET['module']) || $_GET['module'] !== 'cloudhost247ai') {
            return '';
        }
    }
    return '<style>'
        . '.ch247ai-stat{border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:14px;background:#fff;text-align:center}'
        . '.ch247ai-stat .number{font-size:26px;font-weight:700}'
        . '.ch247ai-chat{border:1px solid #e5e7eb;border-radius:8px;padding:14px;max-height:420px;overflow-y:auto;background:#fff}'
        . '.ch247ai-msg{margin-bottom:10px;padding:10px 12px;border-radius:8px;white-space:pre-wrap;word-break:break-word;font-size:13px}'
        . '.ch247ai-msg.user{background:#eef2ff;border-left:3px solid #6366f1}'
        . '.ch247ai-msg.assistant{background:#f0fdf4;border-left:3px solid #16a34a}'
        . '.ch247ai-msg.system{background:#f8fafc;border-left:3px solid #94a3b8;color:#64748b;font-size:12px}'
        . '.ch247ai-pre{background:#0f172a;color:#e2e8f0;border-radius:6px;padding:12px;font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:340px;overflow:auto}'
        . 'ul.ch247ai-tight{padding-left:18px}ul.ch247ai-tight li{margin-bottom:4px}'
        . '</style>';
});
