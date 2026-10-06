<?php
/**
 * Privacy consent decisions. The record is deliberately pseudonymous: the
 * browser supplies a random consent id and the server stores only its hash.
 * Signed-in client ids are stored when available so an operator can honour a
 * verified account's privacy request without storing the raw browser id.
 */

use Chs\Core\Migrator;

return [
    'id' => '0011_consent_records',
    'description' => 'Pseudonymous cookie consent decision history',
    'up' => function () {
        $m = new Migrator();
        $m->createTable('consent_records', function ($t) {
            $t->id()
                ->char('consent_hash', 64)
                ->string('status', 12)
                ->string('categories', 128)
                ->string('policy_version', 64)
                ->string('language', 16, false, '')
                ->bigInteger('client_id', false, 0)
                ->dateTime('recorded_at', false)
                ->index('consent_hash')
                ->index('client_id')
                ->index('recorded_at');
        });
    },
];
