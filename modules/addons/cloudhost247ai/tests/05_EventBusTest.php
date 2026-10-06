<?php
/** Event bus: INSERT-only capture, cron drain into memory, retry/dead-letter, redaction. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Event\EventBus;
use Ch247Ai\Event\Subscribers;
use Ch247Ai\Core\Db;

ch247ai_boot();
ch247ai_freeze();

T::section('Capture is INSERT-only (no model, no agents, no memory rows)');
$id = EventBus::capture('InvoicePaid', ['entity_type' => 'invoice', 'entity_id' => 101, 'client_id' => 11, 'status' => 'Paid']);
T::ok('event row inserted', (int) $id > 0);
$row = Db::first('events', ['id' => $id]);
T::eq('event pending', 'pending', $row['status']);
T::eq('attempts start at zero', 0, (int) $row['attempts']);
T::eq('no memories created by capture', 0, Db::count('memories'));
T::eq('no runs created by capture', 0, Db::count('runs'));

T::section('Unknown hooks are ignored');
T::eq('non-whitelisted hook captures nothing', 0, EventBus::capture('SomeRandomHook', ['x' => 1]));

T::section('Capture redacts secrets from payloads');
EventBus::capture('TicketOpen', ['entity_type' => 'ticket', 'entity_id' => 401, 'client_id' => 11, 'password' => 'hunter2', 'token' => 'tok-abc123']);
$row = Db::first('events', ['event_type' => 'ticket.opened']);
T::ok('secret keys redacted in payload', strpos((string) $row['payload'], 'hunter2') === false);
T::ok('redaction markers present', strpos((string) $row['payload'], '[redacted]') !== false);

T::section('Drain processes events into agent memory (cron only)');
EventBus::capture('InvoiceCreated', ['entity_type' => 'invoice', 'entity_id' => 105, 'client_id' => 22]);
EventBus::capture('DomainRegister', ['entity_type' => 'domain', 'entity_id' => 601, 'client_id' => 11, 'domain' => 'avariver.com']);
$result = EventBus::drain(100);
T::eq('all events processed', 4, $result['processed']);
T::eq('no failures', 0, $result['failed']);
T::eq('nothing left pending', 0, Db::count('events', ['status' => 'pending']));
// ledger_agent + billing_reconciliation subscribe to invoice.created
T::ok('ledger_agent memory appended', Db::count('memories', ['agent' => 'ledger_agent', 'scope' => 'operational']) >= 1);
T::ok('billing_reconciliation memory appended', Db::count('memories', ['agent' => 'billing_reconciliation', 'scope' => 'operational']) >= 1);
T::ok('dns_domain_agent memory appended for domain event', Db::count('memories', ['agent' => 'dns_domain_agent', 'scope' => 'operational']) >= 1);
T::eq('drain created no model runs', 0, Db::count('runs'));
$memory = Db::first('memories', ['agent' => 'dns_domain_agent']);
T::ok('memory carries evidence ref to the event', strpos((string) $memory['evidence_ref'], 'event:') === 0);
T::ok('processed_at set', Db::first('events', ['event_type' => 'domain.registered'])['processed_at'] !== null);

T::section('Drain respects the kill switch');
EventBus::capture('InvoicePaid', ['entity_type' => 'invoice', 'entity_id' => 106]);
\Ch247Ai\Core\Settings::put('kill_switch', '1');
$result = EventBus::drain(100);
T::eq('kill switch stops the drain', 0, $result['processed']);
T::eq('event stays pending', 1, Db::count('events', ['status' => 'pending']));
\Ch247Ai\Core\Settings::put('kill_switch', '0');
$result = EventBus::drain(100);
T::eq('drain resumes after release', 1, $result['processed']);

T::section('Retry and dead-letter');
\Ch247Ai\Core\Settings::put('event_max_attempts', '2');
$pdo = Db::pdo();
// Force dispatch failures by dropping the memories table mid-flight.
$pdo->exec('DROP TABLE ' . Db::t('memories'));
EventBus::capture('InvoicePaid', ['entity_type' => 'invoice', 'entity_id' => 107]);
EventBus::drain(100); // attempt 1 fails
$still = Db::first('events', ['entity_id' => 107]);
T::eq('failed event stays with error', 'pending', $still['status']);
T::ok('error message recorded', strlen((string) $still['error']) > 0);
EventBus::drain(100); // attempt 2 fails -> dead
$dead = Db::first('events', ['entity_id' => 107]);
T::eq('dead-lettered after max attempts', 'dead', $dead['status']);

T::section('Subscription map lives in code, covers Tier A reactions');
$subs = Subscribers::forEventType('cron.daily');
T::ok('daily cron reaches briefing composer', in_array('briefing_composer', $subs, true));
T::ok('daily cron reaches ssl guardian', in_array('ssl_guardian', $subs, true));
T::ok('client.delete has no subscribers', Subscribers::forEventType('client.deleted') === []);

T::section('Prune keeps retention honest');
ch247ai_freeze('2026-10-06 12:00:00');
Db::exec('UPDATE ' . Db::t('events') . " SET created_at = '2025-01-01 00:00:00' WHERE status IN ('processed','dead')");
$kept = Db::count('events');
EventBus::prune();
T::ok('old processed events pruned', Db::count('events') < $kept);

T::finish();
