<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeDomainProvider.php';

use Chs\Core\Db;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\BulkSearchService;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_seed_tld_fixtures();
chs_freeze();
// The suite submits many searches; keep the default guest quota from
// interfering (the rate-limit behaviour itself is tested at the bottom).
Settings::put('bulk_daily_limit_per_ip', '50');

T::section('Bulk: list input parsing, dedupe, ordering');
$queue = new JobQueue();
$svc = new BulkSearchService($queue);
$list = $svc->expand("Bravo.com\nalpha.com, bravo.com\n  gamma.io  \nbad..name\n!!!\n");
T::ok('domains parsed + deduped + sorted', $list === ['alpha.com', 'bravo.com', 'gamma.io']);

T::section('Bulk: keyword expansion across the catalogue');
$list2 = $svc->expand('windels');
$tlds = array_map(function ($d) {
    return substr($d, strpos($d, '.') + 1);
}, $list2);
T::ok('keyword expanded to every catalogue TLD', count($list2) === 4 && in_array('com', $tlds, true) && in_array('co.uk', $tlds, true));
T::ok('keyword itself became a domain', in_array('windels.com', $list2, true));

T::section('Bulk: cap enforced');
Settings::put('bulk_max_domains', '3');
T::throws('over-cap expansion rejected', function () use ($svc) {
    $svc->expand('one two three four five');
}, ValidationException::class);
Settings::put('bulk_max_domains', '500');

T::section('Bulk: empty input rejected');
T::throws('empty input rejected', function () use ($svc) {
    $svc->submit('   ');
}, ValidationException::class);

T::section('Bulk: small list processed inline with live results');
$registry = new FakeProviderRegistry();
$registry->fake->availability['shopme.com'] = ['available' => true, 'status' => 'available'];
$registry->fake->availability['shopme.net'] = ['available' => false, 'status' => 'taken'];
$searchSvc = new \Chs\Services\DomainSearchService($registry);
$svc2 = new BulkSearchService(new JobQueue(), $searchSvc);
$outcome = $svc2->submit("shopme.com\nshopme.net\n");
T::ok('small list inline', $outcome['inline'] === true);
T::eq('search completed', 'completed', $outcome['search']['status']);
T::eq('two names', 2, (int) $outcome['search']['total']);
T::ok('no job queued for inline run', Db::count('jobs', ['type' => DomainJobTypes::BULK_SEARCH]) === 0);
$res = $svc2->results((int) $outcome['search']['id']);
T::eq('two result rows', 2, $res['total']);
T::eq('one available', 1, $res['available']);
$byDomain = [];
foreach ($res['rows'] as $row) {
    $byDomain[$row['domain']] = $row;
}
T::eq('available flag persisted', 1, (int) $byDomain['shopme.com']['available']);
T::eq('taken flag persisted', 0, (int) $byDomain['shopme.net']['available']);
T::eq('server-side price on row', 1299, (int) $byDomain['shopme.com']['register_minor']);

T::section('Bulk: large list goes through the worker job');
Settings::put('bulk_sync_threshold', '2');
$outcome2 = $svc2->submit("w1.com\nw2.com\nw3.com\n");
T::ok('large list queued', $outcome2['inline'] === false);
T::eq('search queued', 'queued', $outcome2['search']['status']);
$jobs = Db::all('jobs', ['type' => DomainJobTypes::BULK_SEARCH]);
T::eq('one bulk job enqueued', 1, count($jobs));
T::eq('job pending', 'pending', $jobs[0]['status']);
T::eq('job payload carries search id', (int) $outcome2['search']['id'], json_decode($jobs[0]['payload'], true)['search_id']);

// Run the worker: the job processes the pending rows.
$run = \Chs\Workflow\DomainWorker::run(5);
T::ok('worker ran the job', $run['ran'] >= 1);
T::eq('job completed', 'completed', Db::first('jobs', ['id' => (int) $jobs[0]['id']])['status']);
$search2 = $svc2->getSearch((int) $outcome2['search']['id']);
T::eq('search completed by worker', 'completed', $search2['status']);
T::eq('all names checked', 3, (int) $search2['completed']);
$res2 = $svc2->results((int) $outcome2['search']['id']);
T::eq('worker rows have prices', 1299, (int) $res2['rows'][0]['register_minor']);
Settings::put('bulk_sync_threshold', '10');

T::section('Bulk: pagination + available-only filter');
$outcome3 = $svc2->submit("p1.com\np2.com\np3.com\np4.com\n"); // inline (4 <= 10)
$res3 = $svc2->results((int) $outcome3['search']['id'], 1, 2);
T::eq('page size honoured', 2, count($res3['rows']));
T::eq('total counts all', 4, $res3['total']);
$res4 = $svc2->results((int) $outcome3['search']['id'], 1, 2, true);
T::eq('available-only total', 4, $res4['total']); // fake provider defaults to available
$res5 = $svc2->results((int) $outcome3['search']['id'], 2, 2);
T::eq('second page has rows', 2, count($res5['rows']));

T::section('Bulk: CSV export');
$csv = $svc2->exportCsv((int) $outcome3['search']['id']);
T::ok('csv header', strpos($csv, 'domain,available,status,register,renew,transfer,currency') === 0);
T::ok('csv has rows', substr_count($csv, "\n") === 5);
T::ok('csv prices formatted', strpos($csv, '12.99') !== false);

T::section('Bulk: ownership enforced (IDOR)');
$otherSearch = $svc2->submit("secret-list.com\n", 2);
T::throws('other client cannot read', function () use ($svc2, $otherSearch) {
    $svc2->getSearchFor((int) $otherSearch['search']['id'], 1);
}, \Chs\Core\NotFoundException::class);
$own = $svc2->getSearchFor((int) $otherSearch['search']['id'], 2);
T::eq('owner can read', (int) $otherSearch['search']['id'], (int) $own['id']);

T::section('Bulk: audit trail');
$audit = Db::all('audit_log', ['action' => 'domain.bulk_search'], 'id ASC');
T::ok('bulk submissions audited', count($audit) >= 4);
$completed = Db::all('audit_log', ['action' => 'domain.bulk_search_completed'], 'id ASC');
T::ok('bulk completions audited', count($completed) >= 1);

T::section('Bulk: rate limiting');
Settings::put('bulk_daily_limit_per_ip', '1');
Db::exec('DELETE FROM ' . Db::t('rate_limits'));
$limited = false;
try {
    $svc2->submit("rl-a.com\n", null);
    $svc2->submit("rl-b.com\n", null);
} catch (\Chs\Core\RateLimitException $e) {
    $limited = true;
}
T::ok('bulk submit rate limited', $limited);
Settings::put('bulk_daily_limit_per_ip', '3');

T::section('Bulk: disabled feature fails closed');
Settings::put('bulk_search_enabled', '0');
T::throws('disabled bulk search throws', function () use ($svc2) {
    $svc2->submit("x.com\n");
}, \Chs\Core\ServiceUnavailableException::class);
Settings::put('bulk_search_enabled', '1');

T::finish();
