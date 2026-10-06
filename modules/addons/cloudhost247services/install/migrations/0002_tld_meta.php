<?php
/**
 * TLD catalog metadata. Prices and availability are always read live from
 * WHMCS' own tbldomainpricing/tblpricing; this table holds the merchandising
 * layer administrators manage: categories, regions, badges and copy.
 */

use Chs\Core\Db;
use Chs\Core\Migrator;

return [
    'id' => '0002_tld_meta',
    'description' => 'TLD catalog metadata layer (admin-managed)',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('tld_meta', function ($t) {
            $t->id()
                ->string('tld', 63)->unique('tld')
                ->string('category', 48, false, 'generic')->index('category')
                ->string('region', 64, false, '')
                ->boolean('is_featured', 0)
                ->boolean('is_popular', 0)
                ->boolean('is_new', 0)
                ->boolean('is_trending', 0)
                ->string('badge', 24, false, '')
                ->string('tagline', 190, false, '')
                ->integer('sort_order', false, 100)
                ->boolean('visible', 1)
                ->timestamps();
        });
    },
];
