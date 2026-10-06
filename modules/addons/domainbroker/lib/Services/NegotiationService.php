<?php
/**
 * Domain Broker — negotiation and offers.
 *
 * Offers are immutable. Every round produces a new row; responding to an offer
 * only ever closes it (accepted / rejected / countered / expired) and creates
 * the next one. Amounts, currencies, senders, recipients, expiry and the exact
 * fee snapshot at acceptance are therefore preserved for the life of the
 * record, which is what makes the negotiation ladder auditable.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Db;
use DomainBroker\Core\Idempotency;
use DomainBroker\Core\InvalidTransitionException;
use DomainBroker\Core\Money;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Workflow\OfferStatus;
use DomainBroker\Workflow\RequestStatus;

class NegotiationService
{
    /** @var RequestService */
    protected $requests;

    /** @var FeeService */
    protected $fees;

    /** @var NotificationService */
    protected $notifications;

    /** @var RiskService */
    protected $risk;

    public function __construct(
        RequestService $requests = null,
        FeeService $fees = null,
        NotificationService $notifications = null,
        RiskService $risk = null
    ) {
        $this->requests = $requests ?: new RequestService();
        $this->fees = $fees ?: new FeeService();
        $this->notifications = $notifications ?: new NotificationService();
        $this->risk = $risk ?: new RiskService();
    }

    /* ------------------------------------------------- owner engagement */

    /**
     * The broker records that they have approached the registrant. This opens
     * negotiation round 1 and moves the request to "owner contacted".
     */
    public function recordOwnerContact(Actor $actor, $requestId, array $input)
    {
        Rbac::assert($actor, Rbac::OWNER_CONTACT);
        $request = $this->requests->findForActor($actor, $requestId);
        $this->assertBrokerOwnsRequest($actor, $request);

        if (!in_array($request['status'], [
            RequestStatus::BROKER_ASSIGNED, RequestStatus::OWNER_CONTACTED,
            RequestStatus::NEGOTIATION, RequestStatus::OFFER_RECEIVED, RequestStatus::COUNTEROFFER,
        ], true)) {
            throw new InvalidTransitionException('Owner contact can only be recorded on an assigned, active request.');
        }

        $data = \DomainBroker\Core\Validator::make($input)
            ->in('channel', ['email', 'phone', 'marketplace', 'registrar', 'postal', 'other'], false, 'email')
            ->text('summary', 4000, true, 5)
            ->validate();

        $round = $this->nextRound($request['id']);
        $now = Clock::now();

        $negotiationId = Db::insert('negotiations', [
            'request_id' => (int) $request['id'],
            'round' => $round,
            'status' => 'awaiting_owner',
            'channel' => $data['channel'],
            'initiated_by_type' => $actor->type,
            'initiated_by_id' => (int) $actor->actorId(),
            'summary' => $data['summary'],
            'opened_at' => $now,
            'owner_contacted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($actor, 'negotiation.owner.contacted', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'negotiation',
            'entity_id' => $negotiationId,
            'new' => ['round' => $round, 'channel' => $data['channel']],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        if ($request['status'] === RequestStatus::BROKER_ASSIGNED) {
            $this->requests->transition($actor, $request, RequestStatus::OWNER_CONTACTED, [
                'action' => 'request.owner.contacted',
                'visibility' => Audit::VIS_CUSTOMER,
            ]);
        }

        Db::update('requests', [
            'negotiation_rounds' => $round,
            'last_broker_action_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        return Db::first('negotiations', ['id' => $negotiationId]);
    }

    /** The broker records what the registrant said (free text, no offer). */
    public function recordOwnerResponse(Actor $actor, $negotiationId, array $input)
    {
        Rbac::assert($actor, Rbac::OFFER_RECORD_OWNER);
        $negotiation = Db::first('negotiations', ['id' => (int) $negotiationId]);
        if (!$negotiation) {
            throw new NotFoundException('Negotiation round not found.');
        }
        $request = $this->requests->findForActor($actor, $negotiation['request_id']);
        $this->assertBrokerOwnsRequest($actor, $request);

        $data = \DomainBroker\Core\Validator::make($input)
            ->text('response', 4000, true, 2)
            ->in('outcome', ['countered', 'accepted', 'rejected', 'no_response', 'interested'], false, 'interested')
            ->validate();

        $now = Clock::now();
        Db::update('negotiations', [
            'owner_response' => $data['response'],
            'owner_responded_at' => $now,
            'outcome' => $data['outcome'],
            'status' => 'awaiting_customer',
            'updated_at' => $now,
        ], ['id' => (int) $negotiation['id']]);

        Audit::record($actor, 'negotiation.owner.responded', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'negotiation',
            'entity_id' => (int) $negotiation['id'],
            'new' => ['outcome' => $data['outcome']],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        if ($request['status'] === RequestStatus::OWNER_CONTACTED) {
            $this->requests->transition($actor, $request, RequestStatus::NEGOTIATION, [
                'action' => 'request.negotiation.started',
                'visibility' => Audit::VIS_CUSTOMER,
                'silent' => true,
            ]);
        }

        $this->notifications->notify(
            NotificationService::OWNER_RESPONDED,
            $this->requests->findRow($request['id']),
            NotificationService::AUDIENCE_CUSTOMER
        );

        return Db::first('negotiations', ['id' => (int) $negotiation['id']]);
    }

    /* --------------------------------------------------------- offers -- */

    /**
     * Create an offer.
     *
     * @param array $input amount, currency, direction, message, internal_note,
     *                     expires_in_hours, parent_offer_id, idempotency_key,
     *                     requires_customer_approval
     */
    public function createOffer(Actor $actor, $requestId, array $input)
    {
        $request = $this->requests->findForActor($actor, $requestId);

        $direction = isset($input['direction']) ? (string) $input['direction'] : OfferStatus::DIR_TO_OWNER;
        if (!in_array($direction, OfferStatus::directions(), true)) {
            throw new ValidationException('Invalid offer direction.', ['direction' => 'Invalid direction.']);
        }

        // Who may create what.
        if ($direction === OfferStatus::DIR_TO_OWNER) {
            if ($actor->isCustomer()) {
                Rbac::assert($actor, Rbac::OFFER_COUNTER);
            } else {
                Rbac::assert($actor, Rbac::OFFER_CREATE);
                $this->assertBrokerOwnsRequest($actor, $request);
            }
        } else {
            // Only a broker/admin can record what the registrant offered.
            Rbac::assert($actor, Rbac::OFFER_RECORD_OWNER);
            $this->assertBrokerOwnsRequest($actor, $request);
        }

        if (RequestStatus::isTerminal($request['status'])) {
            throw new ConflictException('This request is closed; no further offers can be made.');
        }
        if (in_array($request['status'], [
            RequestStatus::OFFER_ACCEPTED, RequestStatus::PAYMENT_PENDING, RequestStatus::PAYMENT_SECURED,
            RequestStatus::DOMAIN_TRANSFER, RequestStatus::TRANSFER_VERIFICATION,
        ], true)) {
            throw new ConflictException('Terms have already been agreed for this acquisition.');
        }
        if (!empty($request['manual_review'])) {
            throw new ConflictException('This request is held for manual review.');
        }

        RateLimiter::hit($actor->isCustomer() ? 'offer.action' : 'offer.create', $actor->identity());

        $currency = isset($input['currency']) && $input['currency'] !== ''
            ? strtoupper((string) $input['currency'])
            : $request['currency'];
        if ($currency !== $request['currency']) {
            throw new ValidationException(
                'Offers must be made in the request currency (' . $request['currency'] . ').',
                ['currency' => 'Currency mismatch.']
            );
        }

        $data = \DomainBroker\Core\Validator::make(array_merge($input, ['currency' => $currency]))
            ->money('amount', $currency, 1, Settings::int('max_budget_minor', 0), true)
            ->text('message', 2000, false)
            ->text('internal_note', 2000, false)
            ->integer('expires_in_hours', 1, 24 * 90, false, Settings::int('offer_validity_hours', 120))
            ->validate();

        $maxRounds = Settings::int('max_negotiation_rounds', 20);
        $sequence = Db::count('offers', ['request_id' => (int) $request['id']]) + 1;
        if ($maxRounds > 0 && $sequence > $maxRounds * 2) {
            throw new ConflictException('This negotiation has reached the configured maximum number of rounds.');
        }

        $parent = null;
        if (!empty($input['parent_offer_id'])) {
            $parent = Db::first('offers', ['id' => (int) $input['parent_offer_id'], 'request_id' => (int) $request['id']]);
            if (!$parent) {
                throw new NotFoundException('The offer being answered does not exist on this request.');
            }
            if (!OfferStatus::isOpen($parent['status'])) {
                throw new ConflictException('That offer is no longer open.');
            }
        }

        // Budget guard for anything travelling to the registrant.
        if ($direction === OfferStatus::DIR_TO_OWNER) {
            $this->assertWithinBudget($request, (int) $data['amount']);
        }

        $idempotencyKey = isset($input['idempotency_key']) && $input['idempotency_key'] !== ''
            ? (string) $input['idempotency_key']
            : Idempotency::deriveKey('offer.create', [
                $request['id'], $actor->identity(), $direction, $data['amount'], $sequence,
            ]);

        $self = $this;
        $outcome = Idempotency::run(
            'offer.create',
            $idempotencyKey,
            [
                'request' => (int) $request['id'], 'direction' => $direction,
                'amount' => (int) $data['amount'], 'sequence' => $sequence,
            ],
            function () use ($self, $actor, $request, $data, $currency, $direction, $parent, $sequence, $input) {
                return $self->persistOffer($actor, $request, $data, $currency, $direction, $parent, $sequence, $input);
            }
        );

        $offer = Db::first('offers', ['id' => (int) $outcome['result']['id']]);
        if ($outcome['replayed']) {
            return $offer;
        }

        $this->afterOfferCreated($actor, $this->requests->findRow($request['id']), $offer, $parent);

        // Velocity check after the write so the rule can see the new row.
        $this->risk->evaluate($this->requests->findRow($request['id']));

        return Db::first('offers', ['id' => (int) $offer['id']]);
    }

    /** @internal */
    public function persistOffer(
        Actor $actor,
        array $request,
        array $data,
        $currency,
        $direction,
        $parent,
        $sequence,
        array $input
    ) {
        $now = Clock::now();
        $negotiation = $this->currentNegotiation($request['id']);
        $round = $negotiation ? (int) $negotiation['round'] : 1;

        if ($direction === OfferStatus::DIR_TO_OWNER) {
            $sender = $actor->isCustomer() ? OfferStatus::PARTY_CUSTOMER : OfferStatus::PARTY_BROKER;
            $recipient = OfferStatus::PARTY_OWNER;
        } else {
            $sender = OfferStatus::PARTY_OWNER;
            $recipient = OfferStatus::PARTY_CUSTOMER;
        }

        $requiresApproval = $direction === OfferStatus::DIR_TO_CUSTOMER
            ? true
            : !empty($input['requires_customer_approval']);

        $expiresInHours = (int) $data['expires_in_hours'];

        $offerId = Db::insert('offers', [
            'reference' => $this->uniqueOfferReference(),
            'request_id' => (int) $request['id'],
            'negotiation_id' => $negotiation ? (int) $negotiation['id'] : null,
            'parent_offer_id' => $parent ? (int) $parent['id'] : null,
            'round' => $round,
            'sequence' => (int) $sequence,
            'amount_minor' => (int) $data['amount'],
            'currency' => $currency,
            'direction' => $direction,
            'sender_party' => $sender,
            'recipient_party' => $recipient,
            'created_by_type' => $actor->type,
            'created_by_id' => (int) $actor->actorId(),
            'created_by_label' => Str::clip($actor->name, 190),
            'is_counteroffer' => $parent ? 1 : 0,
            'requires_customer_approval' => $requiresApproval ? 1 : 0,
            'status' => OfferStatus::PENDING,
            'message' => isset($data['message']) ? $data['message'] : null,
            'internal_note' => isset($data['internal_note']) && $data['internal_note'] !== '' && !$actor->isCustomer()
                ? $data['internal_note'] : null,
            'expires_at' => Clock::inHours($expiresInHours),
            'fee_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Close the parent: countered, never overwritten.
        if ($parent) {
            Db::update('offers', [
                'status' => OfferStatus::COUNTERED,
                'responded_at' => $now,
                'responded_by_type' => $actor->type,
                'responded_by_id' => (int) $actor->actorId(),
                'updated_at' => $now,
            ], ['id' => (int) $parent['id']]);
        }

        // Supersede any other still-open offer travelling the same way.
        Db::update('offers', [
            'status' => OfferStatus::SUPERSEDED,
            'responded_at' => $now,
            'updated_at' => $now,
        ], [
            'request_id' => (int) $request['id'],
            'direction' => $direction,
            'status' => OfferStatus::PENDING,
            'id' => ['!=', $offerId],
        ]);

        return ['id' => $offerId];
    }

    protected function afterOfferCreated(Actor $actor, array $request, array $offer, $parent)
    {
        $now = Clock::now();
        $updates = [
            'current_offer_id' => (int) $offer['id'],
            'updated_at' => $now,
        ];
        if ($offer['direction'] === OfferStatus::DIR_TO_OWNER) {
            $updates['latest_customer_offer_id'] = (int) $offer['id'];
            $updates['last_broker_action_at'] = $now;
            if ($actor->isCustomer()) {
                $updates['last_customer_action_at'] = $now;
            }
        }
        Db::update('requests', $updates, ['id' => (int) $request['id']]);

        Audit::record($actor, $parent ? 'offer.counter.created' : 'offer.created', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'offer',
            'entity_id' => (int) $offer['id'],
            'previous' => $parent ? ['offer_id' => (int) $parent['id'], 'amount_minor' => (int) $parent['amount_minor']] : null,
            'new' => [
                'offer_id' => (int) $offer['id'],
                'reference' => $offer['reference'],
                'amount_minor' => (int) $offer['amount_minor'],
                'currency' => $offer['currency'],
                'direction' => $offer['direction'],
                'expires_at' => $offer['expires_at'],
            ],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $request = $this->requests->findRow($request['id']);

        // Reflect the negotiation state on the request.
        $target = null;
        if ($offer['direction'] === OfferStatus::DIR_TO_CUSTOMER) {
            $target = RequestStatus::OFFER_RECEIVED;
        } elseif ($parent) {
            $target = RequestStatus::COUNTEROFFER;
        } elseif (in_array($request['status'], [RequestStatus::OWNER_CONTACTED, RequestStatus::BROKER_ASSIGNED], true)) {
            $target = RequestStatus::NEGOTIATION;
        }

        if ($target && RequestStatus::canTransition($request['status'], $target)) {
            $this->requests->transition($actor, $request, $target, [
                'action' => $target === RequestStatus::OFFER_RECEIVED ? 'request.offer.received' : 'request.counteroffer.sent',
                'visibility' => Audit::VIS_CUSTOMER,
                'silent' => true,
            ]);
            $request = $this->requests->findRow($request['id']);
        }

        // Notify the party who must now act.
        if ($offer['direction'] === OfferStatus::DIR_TO_CUSTOMER) {
            $this->notifications->notify(
                $parent ? NotificationService::COUNTEROFFER_RECEIVED : NotificationService::OFFER_RECEIVED,
                $request,
                NotificationService::AUDIENCE_CUSTOMER,
                ['payload' => [
                    'offer_reference' => $offer['reference'],
                    'amount' => Money::toDecimalString($offer['amount_minor'], $offer['currency']),
                    'currency' => $offer['currency'],
                ]]
            );
        } elseif ($actor->isCustomer() && !empty($request['assigned_broker_id'])) {
            $this->notifications->notify(
                NotificationService::COUNTEROFFER_RECEIVED,
                $request,
                NotificationService::AUDIENCE_BROKER,
                [
                    'broker_id' => (int) $request['assigned_broker_id'],
                    'subject' => 'Customer counteroffer on ' . $request['domain'],
                ]
            );
        }
    }

    /**
     * Counteroffer helper — a new offer answering an existing one.
     */
    public function counterOffer(Actor $actor, $offerId, array $input)
    {
        $parent = $this->findOffer($offerId);
        $request = $this->requests->findForActor($actor, $parent['request_id']);

        if (!OfferStatus::isOpen($parent['status'])) {
            throw new ConflictException('That offer is no longer open to a counteroffer.');
        }

        // A counteroffer travels back the way the parent came.
        $direction = $parent['direction'] === OfferStatus::DIR_TO_CUSTOMER
            ? OfferStatus::DIR_TO_OWNER
            : OfferStatus::DIR_TO_CUSTOMER;

        // Opening a new round keeps the ladder readable.
        $this->openNextRound($actor, $request);

        return $this->createOffer($actor, $request['id'], array_merge($input, [
            'direction' => $direction,
            'parent_offer_id' => (int) $parent['id'],
        ]));
    }

    /* ------------------------------------------------- accept / reject -- */

    public function acceptOffer(Actor $actor, $offerId, array $options = [])
    {
        $offer = $this->findOffer($offerId);
        $request = $this->requests->findForActor($actor, $offer['request_id']);

        if (!OfferStatus::isOpen($offer['status'])) {
            throw new ConflictException('This offer can no longer be accepted (' . OfferStatus::label($offer['status']) . ').');
        }
        if (Clock::isPast($offer['expires_at'])) {
            $this->expireOffer($offer);
            throw new ConflictException('This offer has expired.');
        }

        // Only the recipient side may accept.
        if ($offer['direction'] === OfferStatus::DIR_TO_CUSTOMER) {
            if (!$actor->isCustomer() && !$actor->isAdmin()) {
                throw new \DomainBroker\Core\AuthorizationException('Only the customer can accept an offer made to them.');
            }
            Rbac::assert($actor, $actor->isCustomer() ? Rbac::OFFER_RESPOND : Rbac::STATUS_OVERRIDE);
        } else {
            // Owner accepted — recorded by the broker.
            Rbac::assert($actor, Rbac::OFFER_RECORD_OWNER);
            $this->assertBrokerOwnsRequest($actor, $request);
        }

        RateLimiter::hit('offer.action', $actor->identity());

        $quote = $this->fees->quote(
            (int) $offer['amount_minor'],
            $offer['currency'],
            $request['domain'],
            isset($options['promo_code']) ? $options['promo_code'] : null
        );

        // Hard budget guard: the total the customer will owe must still fit
        // inside the budget they authorised, unless they explicitly raise it.
        if ($actor->isCustomer() && !empty($request['budget_includes_fees'])
            && $quote['total_minor'] > (int) $request['budget_minor']
            && empty($options['confirm_over_budget'])) {
            throw new ConflictException(
                'Accepting this offer would cost '
                . Money::toDecimalString($quote['total_minor'], $offer['currency']) . ' ' . $offer['currency']
                . ' including fees, which exceeds your stated budget. Raise your budget to continue.',
                ['total_minor' => $quote['total_minor'], 'budget_minor' => (int) $request['budget_minor']]
            );
        }

        $now = Clock::now();

        return Db::transaction(function () use ($actor, $offer, $request, $quote, $now) {
            Db::update('offers', [
                'status' => OfferStatus::ACCEPTED,
                'accepted_at' => $now,
                'responded_at' => $now,
                'responded_by_type' => $actor->type,
                'responded_by_id' => (int) $actor->actorId(),
                'fee_minor' => $quote['fee_minor'],
                'tax_minor' => $quote['tax_minor'],
                'total_minor' => $quote['total_minor'],
                'fee_rule_id' => $quote['rule_id'],
                'updated_at' => $now,
            ], ['id' => (int) $offer['id']]);

            // Every other open offer on this request is closed out.
            Db::update('offers', [
                'status' => OfferStatus::SUPERSEDED,
                'responded_at' => $now,
                'updated_at' => $now,
            ], [
                'request_id' => (int) $request['id'],
                'status' => OfferStatus::PENDING,
            ]);

            Db::update('negotiations', [
                'status' => 'closed',
                'outcome' => 'accepted',
                'closing_offer_id' => (int) $offer['id'],
                'closed_at' => $now,
                'updated_at' => $now,
            ], ['request_id' => (int) $request['id'], 'status' => ['notin', ['closed']]]);

            Db::update('requests', [
                'agreed_offer_id' => (int) $offer['id'],
                'current_offer_id' => (int) $offer['id'],
                'agreed_amount_minor' => (int) $offer['amount_minor'],
                'broker_fee_minor' => $quote['fee_minor'],
                'tax_minor' => $quote['tax_minor'],
                'total_minor' => $quote['total_minor'],
                'fee_rule_id' => $quote['rule_id'],
                'kyc_required' => $this->requests->kycRequired((int) $offer['amount_minor']) ? 1 : 0,
                'updated_at' => $now,
            ], ['id' => (int) $request['id']]);

            Audit::record($actor, 'offer.accepted', [
                'request_id' => (int) $request['id'],
                'entity_type' => 'offer',
                'entity_id' => (int) $offer['id'],
                'previous' => ['status' => $offer['status']],
                'new' => [
                    'status' => OfferStatus::ACCEPTED,
                    'amount_minor' => (int) $offer['amount_minor'],
                    'fee_minor' => $quote['fee_minor'],
                    'tax_minor' => $quote['tax_minor'],
                    'total_minor' => $quote['total_minor'],
                    'fee_rule_id' => $quote['rule_id'],
                ],
                'visibility' => Audit::VIS_CUSTOMER,
            ]);

            $fresh = $this->requests->findRow($request['id']);
            $fresh = $this->requests->transition($actor, $fresh, RequestStatus::OFFER_ACCEPTED, [
                'action' => 'request.offer.accepted',
                'visibility' => Audit::VIS_CUSTOMER,
            ]);

            // Prepare the verification checklist now that a deal exists.
            (new VerificationService())->bootstrap($actor, $fresh);

            return Db::first('offers', ['id' => (int) $offer['id']]);
        });
    }

    public function rejectOffer(Actor $actor, $offerId, $reason = '')
    {
        $offer = $this->findOffer($offerId);
        $request = $this->requests->findForActor($actor, $offer['request_id']);

        if (!OfferStatus::isOpen($offer['status'])) {
            throw new ConflictException('This offer can no longer be rejected.');
        }

        if ($offer['direction'] === OfferStatus::DIR_TO_CUSTOMER) {
            Rbac::assert($actor, $actor->isCustomer() ? Rbac::OFFER_RESPOND : Rbac::OFFER_RECORD_OWNER);
        } else {
            Rbac::assert($actor, Rbac::OFFER_RECORD_OWNER);
            $this->assertBrokerOwnsRequest($actor, $request);
        }

        RateLimiter::hit('offer.action', $actor->identity());

        $now = Clock::now();
        Db::update('offers', [
            'status' => OfferStatus::REJECTED,
            'rejected_at' => $now,
            'responded_at' => $now,
            'responded_by_type' => $actor->type,
            'responded_by_id' => (int) $actor->actorId(),
            'rejection_reason' => Str::cleanText($reason, 1000),
            'updated_at' => $now,
        ], ['id' => (int) $offer['id']]);

        Audit::record($actor, 'offer.rejected', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'offer',
            'entity_id' => (int) $offer['id'],
            'previous' => ['status' => $offer['status']],
            'new' => ['status' => OfferStatus::REJECTED],
            'reason' => $reason,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        // Rejection puts the request back into live negotiation.
        $fresh = $this->requests->findRow($request['id']);
        if (RequestStatus::canTransition($fresh['status'], RequestStatus::NEGOTIATION)) {
            $this->requests->transition($actor, $fresh, RequestStatus::NEGOTIATION, [
                'action' => 'request.negotiation.resumed',
                'visibility' => Audit::VIS_CUSTOMER,
                'silent' => true,
            ]);
        }

        $audience = $offer['direction'] === OfferStatus::DIR_TO_CUSTOMER
            ? NotificationService::AUDIENCE_BROKER
            : NotificationService::AUDIENCE_CUSTOMER;
        $this->notifications->notify(
            NotificationService::OFFER_REJECTED,
            $this->requests->findRow($request['id']),
            $audience,
            $audience === NotificationService::AUDIENCE_BROKER
                ? ['broker_id' => (int) $request['assigned_broker_id']]
                : []
        );

        return Db::first('offers', ['id' => (int) $offer['id']]);
    }

    /** The sender pulls an offer back before it is answered. */
    public function withdrawOffer(Actor $actor, $offerId, $reason = '')
    {
        $offer = $this->findOffer($offerId);
        $request = $this->requests->findForActor($actor, $offer['request_id']);

        if (!OfferStatus::isOpen($offer['status'])) {
            throw new ConflictException('Only an open offer can be withdrawn.');
        }
        $isSender = ($actor->isCustomer() && $offer['created_by_type'] === Actor::TYPE_CUSTOMER
                && (int) $offer['created_by_id'] === (int) $actor->clientId)
            || ($actor->isBroker() && $offer['created_by_type'] === Actor::TYPE_BROKER
                && (int) $offer['created_by_id'] === (int) $actor->brokerId)
            || $actor->isAdmin();
        if (!$isSender) {
            throw new \DomainBroker\Core\AuthorizationException('Only the sender can withdraw an offer.');
        }

        $now = Clock::now();
        Db::update('offers', [
            'status' => OfferStatus::WITHDRAWN,
            'responded_at' => $now,
            'responded_by_type' => $actor->type,
            'responded_by_id' => (int) $actor->actorId(),
            'rejection_reason' => Str::cleanText($reason, 1000),
            'updated_at' => $now,
        ], ['id' => (int) $offer['id']]);

        Audit::record($actor, 'offer.withdrawn', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'offer',
            'entity_id' => (int) $offer['id'],
            'previous' => ['status' => $offer['status']],
            'new' => ['status' => OfferStatus::WITHDRAWN],
            'reason' => $reason,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        return Db::first('offers', ['id' => (int) $offer['id']]);
    }

    /* ---------------------------------------------------------- expiry -- */

    /** Expire offers past their validity window (module cron). */
    public function expireOffers($limit = 200)
    {
        $rows = Db::fetch('offers', [
            'status' => OfferStatus::PENDING,
            'expires_at' => ['<=', Clock::now()],
        ], ['order' => 'id', 'limit' => $limit]);

        $count = 0;
        foreach ($rows as $row) {
            $this->expireOffer($row);
            $count++;
        }
        return $count;
    }

    protected function expireOffer(array $offer)
    {
        $actor = Actor::system('Scheduled task');
        $now = Clock::now();
        Db::update('offers', [
            'status' => OfferStatus::EXPIRED,
            'responded_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $offer['id'], 'status' => OfferStatus::PENDING]);

        Audit::record($actor, 'offer.expired', [
            'request_id' => (int) $offer['request_id'],
            'entity_type' => 'offer',
            'entity_id' => (int) $offer['id'],
            'previous' => ['status' => OfferStatus::PENDING],
            'new' => ['status' => OfferStatus::EXPIRED],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $request = $this->requests->findRow($offer['request_id']);
        if ($request) {
            $this->notifications->notify(
                NotificationService::OFFER_EXPIRED,
                $request,
                NotificationService::AUDIENCE_CUSTOMER
            );
        }
        return true;
    }

    /* --------------------------------------------------------- queries -- */

    public function findOffer($offerId)
    {
        $offer = Db::first('offers', ['id' => (int) $offerId]);
        if (!$offer) {
            throw new NotFoundException('Offer not found.');
        }
        return $offer;
    }

    /** Full ladder for a request, newest last. */
    public function offersFor($requestId, $audience = 'customer')
    {
        $rows = Db::fetch('offers', ['request_id' => (int) $requestId], ['order' => 'sequence', 'dir' => 'asc']);
        if ($audience === 'customer') {
            foreach ($rows as &$row) {
                unset($row['internal_note']);
            }
            unset($row);
        }
        return $rows;
    }

    /** The offer the customer must currently respond to, if any. */
    public function pendingCustomerOffer($requestId)
    {
        return Db::first('offers', [
            'request_id' => (int) $requestId,
            'direction' => OfferStatus::DIR_TO_CUSTOMER,
            'status' => OfferStatus::PENDING,
        ], ['order' => 'id', 'dir' => 'desc']);
    }

    public function pendingOwnerOffer($requestId)
    {
        return Db::first('offers', [
            'request_id' => (int) $requestId,
            'direction' => OfferStatus::DIR_TO_OWNER,
            'status' => OfferStatus::PENDING,
        ], ['order' => 'id', 'dir' => 'desc']);
    }

    public function negotiationsFor($requestId)
    {
        return Db::fetch('negotiations', ['request_id' => (int) $requestId], ['order' => 'round']);
    }

    public function currentNegotiation($requestId)
    {
        return Db::first('negotiations', ['request_id' => (int) $requestId], ['order' => 'round', 'dir' => 'desc']);
    }

    /* --------------------------------------------------------- helpers -- */

    protected function openNextRound(Actor $actor, array $request)
    {
        $current = $this->currentNegotiation($request['id']);
        if (!$current) {
            return null;
        }
        if ($current['status'] === 'closed') {
            return null;
        }
        $round = (int) $current['round'] + 1;
        $maxRounds = Settings::int('max_negotiation_rounds', 20);
        if ($maxRounds > 0 && $round > $maxRounds) {
            throw new ConflictException('This negotiation has reached the configured maximum number of rounds.');
        }

        $now = Clock::now();
        Db::update('negotiations', [
            'status' => 'closed', 'closed_at' => $now, 'updated_at' => $now,
        ], ['id' => (int) $current['id']]);

        $id = Db::insert('negotiations', [
            'request_id' => (int) $request['id'],
            'round' => $round,
            'status' => 'open',
            'channel' => $current['channel'],
            'initiated_by_type' => $actor->type,
            'initiated_by_id' => (int) $actor->actorId(),
            'summary' => null,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Db::update('requests', [
            'negotiation_rounds' => $round, 'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        return Db::first('negotiations', ['id' => $id]);
    }

    protected function nextRound($requestId)
    {
        $current = $this->currentNegotiation($requestId);
        return $current ? (int) $current['round'] + 1 : 1;
    }

    /**
     * Nothing may be offered to the registrant above what the customer
     * authorised. When the budget is inclusive of fees, the ceiling is the
     * largest acquisition price whose total still fits.
     */
    protected function assertWithinBudget(array $request, $amountMinor)
    {
        $budget = (int) $request['budget_minor'];
        if ($budget <= 0) {
            return;
        }
        if (!empty($request['budget_includes_fees'])) {
            $ceiling = $this->fees->maxAcquisitionWithinBudget($budget, $request['currency'], $request['domain']);
            if ($amountMinor > $ceiling) {
                throw new ValidationException(
                    'With fees included, the most that can be offered within this budget is '
                    . Money::toDecimalString($ceiling, $request['currency']) . ' ' . $request['currency'] . '.',
                    ['amount' => 'Exceeds the authorised budget.']
                );
            }
            return;
        }
        if ($amountMinor > $budget) {
            throw new ValidationException(
                'The offer exceeds the customer\'s authorised budget of '
                . Money::toDecimalString($budget, $request['currency']) . ' ' . $request['currency'] . '.',
                ['amount' => 'Exceeds the authorised budget.']
            );
        }
    }

    protected function assertBrokerOwnsRequest(Actor $actor, array $request)
    {
        if (!$actor->isBroker()) {
            return; // admins are governed by their own permissions
        }
        if ((int) $request['assigned_broker_id'] !== (int) $actor->brokerId) {
            throw new \DomainBroker\Core\AuthorizationException('This request is assigned to another broker.');
        }
    }

    protected function uniqueOfferReference()
    {
        for ($i = 0; $i < 25; $i++) {
            $ref = Str::reference('OF', 8);
            if (Db::count('offers', ['reference' => $ref]) === 0) {
                return $ref;
            }
        }
        throw new ConflictException('Unable to allocate an offer reference.');
    }
}
