<?php
/** Indexed, retention-bounded auth event projection from WHMCS activity logs. */

use Ch247Ai\Core\Migrator;

return [
    'id' => '0008_security_auth_events',
    'description' => 'Structured security login events collected from WHMCS activity logs',
    'up' => function () {
        $m = new Migrator();
        $m->createTable('security_auth_events', function ($b) {
            $b->id()->bigInteger('source_activity_id')
                ->string('event_type', 24)->integer('actor_id', true)
                ->char('ip_hash', 64, true)->dateTime('occurred_at')->dateTime('collected_at');
            $b->unique('source_activity_id', 'security_auth_source_id');
            $b->index(['event_type', 'occurred_at'], 'security_auth_type_time');
            $b->index(['actor_id', 'occurred_at'], 'security_auth_actor_time');
        });
        $m->createTable('security_collector_state', function ($b) {
            $b->id()->string('collector', 48)
                ->bigInteger('last_activity_id', false, 0)->dateTime('updated_at');
            $b->unique('collector', 'security_collector_name');
        });
    },
];
