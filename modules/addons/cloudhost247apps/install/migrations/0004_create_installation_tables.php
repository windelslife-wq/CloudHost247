<?php
/**
 * App Cloud — 0004: customer installations, domains, environment and volumes.
 *
 * An installation is the customer's instance of an application on a server. It
 * carries the WHMCS service/order/invoice ids that paid for it, the container
 * project name that isolates it from every other tenant, and the health/SSL state
 * the customer sees. Domains are first-class so one installation can serve
 * several hostnames and so a domain can move between installations.
 *
 * @package Ch247Apps
 */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0004_create_installation_tables',
    'description' => 'Installations, domains, application domains, environment, volumes and certificates.',
    'up' => function (Migrator $m) {

        /* -------------------------------------------------------------- domains -- */
        $m->create('domains', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('customer_id', false, 0);        // tblclients.id
            $t->string('domain', 253);                      // punycode, canonical
            $t->string('domain_display', 253, true);
            // customer|subdomain|free_subdomain|external
            $t->string('domain_type', 20, false, 'customer');
            $t->string('provider', 40, true);               // cloudflare|whmcs|route53|manual
            $t->bigInteger('whmcs_domain_id', true);        // tbldomains.id when registered through WHMCS
            $t->string('verification_status', 20, false, 'unverified'); // unverified|pending|verified|failed
            $t->string('verification_method', 20, true);    // dns_txt|dns_a|http|whois|whmcs
            $t->string('verification_token', 191, true);
            $t->datetime('verified_at', true);
            $t->string('dns_status', 20, false, 'unknown'); // unknown|pointing|not_pointing
            $t->string('resolved_ip', 45, true);
            $t->string('ssl_status', 20, false, 'none');    // none|pending|issued|failed|expiring|expired
            $t->datetime('ssl_issued_at', true);
            $t->datetime('ssl_expires_at', true);
            $t->datetime('expires_at', true);               // registration expiry
            $t->text('notes');
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['domain']);
            $t->index(['customer_id']);
            $t->index(['verification_status']);
            $t->index(['ssl_status']);
        });

        /* --------------------------------------------------------- installations -- */
        $m->create('installations', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40);                     // APP-XXXXXXXX shown to customers
            $t->string('uuid', 36);
            $t->bigInteger('customer_id', false, 0);         // tblclients.id
            $t->bigInteger('application_id', false, 0);
            $t->bigInteger('application_version_id', false, 0);
            $t->bigInteger('server_id', true);
            $t->bigInteger('plan_id', true);
            $t->string('name', 160);                         // customer chosen label
            $t->string('container_project', 100);            // isolated compose project

            // pending|queued|deploying|starting|healthy|unhealthy|stopped|updating|
            // failed|deleting|deleted|suspended|terminated
            $t->string('status', 20, false, 'pending');
            $t->string('previous_status', 20, true);
            $t->datetime('status_changed_at', true);

            $t->string('domain', 253, true);                 // primary domain (denormalised for display)
            $t->string('access_url', 255, true);
            $t->integer('internal_port', true);
            $t->integer('external_port', true);
            $t->bigInteger('deployment_id', true);           // most recent deployment
            $t->string('adapter', 30, false, 'docker');      // docker|cpanel|kubernetes

            // Billing linkage — WHMCS stays authoritative for money.
            $t->bigInteger('whmcs_service_id', true);
            $t->bigInteger('whmcs_order_id', true);
            $t->bigInteger('whmcs_invoice_id', true);
            $t->string('payment_status', 20, false, 'unpaid'); // unpaid|paid|refunded
            $t->datetime('paid_at', true);

            $t->string('health_status', 20, false, 'unknown'); // healthy|unhealthy|unknown
            $t->datetime('health_checked_at', true);
            $t->string('health_message', 255, true);
            $t->string('ssl_status', 20, false, 'none');
            $t->integer('restart_count', false, 0);
            $t->datetime('last_restart_at', true);
            $t->string('circuit_state', 20, false, 'closed'); // closed|open|half_open
            $t->datetime('circuit_opened_at', true);
            $t->string('current_version', 60, true);
            $t->string('available_version', 60, true);
            $t->boolean('auto_update', 0);
            $t->boolean('backup_enabled', 1);
            $t->datetime('last_backup_at', true);
            $t->datetime('suspended_at', true);
            $t->datetime('terminated_at', true);
            $t->datetime('installed_at', true);
            $t->text('notes');
            $t->bigInteger('created_by', true);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['uuid']);
            $t->unique(['reference']);
            $t->index(['customer_id', 'status']);
            $t->index(['application_id']);
            $t->index(['server_id']);
            $t->index(['whmcs_service_id']);
            $t->index(['health_status']);
            $t->foreign('application_id', 'applications', 'id', 'RESTRICT');
            $t->foreign('application_version_id', 'application_versions', 'id', 'RESTRICT');
        });

        /* ---------------------------------------------- installation ↔ domain -- */
        $m->create('installation_domains', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('installation_id', false, 0);
            $t->bigInteger('domain_id', false, 0);
            $t->boolean('primary_domain', 0);
            $t->string('path_prefix', 100, true);            // for path-based routing
            $t->string('status', 20, false, 'pending');      // pending|active|failed|removed
            $t->timestamps();
            $t->unique(['installation_id', 'domain_id']);
            $t->foreign('installation_id', 'installations', 'id', 'CASCADE');
            $t->foreign('domain_id', 'domains', 'id', 'CASCADE');
        });

        /* --------------------------------------------------------- environment -- */
        // Secrets are encrypted with the row context bound as AAD; `is_secret`
        // rows are never returned in plaintext by any API or portal response.
        $m->create('environment', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('installation_id', false, 0);
            $t->string('env_key', 128);
            $t->longText('encrypted_value');
            $t->integer('key_version', false, 1);
            $t->boolean('is_secret', 0);
            // manifest|customer|system|generated
            $t->string('source', 20, false, 'customer');
            $t->string('description', 255, true);
            $t->boolean('locked', 0);                        // managed by the platform, not editable
            $t->bigInteger('updated_by', true);
            $t->timestamps();
            $t->unique(['installation_id', 'env_key']);
            $t->foreign('installation_id', 'installations', 'id', 'CASCADE');
        });

        /* -------------------------------------------------------------- volumes -- */
        $m->create('volumes', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('installation_id', false, 0);
            $t->string('name', 160);
            $t->string('service', 80, true);                 // compose service the volume belongs to
            $t->string('mount_path', 255);
            $t->string('host_path', 255, true);
            $t->string('driver', 20, false, 'local');        // local|nfs|block
            $t->integer('size_mb', false, 0);
            $t->integer('used_mb', true);
            $t->boolean('included_in_backup', 1);
            $t->datetime('created_at', false);
            $t->datetime('updated_at', true);
            $t->datetime('last_backup_at', true);
            $t->unique(['installation_id', 'name']);
            $t->foreign('installation_id', 'installations', 'id', 'CASCADE');
        });

        /* --------------------------------------------------------- certificates -- */
        // Private keys are sealed; only expiry, issuer and status are readable.
        $m->create('certificates', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('domain_id', true);
            $t->bigInteger('installation_id', true);
            $t->bigInteger('server_id', true);
            $t->string('domain', 253);
            $t->text('subject_alternative_names');            // JSON list
            $t->string('issuer', 190, true);
            $t->string('acme_account', 190, true);
            $t->string('storage_path', 255, true);            // path on the node (Traefik ACME store)
            $t->longText('encrypted_private_key');
            $t->longText('certificate_pem');                  // public, safe to display
            $t->integer('key_version', false, 1);
            $t->string('status', 20, false, 'pending');       // pending|issued|failed|expiring|expired|revoked
            $t->datetime('issued_at', true);
            $t->datetime('expires_at', true);
            $t->datetime('last_renewal_attempt_at', true);
            $t->integer('renewal_attempts', false, 0);
            $t->string('error_code', 60, true);
            $t->string('error_message', 255, true);
            $t->timestamps();
            $t->index(['domain_id']);
            $t->index(['installation_id']);
            $t->index(['status', 'expires_at']);
        });
    },
];
