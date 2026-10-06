<?php
/**
 * Event → agent subscriptions. This mapping is the whole "which agent reacts
 * to what" configuration; it lives in code + the subscribers table, never in
 * prompts. Phase 1 reactions are memory-appends only (no model calls).
 */

namespace Ch247Ai\Event;

class Subscribers
{
    const MAP = [
        'invoice.created' => ['ledger_agent', 'billing_reconciliation'],
        'invoice.paid' => ['ledger_agent', 'revenue_analyst', 'billing_reconciliation'],
        'invoice.reminder' => ['collections_agent'],
        'invoice.cancelled' => ['ledger_agent', 'billing_reconciliation'],
        'ticket.opened' => ['resolution_pro'],
        'ticket.replied' => ['resolution_pro'],
        'ticket.closed' => ['resolution_pro'],
        'client.created' => ['customer_intelligence'],
        'client.edited' => ['customer_intelligence'],
        'client.deleted' => [],
        'order.created' => ['ledger_agent', 'billing_reconciliation'],
        'service.creating' => [],
        'service.created' => ['customer_intelligence'],
        'service.suspended' => ['customer_intelligence', 'collections_agent'],
        'service.terminated' => [],
        'domain.registered' => ['dns_domain_agent'],
        'domain.transferred' => ['dns_domain_agent'],
        'domain.renewed' => ['dns_domain_agent'],
        'cron.daily' => ['briefing_composer', 'ssl_guardian', 'dns_domain_agent', 'revenue_analyst', 'collections_agent', 'billing_reconciliation', 'ledger_agent'],
    ];

    /** @return string[] agent slugs subscribed to an event type */
    public static function forEventType($eventType)
    {
        $eventType = (string) $eventType;
        if (isset(self::MAP[$eventType])) {
            return self::MAP[$eventType];
        }
        try {
            $rows = \Ch247Ai\Core\Db::all('subscribers', ['event_type' => $eventType]);
            $out = [];
            foreach ($rows as $row) {
                $out[] = (string) $row['agent'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Sync the default map into the subscribers table (additive). */
    public static function sync()
    {
        $db = \Ch247Ai\Core\Db::class;
        foreach (self::MAP as $eventType => $agents) {
            foreach ($agents as $agent) {
                if (!$db::count('subscribers', ['event_type' => $eventType, 'agent' => $agent])) {
                    $db::insert('subscribers', ['event_type' => $eventType, 'agent' => $agent]);
                }
            }
        }
    }
}
