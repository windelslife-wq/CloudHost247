<?php
/**
 * Domain Broker — the WHMCS admin console.
 *
 * Rendered as HTML (the addon-module contract). Every page is gated on an
 * explicit permission, every write is CSRF protected and goes through a
 * service, and every administrative action lands in the audit log through
 * those services — this class never writes to a table directly.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Http;

use DomainBroker\Api\Presenter;
use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Db;
use DomainBroker\Core\DomainBrokerException;
use DomainBroker\Core\Money;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Services\DisputeService;
use DomainBroker\Services\NotificationService;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;
use DomainBroker\Workflow\TransferStatus;

class AdminPortal extends Controller
{
    /** @var int */
    protected $perPage = 25;

    /** @var string|null captured export payload in test mode */
    public static $lastExport;

    public function handle(array $vars = [])
    {
        if (isset($vars['modulelink'])) {
            View::setAdminLink($vars['modulelink']);
        }

        // A staff broker gets their own desk, not the administrative console.
        if ($this->actor->isBroker()) {
            $desk = new BrokerDesk($this->actor);
            return $desk->handle($vars);
        }

        if (!$this->actor->isAdmin()) {
            return Html::alert('You are not signed in with a Domain Broker administrative role.', 'danger');
        }

        $action = (string) $this->input('action', 'dashboard');

        if ($this->isPost()) {
            $redirect = $this->handleWrite($action);
            if ($redirect !== null) {
                return $redirect;
            }
        }

        try {
            $body = $this->renderPage($action);
        } catch (AuthorizationException $e) {
            $body = Html::alert($e->getMessage(), 'danger');
        } catch (DomainBrokerException $e) {
            $body = Html::alert($e->getMessage(), 'warning');
        }

        return $this->chrome($action, $body);
    }

    /* ----------------------------------------------------------- framing */

    protected function chrome($action, $body)
    {
        $flash = '';
        foreach ($this->takeFlash() as $message) {
            $flash .= Html::alert($message['message'], $message['type']);
        }

        return '<div class="domainbroker-admin">'
            . $this->styles()
            . Html::tabs(View::adminNav($this->actor, $this->navKey($action)))
            . '<div class="db-admin-body">' . $flash . $body . '</div>'
            . '</div>';
    }

    protected function navKey($action)
    {
        $map = [
            'request' => 'requests',
            'dispute' => 'disputes',
            'broker' => 'brokers',
            'payment' => 'payments',
            'fee' => 'fees',
        ];
        return isset($map[$action]) ? $map[$action] : $action;
    }

    protected function styles()
    {
        return '<link rel="stylesheet" href="../modules/addons/domainbroker/assets/css/admin.css">';
    }

    protected function url(array $params)
    {
        return View::adminLink($params);
    }

    /* -------------------------------------------------------------- pages */

    protected function renderPage($action)
    {
        switch ($action) {
            case 'requests':
                return $this->requestsPage();
            case 'request':
                return $this->requestPage();
            case 'brokers':
                return $this->brokersPage();
            case 'payments':
                return $this->paymentsPage();
            case 'disputes':
                return $this->disputesPage();
            case 'dispute':
                return $this->disputePage();
            case 'risk':
                return $this->riskPage();
            case 'fees':
                return $this->feesPage();
            case 'reports':
                return $this->reportsPage();
            case 'audit':
                return $this->auditPage();
            case 'settings':
                return $this->settingsPage();
            case 'dashboard':
            default:
                return $this->dashboardPage();
        }
    }

    protected function dashboardPage()
    {
        Rbac::assert($this->actor, Rbac::REQUEST_VIEW_ALL);

        $stats = '';
        if (Rbac::allows($this->actor, Rbac::REPORT_VIEW)) {
            $overview = $this->reportService()->overview($this->actor, ['period' => '30d']);
            $stats = '<div class="row db-stats">'
                . '<div class="col-sm-3">' . Html::stat('Requests (30 days)', $overview['requests']['total'], 'primary', $this->url(['action' => 'requests'])) . '</div>'
                . '<div class="col-sm-3">' . Html::stat('Active negotiations', $overview['negotiations']['active'], 'info') . '</div>'
                . '<div class="col-sm-3">' . Html::stat('Completed', $overview['requests']['completed'], 'success') . '</div>'
                . '<div class="col-sm-3">' . Html::stat('Conversion rate', $overview['conversion_rate'] . '%', 'default') . '</div>'
                . '</div>'
                . '<div class="row db-stats">'
                . '<div class="col-sm-3">' . Html::stat('Unassigned', $overview['pending']['unassigned'], 'warning', $this->url(['action' => 'requests', 'scope' => 'unassigned'])) . '</div>'
                . '<div class="col-sm-3">' . Html::stat('Pending payments', $overview['pending']['payments'], 'warning', $this->url(['action' => 'payments'])) . '</div>'
                . '<div class="col-sm-3">' . Html::stat('Pending transfers', $overview['pending']['transfers'], 'info') . '</div>'
                . '<div class="col-sm-3">' . Html::stat('Awaiting manual review', $overview['pending']['manual_review'], 'danger', $this->url(['action' => 'risk'])) . '</div>'
                . '</div>';
        }

        $recent = $this->requestService()->listForActor($this->actor, ['limit' => 12, 'offset' => 0]);
        $table = Html::table(
            ['Reference', 'Domain', 'Client', 'Status', 'Budget', 'Broker', 'Updated'],
            array_map([$this, 'requestRow'], $recent),
            'No acquisition requests have been filed yet.'
        );

        $queue = Db::fetch('requests', [
            'assigned_broker_id' => null,
            'deleted_at' => null,
            'status' => ['in', [RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW]],
        ], ['order' => 'created_at', 'dir' => 'asc', 'limit' => 10]);

        return Html::heading('Domain Broker', 'Operational overview of the acquisition desk.')
            . $stats
            . Html::panel('Unassigned queue', Html::table(
                ['Reference', 'Domain', 'Client', 'Status', 'Budget', 'Broker', 'Updated'],
                array_map([$this, 'requestRow'], $queue),
                'Every request currently has a broker.'
            ), 'warning')
            . Html::panel('Latest requests', $table);
    }

    protected function requestsPage()
    {
        Rbac::assert($this->actor, Rbac::REQUEST_VIEW_ALL);

        $page = max(1, $this->intInput('p', 1));
        $filters = [
            'status' => (string) $this->input('status', ''),
            'search' => (string) $this->input('search', ''),
            'broker_id' => (string) $this->input('broker_id', ''),
            'from' => (string) $this->input('from', ''),
            'to' => (string) $this->input('to', ''),
        ];
        if ($this->input('scope') === 'unassigned') {
            $filters['status'] = '';
        }
        $active = array_filter($filters, 'strlen');

        $query = $active;
        $query['limit'] = $this->perPage;
        $query['offset'] = ($page - 1) * $this->perPage;
        $rows = $this->requestService()->listForActor($this->actor, $query);
        $total = $this->requestService()->countForActor($this->actor, $active);

        $brokerOptions = ['' => 'Any broker'];
        foreach ($this->brokerService()->listAll($this->actor, []) as $broker) {
            $brokerOptions[(int) $broker['id']] = $broker['display_name'];
        }

        $form = '<form method="get" action="' . Html::e(View::adminLink()) . '" class="form-inline db-filters">'
            . Html::hidden('module', 'domainbroker')
            . Html::hidden('action', 'requests')
            . Html::input('search', $filters['search'], ['placeholder' => 'Reference or domain', 'class' => 'form-control input-sm'])
            . ' ' . Html::select('status', ['' => 'Any status'] + View::statusOptions(), $filters['status'], ['class' => 'form-control input-sm'])
            . ' ' . Html::select('broker_id', $brokerOptions, $filters['broker_id'], ['class' => 'form-control input-sm'])
            . ' ' . Html::input('from', $filters['from'], ['type' => 'date', 'class' => 'form-control input-sm'])
            . ' ' . Html::input('to', $filters['to'], ['type' => 'date', 'class' => 'form-control input-sm'])
            . ' ' . Html::submit('Filter', 'btn btn-sm btn-primary')
            . ' ' . Html::link('Reset', $this->url(['action' => 'requests']), 'btn btn-sm btn-default')
            . '</form>';

        $pagination = View::pagination($total, $page, $this->perPage, function ($n) use ($active) {
            return $this->url(array_merge(['action' => 'requests'], $active, ['p' => $n]));
        });

        return Html::heading('Acquisition requests', $total . ' matching request(s).')
            . $form
            . Html::table(
                ['Reference', 'Domain', 'Client', 'Status', 'Budget', 'Broker', 'Updated'],
                array_map([$this, 'requestRow'], $rows),
                'No requests match those filters.'
            )
            . Html::pager($pagination);
    }

    protected function requestRow(array $row)
    {
        $broker = $row['assigned_broker_id'] ? $this->brokerService()->find($row['assigned_broker_id']) : null;
        return [
            Html::link($row['reference'], $this->url(['action' => 'request', 'id' => (int) $row['id']])),
            Html::e($row['domain_display'] ?: $row['domain'])
                . (!empty($row['manual_review']) ? ' ' . Html::badge('review', 'danger') : ''),
            Html::link('#' . (int) $row['client_id'], 'clientssummary.php?userid=' . (int) $row['client_id']),
            Html::badge(RequestStatus::label($row['status']), View::toneClass(RequestStatus::tone($row['status']))),
            Html::e(Money::format((int) $row['budget_minor'], $row['currency'])),
            $broker ? Html::e($broker['display_name']) : '<em class="text-muted">unassigned</em>',
            Html::e(View::relative($row['updated_at'])),
        ];
    }

    protected function requestPage()
    {
        $request = $this->requestService()->findForActor($this->actor, $this->intInput('id'));
        $id = (int) $request['id'];
        $currency = $request['currency'];

        $broker = $request['assigned_broker_id'] ? $this->brokerService()->find($request['assigned_broker_id']) : null;
        $payment = $this->paymentService()->activePayment($id);
        $transfer = $this->transferService()->forRequest($id);
        $offers = $this->negotiationService()->offersFor($id, 'admin');
        $documents = $this->documentService()->listFor($this->actor, $id);
        $verifications = $this->verificationService()->forRequest($id);
        $flags = $this->riskService()->allFlags($id);
        $assignments = $this->assignmentService()->history($id);
        $dispute = $this->disputeService()->forRequest($id);

        $summary = Html::details([
            'Reference' => Html::e($request['reference']),
            'Domain' => Html::e($request['domain_display'] ?: $request['domain']),
            'Client' => Html::link('#' . (int) $request['client_id'], 'clientssummary.php?userid=' . (int) $request['client_id']),
            'Status' => Html::badge(RequestStatus::label($request['status']), View::toneClass(RequestStatus::tone($request['status']))),
            'Budget' => Html::e(Money::format((int) $request['budget_minor'], $currency))
                . ($request['budget_includes_fees'] ? ' <small class="text-muted">(fees included)</small>' : ''),
            'Agreed amount' => Html::e(Money::format((int) $request['agreed_amount_minor'], $currency)),
            'Broker fee' => Html::e(Money::format((int) $request['broker_fee_minor'], $currency)),
            'Tax' => Html::e(Money::format((int) $request['tax_minor'], $currency)),
            'Total' => '<strong>' . Html::e(Money::format((int) $request['total_minor'], $currency)) . '</strong>',
            'Payment' => Html::badge(PaymentStatus::label($request['payment_status']), 'default'),
            'Transfer' => Html::badge(TransferStatus::label($request['transfer_status']), 'default'),
            'Verification' => Html::e(Str::label($request['verification_status'])),
            'Broker' => $broker ? Html::e($broker['display_name']) : '<em class="text-muted">unassigned</em>',
            'Anonymous' => $request['anonymous'] ? 'Yes' : 'No',
            'Risk' => Html::e($request['risk_level'] . ' (' . (int) $request['risk_score'] . ')')
                . (!empty($request['manual_review']) ? ' ' . Html::badge('manual review', 'danger') : ''),
            'Submitted' => Html::e(View::date($request['submitted_at'])),
            'Source' => Html::e($request['source']),
            'Invoice' => $request['whmcs_invoice_id']
                ? Html::link('#' . (int) $request['whmcs_invoice_id'], 'invoices.php?action=edit&id=' . (int) $request['whmcs_invoice_id'])
                : '<em class="text-muted">none</em>',
        ]);

        $offerRows = [];
        foreach ($offers as $offer) {
            $offerRows[] = [
                Html::e($offer['reference']),
                Html::e('Round ' . (int) $offer['round']),
                Html::e(Str::label($offer['direction'])),
                Html::e(Money::format((int) $offer['amount_minor'], $offer['currency'])),
                Html::badge(\DomainBroker\Workflow\OfferStatus::label($offer['status']), 'default'),
                Html::e($offer['created_by_label']),
                Html::e(View::date($offer['created_at'])),
            ];
        }

        $documentRows = [];
        foreach ($documents as $document) {
            $documentRows[] = [
                Html::e($document['description'] ?: $document['original_name']),
                Html::e(Str::label($document['category'])),
                Html::e(Str::label($document['visibility'])),
                Html::e(round($document['size_bytes'] / 1024) . ' KB'),
                Html::e(View::date($document['created_at'])),
            ];
        }

        $verificationRows = [];
        foreach ($verifications as $item) {
            $actions = '';
            if ($item['status'] !== 'approved' && Rbac::allows($this->actor, Rbac::VERIFICATION_APPROVE)) {
                $actions = Html::inlineForm(
                    $this->url(['action' => 'request', 'id' => $id, 'do' => 'verification']),
                    ['verification_id' => (int) $item['id'], 'decision' => 'approve'],
                    'Approve',
                    'btn btn-xs btn-success'
                ) . ' ' . Html::inlineForm(
                    $this->url(['action' => 'request', 'id' => $id, 'do' => 'verification']),
                    ['verification_id' => (int) $item['id'], 'decision' => 'waive', 'reason' => 'Waived by administrator'],
                    'Waive',
                    'btn btn-xs btn-warning',
                    'Waive this verification requirement?'
                );
            }
            $verificationRows[] = [
                Html::e(Str::label($item['type'])),
                Html::badge(Str::label($item['status']), $item['status'] === 'approved' ? 'success' : 'default'),
                Html::e($item['required'] ? 'Required' : 'Optional'),
                Html::e(View::date($item['checked_at'])),
                $actions,
            ];
        }

        $flagRows = [];
        foreach ($flags as $flag) {
            $flagRows[] = [
                Html::e(Str::label($flag['rule_code'])),
                Html::e((int) $flag['score'] . ' / ' . Str::label($flag['severity'])),
                Html::badge(Str::label($flag['status']), $flag['status'] === 'open' ? 'danger' : 'default'),
                Html::e($flag['description']),
                $flag['status'] === 'open' && Rbac::allows($this->actor, Rbac::RISK_REVIEW)
                    ? Html::inlineForm(
                        $this->url(['action' => 'request', 'id' => $id, 'do' => 'review-risk']),
                        ['flag_id' => (int) $flag['id'], 'outcome' => 'cleared'],
                        'Clear',
                        'btn btn-xs btn-success'
                    ) . ' ' . Html::inlineForm(
                        $this->url(['action' => 'request', 'id' => $id, 'do' => 'review-risk']),
                        ['flag_id' => (int) $flag['id'], 'outcome' => 'confirmed'],
                        'Confirm',
                        'btn btn-xs btn-danger'
                    )
                    : '',
            ];
        }

        $assignmentRows = [];
        foreach ($assignments as $assignment) {
            $assignmentRows[] = [
                Html::e($assignment['display_name']),
                Html::e(Str::label($assignment['assignment_role'])),
                Html::e($assignment['assigned_by_type'] . ' #' . (int) $assignment['assigned_by_id']),
                Html::e(View::date($assignment['assigned_at'])),
                Html::e($assignment['released_at'] ? View::date($assignment['released_at']) : '—'),
                Html::e($assignment['reason']),
            ];
        }

        $timelineRows = [];
        foreach (Audit::forRequest($id, 200) as $entry) {
            $timelineRows[] = [
                Html::e(View::date($entry['created_at'])),
                Html::e($entry['actor_label'] . ' (' . $entry['actor_type'] . ')'),
                Html::e(Presenter::describe($entry['action'])),
                Html::e(Str::label($entry['visibility'])),
                Html::e($entry['reason']),
            ];
        }

        $chain = Audit::verifyChain($id);

        return Html::heading(
            $request['domain_display'] ?: $request['domain'],
            $request['reference'] . ' · ' . RequestStatus::label($request['status']),
            Html::link('Back to requests', $this->url(['action' => 'requests']), 'btn btn-default btn-sm')
        )
            . '<div class="row"><div class="col-md-7">'
            . Html::panel('Request', $summary)
            . Html::panel('Negotiation history', Html::table(
                ['Offer', 'Round', 'Direction', 'Amount', 'Status', 'By', 'Created'],
                $offerRows,
                'No offers have been recorded.'
            ))
            . Html::panel('Verification', Html::table(
                ['Requirement', 'Status', 'Necessity', 'Verified', ''],
                $verificationRows,
                'No verification requirements have been created yet.'
            ))
            . Html::panel('Documents', Html::table(
                ['Title', 'Type', 'Visibility', 'Size', 'Uploaded'],
                $documentRows,
                'No documents.'
            ))
            . Html::panel('Risk flags', Html::table(
                ['Type', 'Score', 'Status', 'Detail', ''],
                $flagRows,
                'No risk flags were raised.'
            ))
            . Html::panel('Assignment history', Html::table(
                ['Broker', 'Role', 'By', 'Assigned', 'Released', 'Reason'],
                $assignmentRows,
                'This request has never been assigned.'
            ))
            . Html::panel(
                'Audit trail',
                ($chain['valid']
                    ? Html::alert('Hash chain verified — no record has been altered.', 'success')
                    : Html::alert('Hash chain broken at entry #' . (int) $chain['broken_at'] . '.', 'danger'))
                . Html::table(['When', 'Actor', 'Action', 'Visibility', 'Reason'], $timelineRows, 'No audit entries.')
            )
            . '</div><div class="col-md-5">'
            . $this->requestActions($request, $payment, $transfer, $dispute)
            . '</div></div>';
    }

    /** The administrative action panel for a single request. */
    protected function requestActions(array $request, $payment, $transfer, $dispute)
    {
        $id = (int) $request['id'];
        $out = '';

        if (Rbac::allows($this->actor, Rbac::BROKER_ASSIGN)) {
            $options = ['' => 'Choose a broker…'];
            foreach ($this->brokerService()->availableBrokers() as $broker) {
                $options[(int) $broker['id']] = $broker['display_name']
                    . ' (' . $this->brokerService()->openRequestCount($broker['id']) . ' open)';
            }
            $out .= Html::panel('Assign a broker',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'assign']))
                . Html::field('Broker', Html::select('broker_id', $options, (string) $request['assigned_broker_id']))
                . Html::field('Reason', Html::input('reason', '', ['placeholder' => 'Optional note for the audit log']))
                . Html::field('', Html::submit('Assign'))
                . Html::formClose()
            );
        }

        if (Rbac::allows($this->actor, Rbac::REQUEST_APPROVE)
            && in_array($request['status'], [RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW], true)) {
            $out .= Html::panel('Review',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'approve']))
                . Html::field('Note', Html::input('note', ''))
                . Html::field('', Html::submit('Approve', 'btn btn-success'))
                . Html::formClose()
                . '<hr>'
                . Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'reject']))
                . Html::field('Reason', Html::input('reason', '', ['required' => true]))
                . Html::field('', Html::submit('Reject', 'btn btn-danger'))
                . Html::formClose()
            );
        }

        if (Rbac::allows($this->actor, Rbac::REQUEST_CANCEL_ANY) && !RequestStatus::isTerminal($request['status'])) {
            $out .= Html::panel('Cancel',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'cancel']))
                . Html::field('Reason', Html::input('reason', '', ['required' => true]))
                . Html::field('', Html::submit('Cancel request', 'btn btn-warning'))
                . Html::formClose()
            );
        }

        if (Rbac::allows($this->actor, Rbac::STATUS_OVERRIDE)) {
            $out .= Html::panel('Override status',
                Html::alert('Overrides bypass the workflow. They are recorded against your account.', 'warning')
                . Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'override']))
                . Html::field('New status', Html::select('status', View::statusOptions(), $request['status']))
                . Html::field('Reason', Html::input('reason', '', ['required' => true]))
                . Html::field('', Html::submit('Override', 'btn btn-danger'))
                . Html::formClose(),
                'danger'
            );
        }

        if ($payment) {
            $out .= $this->paymentPanel($payment, $request);
        }

        if ($transfer) {
            $out .= Html::panel('Transfer', Html::details([
                'Reference' => Html::e($transfer['reference']),
                'Status' => Html::badge(TransferStatus::label($transfer['status']), 'default'),
                'Losing registrar' => Html::e($transfer['losing_registrar'] ?: '—'),
                'Gaining registrar' => Html::e($transfer['gaining_registrar'] ?: '—'),
                'Auth code' => Html::e($transfer['auth_code_hint'] ?: 'not recorded'),
                'Initiated' => Html::e(View::date($transfer['initiated_at'])),
                'Completed' => Html::e(View::date($transfer['completed_at'])),
            ]));
        }

        if ($dispute) {
            $out .= Html::panel('Dispute',
                Html::details([
                    'Reference' => Html::e($dispute['reference']),
                    'Status' => Html::badge(Str::label($dispute['status']), 'warning'),
                    'Reason' => Html::e(Str::label($dispute['reason_code'])),
                    'Opened' => Html::e(View::date($dispute['created_at'])),
                ])
                . Html::link('Manage dispute', $this->url(['action' => 'dispute', 'id' => (int) $dispute['id']]), 'btn btn-sm btn-primary'),
                'warning'
            );
        }

        $out .= Html::panel('Internal note',
            Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'note']))
            . Html::field('Note', Html::textarea('body', '', ['required' => true]))
            . Html::field('', Html::submit('Add internal note'))
            . Html::formClose()
            . '<p class="help-block">Internal notes are never shown to the customer.</p>'
        );

        return $out;
    }

    protected function paymentPanel(array $payment, array $request)
    {
        $id = (int) $payment['id'];
        $body = Html::details([
            'Reference' => Html::e($payment['reference']),
            'Status' => Html::badge(PaymentStatus::label($payment['status']), 'default'),
            'Acquisition' => Html::e(Money::format((int) $payment['acquisition_minor'], $payment['currency'])),
            'Fee' => Html::e(Money::format((int) $payment['fee_minor'], $payment['currency'])),
            'Tax' => Html::e(Money::format((int) $payment['tax_minor'], $payment['currency'])),
            'Total' => '<strong>' . Html::e(Money::format((int) $payment['total_minor'], $payment['currency'])) . '</strong>',
            'Refunded' => Html::e(Money::format((int) $payment['refunded_minor'], $payment['currency'])),
            'Invoice' => $payment['whmcs_invoice_id']
                ? Html::link('#' . (int) $payment['whmcs_invoice_id'], 'invoices.php?action=edit&id=' . (int) $payment['whmcs_invoice_id'])
                : '—',
            'Due' => Html::e(View::date($payment['due_at'])),
        ]);

        $actions = '';
        if (Rbac::allows($this->actor, Rbac::PAYMENT_VIEW)) {
            $actions .= Html::inlineForm(
                $this->url(['action' => 'request', 'id' => (int) $request['id'], 'do' => 'sync-payment']),
                ['payment_id' => $id],
                'Sync with billing',
                'btn btn-xs btn-default'
            ) . ' ';
        }
        if (Rbac::allows($this->actor, Rbac::PAYMENT_RELEASE)) {
            $actions .= Html::inlineForm(
                $this->url(['action' => 'request', 'id' => (int) $request['id'], 'do' => 'release']),
                ['payment_id' => $id],
                'Release funds',
                'btn btn-xs btn-success',
                'Release the escrowed funds to the seller?'
            ) . ' ';
        }

        $refund = '';
        if (Rbac::allows($this->actor, Rbac::PAYMENT_REFUND)) {
            $refund = '<hr>' . Html::formOpen($this->url(['action' => 'request', 'id' => (int) $request['id'], 'do' => 'refund']))
                . Html::hidden('payment_id', $id)
                . Html::field('Amount', Html::input('amount', Money::toDecimalString(
                    (int) $payment['total_minor'] - (int) $payment['refunded_minor'],
                    $payment['currency']
                )), 'Leave as-is for a full refund of the remaining balance.')
                . Html::field('Reason', Html::input('reason', '', ['required' => true]))
                . Html::field('', Html::submit('Issue refund', 'btn btn-danger'))
                . Html::formClose();
        }

        return Html::panel('Payment', $body . $actions . $refund);
    }

    protected function brokersPage()
    {
        Rbac::assert($this->actor, Rbac::REQUEST_VIEW_ALL);
        $brokers = $this->brokerService()->listAll($this->actor, ['include_inactive' => 1]);

        $rows = [];
        foreach ($brokers as $broker) {
            $open = $this->brokerService()->openRequestCount($broker['id']);
            $actions = '';
            if (Rbac::allows($this->actor, Rbac::BROKER_MANAGE) && $broker['status'] === 'active') {
                $actions = Html::inlineForm(
                    $this->url(['action' => 'brokers', 'do' => 'deactivate']),
                    ['broker_id' => (int) $broker['id'], 'reason' => 'Deactivated from the admin console'],
                    'Deactivate',
                    'btn btn-xs btn-warning',
                    'Deactivate this broker?'
                );
            }
            $rows[] = [
                Html::e($broker['display_name']),
                Html::e(Str::label($broker['role'])),
                Html::e($broker['whmcs_admin_id'] ? ('admin #' . (int) $broker['whmcs_admin_id']) : ('client #' . (int) $broker['whmcs_client_id'])),
                Html::badge(Str::label($broker['status']), $broker['status'] === 'active' ? 'success' : 'default'),
                Html::e($open . ' / ' . ((int) $broker['max_active_requests'] ?: '∞')),
                Html::e((int) $broker['completed_count']),
                $actions,
            ];
        }

        $create = '';
        if (Rbac::allows($this->actor, Rbac::BROKER_MANAGE)) {
            $create = Html::panel('Add a broker',
                Html::formOpen($this->url(['action' => 'brokers', 'do' => 'create']))
                . Html::field('Display name', Html::input('display_name', '', ['required' => true]))
                . Html::field('WHMCS admin ID', Html::input('whmcs_admin_id', '', ['type' => 'number']), 'Link the broker to a staff account, or use a client ID below for an external broker.')
                . Html::field('WHMCS client ID', Html::input('whmcs_client_id', '', ['type' => 'number']))
                . Html::field('Role', Html::select('role', ['broker' => 'Broker', 'broker_lead' => 'Broker lead'], 'broker'))
                . Html::field('Email', Html::input('email', '', ['type' => 'email']))
                . Html::field('Max active requests', Html::input('max_active_requests', '0', ['type' => 'number']), '0 means no limit.')
                . Html::field('', Html::submit('Create broker'))
                . Html::formClose()
            );
        }

        return Html::heading('Brokers', 'Who may be assigned acquisition work.')
            . Html::table(['Broker', 'Role', 'Linked to', 'Status', 'Open / max', 'Completed', ''], $rows, 'No brokers configured yet.')
            . $create;
    }

    protected function paymentsPage()
    {
        Rbac::assert($this->actor, Rbac::PAYMENT_VIEW);

        $status = (string) $this->input('status', '');
        $where = ['type' => 'acquisition'];
        if ($status !== '') {
            $where['status'] = $status;
        }
        $payments = Db::fetch('payments', $where, ['order' => 'id', 'dir' => 'desc', 'limit' => 100]);

        $rows = [];
        foreach ($payments as $payment) {
            $request = $this->requestService()->findRow($payment['request_id']);
            $rows[] = [
                Html::e($payment['reference']),
                $request ? Html::link($request['reference'], $this->url(['action' => 'request', 'id' => (int) $request['id']])) : '—',
                $request ? Html::e($request['domain']) : '—',
                Html::badge(PaymentStatus::label($payment['status']), 'default'),
                Html::e(Money::format((int) $payment['total_minor'], $payment['currency'])),
                Html::e(Money::format((int) $payment['refunded_minor'], $payment['currency'])),
                $payment['whmcs_invoice_id']
                    ? Html::link('#' . (int) $payment['whmcs_invoice_id'], 'invoices.php?action=edit&id=' . (int) $payment['whmcs_invoice_id'])
                    : '—',
                Html::e(View::date($payment['created_at'])),
            ];
        }

        $form = '<form method="get" action="' . Html::e(View::adminLink()) . '" class="form-inline db-filters">'
            . Html::hidden('module', 'domainbroker') . Html::hidden('action', 'payments')
            . Html::select('status', ['' => 'Any status'] + View::paymentStatusOptions(), $status, ['class' => 'form-control input-sm'])
            . ' ' . Html::submit('Filter', 'btn btn-sm btn-primary')
            . '</form>';

        return Html::heading('Payments', 'Acquisition payments and their escrow state.')
            . $form
            . Html::table(
                ['Payment', 'Request', 'Domain', 'Status', 'Total', 'Refunded', 'Invoice', 'Created'],
                $rows,
                'No payments yet.'
            );
    }

    protected function disputesPage()
    {
        Rbac::assert($this->actor, Rbac::REQUEST_VIEW_ALL);
        $disputes = $this->disputeService()->listForActor($this->actor, [
            'status' => (string) $this->input('status', ''),
            'limit' => 100,
        ]);

        $rows = [];
        foreach ($disputes as $dispute) {
            $request = $this->requestService()->findRow($dispute['request_id']);
            $rows[] = [
                Html::link($dispute['reference'], $this->url(['action' => 'dispute', 'id' => (int) $dispute['id']])),
                $request ? Html::link($request['reference'], $this->url(['action' => 'request', 'id' => (int) $request['id']])) : '—',
                Html::e(Str::label($dispute['reason_code'])),
                Html::badge(Str::label($dispute['status']), in_array($dispute['status'], DisputeService::OPEN_STATUSES, true) ? 'warning' : 'default'),
                Html::e(Str::label($dispute['severity'])),
                Html::e(Money::format((int) $dispute['amount_in_dispute_minor'], $dispute['currency'])),
                Html::e(View::date($dispute['created_at'])),
            ];
        }

        return Html::heading('Disputes', 'Customer disputes awaiting resolution.')
            . Html::table(['Dispute', 'Request', 'Reason', 'Status', 'Severity', 'Amount', 'Opened'], $rows, 'No disputes.');
    }

    protected function disputePage()
    {
        Rbac::assert($this->actor, Rbac::REQUEST_VIEW_ALL);
        $dispute = $this->disputeService()->findOrFail($this->intInput('id'));
        $request = $this->requestService()->findRow($dispute['request_id']);

        $detail = Html::details([
            'Reference' => Html::e($dispute['reference']),
            'Request' => $request ? Html::link($request['reference'], $this->url(['action' => 'request', 'id' => (int) $request['id']])) : '—',
            'Reason' => Html::e(Str::label($dispute['reason_code'])),
            'Severity' => Html::e(Str::label($dispute['severity'])),
            'Status' => Html::badge(Str::label($dispute['status']), 'warning'),
            'Amount' => Html::e(Money::format((int) $dispute['amount_in_dispute_minor'], $dispute['currency'])),
            'Opened' => Html::e(View::date($dispute['created_at'])),
            'Due' => Html::e(View::date($dispute['due_at'])),
            'Description' => nl2br(Html::e($dispute['description'])),
        ]);

        $manage = '';
        if (Rbac::allows($this->actor, Rbac::DISPUTE_RESOLVE)) {
            $statusOptions = [];
            foreach (DisputeService::OPEN_STATUSES as $status) {
                $statusOptions[$status] = Str::label($status);
            }
            $manage = Html::panel('Progress',
                Html::formOpen($this->url(['action' => 'dispute', 'id' => (int) $dispute['id'], 'do' => 'status']))
                . Html::field('Status', Html::select('status', $statusOptions, $dispute['status']))
                . Html::field('Note', Html::input('note', ''))
                . Html::field('', Html::submit('Update status'))
                . Html::formClose()
            ) . Html::panel('Resolve',
                Html::formOpen($this->url(['action' => 'dispute', 'id' => (int) $dispute['id'], 'do' => 'resolve']))
                . Html::field('Resolution', Html::select('resolution', DisputeService::RESOLUTIONS, 'transfer_completed'))
                . Html::field('Refund amount', Html::input('refund_amount', ''), 'Only used by the refund resolutions. Leave blank for the full amount.')
                . Html::field('Summary', Html::textarea('summary', '', ['required' => true]))
                . Html::field('', Html::submit('Resolve dispute', 'btn btn-success'))
                . Html::formClose()
            ) . Html::panel('Reject',
                Html::formOpen($this->url(['action' => 'dispute', 'id' => (int) $dispute['id'], 'do' => 'reject']))
                . Html::field('Reason', Html::textarea('reason', '', ['required' => true]))
                . Html::field('', Html::submit('Reject dispute', 'btn btn-warning'))
                . Html::formClose()
            );
        }

        return Html::heading('Dispute ' . $dispute['reference'], '', Html::link('All disputes', $this->url(['action' => 'disputes']), 'btn btn-default btn-sm'))
            . '<div class="row"><div class="col-md-7">' . Html::panel('Dispute', $detail) . '</div>'
            . '<div class="col-md-5">' . $manage . '</div></div>';
    }

    protected function riskPage()
    {
        Rbac::assert($this->actor, Rbac::RISK_REVIEW);
        $flags = Db::fetch('risk', ['status' => 'open'], ['order' => 'score', 'dir' => 'desc', 'limit' => 200]);

        $rows = [];
        foreach ($flags as $flag) {
            $request = $this->requestService()->findRow($flag['request_id']);
            $rows[] = [
                $request ? Html::link($request['reference'], $this->url(['action' => 'request', 'id' => (int) $request['id']])) : '—',
                $request ? Html::e($request['domain']) : '—',
                Html::e(Str::label($flag['rule_code'])),
                Html::e((int) $flag['score']),
                Html::e($flag['description']),
                Html::e(View::date($flag['created_at'])),
                Html::inlineForm(
                    $this->url(['action' => 'risk', 'do' => 'review']),
                    ['flag_id' => (int) $flag['id'], 'outcome' => 'cleared'],
                    'Clear',
                    'btn btn-xs btn-success'
                ) . ' ' . Html::inlineForm(
                    $this->url(['action' => 'risk', 'do' => 'review']),
                    ['flag_id' => (int) $flag['id'], 'outcome' => 'confirmed'],
                    'Confirm',
                    'btn btn-xs btn-danger'
                ),
            ];
        }

        $stats = $this->riskService()->statistics();
        return Html::heading('Risk review', 'High-risk acquisitions held for a human decision.')
            . '<div class="row db-stats">'
            . '<div class="col-sm-3">' . Html::stat('Open flags', isset($stats['open']) ? $stats['open'] : 0, 'danger') . '</div>'
            . '<div class="col-sm-3">' . Html::stat('Cleared', isset($stats['cleared']) ? $stats['cleared'] : 0, 'success') . '</div>'
            . '<div class="col-sm-3">' . Html::stat('Confirmed', isset($stats['confirmed']) ? $stats['confirmed'] : 0, 'warning') . '</div>'
            . '</div>'
            . Html::table(['Request', 'Domain', 'Flag', 'Score', 'Detail', 'Raised', ''], $rows, 'No open risk flags.');
    }

    protected function feesPage()
    {
        Rbac::assert($this->actor, Rbac::FEE_MANAGE);
        $rules = $this->feeService()->listRules($this->actor, true);

        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = [
                Html::e($rule['code']),
                Html::e($rule['name']),
                Html::e(Str::label($rule['calculation'])),
                Html::e($this->feeSummary($rule)),
                Html::e($rule['currency'] ?: 'any'),
                Html::e($rule['tld_scope'] ?: 'any'),
                Html::e((int) $rule['priority']),
                Html::badge($rule['active'] ? 'active' : 'inactive', $rule['active'] ? 'success' : 'default'),
                Html::inlineForm(
                    $this->url(['action' => 'fees', 'do' => 'toggle']),
                    ['rule_id' => (int) $rule['id'], 'active' => $rule['active'] ? 0 : 1],
                    $rule['active'] ? 'Disable' : 'Enable',
                    'btn btn-xs btn-default'
                ) . ' ' . Html::inlineForm(
                    $this->url(['action' => 'fees', 'do' => 'delete']),
                    ['rule_id' => (int) $rule['id'], 'reason' => 'Removed from the admin console'],
                    'Delete',
                    'btn btn-xs btn-danger',
                    'Soft-delete this fee rule? Historical quotes are preserved.'
                ),
            ];
        }

        $currency = Settings::string('default_currency', 'USD');
        $quoteAmount = (string) $this->input('quote_amount', '');
        $quote = '';
        if ($quoteAmount !== '') {
            try {
                $result = $this->feeService()->quote(
                    Money::toMinor($quoteAmount, $currency),
                    $currency,
                    (string) $this->input('quote_domain', ''),
                    (string) $this->input('quote_promo', '')
                );
                $quote = Html::details([
                    'Acquisition' => Html::e(Money::format($result['acquisition_minor'], $currency)),
                    'Broker fee' => Html::e(Money::format($result['fee_minor'], $currency)),
                    'Discount' => Html::e(Money::format($result['discount_minor'], $currency)),
                    'Tax' => Html::e(Money::format($result['tax_minor'], $currency)),
                    'Total' => '<strong>' . Html::e(Money::format($result['total_minor'], $currency)) . '</strong>',
                    'Rule' => Html::e($result['rule_name'] ?: 'none matched'),
                ]);
            } catch (\Throwable $e) {
                $quote = Html::alert($e->getMessage(), 'danger');
            }
        }

        return Html::heading('Broker fees', 'Fee rules are configuration — nothing is hard-coded.')
            . Html::table(
                ['Code', 'Name', 'Calculation', 'Rate', 'Currency', 'TLDs', 'Priority', 'Status', ''],
                $rows,
                'No fee rules configured.'
            )
            . '<div class="row"><div class="col-md-7">'
            . Html::panel('Add a fee rule',
                Html::formOpen($this->url(['action' => 'fees', 'do' => 'create']))
                . Html::field('Code', Html::input('code', '', ['required' => true]))
                . Html::field('Name', Html::input('name', '', ['required' => true]))
                . Html::field('Calculation', Html::select('calculation', [
                    'percentage' => 'Percentage of the acquisition price',
                    'fixed' => 'Fixed amount',
                    'tiered' => 'Tiered',
                ], 'percentage'))
                . Html::field('Percentage', Html::input('percentage', '', ['type' => 'number', 'step' => '0.01']))
                . Html::field('Fixed amount', Html::input('fixed_minor', '', ['type' => 'number']), 'In minor units (cents).')
                . Html::field('Minimum fee', Html::input('min_fee_minor', '', ['type' => 'number']))
                . Html::field('Maximum fee', Html::input('max_fee_minor', '', ['type' => 'number']))
                . Html::field('Currency', Html::input('currency', '', ['maxlength' => 3, 'placeholder' => 'blank = any']))
                . Html::field('TLD scope', Html::input('tld_scope', '', ['placeholder' => 'com,net — blank for any']))
                . Html::field('Promo code', Html::input('promo_code', ''))
                . Html::field('Priority', Html::input('priority', '100', ['type' => 'number']))
                . Html::field('Tiers (JSON)', Html::textarea('tiers', ''), 'Only for tiered rules.')
                . Html::field('', Html::submit('Create rule'))
                . Html::formClose()
            )
            . '</div><div class="col-md-5">'
            . Html::panel('Fee calculator',
                '<form method="get" action="' . Html::e(View::adminLink()) . '" class="form-horizontal">'
                . Html::hidden('module', 'domainbroker') . Html::hidden('action', 'fees')
                . Html::field('Acquisition price', Html::input('quote_amount', $quoteAmount))
                . Html::field('Domain', Html::input('quote_domain', (string) $this->input('quote_domain', '')))
                . Html::field('Promo code', Html::input('quote_promo', (string) $this->input('quote_promo', '')))
                . Html::field('', Html::submit('Calculate'))
                . '</form>' . $quote
            )
            . '</div></div>';
    }

    protected function feeSummary(array $rule)
    {
        switch ($rule['calculation']) {
            case 'percentage':
                return rtrim(rtrim((string) $rule['percentage'], '0'), '.') . '%';
            case 'fixed':
                return Money::format((int) $rule['fixed_minor'], $rule['currency'] ?: Settings::string('default_currency', 'USD'));
            case 'tiered':
                return 'tiered';
        }
        return '—';
    }

    protected function reportsPage()
    {
        Rbac::assert($this->actor, Rbac::REPORT_VIEW);
        $filters = [
            'from' => (string) $this->input('from', ''),
            'to' => (string) $this->input('to', ''),
        ];
        $overview = $this->reportService()->overview($this->actor, array_filter($filters, 'strlen'));
        $performance = $this->reportService()->brokerPerformance($this->actor, array_filter($filters, 'strlen'));

        $valueRows = [];
        foreach ($overview['value'] as $currency => $value) {
            $valueRows[] = [
                Html::e($currency),
                Html::e((int) $value['deals']),
                Html::e(Money::format((int) $value['total'], $currency)),
                Html::e(Money::format((int) $value['average'], $currency)),
            ];
        }

        $revenueRows = [];
        foreach ($overview['revenue'] as $currency => $value) {
            $revenueRows[] = [
                Html::e($currency),
                Html::e(Money::format((int) $value['fees'], $currency)),
                Html::e(Money::format((int) $value['tax'], $currency)),
                Html::e(Money::format((int) $value['total'], $currency)),
            ];
        }

        $brokerRows = [];
        foreach ($performance['brokers'] as $broker) {
            $revenue = [];
            foreach ($broker['revenue'] as $code => $value) {
                $revenue[] = Money::format($value['fees_minor'], $code);
            }
            $brokerRows[] = [
                Html::e($broker['name']),
                Html::e((int) $broker['assigned']),
                Html::e((int) $broker['completed']),
                Html::e((int) $broker['failed']),
                Html::e($broker['success_rate'] . '%'),
                Html::e($revenue ? implode(', ', $revenue) : '—'),
                Html::e($broker['average_close_days']),
            ];
        }

        $statusRows = [];
        foreach ($overview['status_breakdown'] as $status => $count) {
            $statusRows[] = [
                Html::badge(RequestStatus::label($status), View::toneClass(RequestStatus::tone($status))),
                Html::e((int) $count),
            ];
        }

        $tldRows = [];
        foreach ($overview['top_tlds'] as $tld => $count) {
            $tldRows[] = [Html::e('.' . ltrim((string) $tld, '.')), Html::e((int) $count)];
        }

        $exports = '';
        if (Rbac::allows($this->actor, Rbac::REPORT_EXPORT)) {
            $links = [];
            foreach (['requests', 'payments', 'offers', 'brokers', 'disputes'] as $dataset) {
                $links[] = Html::inlineForm(
                    $this->url(['action' => 'reports', 'do' => 'export']),
                    array_merge(['dataset' => $dataset], array_filter($filters, 'strlen')),
                    ucfirst($dataset) . ' CSV',
                    'btn btn-sm btn-default'
                );
            }
            $exports = Html::panel('Export', implode(' ', $links));
        }

        $form = '<form method="get" action="' . Html::e(View::adminLink()) . '" class="form-inline db-filters">'
            . Html::hidden('module', 'domainbroker') . Html::hidden('action', 'reports')
            . ' From ' . Html::input('from', $filters['from'], ['type' => 'date', 'class' => 'form-control input-sm'])
            . ' To ' . Html::input('to', $filters['to'], ['type' => 'date', 'class' => 'form-control input-sm'])
            . ' ' . Html::submit('Apply', 'btn btn-sm btn-primary')
            . '</form>';

        return Html::heading('Reports', 'Range: ' . $overview['range']['from'] . ' → ' . $overview['range']['to'])
            . $form
            . '<div class="row db-stats">'
            . '<div class="col-sm-3">' . Html::stat('Requests', $overview['requests']['total'], 'primary') . '</div>'
            . '<div class="col-sm-3">' . Html::stat('Completed', $overview['requests']['completed'], 'success') . '</div>'
            . '<div class="col-sm-3">' . Html::stat('Failed', $overview['requests']['failed'], 'danger') . '</div>'
            . '<div class="col-sm-3">' . Html::stat('Conversion', $overview['conversion_rate'] . '%', 'info') . '</div>'
            . '</div>'
            . '<div class="row db-stats">'
            . '<div class="col-sm-3">' . Html::stat('Avg negotiation (days)', $overview['durations']['negotiation_days'], 'default') . '</div>'
            . '<div class="col-sm-3">' . Html::stat('Avg transfer (days)', $overview['durations']['transfer_days'], 'default') . '</div>'
            . '<div class="col-sm-3">' . Html::stat('Disputes', isset($overview['disputes']['open']) ? $overview['disputes']['open'] : 0, 'warning') . '</div>'
            . '<div class="col-sm-3">' . Html::stat('Pending payments', $overview['pending']['payments'], 'warning') . '</div>'
            . '</div>'
            . '<div class="row"><div class="col-md-6">'
            . Html::panel('Acquisition value', Html::table(['Currency', 'Deals', 'Total', 'Average'], $valueRows, 'No completed deals in range.'))
            . Html::panel('Status breakdown', Html::table(['Status', 'Requests'], $statusRows, 'Nothing in range.'))
            . '</div><div class="col-md-6">'
            . Html::panel('Brokerage revenue', Html::table(['Currency', 'Fees', 'Tax', 'Total'], $revenueRows, 'No revenue in range.'))
            . Html::panel('Top extensions', Html::table(['TLD', 'Requests'], $tldRows, 'Nothing in range.'))
            . '</div></div>'
            . Html::panel('Broker performance', Html::table(
                ['Broker', 'Assigned', 'Completed', 'Failed', 'Success rate', 'Fee revenue', 'Avg close (days)'],
                $brokerRows,
                'No broker activity in range.'
            ))
            . $exports;
    }

    protected function auditPage()
    {
        Rbac::assert($this->actor, Rbac::AUDIT_VIEW);

        $page = max(1, $this->intInput('p', 1));
        $where = [];
        if ($this->input('request_id')) {
            $where['request_id'] = $this->intInput('request_id');
        }
        if ($this->input('actor_type')) {
            $where['actor_type'] = (string) $this->input('actor_type');
        }
        $total = Db::count('activity', $where);
        $entries = Db::fetch($where ? 'activity' : 'activity', $where, [
            'order' => 'id', 'dir' => 'desc',
            'limit' => $this->perPage, 'offset' => ($page - 1) * $this->perPage,
        ]);

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                Html::e(View::date($entry['created_at'])),
                Html::e($entry['actor_label'] . ' (' . $entry['actor_type'] . ')'),
                Html::e(Presenter::describe($entry['action'])),
                $entry['request_id']
                    ? Html::link('#' . (int) $entry['request_id'], $this->url(['action' => 'request', 'id' => (int) $entry['request_id']]))
                    : '—',
                Html::e(Str::label($entry['visibility'])),
                Html::e($entry['ip_address']),
                Html::e(Str::clip((string) $entry['reason'], 120)),
            ];
        }

        $filters = array_filter([
            'request_id' => (string) $this->input('request_id', ''),
            'actor_type' => (string) $this->input('actor_type', ''),
        ], 'strlen');

        $pagination = View::pagination($total, $page, $this->perPage, function ($n) use ($filters) {
            return $this->url(array_merge(['action' => 'audit'], $filters, ['p' => $n]));
        });

        $form = '<form method="get" action="' . Html::e(View::adminLink()) . '" class="form-inline db-filters">'
            . Html::hidden('module', 'domainbroker') . Html::hidden('action', 'audit')
            . Html::input('request_id', (string) $this->input('request_id', ''), ['placeholder' => 'Request ID', 'class' => 'form-control input-sm'])
            . ' ' . Html::select('actor_type', [
                '' => 'Any actor', 'customer' => 'Customer', 'broker' => 'Broker',
                'admin' => 'Administrator', 'system' => 'System',
            ], (string) $this->input('actor_type', ''), ['class' => 'form-control input-sm'])
            . ' ' . Html::submit('Filter', 'btn btn-sm btn-primary')
            . '</form>';

        return Html::heading('Audit log', $total . ' recorded action(s). Entries are append-only and hash chained.')
            . $form
            . Html::table(['When', 'Actor', 'Action', 'Request', 'Visibility', 'IP', 'Reason'], $rows, 'No audit entries.')
            . Html::pager($pagination);
    }

    protected function settingsPage()
    {
        Rbac::assert($this->actor, Rbac::SETTINGS_MANAGE);

        $groups = [
            'Service' => ['service_enabled', 'public_landing_enabled', 'service_name', 'support_email', 'portal_base_url'],
            'Commercial' => ['default_currency', 'allowed_currencies', 'min_budget_minor', 'max_budget_minor', 'tax_enabled', 'fee_uses_whmcs_tax'],
            'Workflow' => ['offer_validity_hours', 'payment_window_hours', 'transfer_window_days', 'request_expiry_days', 'max_negotiation_rounds', 'auto_assign_brokers', 'anonymous_default'],
            'Risk & compliance' => ['require_kyc_above_minor', 'high_value_review_minor', 'risk_block_score', 'risk_review_score', 'max_failed_payments', 'rapid_offer_seconds', 'rapid_offer_threshold'],
            'Escrow & payments' => ['escrow_provider', 'escrow_endpoint', 'escrow_release_requires_transfer', 'payment_due_days', 'send_whmcs_invoice_email', 'cancel_expired_invoices'],
            'Documents' => ['document_max_bytes', 'document_storage_path', 'virus_scan_enabled'],
            'Notifications' => ['notifications_email', 'notifications_inapp', 'create_support_ticket', 'support_department_id'],
            'Domain intelligence' => ['rdap_enabled', 'rdap_endpoint', 'rdap_timeout', 'rdap_cache_minutes', 'rdap_include_contacts', 'brokerable_tlds'],
            'API & logging' => ['api_rate_limit_per_minute', 'api_write_rate_limit_per_minute', 'api_admin_user', 'audit_retention_days', 'debug_logging'],
            'Presentation' => ['display_date_format', 'display_datetime_format', 'landing_headline', 'landing_subheadline'],
            'Administrative roles' => ['role_admins_admin_super', 'role_admins_admin_finance', 'role_admins_admin_manager', 'role_admins_admin_viewer', 'default_admin_role'],
        ];

        $body = Html::formOpen($this->url(['action' => 'settings', 'do' => 'save']));
        foreach ($groups as $group => $keys) {
            $fields = '';
            foreach ($keys as $key) {
                $fields .= Html::field(
                    Str::label($key),
                    Html::input('setting_' . $key, (string) Settings::get($key, '')),
                    $this->settingHelp($key)
                );
            }
            $body .= Html::panel($group, $fields);
        }
        $body .= Html::submit('Save settings') . Html::formClose();

        return Html::heading('Settings', 'Secrets are read from the environment only and never appear here.')
            . Html::alert(
                'Encryption keys, the escrow API key and the escrow webhook secret are read from DOMAINBROKER_* '
                . 'environment variables. They cannot be set here and are never written to the database.',
                'info'
            )
            . $body;
    }

    protected function settingHelp($key)
    {
        $help = [
            'allowed_currencies' => 'Comma separated ISO codes. Blank means every active WHMCS currency.',
            'min_budget_minor' => 'In minor units — 10000 is 100.00 in a two-decimal currency.',
            'max_budget_minor' => '0 means no ceiling.',
            'require_kyc_above_minor' => 'Acquisitions above this value require identity verification.',
            'escrow_provider' => 'internal, manual, or the code of an installed provider.',
            'brokerable_tlds' => 'Comma separated. Blank means every extension.',
            'role_admins_admin_super' => 'Comma separated WHMCS admin IDs granted this Domain Broker role.',
            'role_admins_admin_finance' => 'Comma separated WHMCS admin IDs.',
            'role_admins_admin_manager' => 'Comma separated WHMCS admin IDs.',
            'role_admins_admin_viewer' => 'Comma separated WHMCS admin IDs.',
            'default_admin_role' => 'Role for staff not listed above. Defaults to admin_viewer.',
            'audit_retention_days' => '0 keeps audit history forever.',
        ];
        return isset($help[$key]) ? $help[$key] : '';
    }

    /* -------------------------------------------------------------- writes */

    protected function handleWrite($action)
    {
        try {
            $this->guardCsrf();
        } catch (\Throwable $e) {
            $this->error('Security token mismatch. Please try again.');
            return $this->chrome($action, '');
        }

        $do = (string) $this->input('do', '');
        $id = $this->intInput('id');

        switch ($action . ':' . $do) {
            case 'request:assign':
                $this->attempt(function () use ($id) {
                    $this->assignmentService()->assign($this->actor, $id, $this->intInput('broker_id'), [
                        'reason' => (string) $this->input('reason', ''),
                    ]);
                }, 'Broker assigned.');
                break;

            case 'request:approve':
                $this->attempt(function () use ($id) {
                    $this->requestService()->approve($this->actor, $id, (string) $this->input('note', ''));
                }, 'Request approved.');
                break;

            case 'request:reject':
                $this->attempt(function () use ($id) {
                    $this->requestService()->reject($this->actor, $id, (string) $this->input('reason', ''));
                }, 'Request rejected.');
                break;

            case 'request:cancel':
                $this->attempt(function () use ($id) {
                    $this->requestService()->cancelByAdmin($this->actor, $id, (string) $this->input('reason', ''));
                }, 'Request cancelled.');
                break;

            case 'request:override':
                $this->attempt(function () use ($id) {
                    $this->requestService()->overrideStatus(
                        $this->actor,
                        $id,
                        (string) $this->input('status', ''),
                        (string) $this->input('reason', '')
                    );
                }, 'Status overridden.');
                break;

            case 'request:note':
                $this->attempt(function () use ($id) {
                    $this->messageService()->addInternalNote($this->actor, $id, (string) $this->input('body', ''));
                }, 'Internal note added.');
                break;

            case 'request:sync-payment':
                $this->attempt(function () {
                    $this->paymentService()->syncWithBilling($this->actor, $this->intInput('payment_id'));
                }, 'Payment synchronised with billing.');
                break;

            case 'request:release':
                $this->attempt(function () {
                    $this->paymentService()->releaseFunds($this->actor, $this->intInput('payment_id'), 'Released from the admin console');
                }, 'Funds released.');
                break;

            case 'request:refund':
                $this->attempt(function () {
                    $payment = $this->paymentService()->findOrFail($this->intInput('payment_id'));
                    $amount = (string) $this->input('amount', '');
                    $minor = $amount === ''
                        ? (int) $payment['total_minor'] - (int) $payment['refunded_minor']
                        : Money::toMinor($amount, $payment['currency']);
                    $this->paymentService()->refund(
                        $this->actor,
                        (int) $payment['id'],
                        $minor,
                        (string) $this->input('reason', '')
                    );
                }, 'Refund issued.');
                break;

            case 'request:verification':
                $this->attempt(function () {
                    $verificationId = $this->intInput('verification_id');
                    $decision = (string) $this->input('decision', 'approve');
                    if ($decision === 'waive') {
                        $this->verificationService()->waive($this->actor, $verificationId, (string) $this->input('reason', 'Waived'));
                    } elseif ($decision === 'reject') {
                        $this->verificationService()->reject($this->actor, $verificationId, (string) $this->input('reason', 'Rejected'));
                    } else {
                        $this->verificationService()->approve($this->actor, $verificationId, (string) $this->input('reason', ''));
                    }
                }, 'Verification updated.');
                break;

            case 'request:review-risk':
            case 'risk:review':
                $this->attempt(function () {
                    $this->riskService()->review(
                        $this->actor,
                        $this->intInput('flag_id'),
                        (string) $this->input('outcome', 'cleared'),
                        (string) $this->input('notes', '')
                    );
                }, 'Risk flag reviewed.');
                break;

            case 'brokers:create':
                $this->attempt(function () {
                    $this->brokerService()->create($this->actor, $this->only([
                        'display_name', 'whmcs_admin_id', 'whmcs_client_id', 'role',
                        'email', 'phone', 'max_active_requests', 'bio', 'specialisms',
                    ]));
                }, 'Broker created.');
                break;

            case 'brokers:deactivate':
                $this->attempt(function () {
                    $this->brokerService()->deactivate(
                        $this->actor,
                        $this->intInput('broker_id'),
                        (string) $this->input('reason', 'Deactivated')
                    );
                }, 'Broker deactivated.');
                break;

            case 'dispute:status':
                $this->attempt(function () use ($id) {
                    $this->disputeService()->setStatus(
                        $this->actor,
                        $id,
                        (string) $this->input('status', ''),
                        (string) $this->input('note', '')
                    );
                }, 'Dispute updated.');
                break;

            case 'dispute:resolve':
                $this->attempt(function () use ($id) {
                    $dispute = $this->disputeService()->findOrFail($id);
                    $input = [
                        'resolution' => (string) $this->input('resolution', ''),
                        'summary' => (string) $this->input('summary', ''),
                    ];
                    $amount = (string) $this->input('refund_amount', '');
                    if ($amount !== '') {
                        $input['refund_amount_minor'] = Money::toMinor($amount, $dispute['currency']);
                    }
                    $this->disputeService()->resolve($this->actor, $id, $input);
                }, 'Dispute resolved.');
                break;

            case 'dispute:reject':
                $this->attempt(function () use ($id) {
                    $this->disputeService()->reject($this->actor, $id, (string) $this->input('reason', ''));
                }, 'Dispute rejected.');
                break;

            case 'fees:create':
                $this->attempt(function () {
                    $this->feeService()->createRule($this->actor, $this->only([
                        'code', 'name', 'calculation', 'percentage', 'fixed_minor',
                        'min_fee_minor', 'max_fee_minor', 'currency', 'tld_scope',
                        'promo_code', 'priority', 'tiers', 'applies_min_minor', 'applies_max_minor',
                    ]));
                }, 'Fee rule created.');
                break;

            case 'fees:toggle':
                $this->attempt(function () {
                    $this->feeService()->updateRule($this->actor, $this->intInput('rule_id'), [
                        'active' => $this->intInput('active'),
                    ]);
                }, 'Fee rule updated.');
                break;

            case 'fees:delete':
                $this->attempt(function () {
                    $this->feeService()->deleteRule(
                        $this->actor,
                        $this->intInput('rule_id'),
                        (string) $this->input('reason', 'Removed')
                    );
                }, 'Fee rule removed.');
                break;

            case 'settings:save':
                $this->saveSettings();
                break;

            case 'reports:export':
                return $this->export();
        }

        // Post/redirect/get: a refresh must never replay an admin action.
        $redirectParams = ['action' => $action];
        if ($id > 0) {
            $redirectParams['id'] = $id;
        }
        $this->redirect($this->url($redirectParams));
        return $this->chrome($action, '');
    }

    protected function saveSettings()
    {
        Rbac::assert($this->actor, Rbac::SETTINGS_MANAGE);
        $saved = 0;
        $rejected = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'setting_') !== 0) {
                continue;
            }
            $name = substr($key, 8);
            try {
                Settings::set($name, is_string($value) ? trim($value) : $value, $this->actor->identity());
                $saved++;
            } catch (DomainBrokerException $e) {
                $rejected[] = $name;
            }
        }
        Audit::record($this->actor, 'settings.updated', [
            'new' => ['count' => $saved],
            'visibility' => Audit::VIS_INTERNAL,
        ]);
        $this->success($saved . ' setting(s) saved.');
        if ($rejected) {
            $this->error('Refused to store: ' . implode(', ', $rejected) . ' (secrets come from the environment).');
        }
    }

    protected function export()
    {
        try {
            $file = $this->reportService()->export($this->actor, (string) $this->input('dataset', 'requests'), [
                'from' => (string) $this->input('from', ''),
                'to' => (string) $this->input('to', ''),
            ]);
        } catch (DomainBrokerException $e) {
            $this->error($e->getMessage());
            $this->redirect($this->url(['action' => 'reports']));
            return $this->chrome('reports', '');
        }

        self::$lastExport = $file;
        if (self::$testMode || php_sapi_name() === 'cli') {
            return $file['body'];
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        header('Content-Length: ' . strlen($file['body']));
        echo $file['body'];
        exit;
    }
}
