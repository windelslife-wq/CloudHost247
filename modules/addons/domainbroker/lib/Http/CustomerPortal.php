<?php
/**
 * Domain Broker — the client area.
 *
 * Returns the structure WHMCS' addon client area expects
 * (pagetitle / breadcrumb / templatefile / vars) and renders through the
 * module's Smarty templates. Writes are post/redirect/get and CSRF protected;
 * nothing here decides authorisation — every call is handed to a service with
 * the server-resolved Actor.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Http;

use DomainBroker\Api\Presenter;
use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\DomainBrokerException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\DomainName;
use DomainBroker\Core\Logger;
use DomainBroker\Core\Money;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\NotificationService;
use DomainBroker\Workflow\RequestStatus;

class CustomerPortal extends Controller
{
    /** Read-only pages, keyed by the ?action= value. */
    const PAGES = [
        'dashboard', 'requests', 'new', 'view', 'transactions', 'help', 'notifications',
    ];

    /** Write actions, keyed by the ?action= value. */
    const ACTIONS = [
        'create', 'update', 'cancel', 'accept-offer', 'reject-offer', 'counter-offer',
        'send-message', 'upload-document', 'pay', 'open-dispute', 'mark-read',
    ];

    /** @var int */
    protected $perPage = 15;

    /**
     * Entry point used by domainbroker_clientarea().
     *
     * @return array the WHMCS client area page definition
     */
    public function handle($vars = [])
    {
        $action = (string) $this->input('action', 'dashboard');

        if (!$this->actor->isCustomer()) {
            // Staff are resolved from the admin session, so their workspace is
            // the admin area; the client area only ever serves a client's own
            // acquisitions and never somebody else's.
            return $this->loginRequired();
        }

        if ($this->isPost()) {
            return $this->handleWrite($action);
        }

        // A hand-edited id in the address bar is a routine event, not an
        // exception the customer should see a stack trace for.
        try {
            switch ($action) {
                case 'requests':
                    return $this->requestsPage();
                case 'new':
                    return $this->newRequestPage();
                case 'view':
                    return $this->requestPage();
                case 'transactions':
                    return $this->transactionsPage();
                case 'notifications':
                    return $this->notificationsPage();
                case 'help':
                    return $this->helpPage();
                case 'document':
                    return $this->downloadDocument();
                case 'dashboard':
                default:
                    return $this->dashboardPage();
            }
        } catch (AuthorizationException $e) {
            return $this->messagePage('Not available', $e->getMessage(), 'fa-lock');
        } catch (DomainBrokerException $e) {
            return $this->messagePage('Not available', $e->getMessage(), 'fa-info-circle');
        } catch (\Throwable $e) {
            Logger::error('Unhandled error rendering a Domain Broker client page.', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'action' => $action,
                'actor' => $this->actor->identity(),
            ]);
            return $this->messagePage(
                'Something went wrong',
                'We could not load that page. Our team has been notified.',
                'fa-exclamation-triangle'
            );
        }
    }

    /**
     * A dead-end page: the request does not exist, is not this client's, or
     * the service declined to show it. Deliberately says nothing about which.
     */
    protected function messagePage($title, $body, $icon = 'fa-info-circle')
    {
        return $this->page('message', $title, [
            'message_title' => $title,
            'message_body' => $body,
            'message_icon' => $icon,
            'nav' => View::clientNav($this->actor, 'requests'),
        ]);
    }

    /* -------------------------------------------------------------- pages */

    protected function dashboardPage()
    {
        $summary = $this->requestService()->customerSummary($this->actor);
        $recent = $this->requestService()->listForActor($this->actor, ['limit' => 5, 'offset' => 0]);
        $actionable = $this->requestService()->listForActor($this->actor, [
            'statuses' => [
                RequestStatus::OFFER_RECEIVED,
                RequestStatus::COUNTEROFFER,
                RequestStatus::PAYMENT_PENDING,
            ],
            'limit' => 10,
            'offset' => 0,
        ]);

        $notifications = new NotificationService();

        return $this->page('dashboard', 'Domain Broker', [
            'summary' => $summary,
            'recent' => $this->decorate(Presenter::requests($recent, $this->actor)),
            'actionable' => $this->decorate(Presenter::requests($actionable, $this->actor)),
            'unread_notifications' => $notifications->unreadCountForClient((int) $this->actor->clientId),
            'spend_rows' => $this->spendRows($summary['spent']),
            'nav' => View::clientNav($this->actor, 'dashboard'),
        ]);
    }

    protected function requestsPage()
    {
        $page = max(1, $this->intInput('p', 1));
        $filters = [
            'status' => (string) $this->input('status', ''),
            'search' => (string) $this->input('search', ''),
        ];
        $query = array_filter($filters, 'strlen');
        $query['limit'] = $this->perPage;
        $query['offset'] = ($page - 1) * $this->perPage;

        $rows = $this->requestService()->listForActor($this->actor, $query);
        $total = $this->requestService()->countForActor($this->actor, array_filter($filters, 'strlen'));

        $filtersForUrl = array_filter($filters, 'strlen');
        $pagination = View::pagination($total, $page, $this->perPage, function ($n) use ($filtersForUrl) {
            return View::url('requests', array_merge($filtersForUrl, ['p' => $n]));
        });

        return $this->page('requests', 'My Acquisitions', [
            'requests' => $this->decorate(Presenter::requests($rows, $this->actor)),
            'filters' => $filters,
            'status_options' => View::statusOptions(),
            'pagination' => $pagination,
            'nav' => View::clientNav($this->actor, 'requests'),
        ], [View::url('requests') => 'My Acquisitions']);
    }

    protected function newRequestPage()
    {
        $prefill = (string) $this->input('domain', '');
        $lookup = null;
        if ($prefill !== '') {
            try {
                $lookup = $this->domainService()->checkAvailability($prefill);
            } catch (\Throwable $e) {
                $lookup = null;
            }
        }

        return $this->page('request_new', 'Request a Domain Acquisition', [
            'prefill_domain' => $prefill,
            'lookup' => $lookup,
            'fee_note' => $this->feeNote(),
            'min_budget' => Money::toDecimalString(
                Settings::int('min_budget_minor', 0),
                Settings::string('default_currency', 'USD')
            ),
            'kyc_threshold' => Money::format(
                Settings::int('require_kyc_above_minor', 0),
                Settings::string('default_currency', 'USD')
            ),
            'nav' => View::clientNav($this->actor, 'new'),
        ], [View::url('new') => 'New Request']);
    }

    protected function requestPage()
    {
        $request = $this->requestService()->findForActor($this->actor, $this->intInput('id'));
        $payload = $this->requestDetail($request);

        return $this->page('request_detail', $request['domain_display'] ?: $request['domain'], array_merge($payload, [
            'nav' => View::clientNav($this->actor, 'requests'),
        ]), [
            View::url('requests') => 'My Acquisitions',
            View::url('view', ['id' => (int) $request['id']]) => $request['reference'],
        ]);
    }

    /** Everything the request detail page renders. */
    protected function requestDetail(array $request)
    {
        $id = (int) $request['id'];
        $view = Presenter::request($request, $this->actor);
        $offers = Presenter::offers($this->negotiationService()->offersFor($id, 'customer'), $this->actor);
        $payment = Presenter::payment($this->paymentService()->activePayment($id), $this->actor);
        $transfer = Presenter::transfer($this->transferService()->forRequest($id), $this->actor);
        $documents = Presenter::documents($this->documentService()->listFor($this->actor, $id));
        $threads = $this->messageService()->threadsFor($this->actor, $id);
        $timeline = Presenter::timeline(Audit::timeline($id, 'customer'));
        $dispute = $this->disputeService()->forRequest($id);

        $broker = null;
        if ($request['assigned_broker_id']) {
            $row = $this->brokerService()->find($request['assigned_broker_id']);
            $broker = $row ? $this->brokerService()->publicProfile($row) : null;
        }

        $pendingOffer = null;
        foreach ($offers as $offer) {
            if ($offer['direction'] === 'to_customer' && $offer['status'] === 'pending') {
                $pendingOffer = $offer;
                break;
            }
        }

        // Mark the customer thread read on view — the unread badge should
        // reflect reality, and the read receipt is part of the audit trail.
        $this->messageService()->markThreadRead($this->actor, $id);

        return [
            'request' => $this->decorateOne($view),
            'tracker' => View::tracker($request['status']),
            'offers' => $offers,
            'pending_offer' => $pendingOffer,
            'payment' => $payment,
            'transfer' => $transfer,
            'documents' => $documents,
            'messages' => isset($threads['customer']) ? Presenter::messages($threads['customer']) : [],
            'timeline' => $timeline,
            'broker' => $broker,
            'dispute' => $dispute ? Presenter::dispute($dispute, $this->actor) : null,
            'verification' => [
                'status' => $request['verification_status'],
                'outstanding' => count($this->verificationService()->outstandingRequirements($request)),
            ],
            'can' => $this->capabilities($request, $payment, $pendingOffer),
            'invoice_url' => $request['whmcs_invoice_id']
                ? 'viewinvoice.php?id=' . (int) $request['whmcs_invoice_id']
                : null,
            'document_types' => $this->documentTypeOptions(),
            'dispute_reasons' => $this->disputeReasonOptions(),
            'max_upload_mb' => round(Settings::int('document_max_bytes', 15728640) / 1048576, 1),
        ];
    }

    protected function transactionsPage()
    {
        $filters = [
            'status' => (string) $this->input('status', ''),
            'from' => (string) $this->input('from', ''),
            'to' => (string) $this->input('to', ''),
        ];
        $rows = $this->paymentService()->transactionHistory(
            (int) $this->actor->clientId,
            array_filter($filters, 'strlen') + ['limit' => 200]
        );

        $transactions = [];
        foreach ($rows as $row) {
            $view = Presenter::payment($row, $this->actor);
            $view['request_reference'] = $row['request_reference'];
            $view['domain'] = $row['domain'];
            $view['request_url'] = View::url('view', ['id' => (int) $row['req_id']]);
            $view['invoice_url'] = $row['whmcs_invoice_id']
                ? 'viewinvoice.php?id=' . (int) $row['whmcs_invoice_id']
                : null;
            $transactions[] = $view;
        }

        return $this->page('transactions', 'Transaction History', [
            'transactions' => $transactions,
            'filters' => $filters,
            'status_options' => View::paymentStatusOptions(),
            'spend_rows' => $this->spendRows($this->requestService()->spentByCurrency((int) $this->actor->clientId)),
            'nav' => View::clientNav($this->actor, 'transactions'),
        ], [View::url('transactions') => 'Transactions']);
    }

    protected function notificationsPage()
    {
        $service = new NotificationService();
        return $this->page('notifications', 'Notifications', [
            'notifications' => $service->inboxForClient((int) $this->actor->clientId, false, 100),
            'nav' => View::clientNav($this->actor, 'dashboard'),
        ], [View::url('notifications') => 'Notifications']);
    }

    protected function helpPage()
    {
        return $this->page('help', 'Domain Broker — Help & FAQ', [
            'faqs' => $this->faqs(),
            'fee_note' => $this->feeNote(),
            'nav' => View::clientNav($this->actor, 'help'),
        ], [View::url('help') => 'Help']);
    }

    /* ------------------------------------------------------------- writes */

    protected function handleWrite($action)
    {
        try {
            $this->guardCsrf();
        } catch (\Throwable $e) {
            $this->error('Your session has expired. Please try again.');
            return $this->redirectPage(View::url('dashboard'));
        }

        $id = $this->intInput('id');
        $back = $id > 0 ? View::url('view', ['id' => $id]) : View::url('dashboard');

        switch ($action) {
            case 'create':
                return $this->createRequest();
            case 'update':
                $this->attempt(function () use ($id) {
                    $this->requestService()->updateByCustomer($this->actor, $id, $this->only([
                        'budget', 'currency', 'budget_includes_fees', 'message', 'anonymous',
                    ]));
                }, 'Your acquisition request has been updated.');
                return $this->redirectPage($back);

            case 'cancel':
                $this->attempt(function () use ($id) {
                    $this->requestService()->cancelByCustomer(
                        $this->actor,
                        $id,
                        (string) $this->input('reason', '')
                    );
                }, 'Your acquisition request has been cancelled.');
                return $this->redirectPage($back);

            case 'accept-offer':
                $offerId = $this->intInput('offer_id');
                $this->attempt(function () use ($offerId) {
                    $this->negotiationService()->acceptOffer($this->actor, $offerId);
                }, 'Offer accepted. We will raise your invoice next.');
                return $this->redirectPage($back);

            case 'reject-offer':
                $offerId = $this->intInput('offer_id');
                $this->attempt(function () use ($offerId) {
                    $this->negotiationService()->rejectOffer(
                        $this->actor,
                        $offerId,
                        (string) $this->input('reason', '')
                    );
                }, 'Offer rejected. Your broker will continue negotiating.');
                return $this->redirectPage($back);

            case 'counter-offer':
                $offerId = $this->intInput('offer_id');
                $this->attempt(function () use ($offerId) {
                    $this->negotiationService()->counterOffer($this->actor, $offerId, [
                        'amount' => $this->input('amount', ''),
                        'message' => (string) $this->input('message', ''),
                    ]);
                }, 'Your counteroffer has been sent to your broker.');
                return $this->redirectPage($back);

            case 'send-message':
                $this->attempt(function () use ($id) {
                    $this->messageService()->send($this->actor, $id, [
                        'body' => (string) $this->input('body', ''),
                    ]);
                }, 'Message sent.');
                return $this->redirectPage($back . '#messages');

            case 'upload-document':
                $this->attempt(function () use ($id) {
                    $file = isset($_FILES['document']) ? $_FILES['document'] : [];
                    $this->documentService()->upload($this->actor, $id, $file, [
                        'category' => (string) $this->input('category', 'other'),
                        'description' => (string) $this->input('description', ''),
                    ]);
                }, 'Document uploaded.');
                return $this->redirectPage($back . '#documents');

            case 'pay':
                return $this->payRequest($id, $back);

            case 'open-dispute':
                $this->attempt(function () use ($id) {
                    $this->disputeService()->open($this->actor, $id, [
                        'reason_code' => (string) $this->input('reason_code', ''),
                        'description' => (string) $this->input('description', ''),
                        'severity' => (string) $this->input('severity', 'normal'),
                    ]);
                }, 'Your dispute has been opened and escalated to our team.');
                return $this->redirectPage($back);

            case 'mark-read':
                $service = new NotificationService();
                $service->markAllRead('customer', (int) $this->actor->clientId);
                return $this->redirectPage(View::url('notifications'));
        }

        $this->error('That action is not available.');
        return $this->redirectPage(View::url('dashboard'));
    }

    protected function createRequest()
    {
        $created = null;
        $ok = $this->attempt(function () use (&$created) {
            $input = $this->only([
                'domain', 'budget', 'currency', 'budget_includes_fees',
                'message', 'anonymous', 'promo_code', 'idempotency_key',
            ]);
            // Never trust a client id from the browser.
            unset($input['client_id']);
            $input['source'] = 'clientarea';
            $created = $this->requestService()->create($this->actor, $input);
        }, 'Your acquisition request has been received.');

        if ($ok && $created) {
            return $this->redirectPage(View::url('view', ['id' => (int) $created['id'], 'new' => 1]));
        }
        return $this->redirectPage(View::url('new', ['domain' => (string) $this->input('domain', '')]));
    }

    protected function payRequest($id, $back)
    {
        $payment = null;
        $ok = $this->attempt(function () use ($id, &$payment) {
            $payment = $this->paymentService()->generateInvoice($this->actor, $id, [
                'idempotency_key' => (string) $this->input('idempotency_key', ''),
            ]);
        });

        if ($ok && $payment && !empty($payment['whmcs_invoice_id'])) {
            // Hand the customer straight to the WHMCS invoice so payment runs
            // through the existing billing stack, not a parallel one.
            return $this->redirectPage('viewinvoice.php?id=' . (int) $payment['whmcs_invoice_id']);
        }
        if ($ok) {
            $this->success('Your invoice is being prepared. It will appear in your billing area shortly.');
        }
        return $this->redirectPage($back);
    }

    /* ---------------------------------------------------------- downloads */

    /**
     * Stream a document. Authorisation and the audit record are produced by
     * DocumentService; nothing is served from a public path.
     */
    protected function downloadDocument()
    {
        $documentId = $this->intInput('id');
        try {
            $file = $this->documentService()->openForDownload($this->actor, $documentId);
        } catch (\Throwable $e) {
            $this->error('That document is not available.');
            return $this->redirectPage(View::url('dashboard'));
        }

        if (self::$testMode || php_sapi_name() === 'cli') {
            return ['download' => $file];
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . (int) $file['size']);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file['path']);
        exit;
    }

    /* ------------------------------------------------------------ helpers */

    /** What the customer may do right now, so the template never guesses. */
    protected function capabilities(array $request, $payment, $pendingOffer)
    {
        $status = $request['status'];
        return [
            'edit' => in_array($status, [
                RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW, RequestStatus::BROKER_ASSIGNED,
            ], true),
            'cancel' => !RequestStatus::isTerminal($status)
                && !in_array($status, [RequestStatus::PAYMENT_SECURED, RequestStatus::DOMAIN_TRANSFER,
                    RequestStatus::TRANSFER_VERIFICATION], true),
            'respond_to_offer' => $pendingOffer !== null,
            'counter' => $pendingOffer !== null && Rbac::allows($this->actor, Rbac::OFFER_COUNTER),
            'pay' => in_array($status, [RequestStatus::OFFER_ACCEPTED, RequestStatus::PAYMENT_PENDING], true),
            'message' => !RequestStatus::isTerminal($status) || $status === RequestStatus::DISPUTED,
            'upload' => !RequestStatus::isTerminal($status) || $status === RequestStatus::DISPUTED,
            'dispute' => RequestStatus::isFinanciallyActive($status) || $status === RequestStatus::COMPLETED,
        ];
    }

    /** Add the links and badges every list row needs. */
    protected function decorate(array $requests)
    {
        foreach ($requests as $index => $request) {
            $requests[$index] = $this->decorateOne($request);
        }
        return $requests;
    }

    protected function decorateOne(array $request)
    {
        $request['url'] = View::url('view', ['id' => (int) $request['id']]);
        $request['status_class'] = View::toneClass($request['status_tone']);
        $request['submitted_display'] = View::date($request['submitted_at']);
        $request['updated_display'] = View::relative($request['updated_at']);
        return $request;
    }

    /** Pre-format the spend table; templates never do money arithmetic. */
    protected function spendRows(array $spent)
    {
        $rows = [];
        foreach ($spent as $currency => $row) {
            $rows[] = [
                'currency' => $currency,
                'acquisition' => Money::format($row['acquisition'], $currency),
                'fees' => Money::format($row['fees'], $currency),
                'tax' => Money::format($row['tax'], $currency),
                'refunded' => Money::format($row['refunded'], $currency),
                'net' => Money::format($row['net'], $currency),
            ];
        }
        return $rows;
    }

    protected function documentTypeOptions()
    {
        return \DomainBroker\Services\DocumentService::CATEGORIES;
    }

    protected function disputeReasonOptions()
    {
        return \DomainBroker\Services\DisputeService::REASONS;
    }

    /**
     * Render a stored percentage for humans: 10.0000 becomes "10", 7.5000
     * becomes "7.5". Trailing zeros are only stripped after a decimal point,
     * so a whole-number rate is never mangled into a different number.
     */
    public static function trimPercentage($value)
    {
        $raw = rtrim((string) $value);
        if (strpos($raw, '.') === false) {
            return $raw;
        }
        return rtrim(rtrim($raw, '0'), '.');
    }

    protected function feeNote()
    {
        $currency = Settings::string('default_currency', 'USD');
        try {
            $sample = $this->feeService()->resolveRule(1000000, $currency);
        } catch (\Throwable $e) {
            $sample = null;
        }
        if (!$sample) {
            return 'Our brokerage fee is confirmed in writing before you are asked to pay anything.';
        }
        if ($sample['calculation'] === 'percentage') {
            return 'Our standard brokerage fee is ' . self::trimPercentage($sample['percentage'])
                . '% of the agreed acquisition price, confirmed in writing before any payment is requested.';
        }
        return 'Our brokerage fee is confirmed in writing before any payment is requested.';
    }

    protected function faqs()
    {
        return [
            [
                'q' => 'What is the Domain Broker Service?',
                'a' => 'When the domain you want is already registered, our brokers approach the current owner on '
                    . 'your behalf, negotiate a price within your budget and manage the transfer and payment '
                    . 'end to end.',
            ],
            [
                'q' => 'How much does it cost?',
                'a' => $this->feeNote() . ' You are never charged until you have accepted an offer in writing.',
            ],
            [
                'q' => 'Will the owner know who I am?',
                'a' => 'Only if you want them to. Choose anonymous negotiation and your broker acts as the '
                    . 'intermediary; your identity is never disclosed to the owner.',
            ],
            [
                'q' => 'What happens to my money?',
                'a' => 'Funds are held securely and are only released to the seller once the domain transfer has '
                    . 'been completed and verified. Payment and transfer are tracked as separate stages.',
            ],
            [
                'q' => 'What if the owner will not sell?',
                'a' => 'Some owners decline or never reply. If we cannot reach an agreement the request is closed '
                    . 'as unsuccessful and you pay nothing.',
            ],
            [
                'q' => 'How long does an acquisition take?',
                'a' => 'Most negotiations conclude within two to six weeks. Transfers typically add five to seven '
                    . 'days depending on the registry and the current registrar.',
            ],
            [
                'q' => 'Can I change my budget after submitting?',
                'a' => 'Yes — while the request is still being reviewed or has just been assigned you can edit '
                    . 'your maximum budget and brief from the request page.',
            ],
            [
                'q' => 'Do you handle every extension?',
                'a' => 'We broker the extensions we are able to transfer and verify. If an extension is not '
                    . 'supported you will be told as soon as you submit the request.',
            ],
        ];
    }

    /* -------------------------------------------------------------- framing */

    /** Build the WHMCS client area page definition. */
    protected function page($template, $title, array $vars, array $breadcrumb = [])
    {
        return [
            'pagetitle' => $title,
            'breadcrumb' => array_merge([View::url('dashboard') => 'Domain Broker'], $breadcrumb),
            'templatefile' => $template,
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => $this->sharedViewData($vars),
        ];
    }

    protected function redirectPage($url)
    {
        $this->redirect($url);
        return [
            'pagetitle' => 'Redirecting',
            'breadcrumb' => [View::url('dashboard') => 'Domain Broker'],
            'templatefile' => 'redirect',
            'requirelogin' => true,
            'vars' => $this->sharedViewData(['redirect_url' => $url]),
        ];
    }

    protected function loginRequired()
    {
        return [
            'pagetitle' => 'Domain Broker',
            'breadcrumb' => [View::url('dashboard') => 'Domain Broker'],
            'templatefile' => 'login_required',
            'requirelogin' => true,
            'vars' => $this->sharedViewData([
                'landing_url' => 'domain-broker.php',
                'is_staff' => $this->actor->isBroker() || $this->actor->isAdmin(),
                'staff_url' => View::adminLink(),
            ]),
        ];
    }
}
