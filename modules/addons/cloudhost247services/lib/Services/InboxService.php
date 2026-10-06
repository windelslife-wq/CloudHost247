<?php
/**
 * Unified inbox.
 *
 * The first channel is the platform's own ticketing system — what customers
 * already know as Support. The conversation list, read-state, labels,
 * assignment and replies all work on real ticket rows through the gateway; a
 * new channel (live chat, marketplaces, social DMs...) implements the same
 * shape and registers beside it without touching a single screen.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\ChsException;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\ForbiddenException;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\Settings;
use Chs\Core\ValidationException;

class InboxService
{
    const CHANNEL_TICKETS = 'tickets';

    /* ----------------------------------------------------------- channels -- */

    /** Registered channels, live first. New channels append here. */
    public function channels()
    {
        return [
            [
                'id'    => self::CHANNEL_TICKETS,
                'label' => 'Support conversations',
                'live'  => true,
                'desc'  => 'Your support history with our team.',
            ],
        ];
    }

    /* --------------------------------------------------- client: browsing -- */

    /**
     * Client conversation list with unread state computed from read markers.
     *
     * @return array[]
     */
    public function conversationsFor($clientId, $limit = 100, $search = '', $statusFilter = '')
    {
        if (!Settings::bool('inbox_enabled', true)) {
            throw new ServiceUnavailableException('The inbox is temporarily unavailable.');
        }
        $rows = Platform::gateway()->inboxConversations((int) $clientId, (int) $limit);
        $markers = $this->readMarkers('client', (int) $clientId);

        $out = [];
        foreach ($rows as $row) {
            $key = self::CHANNEL_TICKETS . ':' . (int) $row['id'];
            $lastSeen = isset($markers[$key]) ? (int) $markers[$key] : 0;

            // Unread when the latest activity is newer than the marked message.
            $lastMessageTs = \Chs\Core\Clock::toTime($row['lastreply'] ?: $row['date']);
            $conversation = [
                'channel'     => self::CHANNEL_TICKETS,
                'id'          => (int) $row['id'],
                'tid'         => isset($row['tid']) ? $row['tid'] : '',
                'subject'     => $row['subject'],
                'status'      => $row['status'],
                'urgency'     => isset($row['urgency']) ? $row['urgency'] : 'Medium',
                'replies'     => isset($row['replies']) ? (int) $row['replies'] : 0,
                'date'        => $row['date'],
                'last_reply'  => $row['lastreply'],
                'unread'      => false,
            ];
            if ($search !== '' && stripos($conversation['subject'], $search) === false) {
                continue;
            }
            if ($statusFilter !== '' && strcasecmp($conversation['status'], $statusFilter) !== 0) {
                continue;
            }
            if ($lastMessageTs !== null && $lastSeen === 0) {
                $conversation['unread'] = true;
            } else {
                // More precise check costs one query; only for conversations near the boundary.
                $latest = Db::query(
                    'SELECT MAX(id) AS m FROM tblticketreplies WHERE tid = ?', [(int) $row['id']]);
                $latestId = $latest && $latest[0]['m'] !== null ? (int) $latest[0]['m'] : 0;
                $conversation['unread'] = $latestId > $lastSeen;
            }
            $out[] = $conversation;
        }
        return $out;
    }

    /**
     * One thread for the owning client, marked read on open.
     */
    public function threadFor($clientId, $ticketId)
    {
        $ticketId = (int) $ticketId;
        $this->assertClientOwnsTicket($clientId, $ticketId);

        $messages = Platform::gateway()->inboxMessages($ticketId);
        if (!$messages) {
            throw new NotFoundException('Conversation not found.');
        }

        $lastId = 0;
        foreach ($messages as $m) {
            $lastId = max($lastId, (int) $m['id']);
        }
        $this->markRead('client', (int) $clientId, self::CHANNEL_TICKETS, (string) $ticketId, $lastId);

        $meta = Db::query('SELECT subject, status, urgency, date, lastreply FROM tbltickets WHERE id = ?', [$ticketId]);
        return [
            'id'        => $ticketId,
            'subject'   => $meta ? $meta[0]['subject'] : '',
            'status'    => $meta ? $meta[0]['status'] : '',
            'urgency'   => $meta ? $meta[0]['urgency'] : '',
            'date'      => $meta ? $meta[0]['date'] : '',
            'last_reply' => $meta ? $meta[0]['lastreply'] : '',
            'messages'  => $messages,
        ];
    }

    /**
     * Client reply → a real ticket reply through the platform.
     */
    public function reply($clientId, $ticketId, $body)
    {
        $this->assertClientOwnsTicket($clientId, $ticketId);
        $body = trim((string) $body);
        if ($body === '' || strlen($body) > 8000) {
            throw new ValidationException(['body' => 'Write between 1 and 8,000 characters.']);
        }
        \Chs\Core\RateLimiter::hitOrFail('inbox_reply', 'client:' . (int) $clientId, 60, 86400);
        $replyId = Platform::gateway()->inboxReply((int) $ticketId, $body, 'client', (int) $clientId);
        $this->markRead('client', (int) $clientId, self::CHANNEL_TICKETS, (string) $ticketId, (int) $replyId);
        Audit::client((int) $clientId, 'inbox.reply', ['ticket' => (int) $ticketId]);
        return $replyId;
    }

    public function unreadCountFor($clientId)
    {
        $count = 0;
        foreach ($this->conversationsFor((int) $clientId, 200) as $c) {
            if ($c['unread']) {
                $count++;
            }
        }
        return $count;
    }

    /* -------------------------------------------------------------- admin -- */

    /** Admin work queue with labels and assignment. */
    public function adminQueue($limit = 200, $search = '', $labelId = 0, $unassigned = false)
    {
        $rows = Platform::gateway()->inboxAdminList((int) $limit, (bool) $unassigned);
        $labels = $this->labelsForTickets();
        $out = [];
        foreach ($rows as $row) {
            if ($search !== '' && stripos($row['subject'] . ' ' . (isset($row['email']) ? $row['email'] : ''), $search) === false) {
                continue;
            }
            $ticketLabels = isset($labels[(int) $row['id']]) ? $labels[(int) $row['id']] : [];
            if ($labelId > 0) {
                $has = false;
                foreach ($ticketLabels as $l) {
                    if ((int) $l['id'] === (int) $labelId) {
                        $has = true;
                    }
                }
                if (!$has) {
                    continue;
                }
            }
            $out[] = [
                'id'        => (int) $row['id'],
                'subject'   => $row['subject'],
                'status'    => $row['status'],
                'urgency'   => isset($row['urgency']) ? $row['urgency'] : 'Medium',
                'email'     => isset($row['email']) ? $row['email'] : '',
                'contact'   => isset($row['contact_name']) ? $row['contact_name'] : '',
                'assigned'  => isset($row['flag']) ? (int) $row['flag'] : 0,
                'replies'   => isset($row['replies']) ? (int) $row['replies'] : 0,
                'date'      => $row['date'],
                'last_reply' => $row['lastreply'],
                'labels'    => $ticketLabels,
            ];
        }
        return $out;
    }

    public function adminAssign($ticketId, $adminId, $assignTo)
    {
        Platform::gateway()->inboxAssign((int) $ticketId, (int) $assignTo);
        Audit::admin($adminId, 'inbox.assign', ['ticket' => (int) $ticketId, 'assignee' => (int) $assignTo]);
    }

    public function adminSetStatus($ticketId, $adminId, $status)
    {
        if (!in_array($status, ['Open', 'Answered', 'Customer-Reply', 'In Progress', 'On Hold', 'Closed'], true)) {
            throw new ValidationException(['status' => 'Unsupported conversation status.']);
        }
        Platform::gateway()->inboxSetStatus((int) $ticketId, $status);
        Audit::admin($adminId, 'inbox.status', ['ticket' => (int) $ticketId, 'status' => $status]);
    }

    /* -------------------------------------------------------------- labels -- */

    public function labels()
    {
        return Db::all('inbox_labels', [], 'name ASC', 100);
    }

    public function saveLabel($adminId, $labelId, $name, $colour)
    {
        $name = trim((string) $name);
        if ($name === '' || strlen($name) > 48) {
            throw new ValidationException(['name' => 'Label name is required.']);
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $colour)) {
            throw new ValidationException(['colour' => 'Colour must look like #2288cc.']);
        }
        if ($labelId) {
            Db::update('inbox_labels', ['id' => (int) $labelId], ['name' => $name, 'colour' => $colour]);
        } else {
            $labelId = Db::insert('inbox_labels', [
                'name'       => $name,
                'colour'     => $colour,
                'created_at' => Clock::now(),
            ]);
        }
        Audit::admin($adminId, 'inbox.label_saved', ['label' => (int) $labelId]);
        return (int) $labelId;
    }

    public function deleteLabel($adminId, $labelId)
    {
        // Clean junctions explicitly — works regardless of the engine's
        // foreign-key pragma state, so deleters can never orphan rows.
        Db::delete('inbox_ticket_labels', ['label_id' => (int) $labelId]);
        Db::delete('inbox_labels', ['id' => (int) $labelId]);
        Audit::admin($adminId, 'inbox.label_deleted', ['label' => (int) $labelId]);
    }

    public function attachLabel($adminId, $ticketId, $labelId)
    {
        if (!Db::first('inbox_labels', ['id' => (int) $labelId])) {
            throw new NotFoundException('Label not found.');
        }
        if (!Db::first('inbox_ticket_labels', ['ticket_id' => (int) $ticketId, 'label_id' => (int) $labelId])) {
            Db::insert('inbox_ticket_labels', [
                'ticket_id' => (int) $ticketId,
                'label_id'  => (int) $labelId,
            ]);
        }
    }

    public function detachLabel($adminId, $ticketId, $labelId)
    {
        Db::exec(
            'DELETE FROM ' . Db::t('inbox_ticket_labels') . ' WHERE ticket_id = ? AND label_id = ?',
            [(int) $ticketId, (int) $labelId]
        );
    }

    /* ------------------------------------------------------------ internal -- */

    /** map "channel:id" → last seen message id for this user */
    protected function readMarkers($userType, $userId)
    {
        $rows = Db::all('inbox_reads', ['user_type' => $userType, 'user_id' => (int) $userId]);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['channel'] . ':' . $row['conversation_key']] = (int) $row['last_seen_message_id'];
        }
        return $map;
    }

    public function markRead($userType, $userId, $channel, $conversationKey, $lastMessageId)
    {
        $existing = Db::first('inbox_reads', [
            'user_type'        => $userType,
            'user_id'          => (int) $userId,
            'channel'          => $channel,
            'conversation_key' => (string) $conversationKey,
        ]);
        if ($existing) {
            Db::update('inbox_reads', ['id' => (int) $existing['id']], [
                'last_seen_message_id' => max((int) $existing['last_seen_message_id'], (int) $lastMessageId),
                'updated_at'           => Clock::now(),
            ]);
        } else {
            Db::insert('inbox_reads', [
                'user_type'            => $userType,
                'user_id'              => (int) $userId,
                'channel'              => $channel,
                'conversation_key'     => (string) $conversationKey,
                'last_seen_message_id' => (int) $lastMessageId,
                'updated_at'           => Clock::now(),
            ]);
        }
    }

    protected function assertClientOwnsTicket($clientId, $ticketId)
    {
        $row = Db::query('SELECT userid FROM tbltickets WHERE id = ?', [(int) $ticketId]);
        if (!$row || (int) $row[0]['userid'] !== (int) $clientId) {
            throw new NotFoundException('Conversation not found.');
        }
    }

    /** ticket_id => [label rows] for everything in the admin queue */
    protected function labelsForTickets()
    {
        $rows = Db::query(
            'SELECT tl.ticket_id, l.id, l.name, l.colour FROM ' . Db::t('inbox_ticket_labels') . ' tl'
            . ' JOIN ' . Db::t('inbox_labels') . ' l ON l.id = tl.label_id'
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['ticket_id']][] = ['id' => (int) $row['id'], 'name' => $row['name'], 'colour' => $row['colour']];
        }
        return $map;
    }
}
