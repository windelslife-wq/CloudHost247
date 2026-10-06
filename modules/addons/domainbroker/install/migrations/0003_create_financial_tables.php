<?php
/**
 * Domain Broker — 0003: payments, escrow, transfers, fee rules.
 *
 * Nothing here stores card data. The only payment identifiers kept are the
 * WHMCS invoice/transaction ids and an *encrypted* escrow provider reference.
 * Transfer authorisation (EPP) codes are likewise stored encrypted, with a
 * non-reversible hint for display.
 *
 * @package DomainBroker
 */

use DomainBroker\Core\Blueprint;
use DomainBroker\Core\Db;
use DomainBroker\Core\Migrator;

return [
    'id' => '0003_create_financial_tables',
    'description' => 'Fee rules, payments/escrow ledger and domain transfer tracking.',
    'up' => function (Migrator $m) {

        /* ------------------------------------------------------ fee rules -- */
        $m->create('fees', function (Blueprint $t) {
            $t->id();
            $t->string('code', 60);
            $t->string('name', 190);
            $t->text('description');
            $t->boolean('active', 1);
            $t->integer('priority', false, 100);        // lowest wins

            // Scope. NULL currency = applies to every currency.
            $t->char('currency', 3, true);
            $t->money('applies_min_minor');             // inclusive lower bound
            $t->money('applies_max_minor');             // 0/NULL = unbounded
            $t->string('tld_scope', 255, true);         // comma list, empty = all

            // Calculation.
            $t->string('calculation', 20, false, 'percentage'); // fixed|percentage|tiered
            $t->money('fixed_minor');
            $t->decimal('percentage', 7, 4, true);
            $t->longText('tiers');                      // JSON [{from,to,percentage,fixed_minor}]
            $t->money('min_fee_minor');
            $t->money('max_fee_minor');                 // 0/NULL = no cap

            // Tax. Rate is configurable; WHMCS tax rules take precedence when
            // the operator enables them on the invoice item.
            $t->decimal('tax_rate', 7, 4, true);
            $t->boolean('tax_inclusive', 0);
            $t->boolean('use_whmcs_tax', 1);

            // Promotional adjustment applied to the computed fee.
            $t->string('promo_code', 60, true);
            $t->decimal('discount_percentage', 7, 4, true);
            $t->money('discount_fixed_minor');
            $t->datetime('valid_from', true);
            $t->datetime('valid_to', true);

            $t->string('created_by', 190, true);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['code']);
            $t->index(['active', 'priority']);
            $t->index(['currency']);
        });

        /* -------------------------------------------------------- payments -- */
        $m->create('payments', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40);
            $t->bigInteger('request_id');
            $t->bigInteger('offer_id', true);            // the accepted offer
            $t->string('type', 30, false, 'acquisition'); // acquisition|refund|partial_refund
            $t->string('status', 30, false, 'none');

            $t->char('currency', 3);
            $t->money('acquisition_minor', false);
            $t->money('fee_minor', false);
            $t->money('tax_minor', false);
            $t->money('total_minor', false);
            $t->money('paid_minor');
            $t->money('refunded_minor');

            // WHMCS linkage — the invoice is the system of record for the money.
            $t->integer('whmcs_invoice_id', true);
            $t->integer('whmcs_transaction_id', true);
            $t->string('gateway', 60, true);
            $t->string('payment_method_label', 120, true);

            // Escrow abstraction. The reference is encrypted; the provider name
            // is not secret.
            $t->string('escrow_provider', 40, true);
            $t->text('escrow_reference_enc');
            $t->string('escrow_status', 40, true);
            $t->datetime('escrow_secured_at', true);
            $t->datetime('escrow_released_at', true);

            $t->string('idempotency_key', 190, true);
            $t->integer('attempts', false, 0);
            $t->integer('failed_attempts', false, 0);
            $t->text('failure_reason');

            $t->datetime('due_at', true);
            $t->datetime('invoiced_at', true);
            $t->datetime('paid_at', true);
            $t->datetime('secured_at', true);
            $t->datetime('released_at', true);
            $t->datetime('refunded_at', true);
            $t->datetime('failed_at', true);
            $t->datetime('expires_at', true);

            $t->timestamps();
            // Financial records are never deleted — not even softly.

            $t->unique(['reference']);
            $t->index(['request_id', 'status']);
            $t->index(['status', 'expires_at']);
            $t->index(['whmcs_invoice_id']);
            $t->index(['idempotency_key']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
        });

        /* ------------------------------------------------------- transfers -- */
        $m->create('transfers', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40);
            $t->bigInteger('request_id');
            $t->string('domain', 253);
            $t->string('status', 30, false, 'not_started');

            $t->string('losing_registrar', 190, true);
            $t->string('losing_registrar_iana', 20, true);
            $t->string('gaining_registrar', 190, true);
            $t->string('gaining_registrar_iana', 20, true);
            $t->string('destination_account', 190, true);
            $t->integer('whmcs_domain_id', true);
            $t->integer('whmcs_order_id', true);

            // Authorisation code: encrypted at rest, exposed only to principals
            // holding Rbac::TRANSFER_CREDENTIAL_VIEW, and only on demand.
            $t->text('auth_code_enc');
            $t->string('auth_code_hint', 20, true);      // e.g. "••••3f2a"
            $t->char('auth_code_fingerprint', 64, true);
            $t->string('auth_code_status', 30, false, 'not_requested');
            $t->datetime('auth_code_requested_at', true);
            $t->datetime('auth_code_received_at', true);

            $t->boolean('registrar_lock_released', 0);
            $t->boolean('whois_privacy_disabled', 0);
            $t->boolean('within_60_day_lock', 0);

            $t->datetime('initiated_at', true);
            $t->datetime('pending_since', true);
            $t->datetime('approved_at', true);
            $t->datetime('completed_at', true);
            $t->datetime('failed_at', true);
            $t->datetime('expires_at', true);
            $t->datetime('last_checked_at', true);

            $t->integer('attempts', false, 0);
            $t->text('failure_reason');
            $t->text('registrar_notes');
            $t->longText('registry_response');

            $t->timestamps();

            $t->unique(['reference']);
            $t->index(['request_id']);
            $t->index(['status', 'expires_at']);
            $t->index(['domain']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
        });

        /* --------------------------------------------------- verifications -- */
        $m->create('verifications', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('request_id');
            $t->string('type', 40);                 // registrar|ownership|transfer_authorization|kyc|manual_admin
            $t->string('status', 20, false, 'pending'); // pending|submitted|approved|rejected|waived|not_required
            $t->boolean('required', 1);
            $t->text('method');                     // how it was verified
            $t->text('reference_enc');              // KYC/provider reference, encrypted
            $t->string('provider', 60, true);
            $t->bigInteger('document_id', true);
            $t->string('checked_by_type', 20, true);
            $t->integer('checked_by_id', true);
            $t->datetime('checked_at', true);
            $t->datetime('expires_at', true);
            $t->text('notes');
            $t->text('rejection_reason');
            $t->timestamps();

            $t->index(['request_id', 'type']);
            $t->index(['status']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
        });
    },
];
