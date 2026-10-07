<?php
/**
 * Audience: mailing lists, subscribers, list membership, saved segments.
 *
 * A subscriber is unique by email_hash (sha256 of the lower-cased address) so
 * dedupe and suppression matching never depend on collation. client_id links a
 * subscriber back to a WHMCS client when one exists, which is what lets the
 * segment builder query live WHMCS data instead of a stale CSV.
 */

use Ch247Mkt\Core\Migrator;

return [
    'id' => '0002_audience',
    'description' => 'Audience tables: lists, subscribers, list members, segments',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('lists', function ($b) {
            $b->id()->string('name', 190)->string('slug', 190)->unique('slug')->text('description')
                ->string('from_name', 190, true)->string('from_email', 190, true)->string('reply_to', 190, true)
                ->integer('double_optin', false, 0)
                ->integer('subscriber_count', false, 0)
                ->integer('created_by', false, 0)
                ->dateTime('created_at', true)->dateTime('updated_at', true)->dateTime('archived_at', true);
        });

        $m->createTable('subscribers', function ($b) {
            $b->id()
                ->string('email', 190)
                ->char('email_hash', 64)->unique('email_hash')
                ->string('first_name', 120, true)->string('last_name', 120, true)->string('company', 190, true)
                ->integer('client_id', false, 0)
                // subscribed | unconfirmed | unsubscribed | bounced | suppressed
                ->string('status', 20, false, 'subscribed')
                ->text('custom_fields')->text('tags')
                ->string('consent_source', 60, true)->dateTime('consent_at', true)->string('consent_ip', 64, true)
                ->string('confirm_token', 64, true)->dateTime('confirmed_at', true)
                ->dateTime('unsubscribed_at', true)
                ->integer('bounce_count', false, 0)->integer('soft_bounce_count', false, 0)->dateTime('last_bounce_at', true)
                ->dateTime('last_sent_at', true)
                ->dateTime('created_at', true)->dateTime('updated_at', true);
            $b->index('status');
            $b->index('client_id');
            $b->index('confirm_token');
        });

        $m->createTable('list_members', function ($b) {
            $b->id()->integer('list_id', false, 0)->integer('subscriber_id', false, 0)
                ->string('status', 20, false, 'subscribed')
                ->dateTime('subscribed_at', true)->dateTime('unsubscribed_at', true);
            $b->unique(['list_id', 'subscriber_id'], 'uq_lm_list_sub');
            $b->index('subscriber_id');
        });

        $m->createTable('segments', function ($b) {
            $b->id()->string('name', 190)->string('slug', 190)->unique('slug')->text('description')
                // subscribers | whmcs_clients
                ->string('source', 30, false, 'subscribers')
                ->text('definition')
                ->integer('cached_count', false, 0)->dateTime('cached_at', true)
                ->integer('created_by', false, 0)
                ->dateTime('created_at', true)->dateTime('updated_at', true);
            $b->index('source');
        });
    },
];
