<?php
/**
 * Phase 2 — write execution.
 *
 * The properties under test are the ones that make an acting AI safe:
 *
 *   - a write cannot run without an approved row;
 *   - an approval is bound to its exact arguments;
 *   - an approval is spendable exactly once;
 *   - an unverified write is reported as a FAILURE, never a success;
 *   - a client session can never reach a write;
 *   - the master switch and the per-tool switch both actually work.
 *
 * The "lying API" cases matter most: WHMCS is faked to return a success
 * envelope while writing nothing. A platform that trusts the envelope would
 * report success. This one must re-read and report failure.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Approval\ApprovalEngine;
use Ch247Ai\Approval\ApprovalExecutor;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ForbiddenException;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\Rbac;
use Ch247Ai\Core\Settings;
use Ch247Ai\Core\Whmcs;
use Ch247Ai\Tools\Bootstrap;
use Ch247Ai\Tools\ToolExecutor;
use Ch247Ai\Tools\ToolRegistry;

ch247ai_boot();
ch247ai_freeze();
Bootstrap::register();

/** Fake WHMCS that performs the write faithfully. */
function ch247ai_t_honest_api()
{
    Whmcs::setApiFake(function ($command, array $args) {
        $pdo = Db::pdo();
        if ($command === 'AddTicketReply') {
            $st = $pdo->prepare('INSERT INTO tblticketreplies (tid, admin, message, date) VALUES (?, ?, ?, ?)');
            $st->execute([(int) $args['ticketid'], 'root', (string) $args['message'], Clock::now()]);
            return ['result' => 'success'];
        }
        if ($command === 'AddTicketNote') {
            $st = $pdo->prepare('INSERT INTO tblticketnotes (tid, admin, message, created_at) VALUES (?, ?, ?, ?)');
            $st->execute([(int) $args['ticketid'], 'root', (string) $args['message'], Clock::now()]);
            return ['result' => 'success'];
        }
        if ($command === 'UpdateTicket') {
            $st = $pdo->prepare('UPDATE tbltickets SET status = ? WHERE id = ?');
            $st->execute([(string) $args['status'], (int) $args['ticketid']]);
            return ['result' => 'success'];
        }
        if ($command === 'SendEmail') {
            $inv = $pdo->prepare('SELECT userid FROM tblinvoices WHERE id = ?');
            $inv->execute([(int) $args['id']]);
            $row = $inv->fetch(\PDO::FETCH_ASSOC);
            $st = $pdo->prepare('INSERT INTO tblemails (userid, subject, message, date) VALUES (?, ?, ?, ?)');
            $st->execute([(int) $row['userid'], 'Invoice Payment Reminder', 'body', Clock::now()]);
            return ['result' => 'success'];
        }
        return ['result' => 'success'];
    });
}

/** Fake WHMCS that claims success and writes nothing. */
function ch247ai_t_lying_api()
{
    Whmcs::setApiFake(function ($command, array $args) {
        return ['result' => 'success', 'message' => 'Done!'];
    });
}

/** Approve a row as admin #1 and return its id. */
function ch247ai_t_approve($agent, $tool, array $args, $risk, $reason = 'test')
{
    Identity::setAdmin(1);
    $_SESSION['adminid'] = 1;
    $id = ApprovalEngine::create($agent, $tool, $args, $risk, $reason);
    ApprovalEngine::decide($id, 'approved', 'ok');
    return $id;
}

Identity::setAdmin(1);
$_SESSION['adminid'] = 1;
Settings::put('writes_enabled', '1');

// ---------------------------------------------------------------------------
T::section('Write tools are registered and declared correctly');

$writeTools = [];
foreach (ToolRegistry::all() as $tool) {
    if ($tool->risk !== 'READ') {
        $writeTools[$tool->name] = $tool;
    }
}
T::ok('at least one write tool is registered', count($writeTools) > 0);
foreach (['write_ticket_reply', 'write_ticket_note', 'write_ticket_status', 'write_invoice_reminder'] as $name) {
    T::ok("{$name} is registered", isset($writeTools[$name]));
}
foreach ($writeTools as $name => $tool) {
    T::ok("{$name} requires approval", ApprovalEngine::requiresApproval($tool->risk));
    T::ok("{$name} has a verification step", is_callable($tool->verify));
    T::ok("{$name} declares an ai.write.* permission", strpos($tool->permission, 'ai.write.') === 0);
    T::ok("{$name} maps to a real permission group", Rbac::groupForTool($tool->permission) !== null);
    T::ok("{$name} is not client-bound", $tool->clientBound === false);
}
T::ok('customer-visible reply carries the higher risk class',
    $writeTools['write_ticket_reply']->risk === 'WRITE_CUSTOMER_VISIBLE');

T::section('No tool exists for forbidden billing actions');
foreach (['mark_invoice_paid', 'invoice_mark_paid', 'add_credit', 'refund_payment', 'apply_credit', 'suspend_service', 'terminate_service'] as $forbidden) {
    T::ok("no {$forbidden} tool exists", ToolRegistry::find($forbidden) === null);
}

// ---------------------------------------------------------------------------
T::section('The gate: a write cannot run without an approval');

ch247ai_t_honest_api();
ToolExecutor::allowAgent('resolution_pro');
ToolExecutor::allowAgent('collections_agent');

T::throws('write refused with no approval id', function () {
    ToolExecutor::execute('resolution_pro', 'write_ticket_reply', ['ticket_id' => 401, 'message' => 'hello'], 0, 0);
}, ForbiddenException::class);

T::throws('write refused with a non-existent approval id', function () {
    ToolExecutor::execute('resolution_pro', 'write_ticket_reply', ['ticket_id' => 401, 'message' => 'hello'], 0, 987654);
}, ForbiddenException::class);

$pending = ApprovalEngine::create('resolution_pro', 'write_ticket_reply', ['ticket_id' => 401, 'message' => 'hello'], 'WRITE_CUSTOMER_VISIBLE', 'r');
T::throws('write refused while the approval is still pending', function () use ($pending) {
    ToolExecutor::execute('resolution_pro', 'write_ticket_reply', ['ticket_id' => 401, 'message' => 'hello'], 0, $pending);
}, ForbiddenException::class);

Identity::setAdmin(1);
$_SESSION['adminid'] = 1;
ApprovalEngine::decide($pending, 'rejected', 'no');
T::throws('write refused on a rejected approval', function () use ($pending) {
    ToolExecutor::execute('resolution_pro', 'write_ticket_reply', ['ticket_id' => 401, 'message' => 'hello'], 0, $pending);
}, ForbiddenException::class);

T::eq('nothing was written to the ticket', 0,
    (int) Db::query('SELECT COUNT(*) AS c FROM tblticketreplies')[0]['c']);

// ---------------------------------------------------------------------------
T::section('Argument binding: an approval cannot be spent on other arguments');

$bound = ch247ai_t_approve('resolution_pro', 'write_ticket_reply', ['ticket_id' => 401, 'message' => 'Approved text'], 'WRITE_CUSTOMER_VISIBLE');

T::throws('cannot redirect an approved reply to a different ticket', function () use ($bound) {
    ToolExecutor::execute('resolution_pro', 'write_ticket_reply', ['ticket_id' => 402, 'message' => 'Approved text'], 0, $bound);
}, ForbiddenException::class);

T::throws('cannot change the approved message body', function () use ($bound) {
    ToolExecutor::execute('resolution_pro', 'write_ticket_reply', ['ticket_id' => 401, 'message' => 'Something else entirely'], 0, $bound);
}, ForbiddenException::class);

T::eq('still nothing written after both tampering attempts', 0,
    (int) Db::query('SELECT COUNT(*) AS c FROM tblticketreplies')[0]['c']);

T::ok('digest ignores key order',
    ApprovalEngine::argsDigest(['a' => 1, 'b' => 2]) === ApprovalEngine::argsDigest(['b' => 2, 'a' => 1]));
T::ok('digest treats 401 and "401" as the same binding',
    ApprovalEngine::argsDigest(['ticket_id' => 401]) === ApprovalEngine::argsDigest(['ticket_id' => '401']));
T::ok('digest changes when a value changes',
    ApprovalEngine::argsDigest(['ticket_id' => 401]) !== ApprovalEngine::argsDigest(['ticket_id' => 402]));
T::ok('digest is stored on creation',
    (string) Db::first('approvals', ['id' => $bound])['args_digest'] !== '');
T::ok('matching arguments pass the binding check',
    ApprovalEngine::assertArgumentsMatch($bound, ['ticket_id' => 401, 'message' => 'Approved text']));

// ---------------------------------------------------------------------------
T::section('Happy path: approve, execute, verify');

$ok = ApprovalExecutor::run($bound);
T::ok('execution reported ok', $ok['ok'] === true);
T::ok('execution reported verified', $ok['verified'] === true);
T::ok('verification note cites the observed change', strpos($ok['note'], 'went from 0 to 1') !== false);
T::eq('the reply really exists in the database', 1,
    (int) Db::query('SELECT COUNT(*) AS c FROM tblticketreplies WHERE tid = 401')[0]['c']);
T::eq('the stored message is the approved text verbatim', 'Approved text',
    (string) Db::query('SELECT message FROM tblticketreplies WHERE tid = 401')[0]['message']);

$row = Db::first('approvals', ['id' => $bound]);
T::eq('approval status is executed', 'executed', (string) $row['status']);
T::eq('execution_status is succeeded', 'succeeded', (string) $row['execution_status']);
T::eq('verified flag set', 1, (int) $row['verified']);
T::ok('executed_at recorded', (string) $row['executed_at'] !== '');
T::eq('exactly one attempt', 1, (int) $row['attempts']);
T::ok('internal verification baselines are not persisted',
    strpos((string) $row['execution_result'], '_verify') === false);

T::section('Single use: an approval cannot be spent twice');
T::throws('re-running a spent approval is refused', function () use ($bound) {
    ApprovalExecutor::run($bound);
}, ForbiddenException::class);
T::eq('the customer did not get a second copy', 1,
    (int) Db::query('SELECT COUNT(*) AS c FROM tblticketreplies WHERE tid = 401')[0]['c']);

T::section('claim() is atomic');
$race = ch247ai_t_approve('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'n'], 'WRITE_LOW');
T::ok('first claim wins', ApprovalEngine::claim($race));
T::ok('second claim loses', !ApprovalEngine::claim($race));
ApprovalEngine::release($race);
T::eq('release restores the approved state', 'approved', (string) Db::first('approvals', ['id' => $race])['status']);

// ---------------------------------------------------------------------------
T::section('A write that did not happen is reported as FAILED, never success');

ch247ai_t_lying_api();
$lie = ch247ai_t_approve('resolution_pro', 'write_ticket_reply', ['ticket_id' => 402, 'message' => 'Ghost reply'], 'WRITE_CUSTOMER_VISIBLE');
$res = ApprovalExecutor::run($lie);

T::ok('API claimed success but the executor did not', $res['ok'] === false);
T::ok('verified is false', $res['verified'] === false);
T::ok('the note says the reply was NOT posted', strpos($res['note'], 'NOT posted') !== false);
$lieRow = Db::first('approvals', ['id' => $lie]);
T::eq('approval marked failed', 'failed', (string) $lieRow['status']);
T::eq('execution_status failed', 'failed', (string) $lieRow['execution_status']);
T::eq('verified column is 0', 0, (int) $lieRow['verified']);
T::eq('and indeed no reply exists on ticket 402', 0,
    (int) Db::query('SELECT COUNT(*) AS c FROM tblticketreplies WHERE tid = 402')[0]['c']);

T::section('Same rule for a silent status change');
$lieStatus = ch247ai_t_approve('resolution_pro', 'write_ticket_status', ['ticket_id' => 401, 'status' => 'Closed'], 'WRITE_LOW');
$res2 = ApprovalExecutor::run($lieStatus);
T::ok('unverified status change is a failure', $res2['ok'] === false);
T::ok('note reports the status actually read back', strpos($res2['note'], 'did NOT apply') !== false);
T::eq('ticket 401 is still Open', 'Open',
    (string) Db::query('SELECT status FROM tbltickets WHERE id = 401')[0]['status']);

T::section('Same rule for a silent reminder send');
$lieMail = ch247ai_t_approve('collections_agent', 'write_invoice_reminder', ['invoice_id' => 102], 'WRITE_CUSTOMER_VISIBLE');
$res3 = ApprovalExecutor::run($lieMail);
T::ok('unverified reminder is a failure', $res3['ok'] === false);
T::ok('note says it was NOT sent', strpos($res3['note'], 'NOT sent') !== false);

// ---------------------------------------------------------------------------
T::section('Verified writes across the remaining tools');

ch247ai_t_honest_api();
$noteId = ch247ai_t_approve('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'Internal context'], 'WRITE_LOW');
$r = ApprovalExecutor::run($noteId);
T::ok('internal note verified', $r['ok'] === true && $r['verified'] === true);
T::eq('note row exists', 1, (int) Db::query('SELECT COUNT(*) AS c FROM tblticketnotes WHERE tid = 401')[0]['c']);

$statusId = ch247ai_t_approve('resolution_pro', 'write_ticket_status', ['ticket_id' => 401, 'status' => 'Answered'], 'WRITE_LOW');
$r = ApprovalExecutor::run($statusId);
T::ok('status change verified', $r['ok'] === true && $r['verified'] === true);
T::eq('ticket 401 is now Answered', 'Answered',
    (string) Db::query('SELECT status FROM tbltickets WHERE id = 401')[0]['status']);

$mailId = ch247ai_t_approve('collections_agent', 'write_invoice_reminder', ['invoice_id' => 102], 'WRITE_CUSTOMER_VISIBLE');
$r = ApprovalExecutor::run($mailId);
T::ok('reminder verified against the email log', $r['ok'] === true && $r['verified'] === true);
T::eq('one email logged for client 22', 1,
    (int) Db::query('SELECT COUNT(*) AS c FROM tblemails WHERE userid = 22')[0]['c']);

// ---------------------------------------------------------------------------
T::section('Business guards inside the write tools');

$paidReminder = ch247ai_t_approve('collections_agent', 'write_invoice_reminder', ['invoice_id' => 101], 'WRITE_CUSTOMER_VISIBLE');
$r = ApprovalExecutor::run($paidReminder);
T::ok('refuses to chase an invoice that is already Paid', $r['ok'] === false);
T::ok('failure explains why', strpos($r['note'], 'not Unpaid') !== false || strpos($r['note'], 'Paid') !== false);
T::eq('no extra email was logged', 1,
    (int) Db::query('SELECT COUNT(*) AS c FROM tblemails WHERE userid = 22')[0]['c']);

$ghostTicket = ch247ai_t_approve('resolution_pro', 'write_ticket_note', ['ticket_id' => 99999, 'note' => 'x'], 'WRITE_LOW');
$r = ApprovalExecutor::run($ghostTicket);
T::ok('refuses to write to a ticket that does not exist', $r['ok'] === false);

$emptyBody = ch247ai_t_approve('resolution_pro', 'write_ticket_reply', ['ticket_id' => 402, 'message' => '   '], 'WRITE_CUSTOMER_VISIBLE');
$r = ApprovalExecutor::run($emptyBody);
T::ok('refuses to post an empty reply', $r['ok'] === false);

$badStatus = ch247ai_t_approve('resolution_pro', 'write_ticket_status', ['ticket_id' => 401, 'status' => 'Deleted'], 'WRITE_LOW');
$r = ApprovalExecutor::run($badStatus);
T::ok('refuses an unknown status label', $r['ok'] === false);

// ---------------------------------------------------------------------------
T::section('Master switch and per-tool switch');

Settings::put('writes_enabled', '0');
$offId = ch247ai_t_approve('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'blocked'], 'WRITE_LOW');
$r = ApprovalExecutor::run($offId);
T::ok('writes_enabled=0 blocks execution even with an approval', $r['ok'] === false);
T::ok('the refusal is CONFIGURATION_REQUIRED', strpos($r['note'], 'CONFIGURATION_REQUIRED') !== false);
Settings::put('writes_enabled', '1');

// The per-tool kill switch: tool_disabled_<name> must match the real name.
Settings::put('tool_disabled_write_ticket_note', '1');
Bootstrap::register();
ToolRegistry::setDisabled('write_ticket_note', true);
T::ok('a disabled tool is reported disabled', ToolRegistry::isDisabled('write_ticket_note'));
$disabledId = ch247ai_t_approve('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'nope'], 'WRITE_LOW');
$r = ApprovalExecutor::run($disabledId);
T::ok('a disabled write tool cannot execute', $r['ok'] === false);
ToolRegistry::setDisabled('write_ticket_note', false);
Settings::put('tool_disabled_write_ticket_note', '0');

// ---------------------------------------------------------------------------
T::section('Authority');

$authId = ch247ai_t_approve('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'auth'], 'WRITE_LOW');
Identity::setAdmin(2);
$_SESSION['adminid'] = 2;
Rbac::setForcedRole(2, 2);
T::throws('an admin without ai.approve cannot execute', function () use ($authId) {
    ApprovalExecutor::run($authId);
}, ForbiddenException::class);

Identity::setAdmin(null);
unset($_SESSION['adminid']);
T::throws('an anonymous caller cannot execute', function () use ($authId) {
    ApprovalExecutor::run($authId);
}, ForbiddenException::class);

Identity::setAdmin(1);
$_SESSION['adminid'] = 1;
Rbac::setForcedRole(1, 1);

T::section('Client scope can never reach a write');
Identity::setAdmin(null);
unset($_SESSION['adminid']);
Identity::setClient(11);
T::throws('client session is refused a write tool', function () {
    ToolExecutor::execute('resolution_pro', 'write_ticket_reply', ['ticket_id' => 401, 'message' => 'x'], 0, 1);
}, ForbiddenException::class);
Identity::setClient(null);
Identity::setAdmin(1);
$_SESSION['adminid'] = 1;

// ---------------------------------------------------------------------------
T::section('Expiry');

$stale = ch247ai_t_approve('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'stale'], 'WRITE_LOW');
Db::update('approvals', ['id' => $stale], ['decided_at' => gmdate('Y-m-d H:i:s', Clock::time() - 400 * 3600)]);
T::throws('an expired approval cannot be executed', function () use ($stale) {
    ApprovalExecutor::run($stale);
}, ForbiddenException::class);
T::eq('and it is marked expired', 'expired', (string) Db::first('approvals', ['id' => $stale])['status']);

// ---------------------------------------------------------------------------
T::section('Audit trail');

$auditRows = Db::query('SELECT action FROM ' . Db::t('audit_log') . " WHERE action LIKE 'ai.approval.%' ORDER BY id ASC");
$actions = array_map(function ($r) { return (string) $r['action']; }, $auditRows);
T::ok('execution start is audited', in_array('ai.approval.execution_started', $actions, true));
T::ok('successful execution is audited', in_array('ai.approval.executed', $actions, true));
T::ok('failed execution is audited', in_array('ai.approval.execution_failed', $actions, true));

$blob = json_encode(Db::query('SELECT * FROM ' . Db::t('audit_log')));
foreach (['CH247AI_API_KEY', 'password', 'Bearer '] as $secret) {
    T::ok("audit log contains no {$secret}", stripos($blob, $secret) === false);
}

T::section('awaitingExecution lists only approved, unspent rows');
$waiting = ApprovalExecutor::awaitingExecution();
foreach ($waiting as $w) {
    T::eq('row ' . $w['id'] . ' is approved', 'approved', (string) $w['status']);
}
T::ok('awaitingExecution returns an array', is_array($waiting));

/* ------------------------------------------------- decision inbox UI -- */

T::section('Decision inbox renders the honest story');
require_once dirname(__DIR__) . '/lib/Http/AdminPortal.php';
ch247ai_as_super_admin();
Settings::put('writes_enabled', '1');
ch247ai_t_honest_api();

// One approved-not-yet-executed row, so the "awaiting execution" state shows.
$uiWaiting = ch247ai_t_approve('resolution_pro', 'write_ticket_note', ['ticket_id' => 402, 'note' => 'Awaiting'], 'WRITE_LOW', 'Needs a human');

$_GET['action'] = 'approvals';
$html = (new \Ch247Ai\Http\AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']))->render();
unset($_GET['action']);

T::ok('page rendered', strlen($html) > 500);
T::ok('decision inbox heading present', strpos($html, 'Decision inbox') !== false);
T::ok('approving is explicitly distinguished from executing',
    strpos($html, 'Approving does not execute') !== false);
T::ok('awaiting-execution section shown', strpos($html, 'Awaiting') !== false || strpos($html, 'awaiting execution') !== false);
// Assert on the form itself, not on prose that happens to name the button.
T::ok('execute control offered to an authorised admin',
    strpos($html, 'value="execute_approval"') !== false);
T::ok('arguments are shown with the approval', strpos($html, 'ticket_id') !== false);
T::ok('argument lock is surfaced', strpos($html, 'digest') !== false);
T::ok('history shows a failed action as not applied', strpos($html, 'not applied') !== false);
T::ok('history shows a verified action', strpos($html, 'verified') !== false);
T::ok('a verification note is quoted in the history', strpos($html, 'Confirmed') !== false);
T::ok('no PHP warnings leaked into the page', stripos($html, 'Warning:') === false);
T::ok('no stack traces leaked', stripos($html, 'Stack trace') === false);
// The env var NAME is legitimate guidance; what must never appear is a value.
T::ok('no secret value rendered', strpos($html, 'sk-') === false);
T::ok('no api key value rendered', !preg_match('/CH247AI_API_KEY\s*[=:]\s*\S/', $html));

T::section('The page tells the truth when execution is switched off');
Settings::put('writes_enabled', '0');
$_GET['action'] = 'approvals';
$offHtml = (new \Ch247Ai\Http\AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']))->render();
unset($_GET['action']);
T::ok('switched-off state is stated plainly', strpos($offHtml, 'Execution is switched off') !== false);
Settings::put('writes_enabled', '1');

T::section('An admin without ai.approve sees no execute control');
Identity::setAdmin(2);
$_SESSION['adminid'] = 2;
Rbac::setForcedRole(2, 2);
$_GET['action'] = 'approvals';
$noAuth = (new \Ch247Ai\Http\AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']))->render();
unset($_GET['action']);
T::ok('no execute form for an unprivileged admin',
    strpos($noAuth, 'value="execute_approval"') === false);
T::ok('no decide form for an unprivileged admin',
    strpos($noAuth, 'value="decide_approval"') === false);
T::ok('and they are told why', stripos($noAuth, 'approve permission') !== false);
ch247ai_as_super_admin();

Whmcs::setApiFake(null);
T::finish();
