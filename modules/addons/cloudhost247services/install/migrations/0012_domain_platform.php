<?php
/**
 * Domain Services & Domain Marketplace platform layer.
 *
 * Adds the module-managed domain platform on top of WHMCS' own domain system:
 * provider registry + per-TLD mappings, customer domain service records
 * (linked to — never duplicating — WHMCS tbldomains), transfer tracking with
 * encrypted EPP storage, module-managed DNS record intent, domain search
 * history (single + bulk), the generic job queue that powers every
 * asynchronous domain operation, renewal tracking, an append-only domain
 * event journal and expiration-notice deduping.
 *
 * Existing WHMCS entities (tblclients, tblorders, tblinvoices, tbldomains,
 * tbltickets, …) are reused; nothing here shadows them.
 */

use Chs\Core\Migrator;

return [
    'id' => '0012_domain_platform',
    'description' => 'Domain platform: providers, mappings, domain services, transfers, DNS intent, search history, job queue, renewals, events, expiration notices',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('domain_providers', function ($t) {
            $t->id()
                ->string('name', 96)
                ->string('slug', 64)->unique('slug')
                ->string('type', 24, false, 'whmcs')->index('type') // whmcs | http
                ->string('base_url', 255, false, '')
                ->boolean('is_default', 0)
                ->boolean('is_enabled', 1)
                ->string('health_status', 24, false, 'unknown')->index('health_status')
                ->string('last_error', 255, false, '')
                ->dateTime('last_checked_at')
                ->text('config')          // non-secret provider configuration (JSON)
                ->longText('credentials_enc') // AES-256-GCM sealed credentials; never plaintext
                ->timestamps();
        });

        $m->createTable('domain_provider_mappings', function ($t) {
            $t->id()
                ->string('tld', 63)->unique('tld')->index('tld')
                ->bigInteger('provider_id')->index('provider_id')
                ->foreign('provider_id', 'domain_providers', 'id', 'cascade')
                ->dateTime('created_at');
        });

        $m->createTable('domain_services', function ($t) {
            $t->id()
                ->bigInteger('client_id')->index('client_id')
                ->bigInteger('whmcs_domain_id', true)->index('whmcs_domain_id')
                ->string('domain', 255)->unique('domain')->index('domain')
                ->string('tld', 63)->index('tld')
                ->string('status', 24, false, 'active')->index('status')
                ->string('registrar', 64, false, '')
                ->bigInteger('provider_id', true)->index('provider_id')
                ->text('nameservers')     // JSON array, last known state
                ->boolean('auto_renew', 1)
                ->boolean('whois_privacy', 0)
                ->dateTime('registered_at')
                ->dateTime('expires_at')->index('expires_at')
                ->bigInteger('renewal_price_minor', true)
                ->string('currency', 3, false, 'USD')
                ->dateTime('last_synced_at')
                ->timestamps();
        });

        $m->createTable('domain_transfers', function ($t) {
            $t->id()
                ->bigInteger('client_id')->index('client_id')
                ->bigInteger('domain_service_id', true)->index('domain_service_id')
                ->string('domain', 255)->index('domain')
                ->string('tld', 63)->index('tld')
                // PENDING INITIATED AWAITING_AUTH_CODE PROCESSING PENDING_REGISTRY
                // COMPLETED FAILED CANCELLED EXPIRED
                ->string('status', 24, false, 'pending')->index('status')
                ->string('registrar', 64, false, '')
                ->longText('epp_enc')     // sealed EPP/auth code; never logged, never echoed
                ->bigInteger('price_minor', false, 0)
                ->string('currency', 3, false, 'USD')
                ->bigInteger('invoice_id', true)->index('invoice_id')
                ->bigInteger('whmcs_domain_id', true)
                ->string('provider_ref', 191, false, '')
                ->longText('history')     // JSON append-only transition log (no secrets)
                ->string('error', 255, false, '')
                ->dateTime('requested_at')
                ->dateTime('completed_at')
                ->timestamps();
        });

        $m->createTable('domain_dns_records', function ($t) {
            $t->id()
                ->bigInteger('domain_service_id')->index('domain_service_id')
                ->foreign('domain_service_id', 'domain_services', 'id', 'cascade')
                ->bigInteger('provider_id', true)
                ->string('record_type', 8)->index('record_type') // A AAAA CNAME MX TXT NS SRV CAA
                ->string('name', 255)
                ->text('value')
                ->integer('ttl', false, 3600)
                ->integer('priority', true)
                ->string('provider_record_id', 191, false, '')
                // pending | synced | error
                ->string('sync_status', 16, false, 'pending')->index('sync_status')
                ->string('last_error', 255, false, '')
                ->timestamps();
        });

        $m->createTable('domain_searches', function ($t) {
            $t->id()
                ->bigInteger('client_id', true)->index('client_id')
                ->string('ip_hash', 64, false, '')->index('ip_hash')
                ->string('mode', 12, false, 'single')->index('mode') // single | bulk
                // queued | running | completed | failed
                ->string('status', 16, false, 'queued')->index('status')
                ->integer('total', false, 0)
                ->integer('completed', false, 0)
                ->string('currency', 3, false, 'USD')
                ->string('correlation_id', 64, false, '')->index('correlation_id')
                ->dateTime('created_at')->index('created_at')
                ->dateTime('completed_at');
        });

        $m->createTable('domain_search_results', function ($t) {
            $t->id()
                ->bigInteger('search_id')->index('search_id')
                ->foreign('search_id', 'domain_searches', 'id', 'cascade')
                ->string('domain', 255)->index('domain')
                ->string('tld', 63, false, '')
                ->tinyInteger('available', true)   // null = unknown (provider unavailable)
                ->string('status', 48, false, 'pending')->index('status')
                ->bigInteger('register_minor', true)
                ->bigInteger('renew_minor', true)
                ->bigInteger('transfer_minor', true)
                ->bigInteger('register_discounted_minor', true)
                ->string('currency', 3, false, 'USD')
                ->boolean('is_suggestion', 0)
                ->dateTime('created_at');
        });

        $m->createTable('jobs', function ($t) {
            $t->id()
                ->string('job_id', 64)->unique('job_id')
                ->string('type', 64)->index('type')
                // pending | running | completed | failed
                ->string('status', 16, false, 'pending')->index('status')
                ->integer('attempts', false, 0)
                ->integer('max_attempts', false, 5)
                ->string('correlation_id', 64, false, '')->index('correlation_id')
                ->string('entity_type', 32, false, '')
                ->bigInteger('entity_id', true)
                ->string('idempotency_key', 96, true)->unique('idempotency_key')
                ->longText('payload')
                ->dateTime('available_at')->index('available_at')
                ->dateTime('locked_until')
                ->dateTime('started_at')
                ->dateTime('completed_at')
                ->string('error_code', 64, false, '')
                ->string('error_message', 500, false, '')
                ->timestamps();
        });

        $m->createTable('domain_renewals', function ($t) {
            $t->id()
                ->bigInteger('domain_service_id')->index('domain_service_id')
                ->foreign('domain_service_id', 'domain_services', 'id', 'cascade')
                ->bigInteger('invoice_id', true)->index('invoice_id')
                // pending_payment | processing | completed | failed
                ->string('status', 24, false, 'pending_payment')->index('status')
                ->integer('years', false, 1)
                ->bigInteger('amount_minor', false, 0)
                ->string('currency', 3, false, 'USD')
                ->dateTime('attempted_at')
                ->dateTime('completed_at')
                ->string('error', 255, false, '')
                ->dateTime('created_at');
        });

        $m->createTable('domain_events', function ($t) {
            $t->id()
                ->string('domain', 255, false, '')->index('domain')
                ->bigInteger('domain_service_id', true)->index('domain_service_id')
                ->string('type', 64)->index('type')
                ->string('actor_type', 16, false, 'system')
                ->bigInteger('actor_id', false, 0)
                ->longText('payload')
                ->dateTime('created_at')->index('created_at');
        });

        $m->createTable('domain_expiration_notices', function ($t) {
            $t->id()
                ->bigInteger('domain_service_id')->index('domain_service_id')
                ->foreign('domain_service_id', 'domain_services', 'id', 'cascade')
                ->integer('days_before', false, 0) // 0 = "already expired" notice
                ->dateTime('sent_at')
                ->unique(['domain_service_id', 'days_before'], 'uq_expiry_notice');
        });
    },
];
