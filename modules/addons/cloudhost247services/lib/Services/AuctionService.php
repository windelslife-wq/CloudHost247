<?php
/**
 * Domain auctions.
 *
 * Listings, proxy bidding, anti-sniping extensions, buy-it-now, reserves,
 * watchlists, an append-only event journal and settlement through the host
 * platform's invoicing — every write inside a transaction, every state change
 * journaled and audited, every notification fanned out through the
 * notification service.
 *
 * No bid is ever trusted from the client: amounts are re-validated server-side
 * against the current auction row read inside the same transaction.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\ChsException;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\DuplicateOperationException;
use Chs\Core\ForbiddenException;
use Chs\Core\Http;
use Chs\Core\Identity;
use Chs\Core\InvalidTransitionException;
use Chs\Core\Money;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\RateLimiter;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Workflow\AuctionStatus;

class AuctionService
{
    /** Minimum listing / bid windows, days. */
    const MIN_DURATION_DAYS = 1;
    const MAX_DURATION_DAYS = 30;

    /* ---------------------------------------------------------- listings -- */

    /**
     * Create a listing. Clients may only list domains they actually hold in
     * their account (verified against the platform); staff may list anything
     * (marketplace inventory), marked with seller_client_id NULL.
     *
     * @return array the created auction row
     */
    public function createListing(array $input, $sellerClientId = null, $isAdmin = false)
    {
        if (!Settings::bool('auction_enabled', true)) {
            throw new ChsException('Domain auctions are temporarily disabled.');
        }
        if (!$isAdmin && !Settings::bool('auction_client_listing', true)) {
            throw new ForbiddenException('Client listings are temporarily disabled.');
        }

        $errors = [];
        $domain = null;
        try {
            $domain = DomainName::parse(isset($input['domain']) ? $input['domain'] : '');
        } catch (ValidationException $e) {
            $errors = $e->fieldErrors();
        }

        $startMinor  = isset($input['start_minor']) ? (int) $input['start_minor'] : 0;
        $reserveMinor = isset($input['reserve_minor']) && $input['reserve_minor'] !== ''
            ? (int) $input['reserve_minor'] : null;
        $binMinor    = isset($input['bin_minor']) && $input['bin_minor'] !== ''
            ? (int) $input['bin_minor'] : null;
        $durationDays = isset($input['duration_days']) ? (int) $input['duration_days'] : 7;
        $startsAt    = !empty($input['starts_at']) ? (string) $input['starts_at'] : Clock::now();
        $currency    = !empty($input['currency']) ? strtoupper(substr((string) $input['currency'], 0, 3))
            : Platform::gateway()->defaultCurrency();
        $description = isset($input['description']) ? trim((string) $input['description']) : '';

        if ($domain && $this->findActiveByDomain($domain->fqdn())) {
            $errors['domain'] = 'That domain already has a live auction.';
        }
        if ($startMinor < 100) {
            $errors['start_price'] = 'The starting price must be at least 1.00.';
        }
        if ($reserveMinor !== null && $reserveMinor < $startMinor) {
            $errors['reserve'] = 'The reserve cannot be below the starting price.';
        }
        if ($binMinor !== null && $binMinor < (int) ceil($startMinor * 1.2)) {
            $errors['bin'] = 'Buy-It-Now must be at least 20% above the starting price.';
        }
        if ($durationDays < self::MIN_DURATION_DAYS || $durationDays > self::MAX_DURATION_DAYS) {
            $errors['duration'] = 'Auctions run between ' . self::MIN_DURATION_DAYS . ' and '
                . self::MAX_DURATION_DAYS . ' days.';
        }
        if (strlen($description) > 500) {
            $errors['description'] = 'Description is limited to 500 characters.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        if (!$isAdmin) {
            $this->assertClientOwnsDomain($domain->fqdn(), (int) $sellerClientId);
            $currency = Platform::gateway()->clientCurrency((int) $sellerClientId);
        }

        $startsT = Clock::toTime($startsAt);
        if ($startsT === null) {
            $startsT = Clock::time();
            $startsAt = Clock::now();
        }
        $status = $startsT <= Clock::time() ? AuctionStatus::ACTIVE : AuctionStatus::SCHEDULED;

        $id = Db::insert('auctions', [
            'domain'            => $domain->fqdn(),
            'tld'               => $domain->tld(),
            'seller_client_id'  => $sellerClientId ? (int) $sellerClientId : null,
            'status'            => $status,
            'start_price_minor' => $startMinor,
            'reserve_minor'     => $reserveMinor,
            'bin_price_minor'   => $binMinor,
            'currency'          => $currency,
            'bids_count'        => 0,
            'highest_bid_minor' => null,
            'highest_bidder_id' => null,
            'reserve_met'       => 0,
            'description'       => $description,
            'starts_at'         => $startsAt,
            'ends_at'           => gmdate('Y-m-d H:i:s', $startsT + $durationDays * 86400),
            'extensions_used'   => 0,
            'closed_at'         => null,
            'created_at'        => Clock::now(),
            'updated_at'        => Clock::now(),
        ]);

        $this->journal($id, 'listed', $isAdmin ? 'admin' : 'client', (int) ($isAdmin ? Identity::adminId() : $sellerClientId), [
            'start_minor' => $startMinor, 'currency' => $currency,
        ]);
        Audit::log($isAdmin ? Audit::ACTOR_ADMIN : Audit::ACTOR_CLIENT,
            (int) ($isAdmin ? Identity::adminId() : $sellerClientId),
            'auction.listed', ['auction' => $id, 'domain' => $domain->fqdn()], Http::clientIp());

        return Db::first('auctions', ['id' => $id]);
    }

    /** @return array|null */
    public function findActiveByDomain($fqdn)
    {
        $rows = Db::query(
            'SELECT id FROM ' . Db::t('auctions')
            . ' WHERE domain = ? AND status IN (\'active\', \'scheduled\') LIMIT 1',
            [strtolower($fqdn)]
        );
        return $rows ? $rows[0] : null;
    }

    /* ------------------------------------------------------------ browse -- */

    /**
     * Public catalogue. Seller identity is deliberately not selected.
     *
     * @return array{rows:array[], total:int, page:int, per_page:int}
     */
    public function browse(array $filters = [])
    {
        $page = max(1, isset($filters['page']) ? (int) $filters['page'] : 1);
        $perPage = max(1, min(60, isset($filters['per_page']) ? (int) $filters['per_page'] : 20));
        $where = ['status IN (\'active\', \'scheduled\', \'sold\', \'paid\')'];
        $bind = [];
        if (!empty($filters['status'])) {
            $where = ['status = ?'];
            $bind[] = (string) $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = 'domain LIKE ?';
            $bind[] = '%' . strtolower(trim($filters['search'])) . '%';
        }
        if (!empty($filters['tld'])) {
            $where[] = 'tld = ?';
            $bind[] = ltrim(strtolower($filters['tld']), '.');
        }
        if (!empty($filters['ending_soon'])) {
            $where[] = 'status = \'active\' AND ends_at <= ?';
            $bind[] = Clock::in(6 * 3600);
        }
        if (!empty($filters['no_reserve'])) {
            $where[] = '(reserve_minor IS NULL OR reserve_minor <= start_price_minor)';
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $total = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('auctions') . $whereSql, $bind);
        $total = $total ? (int) $total[0]['c'] : 0;

        $sort = isset($filters['sort']) ? $filters['sort'] : 'ending';
        $order = 'ORDER BY CASE WHEN status = \'active\' THEN 0 ELSE 1 END, ends_at ASC';
        switch ($sort) {
            case 'price_asc':
                $order = 'ORDER BY COALESCE(highest_bid_minor, start_price_minor) ASC';
                break;
            case 'price_desc':
                $order = 'ORDER BY COALESCE(highest_bid_minor, start_price_minor) DESC';
                break;
            case 'bids':
                $order = 'ORDER BY bids_count DESC';
                break;
            case 'newest':
                $order = 'ORDER BY id DESC';
                break;
        }

        $rows = Db::query(
            'SELECT id, domain, tld, status, start_price_minor, reserve_minor, bin_price_minor,
                    currency, bids_count, highest_bid_minor, reserve_met, description,
                    starts_at, ends_at, extensions_used, created_at
             FROM ' . Db::t('auctions') . $whereSql . ' ' . $order
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        );

        $service = $this;
        $rows = array_map(function ($row) use ($service) {
            return $service->decorateCard($row);
        }, $rows);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * One auction in full, for the detail page. Includes a masked bid history:
     * bidders appear as "Bidder #xxxx" (a stable pseudonym), never a name.
     */
    public function detail($auctionId, $viewerClientId = null)
    {
        $auction = Db::first('auctions', ['id' => (int) $auctionId]);
        if (!$auction) {
            throw new NotFoundException('That auction does not exist.');
        }

        $auction = $this->decorateCard($auction);
        $auction['seller_is_you'] = $viewerClientId
            && (int) $auction['seller_client_id'] === (int) $viewerClientId;
        unset($auction['seller_client_id']); // never expose seller identity
        $auction['min_next_bid_minor'] = $this->minimumNextBid($auction);

        $history = Db::query(
            'SELECT id, bidder_client_id, amount_minor, source, status, created_at
             FROM ' . Db::t('auction_bids') . ' WHERE auction_id = ? ORDER BY id DESC LIMIT 100',
            [(int) $auctionId]
        );
        $auction['bid_history'] = array_map(function ($bid) use ($viewerClientId) {
            return [
                'amount_minor' => (int) $bid['amount_minor'],
                'source'       => $bid['source'],
                'is_you'       => $viewerClientId !== null && (int) $bid['bidder_client_id'] === (int) $viewerClientId,
                'when'         => $bid['created_at'],
            ];
        }, $history);

        return $auction;
    }

    /** Card-level computed fields used by list + detail. */
    protected function decorateCard(array $row)
    {
        $row['start_price_minor']   = (int) $row['start_price_minor'];
        $row['reserve_minor']       = $row['reserve_minor'] !== null ? (int) $row['reserve_minor'] : null;
        $row['bin_price_minor']     = $row['bin_price_minor'] !== null ? (int) $row['bin_price_minor'] : null;
        $row['highest_bid_minor']   = $row['highest_bid_minor'] !== null ? (int) $row['highest_bid_minor'] : null;
        $row['current_price_minor'] = $row['highest_bid_minor'] !== null
            ? $row['highest_bid_minor'] : $row['start_price_minor'];
        $row['bids_count']          = (int) $row['bids_count'];
        $row['has_reserve']         = $row['reserve_minor'] !== null
            && $row['reserve_minor'] > $row['start_price_minor'];
        $row['reserve_met']         = (int) $row['reserve_met'] === 1;
        $row['seconds_remaining']   = max(0, (Clock::toTime($row['ends_at']) ?: 0) - Clock::time());
        $row['has_started']         = !Clock::isPast($row['starts_at']) ? false : true;
        return $row;
    }

    /* -------------------------------------------------------------- bids -- */

    /**
     * Place (or raise) a bid. Proxy bidding: pass max_minor to let the engine
     * outbid challengers automatically, one minimum increment at a time, up to
     * the ceiling — identically for every bidder.
     *
     * @return array{bid_id:int, state:string, extended:bool, current_minor:int}
     */
    public function placeBid($clientId, $auctionId, $amountMinor, $maxMinor = null, $submitToken = '')
    {
        if (!Settings::bool('auction_enabled', true)) {
            throw new ChsException('Domain auctions are temporarily disabled.');
        }
        if (Identity::isMasquerading()) {
            throw new ForbiddenException('For your protection, bids cannot be placed while an '
                . 'administrator is browsing as your account. Please sign in directly.');
        }

        $clientId = (int) $clientId;
        if (!Platform::gateway()->clientExists($clientId)) {
            throw new ForbiddenException('Please sign in with an active account to bid.');
        }
        RateLimiter::hitOrFail('auction_bid', 'client:' . $clientId,
            Settings::int('auction_bid_daily_limit', 100), 86400);

        $amountMinor = (int) $amountMinor;
        $maxMinor = $maxMinor !== null && $maxMinor !== '' ? (int) $maxMinor : null;
        $submitToken = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $submitToken);
        if ($submitToken === '') {
            $submitToken = sha1($clientId . '|' . $auctionId . '|' . microtime(true) . '|' . random_bytes(8));
        }

        $errors = [];
        if ($amountMinor < 100) {
            $errors['amount'] = 'Enter a bid of at least 1.00.';
        }
        if ($maxMinor !== null && $maxMinor < $amountMinor) {
            $errors['max'] = 'Your maximum cannot be below your opening bid.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $self = $this;

        return Db::transaction(function () use ($self, $auctionId, $clientId, $amountMinor, $maxMinor, $submitToken) {

            // Lock the auction row for the duration of the bid attempt.
            $auction = Db::query(
                'SELECT * FROM ' . Db::t('auctions') . ' WHERE id = ?' . (Db::driver() === 'sqlite' ? '' : ' FOR UPDATE'),
                [(int) $auctionId]
            );
            if (!$auction) {
                throw new NotFoundException('That auction does not exist.');
            }
            $auction = $auction[0];

            if ($auction['status'] !== AuctionStatus::ACTIVE) {
                throw new InvalidTransitionException('This auction is not accepting bids.');
            }
            $now = Clock::time();
            if ((Clock::toTime($auction['starts_at']) ?: $now + 1) > $now) {
                throw new InvalidTransitionException('This auction has not started yet.');
            }
            if ((Clock::toTime($auction['ends_at']) ?: 0) <= $now) {
                throw new InvalidTransitionException('This auction has already ended.');
            }
            if ($auction['seller_client_id'] !== null
                && (int) $auction['seller_client_id'] === $clientId) {
                throw new ForbiddenException('You cannot bid on your own listing.');
            }

            $minBid = $self->minimumNextBid($self->decorateCard($auction));
            if ($amountMinor < $minBid) {
                throw new ValidationException(['amount' =>
                    'The minimum next bid is ' . Money::format($minBid, $auction['currency']) . '.']);
            }

            $extended = false;
            $state = 'high';

            // Buy-It-Now: meeting BIN ends the auction immediately.
            $isBin = $auction['bin_price_minor'] !== null
                && $amountMinor >= (int) $auction['bin_price_minor'];

            // Insert the bid (idempotent on submit_token).
            try {
                $bidId = Db::insert('auction_bids', [
                    'auction_id'       => (int) $auction['id'],
                    'bidder_client_id' => $clientId,
                    'amount_minor'     => $amountMinor,
                    'max_amount_minor' => $maxMinor,
                    'status'           => 'active',
                    'source'           => 'web',
                    'ip_hash'          => \Chs\Core\Str::pseudonym(Http::clientIp()),
                    'submit_token'     => $submitToken,
                    'created_at'       => Clock::now(),
                ]);
            } catch (\Throwable $e) {
                $existing = Db::first('auction_bids', [
                    'auction_id'   => (int) $auction['id'],
                    'submit_token' => $submitToken,
                ]);
                if ($existing) {
                    throw new DuplicateOperationException('This bid has already been recorded.');
                }
                throw $e;
            }

            $previousHighBidder = $auction['highest_bidder_id'] !== null
                ? (int) $auction['highest_bidder_id'] : null;
            $previousHighMinor = $auction['highest_bid_minor'] !== null
                ? (int) $auction['highest_bid_minor'] : null;

            // Mark prior bids outbid.
            Db::exec(
                'UPDATE ' . Db::t('auction_bids') . " SET status = 'outbid'"
                . ' WHERE auction_id = ? AND status = \'active\' AND id <> ? AND bidder_client_id <> ?',
                [(int) $auction['id'], $bidId, $clientId]
            );

            // Proxy reply: the previous leader's ceiling defends its position.
            $proxyWinner = null;
            if ($previousHighBidder !== null && $previousHighBidder !== $clientId && !$isBin) {
                $prevMax = Db::query(
                    'SELECT max_amount_minor FROM ' . Db::t('auction_bids')
                    . ' WHERE auction_id = ? AND bidder_client_id = ?'
                    . ' ORDER BY max_amount_minor DESC LIMIT 1',
                    [(int) $auction['id'], $previousHighBidder]
                );
                $prevCeiling = null;
                foreach ($prevMax as $row) {
                    if ($row['max_amount_minor'] !== null) {
                        $prevCeiling = $prevCeiling === null
                            ? (int) $row['max_amount_minor'] : max($prevCeiling, (int) $row['max_amount_minor']);
                    }
                }
                if ($prevCeiling !== null && $prevCeiling >= ($amountMinor + $self->incrementFor($amountMinor, $auction['currency']))) {
                    $defense = $amountMinor + $self->incrementFor($amountMinor, $auction['currency']);
                    $defense = min($defense, $prevCeiling);
                    Db::insert('auction_bids', [
                        'auction_id'       => (int) $auction['id'],
                        'bidder_client_id' => $previousHighBidder,
                        'amount_minor'     => $defense,
                        'max_amount_minor' => $prevCeiling,
                        'status'           => 'active',
                        'source'           => 'proxy',
                        'ip_hash'          => '',
                        'submit_token'     => 'proxy-' . $bidId,
                        'created_at'       => Clock::now(),
                    ]);
                    Db::update('auction_bids', ['id' => $bidId], ['status' => 'outbid']);
                    $proxyWinner = ['bidder' => $previousHighBidder, 'amount' => $defense];
                    $state = 'outbid_by_proxy';
                }
            }

            // Resolve the fresh leader.
            if ($proxyWinner !== null) {
                $newHigh = $proxyWinner['amount'];
                $newLeader = $proxyWinner['bidder'];
            } else {
                $newHigh = $isBin ? (int) $auction['bin_price_minor'] : $amountMinor;
                $newLeader = $clientId;
            }

            // Anti-sniping extension.
            $extensions = (int) $auction['extensions_used'];
            $endsAt = $auction['ends_at'];
            $window = Settings::int('anti_snipe_window_seconds', 300);
            if (!$isBin
                && $extensions < Settings::int('anti_snipe_max_extensions', 5)
                && ((Clock::toTime($endsAt) ?: 0) - $now) <= $window) {
                $endsAt = gmdate('Y-m-d H:i:s', $now + Settings::int('anti_snipe_extend_seconds', 300));
                $extensions++;
                $extended = true;
            }

            $reserveMet = 0;
            if ($auction['reserve_minor'] !== null && $newHigh >= (int) $auction['reserve_minor']) {
                $reserveMet = 1;
            }

            Db::update('auctions', ['id' => (int) $auction['id']], [
                'bids_count'        => (int) $auction['bids_count'] + 1 + ($proxyWinner ? 1 : 0),
                'highest_bid_minor' => $newHigh,
                'highest_bidder_id' => $newLeader,
                'reserve_met'       => $reserveMet,
                'ends_at'           => $endsAt,
                'extensions_used'   => $extensions,
                'updated_at'        => Clock::now(),
            ]);

            $self->journal((int) $auction['id'], $isBin ? 'bin_bid' : 'bid', 'client', $clientId, [
                'bid' => $bidId,
                'amount_minor' => $isBin ? (int) $auction['bin_price_minor'] : $amountMinor,
                'proxy'        => $proxyWinner !== null,
                'extended'     => $extended,
            ]);

            // Notifications.
            $notify = new NotificationService();
            $currency = $auction['currency'];
            if ($previousHighBidder !== null && $previousHighBidder !== $newLeader) {
                $notify->notify(
                    $previousHighBidder,
                    'auction_outbid',
                    'You were outbid on ' . $auction['domain'],
                    'A higher bid of ' . Money::format($newHigh, $currency) . ' now leads the auction for '
                        . $auction['domain'] . '.',
                    'index.php?m=cloudhost247services&action=auction&id=' . (int) $auction['id']
                );
            }

            $watchers = Db::query(
                'SELECT client_id FROM ' . Db::t('auction_watch') . ' WHERE auction_id = ? AND client_id NOT IN (?, ?)',
                [(int) $auction['id'], $clientId, (int) ($previousHighBidder ?: 0)]
            );
            foreach (array_slice($watchers, 0, 200) as $watcher) {
                $notify->notify(
                    (int) $watcher['client_id'],
                    'auction_watch_bid',
                    'New bid on watched domain ' . $auction['domain'],
                    'The price moved to ' . Money::format($newHigh, $currency) . '.',
                    'index.php?m=cloudhost247services&action=auction&id=' . (int) $auction['id']
                );
            }

            if ($isBin) {
                $self->closeAuction((int) $auction['id'], 'bin');
            }

            Audit::client($clientId, 'auction.bid', [
                'auction' => (int) $auction['id'],
                'amount'  => $isBin ? (int) $auction['bin_price_minor'] : $amountMinor,
                'bin'     => $isBin,
            ]);

            return [
                'bid_id'        => $bidId,
                'state'         => $isBin ? 'won_bin' : $state,
                'extended'      => $extended,
                'current_minor' => $newHigh,
                'leader_is_you' => $newLeader === $clientId,
            ];
        });
    }

    /** The minimum acceptable next bid for a decorated auction row. */
    public function minimumNextBid(array $auction)
    {
        if ($auction['highest_bid_minor'] !== null) {
            return (int) $auction['highest_bid_minor']
                + $this->incrementFor((int) $auction['highest_bid_minor'], $auction['currency']);
        }
        return (int) $auction['start_price_minor'];
    }

    /**
     * Bid increment ladder, in minor units, anchored to the current price.
     * (Ladders are defined against USD-scale values; other currencies use the
     * same shape — operators can override per auction by pricing higher.)
     */
    public function incrementFor($priceMinor, $currency = 'USD')
    {
        if ($priceMinor < 2500) {
            return 500;
        }
        if ($priceMinor < 10000) {
            return 1000;
        }
        if ($priceMinor < 50000) {
            return 2500;
        }
        if ($priceMinor < 250000) {
            return 10000;
        }
        if ($priceMinor < 1000000) {
            return 50000;
        }
        return 100000;
    }

    /* ---------------------------------------------------------- watchlist -- */

    public function watch($clientId, $auctionId)
    {
        if (!Db::first('auctions', ['id' => (int) $auctionId])) {
            throw new NotFoundException('That auction does not exist.');
        }
        if (!Db::first('auction_watch', ['auction_id' => (int) $auctionId, 'client_id' => (int) $clientId])) {
            Db::insert('auction_watch', [
                'auction_id' => (int) $auctionId,
                'client_id'  => (int) $clientId,
                'created_at' => Clock::now(),
            ]);
        }
        return true;
    }

    public function unwatch($clientId, $auctionId)
    {
        Db::exec(
            'DELETE FROM ' . Db::t('auction_watch') . ' WHERE auction_id = ? AND client_id = ?',
            [(int) $auctionId, (int) $clientId]
        );
        return true;
    }

    public function isWatching($clientId, $auctionId)
    {
        return (bool) Db::first('auction_watch', [
            'auction_id' => (int) $auctionId, 'client_id' => (int) $clientId]);
    }

    /** @return array[] watched auctions, live ones first */
    public function watchlist($clientId)
    {
        $rows = Db::query(
            'SELECT a.* FROM ' . Db::t('auctions') . ' a
             JOIN ' . Db::t('auction_watch') . ' w ON w.auction_id = a.id
             WHERE w.client_id = ? ORDER BY a.status = \'active\' DESC, a.ends_at ASC',
            [(int) $clientId]
        );
        $self = $this;
        return array_map(function ($row) use ($self) {
            return $self->decorateCard($row);
        }, $rows);
    }

    /* ------------------------------------------------------- state engine -- */

    /**
     * Cron heartbeat: open scheduled auctions, close finished ones.
     * Safe to run every minute; idempotent by construction.
     */
    public function heartbeat()
    {
        $opened = Db::exec(
            'UPDATE ' . Db::t('auctions')
            . " SET status = 'active', updated_at = ? WHERE status = 'scheduled' AND starts_at <= ?",
            [Clock::now(), Clock::now()]
        );

        $due = Db::query(
            'SELECT id FROM ' . Db::t('auctions')
            . " WHERE status = 'active' AND ends_at <= ? LIMIT 100",
            [Clock::now()]
        );
        $closed = 0;
        foreach ($due as $row) {
            $this->closeAuction((int) $row['id'], 'timer');
            $closed++;
        }
        return ['opened' => $opened, 'closed' => $closed];
    }

    /**
     * Settle an auction: pick the winner (if any), invoice them, notify all
     * parties, and journal the outcome.
     */
    public function closeAuction($auctionId, $reason = 'timer')
    {
        return Db::transaction(function () use ($auctionId, $reason) {
            $auction = Db::first('auctions', ['id' => (int) $auctionId]);
            if (!$auction || !in_array($auction['status'], [AuctionStatus::ACTIVE, AuctionStatus::SCHEDULED], true)) {
                return null; // already settled
            }

            $notify = new NotificationService();
            $winner = $auction['highest_bidder_id'] !== null ? (int) $auction['highest_bidder_id'] : null;
            $price = $auction['highest_bid_minor'] !== null ? (int) $auction['highest_bid_minor'] : null;
            $reserveOk = $auction['reserve_minor'] === null
                || ($price !== null && $price >= (int) $auction['reserve_minor']);

            // Flip terminal bids.
            Db::exec(
                'UPDATE ' . Db::t('auction_bids')
                . " SET status = CASE WHEN bidder_client_id = ? AND status = 'active' THEN 'won' ELSE 'lost' END"
                . ' WHERE auction_id = ? AND status = \'active\'',
                [(int) $winner, $auctionId]
            );

            if ($winner !== null && $reserveOk) {
                $dueDays = Settings::int('auction_invoice_due_days', 3);
                $fees = [];
                $items = [[
                    'description'  => 'Domain auction win: ' . $auction['domain'] . ' (auction #' . $auctionId . ')',
                    'amount_minor' => $price,
                    'taxed'        => true,
                ]];
                foreach ($fees as $fee) {
                    $items[] = $fee;
                }
                $invoiceId = Platform::gateway()->createInvoice(
                    $winner,
                    $items,
                    $auction['currency'],
                    $dueDays,
                    'Won at auction on ' . Clock::today() . '. Transfer begins once this invoice is paid.'
                );

                Db::insert('auction_invoices', [
                    'auction_id'   => $auctionId,
                    'invoice_id'   => $invoiceId,
                    'client_id'    => $winner,
                    'amount_minor' => $price,
                    'currency'     => $auction['currency'],
                    'status'       => 'open',
                    'due_at'       => Clock::in($dueDays * 86400),
                    'paid_at'      => null,
                    'created_at'   => Clock::now(),
                ]);

                Db::update('auctions', ['id' => $auctionId], [
                    'status'     => AuctionStatus::SOLD,
                    'closed_at'  => Clock::now(),
                    'updated_at' => Clock::now(),
                ]);

                $notify->notify($winner, 'auction_won',
                    'You won the auction for ' . $auction['domain'],
                    'Congratulations — your bid of ' . Money::format($price, $auction['currency'])
                        . ' won ' . $auction['domain'] . '. An invoice is ready; the domain transfer '
                        . 'starts as soon as it is paid.',
                    'index.php?m=cloudhost247services&action=auction&id=' . $auctionId);

                if ($auction['seller_client_id'] !== null) {
                    $notify->notify((int) $auction['seller_client_id'], 'auction_sold',
                        'Your domain ' . $auction['domain'] . ' sold',
                        'Your listing closed at ' . Money::format($price, $auction['currency'])
                            . '. Our transfer desk will coordinate with the buyer.',
                        'index.php?m=cloudhost247services&action=auctions');
                }

                $this->journal($auctionId, 'closed_sold', 'system', 0, [
                    'reason' => $reason, 'winner' => $winner, 'price' => $price, 'invoice' => $invoiceId,
                ]);
                Audit::system('auction.closed_sold', ['auction' => $auctionId, 'price' => $price]);
                return ['state' => 'sold', 'winner' => $winner, 'invoice' => $invoiceId];
            }

            Db::update('auctions', ['id' => $auctionId], [
                'status'     => AuctionStatus::ENDED,
                'closed_at'  => Clock::now(),
                'updated_at' => Clock::now(),
            ]);

            if ($auction['seller_client_id'] !== null) {
                $notify->notify((int) $auction['seller_client_id'], 'auction_ended_unsold',
                    'Your auction for ' . $auction['domain'] . ' ended without a winning bid',
                    $winner !== null
                        ? 'The highest bid did not meet your reserve. You may relist from your dashboard.'
                        : 'No bids were placed before the close. You may relist from your dashboard.',
                    'index.php?m=cloudhost247services&action=auctions');
            }

            $this->journal($auctionId, 'closed_unsold', 'system', 0, [
                'reason' => $reason,
                'had_bidder' => $winner !== null,
                'reserve_ok' => $reserveOk,
            ]);
            Audit::system('auction.closed_unsold', ['auction' => $auctionId]);
            return ['state' => 'ended'];
        });
    }

    /**
     * Called by the InvoicePaid hook: when the settlement invoice is paid, the
     * auction moves to paid and the transfer desk is engaged.
     */
    public function invoicePaid($invoiceId)
    {
        $link = Db::first('auction_invoices', ['invoice_id' => (int) $invoiceId]);
        if (!$link || $link['status'] === 'paid') {
            return false;
        }

        Db::transaction(function () use ($link, $invoiceId) {
            Db::update('auction_invoices', ['invoice_id' => (int) $invoiceId], [
                'status'  => 'paid',
                'paid_at' => Clock::now(),
            ]);
            Db::update('auctions', ['id' => (int) $link['auction_id']], [
                'status'     => AuctionStatus::PAID,
                'updated_at' => Clock::now(),
            ]);
            $this->journal((int) $link['auction_id'], 'invoice_paid', 'system', 0, [
                'invoice' => (int) $invoiceId,
            ]);

            $notify = new NotificationService();
            $notify->notify((int) $link['client_id'], 'auction_paid',
                'Payment received — transfer starting',
                'We have received your payment of ' . Money::format((int) $link['amount_minor'], $link['currency'])
                    . '. Our transfer desk will now move your domain into your account.',
                'index.php?m=cloudhost247services&action=auction&id=' . (int) $link['auction_id']);
        });
        Audit::system('auction.invoice_paid', ['invoice' => (int) $invoiceId, 'auction' => (int) $link['auction_id']]);
        return true;
    }

    /** Daily safety net: cancel settlement invoices that lapsed unpaid. */
    public function lapseOverdueInvoices()
    {
        if (!Settings::bool('auction_cancel_unpaid_invoices', true)) {
            return 0;
        }
        $rows = Db::query(
            'SELECT ai.*, a.domain FROM ' . Db::t('auction_invoices') . ' ai'
            . ' JOIN ' . Db::t('auctions') . ' a ON a.id = ai.auction_id'
            . " WHERE ai.status = 'open' AND ai.due_at <= ?",
            [Clock::now()]
        );
        $count = 0;
        foreach ($rows as $row) {
            $status = Platform::gateway()->invoiceStatus((int) $row['invoice_id']);
            if ($status === 'Paid') {
                $this->invoicePaid((int) $row['invoice_id']);
                continue;
            }
            if ($status === 'Unpaid') {
                Platform::gateway()->cancelInvoice((int) $row['invoice_id']);
            }
            Db::update('auction_invoices', ['id' => (int) $row['id']], ['status' => 'lapsed']);
            Db::update('auctions', ['id' => (int) $row['auction_id']], [
                'status'     => AuctionStatus::CANCELLED_UNPAID,
                'updated_at' => Clock::now(),
            ]);
            $this->journal((int) $row['auction_id'], 'invoice_lapsed', 'system', 0, [
                'invoice' => (int) $row['invoice_id'],
            ]);
            (new NotificationService())->notify((int) $row['client_id'], 'auction_lapsed',
                'Auction invoice lapsed for ' . $row['domain'],
                'Your winning-bid invoice for ' . $row['domain'] . ' passed its due date unpaid, so the '
                    . 'sale was cancelled. Contact support if this looks wrong.',
                'index.php?m=cloudhost247services&action=auctions');
            Audit::system('auction.invoice_lapsed', ['auction' => (int) $row['auction_id']]);
            $count++;
        }
        return $count;
    }

    /* ------------------------------------------------------------- admin -- */

    public function adminList($status = '', $limit = 100)
    {
        $where = [];
        $bind = [];
        if ($status !== '' && in_array($status, AuctionStatus::all(), true)) {
            $where[] = 'a.status = ?';
            $bind[] = $status;
        }
        return Db::query(
            'SELECT a.*, (SELECT COUNT(*) FROM ' . Db::t('auction_bids') . ' b WHERE b.auction_id = a.id) AS bid_rows
             FROM ' . Db::t('auctions') . ' a'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY a.id DESC LIMIT ' . (int) $limit,
            $bind
        );
    }

    public function adminCancel($auctionId, $adminId, $reason = '')
    {
        $auction = Db::first('auctions', ['id' => (int) $auctionId]);
        if (!$auction) {
            throw new NotFoundException('Auction not found.');
        }
        if (in_array($auction['status'], AuctionStatus::terminal(), true)
            || $auction['status'] === AuctionStatus::PAID
            || $auction['status'] === AuctionStatus::SOLD) {
            throw new InvalidTransitionException('A settled auction cannot be cancelled.');
        }
        Db::update('auctions', ['id' => (int) $auctionId], [
            'status'     => AuctionStatus::CANCELLED,
            'closed_at'  => Clock::now(),
            'updated_at' => Clock::now(),
        ]);
        $this->journal($auctionId, 'cancelled', 'admin', (int) $adminId, ['reason' => $reason]);
        Audit::admin($adminId, 'auction.cancelled', ['auction' => $auctionId, 'reason' => $reason]);

        $notify = new NotificationService();
        if ($auction['seller_client_id'] !== null) {
            $notify->notify((int) $auction['seller_client_id'], 'auction_cancelled',
                'Your auction for ' . $auction['domain'] . ' was cancelled',
                'An administrator cancelled this listing' . ($reason !== '' ? ': ' . $reason : '.'),
                'index.php?m=cloudhost247services&action=auctions');
        }
        if ($auction['highest_bidder_id'] !== null) {
            $notify->notify((int) $auction['highest_bidder_id'], 'auction_cancelled',
                'The auction for ' . $auction['domain'] . ' was cancelled',
                'The listing was withdrawn by the operator. No payment has been taken.',
                'index.php?m=cloudhost247services&action=auctions');
        }
        return true;
    }

    /** Mark transfer finished — final state. */
    public function adminMarkTransferred($auctionId, $adminId, $note = '')
    {
        $auction = Db::first('auctions', ['id' => (int) $auctionId]);
        if (!$auction) {
            throw new NotFoundException('Auction not found.');
        }
        if ($auction['status'] !== AuctionStatus::PAID) {
            throw new InvalidTransitionException('Only a paid auction can be marked transferred.');
        }
        Db::update('auctions', ['id' => (int) $auctionId], [
            'status'     => AuctionStatus::TRANSFERRED,
            'updated_at' => Clock::now(),
        ]);
        $this->journal($auctionId, 'transferred', 'admin', (int) $adminId, ['note' => $note]);
        Audit::admin($adminId, 'auction.transferred', ['auction' => $auctionId]);

        if ($auction['highest_bidder_id'] !== null) {
            (new NotificationService())->notify((int) $auction['highest_bidder_id'], 'auction_transferred',
                $auction['domain'] . ' is now in your account',
                'The transfer completed and the domain is manageable from your My Domains dashboard.',
                'clientarea.php?action=domains');
        }
        return true;
    }

    /** Events for the admin ledger view. */
    public function journalFor($auctionId)
    {
        return Db::all('auction_events', ['auction_id' => (int) $auctionId], 'id ASC', 500);
    }

    /* ------------------------------------------------------------ internal -- */

    protected function journal($auctionId, $type, $actorType, $actorId, array $payload = [])
    {
        Db::insert('auction_events', [
            'auction_id' => (int) $auctionId,
            'type'       => substr($type, 0, 48),
            'actor_type' => substr($actorType, 0, 16),
            'actor_id'   => (int) $actorId,
            'payload'    => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => Clock::now(),
        ]);
    }

    protected function assertClientOwnsDomain($fqdn, $clientId)
    {
        foreach (Platform::gateway()->clientDomains($clientId) as $owned) {
            if (strtolower($owned['domain']) === strtolower($fqdn)) {
                return;
            }
        }
        throw new ForbiddenException(
            'You can only auction domains registered inside your account. '
            . 'To sell an external domain, transfer it in first (Services → Domain Transfers).'
        );
    }
}
