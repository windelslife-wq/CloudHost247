<?php
/**
 * App Cloud — 0011: WHMCS-service-bound control-panel accounts and jobs.
 *
 * The table links an existing, already-created panel account to one paid WHMCS
 * hosting service. It deliberately stores no cPanel password, login URL,
 * session token, or customer-delivery secret. Account creation remains disabled
 * until a separately reviewed credential handoff/SSO design exists.
 *
 * @package Ch247Apps
 */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0011_create_panel_account_workflow',
    'description' => 'WHMCS-bound panel accounts, lifecycle history, and control-panel queue correlation.',
    'up' => function (Migrator $m) {
        $m->create('panel_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 36);
            $t->bigInteger('client_id', false, 0);             // WHMCS tblclients.id
            $t->bigInteger('whmcs_service_id', false, 0);      // WHMCS tblhosting.id; authoritative
            $t->unsignedBigInteger('server_id', false, 0);     // registered App Cloud cPanel/WHM host
            $t->string('panel_key', 40, false, 'cpanel_whm');
            $t->string('username', 16);
            $t->string('domain', 253);
            $t->string('package', 80);
            // unverified|active|suspended|missing|terminated|error
            $t->string('status', 24, false, 'unverified');
            $t->string('pending_action', 24, true);
            $t->bigInteger('pending_job_id', true);
            $t->datetime('last_verified_at', true);
            $t->string('last_error_code', 60, true);
            $t->text('last_error_message');
            $t->string('linked_by', 120, true);
            $t->timestamps();
            $t->unique(['uuid']);
            $t->unique(['whmcs_service_id']);
            $t->unique(['server_id', 'panel_key', 'username']);
            $t->index(['client_id', 'status']);
            $t->index(['server_id', 'status']);
            $t->foreign('server_id', 'servers', 'id', 'RESTRICT');
        });

        $m->create('panel_account_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('panel_account_id', false, 0);
            $t->bigInteger('job_id', true);
            $t->string('event', 60);
            $t->string('from_status', 24, true);
            $t->string('to_status', 24, true);
            $t->longText('metadata');                           // redacted, secret-free JSON
            $t->string('actor_type', 20);
            $t->bigInteger('actor_id', false, 0);
            $t->string('actor_identity', 120, true);
            $t->datetime('created_at', false);
            $t->index(['panel_account_id', 'id']);
            $t->index(['event', 'created_at']);
            $t->foreign('panel_account_id', 'panel_accounts', 'id', 'CASCADE');
        });

        // Keep the queue's existing server_id meaning intact. Panel jobs carry
        // their own resource correlation instead of masquerading as deploy jobs.
        $columnType = Db::isSqlite() ? 'INTEGER NULL' : 'BIGINT NULL';
        $m->addColumn('jobs', 'panel_account_id', $columnType);
        $m->addColumn('events', 'panel_account_id', $columnType);
        $m->addIndex('jobs', ['panel_account_id']);
        $m->addIndex('events', ['panel_account_id', 'id']);
    },
];
