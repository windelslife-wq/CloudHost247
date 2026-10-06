<?php
/**
 * Seed operational defaults: one starter club plan, starter TLD merchandising
 * for the extensions almost every registrar carries, and inbox labels.
 *
 * Everything seeded here is editable or deletable from the admin console —
 * this is starter content, not hard-coded product data.
 */

use Chs\Core\Db;
use Chs\Core\Clock;
use Chs\Core\Money;

return [
    'id' => '0010_seed',
    'description' => 'Starter club plan, TLD merchandising, inbox labels',
    'up' => function () {
        $now = Clock::now();

        if (Db::count('club_plans') === 0) {
            $planId = Db::insert('club_plans', [
                'slug'             => 'domain-club',
                'name'             => 'Discount Domain Club',
                'description'      => 'Member pricing on registrations and renewals across the eligible TLD catalogue. The more domains you hold, the more the membership saves.',
                'price_minor'      => 9999,
                'currency'         => 'USD',
                'period_months'    => 12,
                'discount_percent' => 10,
                'applies_register' => 1,
                'applies_renew'    => 1,
                'applies_transfer' => 0,
                'max_domains'      => 0,
                'status'           => 'active',
                'sort_order'       => 10,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $tlds = ['com', 'net', 'org', 'info', 'biz', 'co', 'io', 'dev', 'app', 'shop', 'store', 'online', 'site'];
            foreach ($tlds as $tld) {
                Db::insert('club_plan_tlds', [
                    'plan_id' => $planId,
                    'tld'     => $tld,
                    'discount_percent' => null,
                ]);
            }
        }

        if (Db::count('tld_meta') === 0) {
            $seed = [
                // tld, category, region, popular, trending, is_new, featured, tagline
                ['com',   'popular', '', 1, 0, 0, 1, 'The world\'s default extension.'],
                ['net',   'popular', '', 1, 0, 0, 0, 'A trusted alternative to .com.'],
                ['org',   'popular', '', 1, 0, 0, 0, 'The home of communities and causes.'],
                ['io',    'tech',    'British Indian Ocean Territory', 1, 1, 0, 1, 'The developer favourite.'],
                ['co',    'popular', 'Colombia', 1, 1, 0, 0, 'Short, global, company-ready.'],
                ['ai',    'tech',    'Anguilla', 1, 1, 0, 1, 'The address of the AI industry.'],
                ['dev',   'tech',    '', 0, 1, 0, 0, 'For builders and shipping teams.'],
                ['app',   'tech',    '', 1, 1, 0, 0, 'Secure by default — HTTPS required.'],
                ['shop',  'commerce', '', 0, 1, 0, 0, 'Purpose-built for storefronts.'],
                ['store', 'commerce', '', 0, 1, 0, 0, 'Made for selling online.'],
                ['online','generic', '', 0, 0, 0, 0, 'Universal and memorable.'],
                ['site',  'generic', '', 0, 0, 0, 0, 'Works for any kind of site.'],
                ['xyz',   'generic', '', 0, 1, 0, 0, 'The next-generation generic.'],
                ['tech',  'tech',    '', 0, 0, 0, 0, 'Signal what you do instantly.'],
                ['cloud', 'tech',    '', 0, 1, 1, 0, 'Native to the cloud era.'],
                ['uk',    'country', 'United Kingdom', 1, 0, 0, 0, 'The British web address.'],
                ['co.uk', 'country', 'United Kingdom', 1, 0, 0, 0, 'Britain\'s business standard.'],
                ['de',    'country', 'Germany', 1, 0, 0, 0, 'Europe\'s largest market.'],
                ['fr',    'country', 'France', 0, 0, 0, 0, 'The French internet identity.'],
                ['ca',    'country', 'Canada', 0, 0, 0, 0, 'Trusted across Canada.'],
                ['com.au','country', 'Australia', 0, 0, 0, 0, 'Australia\'s business address.'],
                ['in',    'country', 'India', 0, 1, 0, 0, 'India\'s national extension.'],
                ['us',    'country', 'United States', 0, 0, 0, 0, 'The American namespace.'],
                ['eu',    'country', 'European Union', 0, 0, 0, 0, 'One address for all of Europe.'],
            ];
            $i = 10;
            foreach ($seed as $row) {
                list($tld, $cat, $region, $popular, $trending, $new, $featured, $tagline) = $row;
                Db::insert('tld_meta', [
                    'tld'         => $tld,
                    'category'    => $cat,
                    'region'      => $region,
                    'is_popular'  => $popular,
                    'is_trending' => $trending,
                    'is_new'      => $new,
                    'is_featured' => $featured,
                    'badge'       => $new ? 'NEW' : ($trending ? 'TRENDING' : ($popular ? 'POPULAR' : '')),
                    'tagline'     => $tagline,
                    'sort_order'  => $i,
                    'visible'     => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
                $i += 10;
            }
        }

        if (Db::count('inbox_labels') === 0) {
            foreach ([
                ['VIP', '#d97706'],
                ['Billing', '#059669'],
                ['Technical', '#2563eb'],
                ['Domain transfer', '#7c3aed'],
            ] as $label) {
                Db::insert('inbox_labels', [
                    'name'       => $label[0],
                    'colour'     => $label[1],
                    'created_at' => $now,
                ]);
            }
        }
    },
];
