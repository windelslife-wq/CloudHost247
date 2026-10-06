<?php
/**
 * Domain Broker — acquisition requests.
 *
 * The single place where a request's `status` column may change. Every write
 * validates the transition against RequestStatus, enforces the workflow
 * invariants that are *not* permissions (funds before transfer, verification
 * before completion), appends to the immutable activity log and fans out
 * notifications. Callers pass an intent; they never pass a status they expect
 * to be written verbatim.
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
use DomainBroker\Core\DomainName;
use DomainBroker\Core\Http;
use DomainBroker\Core\Idempotency;
use DomainBroker\Core\InvalidTransitionException;
use DomainBroker\Core\Money;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Integration\Gateway;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;
use DomainBroker\Workflow\TransferStatus;

class RequestService
{
    /** @var FeeService */
    protected $fees;

    /** @var NotificationService */
    protected $notifications;

    /** @var RiskService */
    protected $risk;

    /** @var AssignmentService|null */
    protected $assignments;

    public function __construct(
        FeeService $fees = null,
        NotificationService $notifications = null,
        RiskService $risk = null
    ) {
        $this->fees = $fees ?: new FeeService();
        $this->notifications = $notifications ?: new NotificationService();
        $this->risk = $risk ?: new RiskService();
    }

    /* ------------------------------------------------------------ create */

    /**
     * Submit an acquisition request.
     *
     * @param Actor $actor    the customer (or an admin acting on their behalf)
     * @param array $input    domain, budget, currency, budget_includes_fees,
     *                        message, anonymous, idempotency_key
     * @return array the created request row
     */
    public function create(Actor $actor, array $input)
    {
        Rbac::assert($actor, Rbac::REQUEST_CREATE);

        $clientId = $this->resolveClientId($actor, $input);
        if ($clientId <= 0) {
            throw new ValidationException('A client account is required.', ['client_id' => 'Unknown client.']);
        }

        RateLimiter::hit('request.create', $actor->identity());

        $currencies = $this->allowedCurrencies($clientId);
        $defaultCurrency = $currencies ? $currencies[0] : 'USD';

        $currency = strtoupper(trim((string) (isset($input['currency']) ? $input['currency'] : $defaultCurrency)));
        if ($currency === '') {
            $currency = $defaultCurrency;
        }

        $data = \DomainBroker\Core\Validator::make(array_merge($input, ['currency' => $currency]))
            ->domain('domain', true)
            ->currency('currency', $currencies, true)
            ->money(
                'budget',
                $currency,
                Settings::int('min_budget_minor', 0),
                Settings::int('max_budget_minor', 0),
                true
            )
            ->boolean('budget_includes_fees', false)
            ->boolean('anonymous', Settings::bool('anonymous_default', true))
            ->text('message', 4000, false)
            ->validate();

        $domain = DomainName::registrable($data['domain']);

        // Idempotency comes first: a double-submitted form must replay the
        // original request rather than being told it is a duplicate of
        // itself. The duplicate guard lives inside the idempotent operation,
        // so a *genuinely* new submission for the same domain still conflicts.
        $idempotencyKey = isset($input['idempotency_key']) && $input['idempotency_key'] !== ''
            ? (string) $input['idempotency_key']
            : Idempotency::deriveKey('request.create', [$clientId, $domain, $data['budget'], $currency]);

        $service = $this;
        $outcome = Idempotency::run(
            'request.create',
            $idempotencyKey,
            ['client' => $clientId, 'domain' => $domain, 'budget' => $data['budget'], 'currency' => $currency],
            function () use ($service, $actor, $clientId, $domain, $data, $currency, $input) {
                return $service->persistNewRequest($actor, $clientId, $domain, $data, $currency, $input);
            }
        );

        $requestId = (int) $outcome['result']['id'];
        $request = $this->findRow($requestId);

        if ($outcome['replayed']) {
            return $request;
        }

        // Risk evaluation happens after persistence so the rules can see the
        // row, and its outcome can gate the workflow.
        $assessment = $this->risk->evaluate($request);
        $updates = [
            'risk_score' => $assessment['score'],
            'risk_level' => $assessment['level'],
            'manual_review' => $assessment['review'] ? 1 : 0,
            'updated_at' => Clock::now(),
        ];
        Db::update('requests', $updates, ['id' => $requestId]);
        $request = array_merge($request, $updates);

        if ($assessment['review']) {
            Audit::record($actor, 'risk.manual_review.flagged', [
                'request_id' => $requestId,
                'new' => ['score' => $assessment['score'], 'level' => $assessment['level']],
                'visibility' => Audit::VIS_INTERNAL,
            ]);
            $this->notifications->notify(
                NotificationService::RISK_FLAGGED,
                $request,
                NotificationService::AUDIENCE_ADMIN,
                ['payload' => ['score' => $assessment['score'], 'flags' => array_column($assessment['flags'], 'rule')]]
            );
        }

        $this->notifications->notify(NotificationService::REQUEST_SUBMITTED, $request, NotificationService::AUDIENCE_CUSTOMER);

        if (Settings::bool('create_support_ticket', false)) {
            $this->openSupportTicket($request);
        }

        // Automatic triage: move straight to review unless risk blocks it.
        if (!$assessment['block']) {
            $this->transition(Actor::system(), $request, RequestStatus::UNDER_REVIEW, [
                'action' => 'request.review.started',
                'visibility' => Audit::VIS_CUSTOMER,
                'system' => true,
            ]);
            $request = $this->findRow($requestId);

            if (Settings::bool('auto_assign_brokers', false)) {
                try {
                    $assignments = $this->assignmentService();
                    $assignments->autoAssign(Actor::system(), $request);
                    $request = $this->findRow($requestId);
                } catch (\Throwable $e) {
                    \DomainBroker\Core\Logger::warning('Auto-assignment skipped', ['error' => $e->getMessage()]);
                }
            }
        }

        return $request;
    }

    /** @internal called through Idempotency::run */
    public function persistNewRequest(Actor $actor, $clientId, $domain, array $data, $currency, array $input)
    {
        // A customer may not hold two live requests for the same domain.
        $duplicate = Db::first('requests', [
            'client_id' => $clientId,
            'domain' => $domain,
            'status' => ['notin', RequestStatus::TERMINAL],
            'deleted_at' => null,
        ]);
        if ($duplicate) {
            throw new ConflictException(
                'You already have an open acquisition request for ' . $domain . ' (' . $duplicate['reference'] . ').',
                ['request_id' => (int) $duplicate['id'], 'reference' => $duplicate['reference']]
            );
        }

        $now = Clock::now();
        $expiryDays = Settings::int('request_expiry_days', 90);

        $id = Db::insert('requests', [
            'reference'   => $this->uniqueReference(),
            'client_id'   => (int) $clientId,
            'contact_id'  => isset($input['contact_id']) && $input['contact_id'] ? (int) $input['contact_id'] : null,
            'whmcs_user_id' => isset($input['whmcs_user_id']) && $input['whmcs_user_id'] ? (int) $input['whmcs_user_id'] : null,
            'domain'      => $domain,
            'domain_display' => DomainName::toUnicode($domain),
            'tld'         => DomainName::tld($domain),
            'sld'         => DomainName::sld($domain),
            'status'      => RequestStatus::SUBMITTED,
            'previous_status' => null,
            'status_changed_at' => $now,
            'budget_minor' => (int) $data['budget'],
            'currency'    => $currency,
            'budget_includes_fees' => !empty($data['budget_includes_fees']) ? 1 : 0,
            'anonymous'   => !empty($data['anonymous']) ? 1 : 0,
            'customer_message' => isset($data['message']) ? $data['message'] : null,
            'source'      => $actor->isAdmin() ? 'admin' : (isset($input['source']) ? (string) $input['source'] : 'client_area'),
            'assigned_broker_id' => null,
            'negotiation_rounds' => 0,
            'agreed_amount_minor' => 0,
            'broker_fee_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 0,
            'payment_status' => PaymentStatus::NONE,
            'transfer_status' => TransferStatus::NOT_STARTED,
            'verification_status' => 'not_started',
            'risk_score' => 0,
            'risk_level' => 'low',
            'manual_review' => 0,
            'kyc_required' => $this->kycRequired((int) $data['budget']) ? 1 : 0,
            'submitted_at' => $now,
            'expires_at' => $expiryDays > 0 ? Clock::inDays($expiryDays) : null,
            'last_customer_action_at' => $now,
            'ip_address' => $actor->ip ?: Http::clientIp(),
            'user_agent' => $actor->userAgent ?: Http::userAgent(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($actor, 'request.created', [
            'request_id' => $id,
            'entity_type' => 'request',
            'entity_id' => $id,
            'new' => [
                'domain' => $domain,
                'budget_minor' => (int) $data['budget'],
                'currency' => $currency,
                'anonymous' => !empty($data['anonymous']),
                'budget_includes_fees' => !empty($data['budget_includes_fees']),
            ],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        Gateway::get()->logActivity(
            'Domain Broker request created for ' . $domain,
            (int) $clientId
        );

        return ['id' => $id];
    }

    /* -------------------------------------------------------- retrieval */

    public function findRow($requestId)
    {
        return Db::first('requests', ['id' => (int) $requestId]);
    }

    public function findByReference($reference)
    {
        return Db::first('requests', ['reference' => (string) $reference, 'deleted_at' => null]);
    }

    /**
     * Load a request and assert the actor may see it.
     *
     * @throws NotFoundException|AuthorizationException
     */
    public function findForActor(Actor $actor, $requestId)
    {
        $request = $this->findRow($requestId);
        if (!$request || $request['deleted_at'] !== null) {
            throw new NotFoundException('Acquisition request not found.');
        }
        $this->assertCanView($actor, $request);
        return $request;
    }

    public function assertCanView(Actor $actor, array $request)
    {
        if ($actor->isCustomer()) {
            if ((int) $request['client_id'] !== (int) $actor->clientId) {
                // Do not disclose existence to a different customer.
                throw new NotFoundException('Acquisition request not found.');
            }
            Rbac::assert($actor, Rbac::REQUEST_VIEW_OWN);
            return true;
        }
        if ($actor->isBroker()) {
            if ((int) $request['assigned_broker_id'] === (int) $actor->brokerId) {
                Rbac::assert($actor, Rbac::REQUEST_VIEW_ASSIGNED);
                return true;
            }
            if (Rbac::allows($actor, Rbac::REQUEST_VIEW_ALL)) {
                return true;
            }
            // Unassigned work is visible in the queue only while unassigned.
            if ($request['assigned_broker_id'] === null && Rbac::allows($actor, Rbac::REQUEST_VIEW_QUEUE)) {
                return true;
            }
            throw new AuthorizationException('This request is assigned to another broker.');
        }
        if ($actor->isAdmin()) {
            Rbac::assert($actor, Rbac::REQUEST_VIEW_ALL);
            return true;
        }
        if ($actor->isSystem()) {
            return true;
        }
        throw new AuthorizationException('Authentication is required.');
    }

    /**
     * List requests visible to the actor.
     *
     * @param array $filters status, statuses, domain, client_id, broker_id,
     *                       from, to, risk, payment_status, transfer_status,
     *                       search, limit, offset, order, dir
     */
    public function listForActor(Actor $actor, array $filters = [])
    {
        $where = ['deleted_at' => null];

        if ($actor->isCustomer()) {
            Rbac::assert($actor, Rbac::REQUEST_VIEW_OWN);
            $where['client_id'] = (int) $actor->clientId;
        } elseif ($actor->isBroker()) {
            if (!empty($filters['scope']) && $filters['scope'] === 'queue') {
                Rbac::assert($actor, Rbac::REQUEST_VIEW_QUEUE);
                $where['assigned_broker_id'] = null;
                $where['status'] = ['in', [RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW]];
            } elseif (Rbac::allows($actor, Rbac::REQUEST_VIEW_ALL) && !empty($filters['scope']) && $filters['scope'] === 'all') {
                // broker_lead viewing everything
            } else {
                Rbac::assert($actor, Rbac::REQUEST_VIEW_ASSIGNED);
                $where['assigned_broker_id'] = (int) $actor->brokerId;
            }
        } elseif ($actor->isAdmin()) {
            Rbac::assert($actor, Rbac::REQUEST_VIEW_ALL);
            if (!empty($filters['client_id'])) {
                $where['client_id'] = (int) $filters['client_id'];
            }
            if (!empty($filters['broker_id'])) {
                $where['assigned_broker_id'] = (int) $filters['broker_id'];
            }
        } else {
            throw new AuthorizationException('Authentication is required.');
        }

        if (!empty($filters['status'])) {
            $where['status'] = $filters['status'];
        }
        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $where['status'] = ['in', $filters['statuses']];
        }
        if (!empty($filters['payment_status'])) {
            $where['payment_status'] = $filters['payment_status'];
        }
        if (!empty($filters['transfer_status'])) {
            $where['transfer_status'] = $filters['transfer_status'];
        }
        if (!empty($filters['domain'])) {
            $normalised = DomainName::normalise($filters['domain']);
            $where['domain'] = $normalised ?: ['like', '%' . $this->escapeLike($filters['domain']) . '%'];
        }
        if (!empty($filters['manual_review'])) {
            $where['manual_review'] = 1;
        }

        // Free-text search and date ranges need OR / BETWEEN, which the
        // array builder deliberately does not express, so they are appended
        // as parameterised fragments.
        $extra = [];
        $extraBind = [];

        if (!empty($filters['search'])) {
            $like = '%' . $this->escapeLike(trim((string) $filters['search'])) . '%';
            $extra[] = '(' . Db::quoteIdentifier('reference') . " LIKE ? ESCAPE '\\'"
                . ' OR ' . Db::quoteIdentifier('domain') . " LIKE ? ESCAPE '\\'"
                . ' OR ' . Db::quoteIdentifier('domain_display') . " LIKE ? ESCAPE '\\')";
            $extraBind[] = strtoupper($like);
            $extraBind[] = strtolower($like);
            $extraBind[] = strtolower($like);
        }
        if (!empty($filters['from'])) {
            $extra[] = Db::quoteIdentifier('created_at') . ' >= ?';
            $extraBind[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $to = (string) $filters['to'];
            $extra[] = Db::quoteIdentifier('created_at') . ' <= ?';
            $extraBind[] = strlen($to) <= 10 ? $to . ' 23:59:59' : $to;
        }

        return $this->queryRequests($where, $extra, $extraBind, $filters);
    }

    public function countForActor(Actor $actor, array $filters = [])
    {
        $rows = $this->listForActor($actor, array_merge($filters, ['limit' => 500, 'offset' => 0]));
        return count($rows);
    }

    /** Shared SELECT builder for listForActor(). */
    protected function queryRequests(array $where, array $extra, array $extraBind, array $filters)
    {
        list($clause, $bind) = Db::buildWhere($where);
        if ($extra) {
            $clause .= ($clause === '' ? ' WHERE ' : ' AND ') . implode(' AND ', $extra);
            $bind = array_merge($bind, $extraBind);
        }

        $order = $this->safeOrder(isset($filters['order']) ? $filters['order'] : 'id');
        $dir = (isset($filters['dir']) && strtolower($filters['dir']) === 'asc') ? 'ASC' : 'DESC';
        $limit = isset($filters['limit']) ? min(500, max(1, (int) $filters['limit'])) : 50;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $sql = 'SELECT * FROM ' . Db::quoteIdentifier(Db::table('requests')) . $clause
            . ' ORDER BY ' . Db::quoteIdentifier($order) . ' ' . $dir
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        return Db::select($sql, $bind);
    }

    /* ------------------------------------------------------- transitions */

    /**
     * The one and only status writer.
     *
     * @param array  $options action, reason, visibility, permission, meta,
     *                        system (bypass permission check for cron steps)
     * @throws InvalidTransitionException
     */
    public function transition(Actor $actor, array $request, $toStatus, array $options = [])
    {
        $from = (string) $request['status'];
        $to = (string) $toStatus;

        if (!RequestStatus::isValid($to)) {
            throw new InvalidTransitionException('Unknown target status.');
        }
        if ($from === $to) {
            return $this->findRow($request['id']);
        }
        if (!RequestStatus::canTransition($from, $to)) {
            throw new InvalidTransitionException(
                'Cannot move an acquisition from "' . RequestStatus::label($from)
                . '" to "' . RequestStatus::label($to) . '".',
                ['from' => $from, 'to' => $to]
            );
        }

        if (!empty($options['permission'])) {
            Rbac::assert($actor, $options['permission']);
        }

        $this->assertInvariants($request, $to);

        $now = Clock::now();
        $updates = [
            'status' => $to,
            'previous_status' => $from,
            'status_changed_at' => $now,
            'updated_at' => $now,
        ];
        if ($to === RequestStatus::COMPLETED) {
            $updates['completed_at'] = $now;
            $updates['closed_at'] = $now;
        } elseif (RequestStatus::isTerminal($to)) {
            $updates['closed_at'] = $now;
        }
        if ($actor->isCustomer()) {
            $updates['last_customer_action_at'] = $now;
        } elseif ($actor->isBroker()) {
            $updates['last_broker_action_at'] = $now;
        }

        Db::update('requests', $updates, ['id' => (int) $request['id']]);

        Audit::transition($actor, (int) $request['id'], $from, $to, [
            'action' => isset($options['action']) ? $options['action'] : 'request.status.changed',
            'reason' => isset($options['reason']) ? $options['reason'] : '',
            'visibility' => isset($options['visibility']) ? $options['visibility'] : Audit::VIS_CUSTOMER,
            'meta' => isset($options['meta']) ? $options['meta'] : null,
        ]);

        $updated = $this->findRow($request['id']);
        $this->notifyTransition($updated, $to, $options);

        if (in_array($to, [RequestStatus::COMPLETED, RequestStatus::CANCELLED, RequestStatus::FAILED], true)
            && !empty($updated['assigned_broker_id'])) {
            (new BrokerDirectoryService())->refreshCounters((int) $updated['assigned_broker_id']);
        }

        return $updated;
    }

    /**
     * Workflow invariants that no permission can bypass.
     *
     * @throws InvalidTransitionException
     */
    protected function assertInvariants(array $request, $to)
    {
        if ($to === RequestStatus::DOMAIN_TRANSFER) {
            if (!PaymentStatus::isSettled($request['payment_status'])) {
                throw new InvalidTransitionException(
                    'A transfer cannot start until the customer\'s funds are secured.',
                    ['payment_status' => $request['payment_status']]
                );
            }
        }

        if ($to === RequestStatus::COMPLETED) {
            if ($request['transfer_status'] !== TransferStatus::COMPLETED) {
                throw new InvalidTransitionException(
                    'An acquisition cannot be completed until the domain transfer is confirmed complete.',
                    ['transfer_status' => $request['transfer_status']]
                );
            }
            $verification = new VerificationService();
            $missing = $verification->outstandingRequirements($request);
            if ($missing) {
                throw new InvalidTransitionException(
                    'Outstanding verification prevents completion: ' . implode(', ', $missing) . '.',
                    ['missing' => $missing]
                );
            }
        }

        if ($to === RequestStatus::PAYMENT_SECURED && !PaymentStatus::isSettled($request['payment_status'])) {
            throw new InvalidTransitionException('Funds have not been confirmed as received.');
        }
    }

    protected function notifyTransition(array $request, $to, array $options)
    {
        if (!empty($options['silent'])) {
            return;
        }
        $map = [
            RequestStatus::BROKER_ASSIGNED => NotificationService::BROKER_ASSIGNED,
            RequestStatus::OWNER_CONTACTED => NotificationService::OWNER_CONTACTED,
            RequestStatus::OFFER_RECEIVED  => NotificationService::OFFER_RECEIVED,
            RequestStatus::COUNTEROFFER    => NotificationService::COUNTEROFFER_RECEIVED,
            RequestStatus::OFFER_ACCEPTED  => NotificationService::OFFER_ACCEPTED,
            RequestStatus::PAYMENT_PENDING => NotificationService::PAYMENT_REQUIRED,
            RequestStatus::PAYMENT_SECURED => NotificationService::FUNDS_SECURED,
            RequestStatus::DOMAIN_TRANSFER => NotificationService::TRANSFER_STARTED,
            RequestStatus::COMPLETED       => NotificationService::REQUEST_COMPLETED,
            RequestStatus::EXPIRED         => NotificationService::REQUEST_EXPIRED,
            RequestStatus::CANCELLED       => NotificationService::REQUEST_CANCELLED,
            RequestStatus::REJECTED        => NotificationService::REQUEST_REJECTED,
            RequestStatus::REFUNDED        => NotificationService::REFUND_ISSUED,
        ];
        if (isset($map[$to])) {
            $this->notifications->notify($map[$to], $request, NotificationService::AUDIENCE_CUSTOMER);
        }
    }

    /* ----------------------------------------------------- customer acts */

    /** Customer edits budget / message / anonymity while still negotiable. */
    public function updateByCustomer(Actor $actor, $requestId, array $input)
    {
        $request = $this->findForActor($actor, $requestId);
        Rbac::assert($actor, Rbac::REQUEST_UPDATE_OWN);

        $editable = [
            RequestStatus::SUBMITTED, RequestStatus::UNDER_REVIEW,
            RequestStatus::BROKER_ASSIGNED, RequestStatus::OWNER_CONTACTED,
            RequestStatus::NEGOTIATION,
        ];
        if (!in_array($request['status'], $editable, true)) {
            throw new ConflictException('This request can no longer be edited at its current stage.');
        }

        RateLimiter::hit('request.update', $actor->identity());

        $validator = \DomainBroker\Core\Validator::make($input);
        $changes = [];

        if (array_key_exists('budget', $input)) {
            $validator->money(
                'budget',
                $request['currency'],
                Settings::int('min_budget_minor', 0),
                Settings::int('max_budget_minor', 0),
                true
            );
        }
        if (array_key_exists('message', $input)) {
            $validator->text('message', 4000, false);
        }
        if (array_key_exists('anonymous', $input)) {
            $validator->boolean('anonymous', (bool) $request['anonymous']);
        }
        if (array_key_exists('budget_includes_fees', $input)) {
            $validator->boolean('budget_includes_fees', (bool) $request['budget_includes_fees']);
        }
        $data = $validator->validate();

        if (array_key_exists('budget', $data)) {
            $changes['budget_minor'] = (int) $data['budget'];
            $changes['kyc_required'] = $this->kycRequired((int) $data['budget']) ? 1 : 0;
        }
        if (array_key_exists('message', $data)) {
            $changes['customer_message'] = $data['message'];
        }
        if (array_key_exists('anonymous', $data)) {
            $changes['anonymous'] = $data['anonymous'] ? 1 : 0;
        }
        if (array_key_exists('budget_includes_fees', $data)) {
            $changes['budget_includes_fees'] = $data['budget_includes_fees'] ? 1 : 0;
        }

        if (!$changes) {
            return $request;
        }

        $previous = array_intersect_key($request, $changes);
        $changes['last_customer_action_at'] = Clock::now();
        $changes['updated_at'] = Clock::now();
        Db::update('requests', $changes, ['id' => (int) $request['id']]);

        Audit::record($actor, 'request.updated', [
            'request_id' => (int) $request['id'],
            'previous' => $previous,
            'new' => $changes,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $updated = $this->findRow($request['id']);

        if (isset($changes['budget_minor'])) {
            $this->risk->evaluate($updated);
        }

        return $updated;
    }

    public function cancelByCustomer(Actor $actor, $requestId, $reason = '')
    {
        $request = $this->findForActor($actor, $requestId);
        Rbac::assert($actor, Rbac::REQUEST_CANCEL_OWN);

        if (RequestStatus::isFinanciallyActive($request['status'])) {
            throw new ConflictException(
                'This acquisition has reached the payment stage and cannot be cancelled here. '
                . 'Please contact your broker or open a dispute.'
            );
        }
        if (RequestStatus::isTerminal($request['status'])) {
            throw new ConflictException('This request is already closed.');
        }

        return $this->transition($actor, $request, RequestStatus::CANCELLED, [
            'action' => 'request.cancelled.customer',
            'reason' => Str::cleanText($reason, 1000),
            'visibility' => Audit::VIS_CUSTOMER,
        ]);
    }

    /* ------------------------------------------------------- admin acts  */

    public function approve(Actor $actor, $requestId, $note = '')
    {
        Rbac::assert($actor, Rbac::REQUEST_APPROVE);
        $request = $this->findForActor($actor, $requestId);

        if ($request['status'] !== RequestStatus::SUBMITTED && $request['status'] !== RequestStatus::UNDER_REVIEW) {
            throw new ConflictException('Only a submitted or in-review request can be approved.');
        }
        if (!empty($request['manual_review'])) {
            // Approving clears the hold explicitly and is audited.
            Db::update('requests', ['manual_review' => 0, 'updated_at' => Clock::now()], ['id' => (int) $request['id']]);
            Audit::record($actor, 'risk.manual_review.released', [
                'request_id' => (int) $request['id'],
                'previous' => ['manual_review' => 1],
                'new' => ['manual_review' => 0],
                'reason' => $note,
                'visibility' => Audit::VIS_INTERNAL,
            ]);
            $request = $this->findRow($request['id']);
        }

        if ($request['status'] === RequestStatus::SUBMITTED) {
            $request = $this->transition($actor, $request, RequestStatus::UNDER_REVIEW, [
                'action' => 'request.approved',
                'reason' => $note,
                'visibility' => Audit::VIS_CUSTOMER,
            ]);
        }

        Audit::record($actor, 'request.approved', [
            'request_id' => (int) $request['id'],
            'reason' => $note,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $request;
    }

    public function reject(Actor $actor, $requestId, $reason)
    {
        Rbac::assert($actor, Rbac::REQUEST_APPROVE);
        $request = $this->findForActor($actor, $requestId);
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A reason is required to reject a request.', ['reason' => 'Required.']);
        }
        return $this->transition($actor, $request, RequestStatus::REJECTED, [
            'action' => 'request.rejected',
            'reason' => $reason,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);
    }

    public function cancelByAdmin(Actor $actor, $requestId, $reason)
    {
        Rbac::assert($actor, Rbac::REQUEST_CANCEL_ANY);
        $request = $this->findForActor($actor, $requestId);
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A reason is required.', ['reason' => 'Required.']);
        }
        return $this->transition($actor, $request, RequestStatus::CANCELLED, [
            'action' => 'request.cancelled.admin',
            'reason' => $reason,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);
    }

    /**
     * Administrative override. Still validated against the transition table —
     * an override is an authority to skip *process*, not to corrupt state —
     * and always demands a reason.
     */
    public function overrideStatus(Actor $actor, $requestId, $toStatus, $reason)
    {
        Rbac::assert($actor, Rbac::STATUS_OVERRIDE);
        $request = $this->findForActor($actor, $requestId);
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('An override requires a documented reason.', ['reason' => 'Required.']);
        }
        return $this->transition($actor, $request, $toStatus, [
            'action' => 'request.status.override',
            'reason' => $reason,
            'visibility' => Audit::VIS_INTERNAL,
        ]);
    }

    /* ---------------------------------------------------------- expiry   */

    /**
     * Expire requests whose window has elapsed (module cron).
     *
     * @return int number expired
     */
    public function expireStale()
    {
        $actor = Actor::system('Scheduled task');
        $rows = Db::fetch('requests', [
            'status' => ['notin', array_merge(RequestStatus::TERMINAL, RequestStatus::FINANCIALLY_ACTIVE)],
            'expires_at' => ['<=', Clock::now()],
            'deleted_at' => null,
        ], ['limit' => 200]);

        $count = 0;
        foreach ($rows as $row) {
            if (!RequestStatus::canTransition($row['status'], RequestStatus::EXPIRED)) {
                continue;
            }
            try {
                $this->transition($actor, $row, RequestStatus::EXPIRED, [
                    'action' => 'request.expired',
                    'visibility' => Audit::VIS_CUSTOMER,
                    'reason' => 'The acquisition window elapsed without completion.',
                ]);
                $count++;
            } catch (\Throwable $e) {
                \DomainBroker\Core\Logger::warning('Expiry failed', ['request' => $row['id'], 'error' => $e->getMessage()]);
            }
        }
        return $count;
    }

    /* ------------------------------------------------------- aggregates  */

    /** Customer dashboard counters and totals. */
    public function customerSummary(Actor $actor)
    {
        Rbac::assert($actor, Rbac::REQUEST_VIEW_OWN);
        $clientId = (int) $actor->clientId;
        $base = ['client_id' => $clientId, 'deleted_at' => null];

        $pendingNegotiation = [
            RequestStatus::OWNER_CONTACTED, RequestStatus::NEGOTIATION, RequestStatus::COUNTEROFFER,
        ];

        $spentByCurrency = $this->spentByCurrency($clientId);

        return [
            'total'             => Db::count('requests', $base),
            'active'            => Db::count('requests', array_merge($base, ['status' => ['notin', RequestStatus::TERMINAL]])),
            'pending_negotiation' => Db::count('requests', array_merge($base, ['status' => ['in', $pendingNegotiation]])),
            'offers_awaiting'   => $this->offersAwaitingCustomer($clientId),
            'accepted'          => Db::count('requests', array_merge($base, ['status' => RequestStatus::OFFER_ACCEPTED])),
            'payment_pending'   => Db::count('requests', array_merge($base, ['status' => RequestStatus::PAYMENT_PENDING])),
            'in_transfer'       => Db::count('requests', array_merge($base, [
                'status' => ['in', [RequestStatus::DOMAIN_TRANSFER, RequestStatus::TRANSFER_VERIFICATION]],
            ])),
            'completed'         => Db::count('requests', array_merge($base, ['status' => RequestStatus::COMPLETED])),
            'cancelled_expired' => Db::count('requests', array_merge($base, [
                'status' => ['in', [RequestStatus::CANCELLED, RequestStatus::EXPIRED, RequestStatus::REJECTED]],
            ])),
            'disputed'          => Db::count('requests', array_merge($base, ['status' => RequestStatus::DISPUTED])),
            'spent'             => $spentByCurrency,
        ];
    }

    protected function offersAwaitingCustomer($clientId)
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . Db::quoteIdentifier(Db::table('offers')) . ' o '
            . 'INNER JOIN ' . Db::quoteIdentifier(Db::table('requests')) . ' r ON r.id = o.request_id '
            . 'WHERE r.client_id = ? AND o.status = ? AND o.direction = ? AND o.requires_customer_approval = 1',
            [(int) $clientId, \DomainBroker\Workflow\OfferStatus::PENDING, \DomainBroker\Workflow\OfferStatus::DIR_TO_CUSTOMER]
        );
    }

    /** Totals paid, by currency, so mixed-currency accounts stay honest. */
    public function spentByCurrency($clientId)
    {
        $rows = Db::select(
            'SELECT p.currency,'
            . ' COALESCE(SUM(p.acquisition_minor), 0) AS acquisition,'
            . ' COALESCE(SUM(p.fee_minor), 0) AS fees,'
            . ' COALESCE(SUM(p.tax_minor), 0) AS tax,'
            . ' COALESCE(SUM(p.total_minor), 0) AS total,'
            . ' COALESCE(SUM(p.refunded_minor), 0) AS refunded'
            . ' FROM ' . Db::quoteIdentifier(Db::table('payments')) . ' p'
            . ' INNER JOIN ' . Db::quoteIdentifier(Db::table('requests')) . ' r ON r.id = p.request_id'
            . ' WHERE r.client_id = ? AND p.type = ? AND p.status IN (?, ?, ?, ?)'
            . ' GROUP BY p.currency',
            [
                (int) $clientId, 'acquisition',
                PaymentStatus::RECEIVED, PaymentStatus::FUNDS_SECURED,
                PaymentStatus::RELEASED, PaymentStatus::PARTIALLY_REFUNDED,
            ]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['currency']] = [
                'currency'    => $row['currency'],
                'acquisition' => (int) $row['acquisition'],
                'fees'        => (int) $row['fees'],
                'tax'         => (int) $row['tax'],
                'total'       => (int) $row['total'],
                'refunded'    => (int) $row['refunded'],
                'net'         => (int) $row['total'] - (int) $row['refunded'],
            ];
        }
        return $out;
    }

    /* ---------------------------------------------------------- helpers  */

    public function allowedCurrencies($clientId = null)
    {
        $configured = Settings::listOf('allowed_currencies');
        if ($configured) {
            return array_values(array_unique(array_map('strtoupper', $configured)));
        }
        $codes = [];
        try {
            foreach (Gateway::get()->getCurrencies() as $currency) {
                $codes[] = strtoupper($currency['code']);
            }
        } catch (\Throwable $e) {
            $codes = [];
        }
        if (!$codes) {
            $codes = ['USD'];
        }
        // Put the client's own currency first so the form defaults sensibly.
        if ($clientId) {
            try {
                $clientCurrency = Gateway::get()->getClientCurrency($clientId);
                if ($clientCurrency && in_array(strtoupper($clientCurrency['code']), $codes, true)) {
                    $code = strtoupper($clientCurrency['code']);
                    $codes = array_merge([$code], array_values(array_diff($codes, [$code])));
                }
            } catch (\Throwable $e) {
                // keep configured order
            }
        }
        return $codes;
    }

    public function kycRequired($amountMinor)
    {
        $threshold = Settings::int('require_kyc_above_minor', 0);
        return $threshold > 0 && (int) $amountMinor >= $threshold;
    }

    protected function resolveClientId(Actor $actor, array $input)
    {
        if ($actor->isCustomer()) {
            return (int) $actor->clientId;
        }
        if ($actor->isAdmin() && !empty($input['client_id'])) {
            return (int) $input['client_id'];
        }
        return 0;
    }

    protected function assignmentService()
    {
        if ($this->assignments === null) {
            $this->assignments = new AssignmentService($this, $this->notifications);
        }
        return $this->assignments;
    }

    public function setAssignmentService(AssignmentService $service)
    {
        $this->assignments = $service;
    }

    protected function openSupportTicket(array $request)
    {
        try {
            $ticket = Gateway::get()->openTicket([
                'clientid' => (int) $request['client_id'],
                'subject'  => 'Domain Broker request ' . $request['reference'] . ' — ' . $request['domain'],
                'message'  => "A new domain acquisition request has been submitted.\n\n"
                    . 'Reference: ' . $request['reference'] . "\n"
                    . 'Domain: ' . $request['domain'] . "\n"
                    . 'Maximum budget: ' . Money::toDecimalString($request['budget_minor'], $request['currency'])
                    . ' ' . $request['currency'],
                'priority' => 'Medium',
            ]);
            if ($ticket && !empty($ticket['ticketid'])) {
                Db::update('requests', [
                    'whmcs_ticket_id' => (int) $ticket['ticketid'],
                    'updated_at' => Clock::now(),
                ], ['id' => (int) $request['id']]);
            }
        } catch (\Throwable $e) {
            \DomainBroker\Core\Logger::warning('Support ticket creation failed', ['error' => $e->getMessage()]);
        }
    }

    protected function uniqueReference()
    {
        for ($i = 0; $i < 25; $i++) {
            $ref = Str::reference('DB', 8);
            if (Db::count('requests', ['reference' => $ref]) === 0) {
                return $ref;
            }
        }
        throw new ConflictException('Unable to allocate a request reference.');
    }

    protected function safeOrder($order)
    {
        $allowed = ['id', 'created_at', 'updated_at', 'status', 'domain', 'budget_minor', 'total_minor', 'expires_at'];
        return in_array($order, $allowed, true) ? $order : 'id';
    }

    protected function escapeLike($value)
    {
        return str_replace(['%', '_'], ['\%', '\_'], (string) $value);
    }
}
