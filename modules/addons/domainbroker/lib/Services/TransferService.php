<?php
/**
 * Domain Broker — domain transfer tracking.
 *
 * The transfer is tracked independently of the money. A transfer cannot be
 * started until funds are secured, and completing the *transfer* does not
 * complete the *acquisition*: the request moves to "transfer verification"
 * and only an approved verification checklist closes it out. Nothing in this
 * class fakes a registry operation — every state change records what a human
 * or the registrar actually reported.
 *
 * Authorisation (EPP) codes are encrypted at rest, displayed only as a hint,
 * and revealed only to principals holding TRANSFER_CREDENTIAL_VIEW, with the
 * reveal itself audited.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Db;
use DomainBroker\Core\InvalidTransitionException;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Core\Validator;
use DomainBroker\Integration\Gateway;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;
use DomainBroker\Workflow\TransferStatus;

class TransferService
{
    /** @var RequestService */
    protected $requests;

    /** @var NotificationService */
    protected $notifications;

    /** @var VerificationService */
    protected $verification;

    public function __construct(
        RequestService $requests = null,
        NotificationService $notifications = null,
        VerificationService $verification = null
    ) {
        $this->requests = $requests ?: new RequestService();
        $this->notifications = $notifications ?: new NotificationService();
        $this->verification = $verification ?: new VerificationService();
    }

    /* ----------------------------------------------------------- lookup */

    public function find($transferId)
    {
        return Db::first('transfers', ['id' => (int) $transferId]);
    }

    public function findOrFail($transferId)
    {
        $row = $this->find($transferId);
        if (!$row) {
            throw new NotFoundException('Transfer record not found.');
        }
        return $row;
    }

    public function forRequest($requestId)
    {
        return Db::first('transfers', ['request_id' => (int) $requestId], ['order' => 'id', 'dir' => 'desc']);
    }

    /**
     * Customer-safe projection: never includes the encrypted auth code.
     */
    public function publicView($transfer)
    {
        if (!$transfer) {
            return null;
        }
        unset($transfer['auth_code_enc'], $transfer['auth_code_fingerprint'], $transfer['registry_response']);
        $transfer['status_label'] = TransferStatus::label($transfer['status']);
        $transfer['status_tone'] = TransferStatus::tone($transfer['status']);
        $transfer['tracker_index'] = TransferStatus::trackerIndex($transfer['status']);
        return $transfer;
    }

    /* ------------------------------------------------------------ start */

    /**
     * Open the transfer record. Permitted only once the customer's funds are
     * secured — this is the hard separation between payment and transfer.
     */
    public function start(Actor $actor, $requestId, array $input = [])
    {
        Rbac::assert($actor, Rbac::TRANSFER_START);
        $request = $this->requests->findForActor($actor, $requestId);
        $this->assertBrokerOwnsRequest($actor, $request);

        if (!PaymentStatus::isSettled($request['payment_status'])) {
            throw new InvalidTransitionException(
                'A transfer cannot start until the customer\'s funds are secured.',
                ['payment_status' => $request['payment_status']]
            );
        }
        if ($request['status'] !== RequestStatus::PAYMENT_SECURED
            && $request['status'] !== RequestStatus::DOMAIN_TRANSFER) {
            throw new InvalidTransitionException(
                'The acquisition is not at the transfer stage.',
                ['status' => $request['status']]
            );
        }

        $existing = $this->forRequest($request['id']);
        if ($existing && !in_array($existing['status'], [TransferStatus::CANCELLED, TransferStatus::NOT_STARTED], true)) {
            return $existing;
        }

        $data = Validator::make($input)
            ->text('losing_registrar', 190, false)
            ->text('gaining_registrar', 190, false)
            ->text('destination_account', 190, false)
            ->text('notes', 2000, false)
            ->boolean('within_60_day_lock', false)
            ->validate();

        $now = Clock::now();
        $windowDays = Settings::int('transfer_window_days', 14);

        if ($existing) {
            $transferId = (int) $existing['id'];
            Db::update('transfers', [
                'status' => TransferStatus::AUTH_PENDING,
                'losing_registrar' => $data['losing_registrar'] ?: null,
                'gaining_registrar' => $data['gaining_registrar'] ?: $this->defaultGainingRegistrar(),
                'destination_account' => $data['destination_account'] ?: null,
                'registrar_notes' => $data['notes'] ?: null,
                'within_60_day_lock' => !empty($data['within_60_day_lock']) ? 1 : 0,
                'initiated_at' => $now,
                'expires_at' => Clock::inDays($windowDays),
                'updated_at' => $now,
            ], ['id' => $transferId]);
        } else {
            $transferId = Db::insert('transfers', [
                'reference' => $this->uniqueTransferReference(),
                'request_id' => (int) $request['id'],
                'domain' => $request['domain'],
                'status' => TransferStatus::AUTH_PENDING,
                'losing_registrar' => $data['losing_registrar'] ?: null,
                'gaining_registrar' => $data['gaining_registrar'] ?: $this->defaultGainingRegistrar(),
                'destination_account' => $data['destination_account'] ?: null,
                'auth_code_status' => 'not_requested',
                'registrar_lock_released' => 0,
                'whois_privacy_disabled' => 0,
                'within_60_day_lock' => !empty($data['within_60_day_lock']) ? 1 : 0,
                'registrar_notes' => $data['notes'] ?: null,
                'attempts' => 0,
                'initiated_at' => $now,
                'expires_at' => Clock::inDays($windowDays),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Db::update('requests', [
            'transfer_status' => TransferStatus::AUTH_PENDING,
            'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'transfer.started', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'transfer',
            'entity_id' => $transferId,
            'previous' => ['transfer_status' => $request['transfer_status']],
            'new' => [
                'transfer_status' => TransferStatus::AUTH_PENDING,
                'gaining_registrar' => $data['gaining_registrar'] ?: $this->defaultGainingRegistrar(),
            ],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $fresh = $this->requests->findRow($request['id']);
        if (RequestStatus::canTransition($fresh['status'], RequestStatus::DOMAIN_TRANSFER)) {
            $this->requests->transition($actor, $fresh, RequestStatus::DOMAIN_TRANSFER, [
                'action' => 'request.transfer.started',
                'visibility' => Audit::VIS_CUSTOMER,
            ]);
        }

        // Verification work can begin in parallel with the registry process.
        $this->verification->bootstrap($actor, $this->requests->findRow($request['id']));

        return $this->findOrFail($transferId);
    }

    /* ----------------------------------------------- authorisation code */

    public function requestAuthCode(Actor $actor, $transferId, $note = '')
    {
        Rbac::assert($actor, Rbac::TRANSFER_UPDATE);
        $transfer = $this->findOrFail($transferId);
        $request = $this->requests->findForActor($actor, $transfer['request_id']);
        $this->assertBrokerOwnsRequest($actor, $request);

        $now = Clock::now();
        Db::update('transfers', [
            'auth_code_status' => 'requested',
            'auth_code_requested_at' => $now,
            'registrar_notes' => Str::clip(trim((string) $transfer['registrar_notes'] . "\n" . $note), 2000) ?: null,
            'updated_at' => $now,
        ], ['id' => (int) $transfer['id']]);

        Audit::record($actor, 'transfer.auth_code.requested', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'transfer',
            'entity_id' => (int) $transfer['id'],
            'new' => ['auth_code_status' => 'requested'],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $this->notifications->notify(
            NotificationService::TRANSFER_ACTION,
            $this->requests->findRow($request['id']),
            NotificationService::AUDIENCE_CUSTOMER
        );

        return $this->findOrFail($transfer['id']);
    }

    /**
     * Store an authorisation code. The plaintext is encrypted immediately; a
     * masked hint and a fingerprint are kept so the code can be recognised
     * and compared without being displayed.
     */
    public function recordAuthCode(Actor $actor, $transferId, $authCode)
    {
        Rbac::assert($actor, Rbac::TRANSFER_UPDATE);
        $transfer = $this->findOrFail($transferId);
        $request = $this->requests->findForActor($actor, $transfer['request_id']);
        $this->assertBrokerOwnsRequest($actor, $request);

        $authCode = trim((string) $authCode);
        if ($authCode === '' || strlen($authCode) < 6 || strlen($authCode) > 128) {
            throw new ValidationException('A valid authorisation code is required.', [
                'auth_code' => 'Enter the authorisation code exactly as supplied by the losing registrar.',
            ]);
        }

        $now = Clock::now();
        Db::update('transfers', [
            'auth_code_enc' => Crypto::encrypt($authCode, 'transfer.auth_code'),
            'auth_code_hint' => Str::mask($authCode, 0, 4),
            'auth_code_fingerprint' => Crypto::blindIndex($authCode, 'transfer.auth_code'),
            'auth_code_status' => 'received',
            'auth_code_received_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $transfer['id']]);

        // The code itself is NEVER written to the audit log.
        Audit::record($actor, 'transfer.auth_code.recorded', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'transfer',
            'entity_id' => (int) $transfer['id'],
            'new' => ['auth_code_status' => 'received', 'hint' => Str::mask($authCode, 0, 4)],
            'visibility' => Audit::VIS_BROKER,
        ]);

        if (TransferStatus::canTransition($transfer['status'], TransferStatus::AUTH_RECEIVED)) {
            $this->updateStatus($actor, (int) $transfer['id'], TransferStatus::AUTH_RECEIVED, [
                'note' => 'Authorisation code received.',
            ]);
        }

        return $this->findOrFail($transfer['id']);
    }

    /**
     * Reveal the stored authorisation code. Strictly permissioned and audited
     * on every single read.
     */
    public function revealAuthCode(Actor $actor, $transferId, $reason = '')
    {
        Rbac::assert($actor, Rbac::TRANSFER_CREDENTIAL_VIEW);
        $transfer = $this->findOrFail($transferId);
        // Holding the permission is not enough: the record must also be yours.
        $this->assertBrokerOwnsRequest($actor, $this->requests->findForActor($actor, $transfer['request_id']));
        if (empty($transfer['auth_code_enc'])) {
            throw new NotFoundException('No authorisation code has been recorded.');
        }

        $plain = Crypto::tryDecrypt($transfer['auth_code_enc'], 'transfer.auth_code');
        if ($plain === null) {
            throw new ConflictException('The stored authorisation code could not be decrypted with the current key.');
        }

        Audit::record($actor, 'transfer.auth_code.revealed', [
            'request_id' => (int) $transfer['request_id'],
            'entity_type' => 'transfer',
            'entity_id' => (int) $transfer['id'],
            'new' => ['hint' => $transfer['auth_code_hint']],
            'reason' => $reason,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $plain;
    }

    /* ---------------------------------------------------- state changes */

    /**
     * Move the transfer through its lifecycle. Every value is validated
     * against the transition table server-side — the client's claim about the
     * current state is never trusted.
     *
     * @param array $options note, registrar_response, registrar fields
     */
    public function updateStatus(Actor $actor, $transferId, $status, array $options = [])
    {
        Rbac::assert($actor, Rbac::TRANSFER_UPDATE);
        $transfer = $this->findOrFail($transferId);
        $request = $this->requests->findForActor($actor, $transfer['request_id']);
        $this->assertBrokerOwnsRequest($actor, $request);

        $status = (string) $status;
        if (!TransferStatus::isValid($status)) {
            throw new ValidationException('Unknown transfer status.', ['status' => 'Unknown status.']);
        }
        if ($transfer['status'] === $status) {
            return $transfer;
        }
        if (!TransferStatus::canTransition($transfer['status'], $status)) {
            throw new InvalidTransitionException(
                'A transfer cannot move from "' . TransferStatus::label($transfer['status'])
                . '" to "' . TransferStatus::label($status) . '".',
                ['from' => $transfer['status'], 'to' => $status]
            );
        }
        if ($status === TransferStatus::COMPLETED) {
            // Completion of the registry operation is a claim of fact; it must
            // be recorded with evidence, not merely asserted.
            return $this->markCompleted($actor, (int) $transfer['id'], $options);
        }
        if ($status === TransferStatus::FAILED || $status === TransferStatus::REJECTED) {
            return $this->markFailed($actor, (int) $transfer['id'], $status, isset($options['reason']) ? $options['reason'] : '');
        }

        $now = Clock::now();
        $updates = [
            'status' => $status,
            'updated_at' => $now,
            'last_checked_at' => $now,
        ];
        if ($status === TransferStatus::INITIATED) {
            $updates['initiated_at'] = $now;
            $updates['attempts'] = (int) $transfer['attempts'] + 1;
        } elseif ($status === TransferStatus::PENDING) {
            $updates['pending_since'] = $now;
        } elseif ($status === TransferStatus::APPROVED) {
            $updates['approved_at'] = $now;
        }
        foreach (['losing_registrar', 'gaining_registrar', 'losing_registrar_iana', 'gaining_registrar_iana', 'destination_account'] as $field) {
            if (isset($options[$field]) && $options[$field] !== '') {
                $updates[$field] = Str::clip((string) $options[$field], 190);
            }
        }
        foreach (['registrar_lock_released', 'whois_privacy_disabled'] as $flag) {
            if (array_key_exists($flag, $options)) {
                $updates[$flag] = !empty($options[$flag]) ? 1 : 0;
            }
        }
        if (!empty($options['note'])) {
            $updates['registrar_notes'] = Str::clip(
                trim((string) $transfer['registrar_notes'] . "\n" . Str::cleanText($options['note'], 1000)),
                2000
            );
        }
        if (!empty($options['registry_response'])) {
            $updates['registry_response'] = Str::clip((string) $options['registry_response'], 20000);
        }

        Db::update('transfers', $updates, ['id' => (int) $transfer['id']]);

        Db::update('requests', [
            'transfer_status' => $status,
            'last_broker_action_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'transfer.status.changed', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'transfer',
            'entity_id' => (int) $transfer['id'],
            'previous' => ['transfer_status' => $transfer['status']],
            'new' => ['transfer_status' => $status],
            'reason' => isset($options['note']) ? $options['note'] : '',
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        if (TransferStatus::requiresCustomerAction($status)) {
            $this->notifications->notify(
                NotificationService::TRANSFER_ACTION,
                $this->requests->findRow($request['id']),
                NotificationService::AUDIENCE_CUSTOMER
            );
        }

        return $this->findOrFail($transfer['id']);
    }

    /**
     * Record the registry/registrar confirming the domain has moved. This
     * moves the acquisition to *verification*, not to completed.
     */
    public function markCompleted(Actor $actor, $transferId, array $options = [])
    {
        Rbac::assert($actor, Rbac::MILESTONE_MARK);
        $transfer = $this->findOrFail($transferId);
        $request = $this->requests->findForActor($actor, $transfer['request_id']);
        $this->assertBrokerOwnsRequest($actor, $request);

        if ($transfer['status'] === TransferStatus::COMPLETED) {
            return $transfer;
        }
        if (!TransferStatus::canTransition($transfer['status'], TransferStatus::COMPLETED)) {
            throw new InvalidTransitionException(
                'A transfer can only be completed from an in-flight state, not from "'
                . TransferStatus::label($transfer['status']) . '".'
            );
        }

        $evidence = Str::cleanText(isset($options['evidence']) ? $options['evidence'] : (isset($options['note']) ? $options['note'] : ''), 2000);
        if ($evidence === '') {
            throw new ValidationException(
                'Record the registrar confirmation that evidences the completed transfer.',
                ['evidence' => 'Evidence of completion is required.']
            );
        }

        $now = Clock::now();
        $updates = [
            'status' => TransferStatus::COMPLETED,
            'completed_at' => $now,
            'last_checked_at' => $now,
            'registrar_notes' => Str::clip(trim((string) $transfer['registrar_notes'] . "\n" . $evidence), 2000),
            'updated_at' => $now,
        ];
        if (!empty($options['whmcs_domain_id'])) {
            $updates['whmcs_domain_id'] = (int) $options['whmcs_domain_id'];
        }
        if (!empty($options['registry_response'])) {
            $updates['registry_response'] = Str::clip((string) $options['registry_response'], 20000);
        }
        Db::update('transfers', $updates, ['id' => (int) $transfer['id']]);

        Db::update('requests', [
            'transfer_status' => TransferStatus::COMPLETED,
            'whmcs_domain_id' => !empty($options['whmcs_domain_id']) ? (int) $options['whmcs_domain_id'] : $request['whmcs_domain_id'],
            'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'transfer.completed', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'transfer',
            'entity_id' => (int) $transfer['id'],
            'previous' => ['transfer_status' => $transfer['status']],
            'new' => ['transfer_status' => TransferStatus::COMPLETED],
            'reason' => $evidence,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $fresh = $this->requests->findRow($request['id']);
        if (RequestStatus::canTransition($fresh['status'], RequestStatus::TRANSFER_VERIFICATION)) {
            $fresh = $this->requests->transition($actor, $fresh, RequestStatus::TRANSFER_VERIFICATION, [
                'action' => 'request.transfer.verifying',
                'visibility' => Audit::VIS_CUSTOMER,
            ]);
        }

        $this->notifications->notify(
            NotificationService::TRANSFER_COMPLETED,
            $fresh,
            NotificationService::AUDIENCE_CUSTOMER
        );

        return $this->findOrFail($transfer['id']);
    }

    public function markFailed(Actor $actor, $transferId, $status, $reason)
    {
        Rbac::assert($actor, Rbac::TRANSFER_UPDATE);
        $transfer = $this->findOrFail($transferId);
        $request = $this->requests->findForActor($actor, $transfer['request_id']);
        $this->assertBrokerOwnsRequest($actor, $request);

        $status = in_array($status, [TransferStatus::FAILED, TransferStatus::REJECTED, TransferStatus::EXPIRED], true)
            ? $status : TransferStatus::FAILED;
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A reason is required.', ['reason' => 'Required.']);
        }
        if (!TransferStatus::canTransition($transfer['status'], $status)) {
            throw new InvalidTransitionException('The transfer cannot be marked as ' . TransferStatus::label($status) . '.');
        }

        $now = Clock::now();
        Db::update('transfers', [
            'status' => $status,
            'failed_at' => $now,
            'failure_reason' => $reason,
            'last_checked_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $transfer['id']]);

        Db::update('requests', [
            'transfer_status' => $status,
            'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'transfer.failed', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'transfer',
            'entity_id' => (int) $transfer['id'],
            'previous' => ['transfer_status' => $transfer['status']],
            'new' => ['transfer_status' => $status],
            'reason' => $reason,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $this->notifications->notify(
            NotificationService::TRANSFER_FAILED,
            $this->requests->findRow($request['id']),
            NotificationService::AUDIENCE_CUSTOMER
        );
        $this->notifications->notify(
            NotificationService::TRANSFER_FAILED,
            $this->requests->findRow($request['id']),
            NotificationService::AUDIENCE_ADMIN
        );

        return $this->findOrFail($transfer['id']);
    }

    /**
     * Close the acquisition. Requires a completed transfer *and* a clean
     * verification checklist; the guard also lives in
     * RequestService::assertInvariants so no caller can route around it.
     */
    public function completeAcquisition(Actor $actor, $requestId, $note = '')
    {
        Rbac::assert($actor, Rbac::MILESTONE_MARK);
        $request = $this->requests->findForActor($actor, $requestId);
        $this->assertBrokerOwnsRequest($actor, $request);

        if ($request['transfer_status'] !== TransferStatus::COMPLETED) {
            throw new InvalidTransitionException('The domain transfer is not confirmed complete.');
        }
        $missing = $this->verification->outstandingRequirements($request);
        if ($missing) {
            throw new InvalidTransitionException(
                'Outstanding verification prevents completion: ' . implode(', ', $missing) . '.',
                ['missing' => $missing]
            );
        }

        $updated = $this->requests->transition($actor, $request, RequestStatus::COMPLETED, [
            'action' => 'request.completed',
            'reason' => $note,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        Gateway::get()->logActivity(
            'Domain Broker: acquisition ' . $request['reference'] . ' completed for ' . $request['domain'],
            (int) $request['client_id']
        );

        return $updated;
    }

    /** Transfers whose registry window elapsed (module cron). */
    public function expireStale($limit = 100)
    {
        $actor = Actor::system('Scheduled task');
        $rows = Db::fetch('transfers', [
            'status' => ['in', [
                TransferStatus::AUTH_PENDING, TransferStatus::AUTH_RECEIVED,
                TransferStatus::INITIATED, TransferStatus::PENDING,
            ]],
            'expires_at' => ['<=', Clock::now()],
        ], ['order' => 'id', 'limit' => $limit]);

        $count = 0;
        foreach ($rows as $transfer) {
            if (!TransferStatus::canTransition($transfer['status'], TransferStatus::EXPIRED)) {
                continue;
            }
            $now = Clock::now();
            Db::update('transfers', [
                'status' => TransferStatus::EXPIRED,
                'failure_reason' => 'The transfer window elapsed without completion.',
                'updated_at' => $now,
            ], ['id' => (int) $transfer['id']]);

            Db::update('requests', [
                'transfer_status' => TransferStatus::EXPIRED,
                'updated_at' => $now,
            ], ['id' => (int) $transfer['request_id']]);

            Audit::record($actor, 'transfer.expired', [
                'request_id' => (int) $transfer['request_id'],
                'entity_type' => 'transfer',
                'entity_id' => (int) $transfer['id'],
                'previous' => ['transfer_status' => $transfer['status']],
                'new' => ['transfer_status' => TransferStatus::EXPIRED],
                'visibility' => Audit::VIS_CUSTOMER,
            ]);

            $request = $this->requests->findRow($transfer['request_id']);
            if ($request) {
                $this->notifications->notify(
                    NotificationService::TRANSFER_FAILED,
                    $request,
                    NotificationService::AUDIENCE_ADMIN
                );
            }
            $count++;
        }
        return $count;
    }

    /** Dashboard counters. */
    public function statistics()
    {
        $out = [];
        foreach (TransferStatus::all() as $status) {
            $out[$status] = Db::count('transfers', ['status' => $status]);
        }
        $out['in_flight'] = Db::count('transfers', [
            'status' => ['in', [
                TransferStatus::AUTH_PENDING, TransferStatus::AUTH_RECEIVED,
                TransferStatus::INITIATED, TransferStatus::PENDING, TransferStatus::APPROVED,
            ]],
        ]);
        return $out;
    }

    /* ---------------------------------------------------------- helpers */

    protected function defaultGainingRegistrar()
    {
        $value = Settings::string('default_gaining_registrar', '');
        return $value !== '' ? $value : null;
    }

    protected function assertBrokerOwnsRequest(Actor $actor, array $request)
    {
        if (!$actor->isBroker()) {
            return;
        }
        if ((int) $request['assigned_broker_id'] !== (int) $actor->brokerId) {
            throw new AuthorizationException('This request is assigned to another broker.');
        }
    }

    protected function uniqueTransferReference()
    {
        for ($i = 0; $i < 25; $i++) {
            $ref = Str::reference('TR', 8);
            if (Db::count('transfers', ['reference' => $ref]) === 0) {
                return $ref;
            }
        }
        throw new ConflictException('Unable to allocate a transfer reference.');
    }
}
