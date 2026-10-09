<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\ValidationException;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

$gateway = chs_boot();
chs_freeze();

function chs_jobs_reset()
{
    Db::exec('DELETE FROM ' . Db::t('jobs'));
}

T::section('JobQueue: enqueue + claim + complete');
$queue = new JobQueue();
$job = $queue->enqueue(DomainJobTypes::NOTIFICATION, ['client_id' => 11, 'subject' => 'hi'], [
    'correlation_id' => 'corr-1',
    'entity_type'    => 'invoice',
    'entity_id'      => 42,
]);
T::ok('job row stored', (int) $job['id'] > 0);
T::eq('status pending', JobQueue::STATUS_PENDING, $job['status']);
T::eq('correlation id kept', 'corr-1', $job['correlation_id']);
T::eq('entity tracked', 42, (int) $job['entity_id']);
T::eq('payload persisted', 'hi', json_decode($job['payload'], true)['subject']);

$claimed = $queue->claim(300);
T::eq('claimed job is ours', (int) $job['id'], (int) $claimed['id']);
T::eq('claim increments attempts', 1, (int) $claimed['attempts']);
T::eq('second claim finds nothing', null, $queue->claim(300));
$queue->complete($claimed['id']);
T::eq('completed', JobQueue::STATUS_COMPLETED, Db::first('jobs', ['id' => (int) $job['id']])['status']);

T::section('JobQueue: idempotent enqueue');
$a = $queue->enqueue(DomainJobTypes::NOTIFICATION, ['n' => 1], ['idempotency_key' => 'idem-1']);
$b = $queue->enqueue(DomainJobTypes::NOTIFICATION, ['n' => 2], ['idempotency_key' => 'idem-1']);
T::eq('same job returned', (int) $a['id'], (int) $b['id']);
T::eq('only one row', 1, Db::count('jobs', ['idempotency_key' => 'idem-1']));
$found = $queue->findByIdempotencyKey('idem-1');
T::eq('find by key', (int) $a['id'], (int) $found['id']);

T::section('JobQueue: unknown type rejected');
T::throws('unknown job type rejected', function () use ($queue) {
    $queue->enqueue('NOT_A_JOB', []);
}, ValidationException::class);

T::section('JobQueue: failure → retry with backoff → terminal failed');
chs_jobs_reset();
$job2 = $queue->enqueue(DomainJobTypes::NOTIFICATION, [], ['max_attempts' => 2]);
$claimed2 = $queue->claim(300);
T::ok('claimed the only pending job', $claimed2 !== null && (int) $claimed2['id'] === (int) $job2['id']);
$queue->fail($claimed2['id'], 'test_error', 'boom');
$afterFirst = Db::first('jobs', ['id' => (int) $job2['id']]);
T::eq('back to pending after first failure', JobQueue::STATUS_PENDING, $afterFirst['status']);
T::eq('error code recorded', 'test_error', $afterFirst['error_code']);
T::ok('backoff scheduled in future', Clock::toTime($afterFirst['available_at']) > Clock::time());
T::eq('not claimable during backoff', null, $queue->claim(300));
// force available now, claim again, fail again → terminal
Db::update('jobs', ['id' => (int) $job2['id']], ['available_at' => Clock::now()]);
$claimed4 = $queue->claim(300);
T::ok('claimable after backoff', $claimed4 !== null && (int) $claimed4['id'] === (int) $job2['id']);
$queue->fail($claimed4['id'], 'test_error', 'boom again');
T::eq('terminal failed at max attempts', JobQueue::STATUS_FAILED, Db::first('jobs', ['id' => (int) $job2['id']])['status']);

T::section('JobQueue: admin retry revives a failed job');
$revived = $queue->retry((int) $job2['id']);
T::eq('retry → pending', JobQueue::STATUS_PENDING, $revived['status']);
T::ok('retry clears the error', $revived['error_code'] === '');
$done = $queue->enqueue(DomainJobTypes::NOTIFICATION, ['client_id' => 11]);
$queue->complete((int) $done['id']);
T::throws('completed job cannot be retried', function () use ($queue, $done) {
    $queue->retry((int) $done['id']);
}, \Chs\Core\DuplicateOperationException::class);

T::section('JobQueue: run() dispatches handlers and records failures');
chs_jobs_reset();
$queue2 = new JobQueue();
$queue2->enqueue(DomainJobTypes::NOTIFICATION, ['client_id' => 11, 'type' => 't', 'subject' => 's', 'body' => 'b']);
$queue2->enqueue(DomainJobTypes::NOTIFICATION, ['client_id' => 0]); // notify() ignores client 0 — still completes
$ran = $queue2->run([
    DomainJobTypes::NOTIFICATION => function (array $job) {
        (new \Chs\Services\NotificationService())->notify(
            (int) $job['payload']['client_id'],
            isset($job['payload']['type']) ? $job['payload']['type'] : 'domain',
            isset($job['payload']['subject']) ? $job['payload']['subject'] : '',
            isset($job['payload']['body']) ? $job['payload']['body'] : ''
        );
    },
], 10, 300);
T::eq('two jobs ran', 2, $ran['ran']);
T::eq('two completed', 2, $ran['completed']);
T::eq('notification stored', 1, Db::count('notifications', ['client_id' => 11, 'subject' => 's']));

$queue2->enqueue(DomainJobTypes::RENEWAL, ['renewal_id' => 999999]); // does not exist → handler throws
$ran2 = $queue2->run(\Chs\Workflow\DomainWorker::handlers(), 10, 300);
T::eq('failing job counted', 1, $ran2['failed']);
$failedRow = Db::first('jobs', ['type' => DomainJobTypes::RENEWAL]);
T::eq('failure status pending (retryable)', JobQueue::STATUS_PENDING, $failedRow['status']);
T::eq('not_found machine code', 'not_found', $failedRow['error_code']);

T::section('JobQueue: lease expiry makes a stuck job claimable');
$job3 = $queue2->enqueue(DomainJobTypes::NOTIFICATION, ['client_id' => 11]);
$queue2->claim(30);
T::eq('locked job not claimable', null, $queue2->claim(30));
Db::update('jobs', ['id' => (int) $job3['id']], ['locked_until' => Clock::ago(1)]);
$reclaimed = $queue2->claim(30);
T::ok('expired lease reclaimed', $reclaimed !== null && (int) $reclaimed['id'] === (int) $job3['id']);
T::eq('attempts counted', 2, (int) $reclaimed['attempts']);

T::section('JobQueue: list + stats + purge');
$list = $queue2->list(['status' => 'pending']);
T::ok('list filters by status', $list['total'] >= 1);
T::ok('all listed rows pending', (function () use ($list) {
    foreach ($list['rows'] as $row) {
        if ($row['status'] !== 'pending') {
            return false;
        }
    }
    return true;
})());
$stats = $queue2->stats();
T::ok('stats counts', $stats['pending'] + $stats['completed'] + $stats['failed'] + $stats['running'] >= 4);
$queue2->purgeOlderThan(30);
T::ok('purge keeps recent rows', Db::count('jobs') >= 4);

T::finish();
