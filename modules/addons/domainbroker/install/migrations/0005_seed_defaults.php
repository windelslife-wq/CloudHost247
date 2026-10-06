<?php
/**
 * Domain Broker — 0005: seed the RBAC matrix and a starting fee rule.
 *
 * The fee rule seeded here is *configuration*, not a hardcoded price: its
 * values come from the seed_fee_* settings (which an operator can set through
 * the environment or the addon configuration before activation) and every
 * field is editable afterwards in Admin → Domain Broker → Fees. If the
 * operator prefers to define their own schedule they can simply deactivate it.
 *
 * @package DomainBroker
 */

use DomainBroker\Core\Clock;
use DomainBroker\Core\Db;
use DomainBroker\Core\Migrator;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;

return [
    'id' => '0005_seed_defaults',
    'description' => 'Seed role/permission matrix and the initial editable fee schedule.',
    'up' => function (Migrator $m) {
        Rbac::seedMatrix();

        $now = Clock::now();

        if (Db::count('fees', []) === 0) {
            Db::insert('fees', [
                'code'        => 'standard',
                'name'        => 'Standard brokerage commission',
                'description' => 'Default commission applied to the agreed acquisition price. '
                               . 'Edit or deactivate this rule and add your own schedule at any time.',
                'active'      => 1,
                'priority'    => 100,
                'currency'    => null,
                'applies_min_minor' => 0,
                'applies_max_minor' => 0,
                'tld_scope'   => null,
                'calculation' => 'percentage',
                'fixed_minor' => 0,
                'percentage'  => Settings::string('seed_fee_percentage', '10'),
                'tiers'       => null,
                'min_fee_minor' => Settings::int('seed_fee_min_minor', 0),
                'max_fee_minor' => Settings::int('seed_fee_max_minor', 0),
                'tax_rate'    => null,
                'tax_inclusive' => 0,
                'use_whmcs_tax' => 1,
                'promo_code'  => null,
                'discount_percentage' => null,
                'discount_fixed_minor' => 0,
                'valid_from'  => null,
                'valid_to'    => null,
                'created_by'  => 'installer',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    },
];
