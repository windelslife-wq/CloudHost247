<?php
/** Approval engine: risk ladder, decision flow, expiry, immutability of decisions. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Approval\ApprovalEngine;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Identity;

ch247ai_boot();
ch247ai_freeze();

T::section('Risk ladder');
// Operator policy for this deployment: every write is gated, including
// WRITE_LOW. Only pure reads execute without an approved row.
T::ok('READ requires no approval', !ApprovalEngine::requiresApproval('READ'));
T::ok('WRITE_LOW now requires approval (all-gated policy)', ApprovalEngine::requiresApproval('WRITE_LOW'));
foreach (['WRITE_CUSTOMER_VISIBLE', 'WRITE_FINANCIAL', 'AVAILABILITY', 'SECURITY_ENFORCEMENT', 'MASS_COMMUNICATION'] as $risk) {
    T::ok("{$risk} requires approval", ApprovalEngine::requiresApproval($risk));
}
T::ok('an unrecognised risk label fails closed into requiring approval',
    ApprovalEngine::requiresApproval('SOMETHING_NEW'));
T::ok('empty risk label fails closed too', ApprovalEngine::requiresApproval(''));

T::section('Execution gate');
T::ok('reads pass the gate', ApprovalEngine::assertExecutable(0, 'READ'));
T::throws('low-risk write is blocked without approval (all-gated)', function () {
    ApprovalEngine::assertExecutable(0, 'WRITE_LOW');
}, \Ch247Ai\Core\ForbiddenException::class);
T::throws('high-risk write without approval blocked', function () {
    ApprovalEngine::assertExecutable(0, 'WRITE_FINANCIAL');
}, \Ch247Ai\Core\ForbiddenException::class);
T::throws('high-risk write with non-existent approval blocked', function () {
    ApprovalEngine::assertExecutable(99999, 'MASS_COMMUNICATION');
}, \Ch247Ai\Core\ForbiddenException::class);

T::section('Decision flow requires authority');
Identity::setAdmin(null);
unset($_SESSION['adminid']);
$id = ApprovalEngine::create('collections_agent', 'send_dunning_email', ['invoice_id' => 102], 'WRITE_CUSTOMER_VISIBLE', 'Draft reminder for invoice 102 (80.50, 8 days overdue)');
T::ok('approval row created pending', (int) $id > 0);
T::throws('anonymous cannot decide', function () use ($id) {
    ApprovalEngine::decide($id, 'approved');
}, \Ch247Ai\Core\ForbiddenException::class);

Identity::setAdmin(2); // role 2, no grants
$_SESSION['adminid'] = 2;
T::throws('admin without approve group cannot decide', function () use ($id) {
    ApprovalEngine::decide($id, 'approved');
}, \Ch247Ai\Core\ForbiddenException::class);

\Ch247Ai\Core\Rbac::grant(2, \Ch247Ai\Core\Rbac::AI_APPROVE);
T::throws('invalid decision value rejected', function () use ($id) {
    ApprovalEngine::decide($id, 'maybe');
}, \Ch247Ai\Core\ValidationException::class);
T::ok('approved with authority', ApprovalEngine::decide($id, 'approved', 'verified against invoice'));
$row = Db::first('approvals', ['id' => $id]);
T::eq('status approved', 'approved', $row['status']);
T::eq('decided_by recorded', 2, (int) $row['decided_by']);
T::ok('decided_at set', $row['decided_at'] !== null);

T::section('Decisions are one-shot');
T::throws('cannot decide twice', function () use ($id) {
    ApprovalEngine::decide($id, 'rejected');
}, \Ch247Ai\Core\ForbiddenException::class);

T::section('Approved but expired approvals do not execute');
\Ch247Ai\Core\Settings::put('approval_expiry_hours', '72');
$id2 = ApprovalEngine::create('resolution_pro', 'send_ticket_reply', ['ticket_id' => 401], 'WRITE_CUSTOMER_VISIBLE', 'Draft reply for ticket 401');
ApprovalEngine::decide($id2, 'approved');
ch247ai_freeze('2026-10-10 12:00:00'); // 4 days later
T::throws('expired approval refuses execution', function () use ($id2) {
    ApprovalEngine::assertExecutable($id2, 'WRITE_CUSTOMER_VISIBLE');
}, \Ch247Ai\Core\ForbiddenException::class);
T::eq('row flipped to expired', 'expired', Db::first('approvals', ['id' => $id2])['status']);

T::section('Rejected approvals never execute');
$id3 = ApprovalEngine::create('collections_agent', 'send_dunning_email', ['invoice_id' => 103], 'WRITE_CUSTOMER_VISIBLE', 'Another reminder');
ApprovalEngine::decide($id3, 'rejected', 'customer promised payment');
T::throws('rejected approval refuses execution', function () use ($id3) {
    ApprovalEngine::assertExecutable($id3, 'WRITE_CUSTOMER_VISIBLE');
}, \Ch247Ai\Core\ForbiddenException::class);

T::section('Stale pending approvals expire via cron helper');
ch247ai_freeze('2026-10-06 12:00:00');
$id4 = ApprovalEngine::create('resolution_pro', 'send_ticket_reply', ['ticket_id' => 402], 'WRITE_CUSTOMER_VISIBLE', 'Old pending draft');
ch247ai_freeze('2026-10-11 12:00:00');
T::eq('one approval expired by housekeeping', 1, ApprovalEngine::expireStale());
T::eq('expired status set', 'expired', Db::first('approvals', ['id' => $id4])['status']);

T::section('Every approval transition is audited');
T::ok('request audited', Db::count('audit_log', ['action' => 'ai.approval.requested']) >= 4);
T::ok('approvals audited', Db::count('audit_log', ['action' => 'ai.approval.approved']) >= 2);
T::ok('rejections audited', Db::count('audit_log', ['action' => 'ai.approval.rejected']) === 1);

T::section('Arguments are redacted at capture time');
$id5 = ApprovalEngine::create('test_agent', 'some_tool', ['api_key' => 'sk-secret-123', 'note' => 'x'], 'WRITE_FINANCIAL', 'r');
$row = Db::first('approvals', ['id' => $id5]);
T::ok('secret redacted in stored arguments', strpos((string) $row['arguments'], 'sk-secret-123') === false);

T::finish();
