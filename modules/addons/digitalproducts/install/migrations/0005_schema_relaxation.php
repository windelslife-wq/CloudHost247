<?php
use WHMCS\Database\Capsule;
return [
    'id' => '0005_schema_relaxation',
    'description' => 'Relax legacy status and credential columns for the additive model.',
    'up' => function ($m) {
        try { Capsule::statement("ALTER TABLE mod_digitalproducts_products MODIFY status VARCHAR(20) NOT NULL DEFAULT 'draft'"); } catch (\Throwable $e) {}
        try { Capsule::statement("ALTER TABLE mod_digitalproducts_licenses MODIFY license_key VARCHAR(96) NULL"); } catch (\Throwable $e) {}
        try { Capsule::statement("ALTER TABLE mod_digitalproducts_downloads MODIFY status VARCHAR(32) NOT NULL DEFAULT 'success'"); } catch (\Throwable $e) {}
        try { Capsule::statement("ALTER TABLE mod_digitalproducts_api_tokens MODIFY api_token VARCHAR(128) NULL"); } catch (\Throwable $e) {}
        try { Capsule::schema()->table('mod_digitalproducts_products', function ($t) { $t->unique('whmcs_product_id'); }); } catch (\Throwable $e) {}
        try { Capsule::schema()->table('mod_digitalproducts_products', function ($t) { $t->unique('slug'); }); } catch (\Throwable $e) {}
        try { Capsule::schema()->table('mod_digitalproducts_licenses', function ($t) { $t->unique('license_hash'); }); } catch (\Throwable $e) {}
    },
];
