<?php
/**
 * Support-operator conversation store.
 *
 * Conversations are keyed by an unguessable 128-bit public_id so guests can
 * hold a conversation without an account. Ownership is enforced three ways:
 * the owning client id, a session claim list for guests, or an admin
 * session — a customer can never read another customer's thread.
 */

namespace Ch247Ai\SupportOperator;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Validator;

class ConversationService
{
    const STATUS_AI_ACTIVE = 'ai_active';
    const STATUS_WAITING_FOR_HUMAN = 'waiting_for_human';
    const STATUS_HUMAN_ACTIVE = 'human_active';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CLOSED = 'closed';

    const STATUSES = [
        self::STATUS_AI_ACTIVE,
        self::STATUS_WAITING_FOR_HUMAN,
        self::STATUS_HUMAN_ACTIVE,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    const AUTHOR_CUSTOMER = 'customer';
    const AUTHOR_AI = 'ai';
    const AUTHOR_AGENT = 'agent';
    const AUTHOR_SYSTEM = 'system';

    const SESSION_KEY = 'ch247ai_support_convs';
    const MAX_BODY = 4000;
    const MAX_SESSION_CONVS = 20;

    public static function create($clientId, $name, $email)
    {
        $now = Clock::now();
        $publicId = bin2hex(random_bytes(16));
        $id = Db::insert('support_conversations', [
            'public_id' => $publicId,
            'client_id' => $clientId > 0 ? (int) $clientId : null,
            'guest_name' => Validator::clip(trim((string) $name), 120),
            'guest_email' => Validator::clip(trim((string) $email), 190),
            'status' => self::STATUS_AI_ACTIVE,
            'message_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::claimGuest($publicId);
        Audit::record($clientId > 0 ? 'client' : 'guest', (int) $clientId, 'ai.support.started', [
            'entity_type' => 'support_conversation',
            'entity_id' => (int) $id,
            'context' => ['public_id' => substr($publicId, 0, 8)],
        ]);
        return self::find((int) $id);
    }

    public static function find($id)
    {
        return Db::first('support_conversations', ['id' => (int) $id]);
    }

    public static function findByPublic($publicId)
    {
        if (!is_string($publicId) || !preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }
        return Db::first('support_conversations', ['public_id' => $publicId]);
    }

    /**
     * May this caller read/write the conversation? $ctx carries
     * client_id (int|null), admin_id (int|null) and the guest session
     * claim list. Admins pass; owners pass; everyone else fails closed.
     */
    public static function visibleTo(array $row, array $ctx)
    {
        if (!empty($ctx['admin_id'])) {
            return true;
        }
        $clientId = isset($ctx['client_id']) ? (int) $ctx['client_id'] : 0;
        if ($clientId > 0 && (int) $row['client_id'] === $clientId) {
            return true;
        }
        $claimed = isset($ctx['session_convs']) && is_array($ctx['session_convs']) ? $ctx['session_convs'] : [];
        return in_array($row['public_id'], $claimed, true);
    }

    /** Remember a guest-owned conversation in this PHP session. */
    public static function claimGuest($publicId)
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        $list = isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])
            ? $_SESSION[self::SESSION_KEY] : [];
        if (!in_array($publicId, $list, true)) {
            $list[] = $publicId;
        }
        $_SESSION[self::SESSION_KEY] = array_slice($list, -1 * self::MAX_SESSION_CONVS);
    }

    public static function sessionClaims()
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return [];
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function addMessage($conversationId, $author, $body, array $meta = [])
    {
        if (!in_array($author, [self::AUTHOR_CUSTOMER, self::AUTHOR_AI, self::AUTHOR_AGENT, self::AUTHOR_SYSTEM], true)) {
            $author = self::AUTHOR_SYSTEM;
        }
        $now = Clock::now();
        $id = Db::insert('support_messages', [
            'conversation_id' => (int) $conversationId,
            'author' => $author,
            'body' => Validator::clip(trim((string) $body), self::MAX_BODY),
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $now,
        ]);
        $row = self::find($conversationId);
        if ($row !== null) {
            Db::update('support_conversations', ['id' => (int) $conversationId], [
                'message_count' => (int) $row['message_count'] + 1,
                'last_message_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return (int) $id;
    }

    public static function messages($conversationId, $limit = 100)
    {
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('support_messages') . ' WHERE conversation_id = ? ORDER BY id ASC LIMIT ' . (int) $limit,
            [(int) $conversationId]
        );
        foreach ($rows as &$row) {
            $row['meta_decoded'] = $row['meta'] ? json_decode((string) $row['meta'], true) : [];
        }
        return $rows;
    }

    public static function setStatus($conversationId, $status)
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        Db::update('support_conversations', ['id' => (int) $conversationId], [
            'status' => $status,
            'updated_at' => Clock::now(),
        ]);
        return true;
    }

    public static function setFlow($conversationId, $flow, $flowData = null)
    {
        Db::update('support_conversations', ['id' => (int) $conversationId], [
            'flow' => $flow,
            'flow_data' => $flowData === null ? null : (string) $flowData,
            'updated_at' => Clock::now(),
        ]);
    }

    public static function setContact($conversationId, $name, $email)
    {
        Db::update('support_conversations', ['id' => (int) $conversationId], [
            'guest_name' => Validator::clip(trim((string) $name), 120),
            'guest_email' => Validator::clip(trim((string) $email), 190),
            'updated_at' => Clock::now(),
        ]);
    }

    /** Plain-text transcript for tickets and agent handoff. */
    public static function transcript($conversationId, $limit = 50)
    {
        $lines = [];
        foreach (self::messages($conversationId, $limit) as $message) {
            $who = $message['author'] === self::AUTHOR_CUSTOMER ? 'Customer'
                : ($message['author'] === self::AUTHOR_AI ? 'AI Operator'
                : ($message['author'] === self::AUTHOR_AGENT ? 'Support agent' : 'System'));
            $lines[] = '[' . $message['created_at'] . '] ' . $who . ': ' . $message['body'];
        }
        return implode("\n\n", $lines);
    }

    public static function overview()
    {
        $counts = [];
        foreach (self::STATUSES as $status) {
            $counts[$status] = Db::count('support_conversations', ['status' => $status]);
        }
        $counts['total'] = array_sum($counts);
        $counts['newsletter'] = Db::count('support_newsletter', []);
        $counts['escalations'] = Db::count('support_conversations', ['status' => self::STATUS_WAITING_FOR_HUMAN])
            + Db::count('support_conversations', ['status' => self::STATUS_HUMAN_ACTIVE]);
        return $counts;
    }

    /** Paginated admin list with status filter + customer/email/public-id search. */
    public static function adminList($status = '', $search = '', $page = 1, $perPage = 25)
    {
        $page = max(1, (int) $page);
        $perPage = min(100, max(1, (int) $perPage));
        $where = [];
        $bind = [];
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'status = ?';
            $bind[] = $status;
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $where[] = '(guest_name LIKE ? ESCAPE \'\\\' OR guest_email LIKE ? ESCAPE \'\\\' OR public_id LIKE ? ESCAPE \'\\\' OR CAST(COALESCE(client_id, 0) AS TEXT) = ?)';
            $bind[] = $like;
            $bind[] = $like;
            $bind[] = $like;
            $bind[] = $search;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $totalRows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('support_conversations') . $whereSql, $bind);
        $total = $totalRows ? (int) $totalRows[0]['c'] : 0;
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('support_conversations') . $whereSql
            . ' ORDER BY last_message_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
