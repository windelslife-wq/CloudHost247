<?php
/**
 * Domain Broker — 0001: core request / broker / assignment tables.
 *
 * Foreign keys are declared between the module's own tables only. WHMCS core
 * tables (tblclients, tblinvoices, tbldomains, …) are referenced by id and
 * resolved through the Integration layer: adding constraints to core tables
 * would make the module undeployable on hosted WHMCS and would block WHMCS'
 * own maintenance routines.
 *
 * @package DomainBroker
 */

use DomainBroker\Core\Blueprint;
use DomainBroker\Core\Db;
use DomainBroker\Core\Migrator;

return [
    'id' => '0001_create_core_tables',
    'description' => 'Broker roster, acquisition requests and broker assignments.',
    'up' => function (Migrator $m) {

        /* ------------------------------------------------------- brokers -- */
        $m->create('brokers', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40);
            // A broker is an existing WHMCS staff member or client contact.
            // Nothing is hardcoded: the roster is populated by an administrator.
            $t->integer('whmcs_admin_id', true);
            $t->integer('whmcs_client_id', true);
            $t->string('display_name', 190);
            $t->string('email', 190, true);
            $t->string('phone', 60, true);
            $t->string('role', 40, false, 'broker');          // RBAC role name
            $t->string('status', 20, false, 'active');        // active|inactive|suspended
            $t->integer('max_active_requests', false, 25);
            $t->decimal('commission_percentage', 7, 4, true);
            $t->string('timezone', 64, true);
            $t->string('languages', 190, true);
            $t->text('specialities');
            $t->text('biography');
            $t->string('avatar_url', 255, true);
            $t->integer('completed_count', false, 0);
            $t->integer('active_count', false, 0);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['reference']);
            $t->index(['status']);
            $t->index(['whmcs_admin_id']);
            $t->index(['whmcs_client_id']);
        });

        /* ------------------------------------------------------ requests -- */
        $m->create('requests', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40);                 // DB-XXXXXXXX, shown to customers
            $t->integer('client_id', false, 0);          // tblclients.id
            $t->integer('contact_id', true);             // tblcontacts.id (sub-account)
            $t->integer('whmcs_user_id', true);          // tblusers.id of the submitter

            $t->string('domain', 253);                   // punycode, canonical
            $t->string('domain_display', 253, true);     // unicode presentation
            $t->string('tld', 64, true);
            $t->string('sld', 190, true);

            $t->string('status', 40, false, 'submitted');
            $t->string('previous_status', 40, true);
            $t->datetime('status_changed_at', true);

            $t->money('budget_minor', false);
            $t->char('currency', 3);
            $t->boolean('budget_includes_fees', 0);
            $t->boolean('anonymous', 1);
            $t->text('customer_message');
            $t->string('source', 20, false, 'client_area'); // client_area|api|admin

            $t->bigInteger('assigned_broker_id', true);
            $t->datetime('assigned_at', true);

            // Negotiation snapshot — the authoritative history lives in offers.
            $t->bigInteger('current_offer_id', true);
            $t->bigInteger('latest_customer_offer_id', true);
            $t->bigInteger('agreed_offer_id', true);
            $t->integer('negotiation_rounds', false, 0);

            // Financial snapshot, all in minor units of `currency`.
            $t->money('agreed_amount_minor');
            $t->money('broker_fee_minor');
            $t->money('tax_minor');
            $t->money('total_minor');
            $t->bigInteger('fee_rule_id', true);

            $t->string('payment_status', 30, false, 'none');
            $t->string('transfer_status', 30, false, 'not_started');
            $t->string('verification_status', 30, false, 'not_started');

            $t->integer('risk_score', false, 0);
            $t->string('risk_level', 20, false, 'low');  // low|medium|high
            $t->boolean('manual_review', 0);
            $t->boolean('kyc_required', 0);

            // WHMCS linkage.
            $t->integer('whmcs_invoice_id', true);
            $t->integer('whmcs_service_id', true);
            $t->integer('whmcs_domain_id', true);
            $t->integer('whmcs_ticket_id', true);

            $t->datetime('submitted_at', true);
            $t->datetime('expires_at', true);
            $t->datetime('completed_at', true);
            $t->datetime('closed_at', true);
            $t->datetime('last_customer_action_at', true);
            $t->datetime('last_broker_action_at', true);

            $t->string('ip_address', 45, true);
            $t->string('user_agent', 400, true);

            $t->timestamps();
            // Soft delete only: an acquisition request carries financial and
            // negotiation history and is never physically removed.
            $t->softDeletes();

            $t->unique(['reference']);
            $t->index(['client_id', 'status']);
            $t->index(['status']);
            $t->index(['assigned_broker_id', 'status']);
            $t->index(['domain']);
            $t->index(['payment_status']);
            $t->index(['transfer_status']);
            $t->index(['expires_at']);
            $t->index(['created_at']);
            $t->index(['manual_review']);
            $t->foreign('assigned_broker_id', Db::table('brokers'), 'id', 'SET NULL');
        });

        /* --------------------------------------------------- assignments -- */
        $m->create('assignments', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('request_id');
            $t->bigInteger('broker_id');
            $t->string('assignment_role', 20, false, 'primary'); // primary|secondary|observer
            $t->string('status', 20, false, 'active');           // active|released|reassigned
            $t->string('assigned_by_type', 20, false, 'admin');
            $t->integer('assigned_by_id', false, 0);
            $t->text('reason');
            $t->datetime('assigned_at', false);
            $t->datetime('released_at', true);
            $t->timestamps();

            $t->index(['request_id', 'status']);
            $t->index(['broker_id', 'status']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
            $t->foreign('broker_id', Db::table('brokers'), 'id', 'CASCADE');
        });

        /* ------------------------------------------------------ settings -- */
        $m->create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('setting_key', 100);
            $t->text('setting_value');
            $t->boolean('is_secret', 0);
            $t->string('updated_by', 190, true);
            $t->timestamps();
            $t->unique(['setting_key']);
        });

        /* ---------------------------------------------- role permissions -- */
        $m->create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('role', 40);
            $t->string('permission', 80);
            $t->boolean('granted', 0);
            $t->timestamps();
            $t->unique(['role', 'permission']);
            $t->index(['role']);
        });
    },
];
