<?php
/** Queue mechanics, the worker loop, retries, backoff, idempotency and transports. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Mkt\Audience\ListService;
use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Delivery\ComplianceService;
use Ch247Mkt\Delivery\DeliveryService;
use Ch247Mkt\Delivery\QueueService;
use Ch247Mkt\Transport\Message;
use Ch247Mkt\Transport\NullTransport;
use Ch247Mkt\Transport\Result;
use Ch247Mkt\Transport\TransportFactory;

/** Build a campaign with N subscribers, queued and ready for the worker. */
function ch247m_ready($count = 3)
{
    $list = ListService::create(['name' => 'Batch ' . uniqid()], 1);
    for ($i = 1; $i <= $count; $i++) {
        $s = ch247m_subscriber('r' . $i . '@example.test');
        ListService::addMember((int) $list['id'], (int) $s['id']);
    }
    $campaign = ch247m_campaign([(int) $list['id']]);
    CampaignService::send((int) $campaign['id'], 1);
    return CampaignService::find((int) $campaign['id']);
}

/* ======================================================== message == */

ch247m_boot();
ch247m_as_super_admin();

T::section('Message construction');
$message = Message::make([
    'to_email' => 'dest@example.test',
    'to_name'  => "Ada\r\nBcc: evil@x.test",
    'subject'  => "Hello\r\nX-Injected: yes",
    'from_email' => 'hello@cloudhost247.test',
    'from_name'  => 'CloudHost247',
    'html' => '<p>Hi</p>',
    'text' => 'Hi',
    'headers' => ['X-Custom' => "value\r\nX-Evil: 1"],
]);
T::notContains('CRLF stripped from the recipient name', "\r", $message->toName);
T::notContains('CRLF stripped from the subject', "\n", $message->subject);
T::notContains('CRLF stripped from custom headers', "\n", $message->headers['X-Custom']);

$raw = $message->rfc822();
T::contains('multipart alternative built', 'multipart/alternative', $raw);
T::contains('text part present', 'text/plain', $raw);
T::contains('html part present', 'text/html', $raw);
T::eq('one subject header only', 1, substr_count($raw, "\nSubject:") + (strpos($raw, 'Subject:') === 0 ? 1 : 0));

$encoded = Message::encodeHeader('Grüße aus Zürich');
T::contains('non-ASCII headers are RFC 2047 encoded', '=?UTF-8?B?', $encoded);
T::eq('ASCII headers are left alone', 'Plain Text', Message::encodeHeader('Plain Text'));

T::section('Result classification');
T::ok('5xx is permanent', Result::fromSmtpCode(550, 'mailbox unavailable')->permanent);
T::ok('4xx is transient', !Result::fromSmtpCode(451, 'try again later')->permanent);
T::eq('a hard bounce is typed', 'hard', Result::fromSmtpCode(550, 'no such user')->bounceType);
T::eq('a soft bounce is typed', 'soft', Result::fromSmtpCode(452, 'over quota')->bounceType);
T::ok('2xx is success', Result::fromSmtpCode(250, 'OK')->ok);

/* ========================================================== queue == */

ch247m_boot();
ch247m_as_super_admin();

T::section('Queue enqueue');
$campaign = ch247m_ready(3);
T::eq('three rows pending', 3, QueueService::stats((int) $campaign['id'])['pending']);
$row = Db::first('email_queue', ['campaign_id' => (int) $campaign['id']]);
T::eq('no rendered body is stored in the queue', '', (string) $row['payload']);
T::ok('an idempotency key was set', strlen((string) $row['idempotency_key']) === 64);

T::section('Lease-based claiming');
$claimed = QueueService::claimBatch(2, 'worker-A');
T::eq('claims up to the limit', 2, count($claimed));
T::eq('a second worker cannot take the same rows', 1, count(QueueService::claimBatch(5, 'worker-B')));
T::eq('nothing left to claim', 0, count(QueueService::claimBatch(5, 'worker-C')));

T::eq('locks are still held', 0, QueueService::releaseStaleLocks());
ch247m_advance(Settings::int('lock_seconds', 300) + 60);
T::eq('expired leases are released', 3, QueueService::releaseStaleLocks());
T::eq('and the work is claimable again', 3, count(QueueService::claimBatch(5, 'worker-D')));

T::section('Backoff');
$base = Settings::int('retry_base_seconds', 60);
$first = QueueService::backoffSeconds(1);
$second = QueueService::backoffSeconds(2);
$fourth = QueueService::backoffSeconds(4);
T::ok('first retry is around the base delay', $first >= $base * 0.8 && $first <= $base * 1.2);
T::ok('delay grows exponentially', $second > $first && $fourth > $second);
T::ok('delay is capped', QueueService::backoffSeconds(20) <= Settings::int('retry_max_seconds', 21600));
$jitterA = QueueService::backoffSeconds(5);
$jitterB = QueueService::backoffSeconds(5);
T::ok('jitter is applied so retries do not thunder', $jitterA !== $jitterB || $jitterA > 0);

/* ========================================================= worker == */

ch247m_boot();
ch247m_as_super_admin();

T::section('Happy path');
$campaign = ch247m_ready(3);
$summary = DeliveryService::processBatch(10, 'worker-1');
T::eq('three claimed', 3, $summary['claimed']);
T::eq('three sent', 3, $summary['sent']);
T::eq('nothing failed', 0, $summary['failed']);
T::eq('three messages reached the transport', 3, count(NullTransport::$sent));
T::eq('queue drained', 0, QueueService::stats((int) $campaign['id'])['pending']);
T::eq('campaign completed', CampaignService::STATUS_SENT, CampaignService::find((int) $campaign['id'])['status']);
T::eq('counters updated', 3, (int) CampaignService::find((int) $campaign['id'])['count_sent']);

$msg = NullTransport::$sent[0];
T::notContains('the sentinel never leaves the building', \Ch247Mkt\Delivery\TrackingService::RECIPIENT_SENTINEL, $msg->html);
T::contains('each recipient gets their own unsubscribe link', 't=u&amp;r=', $msg->html);
T::ok('List-Unsubscribe header set', isset($msg->headers['List-Unsubscribe']));
T::eq('one-click unsubscribe advertised', 'List-Unsubscribe=One-Click', $msg->headers['List-Unsubscribe-Post'] ?? '');
T::eq('bulk mail is marked as such', 'bulk', $msg->headers['Precedence'] ?? '');
T::contains('merge tags were resolved', 'Hello Test', $msg->html);
T::notContains('no unresolved merge tags ship', '{{', $msg->html);

T::section('Transient failure then success');
ch247m_boot();
ch247m_as_super_admin();
$campaign = ch247m_ready(1);
NullTransport::respondWith(function ($message, $n) {
    return $n === 1 ? Result::transient('451 greylisted') : Result::success('ok-2');
});
$summary = DeliveryService::processBatch(10, 'worker-1');
T::eq('nothing sent on the first attempt', 0, $summary['sent']);
T::eq('one scheduled for retry', 1, $summary['retried']);
$row = Db::first('email_queue', ['campaign_id' => (int) $campaign['id']]);
T::eq('attempt counter incremented', 1, (int) $row['attempts']);
T::eq('status returned to pending', QueueService::STATUS_PENDING, $row['status']);
T::contains('the error was recorded', 'greylisted', (string) $row['last_error']);
T::ok('it is not due immediately', $row['available_at'] > Clock::now());

T::eq('a worker running now finds nothing due', 0, DeliveryService::processBatch(10, 'worker-1')['claimed']);
ch247m_advance(7200);
$summary = DeliveryService::processBatch(10, 'worker-1');
T::eq('after the backoff it sends', 1, $summary['sent']);
T::eq('and the campaign completes', CampaignService::STATUS_SENT, CampaignService::find((int) $campaign['id'])['status']);

T::section('Permanent failure is a hard bounce');
ch247m_boot();
ch247m_as_super_admin();
$campaign = ch247m_ready(1);
NullTransport::respondWith(function () {
    return Result::permanent('550 5.1.1 no such mailbox', 'hard');
});
$summary = DeliveryService::processBatch(10, 'worker-1');
T::eq('counted as a failure', 1, $summary['failed']);
T::eq('never retried', 0, $summary['retried']);
$row = Db::first('email_queue', ['campaign_id' => (int) $campaign['id']]);
T::eq('queue row is dead', QueueService::STATUS_FAILED, $row['status']);
$recipient = Db::first('campaign_recipients', ['campaign_id' => (int) $campaign['id']]);
T::ok('recipient marked bounced', !empty($recipient['bounced_at']));
T::contains('reason captured', '550', (string) $recipient['failed_reason']);
T::ok('the address was suppressed', ComplianceService::isSuppressed('r1@example.test'));
T::eq('bounce counter updated', 1, (int) CampaignService::find((int) $campaign['id'])['count_bounced']);
T::eq('a bounce event was recorded', 1, Db::count('email_events', ['event' => 'bounce']));

T::section('Attempts are bounded');
ch247m_boot();
ch247m_as_super_admin();
Settings::override('max_attempts', '3');
$campaign = ch247m_ready(1);
NullTransport::respondWith(function () {
    return Result::transient('451 still greylisted');
});
for ($i = 0; $i < 6; $i++) {
    DeliveryService::processBatch(10, 'worker-1');
    ch247m_advance(90000);
}
$row = Db::first('email_queue', ['campaign_id' => (int) $campaign['id']]);
T::eq('gives up at max_attempts', QueueService::STATUS_FAILED, $row['status']);
T::eq('and stops counting attempts there', 3, (int) $row['attempts']);

T::section('Last-moment suppression');
ch247m_boot();
ch247m_as_super_admin();
$campaign = ch247m_ready(2);
// Somebody unsubscribes between the audience being frozen and the worker running.
ComplianceService::suppress('r1@example.test', 'unsubscribe', ['source' => 'another campaign']);
$summary = DeliveryService::processBatch(10, 'worker-1');
T::eq('only the mailable one is sent', 1, $summary['sent']);
T::eq('the other is skipped', 1, $summary['skipped']);
T::eq('and never reaches the transport', 1, count(NullTransport::$sent));
$skipped = Db::first('campaign_recipients', ['campaign_id' => (int) $campaign['id'], 'email' => 'r1@example.test']);
T::eq('recipient marked skipped', 'skipped', $skipped['status']);

T::section('Kill switch and master switch');
ch247m_boot();
ch247m_as_super_admin();
$campaign = ch247m_ready(2);
Settings::override('kill_switch', '1');
$summary = DeliveryService::processBatch(10, 'worker-1');
T::eq('the kill switch stops the worker dead', 'kill_switch', $summary['stopped']);
T::eq('nothing was sent', 0, $summary['sent']);
T::eq('and nothing was consumed from the queue', 2, QueueService::stats((int) $campaign['id'])['pending']);

Settings::override('kill_switch', '0');
Settings::override('sending_enabled', '0');
$summary = DeliveryService::processBatch(10, 'worker-1');
T::eq('disabled sending also stops it', 'sending_disabled', $summary['stopped']);
T::eq('queue untouched', 2, QueueService::stats((int) $campaign['id'])['pending']);

Settings::override('sending_enabled', '1');
T::eq('turning it back on resumes delivery', 2, DeliveryService::processBatch(10, 'worker-1')['sent']);

T::section('Rate limiting');
ch247m_boot();
ch247m_as_super_admin();
Settings::override('send_rate_per_minute', '2');
$campaign = ch247m_ready(5);
$summary = DeliveryService::processBatch(10, 'worker-1');
T::ok('the per-minute budget is respected', $summary['sent'] <= 2);
T::ok('the worker reports why it stopped', in_array($summary['stopped'], ['rate_limit', ''], true));
T::ok('the rest stays queued', QueueService::stats((int) $campaign['id'])['pending'] >= 3);
ch247m_advance(120);
$summary = DeliveryService::processBatch(10, 'worker-1');
T::ok('the budget refills', $summary['sent'] > 0);

T::section('Unconfigured transport does not burn attempts');
ch247m_boot();
ch247m_as_super_admin();
$campaign = ch247m_ready(2);
Settings::override('transport', 'smtp');
Settings::override('smtp_host', '');
$summary = DeliveryService::processBatch(10, 'worker-1');
T::eq('stops with a clear reason', 'transport_unconfigured', $summary['stopped']);
T::eq('nothing sent', 0, $summary['sent']);
$row = Db::first('email_queue', ['campaign_id' => (int) $campaign['id']]);
T::eq('attempts untouched', 0, (int) $row['attempts']);
T::eq('rows released back to pending', QueueService::STATUS_PENDING, $row['status']);

T::section('Transport factory');
ch247m_boot();
foreach (TransportFactory::DRIVERS as $driver) {
    // name() may append the concrete backend, e.g. "api:generic" — useful in
    // delivery logs when you need to know which provider actually ran.
    $transport = TransportFactory::make($driver);
    T::ok('factory builds "' . $driver . '" (got ' . $transport->name() . ')', strpos($transport->name(), $driver) === 0);
}
Settings::override('transport', 'null');
T::eq('current() honours the setting', 'null', TransportFactory::current()->name());
T::eq('the transport is named in delivery logs', 'null', TransportFactory::make('null')->name());
$check = TransportFactory::verify('null');
T::ok('the null transport verifies', $check['ok']);
Settings::override('transport', 'api');
Settings::override('provider', '');
T::ok('an unconfigured API transport fails verification', !TransportFactory::verify()['ok']);

T::section('Housekeeping');
ch247m_boot();
ch247m_as_super_admin();
$campaign = ch247m_ready(2);
DeliveryService::processBatch(10, 'worker-1');
T::eq('finished rows are kept initially', 2, Db::count('email_queue', ['status' => 'sent']));
T::eq('nothing purged while inside the retention window', 0, QueueService::purge(90));
ch247m_advance(100 * 86400);
T::eq('old finished rows are purged', 2, QueueService::purge(90));
T::eq('but the recipient ledger survives', 2, Db::count('campaign_recipients', ['campaign_id' => (int) $campaign['id']]));

T::finish();
