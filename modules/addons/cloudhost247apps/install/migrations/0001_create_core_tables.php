<?php
/**
 * App Cloud — 0001: platform core (settings, RBAC, audit, idempotency, queue
 * hygiene, event stream, centralised logs, API tokens).
 *
 * Foreign keys are declared between module tables only. WHMCS core tables
 * (tblclients, tblinvoices, tblhosting, tbldomains, tblservers, …) are
 * referenced by id and resolved through the Integration layer: constraining core
 * tables would make the module undeployable on hosted WHMCS and would block
 * WHMCS' own maintenance routines.
 *
 * @package Ch247Apps
 */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0001_create_core_tables',
    'description' => 'Settings, RBAC matrix, hash-chained audit log, idempotency, rate limits, events, logs, API tokens.',
    'up' => function (Migrator $m) {

        /* ------------------------------------------------------------ settings -- */
        $m->create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('setting', 120);
            $t->text('value');
            $t->string('updated_by', 120, true);
            $t->timestamps();
            $t->unique(['setting']);
        });

        /* ----------------------------------------------------------- RBAC matrix -- */
        $m->create('role_permissions', function (Blueprint $t) {
            $t->id();
            $t->string('role', 40);
            $t->string('permission', 120);
            $t->boolean('granted', 0);
            $t->timestamps();
            $t->unique(['role', 'permission']);
        });

        /* -------------------------------------------------- hash-chained audit log -- */
        $m->create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type', 20);
            $t->bigInteger('actor_id', false, 0);
            $t->string('actor_role', 40, false, 'guest');
            $t->string('actor_label', 120, true);
            $t->string('auth_method', 30, false, 'session');
            $t->string('action', 60);
            $t->string('resource_type', 60, true);
            $t->string('resource_id', 64, true);
            $t->bigInteger('client_id', true);
            $t->bigInteger('installation_id', true);
            $t->bigInteger('deployment_id', true);
            $t->bigInteger('server_id', true);
            $t->string('severity', 20, false, 'info');
            $t->string('ip_address', 45, true);
            $t->string('user_agent', 255, true);
            $t->longText('metadata');
            $t->char('previous_hash', 64, false, str_repeat('0', 64));
            $t->char('entry_hash', 64);
            $t->datetime('created_at', false);
            $t->index(['action']);
            $t->index(['client_id']);
            $t->index(['installation_id']);
            $t->index(['created_at']);
            $t->index(['resource_type', 'resource_id']);
        });

        /* --------------------------------------------------------- idempotency keys -- */
        $m->create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 80);
            $t->string('idempotency_key', 120);
            $t->char('payload_hash', 64);
            $t->string('status', 20, false, 'running');   // running|completed|failed
            $t->integer('attempts', false, 1);
            $t->longText('response');
            $t->datetime('expires_at', true);
            $t->datetime('completed_at', true);
            $t->timestamps();
            $t->unique(['scope', 'idempotency_key']);
            $t->index(['expires_at']);
        });

        /* ----------------------------------------------------------- rate limiting -- */
        $m->create('rate_limits', function (Blueprint $t) {
            $t->id();
            $t->string('bucket', 60);
            $t->string('identifier', 120);
            $t->bigInteger('window_start', false, 0);
            $t->integer('hits', false, 0);
            $t->timestamps();
            $t->unique(['bucket', 'identifier', 'window_start']);
        });

        /* ------------------------------------------------------------ event stream -- */
        $m->create('events', function (Blueprint $t) {
            $t->id();
            $t->string('event', 80);
            $t->bigInteger('installation_id', true);
            $t->bigInteger('deployment_id', true);
            $t->bigInteger('server_id', true);
            $t->bigInteger('client_id', true);
            $t->string('source', 40, false, 'platform');
            $t->longText('payload');
            $t->datetime('created_at', false);
            $t->index(['installation_id', 'id']);
            $t->index(['deployment_id', 'id']);
            $t->index(['server_id', 'id']);
            $t->index(['event']);
        });

        /* ----------------------------------------- centralised operational log -- */
        $m->create('logs', function (Blueprint $t) {
            $t->id();
            $t->string('level', 10);
            $t->string('source', 40, false, 'web');   // api|worker|agent|cron|admin|web|billing
            $t->string('message', 400);
            $t->longText('context');
            $t->bigInteger('deployment_id', true);
            $t->bigInteger('installation_id', true);
            $t->bigInteger('server_id', true);
            $t->datetime('created_at', false);
            $t->index(['level', 'created_at']);
            $t->index(['deployment_id']);
            $t->index(['installation_id']);
            $t->index(['source']);
        });

        /* ------------------------------------------------------------ API tokens -- */
        $m->create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->char('token_hash', 64);            // SHA-256 of the plaintext; plaintext never stored
            $t->string('token_prefix', 12, false, '');
            $t->string('actor_type', 20);          // customer|admin
            $t->bigInteger('actor_id', false, 0);
            $t->string('actor_role', 40, true);
            $t->string('actor_label', 120, true);
            $t->text('scopes');                    // JSON list, or empty = role surface
            $t->string('ip_allowlist', 500, true);
            $t->boolean('active', 1);
            $t->bigInteger('request_count', false, 0);
            $t->string('last_used_ip', 45, true);
            $t->datetime('last_used_at', true);
            $t->datetime('expires_at', true);
            $t->datetime('revoked_at', true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['token_hash']);
            $t->index(['actor_type', 'actor_id']);
        });
    },
];
