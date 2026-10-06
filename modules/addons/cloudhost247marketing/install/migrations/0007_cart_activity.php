<?php
/**
 * Cart activity — the minimum needed for an abandoned-cart automation.
 *
 * Deliberately NOT a copy of the cart. Storing what somebody nearly bought is
 * a liability with no payoff here; the automation only needs to know that a
 * client had a cart open, when it was last touched, and whether it converted.
 */

use Ch247Mkt\Core\Migrator;

return [
    'id' => '0007_cart_activity',
    'description' => 'Idle-cart tracking for the abandoned-cart automation trigger',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('cart_activity', function ($b) {
            $b->id()
                ->integer('client_id', false, 0)->unique('client_id')
                ->dateTime('last_seen_at', true)
                ->dateTime('converted_at', true)
                ->dateTime('notified_at', true)
                ->dateTime('created_at', true);
            $b->index('last_seen_at');
        });
    },
];
