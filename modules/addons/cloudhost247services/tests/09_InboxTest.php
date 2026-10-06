<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Db;
use Chs\Core\ForbiddenException;
use Chs\Core\NotFoundException;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\ValidationException;
use Chs\Services\InboxService;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_freeze();

// Ticket fixtures in the WHMCS-shaped tables.
$pdo = Db::pdo();
$pdo->exec("INSERT INTO tbltickets (id, tid, userid, name, email, subject, message, status, urgency, date, lastreply, flag, admin) VALUES
    (501, 'T-501', 11, 'Ava River', 'ava@example.test', 'Mailbox full on riverside.io', 'My mailbox stopped receiving mail since yesterday.', 'Customer-Reply', 'Medium', '2026-10-05 09:00:00', '2026-10-06 08:30:00', 0, ''),
    (502, 'T-502', 11, 'Ava River', 'ava@example.test', 'Transfer EPP code request', 'Please send the EPP code for avas-gem.com.', 'Closed', 'Low', '2026-09-01 10:00:00', '2026-09-02 12:00:00', 1, ''),
    (503, 'T-503', 22, 'Ben Stone', 'ben@example.test', 'DNS propagation question', 'How long does an A record change take?', 'Answered', 'Medium', '2026-10-06 10:00:00', '2026-10-06 11:00:00', 2, 'root')");
$pdo->exec("INSERT INTO tblticketreplies (id, tid, userid, admin, name, message, date) VALUES
    (900, 501, 0, 'Sam Support', '', 'We checked the server queue — messages were held by the spam filter and released.', '2026-10-06 08:30:00'),
    (901, 501, 11, '', 'Ava River', 'Thanks — mail is flowing again.', '2026-10-06 09:00:00'),
    (902, 503, 0, 'Root Admin', '', 'Usually under an hour; up to 24h worst case.', '2026-10-06 11:00:00')");

$gateway->conversations = [
    ['id' => 501, 'tid' => 'T-501', 'subject' => 'Mailbox full on riverside.io', 'status' => 'Customer-Reply',
     'urgency' => 'Medium', 'replies' => 2, 'date' => '2026-10-05 09:00:00', 'lastreply' => '2026-10-06 08:30:00'],
    ['id' => 502, 'tid' => 'T-502', 'subject' => 'Transfer EPP code request', 'status' => 'Closed',
     'urgency' => 'Low', 'replies' => 0, 'date' => '2026-09-01 10:00:00', 'lastreply' => '2026-09-02 12:00:00'],
];
$gateway->ticketMessages = [
    501 => [
        ['id' => 501, 'author' => 'Ava River', 'authorType' => 'client',
         'body' => 'My mailbox stopped receiving mail since yesterday.', 'date' => '2026-10-05 09:00:00'],
        ['id' => 900, 'author' => 'Sam Support', 'authorType' => 'staff',
         'body' => 'We checked the server queue — messages were held by the spam filter and released.', 'date' => '2026-10-06 08:30:00'],
        ['id' => 901, 'author' => 'Ava River', 'authorType' => 'client',
         'body' => 'Thanks — mail is flowing again.', 'date' => '2026-10-06 09:00:00'],
    ],
];

$svc = new InboxService();

T::section('Channels are honest — only the live ticket channel');
$channels = $svc->channels();
T::eq('one channel', 1, count($channels));
T::eq('ticket channel live', true, $channels[0]['live']);

T::section('Client conversation list with unread state');
$list = $svc->conversationsFor(11);
T::eq('two conversations', 2, count($list));
T::eq('sorted by gateway order', 501, $list[0]['id']);
T::ok('first unread (never marked)', $list[0]['unread'] === true);
T::ok('shape complete', isset($list[0]['subject'], $list[0]['status'], $list[0]['tid'], $list[0]['channel']));
T::eq('unread count', 2, $svc->unreadCountFor(11));
$search = $svc->conversationsFor(11, 100, 'EPP');
T::eq('search matches one', 1, count($search));
$closedOnly = $svc->conversationsFor(11, 100, '', 'Closed');
T::eq('status filter matches one', 1, count($closedOnly));

T::section('Thread open marks read + guards ownership');
T::throws('foreign thread unreachable', function () use ($svc) {
    $svc->threadFor(11, 503);
}, NotFoundException::class);
$thread = $svc->threadFor(11, 501);
T::eq('thread subject', 'Mailbox full on riverside.io', $thread['subject']);
T::eq('three messages mirror the reply table', 3, count($thread['messages']));
T::eq('staff reply typed', 'staff', $thread['messages'][1]['authorType']);
T::ok('read marker stored', (function () {
    return count(Db::query('SELECT * FROM ' . Db::t('inbox_reads'))) >= 1;
})());
$fresh = $svc->conversationsFor(11);
T::ok('after open, first no longer unread', $fresh[0]['unread'] === false);
T::eq('unread count drops to one', 1, $svc->unreadCountFor(11));

T::section('New inbound reply re-marks unread');
$pdo->exec("INSERT INTO tblticketreplies (id, tid, userid, admin, name, message, date) VALUES
    (999, 501, 0, 'Sam Support', '', 'One more thing — enable two-factor on the mailbox.', '2026-10-06 11:30:00')");
$pdo->exec("UPDATE tbltickets SET lastreply = '2026-10-06 11:30:00' WHERE id = 501");
$again = $svc->conversationsFor(11);
T::ok('newer reply re-marks unread', $again[0]['unread'] === true);

T::section('Reply path + validation');
T::throws('blank reply refused', function () use ($svc) {
    $svc->reply(11, 501, '   ');
}, ValidationException::class);
T::throws('reply on foreign ticket refused', function () use ($svc) {
    $svc->reply(11, 503, 'hi there');
}, NotFoundException::class);
$id = $svc->reply(11, 501, 'Two-factor enabled, thanks!');
T::ok('reply id returned', $id === 1);
T::ok('gateway got the reply call', count($gateway->callsTo('inboxReply')) === 1);
T::ok('reply drawn to client author', $gateway->callsTo('inboxReply')[0]['authorType'] === 'client');

T::section('Admin queue + labels');
$gateway->adminConversations = [
    ['id' => 501, 'tid' => 'T-501', 'subject' => 'Mailbox full on riverside.io', 'status' => 'Customer-Reply',
     'urgency' => 'Medium', 'name' => 'Ava River', 'email' => 'ava@example.test',
     'date' => '2026-10-05 09:00:00', 'lastreply' => '2026-10-06 08:30:00', 'flag' => 0, 'admin' => ''],
    ['id' => 503, 'tid' => 'T-503', 'subject' => 'DNS propagation question', 'status' => 'Answered',
     'urgency' => 'Medium', 'name' => 'Ben Stone', 'email' => 'ben@example.test',
     'date' => '2026-10-06 10:00:00', 'lastreply' => '2026-10-06 11:00:00', 'flag' => 2, 'admin' => 'root'],
];
$queue = $svc->adminQueue();
T::ok('queue sees global inbox', count($queue) >= 2);
T::ok('unassigned filter', (function () use ($svc) {
    $q = $svc->adminQueue(200, '', 0, true);
    foreach ($q as $row) {
        if ((int) $row['assigned'] !== 0) { return false; }
    }
    return count($q) >= 1;
})());
$labels = $svc->labels();
T::ok('seeded labels exist', count($labels) >= 3);
$lid = $svc->saveLabel(1, 0, 'Escalated', '#B91C1C');
T::ok('label created', $lid > 0);
T::throws('bad colour rejected', function () use ($svc) {
    $svc->saveLabel(1, 0, 'Bad', 'red');
}, ValidationException::class);
T::ok('attach works', (function () use ($svc, $lid) {
    $svc->attachLabel(1, 501, $lid);
    return Db::first('inbox_ticket_labels', ['ticket_id' => 501, 'label_id' => (int) $lid]) !== null;
})());
T::ok('labelled queue carries names', (function () use ($svc) {
    foreach ($svc->adminQueue() as $row) {
        if ((int) $row['id'] === 501) {
            return isset($row['labels']) && in_array('Escalated', array_column($row['labels'], 'name'), true);
        }
    }
    return false;
})());
T::ok('label filter narrows queue', (function () use ($svc, $lid) {
    $q = $svc->adminQueue(200, '', $lid, false);
    return count($q) === 1 && (int) $q[0]['id'] === 501;
})());
T::ok('detach removes', (function () use ($svc, $lid) {
    $svc->detachLabel(1, 501, $lid);
    return Db::first('inbox_ticket_labels', ['ticket_id' => 501, 'label_id' => (int) $lid]) === null;
})());
T::ok('delete label cascades attaches', (function () use ($svc, $lid) {
    $svc->attachLabel(1, 501, $lid);
    $svc->deleteLabel(1, $lid);
    return Db::count('inbox_ticket_labels', ['label_id' => (int) $lid]) === 0;
})());

T::section('Kill switch');
T::throws('inbox switch-off is explicit', function () use ($svc) {
    \Chs\Core\Settings::override('inbox_enabled', '0');
    try {
        $svc->conversationsFor(11);
    } finally {
        \Chs\Core\Settings::override('inbox_enabled', null);
    }
}, ServiceUnavailableException::class);

T::finish();
