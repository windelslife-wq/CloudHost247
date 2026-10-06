<?php
/**
 * Domain Broker — the broker workspace.
 *
 * Brokers are staff, so their desk lives in the WHMCS admin area alongside the
 * administrative console but shows only their own work: the open queue, the
 * requests assigned to them, and the actions their role permits. Every call is
 * handed to a service with the broker Actor, so ownership is re-checked
 * server-side on each operation — being a broker is never enough on its own.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Http;

use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Db;
use DomainBroker\Core\DomainBrokerException;
use DomainBroker\Core\Money;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Services\DocumentService;
use DomainBroker\Services\MessageService;
use DomainBroker\Services\VerificationService;
use DomainBroker\Workflow\OfferStatus;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;
use DomainBroker\Workflow\TransferStatus;

class BrokerDesk extends Controller
{
    /** Buckets the desk is organised into. */
    const BUCKETS = [
        'queue' => 'New & unassigned',
        'assigned' => 'Assigned to me',
        'negotiating' => 'Active negotiations',
        'awaiting_customer' => 'Awaiting customer',
        'accepted' => 'Accepted offers',
        'payment' => 'Payment pending',
        'transfer' => 'Transfers',
        'completed' => 'Completed',
        'failed' => 'Unsuccessful',
        'disputed' => 'Disputes',
    ];

    public function handle(array $vars = [])
    {
        if (isset($vars['modulelink'])) {
            View::setAdminLink($vars['modulelink']);
        }

        $action = (string) $this->input('action', 'desk');

        if ($this->isPost()) {
            $redirect = $this->handleWrite($action);
            if ($redirect !== null) {
                return $redirect;
            }
        }

        try {
            $body = $action === 'request' ? $this->requestPage() : $this->deskPage();
        } catch (AuthorizationException $e) {
            $body = Html::alert($e->getMessage(), 'danger');
        } catch (DomainBrokerException $e) {
            $body = Html::alert($e->getMessage(), 'warning');
        }

        $flash = '';
        foreach ($this->takeFlash() as $message) {
            $flash .= Html::alert($message['message'], $message['type']);
        }

        return '<div class="domainbroker-admin domainbroker-desk">'
            . '<link rel="stylesheet" href="../modules/addons/domainbroker/assets/css/admin.css">'
            . $flash . $body . '</div>';
    }

    protected function url(array $params)
    {
        return View::adminLink($params);
    }

    /* -------------------------------------------------------------- pages */

    protected function deskPage()
    {
        $bucket = (string) $this->input('bucket', 'assigned');
        if (!isset(self::BUCKETS[$bucket])) {
            $bucket = 'assigned';
        }

        $counts = $this->bucketCounts();
        $tabs = [];
        foreach (self::BUCKETS as $key => $label) {
            $tabs[] = [
                'label' => $label . ' (' . (isset($counts[$key]) ? $counts[$key] : 0) . ')',
                'url' => $this->url(['action' => 'desk', 'bucket' => $key]),
                'active' => $key === $bucket,
            ];
        }

        $rows = [];
        foreach ($this->bucketRows($bucket) as $request) {
            $rows[] = $this->requestRow($request, $bucket);
        }

        return Html::heading(
            'Broker desk',
            $this->actor->name . ' · ' . (isset($counts['assigned']) ? $counts['assigned'] : 0) . ' active acquisition(s)'
        )
            . Html::tabs($tabs)
            . Html::table(
                ['Reference', 'Domain', 'Status', 'Budget', 'Current offer', 'Updated', ''],
                $rows,
                'Nothing in this bucket.'
            );
    }

    protected function requestRow(array $request, $bucket)
    {
        $claim = '';
        if ($bucket === 'queue' && Rbac::allows($this->actor, Rbac::REQUEST_CLAIM)) {
            $claim = Html::inlineForm(
                $this->url(['action' => 'desk', 'do' => 'claim', 'bucket' => $bucket]),
                ['request_id' => (int) $request['id']],
                'Claim',
                'btn btn-xs btn-primary'
            );
        }

        $offer = $this->negotiationService()->pendingCustomerOffer($request['id']);
        return [
            Html::link($request['reference'], $this->url(['action' => 'request', 'id' => (int) $request['id']])),
            Html::e($request['domain_display'] ?: $request['domain']),
            Html::badge(RequestStatus::label($request['status']), View::toneClass(RequestStatus::tone($request['status']))),
            Html::e(Money::format((int) $request['budget_minor'], $request['currency'])),
            $offer ? Html::e(Money::format((int) $offer['amount_minor'], $offer['currency'])) : '—',
            Html::e(View::relative($request['updated_at'])),
            $claim,
        ];
    }

    protected function bucketRows($bucket)
    {
        $brokerId = (int) $this->actor->brokerId;
        $limit = ['order' => 'updated_at', 'dir' => 'desc', 'limit' => 100];

        switch ($bucket) {
            case 'queue':
                Rbac::assert($this->actor, Rbac::REQUEST_VIEW_QUEUE);
                return Db::fetch('requests', [
                    'assigned_broker_id' => null,
                    'deleted_at' => null,
                    'manual_review' => 0,
                    'status' => ['in', [RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW]],
                ], ['order' => 'created_at', 'dir' => 'asc', 'limit' => 100]);

            case 'negotiating':
                return $this->mine([
                    RequestStatus::OWNER_CONTACTED, RequestStatus::NEGOTIATION,
                    RequestStatus::OFFER_RECEIVED, RequestStatus::COUNTEROFFER,
                ], $limit);

            case 'awaiting_customer':
                return $this->mine([RequestStatus::OFFER_RECEIVED, RequestStatus::COUNTEROFFER], $limit);

            case 'accepted':
                return $this->mine([RequestStatus::OFFER_ACCEPTED], $limit);

            case 'payment':
                return $this->mine([RequestStatus::PAYMENT_PENDING, RequestStatus::PAYMENT_SECURED], $limit);

            case 'transfer':
                return $this->mine([RequestStatus::DOMAIN_TRANSFER, RequestStatus::TRANSFER_VERIFICATION], $limit);

            case 'completed':
                return $this->mine([RequestStatus::COMPLETED], $limit);

            case 'failed':
                return $this->mine([
                    RequestStatus::FAILED, RequestStatus::REJECTED,
                    RequestStatus::EXPIRED, RequestStatus::CANCELLED,
                ], $limit);

            case 'disputed':
                return $this->mine([RequestStatus::DISPUTED], $limit);

            case 'assigned':
            default:
                return Db::fetch('requests', [
                    'assigned_broker_id' => $brokerId,
                    'deleted_at' => null,
                    'status' => ['in', $this->activeStatuses()],
                ], $limit);
        }
    }

    protected function mine(array $statuses, array $options)
    {
        return Db::fetch('requests', [
            'assigned_broker_id' => (int) $this->actor->brokerId,
            'deleted_at' => null,
            'status' => ['in', $statuses],
        ], $options);
    }

    protected function activeStatuses()
    {
        $out = [];
        foreach (RequestStatus::all() as $status) {
            if (!RequestStatus::isTerminal($status)) {
                $out[] = $status;
            }
        }
        return $out;
    }

    protected function bucketCounts()
    {
        $counts = [];
        foreach (array_keys(self::BUCKETS) as $bucket) {
            try {
                $counts[$bucket] = count($this->bucketRows($bucket));
            } catch (\Throwable $e) {
                $counts[$bucket] = 0;
            }
        }
        return $counts;
    }

    /* ------------------------------------------------------ request page */

    protected function requestPage()
    {
        $request = $this->requestService()->findForActor($this->actor, $this->intInput('id'));
        $id = (int) $request['id'];
        $currency = $request['currency'];

        $offers = $this->negotiationService()->offersFor($id, 'broker');
        $negotiations = $this->negotiationService()->negotiationsFor($id);
        $transfer = $this->transferService()->forRequest($id);
        $payment = $this->paymentService()->activePayment($id);
        $threads = $this->messageService()->threadsFor($this->actor, $id);
        $documents = $this->documentService()->listFor($this->actor, $id);
        $verifications = $this->verificationService()->forRequest($id);

        $summary = Html::details([
            'Reference' => Html::e($request['reference']),
            'Domain' => Html::e($request['domain_display'] ?: $request['domain']),
            'Status' => Html::badge(RequestStatus::label($request['status']), View::toneClass(RequestStatus::tone($request['status']))),
            'Budget' => Html::e(Money::format((int) $request['budget_minor'], $currency))
                . ($request['budget_includes_fees'] ? ' <small class="text-muted">(fees included)</small>' : ''),
            'Maximum offer' => Html::e(Money::format(
                $this->feeService()->maxAcquisitionWithinBudget((int) $request['budget_minor'], $currency, $request['domain']),
                $currency
            )),
            'Anonymous' => $request['anonymous'] ? 'Yes — never disclose the client' : 'No',
            'Customer brief' => nl2br(Html::e($request['customer_message'])),
            'Payment' => Html::badge(PaymentStatus::label($request['payment_status']), 'default'),
            'Transfer' => Html::badge(TransferStatus::label($request['transfer_status']), 'default'),
            'Rounds' => Html::e((int) $request['negotiation_rounds']),
        ]);

        $offerRows = [];
        foreach ($offers as $offer) {
            $withdraw = '';
            if ($offer['status'] === OfferStatus::PENDING && $offer['direction'] === OfferStatus::DIR_TO_OWNER) {
                $withdraw = Html::inlineForm(
                    $this->url(['action' => 'request', 'id' => $id, 'do' => 'withdraw-offer']),
                    ['offer_id' => (int) $offer['id'], 'reason' => 'Withdrawn by broker'],
                    'Withdraw',
                    'btn btn-xs btn-default'
                );
            }
            $offerRows[] = [
                Html::e($offer['reference']),
                Html::e('R' . (int) $offer['round']),
                Html::e(Str::label($offer['direction'])),
                Html::e(Money::format((int) $offer['amount_minor'], $offer['currency'])),
                Html::badge(OfferStatus::label($offer['status']), 'default'),
                Html::e(View::date($offer['created_at'])),
                $withdraw,
            ];
        }

        $negotiationRows = [];
        foreach ($negotiations as $negotiation) {
            $respond = '';
            if ($negotiation['status'] === 'awaiting_owner' && Rbac::allows($this->actor, Rbac::OFFER_RECORD_OWNER)) {
                $respond = Html::link(
                    'Record response',
                    '#negotiation-' . (int) $negotiation['id'],
                    'btn btn-xs btn-default',
                    ['data-db-toggle' => 'negotiation-' . (int) $negotiation['id']]
                );
            }
            $negotiationRows[] = [
                Html::e('R' . (int) $negotiation['round']),
                Html::e(Str::label($negotiation['channel'])),
                Html::e(Str::label($negotiation['status'])),
                Html::e($negotiation['outcome'] ? Str::label($negotiation['outcome']) : '—'),
                Html::e(Str::clip((string) $negotiation['summary'], 160)),
                Html::e(View::date($negotiation['owner_responded_at'])),
                $respond,
            ];
        }

        $messageRows = '';
        foreach (['customer' => 'Customer thread', 'internal' => 'Internal notes', 'owner' => 'Owner correspondence'] as $key => $label) {
            if (!isset($threads[$key])) {
                continue;
            }
            $items = '';
            foreach ($threads[$key] as $message) {
                $items .= '<li class="db-message db-message-' . Html::e($key) . '">'
                    . '<strong>' . Html::e($message['sender_label']) . '</strong> '
                    . '<small class="text-muted">' . Html::e(View::date($message['created_at'])) . '</small>'
                    . '<div>' . nl2br(Html::e($message['body'])) . '</div></li>';
            }
            $messageRows .= Html::panel(
                $label,
                ($items === '' ? '<p class="text-muted">Nothing yet.</p>' : '<ul class="db-messages">' . $items . '</ul>')
                . Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'message']))
                . Html::hidden('thread', $key)
                . Html::field('Message', Html::textarea('body_' . $key, '', ['name' => 'body', 'required' => true]))
                . Html::field('', Html::submit('Post to ' . strtolower($label)))
                . Html::formClose()
            );
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
            $verificationRows[] = [
                Html::e(Str::label($item['type'])),
                Html::badge(Str::label($item['status']), $item['status'] === 'approved' ? 'success' : 'default'),
                Html::e($item['required'] ? 'Required' : 'Optional'),
                Html::e($item['method']),
                Html::e(View::date($item['checked_at'])),
            ];
        }

        $timelineRows = [];
        foreach (Audit::timeline($id, 'broker') as $entry) {
            $timelineRows[] = [
                Html::e(View::date($entry['created_at'])),
                Html::e($entry['actor_label']),
                Html::e(\DomainBroker\Api\Presenter::describe($entry['action'])),
            ];
        }

        return Html::heading(
            $request['domain_display'] ?: $request['domain'],
            $request['reference'] . ' · ' . RequestStatus::label($request['status']),
            Html::link('Back to desk', $this->url(['action' => 'desk']), 'btn btn-default btn-sm')
        )
            . '<div class="row"><div class="col-md-7">'
            . Html::panel('Acquisition', $summary)
            . Html::panel('Offers', Html::table(['Offer', 'Round', 'Direction', 'Amount', 'Status', 'Created', ''], $offerRows, 'No offers yet.'))
            . Html::panel('Negotiation rounds', Html::table(['Round', 'Channel', 'State', 'Outcome', 'Summary', 'Owner replied', ''], $negotiationRows, 'The owner has not been approached yet.'))
            . $messageRows
            . Html::panel('Documents', Html::table(['Document', 'Category', 'Visibility', 'Size', 'Uploaded'], $documentRows, 'No documents.'))
            . Html::panel('Verification', Html::table(['Requirement', 'Status', 'Necessity', 'Method', 'Checked'], $verificationRows, 'No requirements recorded.'))
            . Html::panel('Activity', Html::table(['When', 'Actor', 'Action'], $timelineRows, 'No activity.'))
            . '</div><div class="col-md-5">'
            . $this->actionPanels($request, $negotiations, $transfer, $payment)
            . '</div></div>';
    }

    /** Everything a broker can do, gated on their role and the workflow stage. */
    protected function actionPanels(array $request, array $negotiations, $transfer, $payment)
    {
        $id = (int) $request['id'];
        $currency = $request['currency'];
        $out = '';

        if (!$request['assigned_broker_id'] && Rbac::allows($this->actor, Rbac::REQUEST_CLAIM)) {
            $out .= Html::panel('Claim',
                Html::inlineForm(
                    $this->url(['action' => 'request', 'id' => $id, 'do' => 'claim']),
                    ['request_id' => $id],
                    'Claim this request',
                    'btn btn-primary'
                ),
                'primary'
            );
            return $out; // nothing else is available until it is theirs
        }

        if (Rbac::allows($this->actor, Rbac::OWNER_CONTACT)) {
            $out .= Html::panel('Contact the owner',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'contact-owner']))
                . Html::field('Channel', Html::select('channel', [
                    'email' => 'Email', 'phone' => 'Telephone', 'marketplace' => 'Marketplace',
                    'registrar' => 'Via registrar', 'postal' => 'Postal', 'other' => 'Other',
                ], 'email'))
                . Html::field('Summary', Html::textarea('summary', '', ['required' => true]), 'What you said. Visible to the customer.')
                . Html::field('', Html::submit('Record approach'))
                . Html::formClose()
            );
        }

        if (Rbac::allows($this->actor, Rbac::OFFER_RECORD_OWNER) && $negotiations) {
            $options = [];
            foreach ($negotiations as $negotiation) {
                $options[(int) $negotiation['id']] = 'Round ' . (int) $negotiation['round']
                    . ' — ' . Str::label($negotiation['status']);
            }
            $out .= Html::panel('Record the owner\'s response',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'owner-response']))
                . Html::field('Round', Html::select('negotiation_id', $options))
                . Html::field('Outcome', Html::select('outcome', [
                    'interested' => 'Interested', 'countered' => 'Countered',
                    'accepted' => 'Accepted', 'rejected' => 'Rejected', 'no_response' => 'No response',
                ], 'interested'))
                . Html::field('What they said', Html::textarea('response', '', ['required' => true]))
                . Html::field('', Html::submit('Save response'))
                . Html::formClose()
            );
        }

        if (Rbac::allows($this->actor, Rbac::OFFER_CREATE)) {
            $out .= Html::panel('Submit an offer to the owner',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'offer']))
                . Html::hidden('direction', OfferStatus::DIR_TO_OWNER)
                . Html::field('Amount (' . $currency . ')', Html::input('amount', '', ['required' => true]))
                . Html::field('Valid for (hours)', Html::input('expires_in_hours', (string) Settings::int('offer_validity_hours', 120), ['type' => 'number']))
                . Html::field('Message', Html::textarea('message', ''))
                . Html::field('Internal note', Html::textarea('internal_note', ''), 'Never shown to the customer.')
                . Html::field('', Html::submit('Send offer'))
                . Html::formClose()
            );
        }

        if (Rbac::allows($this->actor, Rbac::OFFER_RECORD_OWNER)) {
            $out .= Html::panel('Record the owner\'s offer / counteroffer',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'offer']))
                . Html::hidden('direction', OfferStatus::DIR_TO_CUSTOMER)
                . Html::field('Amount (' . $currency . ')', Html::input('amount', '', ['required' => true]))
                . Html::field('Valid for (hours)', Html::input('expires_in_hours', (string) Settings::int('offer_validity_hours', 120), ['type' => 'number']))
                . Html::field('Message to the customer', Html::textarea('message', ''))
                . Html::checkbox('requires_customer_approval', true, 'Ask the customer to approve this offer')
                . Html::field('', Html::submit('Record offer'))
                . Html::formClose(),
                'info'
            );
        }

        if ($payment && Rbac::allows($this->actor, Rbac::TRANSFER_START)
            && PaymentStatus::isSettled($payment['status']) && !$transfer) {
            $out .= Html::panel('Start the transfer',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'start-transfer']))
                . Html::field('Losing registrar', Html::input('losing_registrar', ''))
                . Html::field('Gaining registrar', Html::input('gaining_registrar', Settings::string('default_gaining_registrar', '')))
                . Html::field('Destination account', Html::input('destination_account', ''))
                . Html::field('Notes', Html::textarea('notes', ''))
                . Html::field('', Html::submit('Start transfer', 'btn btn-primary'))
                . Html::formClose(),
                'primary'
            );
        }

        if ($transfer) {
            $out .= $this->transferPanel($request, $transfer);
        }

        if (Rbac::allows($this->actor, Rbac::VERIFICATION_RECORD)) {
            $types = [];
            foreach (VerificationService::TYPES as $type => $label) {
                $types[$type] = $label;
            }
            $out .= Html::panel('Submit verification evidence',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'verification']))
                . Html::field('Requirement', Html::select('type', $types))
                . Html::field('Method', Html::input('method', '', ['required' => true]), 'How you verified it.')
                . Html::field('Reference', Html::input('reference', ''), 'Stored encrypted; admins only.')
                . Html::field('Notes', Html::textarea('notes', ''))
                . Html::field('', Html::submit('Submit evidence'))
                . Html::formClose()
            );
        }

        $out .= Html::panel('Upload a document',
            '<form method="post" enctype="multipart/form-data" action="'
            . Html::e($this->url(['action' => 'request', 'id' => $id, 'do' => 'upload']))
            . '" class="form-horizontal db-form">' . \DomainBroker\Core\Csrf::field()
            . Html::field('File', '<input type="file" name="document" required>')
            . Html::field('Category', Html::select('category', DocumentService::CATEGORIES, 'other'))
            . Html::field('Visibility', Html::select('visibility', [
                'customer' => 'Shared with the customer',
                'broker' => 'Broker and admin only',
                'internal' => 'Admin only',
            ], 'broker'))
            . Html::field('Description', Html::input('description', ''))
            . Html::field('', Html::submit('Upload'))
            . '</form>'
        );

        if (Rbac::allows($this->actor, Rbac::ESCALATE)) {
            $out .= Html::panel('Escalate',
                Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'escalate']))
                . Html::field('Summary', Html::textarea('summary', '', ['required' => true]))
                . Html::field('', Html::submit('Escalate to administrators', 'btn btn-warning'))
                . Html::formClose(),
                'warning'
            );
        }

        return $out;
    }

    protected function transferPanel(array $request, array $transfer)
    {
        $id = (int) $request['id'];
        $transferId = (int) $transfer['id'];

        $detail = Html::details([
            'Reference' => Html::e($transfer['reference']),
            'Status' => Html::badge(TransferStatus::label($transfer['status']), 'default'),
            'Auth code' => Html::e($transfer['auth_code_hint'] ?: 'not recorded'),
            'Initiated' => Html::e(View::date($transfer['initiated_at'])),
            'Expires' => Html::e(View::date($transfer['expires_at'])),
        ]);

        $statusOptions = [];
        foreach (TransferStatus::all() as $status) {
            $statusOptions[$status] = TransferStatus::label($status);
        }

        $forms = Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'auth-code']))
            . Html::hidden('transfer_id', $transferId)
            . Html::field('Authorisation code', Html::input('auth_code', '', ['required' => true]), 'Stored encrypted; only the last four characters are ever displayed.')
            . Html::field('', Html::submit('Record auth code'))
            . Html::formClose()
            . '<hr>'
            . Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'transfer-status']))
            . Html::hidden('transfer_id', $transferId)
            . Html::field('Status', Html::select('status', $statusOptions, $transfer['status']))
            . Html::field('Evidence / reason', Html::textarea('evidence', ''), 'Required to mark a transfer completed or failed.')
            . Html::field('', Html::submit('Update transfer'))
            . Html::formClose();

        $complete = '';
        $outstanding = $this->verificationService()->outstandingRequirements($request);
        if ($transfer['status'] === TransferStatus::COMPLETED) {
            if ($outstanding) {
                $complete = Html::alert(
                    'Verification outstanding: ' . implode(', ', array_map([Str::class, 'label'], $outstanding))
                    . '. The acquisition cannot be completed until an administrator approves these.',
                    'warning'
                );
            } else {
                $complete = Html::formOpen($this->url(['action' => 'request', 'id' => $id, 'do' => 'complete']))
                    . Html::field('Note', Html::input('note', ''))
                    . Html::field('', Html::submit('Mark acquisition complete', 'btn btn-success'))
                    . Html::formClose();
            }
        }

        return Html::panel('Transfer', $detail . $forms . $complete, 'info');
    }

    /* ------------------------------------------------------------- writes */

    protected function handleWrite($action)
    {
        try {
            $this->guardCsrf();
        } catch (\Throwable $e) {
            $this->error('Security token mismatch. Please try again.');
            return null;
        }

        $do = (string) $this->input('do', '');
        $id = $this->intInput('id');

        switch ($do) {
            case 'claim':
                $requestId = $this->intInput('request_id', $id);
                $this->attempt(function () use ($requestId) {
                    $this->assignmentService()->claim($this->actor, $requestId);
                }, 'Request claimed.');
                break;

            case 'contact-owner':
                $this->attempt(function () use ($id) {
                    $this->negotiationService()->recordOwnerContact($this->actor, $id, [
                        'channel' => (string) $this->input('channel', 'email'),
                        'summary' => (string) $this->input('summary', ''),
                    ]);
                }, 'Owner approach recorded.');
                break;

            case 'owner-response':
                $this->attempt(function () {
                    $this->negotiationService()->recordOwnerResponse($this->actor, $this->intInput('negotiation_id'), [
                        'response' => (string) $this->input('response', ''),
                        'outcome' => (string) $this->input('outcome', 'interested'),
                    ]);
                }, 'Owner response recorded.');
                break;

            case 'offer':
                $this->attempt(function () use ($id) {
                    $this->negotiationService()->createOffer($this->actor, $id, [
                        'direction' => (string) $this->input('direction', OfferStatus::DIR_TO_OWNER),
                        'amount' => (string) $this->input('amount', ''),
                        'message' => (string) $this->input('message', ''),
                        'internal_note' => (string) $this->input('internal_note', ''),
                        'expires_in_hours' => $this->intInput('expires_in_hours', 0) ?: null,
                        'requires_customer_approval' => $this->boolInput('requires_customer_approval'),
                    ]);
                }, 'Offer recorded.');
                break;

            case 'withdraw-offer':
                $this->attempt(function () {
                    $this->negotiationService()->withdrawOffer(
                        $this->actor,
                        $this->intInput('offer_id'),
                        (string) $this->input('reason', '')
                    );
                }, 'Offer withdrawn.');
                break;

            case 'message':
                $this->attempt(function () use ($id) {
                    $this->messageService()->send($this->actor, $id, [
                        'thread' => (string) $this->input('thread', MessageService::THREAD_CUSTOMER),
                        'body' => (string) $this->input('body', ''),
                    ]);
                }, 'Message posted.');
                break;

            case 'upload':
                $this->attempt(function () use ($id) {
                    $file = isset($_FILES['document']) ? $_FILES['document'] : [];
                    $this->documentService()->upload($this->actor, $id, $file, [
                        'category' => (string) $this->input('category', 'other'),
                        'visibility' => (string) $this->input('visibility', 'broker'),
                        'description' => (string) $this->input('description', ''),
                    ]);
                }, 'Document uploaded.');
                break;

            case 'start-transfer':
                $this->attempt(function () use ($id) {
                    $this->transferService()->start($this->actor, $id, $this->only([
                        'losing_registrar', 'gaining_registrar', 'destination_account', 'notes',
                    ]));
                }, 'Transfer started.');
                break;

            case 'auth-code':
                $this->attempt(function () {
                    $this->transferService()->recordAuthCode(
                        $this->actor,
                        $this->intInput('transfer_id'),
                        (string) $this->input('auth_code', '')
                    );
                }, 'Authorisation code stored securely.');
                break;

            case 'transfer-status':
                $this->attempt(function () {
                    $this->transferService()->updateStatus(
                        $this->actor,
                        $this->intInput('transfer_id'),
                        (string) $this->input('status', ''),
                        [
                            'evidence' => (string) $this->input('evidence', ''),
                            'reason' => (string) $this->input('evidence', ''),
                        ]
                    );
                }, 'Transfer updated.');
                break;

            case 'complete':
                $this->attempt(function () use ($id) {
                    $this->transferService()->completeAcquisition($this->actor, $id, (string) $this->input('note', ''));
                }, 'Acquisition completed.');
                break;

            case 'verification':
                $this->attempt(function () use ($id) {
                    $this->verificationService()->submitEvidence(
                        $this->actor,
                        $id,
                        (string) $this->input('type', ''),
                        $this->only(['method', 'notes', 'reference', 'provider', 'document_id'])
                    );
                }, 'Verification evidence submitted.');
                break;

            case 'escalate':
                $this->attempt(function () use ($id) {
                    $this->disputeService()->escalate($this->actor, $id, (string) $this->input('summary', ''));
                }, 'Escalated to administrators.');
                break;
        }

        $params = $action === 'request' && $id > 0
            ? ['action' => 'request', 'id' => $id]
            : ['action' => 'desk', 'bucket' => (string) $this->input('bucket', 'assigned')];
        $this->redirect($this->url($params));
        return null;
    }
}
