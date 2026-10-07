<?php
/**
 * App Cloud — 0005: deployment engine.
 *
 * jobs is the queue (leases, attempts, backoff, dead-letter). deployments is the
 * unit of work a customer or the scheduler asked for; deployment_steps is the
 * ordered, individually-timed record of what actually happened; created_resources
 * is the ledger that makes rollback possible and guarantees that a failed
 * deployment never leaves half-created infrastructure unrecorded.
 *
 * @package Ch247Apps
 */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0005_create_deployment_tables',
    'description' => 'Job queue, deployments, deployment steps, per-step logs, created-resource ledger.',
    'up' => function (Migrator $m) {

        /* --------------------------------------------------------------- jobs -- */
        $m->create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 36);
            // install|destroy|start|stop|restart|update|backup|restore|ssl|
            // domain_configure|healthcheck|provision_resource|suspend|terminate|cleanup
            $t->string('job_type', 40);
            $t->string('queue', 40, false, 'deployment');
            $t->longText('payload');                          // JSON
            $t->string('status', 20, false, 'queued');        // queued|leased|running|completed|failed|dead|cancelled
            $t->integer('priority', false, 100);               // lower runs first
            $t->integer('attempts', false, 0);
            $t->integer('max_attempts', false, 5);
            $t->datetime('available_at', false);               // backoff / scheduled time
            $t->datetime('leased_at', true);
            $t->datetime('lease_expires_at', true);
            $t->string('leased_by', 120, true);                // worker identity
            $t->datetime('started_at', true);
            $t->datetime('completed_at', true);
            $t->string('error_code', 60, true);
            $t->text('error_message');
            $t->longText('result');                            // JSON
            $t->string('idempotency_key', 120, true);
            $t->bigInteger('installation_id', true);
            $t->bigInteger('deployment_id', true);
            $t->bigInteger('server_id', true);
            $t->bigInteger('client_id', true);
            $t->string('requested_by', 120, true);
            $t->timestamps();
            $t->unique(['uuid']);
            $t->index(['status', 'available_at', 'priority']);
            $t->index(['queue', 'status']);
            $t->index(['installation_id']);
            $t->index(['idempotency_key']);
        });

        /* ---------------------------------------------------------- deployments -- */
        $m->create('deployments', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 36);
            $t->string('reference', 40);                       // DEP-XXXXXXXX
            $t->bigInteger('installation_id', false, 0);
            $t->bigInteger('server_id', true);
            $t->bigInteger('job_id', true);
            // install|start|stop|restart|update|backup|restore|reinstall|uninstall|
            // ssl_provision|domain_configure|healthcheck|suspend|terminate
            $t->string('action', 30);
            // queued|running|succeeded|failed|rolling_back|rolled_back|cancelled|timeout
            $t->string('status', 20, false, 'queued');
            $t->string('adapter', 30, false, 'docker');
            $t->string('idempotency_key', 120);
            $t->string('requested_by', 120, true);
            $t->bigInteger('requested_by_id', true);
            $t->string('requested_by_type', 20, false, 'customer');
            $t->integer('progress', false, 0);                 // 0–100 for the console
            $t->string('current_step', 120, true);
            $t->integer('steps_total', false, 0);
            $t->integer('steps_completed', false, 0);
            $t->integer('attempts', false, 0);
            $t->longText('payload');                           // JSON request snapshot (redacted)
            $t->longText('result');                            // JSON summary
            $t->string('error_code', 60, true);
            $t->text('error_message');
            $t->bigInteger('failed_step_id', true);
            $t->bigInteger('rollback_of', true);               // deployment this one rolled back
            $t->boolean('rolled_back', 0);
            $t->datetime('queued_at', true);
            $t->datetime('started_at', true);
            $t->datetime('completed_at', true);
            $t->integer('duration_ms', true);
            $t->timestamps();
            $t->unique(['uuid']);
            $t->unique(['idempotency_key']);
            $t->index(['installation_id', 'id']);
            $t->index(['status']);
            $t->index(['server_id']);
            $t->foreign('installation_id', 'installations', 'id', 'CASCADE');
        });

        /* --------------------------------------------- deployment steps -- */
        $m->create('deployment_steps', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('deployment_id', false, 0);
            $t->integer('step_order', false, 0);
            $t->string('name', 120);                           // "Pull image", "Configure Traefik", …
            $t->string('key', 80);                             // stable machine key, e.g. pull_image
            // pending|running|succeeded|failed|skipped|rolled_back
            $t->string('status', 20, false, 'pending');
            $t->boolean('reversible', 1);                      // has a compensating action
            $t->boolean('creates_resource', 0);
            $t->longText('output');
            $t->text('error');
            $t->string('error_code', 60, true);
            $t->datetime('started_at', true);
            $t->datetime('completed_at', true);
            $t->integer('duration_ms', true);
            $t->integer('attempts', false, 0);
            $t->longText('context');                           // JSON: resource ids created by this step
            $t->timestamps();
            $t->unique(['deployment_id', 'step_order']);
            $t->index(['deployment_id', 'status']);
            $t->foreign('deployment_id', 'deployments', 'id', 'CASCADE');
        });

        /* --------------------------------------------- deployment log lines -- */
        // Streaming log for the console: one row per line, written by the worker
        // and by agent callbacks. Kept separate from the centralised logs table
        // because it is high-volume and short-lived.
        $m->create('deployment_logs', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('deployment_id', false, 0);
            $t->bigInteger('step_id', true);
            $t->string('level', 10, false, 'info');
            $t->string('source', 30, false, 'worker');         // worker|agent|adapter|docker|traefik|cpanel|kubernetes
            $t->text('message');
            $t->datetime('created_at', false);
            $t->index(['deployment_id', 'id']);
            $t->foreign('deployment_id', 'deployments', 'id', 'CASCADE');
        });

        /* --------------------------------------- created-resource ledger -- */
        // Everything the engine creates is recorded here the moment it exists, so
        // a failure at any later step can be compensated precisely and nothing is
        // left behind unrecorded (specification §14).
        $m->create('created_resources', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('deployment_id', false, 0);
            $t->bigInteger('installation_id', true);
            $t->bigInteger('server_id', true);
            // container|compose_project|network|volume|database|database_user|
            // traefik_route|dns_record|certificate|directory|cpanel_account|
            // k8s_namespace|k8s_deployment|k8s_service|k8s_ingress|k8s_pvc
            $t->string('resource_type', 40);
            $t->string('resource_name', 255);
            $t->longText('metadata');                          // JSON
            $t->string('status', 20, false, 'created');        // created|rollback_pending|removed|leaked
            $t->datetime('created_at', false);
            $t->datetime('removed_at', true);
            $t->index(['deployment_id']);
            $t->index(['installation_id', 'status']);
            $t->index(['status']);
            $t->foreign('deployment_id', 'deployments', 'id', 'CASCADE');
        });
    },
];
