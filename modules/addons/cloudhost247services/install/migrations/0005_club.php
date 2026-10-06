<?php
/**
 * Discount Domain Club: plans, per-TLD overrides and memberships.
 */

use Chs\Core\Migrator;

return [
    'id' => '0005_club',
    'description' => 'Discount Domain Club: plans, plan TLDs, memberships',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('club_plans', function ($t) {
            $t->id()
                ->string('slug', 64)->unique('slug')
                ->string('name', 96)
                ->text('description')
                ->bigInteger('price_minor', false, 0)
                ->string('currency', 3, false, 'USD')
                ->integer('period_months', false, 12)
                ->decimalColumn('discount_percent', 5, 2, false, 10)
                ->boolean('applies_register', 1)
                ->boolean('applies_renew', 1)
                ->boolean('applies_transfer', 0)
                ->integer('max_domains', false, 0) // 0 = unlimited
                ->string('status', 16, false, 'active')->index('status')
                ->integer('sort_order', false, 100)
                ->timestamps();
        });

        $m->createTable('club_plan_tlds', function ($t) {
            $t->bigInteger('plan_id')->index('plan_id')
                ->foreign('plan_id', 'club_plans', 'id', 'cascade')
                ->string('tld', 63)
                ->decimalColumn('discount_percent', 5, 2, true) // null = plan default
                ->unique(['plan_id', 'tld'], 'uq_plan_tld');
        });

        $m->createTable('club_memberships', function ($t) {
            $t->id()
                ->bigInteger('client_id')->index('client_id')
                ->bigInteger('plan_id')->index('plan_id')
                ->foreign('plan_id', 'club_plans', 'id', 'restrict')
                ->string('status', 16, false, 'pending')->index('status')
                ->bigInteger('invoice_id', true)->index('invoice_id')
                ->dateTime('starts_at')
                ->dateTime('expires_at')->index('expires_at')
                ->dateTime('cancelled_at')
                ->timestamps();
        });
    },
];
