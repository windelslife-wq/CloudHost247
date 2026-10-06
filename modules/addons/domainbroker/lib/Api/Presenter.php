<?php
/**
 * Domain Broker — turn database rows into audience-appropriate payloads.
 *
 * Serialisation is allow-list based, not deny-list based: a presenter names
 * the fields it emits, so a column added later (an internal note, a risk
 * score, an encrypted credential) cannot leak into a customer response just
 * because nobody remembered to unset it.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Money;
use DomainBroker\Services\DisputeService;
use DomainBroker\Workflow\OfferStatus;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;
use DomainBroker\Workflow\TransferStatus;

class Presenter
{
    /** The audience of an actor: customer, broker or admin. */
    public static function audience(Actor $actor)
    {
        if ($actor->isAdmin()) {
            return 'admin';
        }
        if ($actor->isBroker()) {
            return 'broker';
        }
        return 'customer';
    }

    public static function money($minor, $currency)
    {
        return [
            'minor' => (int) $minor,
            'amount' => Money::toDecimalString((int) $minor, $currency),
            'currency' => (string) $currency,
            'formatted' => Money::format((int) $minor, $currency),
        ];
    }

    /* --------------------------------------------------------- requests */

    public static function request(array $row, Actor $actor)
    {
        $audience = self::audience($actor);
        $currency = $row['currency'];

        $out = [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'domain' => $row['domain'],
            'domain_display' => $row['domain_display'] ?: $row['domain'],
            'tld' => $row['tld'],
            'status' => $row['status'],
            'status_label' => RequestStatus::label($row['status']),
            'status_tone' => RequestStatus::tone($row['status']),
            'status_description' => RequestStatus::description($row['status']),
            'stage' => RequestStatus::pipelineIndex($row['status']),
            'progress' => RequestStatus::progress($row['status']),
            'is_terminal' => RequestStatus::isTerminal($row['status']),
            'currency' => $currency,
            'budget' => self::money($row['budget_minor'], $currency),
            'budget_includes_fees' => (bool) $row['budget_includes_fees'],
            'anonymous' => (bool) $row['anonymous'],
            'message' => $row['customer_message'],
            'negotiation_rounds' => (int) $row['negotiation_rounds'],
            'agreed_amount' => self::money($row['agreed_amount_minor'], $currency),
            'broker_fee' => self::money($row['broker_fee_minor'], $currency),
            'tax' => self::money($row['tax_minor'], $currency),
            'total' => self::money($row['total_minor'], $currency),
            'payment_status' => $row['payment_status'],
            'payment_status_label' => PaymentStatus::label($row['payment_status']),
            'transfer_status' => $row['transfer_status'],
            'transfer_status_label' => TransferStatus::label($row['transfer_status']),
            'verification_status' => $row['verification_status'],
            'invoice_id' => $row['whmcs_invoice_id'] ? (int) $row['whmcs_invoice_id'] : null,
            'domain_id' => $row['whmcs_domain_id'] ? (int) $row['whmcs_domain_id'] : null,
            'submitted_at' => $row['submitted_at'],
            'expires_at' => $row['expires_at'],
            'completed_at' => $row['completed_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];

        if ($audience === 'customer') {
            return $out;
        }

        // Staff see the operational columns as well.
        $out['client_id'] = (int) $row['client_id'];
        $out['assigned_broker_id'] = $row['assigned_broker_id'] ? (int) $row['assigned_broker_id'] : null;
        $out['assigned_at'] = $row['assigned_at'];
        $out['source'] = $row['source'];
        $out['last_customer_action_at'] = $row['last_customer_action_at'];
        $out['last_broker_action_at'] = $row['last_broker_action_at'];
        $out['kyc_required'] = (bool) $row['kyc_required'];
        $out['manual_review'] = (bool) $row['manual_review'];
        $out['risk_level'] = $row['risk_level'];

        if ($audience === 'admin') {
            $out['risk_score'] = (int) $row['risk_score'];
            $out['ip_address'] = $row['ip_address'];
            $out['fee_rule_id'] = $row['fee_rule_id'] ? (int) $row['fee_rule_id'] : null;
            $out['previous_status'] = $row['previous_status'];
            $out['deleted_at'] = isset($row['deleted_at']) ? $row['deleted_at'] : null;
        }

        return $out;
    }

    public static function requests(array $rows, Actor $actor)
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::request($row, $actor);
        }
        return $out;
    }

    /* ----------------------------------------------------------- offers */

    public static function offer(array $row, Actor $actor)
    {
        $audience = self::audience($actor);
        $currency = $row['currency'];

        $out = [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'request_id' => (int) $row['request_id'],
            'round' => (int) $row['round'],
            'direction' => $row['direction'],
            'amount' => self::money($row['amount_minor'], $currency),
            'fee' => self::money($row['fee_minor'], $currency),
            'total' => self::money($row['total_minor'], $currency),
            'currency' => $currency,
            'status' => $row['status'],
            'status_label' => OfferStatus::label($row['status']),
            'is_open' => OfferStatus::isOpen($row['status']),
            'sender_party' => $row['sender_party'],
            'recipient_party' => $row['recipient_party'],
            'created_by_type' => $row['created_by_type'],
            'created_by_label' => $row['created_by_label'],
            'is_counteroffer' => (bool) $row['is_counteroffer'],
            'requires_customer_approval' => (bool) $row['requires_customer_approval'],
            'message' => $row['message'],
            'parent_offer_id' => $row['parent_offer_id'] ? (int) $row['parent_offer_id'] : null,
            'sequence' => (int) $row['sequence'],
            'expires_at' => $row['expires_at'],
            'created_at' => $row['created_at'],
            'responded_at' => $row['responded_at'],
            'accepted_at' => $row['accepted_at'],
            'rejected_at' => $row['rejected_at'],
            'rejection_reason' => $row['rejection_reason'],
        ];

        // internal_note is broker/admin only — it is never emitted to a client.
        if ($audience !== 'customer') {
            $out['internal_note'] = $row['internal_note'];
            $out['fee_rule_id'] = $row['fee_rule_id'] ? (int) $row['fee_rule_id'] : null;
        }
        return $out;
    }

    public static function offers(array $rows, Actor $actor)
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::offer($row, $actor);
        }
        return $out;
    }

    /* --------------------------------------------------------- payments */

    public static function payment($row, Actor $actor)
    {
        if (!$row) {
            return null;
        }
        $currency = $row['currency'];
        $out = [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'request_id' => (int) $row['request_id'],
            'type' => $row['type'],
            'status' => $row['status'],
            'status_label' => PaymentStatus::label($row['status']),
            'status_tone' => PaymentStatus::tone($row['status']),
            'is_settled' => PaymentStatus::isSettled($row['status']),
            'currency' => $currency,
            'acquisition' => self::money($row['acquisition_minor'], $currency),
            'fee' => self::money($row['fee_minor'], $currency),
            'tax' => self::money($row['tax_minor'], $currency),
            'total' => self::money($row['total_minor'], $currency),
            'paid' => self::money($row['paid_minor'], $currency),
            'refunded' => self::money($row['refunded_minor'], $currency),
            'invoice_id' => $row['whmcs_invoice_id'] ? (int) $row['whmcs_invoice_id'] : null,
            'due_at' => $row['due_at'],
            'expires_at' => $row['expires_at'],
            'paid_at' => $row['paid_at'],
            'secured_at' => $row['secured_at'],
            'refunded_at' => $row['refunded_at'],
            'created_at' => $row['created_at'],
        ];

        if (self::audience($actor) !== 'customer') {
            $out['gateway'] = $row['gateway'];
            $out['escrow_provider'] = $row['escrow_provider'];
            $out['escrow_status'] = $row['escrow_status'];
            $out['failed_attempts'] = (int) $row['failed_attempts'];
            $out['failure_reason'] = $row['failure_reason'];
            $out['released_at'] = $row['released_at'];
        }
        // escrow_reference_enc is never serialised for anybody.
        return $out;
    }

    public static function payments(array $rows, Actor $actor)
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::payment($row, $actor);
        }
        return $out;
    }

    /* -------------------------------------------------------- transfers */

    public static function transfer($row, Actor $actor)
    {
        if (!$row) {
            return null;
        }
        $out = [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'request_id' => (int) $row['request_id'],
            'domain' => $row['domain'],
            'status' => $row['status'],
            'status_label' => TransferStatus::label($row['status']),
            'status_tone' => TransferStatus::tone($row['status']),
            'tracker_index' => TransferStatus::trackerIndex($row['status']),
            'tracker' => TransferStatus::TRACKER,
            'losing_registrar' => $row['losing_registrar'],
            'gaining_registrar' => $row['gaining_registrar'],
            'auth_code_status' => $row['auth_code_status'],
            'auth_code_hint' => $row['auth_code_hint'],
            'registrar_lock_released' => (bool) $row['registrar_lock_released'],
            'whois_privacy_disabled' => (bool) $row['whois_privacy_disabled'],
            'within_60_day_lock' => (bool) $row['within_60_day_lock'],
            'initiated_at' => $row['initiated_at'],
            'approved_at' => $row['approved_at'],
            'completed_at' => $row['completed_at'],
            'expires_at' => $row['expires_at'],
            'failure_reason' => $row['failure_reason'],
        ];
        if (self::audience($actor) !== 'customer') {
            $out['attempts'] = (int) $row['attempts'];
            $out['destination_account'] = $row['destination_account'];
            $out['registrar_notes'] = $row['registrar_notes'];
        }
        // auth_code_enc / auth_code_fingerprint / registry_response never ship.
        return $out;
    }

    /* --------------------------------------------------------- messages */

    public static function message(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'request_id' => (int) $row['request_id'],
            'thread' => $row['thread'],
            'sender_type' => $row['sender_type'],
            'sender_label' => $row['sender_label'],
            'body' => $row['body'],
            'is_internal' => (bool) $row['is_internal'],
            'document_id' => $row['document_id'] ? (int) $row['document_id'] : null,
            'read_at' => $row['read_at'],
            'created_at' => $row['created_at'],
        ];
    }

    public static function messages(array $rows)
    {
        return array_map([self::class, 'message'], $rows);
    }

    /* -------------------------------------------------------- documents */

    public static function document(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'uuid' => $row['uuid'],
            'request_id' => (int) $row['request_id'],
            'category' => $row['category'],
            'name' => $row['original_name'],
            'mime_type' => $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'sha256' => $row['sha256'],
            'visibility' => $row['visibility'],
            'scan_status' => $row['scan_status'],
            'description' => $row['description'],
            'uploaded_by' => $row['uploaded_by_label'],
            'created_at' => $row['created_at'],
            // storage_path and stored_name are deliberately absent.
        ];
    }

    public static function documents(array $rows)
    {
        return array_map([self::class, 'document'], $rows);
    }

    /* --------------------------------------------------------- disputes */

    public static function dispute(array $row, Actor $actor)
    {
        $out = [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'request_id' => (int) $row['request_id'],
            'reason_code' => $row['reason_code'],
            'reason_label' => isset(DisputeService::REASONS[$row['reason_code']])
                ? DisputeService::REASONS[$row['reason_code']] : $row['reason_code'],
            'description' => $row['description'],
            'status' => $row['status'],
            'severity' => $row['severity'],
            'amount_in_dispute' => self::money($row['amount_in_dispute_minor'], $row['currency']),
            'resolution' => $row['resolution'],
            'resolution_type' => $row['resolution_type'],
            'opened_by_type' => $row['opened_by_type'],
            'created_at' => $row['created_at'],
            'resolved_at' => $row['resolved_at'],
        ];
        if (self::audience($actor) !== 'customer') {
            $out['due_at'] = $row['due_at'];
            $out['resolved_by_type'] = $row['resolved_by_type'];
        }
        return $out;
    }

    /* ---------------------------------------------------------- brokers */

    public static function broker(array $row, Actor $actor)
    {
        $out = [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'name' => $row['display_name'],
            'role' => $row['role'],
            'status' => $row['status'],
            'languages' => $row['languages'],
            'specialities' => $row['specialities'],
            'biography' => $row['biography'],
            'avatar_url' => $row['avatar_url'],
            'completed_count' => (int) $row['completed_count'],
        ];
        if (self::audience($actor) !== 'customer') {
            $out['email'] = $row['email'];
            $out['phone'] = $row['phone'];
            $out['active_count'] = (int) $row['active_count'];
            $out['max_active_requests'] = (int) $row['max_active_requests'];
            $out['commission_percentage'] = $row['commission_percentage'] !== null
                ? (float) $row['commission_percentage'] : null;
            $out['whmcs_admin_id'] = $row['whmcs_admin_id'] ? (int) $row['whmcs_admin_id'] : null;
        }
        return $out;
    }

    /* --------------------------------------------------------- timeline */

    public static function timelineEntry(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'action' => $row['action'],
            'description' => self::describe($row['action']),
            'actor_type' => $row['actor_type'],
            'actor_label' => $row['actor_label'],
            'reason' => $row['reason'],
            'created_at' => $row['created_at'],
        ];
    }

    /** Human sentence for an audit action code. */
    const ACTION_LABELS = [
        'request.created' => 'Acquisition request submitted',
        'request.updated' => 'Request details updated',
        'request.status.changed' => 'Status updated',
        'request.status.override' => 'Status overridden by an administrator',
        'request.approved' => 'Request approved',
        'request.rejected' => 'Request rejected',
        'request.cancelled' => 'Request cancelled',
        'request.cancelled.admin' => 'Request cancelled by an administrator',
        'request.expired' => 'Request expired',
        'request.disputed' => 'Dispute raised',
        'request.refunded' => 'Acquisition refunded',
        'request.completed' => 'Acquisition completed',
        'request.transfer.started' => 'Domain transfer started',
        'request.transfer.verifying' => 'Transfer awaiting verification',
        'request.dispute.closed' => 'Dispute closed',
        'broker.assigned' => 'Broker assigned',
        'broker.claimed' => 'Broker claimed the request',
        'broker.unassigned' => 'Broker unassigned',
        'owner.contacted' => 'Registrant contacted',
        'owner.responded' => 'Registrant responded',
        'offer.created' => 'Offer submitted',
        'offer.countered' => 'Counteroffer submitted',
        'offer.accepted' => 'Offer accepted',
        'offer.rejected' => 'Offer declined',
        'offer.withdrawn' => 'Offer withdrawn',
        'offer.expired' => 'Offer expired',
        'message.sent' => 'Message sent',
        'document.uploaded' => 'Document uploaded',
        'payment.invoice.generated' => 'Invoice generated',
        'payment.received' => 'Payment received',
        'payment.secured' => 'Funds secured in escrow',
        'payment.released' => 'Funds released',
        'payment.refunded' => 'Refund issued',
        'payment.failed' => 'Payment failed',
        'payment.expired' => 'Payment window expired',
        'transfer.started' => 'Transfer initiated',
        'transfer.status.changed' => 'Transfer status updated',
        'transfer.completed' => 'Domain transfer completed',
        'transfer.failed' => 'Domain transfer failed',
        'transfer.expired' => 'Transfer window expired',
        'verification.checklist.created' => 'Verification checklist created',
        'verification.evidence.submitted' => 'Verification evidence recorded',
        'verification.approved' => 'Verification approved',
        'verification.rejected' => 'Verification rejected',
        'verification.waived' => 'Verification requirement waived',
        'dispute.opened' => 'Dispute opened',
        'dispute.resolved' => 'Dispute resolved',
        'dispute.rejected' => 'Dispute dismissed',
    ];

    public static function describe($action)
    {
        if (isset(self::ACTION_LABELS[$action])) {
            return self::ACTION_LABELS[$action];
        }
        return ucfirst(str_replace(['.', '_'], ' ', (string) $action));
    }

    public static function timeline(array $rows)
    {
        return array_map([self::class, 'timelineEntry'], $rows);
    }

    /** The full audit view, for staff with audit.view only. */
    public static function auditEntry(array $row)
    {
        return array_merge(self::timelineEntry($row), [
            'actor_id' => (int) $row['actor_id'],
            'entity_type' => $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'previous_value' => $row['previous_value'],
            'new_value' => $row['new_value'],
            'visibility' => $row['visibility'],
            'ip_address' => $row['ip_address'],
            'user_agent' => $row['user_agent'],
            'hash' => $row['record_hash'],
        ]);
    }
}
