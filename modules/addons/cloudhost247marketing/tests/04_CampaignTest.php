<?php
/** Campaign lifecycle: compile, audience resolution, scheduling, state transitions. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Mkt\Audience\ListService;
use Ch247Mkt\Audience\SegmentService;
use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\ValidationException;
use Ch247Mkt\Delivery\ComplianceService;
use Ch247Mkt\Delivery\QueueService;

ch247m_boot();
ch247m_as_super_admin();

T::section('Creation and compilation');
$list = ListService::create(['name' => 'Customers'], 1);
$campaign = ch247m_campaign([(int) $list['id']]);
T::eq('starts as a draft', CampaignService::STATUS_DRAFT, $campaign['status']);
T::ok('gets a public uid', strlen((string) $campaign['uid']) >= 12);
T::contains('html was compiled from the design', 'View plans', $campaign['html']);
T::ok('a plain-text alternative was generated', trim((string) $campaign['text_body']) !== '');
T::notContains('text part is not HTML', '<table', $campaign['text_body']);
T::contains('text part keeps the link target', 'cloudhost247.test/plans', $campaign['text_body']);

T::section('Link rewriting and the open pixel');
T::contains('links are rewritten through the redirector', 'track.php?t=c', $campaign['html']);
T::contains('an open pixel was injected', 't=o', $campaign['html']);
T::contains('the recipient sentinel is embedded', \Ch247Mkt\Delivery\TrackingService::RECIPIENT_SENTINEL, $campaign['html']);
T::eq('one trackable link registered', 1, Db::count('links', ['campaign_id' => (int) $campaign['id']]));
T::eq('the unsubscribe link is not rewritten into a click', 1, Db::count('links', ['campaign_id' => (int) $campaign['id']]));

CampaignService::update((int) $campaign['id'], ['design' => ch247m_design()], 1);
T::eq('recompiling does not duplicate links', 1, Db::count('links', ['campaign_id' => (int) $campaign['id']]));

T::section('Three campaign types');
foreach (['campaign', 'automation', 'transactional'] as $type) {
    $typed = CampaignService::create(['name' => 'A ' . $type, 'type' => $type, 'subject' => 'Hi'], 1);
    T::eq($type . ' accepted', $type, $typed['type']);
}
T::throws('an unknown type is rejected', function () {
    CampaignService::create(['name' => 'Bad', 'type' => 'spam-blast'], 1);
}, ValidationException::class);

/* ======================================================== audience === */

ch247m_boot();
ch247m_as_super_admin();

T::section('Audience resolution');
$listA = ListService::create(['name' => 'List A'], 1);
$listB = ListService::create(['name' => 'List B'], 1);

$one = ch247m_subscriber('one@example.test');
$two = ch247m_subscriber('two@example.test');
$three = ch247m_subscriber('three@example.test');
$gone = ch247m_subscriber('gone@example.test');

ListService::addMember((int) $listA['id'], (int) $one['id']);
ListService::addMember((int) $listA['id'], (int) $two['id']);
ListService::addMember((int) $listB['id'], (int) $two['id']); // in both lists
ListService::addMember((int) $listB['id'], (int) $three['id']);
ListService::addMember((int) $listA['id'], (int) $gone['id']);
SubscriberService::unsubscribe((int) $gone['id'], ['source' => 'link']);

$campaign = ch247m_campaign([(int) $listA['id'], (int) $listB['id']]);
$resolved = CampaignService::resolveAudience($campaign);
T::eq('three mailable people across two lists', 3, count($resolved['recipients']));
T::ok('duplicates were removed', $resolved['stats']['deduplicated'] >= 1);
$emails = array_map(function ($r) { return $r['email']; }, $resolved['recipients']);
T::ok('the unsubscribed member is absent', !in_array('gone@example.test', $emails, true));

ComplianceService::suppress('three@example.test', 'complaint', ['source' => 'feedback loop']);
$resolved = CampaignService::resolveAudience($campaign);
T::eq('a suppressed address drops out', 2, count($resolved['recipients']));
T::eq('and is counted as suppressed', 1, $resolved['stats']['suppressed']);

T::section('Exclusions');
CampaignService::update((int) $campaign['id'], ['audience' => [
    'lists' => [(int) $listA['id'], (int) $listB['id']],
    'exclude_lists' => [(int) $listB['id']],
]], 1);
$excluded = CampaignService::resolveAudience(CampaignService::find((int) $campaign['id']));
T::eq('excluded list members are removed', 1, count($excluded['recipients']));
T::ok('exclusions are counted', $excluded['stats']['excluded'] >= 1);

T::section('WHMCS segments materialise subscribers');
ch247m_boot();
ch247m_as_super_admin();
$segment = SegmentService::create([
    'name' => 'Nigerian hosting clients',
    'source' => SegmentService::SOURCE_WHMCS,
    'definition' => ['match' => 'all', 'rules' => [
        ['field' => 'client_status', 'op' => 'is', 'value' => 'Active'],
        ['field' => 'country', 'op' => 'is', 'value' => 'NG'],
    ]],
], 1);
$campaign = CampaignService::create(['name' => 'Nigeria promo', 'subject' => 'Hello {{first_name}}'], 1);
CampaignService::update((int) $campaign['id'], [
    'design' => ch247m_design(),
    'audience' => ['segments' => [(int) $segment['id']]],
], 1);
T::eq('no subscriber rows yet', 0, Db::count('subscribers'));

$built = CampaignService::buildRecipients((int) $campaign['id'], 1);
T::eq('one WHMCS client became a recipient', 1, $built['count']);
T::eq('and a subscriber record was created for them', 1, Db::count('subscribers'));
$created = Db::first('subscribers', ['client_id' => 11]);
T::eq('consent source records where they came from', 'whmcs_client', $created['consent_source']);
T::ok('so they can unsubscribe like anyone else', (int) $created['id'] > 0);

T::section('Freezing the audience is idempotent');
$again = CampaignService::buildRecipients((int) $campaign['id'], 1);
T::eq('rebuilding does not duplicate recipients', 1, $again['count']);
T::eq('one recipient row total', 1, Db::count('campaign_recipients', ['campaign_id' => (int) $campaign['id']]));
$recipient = Db::first('campaign_recipients', ['campaign_id' => (int) $campaign['id']]);
T::ok('each recipient gets a unique tracking token', strlen((string) $recipient['token']) >= 16);

/* ===================================================== transitions == */

ch247m_boot();
ch247m_as_super_admin();

T::section('Scheduling');
$list = ListService::create(['name' => 'All'], 1);
$sub = ch247m_subscriber('sched@example.test');
ListService::addMember((int) $list['id'], (int) $sub['id']);
$campaign = ch247m_campaign([(int) $list['id']]);

CampaignService::schedule((int) $campaign['id'], '2026-10-07 09:00', 'Africa/Lagos', 1);
$scheduled = CampaignService::find((int) $campaign['id']);
T::eq('status becomes scheduled', CampaignService::STATUS_SCHEDULED, $scheduled['status']);
T::eq('timezone stored', 'Africa/Lagos', $scheduled['timezone']);
T::eq('stored in UTC', '2026-10-07 08:00:00', $scheduled['scheduled_at']);
T::eq('and displays back in local time', '2026-10-07 09:00', substr(CampaignService::toLocal($scheduled['scheduled_at'], 'Africa/Lagos'), 0, 16));

T::throws('a past send time is refused', function () use ($campaign) {
    CampaignService::schedule((int) $campaign['id'], '2020-01-01 00:00', 'UTC', 1);
}, ValidationException::class);

T::eq('not yet due', 0, count(CampaignService::dueForSending()));
ch247m_freeze('2026-10-07 08:30:00');
T::eq('due once the clock passes it', 1, count(CampaignService::dueForSending()));
ch247m_freeze('2026-10-06 12:00:00');

T::section('Send, pause, resume, cancel');
ch247m_boot();
ch247m_as_super_admin();
$list = ListService::create(['name' => 'All'], 1);
foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $email) {
    $s = ch247m_subscriber($email);
    ListService::addMember((int) $list['id'], (int) $s['id']);
}
$campaign = ch247m_campaign([(int) $list['id']]);

$sent = CampaignService::send((int) $campaign['id'], 1);
T::eq('three messages queued', 3, $sent['queued']);
T::eq('campaign is now sending', CampaignService::STATUS_SENDING, CampaignService::find((int) $campaign['id'])['status']);
T::eq('queue holds three pending rows', 3, QueueService::stats((int) $campaign['id'])['pending']);

T::throws('a campaign already sending cannot be sent again', function () use ($campaign) {
    CampaignService::send((int) $campaign['id'], 1);
}, ValidationException::class);
// And below that guard, the queue itself is idempotent: a crashed worker that
// re-runs enqueueCampaign must not double-send.
T::eq('re-enqueueing produces no duplicates', 0, QueueService::enqueueCampaign((int) $campaign['id']));
T::eq('still only three queue rows', 3, Db::count('email_queue', ['campaign_id' => (int) $campaign['id']]));

CampaignService::pause((int) $campaign['id'], 1);
T::eq('paused', CampaignService::STATUS_PAUSED, CampaignService::find((int) $campaign['id'])['status']);
T::eq('a paused campaign yields no work', 0, count(QueueService::claimBatch(10, 'test-worker')));

CampaignService::resume((int) $campaign['id'], 1);
T::eq('resumed', CampaignService::STATUS_SENDING, CampaignService::find((int) $campaign['id'])['status']);
T::eq('and work is claimable again', 3, count(QueueService::claimBatch(10, 'test-worker')));

QueueService::releaseStaleLocks(0);
$cancelled = CampaignService::cancel((int) $campaign['id'], 1);
T::eq('cancelling stops the queued messages', 3, $cancelled['cancelled']);
T::eq('status is cancelled', CampaignService::STATUS_CANCELLED, CampaignService::find((int) $campaign['id'])['status']);
T::eq('nothing left pending', 0, QueueService::stats((int) $campaign['id'])['pending']);

T::section('A sent campaign is immutable');
T::throws('cannot edit a cancelled campaign', function () use ($campaign) {
    CampaignService::update((int) $campaign['id'], ['subject' => 'Changed'], 1);
}, ValidationException::class);

$copy = CampaignService::duplicate((int) $campaign['id'], 1);
T::eq('the duplicate is an editable draft', CampaignService::STATUS_DRAFT, $copy['status']);
T::ok('with its own uid', $copy['uid'] !== $campaign['uid']);
T::eq('and no recipients carried over', 0, Db::count('campaign_recipients', ['campaign_id' => (int) $copy['id']]));
T::eq('and no counters carried over', 0, (int) $copy['count_sent']);

T::section('Test sends');
ch247m_boot();
ch247m_as_super_admin();
$campaign = ch247m_campaign([]);
$test = CampaignService::sendTest((int) $campaign['id'], ['me@example.test', 'colleague@example.test'], 1);
T::eq('two test messages queued', 2, $test['queued']);
$row = Db::first('email_queue', ['to_email' => 'me@example.test']);
$payload = json_decode((string) $row['payload'], true);
T::contains('test subject is prefixed', '[TEST]', $payload['subject']);
T::notContains('the sentinel never ships in a test', \Ch247Mkt\Delivery\TrackingService::RECIPIENT_SENTINEL, $payload['html']);
T::eq('test sends do not touch the real audience', 0, Db::count('campaign_recipients', ['campaign_id' => (int) $campaign['id']]));
T::eq('and leave the campaign a draft', CampaignService::STATUS_DRAFT, CampaignService::find((int) $campaign['id'])['status']);

T::throws('more than ten test addresses is refused', function () use ($campaign) {
    CampaignService::sendTest((int) $campaign['id'], array_map(function ($i) {
        return 't' . $i . '@example.test';
    }, range(1, 11)), 1);
}, ValidationException::class);

T::section('Completion');
ch247m_boot();
ch247m_as_super_admin();
$list = ListService::create(['name' => 'Tiny'], 1);
$s = ch247m_subscriber('only@example.test');
ListService::addMember((int) $list['id'], (int) $s['id']);
$campaign = ch247m_campaign([(int) $list['id']]);
CampaignService::send((int) $campaign['id'], 1);
T::ok('not finished while work remains', !CampaignService::finishIfDrained((int) $campaign['id']));
Db::exec('UPDATE ' . Db::t('email_queue') . ' SET status = ? WHERE campaign_id = ?', ['sent', (int) $campaign['id']]);
T::ok('finishes once the queue drains', CampaignService::finishIfDrained((int) $campaign['id']));
T::eq('status sent', CampaignService::STATUS_SENT, CampaignService::find((int) $campaign['id'])['status']);
T::ok('finished timestamp set', !empty(CampaignService::find((int) $campaign['id'])['finished_at']));

T::finish();
