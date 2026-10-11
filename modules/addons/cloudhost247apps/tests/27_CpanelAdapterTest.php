<?php
/**
 * Suite 27 — the cPanel application deployment adapter.
 *
 * Covers the adapter behind the `cpanel` / `whm` / `uapi` engines: engine and
 * server matching, the step plan, deterministic account identity, account
 * provisioning through WHM, primary-domain verification, MySQL provisioning
 * through the fixed UAPI bridge, install-mode handling, health, status,
 * metrics, rollback compensation and destroy semantics.
 *
 * WHM is a stateful in-memory fake: accounts, databases and users the adapter
 * creates persist for the rest of the section, so idempotent replays and
 * convergence are asserted against observable state, not canned replies.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Adapters\AdapterFactory;
use Ch247Apps\Adapters\CpanelAdapter;
use Ch247Apps\Adapters\DeploymentContext;
use Ch247Apps\Catalog\Manifest;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\CpanelException;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\HealthCheckException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Servers\ServerService;

Harness::boot();
Harness::relaxRateLimits();

$worker = Actor::system('Worker');
$super = Harness::adminActor(1);
$now = Clock::now();

/* ------------------------------------------------------------- fixtures */

function cpanelYaml($package = 'cloudhost247_starter', $mode = 'none', array $databases = null)
{
    $dbs = $databases === null ? [['name' => 'appdb', 'engine' => 'mysql']] : $databases;
    $dbYaml = '';
    foreach ($dbs as $db) {
        $dbYaml .= "    - name: {$db['name']}\n      engine: {$db['engine']}\n";
    }
    return <<<YAML
schema: 1
id: hosted-blog
name: Hosted Blog
slug: hosted-blog
category: cms
kind: application
version: 2.1.0
channel: stable
deployment:
  engine: cpanel
  supported_hosting_types: [cpanel, shared]
  update_strategy: recreate
requirements:
  cpu_min: 1
  memory_min_mb: 512
  storage_min_mb: 1024
services: {}
databases:
{$dbYaml}adapters:
  cpanel:
    package: {$package}
    mode: {$mode}
healthcheck:
  type: http
  path: /
domain:
  enabled: true
ssl:
  enabled: true
YAML;
}

$appSeq = 0;
$makeVersion = function ($yaml) use (&$appSeq, $now) {
    $appSeq++;
    $appId = Db::insert('applications', [
        'name' => 'Fixture ' . $appSeq, 'slug' => 'fixture-' . $appSeq,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $versionId = Db::insert('application_versions', [
        'application_id' => $appId, 'version' => '1.0.' . $appSeq, 'channel' => 'stable',
        'manifest' => $yaml, 'manifest_hash' => Manifest::fromYaml($yaml)->hash(),
        'status' => 'published', 'created_at' => $now,
    ]);
    return [$appId, $versionId];
};

$installSeq = 0;
$makeInstallation = function ($serverId, $appId, $versionId, $domain = 'blog.example.test') use (&$installSeq, $now) {
    $installSeq++;
    return Db::insert('installations', [
        'reference' => 'APP-TEST-' . $installSeq, 'uuid' => Str::uuid4(), 'customer_id' => 42,
        'application_id' => $appId, 'application_version_id' => $versionId,
        'server_id' => $serverId, 'name' => 'Fixture install ' . $installSeq,
        'container_project' => 'c42fixture' . $installSeq, 'domain' => $domain,
        'adapter' => 'cpanel', 'created_at' => $now, 'updated_at' => $now,
    ]);
};

$attachDomain = function ($installationId, $domain, $primary) use ($now) {
    $domainId = Db::insert('domains', [
        'customer_id' => 42, 'domain' => $domain, 'created_at' => $now, 'updated_at' => $now,
    ]);
    Db::insert('installation_domains', [
        'installation_id' => $installationId, 'domain_id' => $domainId,
        'primary_domain' => $primary ? 1 : 0, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    return $domainId;
};

$whmToken = 'WHM-API-TOKEN-PRIVATE-123456';
$cpanelServer = (new ServerService($super))->register([
    'name' => 'cPanel adapter test host', 'hostname' => 'whm.example.test',
    'ip_address' => '198.51.100.65', 'server_type' => 'cpanel', 'cpanel_enabled' => true,
    'credentials' => ['whm_api_token' => ['secret' => $whmToken, 'username' => 'root']],
]);

// A stateful WHM fake: accounts, databases and users persist once created.
$whmAccounts = [];
$whmDatabases = [];
$whmUsers = [];
$whmCalls = [];
$whmFailures = [];
$fakeWhm = function ($method, $url, $options) use (&$whmAccounts, &$whmDatabases, &$whmUsers, &$whmCalls, &$whmFailures) {
    $endpoint = '';
    if (preg_match('|/json-api/([a-z_]+)|', (string) $url, $match)) {
        $endpoint = $match[1];
    }
    $params = [];
    if (strtoupper((string) $method) === 'GET') {
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $params);
    } elseif (isset($options['form']) && is_array($options['form'])) {
        $params = $options['form'];
    }
    $operation = $endpoint;
    if ($endpoint === 'uapi_cpanel' && isset($params['cpanel.function'])) {
        $operation = 'uapi:' . $params['cpanel.function'];
    }
    $whmCalls[] = ['operation' => $operation, 'params' => $params];
    if (isset($whmFailures[$operation])) {
        $failure = $whmFailures[$operation];
        if (is_callable($failure)) {
            $failure = call_user_func($failure, $params);
        }
        if ($failure !== null) {
            return $failure;
        }
    }
    $ok = function (array $data = []) {
        return ['status' => 200, 'headers' => [],
            'body' => json_encode(['data' => $data, 'metadata' => ['result' => 1, 'reason' => 'OK']]),
            'error' => null];
    };
    $uapiOk = function () use ($ok) {
        return $ok(['uapi' => ['status' => 1, 'data' => [], 'errors' => null, 'warnings' => null]]);
    };
    switch ($operation) {
        case 'version':
            return $ok(['version' => '11.126.0.99']);
        case 'listaccts':
            $rows = array_values($whmAccounts);
            if (isset($params['search'])) {
                $rows = array_values(array_filter($rows, function ($row) use ($params) {
                    return strtolower((string) $row['user']) === strtolower((string) $params['search']);
                }));
            }
            return $ok(['acct' => $rows]);
        case 'createacct':
            $whmAccounts[strtolower((string) $params['username'])] = [
                'user' => strtolower((string) $params['username']),
                'domain' => strtolower((string) $params['domain']),
                'plan' => (string) $params['plan'],
                'suspended' => 0,
            ];
            return $ok([]);
        case 'suspendacct':
            $whmAccounts[strtolower((string) $params['username'])]['suspended'] = 1;
            return $ok([]);
        case 'unsuspendacct':
            $whmAccounts[strtolower((string) $params['username'])]['suspended'] = 0;
            return $ok([]);
        case 'removeacct':
            unset($whmAccounts[strtolower((string) $params['username'])]);
            return $ok([]);
        case 'uapi:create_database':
            $whmDatabases[$params['cpanel.user'] . '_' . $params['name']] = true;
            return $uapiOk();
        case 'uapi:create_user':
            $whmUsers[$params['cpanel.user'] . '_' . $params['name']] = $params['password'];
            return $uapiOk();
        case 'uapi:set_privileges_on_database':
            if (empty($whmDatabases[$params['cpanel.user'] . '_' . $params['database']])
                || empty($whmUsers[$params['cpanel.user'] . '_' . $params['user']])) {
                return ['status' => 200, 'headers' => [],
                    'body' => json_encode(['data' => ['uapi' => ['status' => 0, 'errors' => ['missing']]],
                        'metadata' => ['result' => 0, 'reason' => 'UAPI failed']]), 'error' => null];
            }
            return $uapiOk();
        case 'uapi:delete_database':
            unset($whmDatabases[$params['cpanel.user'] . '_' . $params['name']]);
            return $uapiOk();
        default:
            return ['status' => 404, 'headers' => [], 'body' => 'unexpected endpoint', 'error' => null];
    }
};
Http::setClientFake($fakeWhm);

/* ------------------------------------------------- engine and matching */

section('The cPanel adapter matches cpanel engines on cPanel servers');

list($appId, $versionId) = $makeVersion(cpanelYaml());
$installationId = $makeInstallation((int) $cpanelServer['id'], $appId, $versionId);
$context = DeploymentContext::load($installationId, 'install', $worker, ['deployment_id' => 7]);
$adapter = new CpanelAdapter($worker);

T::is('adapter name is cpanel', 'cpanel', $adapter->name());
T::is('adapter engine is cpanel', 'cpanel', $adapter->engine());
T::ok('it supports a cpanel manifest on a cpanel server',
    $adapter->supports($context->manifest, $context->server));
T::ok('it resolves from the factory for cpanel',
    AdapterFactory::forEngine('cpanel', $worker) instanceof CpanelAdapter);
T::ok('it resolves from the factory for whm',
    AdapterFactory::forEngine('whm', $worker) instanceof CpanelAdapter);
T::ok('it resolves from the factory for uapi',
    AdapterFactory::forEngine('uapi', $worker) instanceof CpanelAdapter);
T::ok('availability reports the cpanel engine installed',
    !empty(AdapterFactory::availability()['cpanel']['installed']));

$dockerManifest = Manifest::fromYaml(str_replace('engine: cpanel', 'engine: docker-compose', cpanelYaml()));
T::ok('it refuses a docker manifest', !$adapter->supports($dockerManifest, $context->server));
$vpsServer = (new ServerService($super))->register([
    'name' => 'Not cPanel', 'hostname' => 'vps.example.test', 'ip_address' => '198.51.100.66',
    'server_type' => 'vps',
]);
T::ok('it refuses a VPS server', !$adapter->supports($context->manifest, $vpsServer));
$flagOff = $context->server;
$flagOff['cpanel_enabled'] = 0;
T::ok('it refuses a server without the cpanel flag', !$adapter->supports($context->manifest, $flagOff));
$disabled = $context->server;
$disabled['status'] = 'disabled';
T::ok('it refuses a disabled server', !$adapter->supports($context->manifest, $disabled));
Settings::override('cpanel_enabled', '0');
T::ok('it refuses while the global kill-switch is off', !$adapter->supports($context->manifest, $context->server));
T::ok('availability reports the engine disabled then',
    AdapterFactory::availability()['cpanel']['reason'] === 'ADAPTER_DISABLED');
Settings::override('cpanel_enabled', '1');

/* --------------------------------------------------------------- plan */

section('The step plan is ordered, stable and fails fast on extra domains');

$plan = $adapter->plan($context);
T::is('install plan keys', ['ensure_account', 'ensure_domain', 'ensure_databases', 'install_application',
    'health_check'], array_column($plan, 'key'));
T::is('install plan is ordered', [1, 2, 3, 4, 5], array_column($plan, 'step_order'));
T::ok('account creation is reversible and creates a resource',
    $plan[0]['reversible'] === true && $plan[0]['creates_resource'] === true);
T::is('update plan keys', ['ensure_account', 'ensure_databases', 'install_application', 'health_check'],
    array_column($adapter->plan($context->withAction('update')), 'key'));
T::is('destroy plan keys', ['remove_databases', 'remove_account'],
    array_column($adapter->plan($context->withAction('destroy')), 'key'));
T::is('ssl re-proves serving', ['health_check'],
    array_column($adapter->plan($context->withAction('ssl')), 'key'));
T::is('domain action re-verifies the binding', ['ensure_domain'],
    array_column($adapter->plan($context->withAction('domain_configure')), 'key'));

$multiId = $makeInstallation((int) $cpanelServer['id'], $appId, $versionId);
$attachDomain($multiId, 'blog.example.test', true);
$attachDomain($multiId, 'extra.example.test', false);
$multiContext = DeploymentContext::load($multiId, 'install', $worker);
T::throws('a second attached domain fails the plan fast', ValidationException::class,
    function () use ($adapter, $multiContext) {
        $adapter->plan($multiContext);
    });
try {
    $adapter->plan($multiContext);
    T::ok('multi-domain error code is explicit', false);
} catch (ValidationException $e) {
    T::is('multi-domain error code is explicit', 'CPANEL_MULTI_DOMAIN_UNSUPPORTED', $e->errorCode());
}
$stopPlan = $adapter->plan($multiContext->withAction('stop'));
T::is('lifecycle plans ignore attached domains', ['stop_account'], array_column($stopPlan, 'key'));

/* --------------------------------------------------- account identity */

section('Account identity is deterministic, short and unique per installation');

$username = CpanelAdapter::accountUsername($context);
T::ok('the username fits cPanel rules', (bool) preg_match('/^[a-z][a-z0-9]{7}$/', $username));
T::is('the username is deterministic', $username, CpanelAdapter::accountUsername($context));
$otherContext = DeploymentContext::load($multiId, 'install', $worker);
T::ok('another installation derives another username',
    CpanelAdapter::accountUsername($otherContext) !== $username);
T::ok('the derived names carry no underscores',
    strpos($username, '_') === false && strpos(CpanelAdapter::accountUsername($otherContext), '_') === false);

/* ------------------------------------------------------ ensure account */

section('Account provisioning creates once, then verifies and converges');

$whmCalls = [];
$step = $adapter->executeStep('ensure_account', $context);
T::ok('the account was created', $step['output']['created'] === true);
T::is('the ledger records a cpanel_account', 'cpanel_account', $step['resources'][0]['type']);
T::is('the ledger names the derived username', $username, $step['resources'][0]['name']);
$creates = array_values(array_filter($whmCalls, function ($call) {
    return $call['operation'] === 'createacct';
}));
T::is('one createacct ran', 1, count($creates));
T::is('the manifest package is used', 'cloudhost247_starter', $creates[0]['params']['plan']);
T::is('the primary domain is used', 'blog.example.test', $creates[0]['params']['domain']);
T::ok('no contact email is invented', !isset($creates[0]['params']['contactemail']));

$whmCalls = [];
$replay = $adapter->executeStep('ensure_account', $context);
T::ok('a replay verifies instead of duplicating', $replay['output']['idempotent_replay'] === true);
T::is('no second createacct ran', 0, count(array_filter($whmCalls, function ($call) {
    return $call['operation'] === 'createacct';
})));

// A suspended replay converges back on serving (rollback parks accounts suspended).
$whmAccounts[$username]['suspended'] = 1;
$whmCalls = [];
$adapter->executeStep('ensure_account', $context);
$unsuspends = array_values(array_filter($whmCalls, function ($call) {
    return $call['operation'] === 'unsuspendacct';
}));
T::is('a suspended replay unsuspends', 1, count($unsuspends));
T::is('and the fake confirms it', 0, $whmAccounts[$username]['suspended']);

// A conflicting pre-existing account is refused, never adopted.
$conflictId = $makeInstallation((int) $cpanelServer['id'], $appId, $versionId, 'other.example.test');
$conflictContext = DeploymentContext::load($conflictId, 'install', $worker);
$conflictUser = CpanelAdapter::accountUsername($conflictContext);
$whmAccounts[$conflictUser] = ['user' => $conflictUser, 'domain' => 'someone-else.example.test',
    'plan' => 'cloudhost247_starter', 'suspended' => 0];
try {
    $adapter->executeStep('ensure_account', $conflictContext);
    T::ok('a conflicting account is refused', false);
} catch (CpanelException $e) {
    T::is('a conflicting account is refused', 'CPANEL_ACCOUNT_CONFLICT', $e->errorCode());
}

// Package resolution: manifest wins, setting is the fallback, absence fails closed.
list($noPkgApp, $noPkgVersion) = $makeVersion(str_replace("    package: cloudhost247_starter\n", '', cpanelYaml()));
$noPkgId = $makeInstallation((int) $cpanelServer['id'], $noPkgApp, $noPkgVersion);
$noPkgContext = DeploymentContext::load($noPkgId, 'install', $worker);
try {
    $adapter->executeStep('ensure_account', $noPkgContext);
    T::ok('a missing package fails closed', false);
} catch (ConfigurationException $e) {
    T::is('a missing package fails closed', 'CPANEL_PACKAGE_REQUIRED', $e->errorCode());
}
Settings::override('cpanel_default_package', 'fleet_default');
$whmCalls = [];
$adapter->executeStep('ensure_account', $noPkgContext);
$creates = array_values(array_filter($whmCalls, function ($call) {
    return $call['operation'] === 'createacct';
}));
T::is('the default package setting is the fallback', 'fleet_default', end($creates)['params']['plan']);
Settings::override('cpanel_default_package', '');

$noDomainId = $makeInstallation((int) $cpanelServer['id'], $appId, $versionId, '');
Db::update('installations', ['domain' => null], ['id' => $noDomainId]);
try {
    $adapter->executeStep('ensure_account', DeploymentContext::load($noDomainId, 'install', $worker));
    T::ok('a missing domain fails closed', false);
} catch (ValidationException $e) {
    T::is('a missing domain fails closed', 'CPANEL_DOMAIN_REQUIRED', $e->errorCode());
}

/* ------------------------------------------------------- ensure domain */

section('Domain verification binds the installation to the verified account');

$domainStep = $adapter->executeStep('ensure_domain', $context);
T::is('the verified domain matches', 'blog.example.test', $domainStep['output']['account']['domain']);
T::throws('a missing account is reported, not invented', CpanelException::class,
    function () use ($adapter, $conflictContext) {
        $adapter->executeStep('ensure_domain', $conflictContext);
    });
$driftId = $makeInstallation((int) $cpanelServer['id'], $appId, $versionId, 'drifted.example.test');
$driftContext = DeploymentContext::load($driftId, 'install', $worker);
$driftUser = CpanelAdapter::accountUsername($driftContext);
$whmAccounts[$driftUser] = ['user' => $driftUser, 'domain' => 'blog.example.test',
    'plan' => 'cloudhost247_starter', 'suspended' => 0];
try {
    $adapter->executeStep('ensure_domain', $driftContext);
    T::ok('a drifted binding is refused', false);
} catch (CpanelException $e) {
    T::is('a drifted binding is refused', 'CPANEL_ACCOUNT_CONFLICT', $e->errorCode());
}

/* ---------------------------------------------------- ensure databases */

section('MySQL provisioning is gated, convergent and records its outputs sealed');

Settings::override('cpanel_mysql_enabled', '0');
try {
    $adapter->executeStep('ensure_databases', $context);
    T::ok('database writes fail closed while the gate is off', false);
} catch (ConfigurationException $e) {
    T::is('database writes fail closed while the gate is off', 'CPANEL_MYSQL_DISABLED', $e->errorCode());
}
list($noDbApp, $noDbVersion) = $makeVersion(cpanelYaml('cloudhost247_starter', 'none', []));
$noDbId = $makeInstallation((int) $cpanelServer['id'], $noDbApp, $noDbVersion);
$skipped = $adapter->executeStep('ensure_databases', DeploymentContext::load($noDbId, 'install', $worker));
T::is('no declared databases means a no-op even with the gate off', [], $skipped['output']['databases']);

Settings::override('cpanel_mysql_enabled', '1');
$whmCalls = [];
$dbStep = $adapter->executeStep('ensure_databases', $context);
$ops = array_column($whmCalls, 'operation');
T::contains('a best-effort delete starts the sequence', 'uapi:delete_database', $ops);
T::contains('the database is created', 'uapi:create_database', $ops);
T::contains('the user is created', 'uapi:create_user', $ops);
T::contains('the grant confirms the sequence', 'uapi:set_privileges_on_database', $ops);
$grants = array_values(array_filter($whmCalls, function ($call) {
    return $call['operation'] === 'uapi:set_privileges_on_database';
}));
T::is('the grant is full rights on the one database', 'ALL PRIVILEGES', $grants[0]['params']['privileges']);
T::is('the ledger records the database', 'database', $dbStep['resources'][0]['type']);
T::is('the ledger records the database user', 'database_user', $dbStep['resources'][1]['type']);
T::ok('the database name carries the account prefix',
    strpos($dbStep['resources'][0]['name'], $username . '_') === 0);
T::ok('generated names stay underscore-free apart from the prefix',
    substr_count($dbStep['resources'][0]['name'], '_') === 1
    && substr_count($dbStep['resources'][1]['name'], '_') === 1);

$envRows = Db::fetch('environment', ['installation_id' => $installationId]);
$byKey = [];
foreach ($envRows as $row) {
    $byKey[$row['env_key']] = $row;
}
T::ok('the password is sealed and flagged secret',
    isset($byKey['CPANEL_DB_1_PASSWORD']) && (int) $byKey['CPANEL_DB_1_PASSWORD']['is_secret'] === 1);
T::ok('provisioning outputs are locked platform values',
    (int) $byKey['CPANEL_DB_1_PASSWORD']['locked'] === 1
    && $byKey['CPANEL_DB_1_PASSWORD']['source'] === 'platform');
T::notContains('ciphertext is not the password', $grants[0]['params']['user'],
    (string) $byKey['CPANEL_DB_1_PASSWORD']['encrypted_value']);
T::ok('the provisioned marker is set', isset($byKey['CPANEL_DB_1_PROVISIONED']));
$hostRow = Db::first('environment', ['installation_id' => $installationId, 'env_key' => 'CPANEL_DB_1_HOST']);
T::is('the sealed host output decrypts to localhost', 'localhost',
    Crypto::decrypt($hostRow['encrypted_value'], DeploymentContext::envContext($installationId, 'CPANEL_DB_1_HOST')));
T::ok('non-secret outputs are readable markers', $hostRow !== null && (int) $hostRow['is_secret'] === 0);

// A retry converges on the same names without duplicating.
$whmCalls = [];
$retry = $adapter->executeStep('ensure_databases', $context);
T::is('a retry provisions the same database', $dbStep['output']['databases'], $retry['output']['databases']);
T::is('still exactly one database on the fake', 1, count($whmDatabases));

// Non-MySQL engines fail closed before any vendor call.
list($pgApp, $pgVersion) = $makeVersion(cpanelYaml('cloudhost247_starter', 'none',
    [['name' => 'appdb', 'engine' => 'postgres']]));
$pgId = $makeInstallation((int) $cpanelServer['id'], $pgApp, $pgVersion);
$whmCalls = [];
try {
    $adapter->executeStep('ensure_databases', DeploymentContext::load($pgId, 'install', $worker));
    T::ok('postgres on cPanel fails closed', false);
} catch (ConfigurationException $e) {
    T::is('postgres on cPanel fails closed', 'CPANEL_DATABASE_ENGINE_UNSUPPORTED', $e->errorCode());
}
T::is('no vendor call ran for the rejected engine', [], $whmCalls);

// A user left behind by a crashed attempt is reported with a cleanup path.
$stuckId = $makeInstallation((int) $cpanelServer['id'], $appId, $versionId);
$stuckContext = DeploymentContext::load($stuckId, 'install', $worker);
$adapter->executeStep('ensure_account', $stuckContext);
$adapter->executeStep('ensure_databases', $stuckContext);
$stuckUserFull = null;
foreach ($whmCalls as $call) {
    if ($call['operation'] === 'uapi:create_user') {
        $stuckUserFull = $call['params']['cpanel.user'] . '_' . $call['params']['name'];
    }
}
$whmFailures['uapi:create_user'] = ['status' => 200, 'headers' => [],
    'body' => json_encode(['data' => ['uapi' => ['status' => 0, 'errors' => ['exists']]],
        'metadata' => ['result' => 0, 'reason' => 'UAPI failed']]), 'error' => null];
try {
    $adapter->executeStep('ensure_databases', $stuckContext);
    T::ok('a pre-existing user is reported', false);
} catch (ConfigurationException $e) {
    // The probe grant succeeds (user and database both exist), proving the
    // failure is a leftover user whose password was never recorded.
    T::is('a pre-existing user is reported', 'CPANEL_DATABASE_USER_CONFLICT', $e->errorCode());
}
unset($whmUsers[$stuckUserFull]);
try {
    $adapter->executeStep('ensure_databases', $stuckContext);
    T::ok('a failed probe rethrows the original rejection', false);
} catch (CpanelException $e) {
    T::is('a failed probe rethrows the original rejection', 'CPANEL_API_REJECTED', $e->errorCode());
}
unset($whmFailures['uapi:create_user']);
Settings::override('cpanel_mysql_enabled', '0');

/* ------------------------------------------------- install application */

section('Install modes are explicit: none provisions, anything else fails closed');

$installStep = $adapter->executeStep('install_application', $context);
T::is('mode none is a documented no-op', 'none', $installStep['output']['mode']);
list($wpApp, $wpVersion) = $makeVersion(cpanelYaml('cloudhost247_starter', 'wordpress'));
$wpId = $makeInstallation((int) $cpanelServer['id'], $wpApp, $wpVersion);
try {
    $adapter->executeStep('install_application', DeploymentContext::load($wpId, 'install', $worker));
    T::ok('an unimplemented mode fails closed', false);
} catch (ConfigurationException $e) {
    T::is('an unimplemented mode fails closed', 'CPANEL_INSTALL_MODE_UNSUPPORTED', $e->errorCode());
}
$scriptYaml = str_replace("    mode: none\n", "    mode: ''\n    script: fantastico/blog\n", cpanelYaml());
list($scriptApp, $scriptVersion) = $makeVersion($scriptYaml);
$scriptId = $makeInstallation((int) $cpanelServer['id'], $scriptApp, $scriptVersion);
try {
    $adapter->executeStep('install_application', DeploymentContext::load($scriptId, 'install', $worker));
    T::ok('a script without a mode fails closed', false);
} catch (ConfigurationException $e) {
    T::is('a script without a mode fails closed', 'CPANEL_INSTALL_MODE_UNSUPPORTED', $e->errorCode());
}

/* ---------------------------------------------- health, status, metrics */

section('Health, status and metrics report verified state or unknown — never guesses');

$health = $adapter->health($context);
T::is('a provisioned account is healthy', 'healthy', $health['state']);
$healthStep = $adapter->executeStep('health_check', $context);
T::is('the health step passes', 'healthy', $healthStep['output']['health']['state']);

$status = $adapter->status($context);
T::is('status reports running', 'running', $status['state']);
T::is('status carries the verified domain', 'blog.example.test', $status['domain']);

$adapter->executeStep('stop_account', $context);
T::is('a suspended account is unhealthy', 'unhealthy', $adapter->health($context)['state']);
T::is('status reports suspended', 'suspended', $adapter->status($context)['state']);
$adapter->executeStep('start_account', $context);
T::is('an unsuspended account is healthy again', 'healthy', $adapter->health($context)['state']);

$freshId = $makeInstallation((int) $cpanelServer['id'], $appId, $versionId);
$freshContext = DeploymentContext::load($freshId, 'install', $worker);
T::is('a missing account is unhealthy', 'unhealthy', $adapter->health($freshContext)['state']);
T::is('a missing account reports missing', 'missing', $adapter->status($freshContext)['state']);
T::is('a conflicting account is unhealthy too', 'unhealthy', $adapter->health($conflictContext)['state']);

Http::setClientFake(function () {
    return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'connection refused'];
});
T::is('an unreachable WHM reports unknown status', 'unknown', $adapter->status($context)['state']);
T::is('an unreachable WHM reports unknown health', 'unknown', $adapter->health($context)['state']);
Http::setClientFake($fakeWhm);

T::throws('the health step fails closed on an unhealthy installation', HealthCheckException::class,
    function () use ($adapter, $conflictContext) {
        $adapter->executeStep('health_check', $conflictContext);
    });

T::is('metrics are null while the usage gates are off', null, $adapter->metrics($context));
Settings::overrideMany(['cpanel_uapi_quota_usage_enabled' => '1', 'cpanel_uapi_bandwidth_usage_enabled' => '1']);
Http::setClientFake(function ($method, $url, $options) use ($fakeWhm) {
    if (strpos((string) $url, 'uapi_cpanel') !== false || (isset($options['form']['cpanel.function'])
        && in_array($options['form']['cpanel.function'], ['get_quota_info', 'get_stats'], true))) {
        $function = isset($options['form']['cpanel.function']) ? $options['form']['cpanel.function'] : '';
        if ($function === '' && preg_match('/cpanel\.function=([a-z_]+)/', (string) $url, $match)) {
            $function = $match[1];
        }
        if ($function === 'get_quota_info') {
            return ['status' => 200, 'headers' => [], 'error' => null, 'body' => json_encode(['data' => ['uapi' => [
                'status' => 1, 'errors' => null, 'warnings' => null,
                'data' => ['megabyte_limit' => '1024', 'megabytes_remain' => '900', 'megabytes_used' => '124',
                    'inode_limit' => '100000', 'inodes_remain' => '99000', 'inodes_used' => '1000'],
            ]], 'metadata' => ['result' => 1, 'reason' => 'OK']])];
        }
        if ($function === 'get_stats') {
            return ['status' => 200, 'headers' => [], 'error' => null, 'body' => json_encode(['data' => ['uapi' => [
                'status' => 1, 'errors' => null, 'warnings' => null,
                'data' => [['id' => 'bandwidthusage', '_count' => '12', '_max' => '100', 'percent' => '12',
                    'units' => 'MB', 'zeroisunlimited' => 0, 'is_maxed' => 0, 'normalized' => 1]],
            ]], 'metadata' => ['result' => 1, 'reason' => 'OK']])];
        }
    }
    return $fakeWhm($method, $url, $options);
});
$metrics = $adapter->metrics($context);
T::ok('quota usage is reported once the gate is on', isset($metrics['quota']['usage']['megabytes_used']));
T::ok('bandwidth usage is reported once the gate is on', isset($metrics['bandwidth']['usage']['percent']));
Http::setClientFake($fakeWhm);
Settings::overrideMany(['cpanel_uapi_quota_usage_enabled' => '0', 'cpanel_uapi_bandwidth_usage_enabled' => '0']);

try {
    $adapter->logs($context);
    T::ok('logs fail closed', false);
} catch (ConfigurationException $e) {
    T::is('logs fail closed', 'CPANEL_LOGS_UNAVAILABLE', $e->errorCode());
}

/* ------------------------------------------------ lifecycle and rollback */

section('Lifecycle, rollback and destroy preserve data unless told otherwise');

$restarted = $adapter->restart($context);
T::is('restart keeps the account healthy', 'healthy', $restarted['health']['state']);
$stopped = $adapter->stop($context);
T::is('stop suspends', 1, $whmAccounts[$username]['suspended']);
$started = $adapter->start($context);
T::is('start unsuspends', 0, $whmAccounts[$username]['suspended']);
T::throws('unknown steps are rejected', ValidationException::class, function () use ($adapter, $context) {
    $adapter->executeStep('nope', $context);
});

// Rollback of databases removes only marker-provisioned ones.
Settings::override('cpanel_mysql_enabled', '1');
$contextDbFull = $dbStep['resources'][0]['name'];
$rollback = $adapter->rollbackStep('ensure_databases', $context);
T::ok('rollback removes the provisioned database', count($rollback['removed']) === 1);
T::is('the marker is cleared', null,
    Db::first('environment', ['installation_id' => $installationId, 'env_key' => 'CPANEL_DB_1_PROVISIONED']));
T::ok('the fake confirms removal', !isset($whmDatabases[$contextDbFull]));
T::ok('another installation database is untouched', $whmDatabases !== []);
$secondRollback = $adapter->rollbackStep('ensure_databases', $context);
T::is('a second rollback finds nothing to do', [], $secondRollback['removed']);

// Rollback of the account parks it suspended — never terminated.
$parked = $adapter->rollbackStep('ensure_account', $context);
T::is('rollback parks the account suspended', 1, $whmAccounts[$username]['suspended']);
T::ok('the account still exists', isset($whmAccounts[$username]));
$missingRollback = $adapter->rollbackStep('ensure_account', $freshContext);
T::is('rollback of a missing account is a no-op', [], $missingRollback['removed']);

// Destroy without data removal preserves everything and suspends.
$adapter->executeStep('ensure_databases', $context);
$kept = $adapter->destroy($context, false);
T::is('destroy without data removal suspends', false, $kept['terminated']);
T::ok('the account still exists', isset($whmAccounts[$username]));
T::ok('the database still exists', isset($whmDatabases[$contextDbFull]));
T::ok('the password output survives preservation',
    Db::first('environment', ['installation_id' => $installationId, 'env_key' => 'CPANEL_DB_1_PASSWORD'])
    !== null);

// Destroy with data removal terminates and clears provisioning outputs.
$gone = $adapter->destroy($context, true);
T::is('destroy terminates', true, $gone['terminated']);
T::is('destroy confirms termination', true, $gone['confirmed']);
T::ok('the account is gone', !isset($whmAccounts[$username]));
T::ok('its database is gone', !isset($whmDatabases[$contextDbFull]));
T::ok('another installation database survives', $whmDatabases !== []);
T::is('provisioning outputs are cleared', null,
    Db::first('environment', ['installation_id' => $installationId, 'env_key' => 'CPANEL_DB_1_PASSWORD']));
T::contains('the ledger closes the account', 'cpanel_account', array_column($gone['removed'], 'type'));
Settings::override('cpanel_mysql_enabled', '0');

// The kill-switch gates actuation but never cleanup or reads.
Settings::override('cpanel_enabled', '0');
try {
    $adapter->executeStep('ensure_account', $context);
    T::ok('execute fails closed while disabled', false);
} catch (ConfigurationException $e) {
    T::is('execute fails closed while disabled', 'ADAPTER_DISABLED', $e->errorCode());
}
try {
    $adapter->stop($context);
    T::ok('stop fails closed while disabled', false);
} catch (ConfigurationException $e) {
    T::is('stop fails closed while disabled', 'ADAPTER_DISABLED', $e->errorCode());
}
T::ok('reads still work while disabled', in_array($adapter->status($context)['state'],
    ['missing', 'running', 'suspended', 'unknown'], true));
Settings::override('cpanel_enabled', '1');

Harness::shutdown();
exit(T::summary());
