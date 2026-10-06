<?php
/**
 * Expert-services / hire-an-expert workflow: customer intake, quoting,
 * status tracking and a full message timeline.
 */

use Chs\Core\Migrator;

return [
    'id' => '0006_service_requests',
    'description' => 'Expert service requests + message timeline',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('service_requests', function ($t) {
            $t->id()
                ->bigInteger('client_id')->index('client_id')
                ->string('type', 40)->index('type')
                ->string('status', 24, false, 'requested')->index('status')
                ->string('title', 190)
                ->longText('brief')
                ->string('budget_range', 48, false, '')
                ->string('target_domain', 255, false, '')
                ->bigInteger('quote_minor', true)
                ->string('currency', 3, false, 'USD')
                ->bigInteger('assignee_admin_id', true)->index('assignee_admin_id')
                ->bigInteger('invoice_id', true)->index('invoice_id')
                ->timestamps();
        });

        $m->createTable('service_updates', function ($t) {
            $t->id()
                ->bigInteger('request_id')->index('request_id')
                ->foreign('request_id', 'service_requests', 'id', 'cascade')
                ->string('author_type', 8, false, 'client') // client|admin
                ->bigInteger('author_id', false, 0)
                ->string('status_to', 24, false, '')
                ->boolean('is_internal', 0) // admin-only notes
                ->longText('body')
                ->dateTime('created_at');
        });
    },
];
