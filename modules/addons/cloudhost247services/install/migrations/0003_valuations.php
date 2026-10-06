<?php
/**
 * Domain valuation snapshots — the full factor breakdown is stored with every
 * estimate so the engine is auditable and upgradeable.
 */

use Chs\Core\Migrator;

return [
    'id' => '0003_valuations',
    'description' => 'Domain valuation history',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('valuations', function ($t) {
            $t->id()
                ->bigInteger('client_id', true)->index('client_id')
                ->string('domain', 255)->index('domain')
                ->string('sld', 63)
                ->string('tld', 63)->index('tld')
                ->bigInteger('estimate_minor', false, 0)
                ->string('currency', 3, false, 'USD')
                ->string('engine', 32, false, 'rules')
                ->string('engine_version', 16, false, '1')
                ->integer('score', false, 0)
                ->longText('breakdown')
                ->string('ip_hash', 64, false, '')
                ->dateTime('created_at')->index('created_at');
        });
    },
];
