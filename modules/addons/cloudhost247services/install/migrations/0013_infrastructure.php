<?php
/**
 * Infrastructure layer: OS catalog, OS versions, infrastructure providers,
 * provider image mappings, server product rules, customer SSH keys, the
 * module's server registry and the provisioning job journal.
 *
 * WHMCS stays the system of record for products, orders, invoices, services
 * (tblhosting) and server connection records (tblservers). The module links
 * to them (client_id / hosting_id / order_id / invoice_id) and never copies
 * billing or customer data. Provider credentials are AES-256-GCM sealed.
 *
 * Concept chain (admin-managed, never hard-coded in the frontend):
 *
 *   Operating System → OS Version → Architecture → Provider Image Mapping
 *     → Infrastructure Provider → Provisioning Job → Server
 */

use Chs\Core\Migrator;

return [
    'id' => '0013_infrastructure',
    'description' => 'Infrastructure: OS catalog + versions, providers + regions, server OS images, product rules, SSH keys, server registry, provisioning jobs',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('operating_systems', function ($t) {
            $t->id()
                ->string('name', 96)
                ->string('slug', 64)->unique('slug')->index('slug')
                ->string('vendor', 96, false, '')
                ->text('description')
                ->string('logo_url', 255, false, '')
                // ACTIVE | DISABLED | ARCHIVED
                ->string('status', 16, false, 'ACTIVE')->index('status')
                ->integer('sort_order', false, 0)->index('sort_order')
                ->boolean('is_vps_supported', 1)
                ->boolean('is_dedicated_supported', 1)
                ->boolean('is_cloud_supported', 1)
                ->boolean('is_reinstall_supported', 1)
                ->timestamps();
        });

        $m->createTable('operating_system_versions', function ($t) {
            $t->id()
                ->bigInteger('operating_system_id')->index('operating_system_id')
                ->foreign('operating_system_id', 'operating_systems', 'id', 'cascade')
                ->string('version', 64)
                ->string('display_name', 128)
                ->string('release_name', 96, false, '')
                ->string('architecture_support', 191, false, '["x86_64"]') // JSON array
                // ACTIVE | MAINTENANCE | EOL_WARNING | EOL | ARCHIVED
                ->string('status', 16, false, 'ACTIVE')->index('status')
                ->boolean('is_default', 0)
                ->boolean('is_lts', 0)
                ->dateColumn('release_date')
                ->dateColumn('end_of_life_date')
                ->timestamps();
            $t->unique(['operating_system_id', 'version'], 'osv_os_version');
        });

        $m->createTable('infrastructure_providers', function ($t) {
            $t->id()
                ->string('name', 96)
                ->string('slug', 64)->unique('slug')->index('slug')
                ->string('type', 24, false, 'http')->index('type') // http | (future: ovh, proxmox, …)
                ->string('base_url', 255, false, '')
                ->text('endpoints')       // JSON endpoint map (non-secret)
                ->string('auth_header', 64, false, 'Authorization')
                ->string('auth_prefix', 32, false, 'Bearer ')
                ->string('token_field', 64, false, 'api_key')
                ->string('capabilities', 255, false, '')->index('capabilities') // JSON array
                ->boolean('is_default', 0)
                ->boolean('is_enabled', 1)
                ->string('health_status', 24, false, 'unknown')->index('health_status')
                ->string('last_error', 255, false, '')
                ->dateTime('last_checked_at')
                ->longText('credentials_enc') // AES-256-GCM sealed; never plaintext
                ->timestamps();
        });

        $m->createTable('infrastructure_regions', function ($t) {
            $t->id()
                ->bigInteger('provider_id')->index('provider_id')
                ->foreign('provider_id', 'infrastructure_providers', 'id', 'cascade')
                ->string('code', 64)
                ->string('name', 128)
                ->string('datacenter', 128, false, '')
                ->boolean('is_active', 1)
                ->integer('sort_order', false, 0)
                ->timestamps();
            $t->unique(['provider_id', 'code'], 'infra_region_provider_code');
        });

        $m->createTable('server_os_images', function ($t) {
            $t->id()
                ->bigInteger('provider_id')->index('provider_id')
                ->foreign('provider_id', 'infrastructure_providers', 'id', 'cascade')
                ->bigInteger('operating_system_version_id')->index('operating_system_version_id')
                ->foreign('operating_system_version_id', 'operating_system_versions', 'id', 'cascade')
                ->string('provider_image_id', 191, false, '')
                ->string('provider_template_id', 191, false, '')
                ->string('architecture', 16, false, 'x86_64')->index('architecture')
                ->bigInteger('region_id', true)->index('region_id')
                ->foreign('region_id', 'infrastructure_regions', 'id', 'cascade')
                // active | disabled
                ->string('status', 16, false, 'disabled')->index('status')
                ->text('metadata')        // JSON (display name at provider, notes…)
                ->dateTime('last_tested_at')
                ->string('test_result', 24, false, '') // ok | failed | ''
                ->timestamps();
        });

        $m->createTable('server_product_rules', function ($t) {
            // Optional per-product restriction: server type + allowed
            // provider/region. No row = every enabled provider/region.
            $t->id()
                ->bigInteger('product_id')->unique('product_id')->index('product_id') // tblproducts.id
                ->string('server_type', 16, false, 'vps') // vps | dedicated | cloud
                ->bigInteger('provider_id', true)->index('provider_id')
                ->bigInteger('region_id', true)->index('region_id')
                ->timestamps();
        });

        $m->createTable('customer_ssh_keys', function ($t) {
            $t->id()
                ->bigInteger('client_id')->index('client_id')
                ->string('name', 96)
                ->text('public_key')
                ->string('fingerprint', 128, false, '')
                ->dateTime('created_at');
            $t->unique(['client_id', 'name'], 'sshkey_client_name');
        });

        $m->createTable('module_servers', function ($t) {
            // The module's infrastructure registry: one row per provisioned
            // (or provisioning) server, linked to the WHMCS service record.
            $t->id()
                ->bigInteger('client_id')->index('client_id')
                ->bigInteger('hosting_id', true)->index('hosting_id')   // tblhosting.id
                ->bigInteger('order_id', true)->index('order_id')       // tblorders.id
                ->bigInteger('invoice_id', true)->index('invoice_id')   // tblinvoices.id
                ->bigInteger('provisioning_job_id', true)
                ->bigInteger('provider_id', true)->index('provider_id')
                ->string('provider_server_id', 191, false, '')->index('provider_server_id')
                ->bigInteger('whmcs_server_id', true)->index('whmcs_server_id') // tblservers.id
                ->string('ip_address', 45, false, '')->index('ip_address')
                ->string('hostname', 255, false, '')
                ->bigInteger('operating_system_version_id', true)
                ->string('architecture', 16, false, '')
                ->bigInteger('region_id', true)
                // pending_payment | provisioning | active | stopped | reinstalled | failed | deleted
                ->string('status', 24, false, 'pending_payment')->index('status')
                ->dateTime('provisioned_at')
                ->timestamps();
        });

        $m->createTable('provisioning_jobs', function ($t) {
            $t->id()
                ->string('job_key', 96)->unique('job_key')->index('job_key') // idempotency
                ->string('type', 16, false, 'PROVISION')->index('type')     // PROVISION | REINSTALL | ACTION
                ->bigInteger('client_id')->index('client_id')
                ->bigInteger('module_server_id', true)->index('module_server_id')
                ->bigInteger('hosting_id', true)
                ->bigInteger('invoice_id', true)->index('invoice_id')
                ->bigInteger('provider_id', true)
                ->bigInteger('os_image_id', true)
                ->bigInteger('operating_system_version_id', true)
                ->string('architecture', 16, false, '')
                ->bigInteger('region_id', true)
                ->string('hostname', 255, false, '')
                ->bigInteger('ssh_key_id', true)
                ->string('action', 24, false, '') // start | stop | reboot | shutdown | rescue | delete | console
                // QUEUED ALLOCATING CREATING INSTALLING_OS CONFIGURING
                // NETWORK_CONFIGURING SECURITY_CONFIGURING HEALTH_CHECK READY
                // FAILED CANCELLED
                ->string('status', 24, false, 'QUEUED')->index('status')
                ->string('stage', 24, false, '')
                ->integer('attempts', false, 0)
                ->integer('max_attempts', false, 5)
                ->string('provider_server_id', 191, false, '')
                ->string('ip_address', 45, false, '')
                ->string('error_code', 64, false, '')->index('error_code')
                ->string('error_message', 500, false, '')
                ->boolean('retryable', 1)
                ->longText('logs')             // JSON array of stage log entries
                ->string('correlation_id', 64, false, '')->index('correlation_id')
                ->dateTime('started_at')
                ->dateTime('completed_at')
                ->dateTime('failed_at')
                ->dateTime('next_poll_at')
                ->timestamps();
        });
    },
];
