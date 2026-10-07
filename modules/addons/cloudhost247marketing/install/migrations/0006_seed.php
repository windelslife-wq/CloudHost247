<?php
/**
 * Seed: the shipped template library plus one default list.
 *
 * Idempotent — TemplateService::seed() upserts by slug and only refreshes
 * rows still marked is_system, so an operator's edits are never clobbered.
 */

use Ch247Mkt\Campaign\TemplateService;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;

return [
    'id' => '0006_seed',
    'description' => 'Seed the template library and a default mailing list',
    'up' => function () {
        TemplateService::seed();

        if (Db::count('lists', ['slug' => 'all-customers']) === 0) {
            Db::insert('lists', [
                'name'             => 'All customers',
                'slug'             => 'all-customers',
                'description'      => 'Default list. Subscribers added through imports or the client area land here unless another list is chosen.',
                'double_optin'     => 0,
                'subscriber_count' => 0,
                'created_by'       => 0,
                'created_at'       => Clock::now(),
                'updated_at'       => Clock::now(),
            ]);
        }
    },
];
