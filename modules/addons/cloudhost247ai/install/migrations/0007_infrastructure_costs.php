<?php
/** Manual provider-cost ledger; immutable invoice-level imports with dedupe. */

use Ch247Ai\Core\Migrator;

return [
    'id' => '0007_infrastructure_costs',
    'description' => 'Validated manual infrastructure cost imports',
    'up' => function () {
        $m = new Migrator();
        $m->createTable('infrastructure_costs', function ($b) {
            $b->id()
                ->string('provider', 96)
                ->string('invoice_reference', 128)
                ->date('period_start')
                ->date('period_end')
                ->string('amount', 32)
                ->string('currency', 3)
                ->integer('imported_by', false, 0)
                ->dateTime('created_at', true);
            $b->unique(['provider', 'invoice_reference'], 'infra_cost_provider_invoice');
            $b->index(['period_start', 'period_end']);
        });
    },
];
