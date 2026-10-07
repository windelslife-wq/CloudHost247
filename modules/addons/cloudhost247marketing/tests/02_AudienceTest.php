<?php
/** Subscribers, consent, lists, CSV import and WHMCS-backed segments. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Mkt\Audience\ImportService;
use Ch247Mkt\Audience\ListService;
use Ch247Mkt\Audience\SegmentService;
use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\ValidationException;
use Ch247Mkt\Delivery\ComplianceService;

ch247m_boot();
ch247m_as_super_admin();

/* ====================================================== subscribers == */

T::section('Subscriber creation');
$sub = ch247m_subscriber('Ada@Example.Test', ['first_name' => 'Ada', 'last_name' => 'Obi', 'client_id' => 11]);
T::eq('email is normalised', 'ada@example.test', $sub['email']);
T::eq('starts subscribed', SubscriberService::STATUS_SUBSCRIBED, $sub['status']);
T::ok('consent timestamp recorded', !empty($sub['consent_at']));
T::eq('consent source recorded', 'unit test', $sub['consent_source']);

$again = ch247m_subscriber('ada@example.test', ['first_name' => 'Adaeze']);
T::eq('upsert does not duplicate', 1, Db::count('subscribers'));
T::eq('upsert updates fields', 'Adaeze', $again['first_name']);

T::throws('invalid address rejected', function () {
    ch247m_subscriber('not-an-email');
}, ValidationException::class);
T::throws('header injection rejected', function () {
    ch247m_subscriber("a@b.test\nBcc: evil@x.test");
}, ValidationException::class);

T::section('Unsubscribe is final');
SubscriberService::unsubscribe((int) $sub['id'], ['source' => 'link']);
$after = SubscriberService::find((int) $sub['id']);
T::eq('status flips to unsubscribed', SubscriberService::STATUS_UNSUBSCRIBED, $after['status']);
T::ok('suppression entry written', ComplianceService::isSuppressed('ada@example.test'));

// The critical one: an import must never bring an opt-out back to life.
$resurrect = ch247m_subscriber('ada@example.test', ['first_name' => 'Ada']);
T::eq('upsert cannot resurrect an unsubscribed record', SubscriberService::STATUS_UNSUBSCRIBED, $resurrect['status']);

T::throws('re-subscribe without a consent source is refused', function () use ($sub) {
    SubscriberService::resubscribe((int) $sub['id'], []);
}, ValidationException::class);

SubscriberService::resubscribe((int) $sub['id'], ['consent_source' => 'signed renewal form 2026-10-06']);
$revived = SubscriberService::find((int) $sub['id']);
T::eq('explicit re-subscribe works', SubscriberService::STATUS_SUBSCRIBED, $revived['status']);
T::ok('suppression lifted with it', !ComplianceService::isSuppressed('ada@example.test'));

T::section('Bounce handling');
$bouncer = ch247m_subscriber('bounce@example.test');
SubscriberService::recordBounce((int) $bouncer['id'], 'hard', ['reason' => '550 mailbox unavailable']);
$bounced = SubscriberService::find((int) $bouncer['id']);
T::eq('one hard bounce suppresses', SubscriberService::STATUS_BOUNCED, $bounced['status']);
T::ok('hard bounce reaches the suppression list', ComplianceService::isSuppressed('bounce@example.test'));

$soft = ch247m_subscriber('soft@example.test');
for ($i = 0; $i < 4; $i++) {
    SubscriberService::recordBounce((int) $soft['id'], 'soft');
}
T::eq('four soft bounces are tolerated', SubscriberService::STATUS_SUBSCRIBED, SubscriberService::find((int) $soft['id'])['status']);
SubscriberService::recordBounce((int) $soft['id'], 'soft');
T::eq('the fifth soft bounce suppresses', SubscriberService::STATUS_BOUNCED, SubscriberService::find((int) $soft['id'])['status']);

T::section('GDPR erasure');
$forget = ch247m_subscriber('forget@example.test');
SubscriberService::unsubscribe((int) $forget['id'], ['source' => 'request']);
SubscriberService::delete((int) $forget['id'], ['actor_id' => 1]);
T::eq('subscriber row is gone', 0, Db::count('subscribers', ['email' => 'forget@example.test']));
T::ok('suppression survives erasure', ComplianceService::isSuppressed('forget@example.test'));

/* ============================================================ lists == */

ch247m_boot();
ch247m_as_super_admin();

T::section('Lists');
$news = ListService::create(['name' => 'Newsletter', 'description' => 'Monthly news'], 1);
$promo = ListService::create(['name' => 'Promotions'], 1);
T::ok('slug generated', $news['slug'] === 'newsletter');
T::throws('duplicate name is rejected or re-slugged', function () {
    ListService::create(['name' => ''], 1);
}, ValidationException::class);

$a = ch247m_subscriber('a@example.test');
$b = ch247m_subscriber('b@example.test');
ListService::addMember((int) $news['id'], (int) $a['id']);
ListService::addMember((int) $news['id'], (int) $b['id']);
ListService::addMember((int) $news['id'], (int) $b['id']); // repeat
T::eq('membership is idempotent', 2, Db::count('list_members', ['list_id' => (int) $news['id']]));
ListService::recount((int) $news['id']);
T::eq('cached count matches', 2, (int) ListService::find((int) $news['id'])['subscriber_count']);

ListService::removeMember((int) $news['id'], (int) $b['id']);
ListService::recount((int) $news['id']);
T::eq('removal updates the count', 1, (int) ListService::find((int) $news['id'])['subscriber_count']);

SubscriberService::unsubscribe((int) $a['id'], ['source' => 'link']);
ListService::recountAll();
T::eq('unsubscribed members are not counted as mailable', 0, (int) ListService::find((int) $news['id'])['subscriber_count']);

/* =========================================================== import == */

ch247m_boot();
ch247m_as_super_admin();

T::section('CSV import');
$list = ListService::create(['name' => 'Imported'], 1);
$csv = "Email Address,First Name,Last Name,Company\n"
    . "one@example.test,One,Person,Acme\n"
    . "TWO@EXAMPLE.TEST,Two,Person,\n"
    . "not-an-email,Bad,Row,\n"
    . "one@example.test,One,Duplicate,\n"
    . "three@example.test,Three,Person,Globex\n";
$path = tempnam(sys_get_temp_dir(), 'ch247m');
file_put_contents($path, $csv);

$parsed = ImportService::parse($path);
T::eq('header row detected', 4, count($parsed['headers']));
T::eq('data rows counted', 5, count($parsed['rows']));

$mapping = ImportService::suggestMapping($parsed['headers']);
T::eq('email column auto-detected', 'email', $mapping[0]);
T::eq('first name auto-detected', 'first_name', $mapping[1]);

$dry = ImportService::import($parsed['rows'], $mapping, [
    'lists' => [(int) $list['id']], 'consent_source' => 'webinar signup', 'dry_run' => true,
]);
T::eq('dry run reports the valid rows', 3, $dry['imported']);
T::eq('dry run writes nothing', 0, Db::count('subscribers'));

$real = ImportService::import($parsed['rows'], $mapping, [
    'lists' => [(int) $list['id']], 'consent_source' => 'webinar signup',
]);
T::eq('three unique addresses imported', 3, $real['imported']);
T::eq('one invalid row reported', 1, $real['invalid']);
T::eq('one duplicate row skipped', 1, $real['skipped']);
T::eq('subscriber count matches', 3, Db::count('subscribers'));
T::eq('case-folded address stored once', 1, Db::count('subscribers', ['email' => 'two@example.test']));

T::throws('import without a consent source is refused', function () use ($parsed, $mapping) {
    ImportService::import($parsed['rows'], $mapping, ['consent_source' => '']);
}, ValidationException::class);

ComplianceService::suppress('four@example.test', 'unsubscribe', ['source' => 'earlier campaign']);
$second = ImportService::import([['four@example.test', 'Four', 'Person', '']], $mapping, [
    'consent_source' => 'webinar signup',
]);
T::eq('suppressed address is skipped on import', 1, $second['suppressed']);
T::eq('and not created', 0, Db::count('subscribers', ['email' => 'four@example.test']));

$export = ImportService::exportCsv([]);
T::contains('export contains a header', 'email', $export);
T::contains('export contains a subscriber', 'three@example.test', $export);
T::contains('export records consent', 'webinar signup', $export);
unlink($path);

/* ========================================================= segments == */

ch247m_boot();
ch247m_as_super_admin();

T::section('Subscriber segments');
ch247m_subscriber('ng1@example.test', ['company' => 'Obi Media', 'tags' => 'vip']);
ch247m_subscriber('ng2@example.test', ['company' => '']);
$seg = SegmentService::create([
    'name' => 'Has a company',
    'source' => SegmentService::SOURCE_SUBSCRIBERS,
    'definition' => ['match' => 'all', 'rules' => [['field' => 'company', 'op' => 'is_set', 'value' => '']]],
], 1);
T::eq('segment resolves', 1, SegmentService::count($seg));

$tagSeg = SegmentService::create([
    'name' => 'VIPs',
    'source' => SegmentService::SOURCE_SUBSCRIBERS,
    'definition' => ['match' => 'all', 'rules' => [['field' => 'tag', 'op' => 'contains', 'value' => 'vip']]],
], 1);
T::eq('tag segment resolves', 1, SegmentService::count($tagSeg));

T::throws('unknown field is rejected', function () {
    SegmentService::create([
        'name' => 'Injection attempt',
        'source' => SegmentService::SOURCE_SUBSCRIBERS,
        'definition' => ['match' => 'all', 'rules' => [['field' => "s.id); DROP TABLE x;--", 'op' => 'is', 'value' => '1']]],
    ], 1);
}, ValidationException::class);

T::section('WHMCS-backed segments');
$nigeria = SegmentService::create([
    'name' => 'Active Web Hosting Customers — Nigeria',
    'source' => SegmentService::SOURCE_WHMCS,
    'definition' => ['match' => 'all', 'rules' => [
        ['field' => 'client_status', 'op' => 'is', 'value' => 'Active'],
        ['field' => 'country', 'op' => 'is', 'value' => 'NG'],
        ['field' => 'product_group', 'op' => 'is', 'value' => '1'],
    ]],
], 1);
$resolved = SegmentService::resolve($nigeria);
T::eq('matches exactly the Nigerian active hosting client', 1, count($resolved));
T::eq('and it is client 11', 11, (int) $resolved[0]['client_id']);
T::eq('WHMCS rows carry no subscriber id yet', 0, (int) $resolved[0]['subscriber_id']);

$swiss = SegmentService::create([
    'name' => 'Swiss VPS customers',
    'source' => SegmentService::SOURCE_WHMCS,
    'definition' => ['match' => 'all', 'rules' => [
        ['field' => 'country', 'op' => 'is', 'value' => 'CH'],
        ['field' => 'product', 'op' => 'is', 'value' => '2'],
    ]],
], 1);
T::eq('product rule works', 1, SegmentService::count($swiss));

$unpaid = SegmentService::create([
    'name' => 'Clients with an unpaid invoice',
    'source' => SegmentService::SOURCE_WHMCS,
    'definition' => ['match' => 'all', 'rules' => [['field' => 'unpaid_invoice', 'op' => 'is', 'value' => '1']]],
], 1);
T::eq('unpaid-invoice subquery works', 1, SegmentService::count($unpaid));

$anyCountry = SegmentService::create([
    'name' => 'NG or CH',
    'source' => SegmentService::SOURCE_WHMCS,
    'definition' => ['match' => 'any', 'rules' => [
        ['field' => 'country', 'op' => 'is', 'value' => 'NG'],
        ['field' => 'country', 'op' => 'is', 'value' => 'CH'],
    ]],
], 1);
T::ok('OR matching returns more than AND would', SegmentService::count($anyCountry) >= 3);

$count = SegmentService::refreshCount((int) $nigeria['id']);
T::eq('refreshCount caches the number', $count, (int) SegmentService::find((int) $nigeria['id'])['cached_count']);
T::ok('refreshAll touches every segment', SegmentService::refreshAll() >= 5);

T::section('Client lifecycle sync');
$linked = ch247m_subscriber('ada@example.test', ['client_id' => 11]);
SubscriberService::syncFromClient(11, ['email' => 'ada.new@example.test', 'first_name' => 'Ada', 'last_name' => 'Obi-Nwosu']);
$synced = SubscriberService::find((int) $linked['id']);
T::eq('email change follows the client', 'ada.new@example.test', $synced['email']);
T::eq('name change follows the client', 'Obi-Nwosu', $synced['last_name']);

SubscriberService::optOutClient(11, 'client area');
T::eq('client-area opt-out unsubscribes', SubscriberService::STATUS_UNSUBSCRIBED, SubscriberService::find((int) $linked['id'])['status']);

SubscriberService::forgetClient(11);
T::eq('forgetting a client erases the row', 0, Db::count('subscribers', ['client_id' => 11]));
T::ok('but keeps the suppression', ComplianceService::isSuppressed('ada.new@example.test'));

T::finish();
