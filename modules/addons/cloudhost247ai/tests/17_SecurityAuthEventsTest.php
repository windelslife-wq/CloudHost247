<?php
/** Indexed auth-event collector and safe reader coverage. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Core\Db;
use Ch247Ai\Security\AuthEventCollector;
use Ch247Ai\Tools\ToolExecutor;

ch247ai_boot();
ch247ai_freeze('2026-10-06 12:00:00');
ch247ai_as_super_admin();
$pdo = Db::pdo();
$pdo->exec("INSERT INTO tblactivitylog (id, date, description, user, userid, ipaddr) VALUES
    (1, '2026-10-06 09:00:00', 'Successful Login', 'client', 11, '203.0.113.44'),
    (2, '2026-10-06 10:00:00', 'Failed Login Attempt', 'client', 11, '203.0.113.44'),
    (3, '2026-10-06 11:00:00', 'Client authenticated', 'client', 22, '203.0.113.45'),
    (4, '2026-10-06 11:30:00', 'Payment received', 'system', 0, '203.0.113.45')");

T::section('Classification is conservative and explicit');
T::eq('successful login phrase recognized', 'successful_login', AuthEventCollector::classify('Successful Login from IP Address')); 
T::eq('failed login phrase recognized', 'failed_login', AuthEventCollector::classify('Failed Login Attempt')); 
T::eq('ambiguous authentication description ignored', null, AuthEventCollector::classify('Client authenticated'));
T::eq('unrelated activity ignored', null, AuthEventCollector::classify('Payment received'));

T::section('Incremental collector advances a bounded cursor and pseudonymizes IP');
$GLOBALS['cc_encryption_hash'] = 'test-only-secret-hash';
$first = AuthEventCollector::collect(2);
T::eq('first batch scans two rows', 2, $first['rows_scanned']);
T::eq('two explicit auth events stored', 2, $first['events_added']);
T::eq('cursor advances to source row two', 2, $first['last_activity_id']);
$stored = Db::all('security_auth_events', [], 'source_activity_id ASC');
T::eq('successful then failed event ordering', ['successful_login', 'failed_login'], array_column($stored, 'event_type'));
T::ok('IP stored only as keyed hash', strlen((string) $stored[0]['ip_hash']) === 64 && $stored[0]['ip_hash'] !== '203.0.113.44');
$second = AuthEventCollector::collect(2);
T::eq('ambiguous rows scanned on next batch', 2, $second['rows_scanned']);
T::eq('ambiguous/unrelated activity not emitted', 0, $second['events_added']);
T::eq('cursor advances over ignored rows', 4, $second['last_activity_id']);
$third = AuthEventCollector::collect(2);
T::eq('no rows rescanned after cursor catches up', 0, $third['rows_scanned']);
T::eq('repeated run does not duplicate event rows', 2, Db::count('security_auth_events', []));
unset($GLOBALS['cc_encryption_hash']);

T::section('Tool returns limited evidence without IP hashes or descriptions');
$result = ToolExecutor::execute('admin_copilot', 'read_security_auth_events', ['limit' => 20]);
T::eq('two classified events returned', 2, $result->data['count']);
T::eq('failed/successful event counts grouped', ['failed_login', 'successful_login'], array_column($result->data['last_24h_counts'], 'event_type'));
$json = json_encode($result->data);
T::ok('raw activity description never reaches model', strpos($json, 'Failed Login Attempt') === false);
T::ok('IP hash never reaches model', strpos($json, hash_hmac('sha256', '203.0.113.44', 'test-only-secret-hash')) === false);
T::ok('missing attack inference disclaimer present', strpos($result->data['limitations'], 'not proof of attack') !== false);
T::ok('query citations are present', count($result->citations) === 2);

T::section('Retention prunes old projected rows');
Db::insert('security_auth_events', [
    'source_activity_id' => 999, 'event_type' => 'failed_login', 'actor_id' => 4,
    'ip_hash' => null, 'occurred_at' => '2026-01-01 00:00:00', 'collected_at' => '2026-01-01 00:01:00',
]);
AuthEventCollector::collect(10);
T::eq('event older than retention window removed', 0, Db::count('security_auth_events', ['source_activity_id' => 999]));

T::finish();
