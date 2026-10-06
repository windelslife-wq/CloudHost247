<?php
/**
 * Core plumbing: settings, audit log, rate limits, notifications, lookup cache.
 */

use Chs\Core\Db;
use Chs\Core\Migrator;

return [
    'id' => '0001_core_tables',
    'description' => 'Settings, audit log, rate limiter, in-app notifications, lookup cache',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('settings', function ($t) {
            $t->id()->string('setting', 120)->unique('setting')->text('value')->timestamps();
        });

        $m->createTable('audit_log', function ($t) {
            $t->id()
                ->string('actor_type', 16)->index('actor_type')
                ->bigInteger('actor_id', false, 0)->index('actor_id')
                ->string('action', 120)->index('action')
                ->text('context')
                ->string('ip_hash', 64, false, '')
                ->dateTime('created_at')->index('created_at');
        });

        $m->createTable('rate_limits', function ($t) {
            $t->id()
                ->string('bucket', 96)
                ->string('action', 64)
                ->unique(['bucket', 'action'])
                ->integer('hits', false, 0)
                ->dateTime('window_start');
        });

        $m->createTable('notifications', function ($t) {
            $t->id()
                ->bigInteger('client_id', false, 0)->index('client_id')
                ->string('type', 48)->index('type')
                ->string('subject', 190)
                ->text('body')
                ->string('link', 255, false, '')
                ->dateTime('read_at')
                ->dateTime('created_at')->index('created_at');
        });

        $m->createTable('lookup_cache', function ($t) {
            $t->id()
                ->string('cache_key', 96)->unique('cache_key')
                ->string('kind', 32)->index('kind')
                ->longText('payload')
                ->dateTime('expires_at')->index('expires_at')
                ->dateTime('created_at');
        });
    },
];
