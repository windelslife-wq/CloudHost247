<?php
/**
 * Campaigns + templates + per-recipient ledger.
 *
 * `design` holds the builder's block JSON (the editable source of truth);
 * `html` holds the compiled email. A campaign keeps its own copy of both so
 * editing a template later never rewrites history of something already sent.
 */

use Ch247Mkt\Core\Migrator;

return [
    'id' => '0003_campaigns',
    'description' => 'Templates, campaigns and the per-recipient delivery ledger',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('templates', function ($b) {
            $b->id()->string('name', 190)->string('slug', 190)->unique('slug')
                ->string('category', 60, false, 'custom')->text('description')
                ->longText('design')->longText('html')
                ->integer('is_system', false, 0)
                ->integer('created_by', false, 0)
                ->dateTime('created_at', true)->dateTime('updated_at', true)->dateTime('archived_at', true);
            $b->index('category');
        });

        $m->createTable('campaigns', function ($b) {
            $b->id()
                ->char('uid', 32)->unique('uid')
                ->string('name', 190)
                // campaign | automation | transactional
                ->string('type', 20, false, 'campaign')
                // draft | scheduled | sending | paused | sent | cancelled | failed
                ->string('status', 20, false, 'draft')
                ->string('subject', 255, true)->string('preheader', 255, true)
                ->string('from_name', 190, true)->string('from_email', 190, true)->string('reply_to', 190, true)
                ->integer('template_id', false, 0)
                ->longText('design')->longText('html')->longText('text_body')
                ->text('audience')          // {"lists":[],"segments":[],"exclude_lists":[],"exclude_segments":[]}
                ->string('timezone', 64, false, 'UTC')
                ->dateTime('scheduled_at', true)->dateTime('started_at', true)->dateTime('finished_at', true)
                ->dateTime('paused_at', true)->dateTime('cancelled_at', true)
                ->integer('total_recipients', false, 0)
                ->integer('count_sent', false, 0)
                ->integer('count_delivered', false, 0)
                ->integer('count_bounced', false, 0)
                ->integer('count_failed', false, 0)
                ->integer('count_opened', false, 0)
                ->integer('count_clicked', false, 0)
                ->integer('count_unsubscribed', false, 0)
                ->integer('count_complained', false, 0)
                ->integer('created_by', false, 0)
                ->dateTime('created_at', true)->dateTime('updated_at', true);
            $b->index('status');
            $b->index('type');
            $b->index('scheduled_at');
        });

        $m->createTable('campaign_recipients', function ($b) {
            $b->id()->integer('campaign_id', false, 0)->integer('subscriber_id', false, 0)
                ->string('email', 190)->char('email_hash', 64)
                ->string('name', 190, true)
                ->integer('client_id', false, 0)
                // pending | queued | sent | delivered | bounced | failed | skipped | cancelled
                ->string('status', 20, false, 'pending')
                ->char('token', 32)
                ->dateTime('sent_at', true)->dateTime('delivered_at', true)
                ->dateTime('first_opened_at', true)->integer('open_count', false, 0)
                ->dateTime('first_clicked_at', true)->integer('click_count', false, 0)
                ->dateTime('bounced_at', true)->string('bounce_type', 20, true)
                ->dateTime('unsubscribed_at', true)->dateTime('complained_at', true)
                ->string('skip_reason', 60, true)->text('failed_reason')
                ->dateTime('created_at', true);
            $b->unique(['campaign_id', 'email_hash'], 'uq_cr_campaign_email');
            $b->unique('token', 'uq_cr_token');
            $b->index(['campaign_id', 'status'], 'ix_cr_campaign_status');
            $b->index('subscriber_id');
        });
    },
];
