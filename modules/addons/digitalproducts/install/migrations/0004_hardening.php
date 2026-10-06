<?php
use WHMCS\Database\Capsule;
use DigitalProducts\Core\Clock;

return [
    'id' => '0004_hardening',
    'description' => 'Hash legacy credentials and add compatibility fields to existing tables.',
    'up' => function ($m) {
        $legacy = [
            'mod_digitalproducts_licenses' => [
                'license_hash' => function ($t) { $t->string('license_hash', 64)->nullable(); },
                'license_prefix' => function ($t) { $t->string('license_prefix', 32)->nullable(); },
                'license_encrypted' => function ($t) { $t->text('license_encrypted')->nullable(); },
                'entitlement_id' => function ($t) { $t->integer('entitlement_id')->unsigned()->nullable(); },
                'activation_limit' => function ($t) { $t->integer('activation_limit')->unsigned()->default(0); },
                'domain_limit' => function ($t) { $t->integer('domain_limit')->unsigned()->default(0); },
            ],
            'mod_digitalproducts_downloads' => [
                'version_id' => function ($t) { $t->integer('version_id')->unsigned()->nullable(); },
                'entitlement_id' => function ($t) { $t->integer('entitlement_id')->unsigned()->nullable(); },
                'token_id' => function ($t) { $t->integer('token_id')->unsigned()->nullable(); },
                'order_id' => function ($t) { $t->integer('order_id')->unsigned()->nullable(); },
                'ip_hash' => function ($t) { $t->string('ip_hash', 64)->nullable(); },
                'failure_reason' => function ($t) { $t->string('failure_reason', 100)->nullable(); },
            ],
            'mod_digitalproducts_api_tokens' => [
                'token_hash' => function ($t) { $t->string('token_hash', 64)->nullable(); },
            ],
        ];
        foreach ($legacy as $table => $columns) foreach ($columns as $name => $definition) if ($m->tableExists($table)) $m->addColumn($table, $name, $definition);
        // Legacy installations used restrictive ENUM / NOT NULL columns. Make
        // them additive so draft products and denial statuses are valid.
        try { Capsule::statement("ALTER TABLE mod_digitalproducts_products MODIFY status VARCHAR(20) NOT NULL DEFAULT 'draft'"); } catch (\Throwable $e) {}
        try { Capsule::statement("ALTER TABLE mod_digitalproducts_licenses MODIFY license_key VARCHAR(96) NULL"); } catch (\Throwable $e) {}
        try { Capsule::statement("ALTER TABLE mod_digitalproducts_downloads MODIFY status VARCHAR(32) NOT NULL DEFAULT 'success'"); } catch (\Throwable $e) {}
        if ($m->tableExists('mod_digitalproducts_licenses')) {
            foreach (Capsule::table('mod_digitalproducts_licenses')->whereNotNull('license_key')->get() as $license) {
                if (empty($license->license_hash)) Capsule::table('mod_digitalproducts_licenses')->where('id', $license->id)->update(['license_hash' => hash('sha256', (string) $license->license_key), 'license_prefix' => substr((string) $license->license_key, 0, 10)]);
            }
        }
        if ($m->tableExists('mod_digitalproducts_api_tokens') && $m->hasColumn('mod_digitalproducts_api_tokens', 'api_token')) {
            foreach (Capsule::table('mod_digitalproducts_api_tokens')->whereNotNull('api_token')->get() as $token) {
                if (empty($token->token_hash)) Capsule::table('mod_digitalproducts_api_tokens')->where('id', $token->id)->update(['token_hash' => hash('sha256', (string) $token->api_token)]);
            }
        }
    },
];
