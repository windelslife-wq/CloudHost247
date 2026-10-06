<?php
/**
 * Unified inbox: per-user conversation read markers plus admin labels.
 * Messages themselves live in WHMCS' ticket tables (the first channel);
 * additional channels register through the channel abstraction.
 */

use Chs\Core\Migrator;

return [
    'id' => '0009_inbox',
    'description' => 'Unified inbox: read markers + labels',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('inbox_reads', function ($t) {
            $t->id()
                ->string('user_type', 8, false, 'client')
                ->bigInteger('user_id')
                ->string('channel', 24, false, 'tickets')
                ->string('conversation_key', 64)
                ->bigInteger('last_seen_message_id', false, 0)
                ->dateTime('updated_at')
                ->unique(['user_type', 'user_id', 'channel', 'conversation_key'], 'uq_inbox_read');
        });

        $m->createTable('inbox_labels', function ($t) {
            $t->id()
                ->string('name', 48)->unique('name')
                ->string('colour', 12, false, '#3b82f6')
                ->dateTime('created_at');
        });

        $m->createTable('inbox_ticket_labels', function ($t) {
            $t->bigInteger('ticket_id')->index('ticket_id')
                ->bigInteger('label_id')->index('label_id')
                ->foreign('label_id', 'inbox_labels', 'id', 'cascade')
                ->unique(['ticket_id', 'label_id'], 'uq_ticket_label');
        });
    },
];
