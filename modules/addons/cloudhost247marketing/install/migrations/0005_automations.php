<?php
/**
 * Automations: event-triggered multi-step journeys.
 *
 * Schema lands now so campaigns, queue and events never need a breaking
 * migration later; the runtime that walks enrollments is a later build
 * session. Nothing fires until an automation row is explicitly enabled.
 */

use Ch247Mkt\Core\Migrator;

return [
    'id' => '0005_automations',
    'description' => 'Automation definitions, steps and per-subscriber enrollments',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('automations', function ($b) {
            $b->id()->string('name', 190)->string('slug', 190)->unique('slug')->text('description')
                // client.created | service.activated | invoice.overdue | domain.expiring | manual
                ->string('trigger_event', 60)
                ->text('trigger_filter')
                ->integer('enabled', false, 0)
                ->integer('reentry_allowed', false, 0)
                ->integer('enrolled_count', false, 0)->integer('completed_count', false, 0)
                ->integer('created_by', false, 0)
                ->dateTime('created_at', true)->dateTime('updated_at', true);
            $b->index('trigger_event');
            $b->index('enabled');
        });

        $m->createTable('automation_steps', function ($b) {
            $b->id()->integer('automation_id', false, 0)
                ->integer('position', false, 0)
                // wait | send_email | add_tag | remove_tag | add_to_list | end
                ->string('action', 30)
                ->integer('wait_seconds', false, 0)
                ->integer('campaign_id', false, 0)
                ->text('config')
                ->dateTime('created_at', true);
            $b->unique(['automation_id', 'position'], 'uq_as_automation_position');
        });

        $m->createTable('automation_enrollments', function ($b) {
            $b->id()->integer('automation_id', false, 0)->integer('subscriber_id', false, 0)
                ->integer('current_step', false, 0)
                // active | waiting | completed | cancelled | failed
                ->string('status', 20, false, 'active')
                ->dateTime('next_run_at', true)
                ->text('context')
                ->dateTime('enrolled_at', true)->dateTime('completed_at', true);
            $b->index(['status', 'next_run_at'], 'ix_ae_status_next');
            $b->index(['automation_id', 'subscriber_id'], 'ix_ae_automation_sub');
        });
    },
];
