<?php
/**
 * WRITE tools over the support desk.
 *
 * Every tool here obeys the same four rules:
 *
 *  1. It mutates through the WHMCS API, never with hand-written SQL, so
 *     WHMCS stays the authoritative writer and its own hooks, logging and
 *     notifications fire exactly as they would for a human agent.
 *  2. It fails closed. A missing table or an unavailable localAPI raises
 *     SERVICE_UNAVAILABLE rather than pretending the write happened.
 *  3. It carries a `verify` closure that RE-READS the database afterwards.
 *     The tool result is only reported as success when that read confirms
 *     the change (brief §29 — never claim an action succeeded until
 *     verification confirms it).
 *  4. It is registered with a non-READ risk, so ToolExecutor refuses to run
 *     it without an approved, unexpired, argument-matched approval row.
 *
 * Verification deliberately re-reads through a different path than the write
 * used: we write via the API and confirm via SQL. A write that silently did
 * nothing therefore shows up as a verification failure instead of a success.
 */

namespace Ch247Ai\Tools\Writers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Core\Validator;
use Ch247Ai\Core\Whmcs;
use Ch247Ai\Tools\ToolDefinition;

/** Fail closed when a table the write depends on is absent. */
function ch247ai_w_require(array $tables)
{
    foreach ($tables as $table) {
        if (!Db::whmcsTableExists($table)) {
            throw new ServiceUnavailableException('SERVICE_UNAVAILABLE: table ' . $table . ' does not exist in this WHMCS installation; refusing to write.');
        }
    }
}

/** Writes are admin-scope only; a client session may never reach one. */
function ch247ai_w_assert_admin_scope(array $ctx)
{
    if (isset($ctx['scope']) && $ctx['scope'] === 'client') {
        throw new ServiceUnavailableException('REFUSED: write tools are not available in client scope.');
    }
}

function ch247ai_w_ticket(array $args)
{
    $id = (int) $args['ticket_id'];
    $row = Db::query('SELECT id, tid, userid, status FROM ' . Db::whmcs('tbltickets') . ' WHERE id = ?', [$id]);
    if ($row === []) {
        throw new NotFoundException('Ticket ' . $id . ' does not exist.');
    }
    return $row[0];
}

/** Count replies on a ticket — the before/after measure for reply writes. */
function ch247ai_w_reply_count($ticketId)
{
    $rows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::whmcs('tblticketreplies') . ' WHERE tid = ?', [(int) $ticketId]);
    return $rows === [] ? 0 : (int) $rows[0]['c'];
}

function ch247ai_ticket_writers()
{
    return [
        // ------------------------------------------------------------------
        // Post a public reply. Customer-visible, so it carries the higher
        // risk class and the stricter verification.
        // ------------------------------------------------------------------
        new ToolDefinition(
            'write_ticket_reply',
            'ai.write.ticket',
            'WRITE_CUSTOMER_VISIBLE',
            'Post a public reply to a support ticket. The customer receives it.',
            [
                'ticket_id' => ['type' => 'integer', 'required' => true, 'description' => 'tbltickets.id'],
                'message' => ['type' => 'string', 'required' => true, 'description' => 'Reply body, plain text'],
            ],
            function (array $args, array $ctx) {
                ch247ai_w_assert_admin_scope($ctx);
                ch247ai_w_require(['tbltickets', 'tblticketreplies']);
                $ticket = ch247ai_w_ticket($args);
                $message = trim((string) $args['message']);
                if ($message === '') {
                    throw new \Ch247Ai\Core\ValidationException('Reply message cannot be empty.');
                }

                $before = ch247ai_w_reply_count($ticket['id']);
                Whmcs::api('AddTicketReply', [
                    'ticketid' => (int) $ticket['id'],
                    'message' => $message,
                    'adminusername' => Whmcs::adminUsernameForApi(),
                ]);

                return [
                    'ticket_id' => (int) $ticket['id'],
                    'tid' => (string) $ticket['tid'],
                    'client_id' => (int) $ticket['userid'],
                    'replies_before' => $before,
                    'message_length' => strlen($message),
                    '_verify_before' => $before,
                ];
            },
            ['tbltickets', 'tblticketreplies'],
            false,
            function (array $args, array $ctx, array $result) {
                $before = isset($result['_verify_before']) ? (int) $result['_verify_before'] : -1;
                $after = ch247ai_w_reply_count((int) $args['ticket_id']);
                if ($before < 0) {
                    return ['verified' => false, 'note' => 'No baseline reply count was captured; cannot confirm the reply landed.'];
                }
                if ($after <= $before) {
                    return ['verified' => false, 'note' => 'Reply count on ticket ' . (int) $args['ticket_id'] . ' is still ' . $after . '; the reply was NOT posted.'];
                }
                return ['verified' => true, 'note' => 'Confirmed: tblticketreplies for ticket ' . (int) $args['ticket_id'] . ' went from ' . $before . ' to ' . $after . '.'];
            }
        ),

        // ------------------------------------------------------------------
        // Internal note. Staff-only, so WRITE_LOW — still approval-gated
        // under the all-gated policy, but a lower class for reporting.
        // ------------------------------------------------------------------
        new ToolDefinition(
            'write_ticket_note',
            'ai.write.ticket',
            'WRITE_LOW',
            'Attach an internal staff note to a ticket. Not visible to the customer.',
            [
                'ticket_id' => ['type' => 'integer', 'required' => true, 'description' => 'tbltickets.id'],
                'note' => ['type' => 'string', 'required' => true, 'description' => 'Internal note text'],
            ],
            function (array $args, array $ctx) {
                ch247ai_w_assert_admin_scope($ctx);
                ch247ai_w_require(['tbltickets', 'tblticketnotes']);
                $ticket = ch247ai_w_ticket($args);
                $note = trim((string) $args['note']);
                if ($note === '') {
                    throw new \Ch247Ai\Core\ValidationException('Note cannot be empty.');
                }
                $rows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::whmcs('tblticketnotes') . ' WHERE tid = ?', [(int) $ticket['id']]);
                $before = $rows === [] ? 0 : (int) $rows[0]['c'];

                Whmcs::api('AddTicketNote', [
                    'ticketid' => (int) $ticket['id'],
                    'message' => $note,
                    'markdown' => false,
                ]);

                return [
                    'ticket_id' => (int) $ticket['id'],
                    'notes_before' => $before,
                    '_verify_before' => $before,
                ];
            },
            ['tbltickets', 'tblticketnotes'],
            false,
            function (array $args, array $ctx, array $result) {
                $before = isset($result['_verify_before']) ? (int) $result['_verify_before'] : -1;
                $rows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::whmcs('tblticketnotes') . ' WHERE tid = ?', [(int) $args['ticket_id']]);
                $after = $rows === [] ? 0 : (int) $rows[0]['c'];
                if ($before < 0 || $after <= $before) {
                    return ['verified' => false, 'note' => 'Note count on ticket ' . (int) $args['ticket_id'] . ' is still ' . $after . '; the note was NOT saved.'];
                }
                return ['verified' => true, 'note' => 'Confirmed: tblticketnotes for ticket ' . (int) $args['ticket_id'] . ' went from ' . $before . ' to ' . $after . '.'];
            }
        ),

        // ------------------------------------------------------------------
        // Status change. Verified by reading the status back.
        // ------------------------------------------------------------------
        new ToolDefinition(
            'write_ticket_status',
            'ai.write.ticket',
            'WRITE_LOW',
            'Change a ticket status (e.g. Answered, Closed, On Hold).',
            [
                'ticket_id' => ['type' => 'integer', 'required' => true, 'description' => 'tbltickets.id'],
                'status' => ['type' => 'string', 'required' => true, 'description' => 'Target status label'],
            ],
            function (array $args, array $ctx) {
                ch247ai_w_assert_admin_scope($ctx);
                ch247ai_w_require(['tbltickets']);
                $ticket = ch247ai_w_ticket($args);
                $status = Validator::clip(trim((string) $args['status']), 30);
                $allowed = ['Open', 'Answered', 'Customer-Reply', 'On Hold', 'In Progress', 'Closed'];
                if (!in_array($status, $allowed, true)) {
                    throw new \Ch247Ai\Core\ValidationException('Status must be one of: ' . implode(', ', $allowed));
                }

                Whmcs::api('UpdateTicket', ['ticketid' => (int) $ticket['id'], 'status' => $status]);

                return [
                    'ticket_id' => (int) $ticket['id'],
                    'status_before' => (string) $ticket['status'],
                    'status_requested' => $status,
                ];
            },
            ['tbltickets'],
            false,
            function (array $args, array $ctx, array $result) {
                $want = isset($result['status_requested']) ? (string) $result['status_requested'] : '';
                $rows = Db::query('SELECT status FROM ' . Db::whmcs('tbltickets') . ' WHERE id = ?', [(int) $args['ticket_id']]);
                $now = $rows === [] ? '' : (string) $rows[0]['status'];
                if ($now !== $want) {
                    return ['verified' => false, 'note' => 'Ticket ' . (int) $args['ticket_id'] . ' status reads "' . $now . '", expected "' . $want . '"; the change did NOT apply.'];
                }
                return ['verified' => true, 'note' => 'Confirmed: ticket ' . (int) $args['ticket_id'] . ' status is now "' . $now . '".'];
            }
        ),
    ];
}
