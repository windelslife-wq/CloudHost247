<?php
/**
 * App Cloud — 0006: operations (backups, subscriptions, notifications, webhooks,
 * payment links, scheduled tasks, health probes).
 *
 * Money stays in WHMCS. `payment_events` is the verified webhook ledger (with
 * signature status and idempotent processing), `order_links` ties an installation
 * to the WHMCS order/invoice/service that paid for it, and `subscriptions` tracks
 * only the app-cloud lifecycle WHMCS does not model: grace period, suspension
 * countdown and termination schedule.
 *
 * @package Ch247Apps
 */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0006_create_operations_tables',
    'description' => 'Backups, subscriptions, payment events, order links, notifications, webhooks, schedules, health probes.',
    'up' => function (Migrator $m) {

        /* -------------------------------------------------------------- backups -- */
        $m->create('backups', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 36);
            $t->unsignedBigInteger('installation_id', false, 0);
            $t->bigInteger('server_id', true);
            // local|s3|r2|remote|ftp
            $t->string('storage_provider', 20, false, 'local');
            $t->string('storage_bucket', 160, true);
            $t->string('storage_path', 500, true);
            $t->bigInteger('size_bytes', true);
            // pending|running|completed|failed|restoring|restored|expired|deleted
            $t->string('status', 20, false, 'pending');
            // manual|scheduled|pre_update|pre_restore
            $t->string('trigger', 20, false, 'manual');
            $t->boolean('includes_database', 1);
            $t->boolean('includes_volumes', 1);
            $t->char('checksum', 64, true);                   // sha256 of the archive
            $t->string('checksum_algorithm', 20, true, 'sha256');
            $t->longText('encryption_key_ref');               // sealed passphrase reference, never the key
            $t->boolean('encrypted', 0);
            $t->datetime('started_at', true);
            $t->datetime('completed_at', true);
            $t->datetime('expires_at', true);
            $t->datetime('restored_at', true);
            $t->bigInteger('restored_to_installation_id', true);
            $t->string('error_code', 60, true);
            $t->text('error_message');
            $t->bigInteger('deployment_id', true);
            $t->string('requested_by', 120, true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['uuid']);
            $t->index(['installation_id', 'status']);
            $t->index(['expires_at']);
            $t->foreign('installation_id', 'installations', 'id', 'CASCADE');
        });

        /* -------------------------------------------------------- subscriptions -- */
        $m->create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('customer_id', false, 0);
            $t->bigInteger('installation_id', true);
            $t->bigInteger('plan_id', true);
            $t->bigInteger('whmcs_service_id', true);         // tblhosting.id — authoritative
            $t->bigInteger('whmcs_product_id', true);
            // active|trialing|past_due|grace_period|suspended|cancelled|expired|terminated
            $t->string('status', 20, false, 'active');
            $t->string('provider', 40, false, 'whmcs');
            $t->string('provider_subscription_id', 191, true);
            $t->string('billing_interval', 20, false, 'monthly');
            $t->datetime('current_period_start', true);
            $t->datetime('current_period_end', true);
            $t->datetime('past_due_since', true);
            $t->datetime('grace_period_ends_at', true);
            $t->boolean('cancel_at_period_end', 0);
            $t->datetime('cancelled_at', true);
            $t->string('cancellation_reason', 255, true);
            $t->datetime('suspended_at', true);
            $t->string('suspension_reason', 255, true);
            $t->datetime('terminated_at', true);
            $t->datetime('next_action_at', true);             // when the scheduler must look again
            $t->text('notes');
            $t->timestamps();
            $t->index(['customer_id', 'status']);
            $t->index(['whmcs_service_id']);
            $t->index(['installation_id']);
            $t->index(['next_action_at']);
        });

        /* -------------------------------------------------- order / payment links -- */
        $m->create('order_links', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('installation_id', false, 0);
            $t->bigInteger('customer_id', false, 0);
            $t->bigInteger('whmcs_order_id', true);
            $t->bigInteger('whmcs_invoice_id', true);
            $t->bigInteger('whmcs_service_id', true);
            $t->bigInteger('plan_id', true);
            $t->money('subtotal_minor', true);
            $t->money('tax_minor', true);
            $t->money('discount_minor', true);
            $t->money('total_minor', true);
            $t->char('currency', 3, false, 'USD');
            // pending|paid|unpaid|refunded|cancelled
            $t->string('status', 20, false, 'pending');
            $t->string('payment_provider', 60, true);
            $t->string('payment_reference', 191, true);
            $t->datetime('paid_at', true);
            $t->boolean('provisioning_triggered', 0);         // exactly-once gate
            $t->datetime('provisioning_triggered_at', true);
            $t->timestamps();
            $t->index(['installation_id']);
            $t->index(['whmcs_invoice_id']);
            $t->index(['status']);
        });

        /* ----------------------------------------------------- payment webhooks -- */
        // Idempotent by (provider, provider_event_id): a redelivered webhook is
        // recorded once and processed once.
        $m->create('payment_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 40);
            $t->string('provider_event_id', 191);
            $t->string('event_type', 80);
            $t->bigInteger('whmcs_invoice_id', true);
            $t->bigInteger('customer_id', true);
            $t->money('amount_minor', true);
            $t->char('currency', 3, true);
            $t->string('signature_status', 20, false, 'unverified'); // verified|failed|skipped
            $t->string('signature_detail', 255, true);
            // received|processed|ignored|failed
            $t->string('status', 20, false, 'received');
            $t->longText('payload');
            $t->text('error_message');
            $t->string('source_ip', 45, true);
            $t->datetime('processed_at', true);
            $t->timestamps();
            $t->unique(['provider', 'provider_event_id']);
            $t->index(['status']);
            $t->index(['whmcs_invoice_id']);
        });

        /* -------------------------------------------------------- notifications -- */
        $m->create('notifications', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 20, false, 'email');         // email|admin_email|ticket|internal
            $t->string('template', 80);
            $t->bigInteger('customer_id', true);
            $t->bigInteger('installation_id', true);
            $t->bigInteger('deployment_id', true);
            $t->string('subject', 255, true);
            $t->longText('body');
            $t->string('status', 20, false, 'queued');         // queued|sent|failed|suppressed
            $t->integer('attempts', false, 0);
            $t->datetime('send_at', true);
            $t->datetime('sent_at', true);
            $t->string('error_message', 255, true);
            $t->string('dedupe_key', 191, true);               // prevents notification storms
            $t->timestamps();
            $t->index(['status', 'send_at']);
            $t->index(['customer_id']);
            $t->index(['dedupe_key']);
        });

        /* ------------------------------------------------- inbound webhooks -- */
        $m->create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('source', 40);                          // payment|dns|agent|acme|registrar
            $t->string('event_type', 80);
            $t->string('signature_status', 20, false, 'unverified');
            $t->longText('payload');
            $t->string('source_ip', 45, true);
            $t->string('status', 20, false, 'received');
            $t->text('error_message');
            $t->datetime('created_at', false);
            $t->datetime('processed_at', true);
            $t->index(['source', 'status']);
        });

        /* ------------------------------------------------------- health probes -- */
        // One row per probe attempt is too much; this is the rolling state plus
        // the counters the recovery circuit breaker uses.
        $m->create('health_probes', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('installation_id', false, 0);
            $t->string('probe_type', 20, false, 'http');       // http|tcp|exec|container
            $t->string('target', 255, true);
            $t->string('status', 20, false, 'unknown');        // healthy|unhealthy|unknown
            $t->integer('response_code', true);
            $t->integer('response_ms', true);
            $t->text('detail');
            $t->integer('consecutive_failures', false, 0);
            $t->integer('consecutive_successes', false, 0);
            $t->integer('restarts_in_window', false, 0);
            $t->datetime('window_started_at', true);
            $t->datetime('checked_at', false);
            $t->unique(['installation_id']);
            $t->index(['status']);
        });

        /* --------------------------------------------------------- schedules -- */
        // The scheduler's own ledger: what ran, when it is next due, and how long
        // it took. Lease-based, so two cron processes cannot run the same task.
        $m->create('schedules', function (Blueprint $t) {
            $t->id();
            $t->string('task', 80);                            // health_check|backup_run|ssl_renewal|…
            $t->string('status', 20, false, 'idle');           // idle|running|failed
            $t->integer('interval_seconds', false, 300);
            $t->datetime('last_run_at', true);
            $t->datetime('next_run_at', true);
            $t->datetime('lease_expires_at', true);
            $t->string('leased_by', 120, true);
            $t->integer('last_duration_ms', true);
            $t->integer('last_processed', false, 0);
            $t->text('last_error');
            $t->boolean('enabled', 1);
            $t->timestamps();
            $t->unique(['task']);
        });
    },
];
