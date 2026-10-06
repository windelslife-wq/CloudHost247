<?php
/**
 * Event bus. Web-request hooks do exactly one thing: INSERT a durable row.
 * No model calls, no processing, no tool execution in the request path.
 * The cron drain picks rows up, marks them processed, and only then may
 * an agent observe them.
 */

namespace Ch247Ai\Event;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Logger;
use Ch247Ai\Core\Redaction;
use Ch247Ai\Core\Settings;

class EventBus
{
    /** Whitelisted hook names -> event types we ever react to. */
    const CAPTURE = [
        'InvoiceCreated' => 'invoice.created',
        'InvoicePaid' => 'invoice.paid',
        'InvoicePaymentReminder' => 'invoice.reminder',
        'InvoiceCancelled' => 'invoice.cancelled',
        'TicketOpen' => 'ticket.opened',
        'TicketUserReply' => 'ticket.replied',
        'TicketClose' => 'ticket.closed',
        'ClientAdd' => 'client.created',
        'ClientEdit' => 'client.edited',
        'ClientDelete' => 'client.deleted',
        'OrderAdd' => 'order.created',
        'PreModuleCreate' => 'service.creating',
        'PostModuleCreate' => 'service.created',
        'PostModuleSuspend' => 'service.suspended',
        'PostModuleTerminate' => 'service.terminated',
        'DomainRegister' => 'domain.registered',
        'DomainTransfer' => 'domain.transferred',
        'DomainRenew' => 'domain.renewed',
        'DailyCron' => 'cron.daily',
    ];

    /** INSERT-only capture from a hook. Never throws into the hook. */
    public static function capture($hookName, array $payload)
    {
        if (!isset(self::CAPTURE[$hookName])) {
            return 0;
        }
        try {
            $redacted = Redaction::clean($payload);
            // Remove big blobs we never want.
            foreach ($redacted as $key => $value) {
                if (is_string($value) && strlen($value) > 4000) {
                    $redacted[$key] = substr($value, 0, 4000) . '…[clipped]';
                }
            }
            return Db::insert('events', [
                'event_type' => self::CAPTURE[$hookName],
                'entity_type' => isset($payload['entity_type']) ? (string) $payload['entity_type'] : self::entityTypeFor($hookName),
                'entity_id' => isset($payload['entity_id']) ? (int) $payload['entity_id'] : self::entityIdFor($payload),
                'client_id' => isset($payload['client_id']) ? (int) $payload['client_id'] : (isset($payload['userid']) ? (int) $payload['userid'] : 0),
                'payload' => json_encode($redacted, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => Clock::now(),
                'processed_at' => null,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('event capture failed', ['hook' => $hookName]);
            return 0;
        }
    }

    /** Drain up to N pending events (cron only). */
    public static function drain($limit = 100)
    {
        if (!Settings::bool('service_enabled', true) || Settings::bool('kill_switch', false)) {
            return ['processed' => 0, 'failed' => 0, 'skipped' => 0];
        }
        $maxAttempts = max(1, Settings::int('event_max_attempts', 5));
        $rows = Db::all('events', ['status' => 'pending'], 'id ASC', (int) $limit);
        $processed = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if ((int) $row['attempts'] >= $maxAttempts) {
                Db::update('events', ['id' => (int) $row['id']], ['status' => 'dead', 'error' => 'max attempts reached']);
                $failed++;
                continue;
            }
            try {
                self::dispatch($row);
                Db::update('events', ['id' => (int) $row['id']], ['status' => 'processed', 'processed_at' => Clock::now(), 'attempts' => (int) $row['attempts'] + 1, 'error' => null]);
                $processed++;
            } catch (\Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $dead = $attempts >= $maxAttempts;
                Db::update('events', ['id' => (int) $row['id']], [
                    'attempts' => $attempts,
                    'status' => $dead ? 'dead' : 'pending',
                    'error' => Redaction::cleanString(substr($e->getMessage(), 0, 400)),
                ]);
                $failed++;
            }
        }
        return ['processed' => $processed, 'failed' => $failed, 'skipped' => 0];
    }

    /** Route one event to observers that declared interest. */
    protected static function dispatch(array $event)
    {
        $subscribers = \Ch247Ai\Event\Subscribers::forEventType($event['event_type']);
        foreach ($subscribers as $agentSlug) {
            if (!\Ch247Ai\Agents\AgentRegistry::isEnabled($agentSlug)) {
                continue;
            }
            if (\Ch247Ai\Agents\AgentRegistry::find($agentSlug) === null) {
                continue;
            }
            \Ch247Ai\Memory\Memory::rememberEvent($agentSlug, $event);
        }
        Audit::system('ai.event.processed', ['event_id' => (int) $event['id'], 'type' => $event['event_type'], 'subscribers' => $subscribers]);
    }

    public static function prune()
    {
        $days = Settings::int('retention_days_events', 60);
        if ($days > 0) {
            Db::exec('DELETE FROM ' . Db::t('events') . " WHERE status IN ('processed','dead') AND created_at < ?", [Clock::ago($days * 86400)]);
        }
        return true;
    }

    protected static function entityTypeFor($hookName)
    {
        $map = [
            'InvoiceCreated' => 'invoice', 'InvoicePaid' => 'invoice', 'InvoicePaymentReminder' => 'invoice', 'InvoiceCancelled' => 'invoice',
            'TicketOpen' => 'ticket', 'TicketUserReply' => 'ticket', 'TicketClose' => 'ticket',
            'ClientAdd' => 'client', 'ClientEdit' => 'client', 'ClientDelete' => 'client',
            'OrderAdd' => 'order',
            'PreModuleCreate' => 'service', 'PostModuleCreate' => 'service', 'PostModuleSuspend' => 'service', 'PostModuleTerminate' => 'service',
            'DomainRegister' => 'domain', 'DomainTransfer' => 'domain', 'DomainRenew' => 'domain',
            'DailyCron' => 'cron',
        ];
        return isset($map[$hookName]) ? $map[$hookName] : 'other';
    }
    protected static function entityIdFor(array $payload)
    {
        foreach (['invoiceid', 'ticketid', 'userid', 'orderid', 'serviceid', 'domainid', 'id'] as $key) {
            if (!empty($payload[$key])) {
                return (int) $payload[$key];
            }
        }
        return 0;
    }
}
