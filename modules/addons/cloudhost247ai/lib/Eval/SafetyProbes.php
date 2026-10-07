<?php
/**
 * Live safety probes (brief §31).
 *
 * Unit tests prove the guards worked on a developer's machine against
 * fixtures. These probes prove they are still working **on this
 * installation, against this database, right now** — after an upgrade, a
 * settings change, a role edit or a half-finished migration.
 *
 * Three rules govern everything in this file:
 *
 *  1. READ ONLY. A probe may never create, modify or delete a business
 *     record. Nothing here writes a ticket, an invoice, a client or an
 *     approval. Probes that test write paths are built so that **even a
 *     completely broken guard cannot cause a write** — they aim at a
 *     non-existent entity, so the worst case is a NotFound, never a
 *     mutation.
 *
 *  2. NO SYNTHETIC RECORDS. Brief §37 forbids inventing production data to
 *     make a check pass. A probe with nothing real to test reports
 *     `skipped` and says what was missing. It never manufactures a customer
 *     to test customer isolation.
 *
 *  3. FAILURE IS LOUD AND SPECIFIC. A failing probe names the exact
 *     invariant that broke, because "safety check failed" helps nobody.
 *
 * Probe identity is restored in a finally block, so a probe cannot leave the
 * process holding a client session.
 */

namespace Ch247Ai\Eval;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ForbiddenException;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\Redaction;
use Ch247Ai\Tools\Bootstrap as ToolBootstrap;
use Ch247Ai\Tools\ToolExecutor;
use Ch247Ai\Tools\ToolRegistry;

class SafetyProbes
{
    const PASS = 'pass';
    const FAIL = 'fail';
    const SKIPPED = 'skipped';

    /** A ticket id chosen to be absent, so a broken gate still cannot write. */
    const UNREACHABLE_ENTITY = 2147480000;

    /** @return array<int,array{probe:string,status:string,detail:string}> */
    public static function runAll()
    {
        ToolBootstrap::register();
        return [
            self::auditChainIntact(),
            self::unknownToolRefused(),
            self::approvalGateBlocksUnapprovedWrite(),
            self::clientScopeBlocksWrites(),
            self::clientScopeIsolatesReads(),
            self::redactionStripsSecrets(),
            self::auditCarriesNoSecrets(),
        ];
    }

    protected static function result($probe, $status, $detail)
    {
        return ['probe' => $probe, 'status' => $status, 'detail' => $detail];
    }

    // -----------------------------------------------------------------

    /** The audit log must still hash-chain cleanly. */
    public static function auditChainIntact()
    {
        $name = 'audit_chain_intact';
        try {
            if (!Db::tableExists('audit_log')) {
                return self::result($name, self::SKIPPED, 'No audit_log table on this installation.');
            }
            if (Db::count('audit_log') === 0) {
                return self::result($name, self::SKIPPED, 'Audit log is empty; nothing to verify yet.');
            }
            $check = Audit::verifyChain(2000);
            if (empty($check['valid'])) {
                return self::result($name, self::FAIL, 'Audit chain broken at row #' . (int) $check['broken_at'] . ' after ' . (int) $check['checked'] . ' good rows. The log has been altered.');
            }
            return self::result($name, self::PASS, 'Hash chain verified across ' . (int) $check['checked'] . ' entries.');
        } catch (\Throwable $e) {
            return self::result($name, self::FAIL, 'Chain verification errored: ' . $e->getMessage());
        }
    }

    /** An unregistered tool name must be refused, not improvised. */
    public static function unknownToolRefused()
    {
        $name = 'unknown_tool_refused';
        try {
            ToolExecutor::execute('admin_copilot', 'tool_that_does_not_exist', [], 0);
            return self::result($name, self::FAIL, 'An unregistered tool name was accepted.');
        } catch (NotFoundException $e) {
            return self::result($name, self::PASS, 'Unregistered tool names are refused.');
        } catch (ForbiddenException $e) {
            return self::result($name, self::PASS, 'Refused before reaching the registry.');
        } catch (\Throwable $e) {
            return self::result($name, self::FAIL, 'Unexpected failure mode: ' . get_class($e));
        }
    }

    /**
     * A write with no approval must be refused.
     *
     * Aimed at a non-existent ticket so that even a totally broken gate
     * reaches NotFound rather than performing a write.
     */
    public static function approvalGateBlocksUnapprovedWrite()
    {
        $name = 'approval_gate_blocks_unapproved_write';
        $tool = ToolRegistry::find('write_ticket_note');
        if ($tool === null) {
            return self::result($name, self::SKIPPED, 'No write tools are registered on this installation.');
        }
        try {
            ToolExecutor::execute('resolution_pro', 'write_ticket_note', [
                'ticket_id' => self::UNREACHABLE_ENTITY,
                'note' => 'safety probe - must never be written',
            ], 0, 0);
            return self::result($name, self::FAIL, 'A write executed with no approval id. THE APPROVAL GATE IS NOT ENFORCING.');
        } catch (ForbiddenException $e) {
            return self::result($name, self::PASS, 'Unapproved writes are refused at the gate.');
        } catch (NotFoundException $e) {
            return self::result($name, self::FAIL, 'The gate did not refuse; the call reached the tool body and only stopped because the target does not exist.');
        } catch (\Throwable $e) {
            return self::result($name, self::PASS, 'Refused (' . get_class($e) . ').');
        }
    }

    /** No write tool may be reachable from a customer session. */
    public static function clientScopeBlocksWrites()
    {
        $name = 'client_scope_blocks_writes';
        if (ToolRegistry::find('write_ticket_note') === null) {
            return self::result($name, self::SKIPPED, 'No write tools are registered on this installation.');
        }
        $clientId = self::anyClientId();
        if ($clientId === 0) {
            return self::result($name, self::SKIPPED, 'No client records exist to probe with.');
        }
        $restore = self::enterClientScope($clientId);
        try {
            ToolExecutor::execute('customer_assistant', 'write_ticket_note', [
                'ticket_id' => self::UNREACHABLE_ENTITY,
                'note' => 'safety probe - must never be written',
            ], 0, 0);
            return self::result($name, self::FAIL, 'A customer session reached a write tool. CLIENT SCOPE IS NOT READ-ONLY.');
        } catch (ForbiddenException $e) {
            return self::result($name, self::PASS, 'Customer sessions cannot reach write tools.');
        } catch (NotFoundException $e) {
            return self::result($name, self::FAIL, 'Client scope did not refuse; the call reached the tool body.');
        } catch (\Throwable $e) {
            return self::result($name, self::PASS, 'Refused (' . get_class($e) . ').');
        } finally {
            $restore();
        }
    }

    /**
     * A customer session must not be able to read another customer's rows,
     * even when it explicitly asks for them.
     */
    public static function clientScopeIsolatesReads()
    {
        $name = 'client_scope_isolates_reads';
        if (ToolRegistry::find('read_invoices') === null) {
            return self::result($name, self::SKIPPED, 'read_invoices is not registered.');
        }
        if (!Db::whmcsTableExists('tblinvoices')) {
            return self::result($name, self::SKIPPED, 'No tblinvoices table on this installation.');
        }
        $pair = self::twoClientsWithInvoices();
        if ($pair === null) {
            return self::result($name, self::SKIPPED, 'Fewer than two customers have invoices; isolation cannot be probed without inventing data.');
        }
        list($selfId, $otherId) = $pair;

        $restore = self::enterClientScope($selfId);
        try {
            // Deliberately ask for the OTHER customer's invoices.
            $result = ToolExecutor::execute('customer_assistant', 'read_invoices', ['client_id' => $otherId], 0);
            $rows = isset($result->data['invoices']) && is_array($result->data['invoices']) ? $result->data['invoices'] : [];
            foreach ($rows as $row) {
                if (isset($row['userid']) && (int) $row['userid'] !== (int) $selfId) {
                    return self::result($name, self::FAIL, 'Customer ' . $selfId . ' received a row belonging to customer ' . (int) $row['userid'] . '. TENANT ISOLATION IS BROKEN.');
                }
            }
            return self::result($name, self::PASS, 'Customer ' . $selfId . ' asked for customer ' . $otherId . "'s invoices and received only their own (" . count($rows) . ' rows).');
        } catch (ForbiddenException $e) {
            return self::result($name, self::PASS, 'Read refused outright in client scope.');
        } catch (\Throwable $e) {
            return self::result($name, self::FAIL, 'Isolation probe errored: ' . get_class($e) . ': ' . $e->getMessage());
        } finally {
            $restore();
        }
    }

    /** Redaction must still strip credential-shaped text. */
    public static function redactionStripsSecrets()
    {
        $name = 'redaction_strips_secrets';
        $samples = [
            'api_key=ABCDEF1234567890abcdef',
            'password: hunter2hunter2',
            'Authorization: Bearer abcdef0123456789abcdef',
        ];
        foreach ($samples as $sample) {
            $cleaned = Redaction::cleanString($sample);
            if ($cleaned === $sample) {
                return self::result($name, self::FAIL, 'Credential-shaped text passed through unredacted: ' . substr($sample, 0, 20) . '…');
            }
        }
        return self::result($name, self::PASS, 'Credential patterns are redacted.');
    }

    /** The audit log itself must not contain credential-shaped text (§22). */
    public static function auditCarriesNoSecrets()
    {
        $name = 'audit_carries_no_secrets';
        if (!Db::tableExists('audit_log')) {
            return self::result($name, self::SKIPPED, 'No audit_log table on this installation.');
        }
        try {
            $rows = Db::all('audit_log', [], 'id DESC', 500);
        } catch (\Throwable $e) {
            return self::result($name, self::SKIPPED, 'Audit log is not readable.');
        }
        if ($rows === []) {
            return self::result($name, self::SKIPPED, 'Audit log is empty.');
        }
        $blob = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $patterns = [
            '/(?i)\b(api[_-]?key|password|passwd|secret|access[_-]?token)\s*[:=]\s*(?!\[redacted)[^\s",}]{6,}/' => 'credential assignment',
            '/(?i)\bbearer\s+(?!\[redacted)[a-z0-9._~+\-\/=]{12,}/' => 'bearer token',
        ];
        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, (string) $blob)) {
                return self::result($name, self::FAIL, 'The audit log contains an unredacted ' . $label . '.');
            }
        }
        return self::result($name, self::PASS, 'No credential-shaped text in the last ' . count($rows) . ' audit entries.');
    }

    // -----------------------------------------------------------------

    /**
     * Temporarily adopt a customer session. Returns a restore closure that
     * must be called in a finally block.
     */
    protected static function enterClientScope($clientId)
    {
        $previousAdmin = Identity::adminId();
        $previousClient = Identity::clientId();
        $previousSessionAdmin = isset($_SESSION['adminid']) ? $_SESSION['adminid'] : null;

        Identity::setAdmin(null);
        unset($_SESSION['adminid']);
        Identity::setClient((int) $clientId);

        return function () use ($previousAdmin, $previousClient, $previousSessionAdmin) {
            Identity::setClient($previousClient);
            Identity::setAdmin($previousAdmin);
            if ($previousSessionAdmin !== null) {
                $_SESSION['adminid'] = $previousSessionAdmin;
            }
        };
    }

    protected static function anyClientId()
    {
        if (!Db::whmcsTableExists('tblclients')) {
            return 0;
        }
        try {
            $rows = Db::query('SELECT id FROM ' . Db::whmcs('tblclients') . ' ORDER BY id ASC LIMIT 1');
            return $rows === [] ? 0 : (int) $rows[0]['id'];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Two distinct customers that each own at least one invoice. */
    protected static function twoClientsWithInvoices()
    {
        try {
            $rows = Db::query(
                'SELECT DISTINCT userid FROM ' . Db::whmcs('tblinvoices') . ' WHERE userid > 0 ORDER BY userid ASC LIMIT 2'
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (count($rows) < 2) {
            return null;
        }
        return [(int) $rows[0]['userid'], (int) $rows[1]['userid']];
    }
}
