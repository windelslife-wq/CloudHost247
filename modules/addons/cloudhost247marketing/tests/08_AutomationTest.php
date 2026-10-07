<?php
/** Event-triggered journeys: enrolment, waits, sends, and dropping out. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Mkt\Audience\ListService;
use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Automation\AutomationService;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\ValidationException;
use Ch247Mkt\Delivery\ComplianceService;
use Ch247Mkt\Delivery\DeliveryService;
use Ch247Mkt\Delivery\QueueService;
use Ch247Mkt\Transport\NullTransport;

/** A two-email welcome journey with a wait in between. */
function ch247m_journey($waitSeconds = 86400)
{
    $one = CampaignService::create(['name' => 'Welcome 1', 'type' => 'automation', 'subject' => 'Welcome {{first_name}}'], 1);
    CampaignService::update((int) $one['id'], ['design' => ch247m_design()], 1);
    $two = CampaignService::create(['name' => 'Welcome 2', 'type' => 'automation', 'subject' => 'Getting started'], 1);
    CampaignService::update((int) $two['id'], ['design' => ch247m_design()], 1);

    $automation = AutomationService::create([
        'name' => 'Welcome series',
        'trigger_event' => 'client.created',
    ], 1);
    AutomationService::addStep((int) $automation['id'], ['action' => 'send_email', 'campaign_id' => (int) $one['id']], 1);
    AutomationService::addStep((int) $automation['id'], ['action' => 'wait', 'wait_seconds' => $waitSeconds], 1);
    AutomationService::addStep((int) $automation['id'], ['action' => 'send_email', 'campaign_id' => (int) $two['id']], 1);
    AutomationService::update((int) $automation['id'], ['enabled' => 1], 1);
    return AutomationService::find((int) $automation['id']);
}

ch247m_boot();
ch247m_as_super_admin();

T::section('Defining an automation');
$automation = AutomationService::create(['name' => 'Welcome series', 'trigger_event' => 'client.created'], 1);
T::ok('it is created', (int) $automation['id'] > 0);
T::eq('but starts switched off', 0, (int) $automation['enabled']);
T::ok('it gets a slug', $automation['slug'] !== '');
T::throws('an unknown trigger is refused', function () {
    AutomationService::create(['name' => 'Nope', 'trigger_event' => 'the.stars.align'], 1);
}, ValidationException::class);
T::throws('a nameless automation is refused', function () {
    AutomationService::create(['name' => '', 'trigger_event' => 'manual'], 1);
}, ValidationException::class);

T::section('Steps');
T::throws('an unknown action is refused', function () use ($automation) {
    AutomationService::addStep((int) $automation['id'], ['action' => 'launch_rocket'], 1);
}, ValidationException::class);
T::throws('a send step without a campaign is refused', function () use ($automation) {
    AutomationService::addStep((int) $automation['id'], ['action' => 'send_email'], 1);
}, ValidationException::class);
AutomationService::addStep((int) $automation['id'], ['action' => 'wait', 'wait_seconds' => 3600], 1);
AutomationService::addStep((int) $automation['id'], ['action' => 'add_tag', 'config' => ['tag' => 'welcomed']], 1);
$steps = AutomationService::steps((int) $automation['id']);
T::eq('two steps', 2, count($steps));
T::eq('they are ordered', 'wait', $steps[0]['action']);
T::eq('and numbered from one', 1, (int) $steps[0]['position']);
AutomationService::removeStep((int) $steps[0]['id'], 1);
T::eq('a step can be removed', 1, count(AutomationService::steps((int) $automation['id'])));

T::section('Triggers only fire enabled automations');
ch247m_boot();
ch247m_as_super_admin();
$sub = ch247m_subscriber('ada@example.test', ['client_id' => 11]);
$automation = AutomationService::create(['name' => 'Welcome', 'trigger_event' => 'client.created'], 1);
AutomationService::addStep((int) $automation['id'], ['action' => 'add_tag', 'config' => ['tag' => 'new']], 1);

T::eq('a disabled automation ignores its trigger', 0, AutomationService::trigger('client.created', ['client_id' => 11]));
AutomationService::update((int) $automation['id'], ['enabled' => 1], 1);
T::eq('an enabled one enrols', 1, AutomationService::trigger('client.created', ['client_id' => 11]));
T::eq('one enrollment exists', 1, Db::count('automation_enrollments'));

T::eq('the same person is not enrolled twice', 0, AutomationService::trigger('client.created', ['client_id' => 11]));
T::eq('a different event does nothing', 0, AutomationService::trigger('invoice.paid', ['client_id' => 11]));
T::eq('an unknown event does nothing', 0, AutomationService::trigger('not.a.real.event', ['client_id' => 11]));
T::eq('"manual" cannot be fired by a hook', 0, AutomationService::trigger('manual', ['client_id' => 11]));
T::eq('an unknown client enrols nobody', 0, AutomationService::trigger('client.created', ['client_id' => 9999]));

T::section('Triggers never throw at the hook');
// A hook firing during checkout must not be able to break checkout.
Db::exec('DROP TABLE ' . Db::t('automation_enrollments'));
T::eq('a broken automation table is swallowed', 0, AutomationService::trigger('client.created', ['client_id' => 22]));

T::section('The master switch');
ch247m_boot();
ch247m_as_super_admin();
ch247m_subscriber('ada@example.test', ['client_id' => 11]);
$automation = AutomationService::create(['name' => 'Welcome', 'trigger_event' => 'client.created'], 1);
AutomationService::addStep((int) $automation['id'], ['action' => 'add_tag', 'config' => ['tag' => 'new']], 1);
AutomationService::update((int) $automation['id'], ['enabled' => 1], 1);
Settings::override('automations_enabled', '0');
T::eq('automations off means nothing enrols', 0, AutomationService::trigger('client.created', ['client_id' => 11]));
T::eq('and the tick does nothing', 0, AutomationService::tick()['advanced']);
Settings::override('automations_enabled', '1');
T::eq('turning it on resumes enrolment', 1, AutomationService::trigger('client.created', ['client_id' => 11]));

T::section('Nobody unmailable is ever enrolled');
ch247m_boot();
ch247m_as_super_admin();
$automation = AutomationService::create(['name' => 'Welcome', 'trigger_event' => 'manual'], 1);
AutomationService::addStep((int) $automation['id'], ['action' => 'add_tag', 'config' => ['tag' => 'x']], 1);
AutomationService::update((int) $automation['id'], ['enabled' => 1], 1);

$ok = ch247m_subscriber('fine@example.test');
$out = ch247m_subscriber('out@example.test');
SubscriberService::unsubscribe((int) $out['id'], ['source' => 'link']);
$blocked = ch247m_subscriber('blocked@example.test');
ComplianceService::suppress('blocked@example.test', 'complaint', []);

T::ok('a mailable subscriber enrols', AutomationService::enroll((int) $automation['id'], (int) $ok['id']) !== null);
T::eq('an unsubscribed one does not', null, AutomationService::enroll((int) $automation['id'], (int) $out['id']));
T::eq('a suppressed one does not', null, AutomationService::enroll((int) $automation['id'], (int) $blocked['id']));
T::eq('an unknown subscriber does not', null, AutomationService::enroll((int) $automation['id'], 99999));
T::eq('only one enrollment exists', 1, Db::count('automation_enrollments'));

T::section('Re-entry');
T::eq('re-enrolling is refused by default', null, AutomationService::enroll((int) $automation['id'], (int) $ok['id']));
AutomationService::update((int) $automation['id'], ['reentry_allowed' => 1], 1);
T::eq('still refused while a journey is live', null, AutomationService::enroll((int) $automation['id'], (int) $ok['id']));
$live = Db::first('automation_enrollments', ['subscriber_id' => (int) $ok['id']]);
AutomationService::cancelEnrollment((int) $live['id'], 'test');
T::ok('allowed once the previous run has ended', AutomationService::enroll((int) $automation['id'], (int) $ok['id']) !== null);

/* ============================================================ run === */

ch247m_boot();
ch247m_as_super_admin();

T::section('Walking a journey');
$automation = ch247m_journey(86400);
$sub = ch247m_subscriber('ada@example.test', ['client_id' => 11]);
T::eq('the trigger enrols them', 1, AutomationService::trigger('client.created', ['client_id' => 11]));

$summary = AutomationService::tick();
T::eq('the first tick runs one step', 1, $summary['advanced']);
T::eq('and queues the first email', 1, Db::count('email_queue'));
T::eq('nothing completed yet', 0, $summary['completed']);

$enrollment = Db::first('automation_enrollments', ['subscriber_id' => (int) $sub['id']]);
T::eq('the cursor moved to step two', 1, (int) $enrollment['current_step']);

$summary = AutomationService::tick();
T::eq('the next tick hits the wait', 1, $summary['advanced']);
$enrollment = Db::first('automation_enrollments', ['subscriber_id' => (int) $sub['id']]);
T::eq('and parks the enrollment', AutomationService::STATUS_WAITING, $enrollment['status']);
T::ok('with an absolute wake-up time', $enrollment['next_run_at'] > \Ch247Mkt\Core\Clock::now());

T::eq('ticking again changes nothing while it waits', 0, AutomationService::tick()['advanced']);
T::eq('and sends nothing', 1, Db::count('email_queue'));

// A missed cron must resume, not replay: jump well past the wake-up time.
ch247m_advance(86400 * 3);
$summary = AutomationService::tick();
T::eq('after the wait it sends the second email', 1, $summary['advanced']);
T::eq('two emails queued in total', 2, Db::count('email_queue'));
$firstCampaign = (int) AutomationService::steps((int) $automation['id'])[0]['campaign_id'];
T::eq('the long gap did not replay the first step', 1, Db::count('campaign_recipients', ['campaign_id' => $firstCampaign]));

$summary = AutomationService::tick();
T::eq('the journey completes', 1, $summary['completed']);
$enrollment = Db::first('automation_enrollments', ['subscriber_id' => (int) $sub['id']]);
T::eq('status completed', AutomationService::STATUS_COMPLETED, $enrollment['status']);
T::ok('and stamped', !empty($enrollment['completed_at']));
T::eq('the counter on the automation moved', 1, (int) AutomationService::find((int) $automation['id'])['completed_count']);
T::eq('further ticks do nothing', 0, AutomationService::tick()['advanced']);

T::section('Automation email is real, tracked email');
DeliveryService::processBatch(10, 'worker-1');
T::eq('both messages were delivered', 2, count(NullTransport::$sent));
$message = NullTransport::$sent[0];
// The trigger context wins over the stored subscriber record: this journey
// started from WHMCS client 11, so the name on the account is used.
T::contains('merge tags resolved from the trigger context', 'Welcome Ada', $message->subject);
T::ok('it carries a List-Unsubscribe header', isset($message->headers['List-Unsubscribe']));
T::ok('it is tagged as automation traffic', isset($message->headers['X-CH247M-Automation']));
T::contains('and the body has a working unsubscribe link', 't=u&amp;r=', $message->html);
$recipient = Db::first('campaign_recipients', ['email' => 'ada@example.test']);
T::ok('a recipient row exists so opens and clicks are attributable', $recipient !== null);
T::eq('and the ledger records the delivery', 'sent', $recipient['status']);
T::ok('with a delivery timestamp', !empty($recipient['delivered_at']));
$stats = \Ch247Mkt\Delivery\AnalyticsService::campaignStats((int) $recipient['campaign_id']);
T::eq('so automated mail shows up in reporting too', 1, $stats['delivered']);

T::section('Unsubscribing mid-journey ends it');
ch247m_boot();
ch247m_as_super_admin();
$automation = ch247m_journey(3600);
$sub = ch247m_subscriber('ada@example.test', ['client_id' => 11]);
AutomationService::trigger('client.created', ['client_id' => 11]);
AutomationService::tick(); // send #1
AutomationService::tick(); // hit the wait
SubscriberService::unsubscribe((int) $sub['id'], ['source' => 'link']);
ch247m_advance(7200);
AutomationService::tick();
$enrollment = Db::first('automation_enrollments', ['subscriber_id' => (int) $sub['id']]);
T::eq('the enrollment is cancelled', AutomationService::STATUS_CANCELLED, $enrollment['status']);
T::eq('and the second email was never queued', 1, Db::count('email_queue'));

T::section('Disabling an automation stops journeys in flight');
ch247m_boot();
ch247m_as_super_admin();
$automation = ch247m_journey(3600);
ch247m_subscriber('ada@example.test', ['client_id' => 11]);
AutomationService::trigger('client.created', ['client_id' => 11]);
AutomationService::tick();
AutomationService::update((int) $automation['id'], ['enabled' => 0], 1);
ch247m_advance(7200);
AutomationService::tick();
T::eq('in-flight enrollments are cancelled', AutomationService::STATUS_CANCELLED, Db::first('automation_enrollments')['status']);

T::section('One bad step does not stop the queue');
ch247m_boot();
ch247m_as_super_admin();
$automation = ch247m_journey(60);
ch247m_subscriber('ada@example.test', ['client_id' => 11]);
ch247m_subscriber('luca@example.test', ['client_id' => 22]);
AutomationService::trigger('client.created', ['client_id' => 11]);
AutomationService::trigger('client.created', ['client_id' => 22]);
// Point the first step at a campaign that no longer exists.
$steps = AutomationService::steps((int) $automation['id']);
Db::update('automation_steps', ['id' => (int) $steps[0]['id']], ['campaign_id' => 999999]);
$summary = AutomationService::tick();
T::eq('both enrollments fail on that step', 2, $summary['failed']);
T::eq('they are marked failed, not retried forever', 2, Db::count('automation_enrollments', ['status' => AutomationService::STATUS_FAILED]));
T::eq('and the tick still returned a summary', 0, $summary['advanced']);

T::section('Abandoned carts');
ch247m_boot();
ch247m_as_super_admin();
Settings::override('abandoned_cart_minutes', '120');
$automation = AutomationService::create(['name' => 'Cart recovery', 'trigger_event' => 'cart.abandoned'], 1);
$campaign = CampaignService::create(['name' => 'Still interested?', 'type' => 'automation', 'subject' => 'Still interested?'], 1);
CampaignService::update((int) $campaign['id'], ['design' => ch247m_design()], 1);
AutomationService::addStep((int) $automation['id'], ['action' => 'send_email', 'campaign_id' => (int) $campaign['id']], 1);
AutomationService::update((int) $automation['id'], ['enabled' => 1], 1);
ch247m_subscriber('ada@example.test', ['client_id' => 11]);

AutomationService::touchCart(11);
T::eq('the cart is recorded', 1, Db::count('cart_activity', ['client_id' => 11]));
AutomationService::touchCart(11);
T::eq('browsing again updates rather than duplicates', 1, Db::count('cart_activity', ['client_id' => 11]));
T::eq('a fresh cart is not abandoned yet', 0, AutomationService::sweepAbandonedCarts());

ch247m_advance(3 * 3600);
T::eq('after the window it enrols', 1, AutomationService::sweepAbandonedCarts());
T::eq('and only once', 0, AutomationService::sweepAbandonedCarts());

AutomationService::tick();
T::eq('the recovery email is queued', 1, Db::count('email_queue'));

AutomationService::touchCart(22);
AutomationService::clearCart(22);
ch247m_advance(3 * 3600);
T::eq('a converted cart is never chased', 0, AutomationService::sweepAbandonedCarts());

T::section('Cart recovery is driven by the sweep, not by page views');
ch247m_boot();
ch247m_as_super_admin();
$automation = AutomationService::create(['name' => 'Cart recovery', 'trigger_event' => 'cart.abandoned'], 1);
AutomationService::addStep((int) $automation['id'], ['action' => 'add_tag', 'config' => ['tag' => 'cart']], 1);
AutomationService::update((int) $automation['id'], ['enabled' => 1], 1);
ch247m_subscriber('ada@example.test', ['client_id' => 11]);

AutomationService::touchCart(11);
T::eq('browsing a cart enrols nobody by itself', 0, Db::count('automation_enrollments'));
T::eq('and nothing is due yet', 0, AutomationService::sweepAbandonedCarts());
ch247m_advance(3 * 3600);
T::eq('the cron sweep is what notices', 1, AutomationService::sweepAbandonedCarts());
T::eq('one enrollment resulted', 1, Db::count('automation_enrollments'));

// Turning the automation off stops the sweep chasing anyone.
ch247m_boot();
ch247m_as_super_admin();
$automation = AutomationService::create(['name' => 'Cart recovery', 'trigger_event' => 'cart.abandoned'], 1);
AutomationService::addStep((int) $automation['id'], ['action' => 'add_tag', 'config' => ['tag' => 'cart']], 1);
ch247m_subscriber('ada@example.test', ['client_id' => 11]);
AutomationService::touchCart(11);
ch247m_advance(3 * 3600);
T::eq('a disabled automation means no chasing', 0, AutomationService::sweepAbandonedCarts());
T::eq('and no enrollments', 0, Db::count('automation_enrollments'));

T::section('The tick is bounded');
ch247m_boot();
ch247m_as_super_admin();
$automation = AutomationService::create(['name' => 'Tagger', 'trigger_event' => 'manual'], 1);
AutomationService::addStep((int) $automation['id'], ['action' => 'add_tag', 'config' => ['tag' => 'x']], 1);
AutomationService::update((int) $automation['id'], ['enabled' => 1], 1);
for ($i = 1; $i <= 12; $i++) {
    $s = ch247m_subscriber('many' . $i . '@example.test');
    AutomationService::enroll((int) $automation['id'], (int) $s['id']);
}
T::eq('twelve journeys are waiting', 12, Db::count('automation_enrollments'));
$summary = AutomationService::tick(5);
T::eq('one tick only advances up to its limit', 5, $summary['advanced']);
T::eq('the rest wait for the next cron run', 7, Db::count('automation_enrollments', ['current_step' => 0]));

T::finish();
