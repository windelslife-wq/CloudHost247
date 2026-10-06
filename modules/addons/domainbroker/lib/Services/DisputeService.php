<?php
/**
 * Domain Broker — disputes and escalations.
 *
 * A dispute freezes the acquisition (the request moves to "disputed") so no
 * further automatic money or transfer movement happens while a human looks at
 * it. Resolutions are explicit and typed, and a resolution that implies a
 * refund actually issues one through PaymentService rather than just changing
 * a label.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Db;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Core\Validator;
use DomainBroker\Workflow\RequestStatus;

class DisputeService
{
    const STATUS_OPEN              = 'open';
    const STATUS_INVESTIGATING     = 'investigating';
    const STATUS_AWAITING_CUSTOMER = 'awaiting_customer';
    const STATUS_AWAITING_OWNER    = 'awaiting_owner';
    const STATUS_ESCALATED         = 'escalated';
    const STATUS_RESOLVED          = 'resolved';
    const STATUS_REJECTED          = 'rejected';

    const OPEN_STATUSES = [
        self::STATUS_OPEN, self::STATUS_INVESTIGATING,
        self::STATUS_AWAITING_CUSTOMER, self::STATUS_AWAITING_OWNER, self::STATUS_ESCALATED,
    ];

    const REASONS = [
        'domain_not_transferred' => 'The domain was not transferred',
        'wrong_domain'           => 'The wrong domain was transferred',
        'seller_unresponsive'    => 'The seller stopped responding',
        'price_disagreement'     => 'Disagreement over the agreed price',
        'fee_dispute'            => 'Dispute over the broker fee',
        'unauthorised_payment'   => 'Unauthorised or duplicate payment',
        'service_quality'        => 'Service quality concern',
        'other'                  => 'Other',
    ];

    const RESOLUTIONS = [
        'refund'             => 'Full refund issued',
        'partial_refund'     => 'Partial refund issued',
        'transfer_completed' => 'Transfer completed as agreed',
        'no_action'          => 'No action required',
        'cancelled'          => 'Acquisition cancelled',
    ];

    /** @var RequestService */
    protected $requests;

    /** @var NotificationService */
    protected $notifications;

    /** @var PaymentService */
    protected $payments;

    public function __construct(
        RequestService $requests = null,
        NotificationService $notifications = null,
        PaymentService $payments = null
    ) {
        $this->requests = $requests ?: new RequestService();
        $this->notifications = $notifications ?: new NotificationService();
        $this->payments = $payments ?: new PaymentService($this->requests, $this->notifications);
    }

    /* ------------------------------------------------------------- open */

    public function open(Actor $actor, $requestId, array $input)
    {
        Rbac::assert($actor, Rbac::DISPUTE_OPEN);
        $request = $this->requests->findForActor($actor, $requestId);
        RateLimiter::hit('dispute.open', $actor->identity());

        $existing = Db::first('disputes', [
            'request_id' => (int) $request['id'],
            'status' => ['in', self::OPEN_STATUSES],
        ]);
        if ($existing) {
            throw new ConflictException('A dispute is already open on this acquisition.', [
                'reference' => $existing['reference'],
            ]);
        }

        $data = Validator::make($input)
            ->in('reason_code', array_keys(self::REASONS), true)
            ->text('description', 4000, true, 20)
            ->in('severity', ['low', 'normal', 'high', 'critical'], false, 'normal')
            ->validate();

        $amountMinor = isset($input['amount_in_dispute_minor']) && $input['amount_in_dispute_minor'] !== ''
            ? max(0, (int) $input['amount_in_dispute_minor'])
            : (int) $request['total_minor'];

        $now = Clock::now();
        $disputeId = Db::insert('disputes', [
            'reference' => $this->uniqueReference(),
            'request_id' => (int) $request['id'],
            'opened_by_type' => $actor->type,
            'opened_by_id' => (int) $actor->actorId(),
            'reason_code' => $data['reason_code'],
            'description' => $data['description'],
            'status' => self::STATUS_OPEN,
            'severity' => $data['severity'],
            'amount_in_dispute_minor' => $amountMinor,
            'currency' => $request['currency'],
            'due_at' => Clock::inDays(Settings::int('dispute_sla_days', 5)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($actor, 'dispute.opened', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'dispute',
            'entity_id' => $disputeId,
            'new' => [
                'reason_code' => $data['reason_code'],
                'severity' => $data['severity'],
                'amount_in_dispute_minor' => $amountMinor,
            ],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        // Freeze the acquisition while the dispute is live.
        $fresh = $this->requests->findRow($request['id']);
        if (RequestStatus::canTransition($fresh['status'], RequestStatus::DISPUTED)) {
            $this->requests->transition($actor, $fresh, RequestStatus::DISPUTED, [
                'action' => 'request.disputed',
                'reason' => Str::clip($data['description'], 500),
                'visibility' => Audit::VIS_CUSTOMER,
                'silent' => true,
            ]);
        }

        $fresh = $this->requests->findRow($request['id']);
        $this->notifications->notify(NotificationService::DISPUTE_OPENED, $fresh, NotificationService::AUDIENCE_ADMIN);
        $this->notifications->notify(NotificationService::DISPUTE_OPENED, $fresh, NotificationService::AUDIENCE_CUSTOMER);
        if (!empty($fresh['assigned_broker_id'])) {
            $this->notifications->notify(
                NotificationService::DISPUTE_OPENED,
                $fresh,
                NotificationService::AUDIENCE_BROKER,
                ['broker_id' => (int) $fresh['assigned_broker_id']]
            );
        }

        return $this->find($disputeId);
    }

    /** A broker escalates a stalled acquisition to the admin team. */
    public function escalate(Actor $actor, $requestId, $summary)
    {
        Rbac::assert($actor, Rbac::ESCALATE);
        $request = $this->requests->findForActor($actor, $requestId);
        $summary = Str::cleanText($summary, 2000);
        if ($summary === '') {
            throw new ValidationException('Describe why this needs attention.', ['summary' => 'Required.']);
        }

        $dispute = Db::first('disputes', [
            'request_id' => (int) $request['id'],
            'status' => ['in', self::OPEN_STATUSES],
        ]);

        if ($dispute) {
            $this->setStatus($actor, (int) $dispute['id'], self::STATUS_ESCALATED, $summary);
            return $this->find($dispute['id']);
        }

        $now = Clock::now();
        $disputeId = Db::insert('disputes', [
            'reference' => $this->uniqueReference(),
            'request_id' => (int) $request['id'],
            'opened_by_type' => $actor->type,
            'opened_by_id' => (int) $actor->actorId(),
            'reason_code' => 'other',
            'description' => $summary,
            'status' => self::STATUS_ESCALATED,
            'severity' => 'high',
            'amount_in_dispute_minor' => (int) $request['total_minor'],
            'currency' => $request['currency'],
            'escalated_at' => $now,
            'due_at' => Clock::inDays(Settings::int('dispute_sla_days', 5)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($actor, 'request.escalated', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'dispute',
            'entity_id' => $disputeId,
            'new' => ['status' => self::STATUS_ESCALATED],
            'reason' => $summary,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        $this->notifications->notify(
            NotificationService::DISPUTE_OPENED,
            $this->requests->findRow($request['id']),
            NotificationService::AUDIENCE_ADMIN,
            ['subject' => 'Escalation: ' . $request['domain']]
        );

        return $this->find($disputeId);
    }

    /* ------------------------------------------------------- management */

    public function setStatus(Actor $actor, $disputeId, $status, $note = '')
    {
        Rbac::assert($actor, Rbac::DISPUTE_RESOLVE);
        $dispute = $this->findOrFail($disputeId);

        if (!in_array($status, self::OPEN_STATUSES, true)) {
            throw new ValidationException('Use resolve() or reject() to close a dispute.', [
                'status' => 'Invalid status for this operation.',
            ]);
        }
        if (!in_array($dispute['status'], self::OPEN_STATUSES, true)) {
            throw new ConflictException('This dispute is already closed.');
        }

        $now = Clock::now();
        $updates = ['status' => $status, 'updated_at' => $now];
        if ($status === self::STATUS_ESCALATED) {
            $updates['escalated_at'] = $now;
            $updates['severity'] = 'high';
        }
        Db::update('disputes', $updates, ['id' => (int) $dispute['id']]);

        Audit::record($actor, 'dispute.status.changed', [
            'request_id' => (int) $dispute['request_id'],
            'entity_type' => 'dispute',
            'entity_id' => (int) $dispute['id'],
            'previous' => ['status' => $dispute['status']],
            'new' => ['status' => $status],
            'reason' => $note,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        return $this->find($dispute['id']);
    }

    /**
     * Close a dispute with an explicit outcome. Refund outcomes actually move
     * the money through PaymentService.
     *
     * @param array $input resolution_type, resolution, refund_amount_minor
     */
    public function resolve(Actor $actor, $disputeId, array $input)
    {
        Rbac::assert($actor, Rbac::DISPUTE_RESOLVE);
        $dispute = $this->findOrFail($disputeId);
        if (!in_array($dispute['status'], self::OPEN_STATUSES, true)) {
            throw new ConflictException('This dispute is already closed.');
        }

        $data = Validator::make($input)
            ->in('resolution_type', array_keys(self::RESOLUTIONS), true)
            ->text('resolution', 4000, true, 10)
            ->validate();

        $request = $this->requests->findRow($dispute['request_id']);
        $resolutionType = $data['resolution_type'];

        // A refund outcome must actually issue the refund.
        if ($resolutionType === 'refund' || $resolutionType === 'partial_refund') {
            $payment = $this->payments->activePayment($request['id']);
            if (!$payment) {
                throw new ConflictException('There is no payment to refund on this acquisition.');
            }
            $amount = $resolutionType === 'refund'
                ? null
                : (int) (isset($input['refund_amount_minor']) ? $input['refund_amount_minor'] : 0);
            if ($resolutionType === 'partial_refund' && $amount <= 0) {
                throw new ValidationException('Specify the partial refund amount.', [
                    'refund_amount_minor' => 'Required for a partial refund.',
                ]);
            }
            $this->payments->refund(
                $actor,
                (int) $payment['id'],
                $amount,
                'Dispute ' . $dispute['reference'] . ': ' . Str::clip($data['resolution'], 400)
            );
        }

        $now = Clock::now();
        Db::update('disputes', [
            'status' => self::STATUS_RESOLVED,
            'resolution' => $data['resolution'],
            'resolution_type' => $resolutionType,
            'resolved_by_type' => $actor->type,
            'resolved_by_id' => (int) $actor->actorId(),
            'resolved_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $dispute['id']]);

        Audit::record($actor, 'dispute.resolved', [
            'request_id' => (int) $dispute['request_id'],
            'entity_type' => 'dispute',
            'entity_id' => (int) $dispute['id'],
            'previous' => ['status' => $dispute['status']],
            'new' => ['status' => self::STATUS_RESOLVED, 'resolution_type' => $resolutionType],
            'reason' => $data['resolution'],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $this->restoreRequestStatus($actor, $dispute, $resolutionType, $data['resolution']);

        $this->notifications->notify(
            NotificationService::DISPUTE_RESOLVED,
            $this->requests->findRow($dispute['request_id']),
            NotificationService::AUDIENCE_CUSTOMER
        );

        return $this->find($dispute['id']);
    }

    public function reject(Actor $actor, $disputeId, $reason)
    {
        Rbac::assert($actor, Rbac::DISPUTE_RESOLVE);
        $dispute = $this->findOrFail($disputeId);
        if (!in_array($dispute['status'], self::OPEN_STATUSES, true)) {
            throw new ConflictException('This dispute is already closed.');
        }
        $reason = Str::cleanText($reason, 2000);
        if ($reason === '') {
            throw new ValidationException('A reason is required.', ['reason' => 'Required.']);
        }

        $now = Clock::now();
        Db::update('disputes', [
            'status' => self::STATUS_REJECTED,
            'resolution' => $reason,
            'resolution_type' => 'no_action',
            'resolved_by_type' => $actor->type,
            'resolved_by_id' => (int) $actor->actorId(),
            'resolved_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $dispute['id']]);

        Audit::record($actor, 'dispute.rejected', [
            'request_id' => (int) $dispute['request_id'],
            'entity_type' => 'dispute',
            'entity_id' => (int) $dispute['id'],
            'previous' => ['status' => $dispute['status']],
            'new' => ['status' => self::STATUS_REJECTED],
            'reason' => $reason,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $this->restoreRequestStatus($actor, $dispute, 'no_action', $reason);

        $this->notifications->notify(
            NotificationService::DISPUTE_RESOLVED,
            $this->requests->findRow($dispute['request_id']),
            NotificationService::AUDIENCE_CUSTOMER
        );

        return $this->find($dispute['id']);
    }

    /* ---------------------------------------------------------- queries */

    public function find($disputeId)
    {
        return Db::first('disputes', ['id' => (int) $disputeId]);
    }

    public function findOrFail($disputeId)
    {
        $row = $this->find($disputeId);
        if (!$row) {
            throw new NotFoundException('Dispute not found.');
        }
        return $row;
    }

    public function forRequest($requestId)
    {
        return Db::fetch('disputes', ['request_id' => (int) $requestId], ['order' => 'id', 'dir' => 'desc']);
    }

    public function openDisputes($limit = 100)
    {
        return Db::fetch('disputes', ['status' => ['in', self::OPEN_STATUSES]], [
            'order' => 'due_at', 'limit' => $limit,
        ]);
    }

    public function listForActor(Actor $actor, array $filters = [])
    {
        Rbac::assert($actor, Rbac::DISPUTE_RESOLVE);
        $where = [];
        if (!empty($filters['status'])) {
            $where['status'] = (string) $filters['status'];
        }
        if (!empty($filters['request_id'])) {
            $where['request_id'] = (int) $filters['request_id'];
        }
        return Db::fetch('disputes', $where, [
            'order' => 'id',
            'dir' => 'desc',
            'limit' => isset($filters['limit']) ? max(1, min(200, (int) $filters['limit'])) : 50,
            'offset' => isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0,
        ]);
    }

    public function statistics()
    {
        return [
            'open' => Db::count('disputes', ['status' => ['in', self::OPEN_STATUSES]]),
            'resolved' => Db::count('disputes', ['status' => self::STATUS_RESOLVED]),
            'rejected' => Db::count('disputes', ['status' => self::STATUS_REJECTED]),
            'total' => Db::count('disputes'),
        ];
    }

    /* ---------------------------------------------------------- helpers */

    /**
     * Put the acquisition back on a sensible footing once the dispute closes.
     * The request never silently jumps forward: it either closes out or
     * returns to the stage that matches reality.
     */
    protected function restoreRequestStatus(Actor $actor, array $dispute, $resolutionType, $reason)
    {
        $request = $this->requests->findRow($dispute['request_id']);
        if (!$request || $request['status'] !== RequestStatus::DISPUTED) {
            return;
        }

        $target = null;
        switch ($resolutionType) {
            case 'refund':
                $target = RequestStatus::REFUNDED;
                break;
            case 'cancelled':
                $target = RequestStatus::CANCELLED;
                break;
            case 'transfer_completed':
                $target = RequestStatus::TRANSFER_VERIFICATION;
                break;
            case 'partial_refund':
            case 'no_action':
            default:
                $target = $request['previous_status'] ?: RequestStatus::NEGOTIATION;
                if (!RequestStatus::canTransition($request['status'], $target)) {
                    // The stage it was frozen at is no longer reachable (for
                    // example the workflow moved on): fall back to the
                    // negotiation desk rather than leaving it stuck.
                    $target = RequestStatus::NEGOTIATION;
                }
        }

        if ($target && RequestStatus::canTransition($request['status'], $target)) {
            $this->requests->transition($actor, $request, $target, [
                'action' => 'request.dispute.closed',
                'reason' => Str::clip($reason, 500),
                'visibility' => Audit::VIS_CUSTOMER,
                'silent' => true,
            ]);
        }
    }

    protected function uniqueReference()
    {
        for ($i = 0; $i < 25; $i++) {
            $ref = Str::reference('DS', 8);
            if (Db::count('disputes', ['reference' => $ref]) === 0) {
                return $ref;
            }
        }
        throw new ConflictException('Unable to allocate a dispute reference.');
    }
}
