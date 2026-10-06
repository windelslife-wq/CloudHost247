<?php
/**
 * Domain Broker — 0002: negotiation, offers, messaging and owner contacts.
 *
 * `offers` and `negotiations` are append-only: there is no deleted_at column
 * and no code path that deletes a row. A counteroffer is a new record that
 * points at its parent, so the full ladder of amounts is preserved forever.
 *
 * @package DomainBroker
 */

use DomainBroker\Core\Blueprint;
use DomainBroker\Core\Db;
use DomainBroker\Core\Migrator;

return [
    'id' => '0002_create_negotiation_tables',
    'description' => 'Negotiation rounds, immutable offers, messaging and encrypted owner contacts.',
    'up' => function (Migrator $m) {

        /* -------------------------------------------------- negotiations -- */
        $m->create('negotiations', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('request_id');
            $t->integer('round', false, 1);
            $t->string('status', 30, false, 'open');  // open|awaiting_owner|awaiting_customer|closed
            $t->string('channel', 30, true);          // email|phone|marketplace|registrar|other
            $t->string('initiated_by_type', 20, false, 'broker');
            $t->integer('initiated_by_id', false, 0);
            $t->text('summary');                      // broker-authored, visible to customer
            $t->text('owner_response');               // what the registrant said (sanitised)
            $t->bigInteger('opening_offer_id', true);
            $t->bigInteger('closing_offer_id', true);
            $t->string('outcome', 30, true);          // countered|accepted|rejected|no_response
            $t->datetime('opened_at', false);
            $t->datetime('owner_contacted_at', true);
            $t->datetime('owner_responded_at', true);
            $t->datetime('closed_at', true);
            $t->timestamps();

            $t->index(['request_id', 'round']);
            $t->index(['status']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
        });

        /* --------------------------------------------------------- offers -- */
        $m->create('offers', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40);               // OF-XXXXXXXX
            $t->bigInteger('request_id');
            $t->bigInteger('negotiation_id', true);
            $t->bigInteger('parent_offer_id', true);   // the offer this answers
            $t->integer('round', false, 1);
            $t->integer('sequence', false, 1);         // ordinal within the request

            $t->money('amount_minor', false);
            $t->char('currency', 3);

            $t->string('direction', 20, false);        // to_owner|to_customer
            $t->string('sender_party', 20, false);     // customer|broker|owner
            $t->string('recipient_party', 20, false);  // customer|broker|owner
            $t->string('created_by_type', 20, false);  // customer|broker|admin|system
            $t->integer('created_by_id', false, 0);
            $t->string('created_by_label', 190, true);

            $t->boolean('is_counteroffer', 0);
            $t->boolean('requires_customer_approval', 0);
            $t->string('status', 20, false, 'pending');
            $t->text('message');                       // shared with the counterparty
            $t->text('internal_note');                 // broker only

            $t->datetime('expires_at', true);
            $t->datetime('responded_at', true);
            $t->string('responded_by_type', 20, true);
            $t->integer('responded_by_id', true);
            $t->datetime('accepted_at', true);
            $t->datetime('rejected_at', true);
            $t->text('rejection_reason');

            // Fee snapshot taken at the moment of acceptance; never recomputed.
            $t->money('fee_minor');
            $t->money('tax_minor');
            $t->money('total_minor');
            $t->bigInteger('fee_rule_id', true);

            $t->timestamps();

            $t->unique(['reference']);
            $t->index(['request_id', 'sequence']);
            $t->index(['request_id', 'status']);
            $t->index(['status', 'expires_at']);
            $t->index(['parent_offer_id']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
            $t->foreign('negotiation_id', Db::table('negotiations'), 'id', 'SET NULL');
        });

        /* ------------------------------------------------------- messages -- */
        $m->create('messages', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('request_id');
            // Threads are physically separated so an internal note can never be
            // served on a customer query by accident.
            $t->string('thread', 20, false, 'customer'); // customer|internal|owner
            $t->string('sender_type', 20, false);        // customer|broker|admin|system
            $t->integer('sender_id', false, 0);
            $t->string('sender_label', 190, true);
            $t->string('recipient_type', 20, true);
            $t->longText('body');
            $t->string('body_format', 10, false, 'text');
            $t->boolean('is_internal', 0);
            $t->bigInteger('document_id', true);
            $t->datetime('read_at', true);
            $t->string('read_by', 60, true);
            $t->string('ip_address', 45, true);
            $t->timestamps();
            $t->softDeletes();

            $t->index(['request_id', 'thread']);
            $t->index(['request_id', 'read_at']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
        });

        /* ------------------------------------------- owner contact vault -- */
        // Anonymous brokerage: the registrant's identity lives here, encrypted
        // at rest, and is only ever decrypted for a principal holding
        // Rbac::OWNER_CONTACT_VIEW.
        $m->create('contacts', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('request_id');
            $t->string('role', 20, false, 'owner');     // owner|owner_agent|registrar
            $t->text('name_enc');
            $t->text('organisation_enc');
            $t->text('email_enc');
            $t->string('email_index', 64, true);        // blind index for exact match
            $t->text('phone_enc');
            $t->text('address_enc');
            $t->text('notes_enc');
            $t->string('preferred_channel', 30, true);
            $t->string('source', 40, true);             // rdap|registrar|marketplace|inbound
            $t->boolean('verified', 0);
            $t->datetime('verified_at', true);
            $t->string('created_by_type', 20, false, 'broker');
            $t->integer('created_by_id', false, 0);
            $t->timestamps();
            $t->softDeletes();

            $t->index(['request_id']);
            $t->index(['email_index']);
            $t->foreign('request_id', Db::table('requests'), 'id', 'CASCADE');
        });

        /* -------------------------------------------------- activity log -- */
        // Append-only. No deleted_at, no update path anywhere in the module.
        $m->create('activity', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('request_id', true);
            $t->string('entity_type', 40, false, 'request');
            $t->bigInteger('entity_id', false, 0);
            $t->string('action', 80);
            $t->string('actor_type', 20, false);
            $t->integer('actor_id', false, 0);
            $t->string('actor_label', 190, true);
            $t->string('actor_identity', 60, true);
            $t->string('actor_role', 60, true);
            $t->longText('previous_value');
            $t->longText('new_value');
            $t->text('reason');
            $t->string('visibility', 20, false, 'internal');
            $t->string('ip_address', 45, true);
            $t->string('user_agent', 400, true);
            $t->longText('meta');
            $t->char('record_hash', 64);
            $t->datetime('created_at', false);

            $t->index(['request_id', 'id']);
            $t->index(['action']);
            $t->index(['actor_type', 'actor_id']);
            $t->index(['created_at']);
            $t->index(['visibility']);
        });
    },
];
