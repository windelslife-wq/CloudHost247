<?php
/**
 * Customer portal — every page this module serves to a signed-in client.
 *
 * One dispatcher, exhaustive action map, every write behind POST+CSRF, every
 * screen fed with pre-escaped/pre-computed view models.
 *
 * @package Chs\Http
 */

namespace Chs\Http;

use Chs\Core\ChsException;
use Chs\Core\Csrf;
use Chs\Core\ForbiddenException;
use Chs\Core\Http;
use Chs\Core\Identity;
use Chs\Core\Money;
use Chs\Core\NotFoundException;
use Chs\Core\ProviderNotConfiguredException;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\AiBuilderService;
use Chs\Services\AuctionService;
use Chs\Services\AvailabilityService;
use Chs\Services\ClubService;
use Chs\Services\ConsentService;
use Chs\Services\InboxService;
use Chs\Services\LogoService;
use Chs\Services\NotificationService;
use Chs\Services\ServiceRequestService;
use Chs\Services\ValuationService;
use Chs\Core\DomainName;

class CustomerPortal extends Controller
{
    /**
     * WHMCS _clientarea entry point.
     */
    public function dispatch($vars)
    {
        $action = Http::get('action', 'dashboard');
        if ($action === 'consent') {
            return $this->recordConsent();
        }

        $clientId = Identity::clientId();
        if (!$clientId) {
            return $this->page('Sign in required', 'login_required', [
                'error_message' => 'Please sign in to continue.',
            ]);
        }

        if (!preg_match('/^[a-z_]+$/', $action)) {
            $action = 'dashboard';
        }

        $map = [
            'dashboard'     => 'pageDashboard',
            'valuation'     => 'pageValuation',
            'auctions'      => 'pageAuctions',
            'auction'       => 'pageAuctionDetail',
            'watchlist'     => 'pageWatchlist',
            'sell'          => 'pageSell',
            'club'          => 'pageClub',
            'requests'      => 'pageRequests',
            'requestnew'    => 'pageRequestNew',
            'request'       => 'pageRequestDetail',
            'logos'         => 'pageLogos',
            'logostudio'    => 'pageLogoStudio',
            'logo'          => 'pageLogoDetail',
            'ai'            => 'pageAi',
            'generation'    => 'pageAiDetail',
            'inbox'         => 'pageInbox',
            'thread'        => 'pageThread',
            'notifications' => 'pageNotifications',
            'logoapi'       => 'pageLogoApi',
        ];

        $method = isset($map[$action]) ? $map[$action] : 'pageDashboard';
        $portal = $this;

        return $this->guard(function () use ($portal, $method, $vars, $clientId) {
            return $portal->$method($vars, $clientId);
        });
    }

    /**
     * Public, deliberately narrow consent endpoint. It never returns account
     * data and uses the same-origin browser request only for the decision
     * record; no query-string credentials or wildcard CORS are involved.
     */
    protected function recordConsent()
    {
        if (!Http::isPost()) {
            Http::json(['ok' => false, 'error' => 'POST required'], 405);
        }
        try {
            $saved = (new ConsentService())->record(
                Http::post('status'),
                Http::post('consent_id'),
                Http::post('categories'),
                Http::post('policy_version', 'cookie-policy-v1'),
                Http::post('language')
            );
        } catch (\Throwable $e) {
            \Chs\Core\Logger::warning('Consent record was not persisted', ['message' => $e->getMessage()]);
            $saved = false;
        }
        Http::json(['ok' => $saved]);
    }

    /* --------------------------------------------------------- dashboard -- */

    protected function pageDashboard($vars, $clientId)
    {
        $notify = new NotificationService();
        $club = new ClubService();
        $requests = new ServiceRequestService();
        $inbox = new InboxService();
        $logos = new LogoService();

        $openRequests = array_filter($requests->listFor($clientId), function ($r) {
            return in_array($r['status'], \Chs\Workflow\RequestStatus::open(), true);
        });

        return $this->page('My digital services', 'dashboard', [
            'unread_notifications' => $notify->unreadCount($clientId),
            'notifications'        => array_slice($notify->listFor($clientId, 8), 0, 8),
            'membership'           => $club->activeMembership($clientId),
            'open_requests'        => count($openRequests),
            'requests'             => array_slice($requests->listFor($clientId), 0, 5),
            'inbox_unread'         => $inbox->unreadCountFor($clientId),
            'logo_count'           => count($logos->listFor($clientId)),
            'ai_status'            => (new AiBuilderService())->status(),
            'features'             => [
                'auctions' => Settings::bool('auction_enabled', true),
                'club'     => Settings::bool('club_enabled', true),
                'valuation' => Settings::bool('valuation_enabled', true),
                'requests' => Settings::bool('requests_enabled', true),
                'logo'     => Settings::bool('logo_enabled', true),
                'inbox'    => Settings::bool('inbox_enabled', true),
            ],
            'flash' => $this->flash('ok'),
        ]);
    }

    /* --------------------------------------------------------- valuation -- */

    protected function pageValuation($vars, $clientId)
    {
        $service = new ValuationService();
        $result = null;
        $errors = [];

        if (Http::isPost()) {
            Csrf::verifyRequest();
            try {
                $result = $service->estimate(Http::post('domain'), $clientId);
            } catch (ValidationException $e) {
                $errors = $e->fieldErrors();
            }
        }

        return $this->page('Domain valuation', 'valuation', [
            'result'  => $result,
            'errors'  => $errors,
            'history' => $service->history($clientId, 12),
            'engine'  => $service->engineInfo(),
            'disclaimer' => ValuationService::DISCLAIMER,
            'money'   => $this->moneyHelper(),
        ]);
    }

    /* ---------------------------------------------------------- auctions -- */

    protected function pageAuctions($vars, $clientId)
    {
        $service = new AuctionService();
        $filters = [
            'search'   => Http::get('search'),
            'tld'      => Http::get('tld'),
            'sort'     => Http::get('sort', 'ending'),
            'page'     => Http::getInt('page', 1),
            'per_page' => 18,
        ];
        if (Http::get('ending') === '1') {
            $filters['ending_soon'] = true;
        }

        $browse = $service->browse($filters);

        return $this->page('Domain auctions', 'auctions', [
            'auctions' => $browse['rows'],
            'total'    => $browse['total'],
            'page'     => $browse['page'],
            'pages'    => max(1, (int) ceil($browse['total'] / $browse['per_page'])),
            'filters'  => $filters,
            'money'    => $this->moneyHelper(),
        ]);
    }

    protected function pageAuctionDetail($vars, $clientId)
    {
        $service = new AuctionService();
        $id = Http::getInt('id');
        $flashError = null;
        $flashOk = $this->flash('ok');

        if (Http::isPost()) {
            Csrf::verifyRequest();
            $do = Http::post('do', 'bid');
            try {
                if ($do === 'bid') {
                    $amount = Money::fromDecimal(Http::post('amount'));
                    $max = Http::post('max') !== '' ? Money::fromDecimal(Http::post('max')) : null;
                    $token = Http::post('submit_token');
                    $result = $service->placeBid($clientId, $id, $amount, $max, $token);
                    $msg = 'Bid placed — you are leading.';
                    if ($result['state'] === 'outbid_by_proxy') {
                        $msg = 'Bid placed, but another bidder\'s maximum is still higher. Raise your bid to lead.';
                    } elseif ($result['state'] === 'won_bin') {
                        $msg = 'Buy-It-Now met — congratulations, the domain is yours pending payment.';
                    }
                    if (!empty($result['extended'])) {
                        $msg .= ' A late bid extended the closing time (anti-sniping protection).';
                    }
                    $this->flash('ok', $msg);
                } elseif ($do === 'watch') {
                    $service->watch($clientId, $id);
                    $this->flash('ok', 'Added to your watchlist.');
                } elseif ($do === 'unwatch') {
                    $service->unwatch($clientId, $id);
                    $this->flash('ok', 'Removed from your watchlist.');
                }
                Http::redirect('index.php?m=cloudhost247services&action=auction&id=' . $id);
            } catch (ValidationException $e) {
                $flashError = implode(' ', $e->fieldErrors());
            }
        }

        $auction = $service->detail($id, $clientId);

        return $this->page('Auction: ' . $auction['domain'], 'auction_detail', [
            'auction'     => $auction,
            'watching'    => $service->isWatching($clientId, $id),
            'flash_error' => $flashError,
            'flash_ok'    => $flashOk,
            'submit_token'=> \Chs\Core\Str::random(12),
            'money'       => $this->moneyHelper(),
            'increment_hint' => Money::format($auction['min_next_bid_minor'], $auction['currency']),
        ]);
    }

    protected function pageWatchlist($vars, $clientId)
    {
        $service = new AuctionService();
        if (Http::isPost()) {
            Csrf::verifyRequest();
            if (Http::post('do') === 'unwatch') {
                $service->unwatch($clientId, Http::postInt('id'));
            }
            Http::redirect('index.php?m=cloudhost247services&action=watchlist');
        }

        return $this->page('My watchlist', 'watchlist', [
            'auctions' => $service->watchlist($clientId),
            'money'    => $this->moneyHelper(),
        ]);
    }

    protected function pageSell($vars, $clientId)
    {
        $service = new AuctionService();
        $errors = [];
        $old = ['domain' => '', 'start' => '', 'reserve' => '', 'bin' => '', 'duration' => 7, 'description' => ''];

        if (Http::isPost()) {
            Csrf::verifyRequest();
            $old = [
                'domain'      => Http::post('domain'),
                'start'       => Http::post('start'),
                'reserve'     => Http::post('reserve'),
                'bin'         => Http::post('bin'),
                'duration'    => Http::postInt('duration', 7),
                'description' => Http::post('description'),
            ];
            try {
                $listing = $service->createListing([
                    'domain'        => $old['domain'],
                    'start_minor'   => Money::fromDecimal($old['start']),
                    'reserve_minor' => $old['reserve'] !== '' ? Money::fromDecimal($old['reserve']) : null,
                    'bin_minor'     => $old['bin'] !== '' ? Money::fromDecimal($old['bin']) : null,
                    'duration_days' => $old['duration'],
                    'description'   => $old['description'],
                ], $clientId, false);
                $this->flash('ok', 'Your auction for ' . $listing['domain'] . ' is listed.');
                Http::redirect('index.php?m=cloudhost247services&action=auction&id=' . (int) $listing['id']);
            } catch (ValidationException $e) {
                $errors = $e->fieldErrors();
            }
        }

        $eligible = [];
        foreach (\Chs\Core\Platform::gateway()->clientDomains($clientId) as $d) {
            $eligible[] = $d['domain'];
        }

        return $this->page('Sell a domain', 'sell', [
            'errors'   => $errors,
            'old'      => $old,
            'eligible' => $eligible,
        ]);
    }

    /* -------------------------------------------------------------- club -- */

    protected function pageClub($vars, $clientId)
    {
        $club = new ClubService();

        if (Http::isPost()) {
            Csrf::verifyRequest();
            $do = Http::post('do');
            if ($do === 'join') {
                $result = $club->join($clientId, Http::postInt('plan_id'));
                Http::redirect($result['url']);
            }
            if ($do === 'cancel') {
                $club->cancel($clientId, Http::postInt('membership_id'));
                $this->flash('ok', 'Your membership will end at the close of the paid period.');
                Http::redirect('index.php?m=cloudhost247services&action=club');
            }
        }

        return $this->page('Discount Domain Club', 'club', [
            'plans'       => $club->publicPlans(),
            'membership'  => $club->activeMembership($clientId),
            'memberships' => $club->membershipsFor($clientId),
            'money'       => $this->moneyHelper(),
            'flash'       => $this->flash('ok'),
        ]);
    }

    /* ---------------------------------------------------------- requests -- */

    protected function pageRequests($vars, $clientId)
    {
        $service = new ServiceRequestService();
        return $this->page('My service requests', 'requests', [
            'requests'  => $service->listFor($clientId),
            'type_labels' => $this->requestTypeLabels(),
        ]);
    }

    protected function pageRequestNew($vars, $clientId)
    {
        $service = new ServiceRequestService();
        $errors = [];
        $old = ['type' => '', 'title' => '', 'brief' => '', 'budget_range' => '', 'target_domain' => ''];

        if (Http::isPost()) {
            Csrf::verifyRequest();
            $old = [
                'type'          => Http::post('type'),
                'title'         => Http::post('title'),
                'brief'         => Http::post('brief'),
                'budget_range'  => Http::post('budget_range'),
                'target_domain' => Http::post('target_domain'),
            ];
            try {
                $request = $service->create($clientId, $old);
                $this->flash('ok', 'Request received — our team will review it shortly.');
                Http::redirect('index.php?m=cloudhost247services&action=request&id=' . (int) $request['id']);
            } catch (ValidationException $e) {
                $errors = $e->fieldErrors();
            }
        }

        return $this->page('New service request', 'request_new', [
            'types'  => ServiceRequestService::types(),
            'budgets' => $service->budgetRanges(),
            'errors' => $errors,
            'old'    => $old,
            'preset_type' => Http::get('type'),
        ]);
    }

    protected function pageRequestDetail($vars, $clientId)
    {
        $service = new ServiceRequestService();
        $id = Http::getInt('id');
        $flashError = null;

        if (Http::isPost()) {
            Csrf::verifyRequest();
            try {
                $do = Http::post('do');
                if ($do === 'reply') {
                    $service->post($id, 'client', $clientId, Http::post('body'));
                    $this->flash('ok', 'Reply sent.');
                } elseif ($do === 'accept_quote') {
                    $result = $service->acceptQuote($clientId, $id);
                    Http::redirect($result['url']);
                } elseif ($do === 'cancel') {
                    $service->post($id, 'client', $clientId, Http::post('body', 'Cancelled by the customer.'), \Chs\Workflow\RequestStatus::CANCELLED);
                    $this->flash('ok', 'Request cancelled.');
                } elseif ($do === 'complete') {
                    $service->post($id, 'client', $clientId, 'Marked as completed by the customer.', \Chs\Workflow\RequestStatus::COMPLETED);
                    $this->flash('ok', 'Glad it\'s done — thank you.');
                }
                Http::redirect('index.php?m=cloudhost247services&action=request&id=' . $id);
            } catch (ValidationException $e) {
                $flashError = implode(' ', $e->fieldErrors());
            }
        }

        return $this->page('Service request #' . $id, 'request_detail', [
            'request'     => $service->detailFor($clientId, $id),
            'flash_error' => $flashError,
            'flash_ok'    => $this->flash('ok'),
            'money'       => $this->moneyHelper(),
        ]);
    }

    /* -------------------------------------------------------------- logos -- */

    protected function pageLogos($vars, $clientId)
    {
        $service = new LogoService();
        if (Http::isPost()) {
            Csrf::verifyRequest();
            if (Http::post('do') === 'delete') {
                $service->delete($clientId, Http::postInt('id'));
                $this->flash('ok', 'Project deleted.');
            }
            Http::redirect('index.php?m=cloudhost247services&action=logos');
        }

        return $this->page('My logo projects', 'logos', [
            'logos' => $service->listFor($clientId),
            'flash' => $this->flash('ok'),
        ]);
    }

    protected function pageLogoStudio($vars, $clientId)
    {
        $service = new LogoService();
        $errors = [];

        if (Http::isPost()) {
            Csrf::verifyRequest();
            $do = Http::post('do', 'save');
            if ($do === 'save') {
                try {
                    $project = $service->save($clientId, [
                        'company'  => Http::post('company'),
                        'industry' => Http::post('industry'),
                        'style'    => Http::post('style'),
                        'palette'  => Http::post('palette'),
                        'concept'  => Http::post('concept'),
                    ]);
                    $this->flash('ok', 'Saved to your logo projects.');
                    Http::redirect('index.php?m=cloudhost247services&action=logo&id=' . (int) $project['id']);
                } catch (ValidationException $e) {
                    $errors = $e->fieldErrors();
                }
            }
        }

        $libraries = $service->libraries();

        return $this->page('Logo Studio', 'logo_studio', [
            'libraries' => $libraries,
            'industries' => \Chs\Services\LogoEngine::industries(),
            'errors'    => $errors,
            'prefill'   => [
                'company'  => Http::get('company'),
                'industry' => Http::get('industry', 'general'),
                'style'    => Http::get('style', 'modern'),
                'palette'  => Http::get('palette', 'ocean'),
            ],
            'png_available' => $service->pngAvailable(),
        ]);
    }

    protected function pageLogoDetail($vars, $clientId)
    {
        $service = new LogoService();

        if (Http::get('export') !== '') {
            $format = Http::get('export');
            $package = $service->export($clientId, Http::getInt('id'), $format);
            if (!headers_sent()) {
                header('Content-Type: ' . $package['mime']);
                header('Content-Disposition: attachment; filename="' . $package['filename'] . '"');
                header('Content-Length: ' . strlen($package['data']));
                header('X-Content-Type-Options: nosniff');
            }
            echo $package['data'];
            exit;
        }

        $project = $service->getFor($clientId, Http::getInt('id'));

        return $this->page('Logo: ' . $project['company_name'], 'logo_detail', [
            'project'       => $project,
            'png_available' => $service->pngAvailable(),
        ]);
    }

    /* ----------------------------------------------------------------- ai -- */

    protected function pageAi($vars, $clientId)
    {
        $service = new AiBuilderService();
        $errors = [];

        if (Http::isPost()) {
            Csrf::verifyRequest();
            try {
                $generation = $service->generate($clientId, Http::post('brief'), [
                    'industry' => Http::post('industry'),
                    'pages'    => Http::postInt('pages', 4),
                ]);
                Http::redirect('index.php?m=cloudhost247services&action=generation&id=' . (int) $generation['id']);
            } catch (ValidationException $e) {
                $errors = $e->fieldErrors();
            }
        }

        return $this->page('AI Website Builder', 'ai_builder', [
            'status'      => $service->status(),
            'history'     => $service->history($clientId),
            'errors'      => $errors,
            'industries'  => \Chs\Services\LogoEngine::industries(),
        ]);
    }

    protected function pageAiDetail($vars, $clientId)
    {
        $service = new AiBuilderService();
        $generation = $service->getFor($clientId, Http::getInt('id'));

        return $this->page('AI outline: ' . $generation['result']['site_title'], 'ai_detail', [
            'generation' => $generation,
        ]);
    }

    /* --------------------------------------------------------------- inbox -- */

    protected function pageInbox($vars, $clientId)
    {
        $service = new InboxService();
        return $this->page('Unified inbox', 'inbox', [
            'conversations' => $service->conversationsFor($clientId, 100, Http::get('search'), Http::get('status')),
            'search'  => Http::get('search'),
            'status'  => Http::get('status'),
            'channels' => $service->channels(),
        ]);
    }

    protected function pageThread($vars, $clientId)
    {
        $service = new InboxService();
        $id = Http::getInt('id');
        $flashError = null;

        if (Http::isPost()) {
            Csrf::verifyRequest();
            try {
                $service->reply($clientId, $id, Http::post('body'));
                Http::redirect('index.php?m=cloudhost247services&action=thread&id=' . $id);
            } catch (ValidationException $e) {
                $flashError = implode(' ', $e->fieldErrors());
            }
        }

        return $this->page('Conversation', 'thread', [
            'thread'      => $service->threadFor($clientId, $id),
            'flash_error' => $flashError,
        ]);
    }

    /* ------------------------------------------------------- notifications -- */

    protected function pageNotifications($vars, $clientId)
    {
        $service = new NotificationService();
        if (Http::isPost()) {
            Csrf::verifyRequest();
            if (Http::post('do') === 'mark_all') {
                $service->markAllRead($clientId);
            } elseif (Http::postInt('id')) {
                $service->markRead(Http::postInt('id'), $clientId);
            }
            Http::redirect('index.php?m=cloudhost247services&action=notifications');
        }

        return $this->page('Notifications', 'notifications', [
            'notifications' => $service->listFor($clientId, 100),
            'unread'        => $service->unreadCount($clientId),
        ]);
    }

    /**
     * JSON endpoint for the live studio: renders one concept per request
     * through the server-side engine so the studio and the saved/exported
     * files can never diverge.
     */
    protected function pageLogoApi($vars, $clientId)
    {
        $service = new LogoService();
        try {
            $concept = Http::get('concept', 'wordmark');
            $svg = $service->renderOne([
                'company'  => Http::get('company'),
                'industry' => Http::get('industry'),
                'style'    => Http::get('style'),
                'palette'  => Http::get('palette'),
            ], $concept);
            Http::json(['ok' => true, 'svg' => $svg]);
        } catch (\Chs\Core\ChsException $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /* ------------------------------------------------------------ helpers -- */

    /** Smarty-accessible formatter metadata rendered via a plugin-less trick. */
    protected function moneyHelper()
    {
        return [
            'enabled' => true,
        ];
    }

    protected function requestTypeLabels()
    {
        $labels = [];
        foreach (ServiceRequestService::types() as $key => $row) {
            $labels[$key] = $row['label'];
        }
        return $labels;
    }
}
