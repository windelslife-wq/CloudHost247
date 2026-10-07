<?php
/**
 * WRITE tools that touch billing — deliberately the narrowest set in the
 * module.
 *
 * What is here: sending an invoice reminder, which communicates about money
 * without moving any.
 *
 * What is NOT here, and why:
 *
 *   - Marking an invoice paid. WHMCS is the authoritative payment executor;
 *     payment state is set by a gateway callback that verified funds, never
 *     by an assistant. A "mark as paid" tool would let an AI fabricate a
 *     receipt, which is exactly what the safety rules forbid.
 *   - Applying credit, issuing refunds, editing invoice lines, changing
 *     prices. All of these move money and none of them can be verified as
 *     "correct" by re-reading a row — only as "applied".
 *   - Suspending or terminating services. Availability-class actions need an
 *     unsuspend path and a blast-radius check that this module does not have.
 *
 * Those stay human. The reminder below is the one billing action that is
 * reversible in practice (worst case: a customer gets an email twice) and is
 * verifiable (the email log gains a row).
 */

namespace Ch247Ai\Tools\Writers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Core\Whmcs;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_billing_writers()
{
    return [
        new ToolDefinition(
            'write_invoice_reminder',
            'ai.write.billing',
            'WRITE_CUSTOMER_VISIBLE',
            'Send the standard WHMCS payment reminder email for one unpaid invoice.',
            [
                'invoice_id' => ['type' => 'integer', 'required' => true, 'description' => 'tblinvoices.id'],
            ],
            function (array $args, array $ctx) {
                ch247ai_w_assert_admin_scope($ctx);
                ch247ai_w_require(['tblinvoices', 'tblemails']);
                $id = (int) $args['invoice_id'];
                $rows = Db::query('SELECT id, userid, status, total FROM ' . Db::whmcs('tblinvoices') . ' WHERE id = ?', [$id]);
                if ($rows === []) {
                    throw new NotFoundException('Invoice ' . $id . ' does not exist.');
                }
                $invoice = $rows[0];

                // Refuse to chase an invoice that is not actually owed. An
                // erroneous dunning email is a customer-trust incident.
                if ((string) $invoice['status'] !== 'Unpaid') {
                    throw new \Ch247Ai\Core\ValidationException('Invoice ' . $id . ' is ' . $invoice['status'] . ', not Unpaid; refusing to send a payment reminder.');
                }

                $clientId = (int) $invoice['userid'];
                $countRows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::whmcs('tblemails') . ' WHERE userid = ?', [$clientId]);
                $before = $countRows === [] ? 0 : (int) $countRows[0]['c'];

                Whmcs::api('SendEmail', [
                    'messagename' => 'Invoice Payment Reminder',
                    'id' => $id,
                ]);

                return [
                    'invoice_id' => $id,
                    'client_id' => $clientId,
                    'amount' => (float) $invoice['total'],
                    'emails_before' => $before,
                    '_verify_before' => $before,
                    '_verify_client' => $clientId,
                ];
            },
            ['tblinvoices', 'tblemails'],
            false,
            function (array $args, array $ctx, array $result) {
                $before = isset($result['_verify_before']) ? (int) $result['_verify_before'] : -1;
                $clientId = isset($result['_verify_client']) ? (int) $result['_verify_client'] : 0;
                if ($before < 0 || $clientId === 0) {
                    return ['verified' => false, 'note' => 'No baseline email count was captured; cannot confirm the reminder was sent.'];
                }
                $rows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::whmcs('tblemails') . ' WHERE userid = ?', [$clientId]);
                $after = $rows === [] ? 0 : (int) $rows[0]['c'];
                if ($after <= $before) {
                    return ['verified' => false, 'note' => 'Email log for client ' . $clientId . ' is still at ' . $after . ' entries; the reminder was NOT sent.'];
                }
                return ['verified' => true, 'note' => 'Confirmed: email log for client ' . $clientId . ' went from ' . $before . ' to ' . $after . '.'];
            }
        ),
    ];
}
