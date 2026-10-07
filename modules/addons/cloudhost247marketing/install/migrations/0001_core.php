<?php
/** Module infrastructure: settings, rate limits, role permissions, audit chain. */

use Ch247Mkt\Core\Migrator;

return [
    'id' => '0001_core',
    'description' => 'Core tables: settings, rate limits, role permissions, hash-chained audit log',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('settings', function ($b) {
            $b->id()->string('setting', 100)->unique('setting')->text('value')->dateTime('updated_at', true);
        });

        $m->createTable('rate_limits', function ($b) {
            $b->id()->string('bucket', 120)->string('action', 80)->integer('hits', false, 0)->dateTime('window_start');
            $b->unique(['bucket', 'action'], 'uq_rl_bucket_action');
        });

        $m->createTable('role_permissions', function ($b) {
            $b->id()->integer('role_id', false, 0)->string('permission', 60);
            $b->unique(['role_id', 'permission'], 'uq_rp_role_perm');
        });

        $m->createTable('audit_log', function ($b) {
            $b->id()->string('actor_type', 10)->integer('actor_id', false, 0)->string('actor_label', 190, true)
                ->string('action', 80)->string('entity_type', 60, false, 'system')->integer('entity_id', false, 0)
                ->text('context')->char('prev_hash', 64, true)->char('record_hash', 64, true)
                ->dateTime('created_at', true);
            $b->index('action');
            $b->index(['entity_type', 'entity_id'], 'ix_audit_entity');
        });
    },
];
