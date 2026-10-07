<?php
/**
 * Admin control center.
 *
 * Console sections: overview, TLD catalogue, valuations, auctions, club
 * (plans + memberships), service requests, logo projects, AI builder, inbox
 * and settings. Renders the module's own .phtml views; every mutation is
 * POST+WHMCS-token checked, audited and RBAC-gated on top of WHMCS' own
 * addon-module permission.
 *
 * @package Chs\Http
 */

namespace Chs\Http;

use Chs\Core\Audit;
use Chs\Core\ChsException;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Identity;
use Chs\Core\InvalidTransitionException;
use Chs\Core\Money;
use Chs\Core\NotFoundException;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\AiBuilderService;
use Chs\Services\AuctionService;
use Chs\Services\ClubService;
use Chs\Services\InboxService;
use Chs\Services\ServiceRequestService;
use Chs\Services\TldCatalogService;
use Chs\Services\ValuationService;

class AdminPortal
{
    /** @var array */
    private $vars;

    private $baseLink;

    public function __construct($vars)
    {
        $this->vars = is_array($vars) ? $vars : [];
        $this->baseLink = isset($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=cloudhost247services';
    }

    /**
     * @return string full HTML for the WHMCS admin module page
     */
    public function render()
    {
        $staff = Identity::adminId();
        if (!$staff) {
            return '<div class="alert alert-danger">Administrator session required.</div>';
        }

        $action = isset($_GET['action']) ? preg_replace('/[^a-z_]/', '', (string) $_GET['action']) : 'overview';
        if ($action === '') {
            $action = 'overview';
        }

        $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : '';
        $errorBox = '';

        // Mutations
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->checkToken();
                $notice = $this->handlePost($action, $staff);
            } catch (ValidationException $e) {
                $errorBox = '<div class="alert alert-danger"><strong>Check the form:</strong><br>'
                    . implode('<br>', array_map('chs_h', $e->fieldErrors())) . '</div>';
            } catch (ChsException $e) {
                $errorBox = '<div class="alert alert-danger">' . chs_h($e->getMessage()) . '</div>';
            } catch (\Throwable $e) {
                \Chs\Core\Logger::error('Admin portal error', ['message' => $e->getMessage()]);
                $errorBox = '<div class="alert alert-danger">Unexpected error: ' . chs_h($e->getMessage()) . '</div>';
            }
        }

        $nav = $this->renderNav($action);
        $body = '';
        try {
            $body = $this->renderSection($action, $staff);
        } catch (ChsException $e) {
            $body = '<div class="alert alert-warning">' . chs_h($e->getMessage()) . '</div>';
        } catch (\Throwable $e) {
            \Chs\Core\Logger::error('Admin render error', ['message' => $e->getMessage(), 'action' => $action]);
            $body = '<div class="alert alert-danger">Could not render this section: ' . chs_h($e->getMessage()) . '</div>';
        }

        $noticeBox = $notice !== ''
            ? '<div class="alert alert-success">' . chs_h($notice) . '</div>' : '';

        return $this->layout($nav . $noticeBox . $errorBox . $body);
    }

    /* ------------------------------------------------------------ mutations -- */

    protected function handlePost($action, $staff)
    {
        $do = isset($_POST['do']) ? (string) $_POST['do'] : '';

        switch ($action) {
            case 'tlds':
                if ($do === 'save_meta') {
                    $service = new TldCatalogService();
                    $service->saveMeta(isset($_POST['tld']) ? $_POST['tld'] : '', [
                        'category'    => isset($_POST['category']) ? $_POST['category'] : '',
                        'region'      => isset($_POST['region']) ? $_POST['region'] : '',
                        'is_featured' => !empty($_POST['is_featured']),
                        'is_popular'  => !empty($_POST['is_popular']),
                        'is_new'      => !empty($_POST['is_new']),
                        'is_trending' => !empty($_POST['is_trending']),
                        'badge'       => isset($_POST['badge']) ? $_POST['badge'] : '',
                        'tagline'     => isset($_POST['tagline']) ? $_POST['tagline'] : '',
                        'sort_order'  => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 100,
                        'visible'     => !empty($_POST['visible']) ? 1 : 0,
                    ]);
                    Audit::admin($staff, 'tld.meta_saved', ['tld' => isset($_POST['tld']) ? $_POST['tld'] : '']);
                    return 'TLD metadata saved.';
                }
                break;

            case 'auctions':
                $service = new AuctionService();
                if ($do === 'create') {
                    $service->createListing([
                        'domain'        => isset($_POST['domain']) ? $_POST['domain'] : '',
                        'start_minor'   => Money::fromDecimal(isset($_POST['start']) ? $_POST['start'] : '0'),
                        'reserve_minor' => !empty($_POST['reserve']) ? Money::fromDecimal($_POST['reserve']) : null,
                        'bin_minor'     => !empty($_POST['bin']) ? Money::fromDecimal($_POST['bin']) : null,
                        'duration_days' => isset($_POST['duration']) ? (int) $_POST['duration'] : 7,
                        'starts_at'     => !empty($_POST['starts_at']) ? ($_POST['starts_at'] . ' 00:00:00') : '',
                        'description'   => isset($_POST['description']) ? $_POST['description'] : '',
                        'currency'      => isset($_POST['currency']) ? $_POST['currency'] : '',
                    ], null, true);
                    return 'Auction created.';
                }
                if ($do === 'cancel') {
                    $service->adminCancel((int) $_POST['id'], $staff, isset($_POST['reason']) ? (string) $_POST['reason'] : '');
                    return 'Auction cancelled.';
                }
                if ($do === 'transferred') {
                    $service->adminMarkTransferred((int) $_POST['id'], $staff, isset($_POST['note']) ? (string) $_POST['note'] : '');
                    return 'Marked as transferred.';
                }
                if ($do === 'heartbeat') {
                    $result = $service->heartbeat();
                    return 'Heartbeat: ' . $result['opened'] . ' opened, ' . $result['closed'] . ' closed.';
                }
                break;

            case 'club':
                $service = new ClubService();
                if ($do === 'save_plan') {
                    $tlds = [];
                    if (!empty($_POST['tlds']) && is_array($_POST['tlds'])) {
                        foreach ($_POST['tlds'] as $tld => $on) {
                            $tlds[$tld] = null;
                        }
                    }
                    $service->savePlan(isset($_POST['plan_id']) && (int) $_POST['plan_id'] ? (int) $_POST['plan_id'] : null, [
                        'name'             => isset($_POST['name']) ? $_POST['name'] : '',
                        'description'      => isset($_POST['description']) ? $_POST['description'] : '',
                        'price_minor'      => Money::fromDecimal(isset($_POST['price']) ? $_POST['price'] : '0'),
                        'currency'         => isset($_POST['currency']) ? $_POST['currency'] : '',
                        'period_months'    => isset($_POST['period_months']) ? (int) $_POST['period_months'] : 12,
                        'discount_percent' => isset($_POST['discount_percent']) ? $_POST['discount_percent'] : 0,
                        'applies_register' => !empty($_POST['applies_register']),
                        'applies_renew'    => !empty($_POST['applies_renew']),
                        'applies_transfer' => !empty($_POST['applies_transfer']),
                        'max_domains'      => isset($_POST['max_domains']) ? (int) $_POST['max_domains'] : 0,
                        'status'           => isset($_POST['status']) ? $_POST['status'] : 'active',
                        'sort_order'       => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 100,
                        'tlds'             => $tlds,
                    ], $staff);
                    return 'Plan saved.';
                }
                break;

            case 'requests':
                $service = new ServiceRequestService();
                $requestId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
                if ($do === 'reply' || $do === 'note') {
                    $service->post($requestId, 'admin', $staff,
                        isset($_POST['body']) ? $_POST['body'] : '',
                        isset($_POST['status_to']) ? (string) $_POST['status_to'] : '',
                        $do === 'note');
                    return $do === 'note' ? 'Internal note added.' : 'Reply posted.';
                }
                if ($do === 'quote') {
                    $service->quote($requestId, $staff,
                        Money::fromDecimal(isset($_POST['quote']) ? $_POST['quote'] : '0'),
                        isset($_POST['body']) ? (string) $_POST['body'] : '');
                    return 'Quote published to the customer.';
                }
                if ($do === 'assign') {
                    $service->adminAssign($requestId, $staff, isset($_POST['assignee']) ? (int) $_POST['assignee'] : 0);
                    return 'Assignee updated.';
                }
                break;

            case 'inbox':
                $service = new InboxService();
                if ($do === 'assign') {
                    $service->adminAssign((int) $_POST['ticket_id'], $staff, (int) $_POST['assignee']);
                    return 'Conversation assigned.';
                }
                if ($do === 'status') {
                    $service->adminSetStatus((int) $_POST['ticket_id'], $staff, (string) $_POST['status']);
                    return 'Status updated.';
                }
                if ($do === 'save_label') {
                    $service->saveLabel($staff, isset($_POST['label_id']) ? (int) $_POST['label_id'] : 0,
                        isset($_POST['name']) ? $_POST['name'] : '',
                        isset($_POST['colour']) ? $_POST['colour'] : '');
                    return 'Label saved.';
                }
                if ($do === 'delete_label') {
                    $service->deleteLabel($staff, (int) $_POST['label_id']);
                    return 'Label deleted.';
                }
                if ($do === 'attach_label') {
                    $service->attachLabel($staff, (int) $_POST['ticket_id'], (int) $_POST['label_id']);
                    return 'Label attached.';
                }
                if ($do === 'detach_label') {
                    $service->detachLabel($staff, (int) $_POST['ticket_id'], (int) $_POST['label_id']);
                    return 'Label removed.';
                }
                break;

            case 'payments':
                if (!\Chs\Admin\BlockonomicsFactory::available()) {
                    throw new ChsException('Blockonomics governance layer missing.');
                }
                $bn = \Chs\Admin\BlockonomicsFactory::admin();
                $ipHash = \CloudHost247\Blockonomics\Bridge::ipHash();
                if ($do === 'save_settings') {
                    return $bn->saveSettings([
                        'gateway_enabled' => !empty($_POST['gateway_enabled']),
                        'btc_enabled'     => !empty($_POST['btc_enabled']),
                        'bch_enabled'     => !empty($_POST['bch_enabled']),
                        'usdt_enabled'    => !empty($_POST['usdt_enabled']),
                        'confirmations'   => isset($_POST['confirmations']) ? (int) $_POST['confirmations'] : 2,
                        'usdt_network'    => isset($_POST['usdt_network']) ? (string) $_POST['usdt_network'] : '',
                    ], $staff, $ipHash);
                }
                if ($do === 'replace_api_key') {
                    return $bn->replaceApiKey(isset($_POST['api_key']) ? (string) $_POST['api_key'] : '', $staff, $ipHash);
                }
                if ($do === 'replace_etherscan_key') {
                    return $bn->replaceEtherscanKey(isset($_POST['etherscan_key']) ? (string) $_POST['etherscan_key'] : '', $staff, $ipHash);
                }
                if ($do === 'update_usdt_address') {
                    return $bn->updateUsdtAddress(isset($_POST['usdt_address']) ? (string) $_POST['usdt_address'] : '', $staff, $ipHash);
                }
                if ($do === 'test_connection') {
                    $r = $bn->testConnection($staff, $ipHash);
                    return 'Connection test: ' . $r['label'] . ' [' . $r['category'] . ']';
                }
                throw new ChsException('Unknown payments action.');

            case 'settings':
                if ($do === 'save') {
                    $this->saveSettings($staff);
                    return 'Settings saved.';
                }
                break;
        }

        return $notice = isset($_POST['notice']) ? (string) $_POST['notice'] : '';
    }

    protected function saveSettings($staff)
    {
        $booleans = [
            'service_enabled', 'public_pages_enabled', 'valuation_enabled', 'whois_enabled',
            'auction_enabled', 'auction_public_browse', 'auction_client_listing',
            'club_enabled', 'club_allow_registrations', 'club_allow_renewals', 'club_allow_transfers',
            'requests_enabled', 'logo_enabled', 'inbox_enabled', 'ai_enabled',
            'notifications_email', 'notifications_inapp', 'auction_cancel_unpaid_invoices',
            'debug_logging',
        ];
        $ints = [
            'valuation_guest_daily_limit', 'valuation_client_daily_limit',
            'whois_timeout_seconds', 'whois_cache_minutes', 'whois_daily_limit_per_ip',
            'anti_snipe_window_seconds', 'anti_snipe_extend_seconds', 'anti_snipe_max_extensions',
            'auction_bid_daily_limit', 'auction_invoice_due_days',
            'club_invoice_due_days', 'requests_daily_limit', 'logo_projects_limit',
            'ai_timeout_seconds', 'ai_daily_limit_per_client',
            'lookup_cache_minutes', 'cache_retention_days', 'consent_retention_days', 'audit_retention_days',
        ];
        $strings = ['valuation_engine', 'valuation_api_url', 'ai_provider', 'ai_endpoint', 'ai_model', 'default_currency'];

        foreach ($booleans as $key) {
            Settings::put($key, !empty($_POST[$key]) ? '1' : '0');
        }
        foreach ($ints as $key) {
            if (isset($_POST[$key]) && \Chs\Core\Validator::isInt($_POST[$key])) {
                Settings::put($key, (string) max(0, (int) $_POST[$key]));
            }
        }
        foreach ($strings as $key) {
            if (isset($_POST[$key])) {
                $value = trim((string) $_POST[$key]);
                if ($key === 'valuation_engine' && !in_array($value, ['rules', 'http'], true)) {
                    continue;
                }
                if ($key === 'default_currency' && $value !== '' && preg_match('/^[A-Z]{3}$/', strtoupper($value)) !== 1) {
                    continue;
                }
                if (strlen($value) > 500) {
                    continue;
                }
                Settings::put($key, $value);
            }
        }
        Audit::admin($staff, 'settings.saved');
    }

    protected function checkToken()
    {
        if (function_exists('check_token')) {
            check_token('WHMCS.admin.default');
            return;
        }
        // Fallback for environments without WHMCS token plumbing (tests).
        \Chs\Core\Csrf::verifyRequest();
    }


    /* ------------------------------------------------------------ sections -- */

    protected function renderSection($action, $staff)
    {
        switch ($action) {
            case 'overview':
                return $this->view('overview', $this->overviewData());
            case 'tlds':
                return $this->view('tlds', [
                    'rows'      => (new TldCatalogService())->adminList(),
                    'messages'  => [],
                    'baseLink'  => $this->baseLink,
                ]);
            case 'valuations':
                return $this->view('valuations', [
                    'rows'   => (new ValuationService())->recentForAdmin(100),
                    'engine' => (new ValuationService())->engineInfo(),
                ]);
            case 'auctions':
                return $this->view('auctions', [
                    'rows' => (new AuctionService())->adminList(isset($_GET['status']) ? (string) $_GET['status'] : ''),
                    'status' => isset($_GET['status']) ? (string) $_GET['status'] : '',
                    'baseLink' => $this->baseLink,
                ]);
            case 'auction':
                $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
                $service = new AuctionService();
                $row = Db::first('auctions', ['id' => $id]);
                if (!$row) {
                    throw new NotFoundException('Auction not found.');
                }
                return $this->view('auction_detail', [
                    'auction' => $row,
                    'journal' => $service->journalFor($id),
                    'bids'    => Db::all('auction_bids', ['auction_id' => $id], 'id DESC', 200),
                    'invoice' => Db::first('auction_invoices', ['auction_id' => $id]),
                    'baseLink' => $this->baseLink,
                ]);
            case 'club':
                return $this->view('club', [
                    'plans'       => (new ClubService())->allPlans(),
                    'memberships' => Db::all('club_memberships', [], 'id DESC', 100),
                    'baseLink'    => $this->baseLink,
                    'tld_choices' => array_map(function ($r) {
                        return $r['tld'];
                    }, (new TldCatalogService())->adminList()),
                ]);
            case 'requests':
                return $this->view('requests', [
                    'rows'  => (new ServiceRequestService())->adminList(isset($_GET['status']) ? (string) $_GET['status'] : ''),
                    'status' => isset($_GET['status']) ? (string) $_GET['status'] : '',
                    'types' => ServiceRequestService::types(),
                    'baseLink' => $this->baseLink,
                ]);
            case 'request':
                $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
                $service = new ServiceRequestService();
                $detail = $service->detailForAdmin($id);
                return $this->view('request_detail', [
                    'request'  => $detail,
                    'statuses' => \Chs\Workflow\RequestStatus::all(),
                    'admins'   => $this->adminDirectory(),
                    'baseLink' => $this->baseLink,
                ]);
            case 'logos':
                return $this->view('logos', [
                    'rows' => Db::all('logo_projects', [], 'id DESC', 200),
                ]);
            case 'ai':
                return $this->view('ai', [
                    'status' => (new AiBuilderService())->status(),
                    'rows'   => Db::all('ai_generations', [], 'id DESC', 100),
                ]);
            case 'inbox':
                $service = new InboxService();
                return $this->view('inbox', [
                    'rows'    => $service->adminQueue(200, isset($_GET['search']) ? (string) $_GET['search'] : '', isset($_GET['label']) ? (int) $_GET['label'] : 0, !empty($_GET['unassigned'])),
                    'labels'  => $service->labels(),
                    'admins'  => $this->adminDirectory(),
                    'search'  => isset($_GET['search']) ? (string) $_GET['search'] : '',
                    'label_filter' => isset($_GET['label']) ? (int) $_GET['label'] : 0,
                    'statuses' => ['Open', 'Answered', 'Customer-Reply', 'In Progress', 'On Hold', 'Closed'],
                    'baseLink' => $this->baseLink,
                ]);
            case 'payments':
                if (!\Chs\Admin\BlockonomicsFactory::available()) {
                    return '<div class="alert alert-warning">Blockonomics governance layer not found. '
                        . 'Deploy modules/gateways/blockonomics/cloudhost247/ first.</div>';
                }
                $bn = \Chs\Admin\BlockonomicsFactory::admin();
                return $this->view('blockonomics', ['state' => $bn->panelState()]);
            case 'crypto_tx':
                if (!\Chs\Admin\BlockonomicsFactory::available()) {
                    return '<div class="alert alert-warning">Blockonomics governance layer not found.</div>';
                }
                $legacyLoader = function () {
                    if (!function_exists('getGatewayVariables')) {
                        require_once dirname(__DIR__, 4) . '/includes/gatewayfunctions.php';
                    }
                    $params = getGatewayVariables('blockonomics');
                    return is_array($params) ? $params : [];
                };
                $policy = \Chs\Admin\BlockonomicsFactory::displayPolicy($legacyLoader);
                $filters = [
                    'search'       => isset($_GET['search']) ? (string) $_GET['search'] : '',
                    'currency'     => isset($_GET['currency']) ? (string) $_GET['currency'] : '',
                    'network'      => isset($_GET['network']) ? (string) $_GET['network'] : '',
                    'status'       => isset($_GET['status']) ? (string) $_GET['status'] : '',
                    'from'         => isset($_GET['from']) ? (string) $_GET['from'] : '',
                    'to'           => isset($_GET['to']) ? (string) $_GET['to'] : '',
                    'page'         => isset($_GET['page']) ? (int) $_GET['page'] : 1,
                    'confirmations'  => $policy['confirmations'],
                    'time_period_min' => $policy['time_period_min'],
                ];
                $result = \Chs\Admin\BlockonomicsFactory::transactions()->query($filters);
                return $this->view('crypto_tx', [
                    'rows' => $result['rows'], 'total' => $result['total'],
                    'page' => $result['page'], 'pages' => $result['pages'],
                    'filters' => $filters,
                    'statuses' => ['Pending', 'Confirming', 'Paid', 'Failed', 'Expired', 'Cancelled', 'Refunded'],
                ]);
            case 'audit':
                return $this->view('audit', [
                    'rows' => Audit::recent(200),
                ]);
            case 'settings':
                return $this->view('settings', [
                    'values'   => Settings::editable(),
                    'secretKeys' => Settings::SECRET_KEYS,
                    'baseLink' => $this->baseLink,
                ]);
            case 'overview':
            default:
                return $this->view('overview', $this->overviewData());
        }
    }

    protected function overviewData()
    {
        return [
            'counts' => [
                'active_auctions' => Db::count('auctions', ['status' => 'active']),
                'open_requests'   => Db::query(
                    'SELECT COUNT(*) AS c FROM ' . Db::t('service_requests')
                    . " WHERE status IN ('requested','reviewing','quoted','accepted','in_progress')"
                ) ? (int) Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('service_requests')
                    . " WHERE status IN ('requested','reviewing','quoted','accepted','in_progress')")[0]['c'] : 0,
                'active_memberships' => Db::count('club_memberships', ['status' => 'active']),
                'valuations_today' => Db::query(
                    'SELECT COUNT(*) AS c FROM ' . Db::t('valuations') . ' WHERE created_at >= ?',
                    [Clock::ago(86400)]
                ) ? (int) Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('valuations') . ' WHERE created_at >= ?', [Clock::ago(86400)])[0]['c'] : 0,
                'tlds_merchandised' => Db::count('tld_meta'),
                'ai_generations'     => Db::count('ai_generations'),
            ],
            'ai_status' => (new AiBuilderService())->status(),
            'engine'    => (new ValuationService())->engineInfo(),
            'baseLink'  => $this->baseLink,
        ];
    }

    /** id => display name, for assignment pickers. */
    protected function adminDirectory()
    {
        try {
            $rows = Db::query('SELECT id, username, firstname, lastname FROM tbladmins ORDER BY firstname, username');
            $out = [];
            foreach ($rows as $row) {
                $name = trim($row['firstname'] . ' ' . $row['lastname']);
                $out[(int) $row['id']] = $name !== '' ? $name : $row['username'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* -------------------------------------------------------------- nav -- */

    protected function renderNav($current)
    {
        $items = [
            'overview'   => 'Overview',
            'tlds'       => 'TLD Catalogue',
            'valuations' => 'Valuations',
            'auctions'   => 'Auctions',
            'club'       => 'Domain Club',
            'requests'   => 'Service Requests',
            'logos'      => 'Logo Projects',
            'ai'         => 'AI Builder',
            'inbox'      => 'Unified Inbox',
            'payments'   => 'Payments · Blockonomics',
            'crypto_tx'  => 'Crypto Transactions',
            'audit'      => 'Audit Log',
            'settings'   => 'Settings',
        ];
        $html = '<ul class="nav nav-tabs chs-admin-nav">';
        foreach ($items as $key => $label) {
            $active = ($key === $current || ($key === 'auctions' && $current === 'auction') || ($key === 'requests' && $current === 'request')) ? ' class="active"' : '';
            $html .= '<li' . $active . '><a href="' . chs_h($this->baseLink . '&action=' . $key) . '">' . chs_h($label) . '</a></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /* -------------------------------------------------------------- view -- */

    protected function view($template, array $vars)
    {
        $file = dirname(dirname(__DIR__)) . '/templates/admin/' . $template . '.phtml';
        if (!is_file($file)) {
            return '<div class="alert alert-danger">Missing admin view: ' . chs_h($template) . '</div>';
        }
        $vars['baseLink'] = $this->baseLink;
        $vars['tokenField'] = function_exists('generate_token')
            ? generate_token('input') : \Chs\Core\Csrf::field();
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    protected function layout($content)
    {
        $cssPath = '../modules/addons/cloudhost247services/assets/css/admin.css';
        return '<link rel="stylesheet" href="' . $cssPath . '">'
            . '<div class="chs-admin">' . $content . '</div>';
    }
}
