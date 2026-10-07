<?php
/** Open/click/unsubscribe tracking, the click map and campaign analytics. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Mkt\Audience\ListService;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Campaign\Renderer;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Delivery\AnalyticsService;
use Ch247Mkt\Delivery\DeliveryService;
use Ch247Mkt\Delivery\TrackingService;

/** A delivered campaign with $count recipients, ready to be tracked. */
function ch247m_delivered($count = 3, array $design = null)
{
    $list = ListService::create(['name' => 'Tracked ' . uniqid()], 1);
    for ($i = 1; $i <= $count; $i++) {
        $s = ch247m_subscriber('t' . $i . '@example.test', ['first_name' => 'Person' . $i]);
        ListService::addMember((int) $list['id'], (int) $s['id']);
    }
    $campaign = ch247m_campaign([(int) $list['id']], $design === null ? [] : ['design' => $design]);
    CampaignService::send((int) $campaign['id'], 1);
    DeliveryService::processBatch(100, 'worker-1');
    return CampaignService::find((int) $campaign['id']);
}

ch247m_boot();
ch247m_as_super_admin();

T::section('Tracking URLs');
T::contains('open pixel url', 't=o', TrackingService::openUrl('abc'));
T::contains('click url carries both tokens', 'r=abc', TrackingService::clickUrl('lnk', 'abc'));
T::contains('and the link token', 'l=lnk', TrackingService::clickUrl('lnk', 'abc'));
T::contains('unsubscribe url', 't=u', TrackingService::unsubscribeUrl('abc'));
T::contains('preferences url', 't=p', TrackingService::preferencesUrl('abc'));
T::contains('webview url', 't=v', TrackingService::webviewUrl('abc'));
T::contains('urls are absolute', 'http', TrackingService::openUrl('abc'));
T::eq('the sentinel is the default recipient', true, strpos(TrackingService::openUrl(), TrackingService::RECIPIENT_SENTINEL) !== false);
T::ok('the sentinel is not a merge tag an author could type', strpos(TrackingService::RECIPIENT_SENTINEL, '{{') === false);

T::section('Link rewriting');
$html = '<a href="https://cloudhost247.test/a">A</a> <a href="https://cloudhost247.test/b">B</a>'
      . ' <a href="mailto:hi@cloudhost247.test">Mail</a>'
      . ' <a href="{{unsubscribe_url}}">Unsub</a>'
      . ' <a href="#top">Top</a>';
$rewritten = TrackingService::rewriteLinks($html, 1);
T::eq('two trackable links registered', 2, Db::count('links', ['campaign_id' => 1]));
T::contains('mailto is left alone', 'mailto:hi@cloudhost247.test', $rewritten);
T::contains('the unsubscribe tag is left alone', '{{unsubscribe_url}}', $rewritten);
T::contains('anchors are left alone', '"#top"', $rewritten);
T::eq('each http link is rewritten once', 2, substr_count($rewritten, 't=c'));

$again = TrackingService::rewriteLinks($html, 1);
T::eq('rewriting again reuses the same link rows', 2, Db::count('links', ['campaign_id' => 1]));
T::eq('and produces the same output', $rewritten, $again);

Settings::override('track_clicks', '0');
$untracked = TrackingService::rewriteLinks($html, 2);
T::notContains('click tracking can be turned off', 't=c', $untracked);
T::eq('and registers nothing', 0, Db::count('links', ['campaign_id' => 2]));
Settings::override('track_clicks', '1');

Settings::override('track_opens', '0');
T::notContains('open tracking can be turned off', 't=o', TrackingService::injectOpenPixel('<html><body>x</body></html>'));
Settings::override('track_opens', '1');
T::contains('the pixel goes just before </body>', 't=o', TrackingService::injectOpenPixel('<html><body>x</body></html>'));

/* ======================================================== opens === */

ch247m_boot();
ch247m_as_super_admin();

T::section('Opens');
$campaign = ch247m_delivered(3);
$recipients = Db::all('campaign_recipients', ['campaign_id' => (int) $campaign['id']], 'id ASC');
$first = $recipients[0];

T::ok('nobody has opened yet', empty($first['first_opened_at']));
T::ok('an open is recorded', TrackingService::recordOpen($first['token'], ['ip' => '1.2.3.4', 'ua' => 'Mozilla/5.0 (iPhone)']));
$row = Db::first('campaign_recipients', ['id' => (int) $first['id']]);
T::ok('first open timestamped', !empty($row['first_opened_at']));
T::eq('open counted', 1, (int) $row['open_count']);

ch247m_advance(60);
TrackingService::recordOpen($first['token'], ['ua' => 'Mozilla/5.0 (iPhone)']);
$row = Db::first('campaign_recipients', ['id' => (int) $first['id']]);
T::eq('repeat opens are counted', 2, (int) $row['open_count']);
T::eq('but the first-open time does not move', $first['first_opened_at'] ?: $row['first_opened_at'], $row['first_opened_at']);
T::eq('both opens are in the event log', 2, Db::count('email_events', ['event' => 'open']));

T::ok('an unknown token is ignored', !TrackingService::recordOpen('not-a-real-token', []));
T::eq('and logs nothing', 2, Db::count('email_events', ['event' => 'open']));

T::section('Bots do not count as readers');
T::ok('a scanner user-agent is recognised', TrackingService::looksAutomated('GoogleImageProxy'));
T::ok('and so is a bare bot', TrackingService::looksAutomated('Mozilla/5.0 (compatible; bingbot/2.0)'));
T::ok('a real client is not', !TrackingService::looksAutomated('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'));
T::ok('and neither is an empty UA', !TrackingService::looksAutomated(''));

// The filtering happens at the HTTP edge (TrackingEndpoint), so the service
// still records whatever it is handed. Prove the edge actually filters.
$second = $recipients[1];
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$_GET = ['t' => 'o', 'r' => $second['token']];
ob_start();
(new \Ch247Mkt\Http\TrackingEndpoint())->handle();
$body = ob_get_clean();
T::eq('the pixel is still served to the scanner', strlen(TrackingService::pixelBytes()), strlen($body));
T::contains('and it really is a GIF', 'GIF89a', $body);
$row = Db::first('campaign_recipients', ['id' => (int) $second['id']]);
T::eq('but the scanner open is not counted', 0, (int) $row['open_count']);
T::ok('and the first-open time stays empty', empty($row['first_opened_at']));

$third = $recipients[2];
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';
$_GET = ['t' => 'o', 'r' => $third['token']];
ob_start();
(new \Ch247Mkt\Http\TrackingEndpoint())->handle();
ob_end_clean();
T::eq('a human open is counted', 1, (int) Db::first('campaign_recipients', ['id' => (int) $third['id']])['open_count']);

$_GET = ['t' => 'o', 'r' => 'totally-made-up-token'];
ob_start();
(new \Ch247Mkt\Http\TrackingEndpoint())->handle();
$body = ob_get_clean();
T::eq('an invalid token still gets a pixel, never an error', strlen(TrackingService::pixelBytes()), strlen($body));

$_GET = [];
$_SERVER['HTTP_USER_AGENT'] = '';

/* ======================================================= clicks === */

ch247m_boot();
ch247m_as_super_admin();

T::section('Clicks');
$campaign = ch247m_delivered(3);
$recipients = Db::all('campaign_recipients', ['campaign_id' => (int) $campaign['id']], 'id ASC');
$first = $recipients[0];
$link = Db::first('links', ['campaign_id' => (int) $campaign['id']]);

$destination = TrackingService::recordClick($link['token'], $first['token'], ['ip' => '1.2.3.4', 'ua' => 'Mozilla/5.0']);
T::eq('the click resolves to the real destination', 'https://cloudhost247.test/plans', $destination);
$row = Db::first('campaign_recipients', ['id' => (int) $first['id']]);
T::eq('click counted', 1, (int) $row['click_count']);
T::ok('first click timestamped', !empty($row['first_clicked_at']));
T::ok('a click implies an open', !empty($row['first_opened_at']));

$linkRow = Db::first('links', ['id' => (int) $link['id']]);
T::eq('the link counter moved', 1, (int) $linkRow['click_count']);
T::eq('unique clicks counted once per person', 1, (int) $linkRow['unique_click_count']);

TrackingService::recordClick($link['token'], $first['token'], ['ua' => 'Mozilla/5.0']);
$linkRow = Db::first('links', ['id' => (int) $link['id']]);
T::eq('a second click from the same person raises total clicks', 2, (int) $linkRow['click_count']);
T::eq('but not unique clicks', 1, (int) $linkRow['unique_click_count']);

TrackingService::recordClick($link['token'], $recipients[1]['token'], ['ua' => 'Mozilla/5.0']);
$linkRow = Db::first('links', ['id' => (int) $link['id']]);
T::eq('a different person raises unique clicks', 2, (int) $linkRow['unique_click_count']);

T::eq('an unknown link token resolves to nothing', null, TrackingService::recordClick('nope', $first['token'], []));
T::eq('an unknown recipient still gets redirected', 'https://cloudhost247.test/plans', TrackingService::linkUrl($link['token']));
T::eq('resolving a link does not record a click', 2, (int) Db::first('links', ['id' => (int) $link['id']])['unique_click_count']);

T::section('The redirector only ever sends people to the registered URL');
Db::update('links', ['id' => (int) $link['id']], ['url' => 'javascript:alert(1)']);
T::eq('a dangerous stored URL is refused', null, TrackingService::linkUrl($link['token']));

T::section('Click map');
ch247m_boot();
ch247m_as_super_admin();
$design = ch247m_design();
$design['rows'][] = [
    'layout' => 'col-1',
    'cells'  => [[
        ['type' => 'button', 'props' => ['text' => 'Renew now', 'href' => 'https://cloudhost247.test/renew']],
        ['type' => 'text', 'props' => ['html' => '<p><a href="https://cloudhost247.test/support">Support</a></p>']],
    ]],
];
$campaign = ch247m_delivered(4, $design);
$recipients = Db::all('campaign_recipients', ['campaign_id' => (int) $campaign['id']], 'id ASC');
$links = Db::all('links', ['campaign_id' => (int) $campaign['id']], 'position ASC');
T::eq('three links were registered', 3, count($links));

// Everyone clicks the first link, one person clicks the second.
foreach ($recipients as $r) {
    TrackingService::recordClick($links[0]['token'], $r['token'], ['ua' => 'Mozilla/5.0']);
}
TrackingService::recordClick($links[1]['token'], $recipients[0]['token'], ['ua' => 'Mozilla/5.0']);

$map = AnalyticsService::clickMap((int) $campaign['id']);
T::eq('the map covers every link', 3, count($map));
T::eq('the most-clicked link is first', 4, $map[0]['unique_click_count']);
T::eq('and knows its destination', 'https://cloudhost247.test/plans', $map[0]['url']);
T::eq('shares are percentages of unique clicks', 80.0, $map[0]['share']);
T::eq('the runner-up is second', 1, $map[1]['unique_click_count']);
T::eq('an unclicked link still appears', 0, $map[2]['unique_click_count']);

/* ==================================================== analytics === */

ch247m_boot();
ch247m_as_super_admin();

T::section('Campaign statistics');
$campaign = ch247m_delivered(10);
$recipients = Db::all('campaign_recipients', ['campaign_id' => (int) $campaign['id']], 'id ASC');
$link = Db::first('links', ['campaign_id' => (int) $campaign['id']]);

$stats = AnalyticsService::campaignStats((int) $campaign['id']);
T::eq('ten recipients', 10, $stats['recipients']);
T::eq('ten sent', 10, $stats['sent']);
T::eq('ten delivered', 10, $stats['delivered']);
T::eq('delivery rate is a percentage', 100.0, $stats['delivery_rate']);
T::eq('no opens yet', 0, $stats['unique_opens']);
T::eq('open rate is zero, not a division error', 0.0, $stats['open_rate']);

for ($i = 0; $i < 5; $i++) {
    TrackingService::recordOpen($recipients[$i]['token'], ['ua' => 'Mozilla/5.0']);
}
TrackingService::recordOpen($recipients[0]['token'], ['ua' => 'Mozilla/5.0']); // a re-open
for ($i = 0; $i < 2; $i++) {
    TrackingService::recordClick($link['token'], $recipients[$i]['token'], ['ua' => 'Mozilla/5.0']);
}
TrackingService::recordUnsubscribe($recipients[9]['token'], []);
TrackingService::recordComplaint($recipients[8]['token'], []);

$stats = AnalyticsService::campaignStats((int) $campaign['id']);
T::eq('five unique opens', 5, $stats['unique_opens']);
T::eq('six total opens', 6, $stats['total_opens']);
T::eq('open rate', 50.0, $stats['open_rate']);
T::eq('two unique clicks', 2, $stats['unique_clicks']);
T::eq('click rate', 20.0, $stats['click_rate']);
T::eq('click-to-open rate', 40.0, $stats['click_to_open_rate']);
T::eq('one unsubscribe', 1, $stats['unsubscribed']);
T::eq('unsubscribe rate', 10.0, $stats['unsubscribe_rate']);
T::eq('one complaint', 1, $stats['complained']);
T::eq('complaint rate', 10.0, $stats['complaint_rate']);

T::section('Rates never divide by zero');
$empty = AnalyticsService::campaignStats(99999);
foreach (['delivery_rate', 'open_rate', 'click_rate', 'click_to_open_rate', 'bounce_rate', 'unsubscribe_rate', 'complaint_rate'] as $key) {
    T::eq($key . ' on an empty campaign is 0', 0.0, $empty[$key]);
}

T::section('Per-recipient report');
$all = AnalyticsService::recipients((int) $campaign['id'], 'all', 1, 50);
T::eq('every recipient is listed', 10, count($all['rows']));
T::eq('with a total for paging', 10, $all['total']);
T::ok('each row carries the address', isset($all['rows'][0]['email']));
T::eq('filter: opened', 5, count(AnalyticsService::recipients((int) $campaign['id'], 'opened', 1, 50)['rows']));
T::eq('filter: clicked', 2, count(AnalyticsService::recipients((int) $campaign['id'], 'clicked', 1, 50)['rows']));
T::eq('filter: not opened', 5, count(AnalyticsService::recipients((int) $campaign['id'], 'not_opened', 1, 50)['rows']));
T::eq('filter: unsubscribed', 1, count(AnalyticsService::recipients((int) $campaign['id'], 'unsubscribed', 1, 50)['rows']));
T::eq('paging works', 4, count(AnalyticsService::recipients((int) $campaign['id'], 'all', 1, 4)['rows']));
T::eq('and the second page continues', 4, count(AnalyticsService::recipients((int) $campaign['id'], 'all', 2, 4)['rows']));
T::eq('the last page is short', 2, count(AnalyticsService::recipients((int) $campaign['id'], 'all', 3, 4)['rows']));

T::section('Timeline and event feed');
$timeline = AnalyticsService::timeline((int) $campaign['id'], 14);
T::ok('a timeline is produced', count($timeline) > 0);
$events = AnalyticsService::events((int) $campaign['id'], 100);
T::ok('the raw event feed is available', count($events) > 0);
T::ok('newest first', $events[0]['id'] >= $events[count($events) - 1]['id']);

T::section('Dashboard');
$dashboard = AnalyticsService::dashboard();
T::eq('counts subscribers', 10, $dashboard['subscribers']['total']);
T::eq('broken down by status', 8, $dashboard['subscribers']['subscribed']);
T::ok('counts lists', $dashboard['lists'] >= 1);
T::eq('counts campaigns by state', 1, $dashboard['campaigns'][CampaignService::STATUS_SENT]);
T::eq('reports an overall open rate', 50.0, $dashboard['performance']['open_rate']);
T::eq('and overall delivery', 10, $dashboard['performance']['delivered']);
T::ok('queue health is on the dashboard', isset($dashboard['queue']));
T::ok('suppressions are on the dashboard', isset($dashboard['suppressions']));
$recent = AnalyticsService::recentActivity(5);
T::ok('recent activity is available', is_array($recent) && count($recent) >= 1);
T::ok('and reads like campaigns', isset($recent[0]['name']));

T::section('Event retention');
T::eq('nothing purged inside the window', 0, AnalyticsService::purgeEvents(30));
$before = Db::count('email_events');
T::ok('there are events to purge', $before > 0);
ch247m_advance(60 * 86400);
T::eq('old events are purged', $before, AnalyticsService::purgeEvents(30));
T::eq('the ledger survives the purge', 10, Db::count('campaign_recipients', ['campaign_id' => (int) $campaign['id']]));
$stats = AnalyticsService::campaignStats((int) $campaign['id']);
T::eq('so the campaign report still reads 5 opens', 5, $stats['unique_opens']);
T::eq('and still reads 2 clicks', 2, $stats['unique_clicks']);

T::section('Web view');
ch247m_boot();
ch247m_as_super_admin();
$campaign = ch247m_delivered(1);
$recipient = Db::first('campaign_recipients', ['campaign_id' => (int) $campaign['id']]);
$view = CampaignService::webview((int) $campaign['id'], $recipient);
T::ok('a sent campaign has a web view', $view !== null);
T::notContains('the web view is not tracked', 't=o', (string) $view);
T::contains('merge tags are resolved in it', 'Hello Person1', (string) $view);
$draft = ch247m_campaign([]);
T::eq('a draft has no public web view', null, CampaignService::webview((int) $draft['id'], $recipient));

T::finish();
