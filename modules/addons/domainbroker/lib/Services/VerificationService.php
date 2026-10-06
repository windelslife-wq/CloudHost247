<?php
/**
 * Domain Broker — ownership and authority verification.
 *
 * An acquisition cannot be marked complete until every *required* verification
 * is approved. Brokers can record evidence; only a principal holding
 * VERIFICATION_APPROVE can approve it, so a broker cannot sign off their own
 * deal. KYC is required automatically above the configured value threshold.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Db;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;

class VerificationService
{
    const TYPE_REGISTRAR  = 'registrar';
    const TYPE_OWNERSHIP  = 'ownership';
    const TYPE_TRANSFER_AUTH = 'transfer_authorization';
    const TYPE_KYC        = 'kyc';
    const TYPE_MANUAL     = 'manual_admin';

    const STATUS_PENDING   = 'pending';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_WAIVED    = 'waived';
    const STATUS_NOT_REQUIRED = 'not_required';

    const TYPES = [
        self::TYPE_REGISTRAR  => 'Registrar verification',
        self::TYPE_OWNERSHIP  => 'Registrant ownership verification',
        self::TYPE_TRANSFER_AUTH => 'Transfer authorisation',
        self::TYPE_KYC        => 'Identity / KYC verification',
        self::TYPE_MANUAL     => 'Manual administrative verification',
    ];

    /** Types that must be approved before completion, for every acquisition. */
    const ALWAYS_REQUIRED = [self::TYPE_REGISTRAR, self::TYPE_OWNERSHIP, self::TYPE_TRANSFER_AUTH];

    /**
     * Create the checklist for a request. Idempotent.
     */
    public function bootstrap(Actor $actor, array $request)
    {
        $required = self::ALWAYS_REQUIRED;
        if (!empty($request['kyc_required'])) {
            $required[] = self::TYPE_KYC;
        }

        $created = [];
        foreach (self::TYPES as $type => $label) {
            if (Db::count('verifications', ['request_id' => (int) $request['id'], 'type' => $type]) > 0) {
                continue;
            }
            $isRequired = in_array($type, $required, true);
            $created[] = Db::insert('verifications', [
                'request_id' => (int) $request['id'],
                'type' => $type,
                'status' => $isRequired ? self::STATUS_PENDING : self::STATUS_NOT_REQUIRED,
                'required' => $isRequired ? 1 : 0,
                'created_at' => Clock::now(),
                'updated_at' => Clock::now(),
            ]);
        }

        if ($created) {
            Audit::record($actor, 'verification.checklist.created', [
                'request_id' => (int) $request['id'],
                'new' => ['required' => $required],
                'visibility' => Audit::VIS_BROKER,
            ]);
        }

        $this->syncRequestStatus((int) $request['id']);
        return $this->forRequest($request['id']);
    }

    public function forRequest($requestId)
    {
        return Db::fetch('verifications', ['request_id' => (int) $requestId], ['order' => 'id']);
    }

    public function find($verificationId)
    {
        return Db::first('verifications', ['id' => (int) $verificationId]);
    }

    /**
     * A broker records evidence. This moves the item to "submitted" — never to
     * "approved": a broker cannot clear their own verification.
     */
    public function submitEvidence(Actor $actor, $requestId, $type, array $input)
    {
        Rbac::assert($actor, Rbac::VERIFICATION_RECORD);
        $this->assertType($type);

        $row = Db::first('verifications', ['request_id' => (int) $requestId, 'type' => $type]);
        if (!$row) {
            throw new NotFoundException('Verification item not found.');
        }
        if ($row['status'] === self::STATUS_APPROVED) {
            throw new ConflictException('That verification has already been approved.');
        }

        $data = \DomainBroker\Core\Validator::make($input)
            ->text('method', 1000, true, 3)
            ->text('notes', 2000, false)
            ->text('provider', 60, false)
            ->validate();

        $reference = isset($input['reference']) && $input['reference'] !== ''
            ? Crypto::encrypt((string) $input['reference'], 'verification.reference')
            : $row['reference_enc'];

        $documentId = isset($input['document_id']) && $input['document_id'] ? (int) $input['document_id'] : $row['document_id'];
        if ($documentId) {
            $doc = Db::first('documents', ['id' => $documentId, 'request_id' => (int) $requestId, 'deleted_at' => null]);
            if (!$doc) {
                throw new ValidationException('The referenced document does not belong to this request.', [
                    'document_id' => 'Unknown document.',
                ]);
            }
        }

        Db::update('verifications', [
            'status' => self::STATUS_SUBMITTED,
            'method' => $data['method'],
            'notes' => isset($data['notes']) ? $data['notes'] : null,
            'provider' => isset($data['provider']) && $data['provider'] !== '' ? $data['provider'] : null,
            'reference_enc' => $reference,
            'document_id' => $documentId ?: null,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);

        Audit::record($actor, 'verification.evidence.submitted', [
            'request_id' => (int) $requestId,
            'entity_type' => 'verification',
            'entity_id' => (int) $row['id'],
            'previous' => ['status' => $row['status']],
            'new' => ['status' => self::STATUS_SUBMITTED, 'type' => $type],
            'visibility' => Audit::VIS_BROKER,
        ]);

        $this->syncRequestStatus((int) $requestId);
        return $this->find($row['id']);
    }

    /** Approve an item. Requires VERIFICATION_APPROVE (admin), never a broker. */
    public function approve(Actor $actor, $verificationId, $notes = '')
    {
        Rbac::assert($actor, Rbac::VERIFICATION_APPROVE);
        $row = $this->find($verificationId);
        if (!$row) {
            throw new NotFoundException('Verification item not found.');
        }
        if ($row['status'] === self::STATUS_APPROVED) {
            return $row;
        }
        if ($row['status'] === self::STATUS_PENDING && (int) $row['required'] === 1) {
            throw new ConflictException('Evidence must be recorded before this item can be approved.');
        }

        Db::update('verifications', [
            'status' => self::STATUS_APPROVED,
            'checked_by_type' => $actor->type,
            'checked_by_id' => (int) $actor->actorId(),
            'checked_at' => Clock::now(),
            'notes' => Str::clip(trim(($row['notes'] ? $row['notes'] . "\n" : '') . $notes), 2000),
            'rejection_reason' => null,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);

        Audit::record($actor, 'verification.approved', [
            'request_id' => (int) $row['request_id'],
            'entity_type' => 'verification',
            'entity_id' => (int) $row['id'],
            'previous' => ['status' => $row['status']],
            'new' => ['status' => self::STATUS_APPROVED, 'type' => $row['type']],
            'reason' => $notes,
            'visibility' => Audit::VIS_BROKER,
        ]);

        $this->syncRequestStatus((int) $row['request_id']);
        return $this->find($row['id']);
    }

    public function reject(Actor $actor, $verificationId, $reason)
    {
        Rbac::assert($actor, Rbac::VERIFICATION_APPROVE);
        $row = $this->find($verificationId);
        if (!$row) {
            throw new NotFoundException('Verification item not found.');
        }
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A reason is required.', ['reason' => 'Required.']);
        }

        Db::update('verifications', [
            'status' => self::STATUS_REJECTED,
            'checked_by_type' => $actor->type,
            'checked_by_id' => (int) $actor->actorId(),
            'checked_at' => Clock::now(),
            'rejection_reason' => $reason,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);

        Audit::record($actor, 'verification.rejected', [
            'request_id' => (int) $row['request_id'],
            'entity_type' => 'verification',
            'entity_id' => (int) $row['id'],
            'previous' => ['status' => $row['status']],
            'new' => ['status' => self::STATUS_REJECTED],
            'reason' => $reason,
            'visibility' => Audit::VIS_BROKER,
        ]);

        $this->syncRequestStatus((int) $row['request_id']);
        return $this->find($row['id']);
    }

    /** Waive a requirement (documented exception). */
    public function waive(Actor $actor, $verificationId, $reason)
    {
        Rbac::assert($actor, Rbac::VERIFICATION_APPROVE);
        $row = $this->find($verificationId);
        if (!$row) {
            throw new NotFoundException('Verification item not found.');
        }
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A waiver requires a documented reason.', ['reason' => 'Required.']);
        }

        Db::update('verifications', [
            'status' => self::STATUS_WAIVED,
            'required' => 0,
            'checked_by_type' => $actor->type,
            'checked_by_id' => (int) $actor->actorId(),
            'checked_at' => Clock::now(),
            'notes' => Str::clip(trim(($row['notes'] ? $row['notes'] . "\n" : '') . 'Waived: ' . $reason), 2000),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);

        Audit::record($actor, 'verification.waived', [
            'request_id' => (int) $row['request_id'],
            'entity_type' => 'verification',
            'entity_id' => (int) $row['id'],
            'previous' => ['status' => $row['status'], 'required' => (int) $row['required']],
            'new' => ['status' => self::STATUS_WAIVED, 'required' => 0],
            'reason' => $reason,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        $this->syncRequestStatus((int) $row['request_id']);
        return $this->find($row['id']);
    }

    /**
     * Human-readable list of requirements still blocking completion.
     *
     * @return string[]
     */
    public function outstandingRequirements(array $request)
    {
        $rows = $this->forRequest($request['id']);
        if (!$rows) {
            // No checklist yet means nothing has been verified.
            return array_map(function ($type) {
                return self::TYPES[$type];
            }, self::ALWAYS_REQUIRED);
        }

        $missing = [];
        foreach ($rows as $row) {
            if ((int) $row['required'] !== 1) {
                continue;
            }
            if ($row['status'] !== self::STATUS_APPROVED && $row['status'] !== self::STATUS_WAIVED) {
                $missing[] = isset(self::TYPES[$row['type']]) ? self::TYPES[$row['type']] : $row['type'];
            }
        }
        return $missing;
    }

    public function isFullyVerified(array $request)
    {
        return $this->outstandingRequirements($request) === [];
    }

    /** Reveal a stored verification reference. Strictly permissioned. */
    public function revealReference(Actor $actor, $verificationId)
    {
        Rbac::assert($actor, Rbac::PII_VIEW);
        $row = $this->find($verificationId);
        if (!$row) {
            throw new NotFoundException('Verification item not found.');
        }
        $value = Crypto::tryDecrypt($row['reference_enc'], 'verification.reference');

        Audit::record($actor, 'verification.reference.revealed', [
            'request_id' => (int) $row['request_id'],
            'entity_type' => 'verification',
            'entity_id' => (int) $row['id'],
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $value;
    }

    /** Keep requests.verification_status in step with the checklist. */
    protected function syncRequestStatus($requestId)
    {
        $rows = $this->forRequest($requestId);
        if (!$rows) {
            return;
        }
        $required = array_filter($rows, function ($r) {
            return (int) $r['required'] === 1;
        });

        $status = 'not_started';
        if ($required) {
            $approved = 0;
            $rejected = 0;
            $submitted = 0;
            foreach ($required as $row) {
                if (in_array($row['status'], [self::STATUS_APPROVED, self::STATUS_WAIVED], true)) {
                    $approved++;
                } elseif ($row['status'] === self::STATUS_REJECTED) {
                    $rejected++;
                } elseif ($row['status'] === self::STATUS_SUBMITTED) {
                    $submitted++;
                }
            }
            if ($rejected > 0) {
                $status = 'rejected';
            } elseif ($approved === count($required)) {
                $status = 'verified';
            } elseif ($submitted > 0 || $approved > 0) {
                $status = 'in_progress';
            } else {
                $status = 'pending';
            }
        } else {
            $status = 'verified';
        }

        Db::update('requests', [
            'verification_status' => $status,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $requestId]);
    }

    protected function assertType($type)
    {
        if (!isset(self::TYPES[$type])) {
            throw new ValidationException('Unknown verification type.', ['type' => 'Unknown verification type.']);
        }
    }
}
