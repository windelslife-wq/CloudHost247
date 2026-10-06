<?php
/** Pre-flight gates, the suppression list, consent and the audit trail. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Mkt\Audience\ListService;
use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\ValidationException;
use Ch247Mkt\Delivery\ComplianceService;
use Ch247Mkt\Delivery\DeliveryService;
use Ch247Mkt\Delivery\TrackingService;

/** Shorthand: are any blockers of this shape present? */
function ch247m_blocked($report, $needle)
{
    foreach ($report['blockers'] as $blocker) {
        if (stripos(is_array($blocker) ? json_encode($blocker) : (string) $blocker, $needle) !== false) {
            return true;
        }
    }
    return false;
}

ch247m_boot();
ch247m_as_super_admin();

T::section('Pre-flight passes a well-formed campaign');
$campaign = ch247m_campaign([]);
$report = ComplianceService::preflight($campaign);
T::ok('a compliant campaign is clear to send', $report['ok']);
T::eq('no blockers', 0, count($report['blockers']));

T::section('Pre-flight blockers');
Settings::override('sending_enabled', '0');
T::ok('sending disabled blocks the send', !ComplianceService::preflight($campaign)['ok']);
Settings::override('sending_enabled', '1');

Settings::override('kill_switch', '1');
T::ok('the kill switch blocks the send', !ComplianceService::preflight($campaign)['ok']);
Settings::override('kill_switch', '0');

$noSubject = $campaign;
$noSubject['subject'] = '   ';
$report = ComplianceService::preflight($noSubject);
T::ok('an empty subject blocks the send', !$report['ok']);
T::ok('and says so', ch247m_blocked($report, 'subject'));

$badFrom = $campaign;
$badFrom['from_email'] = 'not-an-address';
T::ok('a malformed from address blocks the send', !ComplianceService::preflight($badFrom)['ok']);

$badReply = $campaign;
$badReply['reply_to'] = 'nope@@example';
T::ok('a malformed reply-to blocks the send', !ComplianceService::preflight($badReply)['ok']);

$empty = $campaign;
$empty['html'] = '';
T::ok('an empty body blocks the send', !ComplianceService::preflight($empty)['ok']);

T::section('Marketing mail must be unsubscribable');
$noUnsub = $campaign;
$noUnsub['html'] = '<p>Buy our hosting. No way out.</p>';
$report = ComplianceService::preflight($noUnsub);
T::ok('a missing unsubscribe link blocks the send', !$report['ok']);
T::ok('and names the problem', ch247m_blocked($report, 'unsubscribe'));

$noUnsub['type'] = 'transactional';
T::ok('transactional mail is exempt', ComplianceService::preflight($noUnsub)['ok']);

T::section('A physical postal address is required');
Settings::override('physical_address', '');
$report = ComplianceService::preflight($campaign);
T::ok('no address, no marketing send', !$report['ok']);
T::ok('and the operator is told why', ch247m_blocked($report, 'address'));
$transactional = $campaign;
$transactional['type'] = 'transactional';
T::ok('transactional mail is exempt from the address rule', ComplianceService::preflight($transactional)['ok']);
Settings::override('physical_address', '1 Marina Road, Lagos, Nigeria');

T::section('Content checks advise, they do not block');
$spammy = ComplianceService::contentCheck('FREE!!! ACT NOW!!! 100% GUARANTEED', '<p>CLICK HERE</p>', '');
T::ok('shouty spam-bait is flagged', count($spammy['notes']) > 0);
T::ok('and scores badly', $spammy['score'] > 0);
$clean = ComplianceService::contentCheck(
    'Your October hosting update',
    '<p>Hello, here is a readable paragraph about what changed on your hosting this month, '
        . 'including the maintenance window and the new backup retention.</p><a href="x">Unsubscribe</a>',
    'Hello, here is a readable paragraph about what changed on your hosting this month.'
);
T::ok('ordinary copy scores lower than spam-bait', $clean['score'] < $spammy['score']);
T::eq('a clean email gets the all-clear note', 1, count($clean['notes']));
T::ok('script tags are called out', ComplianceService::contentCheck('Hi', '<script>alert(1)</script>', 'x')['score'] >= 4);
T::ok('raw-IP links are called out', ComplianceService::contentCheck('Hi', '<a href="http://203.0.113.9/x">go</a>', 'some body text')['score'] >= 3);
$imageOnly = ComplianceService::contentCheck('Hi', '<img src="https://x.test/a.png">', '');
T::ok('an image-only email is flagged', $imageOnly['score'] >= 3);
// None of this stops a send — the operator decides.
$campaign = ch247m_campaign([]);
T::ok('a low-quality but legal email can still be sent', ComplianceService::preflight($campaign)['ok']);

T::section('Merge tags are validated before sending');
$mergeList = ListService::create(['name' => 'Merge check'], 1);
$mergeSub = ch247m_subscriber('merge@example.test');
ListService::addMember((int) $mergeList['id'], (int) $mergeSub['id']);
$campaign = ch247m_campaign([(int) $mergeList['id']]);
CampaignService::update((int) $campaign['id'], ['subject' => 'Hi {{first_name}} {{not_a_real_tag}}'], 1);
$report = CampaignService::preflight((int) $campaign['id']);
T::ok('an unknown merge tag blocks the send', !$report['ok']);
T::ok('and the tag is named', ch247m_blocked($report, 'not_a_real_tag'));
CampaignService::update((int) $campaign['id'], ['subject' => 'Hi {{first_name}}'], 1);
T::ok('fixing the tag clears the block', CampaignService::preflight((int) $campaign['id'])['ok']);

T::section('Send is refused when pre-flight fails');
ch247m_boot();
ch247m_as_super_admin();
$list = ListService::create(['name' => 'People'], 1);
$s = ch247m_subscriber('person@example.test');
ListService::addMember((int) $list['id'], (int) $s['id']);
$campaign = ch247m_campaign([(int) $list['id']]);
Settings::override('from_email', '');
CampaignService::update((int) $campaign['id'], ['from_email' => ''], 1);
T::throws('the send is refused outright', function () use ($campaign) {
    CampaignService::send((int) $campaign['id'], 1);
}, ValidationException::class);
T::eq('nothing was queued', 0, Db::count('email_queue'));
T::eq('the campaign stayed a draft', CampaignService::STATUS_DRAFT, CampaignService::find((int) $campaign['id'])['status']);

/* =================================================== suppression === */

ch247m_boot();
ch247m_as_super_admin();

T::section('The suppression list');
ComplianceService::suppress('Blocked@Example.TEST', 'complaint', ['source' => 'feedback loop']);
T::ok('suppression is case-insensitive', ComplianceService::isSuppressed('blocked@example.test'));
T::ok('and whitespace-insensitive', ComplianceService::isSuppressed('  BLOCKED@example.test '));
T::ok('an unknown address is not suppressed', !ComplianceService::isSuppressed('fine@example.test'));

ComplianceService::suppress('blocked@example.test', 'complaint', ['source' => 'again']);
T::eq('suppressing twice keeps one row', 1, Db::count('suppressions'));

$among = ComplianceService::suppressedAmong(['blocked@example.test', 'fine@example.test', 'other@example.test']);
T::eq('bulk lookup finds the one', 1, count($among));
T::ok('and returns it keyed by address', in_array('blocked@example.test', array_map('strtolower', array_values($among)), true)
    || isset($among['blocked@example.test']));

foreach (ComplianceService::REASONS as $reason) {
    ComplianceService::suppress($reason . '@example.test', $reason, []);
}
T::eq('every documented reason is accepted', count(ComplianceService::REASONS), count(ComplianceService::reasonCounts()));
// A suppression must never be lost because the caller mislabelled it, so an
// unrecognised reason is recorded as "manual" rather than rejected.
ComplianceService::suppress('odd@example.test', 'because-i-said-so', []);
T::ok('an oddly-labelled suppression still suppresses', ComplianceService::isSuppressed('odd@example.test'));
T::eq('and is filed under "manual"', 'manual', Db::first('suppressions', ['email' => 'odd@example.test'])['reason']);

ComplianceService::suppress('downgrade@example.test', 'complaint', []);
ComplianceService::suppress('downgrade@example.test', 'manual', []);
T::eq('a complaint is never downgraded by a later suppression', 'complaint', Db::first('suppressions', ['email' => 'downgrade@example.test'])['reason']);

$found = ComplianceService::search('blocked');
T::eq('search finds the address', 1, count($found['rows']));
T::eq('filtering by reason works', 3, count(ComplianceService::search('', 'complaint')['rows']));
T::eq('filtering by a different reason narrows it', 1, count(ComplianceService::search('', 'invalid')['rows']));

T::section('Removing a suppression is deliberate and audited');
ComplianceService::unsuppress('blocked@example.test', 1, 'customer asked us to resume');
T::ok('the address can receive mail again', !ComplianceService::isSuppressed('blocked@example.test'));
$audit = Db::first('audit_log', ['action' => 'suppression.removed']);
T::ok('the removal is in the audit trail', $audit !== null);
T::contains('with the reason the admin gave', 'customer asked', (string) $audit['context']);
T::eq('attributed to the admin who did it', 1, (int) $audit['actor_id']);
T::eq('and typed as an admin action', 'admin', $audit['actor_type']);

T::section('Suppression beats list membership');
ch247m_boot();
ch247m_as_super_admin();
$list = ListService::create(['name' => 'Everyone'], 1);
$sub = ch247m_subscriber('returning@example.test');
ListService::addMember((int) $list['id'], (int) $sub['id']);
ComplianceService::suppress('returning@example.test', 'complaint', ['source' => 'fbl']);
// Re-importing or re-adding someone must not undo a complaint.
SubscriberService::upsert(['email' => 'returning@example.test', 'consent_source' => 'import']);
T::ok('still suppressed after a re-import', ComplianceService::isSuppressed('returning@example.test'));
$campaign = ch247m_campaign([(int) $list['id']]);
T::eq('and excluded from the audience', 0, count(CampaignService::resolveAudience($campaign)['recipients']));

T::section('Unsubscribing is honoured everywhere');
ch247m_boot();
ch247m_as_super_admin();
$listA = ListService::create(['name' => 'Newsletter'], 1);
$listB = ListService::create(['name' => 'Offers'], 1);
$sub = ch247m_subscriber('bye@example.test');
ListService::addMember((int) $listA['id'], (int) $sub['id']);
ListService::addMember((int) $listB['id'], (int) $sub['id']);

$campaign = ch247m_campaign([(int) $listA['id']]);
CampaignService::send((int) $campaign['id'], 1);
DeliveryService::processBatch(10, 'worker-1');
$recipient = Db::first('campaign_recipients', ['campaign_id' => (int) $campaign['id']]);
TrackingService::recordUnsubscribe($recipient['token'], ['ip' => '1.2.3.4', 'ua' => 'Mozilla/5.0']);

$after = SubscriberService::find((int) $sub['id']);
T::eq('the subscriber is unsubscribed globally', SubscriberService::STATUS_UNSUBSCRIBED, $after['status']);
T::ok('the address is on the suppression list', ComplianceService::isSuppressed('bye@example.test'));
$other = ch247m_campaign([(int) $listB['id']]);
T::eq('a different list does not reach them', 0, count(CampaignService::resolveAudience($other)['recipients']));
T::eq('an unsubscribe event was recorded', 1, Db::count('email_events', ['event' => 'unsubscribe']));
T::ok('the recipient row records it', !empty(Db::first('campaign_recipients', ['id' => (int) $recipient['id']])['unsubscribed_at']));

TrackingService::recordUnsubscribe($recipient['token'], []);
T::eq('clicking unsubscribe twice is harmless', 1, Db::count('email_events', ['event' => 'unsubscribe']));

T::section('Consent is recorded, not assumed');
ch247m_boot();
ch247m_as_super_admin();
$sub = SubscriberService::upsert([
    'email' => 'consented@example.test',
    'consent_source' => 'signup_form',
]);
T::ok('a consent timestamp is stored', !empty($sub['consent_at']));
T::eq('together with where it came from', 'signup_form', $sub['consent_source']);
// Consent is always attributed to something. Added by hand in the admin UI
// with no source given, that something is "admin" — never blank.
$byAdmin = SubscriberService::upsert(['email' => 'nosource@example.test']);
T::eq('an admin-added subscriber is attributed to the admin', 'admin', $byAdmin['consent_source']);
T::ok('and still gets a consent timestamp', !empty($byAdmin['consent_at']));
T::throws('an invalid address is refused outright', function () {
    SubscriberService::upsert(['email' => 'not-an-address', 'consent_source' => 'signup_form']);
}, ValidationException::class);

T::section('Double opt-in');
ch247m_boot();
ch247m_as_super_admin();
Settings::override('double_optin', '1');
$pending = SubscriberService::upsert(['email' => 'pending@example.test', 'consent_source' => 'signup_form']);
T::eq('starts unconfirmed', SubscriberService::STATUS_UNCONFIRMED, $pending['status']);
T::ok('an unconfirmed address is not mailable', !in_array($pending['status'], SubscriberService::MAILABLE, true));
T::ok('a confirmation token was issued', strlen((string) $pending['confirm_token']) >= 16);
$confirmed = SubscriberService::confirm($pending['confirm_token']);
T::eq('confirming makes them subscribed', SubscriberService::STATUS_SUBSCRIBED, $confirmed['status']);
T::ok('and stamps when they confirmed', !empty($confirmed['confirmed_at']));
T::ok('the token is burnt after use', empty($confirmed['confirm_token']));
T::throws('a stale confirmation link is refused', function () use ($pending) {
    SubscriberService::confirm($pending['confirm_token']);
}, \Ch247Mkt\Core\NotFoundException::class);

T::section('Audit trail');
ch247m_boot();
ch247m_as_super_admin();
$list = ListService::create(['name' => 'Audited'], 1);
$sub = ch247m_subscriber('audited@example.test');
ListService::addMember((int) $list['id'], (int) $sub['id']);
$campaign = ch247m_campaign([(int) $list['id']]);
CampaignService::schedule((int) $campaign['id'], '2026-10-09 10:00', 'Africa/Lagos', 1);
CampaignService::cancel((int) $campaign['id'], 1);
$actions = array_map(function ($row) {
    return $row['action'];
}, Db::all('audit_log', [], 'id ASC'));
foreach (['list.created', 'campaign.created', 'campaign.scheduled', 'campaign.cancelled'] as $expected) {
    T::ok('"' . $expected . '" is audited', in_array($expected, $actions, true));
}
$row = Db::first('audit_log', ['action' => 'campaign.scheduled']);
T::eq('every entry names the admin', 1, (int) $row['actor_id']);
T::ok('and is timestamped', !empty($row['created_at']));

// The log is hash-chained, so a deleted or edited row is detectable.
$chain = \Ch247Mkt\Core\Audit::verifyChain();
T::ok('the audit chain verifies', $chain['valid']);
T::ok('and covered every entry', $chain['checked'] >= count($actions));
Db::exec('UPDATE ' . Db::t('audit_log') . ' SET action = ? WHERE action = ?', ['campaign.unscheduled', 'campaign.scheduled']);
$tampered = \Ch247Mkt\Core\Audit::verifyChain();
T::ok('tampering with a row is detected', !$tampered['valid']);
T::ok('and the broken link is identified', (int) $tampered['broken_at'] > 0);

T::finish();
