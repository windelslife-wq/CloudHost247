<?php
/** Source-tagged, bounded uptime retention never deletes unrelated metric history. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Api\AgentHeartbeatIngress;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Migrator;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Servers\AgentAuthenticator;
use Ch247Apps\Servers\ServerService;
use Ch247Apps\Servers\UptimeRetention;

Harness::boot();
Harness::relaxRateLimits();
$service = new ServerService(Harness::adminActor());
$node = $service->register([
    'name' => 'Retention node', 'hostname' => 'retention.example.test',
    'ip_address' => '198.51.100.51', 'server_type' => 'vps',
    'cpu_cores' => 2, 'memory_mb' => 4096, 'storage_mb' => 40960,
    'agent_endpoint' => 'https://198.51.100.51:8443', 'agent_secret' => str_repeat('r', 64),
]);
$id = (int) $node['id'];
$agent = Db::first('agents', ['id' => (int) $node['agent']['id']]);
$cron = new UptimeRetention(Actor::system('Cron'));

section('Additive source schema, migration replay and default-off policy');
T::ok('source migration applied', (new Migrator())->hasRun('0014_agent_uptime_source'));
T::ok('nullable source column exists', (new Migrator())->hasColumn('metrics', 'source'));
$sourceMigration = require dirname(__DIR__) . '/install/migrations/0014_agent_uptime_source.php';
T::nothrow('interrupted migration is idempotently replayable', function () use ($sourceMigration) {
    $sourceMigration['up'](new Migrator());
});
T::is('index exists for scoped age queries', 1, (int) (Db::selectOne(
    "SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'index' AND name = ?",
    ['idx_ch247apps_metric_source_age'])['n']));
T::ok('retention defaults off', !Settings::bool('agent_uptime_retention_enabled', false));
T::is('shipped period is 30 days but cannot run while disabled', 30,
    Settings::int('agent_uptime_retention_days', 30));
T::is('disabled cron has no effect', 0, $cron->prune());
T::throws('admin cannot invoke retention', AuthorizationException::class,
    function () { (new UptimeRetention(Harness::adminActor()))->prune(); });

define('WHMCS', true);
require_once dirname(__DIR__) . '/cloudhost247apps.php';
$config = cloudhost247apps_config();
T::is('addon retention switch off', '', $config['fields']['agent_uptime_retention_enabled']['Default']);
T::is('addon period defaults to 30', '30', $config['fields']['agent_uptime_retention_days']['Default']);

section('Only the signed, assigned agent records a taggable uptime sample');
Settings::override('agent_heartbeat_ingress_enabled', '1');
Settings::override('agent_uptime_ingress_enabled', '1');
$body = '{"agent_version":"1.0.0","uptime_seconds":120}';
$signed = AgentAuthenticator::sign($agent, 'POST', AgentHeartbeatIngress::PATH, $body);
foreach ($signed['headers'] as $name => $value) { Http::overrideHeader($name, $value); }
T::is('signed uptime accepted', 200, (new AgentHeartbeatIngress())->dispatch(
    'POST', AgentHeartbeatIngress::PATH, $body)['status']);
$sample = Db::first('metrics', ['server_id' => $id]);
T::is('sample is specifically tagged for retention', ServerService::METRIC_SOURCE_AGENT_UPTIME,
    $sample['source']);
T::is('uptime value remains the only numeric field', null, $sample['cpu_percent']);
T::is('health remains unverified', 'unknown', $service->health($id)['health']);
T::throws('customer cannot forge a taggable sample', AuthorizationException::class,
    function () use ($id) { (new ServerService(Actor::customer(2)))->recordNodeUptime($id, 123); });
T::throws('another agent cannot claim this server', AuthorizationException::class,
    function () use ($id) { (new ServerService(Actor::agent(55, $id + 1)))->recordNodeUptime($id, 123); });
T::throws('source tag cannot attach to health data', ValidationException::class,
    function () use ($id, $service) { $service->recordMetrics($id,
        ['uptime_seconds' => 1, 'health' => 'healthy'], null, 'server', ServerService::METRIC_SOURCE_AGENT_UPTIME); });

section('Opt-in prune is bounded, strictly older and source-scoped');
// Freeze the clock: the cutoff-edge fixture is exactly 30 days old, so a
// second boundary crossed between fixture setup and prune() would push it
// over the edge and flake the "rows remain" assertions (E-1 CI hardening).
Clock::freeze();
$old = Clock::at(-31 * 86400);
$edge = Clock::at(-30 * 86400);
for ($n = 0; $n < 205; $n++) {
    Db::insert('metrics', ['server_id' => $id, 'source' => ServerService::METRIC_SOURCE_AGENT_UPTIME,
        'uptime_seconds' => $n, 'sampled_at' => $old]);
}
Db::insert('metrics', ['server_id' => $id, 'source' => ServerService::METRIC_SOURCE_AGENT_UPTIME,
    'uptime_seconds' => 999, 'sampled_at' => $edge]);
Db::insert('metrics', ['server_id' => $id, 'source' => null,
    'uptime_seconds' => 777, 'sampled_at' => $old]);
Db::insert('metrics', ['server_id' => $id, 'source' => 'provider_metric',
    'cpu_percent' => 12, 'sampled_at' => $old]);
Db::insert('metrics', ['server_id' => $id, 'installation_id' => 42, 'scope' => 'application',
    'source' => null, 'health' => 'healthy', 'sampled_at' => $old]);
T::is('all fixtures present', 210, Db::count('metrics'));
T::is('default-off leaves every row alone', 0, $cron->prune());
T::is('no pre-approval deletion', 210, Db::count('metrics'));
Settings::override('agent_uptime_retention_enabled', '1');
foreach (['0', '3651', '-1', '30.5', '1; DROP TABLE metrics'] as $badDays) {
    Settings::override('agent_uptime_retention_days', $badDays);
    T::throws('bad retention period fails closed', ConfigurationException::class,
        function () use ($cron) { $cron->prune(); });
}
T::is('invalid periods deleted nothing', 210, Db::count('metrics'));
Settings::override('agent_uptime_retention_days', '30');
T::is('first pass removes at most 200 old tagged rows', 200, $cron->prune());
T::is('second pass drains remainder', 5, $cron->prune());
T::is('third pass is idempotent', 0, $cron->prune());
T::is('recent tagged and cutoff-edge rows remain', 2,
    Db::count('metrics', ['source' => ServerService::METRIC_SOURCE_AGENT_UPTIME]));
T::is('untagged legacy row survives', 1,
    Db::count('metrics', ['source' => null, 'scope' => 'server']));
T::is('provider metric survives', 1,
    Db::count('metrics', ['source' => 'provider_metric']));
T::is('application metric survives', 1,
    Db::count('metrics', ['scope' => 'application']));
T::is('five total rows remain', 5, Db::count('metrics'));
T::is('no artificial health result from retained uptime', 'unknown', $service->health($id)['health']);

section('Cron task is wired without changing other retention policies');
$cronSource = file_get_contents(dirname(__DIR__) . '/cron/cloudhost247apps.php');
T::contains('cron registers a separate uptime task', "'agent_uptime_pruned'", $cronSource);
T::contains('cron uses system actor for uptime retention', 'new UptimeRetention($actor)', $cronSource);

Clock::unfreeze();
exit(T::summary());
