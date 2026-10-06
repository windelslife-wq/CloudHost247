<?php
/**
 * Domain auctions: listings, bids (proxy maxima supported), watchlist,
 * an append-only event journal and the invoice linkage that settles a win.
 */

use Chs\Core\Migrator;

return [
    'id' => '0004_auctions',
    'description' => 'Domain auctions: listings, bids, watchlist, event journal, invoice map',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('auctions', function ($t) {
            $t->id()
                ->string('domain', 255)->index('domain')
                ->string('tld', 63)->index('tld')
                ->bigInteger('seller_client_id', true)->index('seller_client_id')
                ->string('status', 24, false, 'draft')->index('status')
                ->bigInteger('start_price_minor', false, 0)
                ->bigInteger('reserve_minor', true)
                ->bigInteger('bin_price_minor', true)
                ->string('currency', 3, false, 'USD')
                ->integer('bids_count', false, 0)
                ->bigInteger('highest_bid_minor', true)
                ->bigInteger('highest_bidder_id', true)
                ->boolean('reserve_met', 0)
                ->string('description', 500, false, '')
                ->dateTime('starts_at')->index('starts_at')
                ->dateTime('ends_at')->index('ends_at')
                ->integer('extensions_used', false, 0)
                ->dateTime('closed_at')
                ->timestamps();
        });

        $m->createTable('auction_bids', function ($t) {
            $t->id()
                ->bigInteger('auction_id')->index('auction_id')
                ->foreign('auction_id', 'auctions', 'id', 'cascade')
                ->bigInteger('bidder_client_id')->index('bidder_client_id')
                ->bigInteger('amount_minor')
                ->bigInteger('max_amount_minor', true)
                ->string('status', 16, false, 'active')->index('status')
                ->string('source', 8, false, 'web')
                ->string('ip_hash', 64, false, '')
                ->string('submit_token', 64, false, '')
                ->unique(['auction_id', 'submit_token'], 'uq_auction_submit')
                ->dateTime('created_at')->index('created_at');
        });

        $m->createTable('auction_watch', function ($t) {
            $t->bigInteger('auction_id')
                ->bigInteger('client_id')
                ->unique(['auction_id', 'client_id'], 'uq_watch')
                ->foreign('auction_id', 'auctions', 'id', 'cascade')
                ->dateTime('created_at');
        });

        $m->createTable('auction_events', function ($t) {
            $t->id()
                ->bigInteger('auction_id')->index('auction_id')
                ->foreign('auction_id', 'auctions', 'id', 'cascade')
                ->string('type', 48)->index('type')
                ->string('actor_type', 16, false, 'system')
                ->bigInteger('actor_id', false, 0)
                ->longText('payload')
                ->dateTime('created_at');
        });

        $m->createTable('auction_invoices', function ($t) {
            $t->id()
                ->bigInteger('auction_id')->index('auction_id')
                ->foreign('auction_id', 'auctions', 'id', 'cascade')
                ->bigInteger('invoice_id')->unique('invoice_id')
                ->bigInteger('client_id')->index('client_id')
                ->bigInteger('amount_minor')
                ->string('currency', 3, false, 'USD')
                ->string('status', 16, false, 'open')->index('status')
                ->dateTime('due_at')
                ->dateTime('paid_at')
                ->dateTime('created_at');
        });
    },
];
