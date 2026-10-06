<?php
/**
 * Domain Broker — broker assignment.
 *
 * Assignment is recorded as a history (domain_broker_assignments) rather than
 * a single mutable column, so "who had this request, when, and who moved it"
 * is always answerable. The requests table keeps a denormalised pointer to the
 * current primary broker for cheap filtering.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Db;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Workflow\RequestStatus;

class AssignmentService
{
    /** @var RequestService */
    protected $requests;

    /** @var NotificationService */
    protected $notifications;

    /** @var BrokerDirectoryService */
    protected $directory;

    public function __construct(RequestService $requests = null, NotificationService $notifications = null)
    {
        $this->requests = $requests ?: new RequestService();
        $this->notifications = $notifications ?: new NotificationService();
        $this->directory = new BrokerDirectoryService();
    }

    /**
     * Assign (or reassign) a request to a broker.
     */
    public function assign(Actor $actor, $requestId, $brokerId, $reason = '')
    {
        Rbac::assert($actor, Rbac::BROKER_ASSIGN);

        // Callers may pass a plain reason or an options array ['reason'|'note'].
        if (is_array($reason)) {
            $options = $reason;
            $reason = '';
            foreach (['reason', 'note'] as $key) {
                if (!empty($options[$key])) {
                    $reason = (string) $options[$key];
                    break;
                }
            }
        }
        $reason = (string) $reason;

        $request = $this->requests->findForActor($actor, $requestId);
        $broker = $this->directory->findOrFail($brokerId);

        if ($broker['status'] !== BrokerDirectoryService::STATUS_ACTIVE) {
            throw new ConflictException('That broker is not currently active.');
        }
        if (RequestStatus::isTerminal($request['status'])) {
            throw new ConflictException('A closed request cannot be assigned.');
        }
        if ((int) $request['assigned_broker_id'] === (int) $broker['id']) {
            return $request;
        }

        $capacity = (int) $broker['max_active_requests'];
        if ($capacity > 0 && $this->directory->openRequestCount($broker['id']) >= $capacity) {
            throw new ConflictException(
                $broker['display_name'] . ' is at capacity (' . $capacity . ' open requests).'
            );
        }

        $previousBrokerId = $request['assigned_broker_id'] !== null ? (int) $request['assigned_broker_id'] : null;

        return Db::transaction(function () use ($actor, $request, $broker, $previousBrokerId, $reason) {
            $now = Clock::now();

            if ($previousBrokerId) {
                Db::update('assignments', [
                    'status' => 'reassigned',
                    'released_at' => $now,
                    'updated_at' => $now,
                ], ['request_id' => (int) $request['id'], 'broker_id' => $previousBrokerId, 'status' => 'active']);
            }

            Db::insert('assignments', [
                'request_id' => (int) $request['id'],
                'broker_id' => (int) $broker['id'],
                'assignment_role' => 'primary',
                'status' => 'active',
                'assigned_by_type' => $actor->type,
                'assigned_by_id' => (int) $actor->actorId(),
                'reason' => Str::cleanText($reason, 1000),
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::update('requests', [
                'assigned_broker_id' => (int) $broker['id'],
                'assigned_at' => $now,
                'updated_at' => $now,
            ], ['id' => (int) $request['id']]);

            Audit::record($actor, $previousBrokerId ? 'broker.reassigned' : 'broker.assigned', [
                'request_id' => (int) $request['id'],
                'entity_type' => 'assignment',
                'entity_id' => (int) $broker['id'],
                'previous' => ['broker_id' => $previousBrokerId],
                'new' => ['broker_id' => (int) $broker['id'], 'broker' => $broker['display_name']],
                'reason' => $reason,
                'visibility' => Audit::VIS_CUSTOMER,
            ]);

            $fresh = $this->requests->findRow($request['id']);

            // Move the request forward when it is still in triage.
            if (in_array($fresh['status'], [RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW], true)) {
                $fresh = $this->requests->transition($actor, $fresh, RequestStatus::BROKER_ASSIGNED, [
                    'action' => 'request.broker.assigned',
                    'visibility' => Audit::VIS_CUSTOMER,
                ]);
            } else {
                $this->notifications->notify(
                    NotificationService::BROKER_ASSIGNED,
                    $fresh,
                    NotificationService::AUDIENCE_CUSTOMER
                );
            }

            $this->notifications->notify(
                NotificationService::BROKER_ASSIGNED,
                $fresh,
                NotificationService::AUDIENCE_BROKER,
                ['broker_id' => (int) $broker['id'], 'subject' => 'New acquisition assigned: ' . $fresh['domain']]
            );

            $this->directory->refreshCounters((int) $broker['id']);
            if ($previousBrokerId) {
                $this->directory->refreshCounters($previousBrokerId);
            }

            return $fresh;
        });
    }

    /** A broker takes an unassigned request from the queue. */
    public function claim(Actor $actor, $requestId)
    {
        Rbac::assert($actor, Rbac::REQUEST_CLAIM);
        if (!$actor->isBroker()) {
            throw new ValidationException('Only a broker can claim a request.');
        }

        $request = $this->requests->findRow($requestId);
        if (!$request || $request['deleted_at'] !== null) {
            throw new NotFoundException('Acquisition request not found.');
        }
        if ($request['assigned_broker_id'] !== null) {
            throw new ConflictException('This request has already been claimed.');
        }
        if (!empty($request['manual_review'])) {
            throw new ConflictException('This request is held for manual review and cannot be claimed yet.');
        }

        // Claiming requires the assign permission on behalf of oneself, which
        // brokers do not have — so claim is modelled as a system assignment
        // with the broker recorded as the actor.
        $broker = $this->directory->findOrFail($actor->brokerId);
        $capacity = (int) $broker['max_active_requests'];
        if ($capacity > 0 && $this->directory->openRequestCount($broker['id']) >= $capacity) {
            throw new ConflictException('You are at your configured capacity for open requests.');
        }

        $now = Clock::now();
        Db::insert('assignments', [
            'request_id' => (int) $request['id'],
            'broker_id' => (int) $broker['id'],
            'assignment_role' => 'primary',
            'status' => 'active',
            'assigned_by_type' => $actor->type,
            'assigned_by_id' => (int) $actor->actorId(),
            'reason' => 'Claimed from the open queue.',
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::update('requests', [
            'assigned_broker_id' => (int) $broker['id'],
            'assigned_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'broker.claimed', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'assignment',
            'entity_id' => (int) $broker['id'],
            'new' => ['broker_id' => (int) $broker['id']],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $fresh = $this->requests->findRow($request['id']);
        if (in_array($fresh['status'], [RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW], true)) {
            $fresh = $this->requests->transition($actor, $fresh, RequestStatus::BROKER_ASSIGNED, [
                'action' => 'request.broker.assigned',
                'visibility' => Audit::VIS_CUSTOMER,
            ]);
        }

        $this->directory->refreshCounters((int) $broker['id']);
        return $fresh;
    }

    /** Release a broker from a request without immediately replacing them. */
    public function unassign(Actor $actor, $requestId, $reason)
    {
        Rbac::assert($actor, Rbac::BROKER_ASSIGN);
        $request = $this->requests->findForActor($actor, $requestId);
        if ($request['assigned_broker_id'] === null) {
            throw new ConflictException('This request has no assigned broker.');
        }
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A reason is required to unassign a broker.', ['reason' => 'Required.']);
        }

        $previousBrokerId = (int) $request['assigned_broker_id'];
        $now = Clock::now();

        Db::update('assignments', [
            'status' => 'released', 'released_at' => $now, 'updated_at' => $now,
        ], ['request_id' => (int) $request['id'], 'broker_id' => $previousBrokerId, 'status' => 'active']);

        Db::update('requests', [
            'assigned_broker_id' => null, 'assigned_at' => null, 'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'broker.unassigned', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'assignment',
            'entity_id' => $previousBrokerId,
            'previous' => ['broker_id' => $previousBrokerId],
            'new' => ['broker_id' => null],
            'reason' => $reason,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        $this->directory->refreshCounters($previousBrokerId);
        return $this->requests->findRow($request['id']);
    }

    /**
     * Pick the least loaded active broker with capacity and assign them.
     * Used only when the operator has enabled auto_assign_brokers.
     */
    public function autoAssign(Actor $actor, array $request)
    {
        foreach ($this->directory->availableBrokers() as $broker) {
            if (empty($broker['has_capacity'])) {
                continue;
            }
            // Auto-assignment runs as the system actor, which is not granted
            // BROKER_ASSIGN; perform the write directly but audit it as such.
            return $this->performSystemAssignment($actor, $request, $broker);
        }
        return $request;
    }

    protected function performSystemAssignment(Actor $actor, array $request, array $broker)
    {
        $now = Clock::now();
        Db::insert('assignments', [
            'request_id' => (int) $request['id'],
            'broker_id' => (int) $broker['id'],
            'assignment_role' => 'primary',
            'status' => 'active',
            'assigned_by_type' => 'system',
            'assigned_by_id' => 0,
            'reason' => 'Automatic assignment (least loaded broker).',
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::update('requests', [
            'assigned_broker_id' => (int) $broker['id'],
            'assigned_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'broker.auto_assigned', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'assignment',
            'entity_id' => (int) $broker['id'],
            'new' => ['broker_id' => (int) $broker['id'], 'broker' => $broker['display_name']],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $fresh = $this->requests->findRow($request['id']);
        if (in_array($fresh['status'], [RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW], true)) {
            $fresh = $this->requests->transition($actor, $fresh, RequestStatus::BROKER_ASSIGNED, [
                'action' => 'request.broker.assigned',
                'visibility' => Audit::VIS_CUSTOMER,
            ]);
        }

        $this->notifications->notify(
            NotificationService::BROKER_ASSIGNED,
            $fresh,
            NotificationService::AUDIENCE_BROKER,
            ['broker_id' => (int) $broker['id'], 'subject' => 'New acquisition assigned: ' . $fresh['domain']]
        );

        $this->directory->refreshCounters((int) $broker['id']);
        return $fresh;
    }

    public function history($requestId)
    {
        return Db::select(
            'SELECT a.*, b.display_name, b.reference AS broker_reference'
            . ' FROM ' . Db::quoteIdentifier(Db::table('assignments')) . ' a'
            . ' LEFT JOIN ' . Db::quoteIdentifier(Db::table('brokers')) . ' b ON b.id = a.broker_id'
            . ' WHERE a.request_id = ? ORDER BY a.id ASC',
            [(int) $requestId]
        );
    }

    public function currentAssignment($requestId)
    {
        return Db::first('assignments', ['request_id' => (int) $requestId, 'status' => 'active'], [
            'order' => 'id', 'dir' => 'desc',
        ]);
    }
}
