<?php
/**
 * Delivery engine: queue, event stream, suppression list, trackable links.
 *
 * The queue intentionally does NOT store a rendered body per recipient — at
 * 12k recipients that would be hundreds of megabytes of duplicated HTML.
 * Each row carries the identity and the campaign reference; DeliveryService
 * renders and personalises at dispatch time. One-off messages (test sends,
 * confirmations) carry an inline `payload` instead and have campaign_id = 0.
 */

use Ch247Mkt\Core\Migrator;

return [
    'id' => '0004_delivery',
    'description' => 'Email queue, event stream, suppressions and trackable links',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('email_queue', function ($b) {
            $b->id()
                ->integer('campaign_id', false, 0)->integer('recipient_id', false, 0)->integer('subscriber_id', false, 0)
                ->char('idempotency_key', 64)->unique('idempotency_key')
                ->string('to_email', 190)->string('to_name', 190, true)
                ->longText('payload')        // inline message for one-off sends
                // pending | processing | sent | failed | cancelled
                ->string('status', 20, false, 'pending')
                ->integer('priority', false, 5)
                ->integer('attempts', false, 0)
                ->text('last_error')
                ->dateTime('available_at', true)
                ->string('locked_by', 64, true)->dateTime('locked_until', true)
                ->string('provider_message_id', 190, true)
                ->dateTime('created_at', true)->dateTime('sent_at', true);
            $b->index(['status', 'available_at'], 'ix_q_status_available');
            $b->index('campaign_id');
            $b->index('locked_until');
        });

        $m->createTable('email_events', function ($b) {
            $b->id()
                ->integer('campaign_id', false, 0)->integer('recipient_id', false, 0)->integer('subscriber_id', false, 0)
                // sent | delivered | open | click | bounce | complaint | unsubscribe | failed
                ->string('event', 20)
                ->integer('link_id', false, 0)
                ->char('ip_hash', 64, true)->string('user_agent', 255, true)
                ->text('meta')
                ->dateTime('created_at', true);
            $b->index(['campaign_id', 'event'], 'ix_ev_campaign_event');
            $b->index('recipient_id');
            $b->index('created_at');
        });

        $m->createTable('suppressions', function ($b) {
            $b->id()
                ->char('email_hash', 64)->unique('email_hash')
                ->string('email', 190)
                // hard_bounce | complaint | unsubscribe | manual | invalid
                ->string('reason', 30)
                ->string('source', 60, true)
                ->integer('campaign_id', false, 0)
                ->text('notes')
                ->dateTime('created_at', true);
            $b->index('reason');
        });

        $m->createTable('links', function ($b) {
            $b->id()->integer('campaign_id', false, 0)
                ->text('url')->char('url_hash', 64)
                ->char('token', 24)->unique('token')
                ->integer('position', false, 0)
                ->integer('click_count', false, 0)->integer('unique_click_count', false, 0)
                ->dateTime('created_at', true);
            $b->unique(['campaign_id', 'url_hash'], 'uq_link_campaign_url');
        });
    },
];
