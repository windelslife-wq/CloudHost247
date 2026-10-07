<?php
/**
 * Escalation: hand a conversation to CloudHost247 Support.
 *
 * Escalations land in the native WHMCS ticket system (OpenTicket) with the
 * full transcript attached — the operator never builds a parallel support
 * queue. Online agents get notified and assigned; when nobody is online
 * the ticket still lands in the support queue so nothing is lost.
 */

namespace Ch247Ai\SupportOperator;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Settings;
use Ch247Ai\Core\Whmcs;

class EscalationService
{
    const USER_REQUESTED_HUMAN = 'USER_REQUESTED_HUMAN';
    const ACCOUNT_SPECIFIC_REQUEST = 'ACCOUNT_SPECIFIC_REQUEST';
    const BILLING_SUPPORT_REQUIRED = 'BILLING_SUPPORT_REQUIRED';
    const TECHNICAL_SUPPORT_REQUIRED = 'TECHNICAL_SUPPORT_REQUIRED';
    const SERVER_SUPPORT_REQUIRED = 'SERVER_SUPPORT_REQUIRED';
    const COMPLAINT = 'COMPLAINT';
    const REFUND_REQUEST = 'REFUND_REQUEST';
    const SECURITY_RELATED = 'SECURITY_RELATED';
    const AI_UNABLE_TO_ANSWER = 'AI_UNABLE_TO_ANSWER';
    const OTHER = 'OTHER';

    public static function labels()
    {
        return [
            self::USER_REQUESTED_HUMAN => 'Customer asked for a human',
            self::ACCOUNT_SPECIFIC_REQUEST => 'Needs account access',
            self::BILLING_SUPPORT_REQUIRED => 'Billing issue',
            self::TECHNICAL_SUPPORT_REQUIRED => 'Technical issue',
            self::SERVER_SUPPORT_REQUIRED => 'Server incident',
            self::COMPLAINT => 'Complaint',
            self::REFUND_REQUEST => 'Refund request',
            self::SECURITY_RELATED => 'Security matter',
            self::AI_UNABLE_TO_ANSWER => 'AI could not answer',
            self::OTHER => 'Other',
        ];
    }

    /**
     * Escalate: create (or reuse) a WHMCS ticket, assign an online agent
     * when one exists, flip the conversation to waiting_for_human.
     * Returns ['ticket_id','assigned_admin_id','online'].
     */
    public static function escalate(array $conv, $reason, $detail = '')
    {
        $labels = self::labels();
        if (!isset($labels[$reason])) {
            $reason = self::OTHER;
        }
        $online = in_array(PresenceService::availability()['status'],
            [PresenceService::ONLINE, PresenceService::BUSY], true);
        $ticketId = (int) ($conv['ticket_id'] ?: 0);
        if ($ticketId <= 0) {
            $ticketId = self::openTicket($conv, $reason, $detail);
        }
        $assigned = $online ? PresenceService::recentOnlineAdmin() : 0;
        $patch = [
            'status' => ConversationService::STATUS_WAITING_FOR_HUMAN,
            'escalation_reason' => $reason,
            'flow' => null,
            'flow_data' => null,
            'updated_at' => Clock::now(),
        ];
        if ($ticketId > 0) {
            $patch['ticket_id'] = $ticketId;
        }
        if ($assigned > 0) {
            $patch['assigned_admin_id'] = $assigned;
        }
        Db::update('support_conversations', ['id' => (int) $conv['id']], $patch);
        Audit::record('system', 0, 'ai.support.escalated', [
            'entity_type' => 'support_conversation',
            'entity_id' => (int) $conv['id'],
            'context' => ['reason' => $reason, 'ticket_id' => $ticketId, 'online' => $online],
        ]);
        if ($online) {
            self::notifyAgents($conv, $reason, $ticketId);
        }
        return ['ticket_id' => $ticketId, 'assigned_admin_id' => $assigned, 'online' => $online];
    }

    private static function openTicket(array $conv, $reason, $detail)
    {
        $labels = self::labels();
        $subject = 'AI Support escalation: ' . $labels[$reason] . ' — ' . substr($conv['public_id'], 0, 8);
        $lines = [
            'Reason: ' . $labels[$reason] . ' (' . $reason . ')',
            'Conversation: ' . $conv['public_id'],
            'Visitor: ' . trim($conv['guest_name'] . ' <' . $conv['guest_email'] . '>'),
        ];
        if ($detail !== '') {
            $lines[] = 'Customer said: ' . $detail;
        }
        $lines[] = '';
        $lines[] = '--- Transcript ---';
        $lines[] = ConversationService::transcript((int) $conv['id']);
        $params = [
            'deptid' => max(1, Settings::int('support_ticket_dept', 1)),
            'subject' => $subject,
            'message' => implode("\n", $lines),
            'priority' => in_array($reason, [self::COMPLAINT, self::REFUND_REQUEST, self::SECURITY_RELATED, self::SERVER_SUPPORT_REQUIRED], true) ? 'High' : 'Medium',
        ];
        if ((int) $conv['client_id'] > 0) {
            $params['userid'] = (int) $conv['client_id'];
        } else {
            $params['name'] = $conv['guest_name'] !== '' ? $conv['guest_name'] : 'Website visitor';
            $params['email'] = $conv['guest_email'];
        }
        try {
            $res = Whmcs::api('OpenTicket', $params);
        } catch (\Exception $e) {
            return 0;
        } catch (\Throwable $e) {
            return 0;
        }
        if (!is_array($res) || ($res['result'] ?? '') !== 'success') {
            return 0;
        }
        return (int) ($res['id'] ?? $res['ticketid'] ?? 0);
    }

    /** Best-effort admin alert; the ticket itself is the durable signal. */
    private static function notifyAgents(array $conv, $reason, $ticketId)
    {
        $labels = self::labels();
        $message = 'AI Support Operator escalated a conversation (' . substr($conv['public_id'], 0, 8)
            . ') — ' . $labels[$reason] . ', ticket #' . $ticketId . '.';
        try {
            Whmcs::api('SendAdminEmail', [
                'customsubject' => 'AI Support escalation — ticket #' . $ticketId,
                'custommessage' => $message,
                'customtype' => 'general',
                'customvars' => base64_encode(serialize(['ticket_id' => $ticketId])),
            ]);
        } catch (\Exception $e) {
            if (function_exists('logActivity')) {
                logActivity($message);
            }
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity($message);
            }
        }
    }

    /**
     * Agent reply from the admin support page. Appends an agent message,
     * marks the thread human_active, and mirrors the reply into the
     * linked ticket as a note (best-effort).
     */
    public static function agentReply($conversationId, $adminId, $text)
    {
        $conv = ConversationService::find($conversationId);
        if ($conv === null) {
            return false;
        }
        ConversationService::addMessage((int) $conversationId,
            ConversationService::AUTHOR_AGENT, $text,
            ['admin_id' => (int) $adminId]);
        Db::update('support_conversations', ['id' => (int) $conversationId], [
            'status' => ConversationService::STATUS_HUMAN_ACTIVE,
            'assigned_admin_id' => (int) $adminId,
            'flow' => null,
            'flow_data' => null,
            'updated_at' => Clock::now(),
        ]);
        if ((int) $conv['ticket_id'] > 0) {
            try {
                Whmcs::api('AddTicketNote', [
                    'ticketid' => (int) $conv['ticket_id'],
                    'message' => "Support chat reply by admin #{$adminId}:\n\n" . trim((string) $text),
                ]);
            } catch (\Exception $e) {
                // The chat message is stored; ticket mirroring is a courtesy.
            } catch (\Throwable $e) {
                // The chat message is stored; ticket mirroring is a courtesy.
            }
        }
        Audit::record('admin', (int) $adminId, 'ai.support.agent_reply', [
            'entity_type' => 'support_conversation',
            'entity_id' => (int) $conversationId,
            'context' => ['ticket_id' => (int) $conv['ticket_id']],
        ]);
        return true;
    }
}
