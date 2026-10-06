<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DuplicateOperationException;
use Chs\Core\ForbiddenException;
use Chs\Core\Identity;
use Chs\Core\InvalidTransitionException;
use Chs\Core\Money;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\AuctionService;
use Chs\Workflow\AuctionStatus;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_freeze();

// Ava(11) owns two domains; Ben(22) and Cy(33) are bidders.
$gateway->clientDomains = [
    11 => [
        ['domain' => 'avas-gem.com'],
        ['domain' => 'riverside.io'],
    ],
    22 => [['domain' => 'bens.town']],
];

T::section('Listed correctly, validated honestly');
$svc = new AuctionService();
$listed = $svc->createListing([
    'domain' => 'avas-gem.com', 'start_minor' => 2000, 'reserve_minor' => 9000,
    'bin_minor' => 50000, 'duration_days' => 7, 'currency' => 'USD',
    'description' => 'Short, brandable .com',
], 11, false);
T::eq('status active (starts now)', AuctionStatus::ACTIVE, $listed['status']);
T::eq('seller recorded', 11, (int) $listed['seller_client_id']);
T::eq('end is 7 days out', '2026-10-13 12:00:00', $listed['ends_at']);
T::eq('zero bids at open', 0, (int) $listed['bids_count']);
T::ok('listing journaled', Db::count('auction_events', ['auction_id' => (int) $listed['id'], 'type' => 'listed']) === 1);

T::throws('start under 1.00 rejected', function () use ($svc) {
    $svc->createListing(['domain' => 'riverside.io', 'start_minor' => 50, 'duration_days' => 7], 11, false);
}, ValidationException::class);
T::throws('reserve below start rejected', function () use ($svc) {
    $svc->createListing(['domain' => 'riverside.io', 'start_minor' => 10000, 'reserve_minor' => 500, 'duration_days' => 7], 11, false);
}, ValidationException::class);
T::throws('BIN below +20% rejected', function () use ($svc) {
    $svc->createListing(['domain' => 'riverside.io', 'start_minor' => 10000, 'bin_minor' => 11000, 'duration_days' => 7], 11, false);
}, ValidationException::class);
T::throws('overlong duration rejected', function () use ($svc) {
    $svc->createListing(['domain' => 'riverside.io', 'start_minor' => 10000, 'duration_days' => 90], 11, false);
}, ValidationException::class);
T::throws('spoofing another persons domain rejected', function () use ($svc) {
    $svc->createListing(['domain' => 'someone-elses.com', 'start_minor' => 1000, 'duration_days' => 7], 22, false);
}, \Chs\Core\ChsException::class);
T::throws('tomfoolery over duplicate live auction', function () use ($svc) {
    $svc->createListing(['domain' => 'avas-gem.com', 'start_minor' => 1000, 'duration_days' => 7], 11, false);
}, ValidationException::class);
T::ok('admin can list any domain at own currency choice', (function () use ($svc) {
    $r = $svc->createListing(['domain' => 'admin-special.xyz', 'start_minor' => 500, 'duration_days' => 3, 'currency' => 'USD'], null, true);
    return $r['seller_client_id'] === null && $r['currency'] === 'USD';
})());

T::section('Bidding basics: minimum, ownership, state windows');
$aid = (int) $listed['id'];
T::throws('seller cannot bid on own listing', function () use ($svc, $aid) {
    $svc->placeBid(11, $aid, 2000);
}, ForbiddenException::class);
T::throws('bid below start rejected', function () use ($svc, $aid) {
    $svc->placeBid(22, $aid, 1500);
}, ValidationException::class);
T::throws('bid below 1.00 rejected', function () use ($svc, $aid) {
    $svc->placeBid(22, $aid, 42);
}, ValidationException::class);
T::throws('max below opening rejected', function () use ($svc, $aid) {
    $svc->placeBid(22, $aid, 3000, 2000);
}, ValidationException::class);
T::throws('ghost client cannot bid', function () use ($svc, $aid) {
    $svc->placeBid(999, $aid, 3000);
}, ForbiddenException::class);

$bid1 = $svc->placeBid(22, $aid, 2000, null, 'token-ben-1');
T::eq('first bid at start becomes leader', 'high', $bid1['state']);
T::ok('leader flag honest', $bid1['leader_is_you'] === true);
T::eq('current equals opening', 2000, $bid1['current_minor']);
$row = Db::first('auctions', ['id' => $aid]);
T::eq('auction reflects leader', 22, (int) $row['highest_bidder_id']);
T::eq('bid count one', 1, (int) $row['bids_count']);
T::ok('bid row active with token', (function () use ($aid) {
    $b = Db::first('auction_bids', ['auction_id' => $aid]);
    return $b && $b['status'] === 'active' && $b['submit_token'] === 'token-ben-1';
})());
T::eq('reserve not yet met', 0, (int) $row['reserve_met']);

T::section('Idempotent double-submit is rejected verbatim');
T::throws('same submit token cannot double-record', function () use ($svc, $aid) {
    $svc->placeBid(22, $aid, 5000, null, 'token-ben-1');
}, DuplicateOperationException::class);
T::eq('still one bid in table', 1, Db::count('auction_bids', ['auction_id' => $aid]));

T::section('Increment ladder');
T::eq('ladder: under $25', 500, $svc->incrementFor(1999));
T::eq('ladder: $25–$100', 1000, $svc->incrementFor(2500));
T::eq('ladder: mid', 2500, $svc->incrementFor(10000));
T::eq('ladder: high', 10000, $svc->incrementFor(100000));
T::eq('ladder: six-figure', 50000, $svc->incrementFor(500000));
T::eq('ladder: seven-figure', 100000, $svc->incrementFor(1000000));

T::section('Proxy bidding: ceiling defends previous leader');
// Ben sets a proxy ceiling of 20000.
$svc->placeBid(22, $aid, 9000, 20000, 'token-ben-2');
$after = Db::first('auctions', ['id' => $aid]);
T::eq('ben still leads at own opening', 22, (int) $after['highest_bidder_id']);
T::eq('price now 9000', 9000, (int) $after['highest_bid_minor']);
T::eq('reserve met reached', 1, (int) $after['reserve_met']);

// Cy bids 12000 — inside Ben's ceiling; proxy must answer 12000+2500.
$fight = $svc->placeBid(33, $aid, 12000, null, 'token-cy-1');
T::eq('cy informed of loss', 'outbid_by_proxy', $fight['state']);
T::eq('leader is NOT cy', false, $fight['leader_is_you']);
$after = Db::first('auctions', ['id' => $aid]);
T::eq('proxy defends at 14500', 14500, (int) $after['highest_bid_minor']);
T::eq('ben remains leader', 22, (int) $after['highest_bidder_id']);
T::ok('proxy bid journaled as such', Db::query(
    'SELECT * FROM ' . Db::t('auction_bids') . " WHERE auction_id = ? AND source = 'proxy'",
    [$aid]
) !== []);
T::eq('4 bids total (2 ben, 1 cy, 1 proxy)', 4, Db::count('auction_bids', ['auction_id' => $aid]));

// Cy surpasses the ceiling: 20000 + increment 2500 → 22500 wins outright.
$win = $svc->placeBid(33, $aid, 22500, null, 'token-cy-2');
T::eq('cy overtakes the ceiling', 'high', $win['state']);
$after = Db::first('auctions', ['id' => $aid]);
T::eq('price 22500', 22500, (int) $after['highest_bid_minor']);
T::eq('cy is leader', 33, (int) $after['highest_bidder_id']);
T::ok('outbid notification to ben', Db::count('notifications', ['client_id' => 22, 'type' => 'auction_outbid']) >= 1);

T::section('Watchlist + watcher notifications');
$svc->watch(11, $aid);
$svc->watch(11, $aid); // idempotent
T::eq('watch stored once', 1, Db::count('auction_watch', ['auction_id' => $aid, 'client_id' => 11]));
T::ok('isWatching true', $svc->isWatching(11, $aid));
$svc->placeBid(22, $aid, 25000, null, 'token-ben-3');
T::ok('watcher notified of movement', Db::count('notifications', ['client_id' => 11, 'type' => 'auction_watch_bid']) >= 1);
$svc->unwatch(11, $aid);
T::eq('unwatch clears', 0, Db::count('auction_watch', ['auction_id' => $aid, 'client_id' => 11]));

T::section('Anti-sniping: late bid extends the closing time');
Settings::override('anti_snipe_window_seconds', '300');
Settings::override('anti_snipe_extend_seconds', '300');
Settings::override('anti_snipe_max_extensions', '2');
$endsBefore = Db::first('auctions', ['id' => $aid])['ends_at'];
chs_freeze('+7 days'); chs_freeze('-4 minutes'); // land inside the last-5-min window
$snipe = $svc->placeBid(33, $aid, 27500, null, 'token-cy-3');
T::ok('late bid flagged as extending', $snipe['extended'] === true);
$after = Db::first('auctions', ['id' => $aid]);
T::eq('end pushed 5 min past now', '2026-10-13 12:01:00', $after['ends_at']);
T::eq('one extension used', 1, (int) $after['extensions_used']);
T::ok('ends_at really moved', $after['ends_at'] !== $endsBefore);
// second snipe → second extension allowed, third must be denied.
chs_freeze('+4 minutes');
$snipe2 = $svc->placeBid(22, $aid, 30000, null, 'token-ben-4');
T::ok('second extension allowed', $snipe2['extended'] === true);
chs_freeze('+4 minutes');
$snipe3 = $svc->placeBid(33, $aid, 32500, null, 'token-cy-4');
T::ok('extension cap respected', $snipe3['extended'] === false);
T::eq('exactly two extensions used', 2, (int) Db::first('auctions', ['id' => $aid])['extensions_used']);
Settings::override('anti_snipe_max_extensions', null);

T::section('Close + settle: reserve met → invoice + sold');
chs_freeze('+6 minutes'); // past final end
$outcome = (new AuctionService())->heartbeat();
T::eq('two auctions closed by timer (ours + the admin one)', 2, $outcome['closed']);
$a = Db::first('auctions', ['id' => $aid]);
T::eq('status sold', AuctionStatus::SOLD, $a['status']);
T::ok('winner invoiced via gateway', count($gateway->callsTo('createInvoice')) === 1);
$inv = $gateway->callsTo('createInvoice')[0];
T::eq('invoice charged to winner cy', 33, $inv['client']);
T::eq('invoice price equals winning 32500', 32500, (int) $inv['items'][0]['amount_minor']);
T::ok('settlement link row', (function () use ($aid) {
    return Db::first('auction_invoices', ['auction_id' => $aid, 'status' => 'open']) !== null;
})());
T::ok('winner got notification', Db::count('notifications', ['client_id' => 33, 'type' => 'auction_won']) >= 1);
T::ok('seller got notification', Db::count('notifications', ['client_id' => 11, 'type' => 'auction_sold']) >= 1);
T::ok('closing journaled', Db::count('auction_events', ['auction_id' => $aid, 'type' => 'closed_sold']) >= 1);
T::ok('winning bid flipped to won', (function () use ($aid) {
    $wins = Db::query('SELECT * FROM ' . Db::t('auction_bids') . " WHERE auction_id = ? AND status = 'won'", [$aid]);
    return count($wins) === 1 && (int) $wins[0]['amount_minor'] === 32500;
})());
T::ok('every non-winning bid ended in a terminal state', (function () use ($aid) {
    $total = Db::count('auction_bids', ['auction_id' => $aid]);
    $open = Db::count('auction_bids', ['auction_id' => $aid, 'status' => 'active']);
    $lost = Db::count('auction_bids', ['auction_id' => $aid, 'status' => 'lost'])
        + Db::count('auction_bids', ['auction_id' => $aid, 'status' => 'outbid']);
    return $open === 0 && ($lost + 1) === $total;
})());
T::throws('bidding a settled auction is refused', function () use ($svc, $aid) {
    $svc->placeBid(22, $aid, 50000);
}, InvalidTransitionException::class);

T::section('Invoice paid → paid state; idempotent on replay');
$invoiceId = (int) $inv['invoice'];
(new AuctionService())->invoicePaid($invoiceId);
$a = Db::first('auctions', ['id' => $aid]);
T::eq('status paid', AuctionStatus::PAID, $a['status']);
T::eq('invoice link paid', 'paid', Db::first('auction_invoices', ['auction_id' => $aid])['status']);
(new AuctionService())->invoicePaid($invoiceId); // replayed webhook
T::ok('replay harmless', Db::first('auctions', ['id' => $aid])['status'] === AuctionStatus::PAID);

T::section('Reserve missed → ended, no invoice');
$gateway->availability = [];
$gateway->clientDomains[11][2] = ['domain' => 'reserve-miss.com'];
$svc2 = new AuctionService();
$l2 = $svc2->createListing([
    'domain' => 'reserve-miss.com', 'start_minor' => 1000, 'reserve_minor' => 100000,
    'duration_days' => 1, 'currency' => 'USD',
], 11, false);
$svc2->placeBid(22, (int) $l2['id'], 2000, null, 'token-rm-1');
chs_freeze('+2 days');
(new AuctionService())->heartbeat();
$fin = Db::first('auctions', ['id' => (int) $l2['id']]);
T::eq('missed reserve → ended not sold', AuctionStatus::ENDED, $fin['status']);
T::eq('no new invoice created', 1, count($gateway->callsTo('createInvoice')));
T::ok('bidder told they did not meet reserve or sold', Db::count('notifications', ['client_id' => 22]) >= 1);
T::ok('journal records no-sale outcome', (function () use ($l2) {
    return Db::count('auction_events', ['auction_id' => (int) $l2['id'], 'type' => 'closed_unsold']) >= 1;
})());

T::section('BIN ends immediately, skips proxy');
$gateway->clientDomains[11][3] = ['domain' => 'bin-special.com'];
$l3 = (new AuctionService())->createListing([
    'domain' => 'bin-special.com', 'start_minor' => 1000, 'bin_minor' => 5000,
    'duration_days' => 3, 'currency' => 'USD',
], 11, false);
$bin = (new AuctionService())->placeBid(22, (int) $l3['id'], 5000, null, 'token-bin-1');
T::eq('bin flag returned', 'won_bin', $bin['state']);
$binRow = Db::first('auctions', ['id' => (int) $l3['id']]);
T::eq('auction immediately sold', AuctionStatus::SOLD, $binRow['status']);
T::eq('price recorded at bin', 5000, (int) $binRow['highest_bid_minor']);
T::eq('bin invoice created for bidder', 2, count($gateway->callsTo('createInvoice')));

T::section('Overdue invoice → cancelled unpaid (safety net)');
$overdueInv = (int) $gateway->callsTo('createInvoice')[1]['invoice'];
// due_at was captured at creation (default 3 days) — go past it
chs_freeze('+4 days');
$laps = (new AuctionService())->lapseOverdueInvoices();
$row = Db::first('auctions', ['id' => (int) $l3['id']]);
T::eq('lapsed → cancelled_unpaid', AuctionStatus::CANCELLED_UNPAID, $row['status']);
T::eq('invoice marked lapsed', 'lapsed', Db::first('auction_invoices', ['auction_id' => (int) $l3['id']])['status']);
T::ok('gateway invoice cancelled', $gateway->invoiceStatus($overdueInv) === 'Cancelled');
T::ok('buyer notified of lapse', Db::count('notifications', ['client_id' => 22, 'type' => 'auction_lapsed']) >= 1);

T::section('Admin oversight');
\Chs\Core\Identity::setAdmin(1);
$admin = new AuctionService();
$list = $admin->adminList(AuctionStatus::CANCELLED_UNPAID);
T::eq('admin filter works', 1, count($list));
T::ok('journal readable for admin', count($admin->journalFor($aid)) >= 3);
// admin cancel on a live auction
$l4 = (new AuctionService())->createListing([
    'domain' => 'riverside.io', 'start_minor' => 1000, 'duration_days' => 2, 'currency' => 'USD',
], 11, false);
$admin->adminCancel((int) $l4['id'], 1, 'Suspicious listing');
T::eq('admin cancel applies', AuctionStatus::CANCELLED, Db::first('auctions', ['id' => (int) $l4['id']])['status']);
T::throws('cancelling twice refused', function () use ($admin, $l4) {
    $admin->adminCancel((int) $l4['id'], 1, 'again');
}, InvalidTransitionException::class);

T::section('Masquerade protection');
$_SESSION['adminid'] = 1;
$_SESSION['uid'] = 22;
$l5 = (new AuctionService())->createListing([
    'domain' => 'avas-gem.com', 'start_minor' => 1000, 'duration_days' => 2, 'currency' => 'USD',
], null, true);
T::throws('bids blocked under admin masquerade', function () use ($l5) {
    (new AuctionService())->placeBid(22, (int) $l5['id'], 1000);
}, ForbiddenException::class);
unset($_SESSION['adminid'], $_SESSION['uid']);
Identity::setAdmin(null);
Identity::setClient(null);

T::finish();
