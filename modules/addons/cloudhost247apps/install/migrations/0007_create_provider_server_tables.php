<?php
/**
 * App Cloud — 0007: customer-owned infrastructure servers and provider accounts.
 *
 * These are deliberately separate from `servers` (App Cloud deployment targets).
 * WHMCS services/invoices remain the business-system authority; module rows hold
 * only the provider resource and the provisioning state that WHMCS does not model.
 *
 * @package Ch247Apps
 */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0007_create_provider_server_tables',
    'description' => 'Provider accounts, customer-owned VMs, lifecycle history, and queue correlation.',
    'up' => function (Migrator $m) {
        $m->create('provider_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 36);
            $t->string('provider_code', 40);
            $t->string('name', 120);
            $t->string('status', 24, false, 'unverified'); // unverified|active|suspended|error
            $t->string('region', 80, true);
            $t->longText('public_config');               // provider-specific, non-secret JSON
            $t->longText('encrypted_credentials');       // AES-GCM; never returned by an API
            $t->integer('credential_key_version', false, 0);
            $t->char('credential_fingerprint', 64, true);
            $t->datetime('last_verified_at', true);
            $t->string('last_error_code', 60, true);
            $t->text('last_error_message');
            $t->string('created_by', 120, true);
            $t->timestamps();
            $t->unique(['uuid']);
            $t->index(['provider_code', 'status']);
            $t->index(['status']);
        });

        $m->create('customer_servers', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 36);
            $t->bigInteger('client_id', false, 0);          // WHMCS tblclients.id
            $t->bigInteger('whmcs_service_id', false, 0);    // WHMCS tblhosting.id; unique per VM
            $t->bigInteger('whmcs_order_id', false, 0);
            $t->bigInteger('whmcs_invoice_id', false, 0);
            $t->unsignedBigInteger('provider_account_id', false, 0);
            $t->string('name', 120);
            $t->string('hostname', 253, true);
            $t->string('region', 80);
            $t->string('image', 120);
            $t->integer('cpu_cores', false, 1);
            $t->integer('memory_mb', false, 512);
            $t->integer('storage_gb', false, 10);
            $t->longText('requested_spec');                 // normalized, secret-free JSON
            $t->string('provider_server_id', 191, true);
            $t->string('provider_operation_id', 191, true);
            $t->string('provider_operation', 32, false, 'create');
            $t->string('provider_state', 40, true);
            $t->string('ipv4', 45, true);
            $t->string('ipv6', 45, true);
            // The customer-visible lifecycle stays provisioning until future
            // health/security gates explicitly mark the resource active.
            $t->string('status', 24, false, 'pending');
            $t->string('provisioning_state', 32, false, 'queued');
            $t->bigInteger('create_job_id', true);
            $t->integer('poll_count', false, 0);
            $t->string('last_error_code', 60, true);
            $t->text('last_error_message');
            $t->string('requested_by', 120, true);
            $t->timestamps();
            $t->unique(['uuid']);
            $t->unique(['whmcs_service_id']);
            $t->unique(['provider_account_id', 'provider_server_id']);
            $t->index(['client_id', 'status']);
            $t->index(['provider_account_id', 'status']);
            $t->index(['status', 'provisioning_state']);
            $t->index(['create_job_id']);
            $t->foreign('provider_account_id', 'provider_accounts', 'id', 'RESTRICT');
        });

        $m->create('customer_server_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_server_id', false, 0);
            $t->string('event', 60);
            $t->string('from_status', 24, true);
            $t->string('to_status', 24, true);
            $t->string('from_state', 32, true);
            $t->string('to_state', 32, true);
            $t->longText('metadata');                       // redacted, non-secret JSON
            $t->string('actor_type', 20);
            $t->bigInteger('actor_id', false, 0);
            $t->string('actor_identity', 120, true);
            $t->datetime('created_at', false);
            $t->index(['customer_server_id', 'id']);
            $t->index(['event', 'created_at']);
            $t->foreign('customer_server_id', 'customer_servers', 'id', 'CASCADE');
        });

        // Correlate customer-VM jobs without overloading server_id, which refers
        // exclusively to App Cloud deployment targets.
        $columnType = Db::isSqlite() ? 'INTEGER NULL' : 'BIGINT NULL';
        foreach (['customer_server_id', 'provider_account_id', 'whmcs_service_id'] as $column) {
            $m->addColumn('jobs', $column, $columnType);
        }
        $m->addColumn('events', 'customer_server_id', $columnType);
        $m->addIndex('jobs', ['customer_server_id']);
        $m->addIndex('jobs', ['provider_account_id']);
        $m->addIndex('jobs', ['whmcs_service_id']);
        $m->addIndex('events', ['customer_server_id', 'id']);
    },
];
