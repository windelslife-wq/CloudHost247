<?php
/**
 * Domain Broker — per-request messaging.
 *
 * Three physically separate threads share one table, discriminated by the
 * `thread` column:
 *
 *   customer  — customer ↔ broker. Visible to both, plus admins.
 *   internal  — broker/admin notes. NEVER returned by a customer query.
 *   owner     — the broker's record of correspondence with the registrant.
 *               Not exposed to the customer either, because it can carry the
 *               registrant's identity.
 *
 * The separation is enforced at the query level (`customerThread()` hard-codes
 * `thread = customer`), not by filtering after the fact, so a missed flag
 * cannot leak an internal note.
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
use DomainBroker\Core\Http;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Core\Validator;
use DomainBroker\Workflow\RequestStatus;

class MessageService
{
    const THREAD_CUSTOMER = 'customer';
    const THREAD_INTERNAL = 'internal';
    const THREAD_OWNER    = 'owner';

    const THREADS = [self::THREAD_CUSTOMER, self::THREAD_INTERNAL, self::THREAD_OWNER];

    /** @var RequestService */
    protected $requests;

    /** @var NotificationService */
    protected $notifications;

    public function __construct(RequestService $requests = null, NotificationService $notifications = null)
    {
        $this->requests = $requests ?: new RequestService();
        $this->notifications = $notifications ?: new NotificationService();
    }

    /* ------------------------------------------------------------ write */

    /**
     * Post a message.
     *
     * @param array $input body, thread, document_id
     */
    public function send(Actor $actor, $requestId, array $input)
    {
        $request = $this->requests->findForActor($actor, $requestId);
        $thread = isset($input['thread']) ? (string) $input['thread'] : self::THREAD_CUSTOMER;
        if (!in_array($thread, self::THREADS, true)) {
            throw new ValidationException('Unknown message thread.', ['thread' => 'Unknown thread.']);
        }

        // Permission depends on which thread is being written to.
        if ($thread === self::THREAD_CUSTOMER) {
            Rbac::assert($actor, Rbac::MESSAGE_SEND);
        } else {
            Rbac::assert($actor, Rbac::NOTE_INTERNAL_WRITE);
            if ($actor->isCustomer()) {
                // Belt and braces: a customer can never write to a private thread.
                throw new AuthorizationException('You cannot post to an internal thread.');
            }
        }
        if ($actor->isBroker()) {
            $this->assertBrokerOwnsRequest($actor, $request);
        }

        if (RequestStatus::isTerminal($request['status']) && $thread === self::THREAD_CUSTOMER
            && !in_array($request['status'], [RequestStatus::DISPUTED, RequestStatus::COMPLETED], true)) {
            throw new ConflictException('This request is closed; messaging is no longer available.');
        }

        RateLimiter::hit('message.send', $actor->identity());

        $data = Validator::make($input)
            ->text('body', 10000, true, 1)
            ->validate();

        $documentId = null;
        if (!empty($input['document_id'])) {
            $doc = Db::first('documents', [
                'id' => (int) $input['document_id'],
                'request_id' => (int) $request['id'],
                'deleted_at' => null,
            ]);
            if (!$doc) {
                throw new ValidationException('The attached document does not belong to this request.', [
                    'document_id' => 'Unknown document.',
                ]);
            }
            $documentId = (int) $doc['id'];
        }

        $now = Clock::now();
        $messageId = Db::insert('messages', [
            'request_id' => (int) $request['id'],
            'thread' => $thread,
            'sender_type' => $actor->type,
            'sender_id' => (int) $actor->actorId(),
            'sender_label' => Str::clip($actor->name, 190),
            'recipient_type' => $this->recipientFor($actor, $thread),
            'body' => $data['body'],
            'body_format' => 'text',
            'is_internal' => $thread === self::THREAD_CUSTOMER ? 0 : 1,
            'document_id' => $documentId,
            'ip_address' => Str::clip(Http::clientIp(), 45),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($actor, $thread === self::THREAD_CUSTOMER ? 'message.sent' : 'note.added', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'message',
            'entity_id' => $messageId,
            'new' => ['thread' => $thread, 'length' => strlen($data['body'])],
            // An internal note must not appear on the customer's timeline.
            'visibility' => $thread === self::THREAD_CUSTOMER ? Audit::VIS_CUSTOMER : Audit::VIS_INTERNAL,
        ]);

        if ($thread === self::THREAD_CUSTOMER) {
            $this->notifyCounterparty($actor, $request);
        }

        if ($actor->isBroker()) {
            Db::update('requests', ['last_broker_action_at' => $now, 'updated_at' => $now], ['id' => (int) $request['id']]);
        } elseif ($actor->isCustomer()) {
            Db::update('requests', ['last_customer_action_at' => $now, 'updated_at' => $now], ['id' => (int) $request['id']]);
        }

        return Db::first('messages', ['id' => $messageId]);
    }

    /** Convenience wrapper used by the broker UI. */
    public function addInternalNote(Actor $actor, $requestId, $body)
    {
        return $this->send($actor, $requestId, ['body' => $body, 'thread' => self::THREAD_INTERNAL]);
    }

    /** The broker logs correspondence with the registrant. */
    public function logOwnerCorrespondence(Actor $actor, $requestId, $body)
    {
        return $this->send($actor, $requestId, ['body' => $body, 'thread' => self::THREAD_OWNER]);
    }

    /* ------------------------------------------------------------- read */

    /**
     * The customer-visible conversation. Hard-scoped to the customer thread.
     */
    public function customerThread(Actor $actor, $requestId, $limit = 200)
    {
        $request = $this->requests->findForActor($actor, $requestId);
        return Db::fetch('messages', [
            'request_id' => (int) $request['id'],
            'thread' => self::THREAD_CUSTOMER,
            'deleted_at' => null,
        ], ['order' => 'id', 'limit' => $limit]);
    }

    /** Internal notes. Requires NOTE_INTERNAL_READ. */
    public function internalThread(Actor $actor, $requestId, $limit = 200)
    {
        Rbac::assert($actor, Rbac::NOTE_INTERNAL_READ);
        $request = $this->requests->findForActor($actor, $requestId);
        if ($actor->isBroker()) {
            $this->assertBrokerOwnsRequest($actor, $request);
        }
        return Db::fetch('messages', [
            'request_id' => (int) $request['id'],
            'thread' => self::THREAD_INTERNAL,
            'deleted_at' => null,
        ], ['order' => 'id', 'limit' => $limit]);
    }

    /** Owner correspondence log. Requires OWNER_CONTACT_VIEW. */
    public function ownerThread(Actor $actor, $requestId, $limit = 200)
    {
        Rbac::assert($actor, Rbac::OWNER_CONTACT_VIEW);
        $request = $this->requests->findForActor($actor, $requestId);
        if ($actor->isBroker()) {
            $this->assertBrokerOwnsRequest($actor, $request);
        }
        return Db::fetch('messages', [
            'request_id' => (int) $request['id'],
            'thread' => self::THREAD_OWNER,
            'deleted_at' => null,
        ], ['order' => 'id', 'limit' => $limit]);
    }

    /**
     * Everything the given actor is allowed to see, grouped by thread.
     */
    public function threadsFor(Actor $actor, $requestId)
    {
        $out = ['customer' => $this->customerThread($actor, $requestId)];
        if (Rbac::allows($actor, Rbac::NOTE_INTERNAL_READ)) {
            $out['internal'] = $this->internalThread($actor, $requestId);
        }
        if (Rbac::allows($actor, Rbac::OWNER_CONTACT_VIEW)) {
            $out['owner'] = $this->ownerThread($actor, $requestId);
        }
        return $out;
    }

    public function unreadCount(Actor $actor, $requestId)
    {
        $request = $this->requests->findForActor($actor, $requestId);
        $senderTypes = $actor->isCustomer()
            ? [Actor::TYPE_BROKER, Actor::TYPE_ADMIN, Actor::TYPE_SYSTEM]
            : [Actor::TYPE_CUSTOMER];

        return Db::count('messages', [
            'request_id' => (int) $request['id'],
            'thread' => self::THREAD_CUSTOMER,
            'deleted_at' => null,
            'read_at' => null,
            'sender_type' => ['in', $senderTypes],
        ]);
    }

    /** Mark the counterparty's messages as read. */
    public function markThreadRead(Actor $actor, $requestId)
    {
        $request = $this->requests->findForActor($actor, $requestId);
        $senderTypes = $actor->isCustomer()
            ? [Actor::TYPE_BROKER, Actor::TYPE_ADMIN, Actor::TYPE_SYSTEM]
            : [Actor::TYPE_CUSTOMER];

        return Db::update('messages', [
            'read_at' => Clock::now(),
            'read_by' => Str::clip($actor->identity(), 60),
            'updated_at' => Clock::now(),
        ], [
            'request_id' => (int) $request['id'],
            'thread' => self::THREAD_CUSTOMER,
            'read_at' => null,
            'sender_type' => ['in', $senderTypes],
        ]);
    }

    /**
     * Soft-delete a message. Only an administrator may do this, the row is
     * retained, and the removal is audited with the original body preserved
     * in the audit entry.
     */
    public function softDelete(Actor $actor, $messageId, $reason)
    {
        Rbac::assert($actor, Rbac::STATUS_OVERRIDE);
        $message = Db::first('messages', ['id' => (int) $messageId]);
        if (!$message) {
            throw new NotFoundException('Message not found.');
        }
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A reason is required.', ['reason' => 'Required.']);
        }

        Db::update('messages', [
            'deleted_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $message['id']]);

        Audit::record($actor, 'message.removed', [
            'request_id' => (int) $message['request_id'],
            'entity_type' => 'message',
            'entity_id' => (int) $message['id'],
            'previous' => ['body' => Str::clip($message['body'], 2000), 'thread' => $message['thread']],
            'new' => ['deleted' => true],
            'reason' => $reason,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return true;
    }

    /* ---------------------------------------------------------- helpers */

    protected function notifyCounterparty(Actor $actor, array $request)
    {
        $request = $this->requests->findRow($request['id']);
        if ($actor->isCustomer()) {
            if (!empty($request['assigned_broker_id'])) {
                $this->notifications->notify(
                    NotificationService::MESSAGE_RECEIVED,
                    $request,
                    NotificationService::AUDIENCE_BROKER,
                    ['broker_id' => (int) $request['assigned_broker_id']]
                );
            } else {
                $this->notifications->notify(
                    NotificationService::MESSAGE_RECEIVED,
                    $request,
                    NotificationService::AUDIENCE_ADMIN
                );
            }
            return;
        }
        $this->notifications->notify(
            NotificationService::MESSAGE_RECEIVED,
            $request,
            NotificationService::AUDIENCE_CUSTOMER
        );
    }

    protected function recipientFor(Actor $actor, $thread)
    {
        if ($thread === self::THREAD_OWNER) {
            return 'owner';
        }
        if ($thread === self::THREAD_INTERNAL) {
            return 'internal';
        }
        return $actor->isCustomer() ? Actor::TYPE_BROKER : Actor::TYPE_CUSTOMER;
    }

    protected function assertBrokerOwnsRequest(Actor $actor, array $request)
    {
        if ((int) $request['assigned_broker_id'] !== (int) $actor->brokerId) {
            throw new AuthorizationException('This request is assigned to another broker.');
        }
    }
}
