<?php
/**
 * Domain Broker — 0004: documents, disputes, notifications, risk, webhooks
 * and the infrastructure tables (idempotency keys, rate-limit buckets).
 *
 * @package DomainBroker
 */

use DomainBroker\Core\Blueprint;
use DomainBroker\Core\Db;
use DomainBroker\Core\Migrator;

return [
    'id' => '0004_create_support_tables',
    'description' => 'Documents, disputes, notifications, risk flags, webhook log, idempotency and rate limits.',
    'up' => function (Migrator $m) {

        /* ------------------------------------------------------- documents -- */
        $m->create('documents', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 64);
            $t->bigInteger('request_id');
            $t->string('category', 40, false, 'other');
            // The public-facing name. The on-disk name is random and the path
            // is never rendered — downloads go through an authorised endpoint.
            $t->string('original_name', 255);
            $t->string('stored_name', 255);
            $t->string('storage_disk', 20, false, 'local');
            $t->string('storage_path', 500);
            $t->string('mime_type', 120);
            $t->string('extension', 20);
            $t->bigInteger('size_bytes', false, 0);
            $t->char('sha256', 64);
            $t->string('visibility', 20, false, 'internal'); // customer|broker|internal
            $t->string('uploaded_by_type', 20, false);
            $t->integer('uploaded_by_id', false, 0);
            $t->string('uploaded_by_label', 190, true);
            $t->string('scan_status', 20, false, 'pending'); // pending|clean|infected|skipped|error
            $t->text('scan_result');
            $t->datetime('scanned_at', true);
            $t->text('description');
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['uuid']);
            $t->index(['request_id', 'visibility']);
            $t->index(['sha256']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
        });

        /* -------------------------------------------------------- disputes -- */
        $m->create('disputes', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40);
            $t->bigInteger('request_id');
            $t->string('opened_by_type', 20, false);
            $t->integer('opened_by_id', false, 0);
            $t->string('reason_code', 60);
            $t->text('description');
            $t->string('status', 30, false, 'open'); // open|investigating|awaiting_customer|awaiting_owner|escalated|resolved|rejected
            $t->string('severity', 20, false, 'normal');
            $t->money('amount_in_dispute_minor');
            $t->char('currency', 3, true);
            $t->text('resolution');
            $t->string('resolution_type', 40, true); // refund|partial_refund|transfer_completed|no_action|cancelled
            $t->string('resolved_by_type', 20, true);
            $t->integer('resolved_by_id', true);
            $t->datetime('resolved_at', true);
            $t->datetime('escalated_at', true);
            $t->datetime('due_at', true);
            $t->timestamps();

            $t->unique(['reference']);
            $t->index(['request_id', 'status']);
            $t->index(['status']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
        });

        /* --------------------------------------------------- notifications -- */
        $m->create('notifications', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('request_id', true);
            $t->string('event', 80);
            $t->string('channel', 20, false, 'inapp'); // email|inapp
            $t->string('audience', 20, false, 'customer'); // customer|broker|admin
            $t->integer('client_id', true);
            $t->bigInteger('broker_id', true);
            $t->integer('admin_id', true);
            $t->string('subject', 255, true);
            $t->longText('body');
            $t->string('template', 120, true);
            $t->longText('payload');
            $t->string('url', 255, true);
            $t->string('status', 20, false, 'queued'); // queued|sent|failed|suppressed
            $t->datetime('sent_at', true);
            $t->datetime('read_at', true);
            $t->integer('attempts', false, 0);
            $t->text('error');
            $t->timestamps();

            $t->index(['request_id']);
            $t->index(['client_id', 'read_at']);
            $t->index(['broker_id', 'read_at']);
            $t->index(['status']);
            $t->index(['event']);
        });

        /* ------------------------------------------------------ risk flags -- */
        $m->create('risk', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('request_id', true);
            $t->integer('client_id', true);
            $t->string('rule_code', 60);
            $t->string('severity', 20, false, 'low'); // low|medium|high|critical
            $t->integer('score', false, 0);
            $t->text('description');
            $t->longText('evidence');
            $t->string('status', 20, false, 'open'); // open|cleared|confirmed
            $t->string('reviewed_by_type', 20, true);
            $t->integer('reviewed_by_id', true);
            $t->datetime('reviewed_at', true);
            $t->text('review_notes');
            $t->timestamps();

            $t->index(['request_id']);
            $t->index(['client_id']);
            $t->index(['status', 'severity']);
            $t->index(['rule_code']);
        });

        /* -------------------------------------------------- webhook events -- */
        $m->create('webhooks', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 40);
            $t->string('event_id', 190);
            $t->string('event_type', 80, true);
            $t->boolean('signature_valid', 0);
            $t->longText('payload');
            $t->longText('headers');
            $t->string('source_ip', 45, true);
            $t->boolean('processed', 0);
            $t->datetime('processed_at', true);
            $t->text('error');
            $t->datetime('received_at', false);
            $t->timestamps();

            $t->unique(['provider', 'event_id']);
            $t->index(['processed']);
            $t->index(['received_at']);
        });

        /* ----------------------------------------------- idempotency keys -- */
        $m->create('idempotency', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 100);
            $t->string('idempotency_key', 190);
            $t->char('fingerprint', 64);
            $t->string('state', 20, false, 'in_progress');
            $t->integer('attempts', false, 1);
            $t->longText('response');
            $t->text('error');
            $t->datetime('locked_until', true);
            $t->datetime('expires_at', false);
            $t->timestamps();

            $t->unique(['scope', 'idempotency_key']);
            $t->index(['expires_at']);
        });

        /* ------------------------------------------------- rate limiting -- */
        $m->create('ratelimits', function (Blueprint $t) {
            $t->id();
            $t->string('bucket_key', 250);
            $t->string('action', 60);
            $t->string('principal', 190);
            $t->integer('hits', false, 0);
            $t->datetime('window_start', false);
            $t->datetime('expires_at', false);
            $t->timestamps();

            $t->unique(['bucket_key']);
            $t->index(['expires_at']);
        });
    },
];
