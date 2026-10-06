<?php
use WHMCS\Database\Capsule;
use DigitalProducts\Core\Clock;

return [
    'id' => '0001_schema',
    'description' => 'Create the digital product schema and add safe compatibility columns.',
    'up' => function ($m) {
        $m->create('mod_digitalproducts_products', function ($t) {
            $t->increments('id'); $t->integer('product_id')->unsigned()->unique(); $t->integer('whmcs_product_id')->unsigned()->nullable();
            $t->string('product_name', 255)->nullable(); $t->string('name', 255)->nullable(); $t->string('slug', 191)->nullable();
            $t->text('short_description')->nullable(); $t->text('description')->nullable(); $t->string('product_type', 32)->default('software');
            $t->string('status', 20)->default('draft'); $t->integer('current_file_id')->unsigned()->nullable(); $t->integer('current_version_id')->unsigned()->nullable();
            $t->integer('download_limit')->unsigned()->default(0); $t->integer('link_expiry_hours')->unsigned()->default(48); $t->integer('download_expiry_hours')->unsigned()->default(48);
            $t->string('access_mode', 24)->default('current_version'); $t->boolean('license_enabled')->default(true); $t->string('license_expiry_mode', 20)->default('never');
            $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable();
            $t->index('whmcs_product_id'); $t->index('status');
        });
        foreach (['whmcs_product_id' => function ($t) { $t->integer('whmcs_product_id')->unsigned()->nullable(); }, 'name' => function ($t) { $t->string('name', 255)->nullable(); }, 'slug' => function ($t) { $t->string('slug', 191)->nullable(); }, 'short_description' => function ($t) { $t->text('short_description')->nullable(); }, 'product_type' => function ($t) { $t->string('product_type', 32)->default('software'); }, 'current_version_id' => function ($t) { $t->integer('current_version_id')->unsigned()->nullable(); }, 'download_expiry_hours' => function ($t) { $t->integer('download_expiry_hours')->unsigned()->default(48); }, 'access_mode' => function ($t) { $t->string('access_mode', 24)->default('current_version'); }, 'license_expiry_mode' => function ($t) { $t->string('license_expiry_mode', 20)->default('never'); }] as $column => $definition) $m->addColumn('mod_digitalproducts_products', $column, $definition);
        if ($m->tableExists('mod_digitalproducts_products')) {
            try { Capsule::schema()->table('mod_digitalproducts_products', function ($t) { $t->unique('whmcs_product_id'); }); } catch (\Throwable $e) {}
            try { Capsule::schema()->table('mod_digitalproducts_products', function ($t) { $t->unique('slug'); }); } catch (\Throwable $e) {}
            Capsule::statement("UPDATE mod_digitalproducts_products SET whmcs_product_id = product_id WHERE whmcs_product_id IS NULL");
            Capsule::statement("UPDATE mod_digitalproducts_products SET name = product_name WHERE name IS NULL");
            if ($m->hasColumn('mod_digitalproducts_products', 'link_expiry_hours')) Capsule::statement("UPDATE mod_digitalproducts_products SET download_expiry_hours = link_expiry_hours");
        }

        $m->create('mod_digitalproducts_versions', function ($t) {
            $t->increments('id'); $t->integer('product_id')->unsigned(); $t->string('version', 50); $t->string('storage_key', 500)->nullable(); $t->string('original_filename', 255);
            $t->bigInteger('file_size')->unsigned()->default(0); $t->string('checksum_sha256', 64)->nullable(); $t->text('release_notes')->nullable(); $t->text('changelog')->nullable();
            $t->string('min_php', 20)->nullable(); $t->string('max_php', 20)->nullable(); $t->string('min_whmcs', 20)->nullable(); $t->string('max_whmcs', 20)->nullable(); $t->text('required_extensions')->nullable();
            $t->dateTime('release_date')->nullable(); $t->string('status', 20)->default('active'); $t->integer('download_count')->unsigned()->default(0); $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable();
            $t->unique(['product_id', 'version']); $t->index(['product_id', 'status']);
        });

        $m->create('mod_digitalproducts_entitlements', function ($t) {
            $t->increments('id'); $t->integer('product_id')->unsigned(); $t->integer('whmcs_product_id')->unsigned(); $t->integer('order_id')->unsigned()->nullable(); $t->integer('service_id')->unsigned(); $t->integer('client_id')->unsigned();
            $t->integer('purchase_version_id')->unsigned()->nullable(); $t->string('access_mode', 24)->default('current_version'); $t->string('status', 20)->default('active'); $t->integer('download_limit')->unsigned()->nullable(); $t->integer('downloads_used')->unsigned()->default(0); $t->string('revoked_reason', 255)->nullable();
            $t->dateTime('purchased_at')->nullable(); $t->dateTime('expires_at')->nullable(); $t->dateTime('email_sent_at')->nullable(); $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable();
            $t->unique(['client_id', 'service_id', 'product_id']); $t->index(['client_id', 'status']); $t->index('service_id'); $t->index(['product_id', 'status']);
        });

        $m->create('mod_digitalproducts_download_tokens', function ($t) {
            $t->increments('id'); $t->string('token_hash', 64)->unique(); $t->integer('entitlement_id')->unsigned(); $t->integer('version_id')->unsigned(); $t->integer('client_id')->unsigned(); $t->boolean('single_use')->default(true); $t->dateTime('expires_at')->nullable(); $t->dateTime('used_at')->nullable(); $t->string('created_ip_hash', 64)->nullable(); $t->dateTime('created_at')->nullable(); $t->index('expires_at'); $t->index('entitlement_id');
        });

        $m->create('mod_digitalproducts_licenses', function ($t) {
            $t->increments('id'); $t->integer('product_id')->unsigned(); $t->integer('service_id')->unsigned(); $t->integer('client_id')->unsigned(); $t->integer('entitlement_id')->unsigned()->nullable();
            $t->string('license_key', 96)->nullable(); $t->string('license_hash', 64)->nullable(); $t->string('license_prefix', 32)->nullable(); $t->text('license_encrypted')->nullable(); $t->string('status', 20)->default('active'); $t->text('domains')->nullable(); $t->integer('activation_limit')->unsigned()->default(0); $t->integer('activations_limit')->unsigned()->default(0); $t->integer('activations_count')->unsigned()->default(0); $t->integer('domain_limit')->unsigned()->default(0); $t->dateTime('expires_at')->nullable(); $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable();
            $t->unique(['service_id', 'product_id']); $t->unique('license_hash'); $t->index('client_id');
        });

        $m->create('mod_digitalproducts_downloads', function ($t) {
            $t->increments('id'); $t->integer('file_id')->unsigned()->nullable(); $t->integer('product_id')->unsigned()->nullable(); $t->integer('version_id')->unsigned()->nullable(); $t->integer('entitlement_id')->unsigned()->nullable(); $t->integer('token_id')->unsigned()->nullable(); $t->integer('service_id')->unsigned()->nullable(); $t->integer('order_id')->unsigned()->nullable(); $t->integer('client_id')->unsigned()->nullable(); $t->string('ip_hash', 64)->nullable(); $t->string('ip_address', 45)->nullable(); $t->text('user_agent')->nullable(); $t->string('status', 32)->default('success'); $t->string('failure_reason', 100)->nullable(); $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable();
            $t->index(['client_id', 'product_id']); $t->index(['status', 'created_at']); $t->index('token_id'); $t->index('version_id');
        });

        $m->create('mod_digitalproducts_api_tokens', function ($t) {
            $t->increments('id'); $t->integer('client_id')->unsigned(); $t->string('token_name', 100); $t->string('api_token', 128)->nullable(); $t->string('token_hash', 64)->nullable(); $t->text('permissions')->nullable(); $t->string('ip_restriction', 255)->nullable(); $t->dateTime('last_used_at')->nullable(); $t->dateTime('expires_at')->nullable(); $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable(); $t->unique('token_hash'); $t->index('client_id');
        });

        $m->create('mod_digitalproducts_audit', function ($t) {
            $t->increments('id'); $t->string('action', 100); $t->string('resource', 190)->nullable(); $t->string('result', 32); $t->integer('admin_id')->unsigned()->default(0); $t->integer('client_id')->unsigned()->default(0); $t->string('correlation_id', 32); $t->text('context')->nullable(); $t->string('ip_hash', 64)->nullable(); $t->dateTime('created_at'); $t->index(['action', 'created_at']);
        });
        $m->create('mod_digitalproducts_rate_limits', function ($t) {
            $t->increments('id'); $t->string('action', 80); $t->string('bucket', 128); $t->integer('hits')->unsigned()->default(0); $t->dateTime('window_start'); $t->unique(['action', 'bucket']);
        });
    },
];
