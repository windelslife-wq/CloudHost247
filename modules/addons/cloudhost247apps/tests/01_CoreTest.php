<?php
/**
 * Suite 01 — platform core.
 *
 * Proves the foundations every other component depends on: the portable schema
 * layer, settings precedence, field encryption and key rotation, RBAC, identity
 * resolution, the hash-chained audit log, idempotency, rate limiting, validation,
 * the YAML parser that reads manifests, and the event stream that feeds the
 * deployment console.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Integration\Gateway;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Migrator;
use Ch247Apps\Core\RateLimiter;
use Ch247Apps\Core\RateLimitException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Core\Validator;
use Ch247Apps\Core\Whmcs;
use Ch247Apps\Core\Yaml;

Harness::boot();
Harness::relaxRateLimits();

/* ------------------------------------------------------------ schema ---- */

section('Migrations create the whole schema');

$expectedTables = [
    'settings', 'role_permissions', 'audit_logs', 'idempotency_keys', 'rate_limits',
    'events', 'logs', 'api_tokens', 'categories', 'applications', 'application_versions',
    'panel_categories', 'control_panels', 'control_panel_plans',
    'application_compatibility', 'application_dependencies', 'plans', 'servers',
    'server_credentials', 'agents', 'agent_nonces', 'metrics', 'domains', 'installations',
    'installation_domains', 'environment', 'volumes', 'certificates', 'jobs', 'deployments',
    'deployment_steps', 'deployment_logs', 'created_resources', 'backups', 'subscriptions',
    'order_links', 'payment_events', 'notifications', 'webhook_events', 'health_probes',
    'schedules', 'migrations', 'provider_accounts', 'customer_servers', 'customer_server_events',
    'panel_accounts', 'panel_account_events',
];
$missing = [];
foreach ($expectedTables as $table) {
    if (!Db::tableExists($table)) {
        $missing[] = $table;
    }
}
T::is('every platform table exists', [], $missing);

$migrator = new Migrator(dirname(__DIR__) . '/install/migrations');
$second = $migrator->migrate();
T::is('migrations are idempotent (nothing applied twice)', 0, count($second['applied']));
T::ok('the ledger records the migrations', count($second['skipped']) >= 6);

// Render the production MySQL DDL from the same historical migration callbacks
// without needing a MySQL server, then audit every declared module FK column.
$mysqlDdlProbe = new class extends Migrator {
    public $ddl = [];

    public function create($logicalName, callable $definition)
    {
        $blueprint = new Blueprint(Db::t($logicalName));
        $definition($blueprint);
        $this->ddl[$logicalName] = implode("\n", $blueprint->createSql('mysql'));
        return $this;
    }

    public function addColumn($logicalName, $column, $definitionSql)
    {
        return $this;
    }

    public function addIndex($logicalName, array $columns, $unique = false, $name = null)
    {
        return $this;
    }
};
$mysqlSchemaMigrationIds = [
    '0002_create_catalog_tables',
    '0003_create_infrastructure_tables',
    '0004_create_installation_tables',
    '0005_create_deployment_tables',
    '0006_create_operations_tables',
    '0007_create_provider_server_tables',
    '0010_create_panel_catalog_tables',
    '0011_create_panel_account_workflow',
];
foreach ($migrator->discover() as $definition) {
    if (in_array($definition['id'], $mysqlSchemaMigrationIds, true)) {
        call_user_func($definition['up'], $mysqlDdlProbe);
    }
}
$foreignKeyCount = 0;
$unsignedForeignKeyCount = 0;
$unsignedReferencedIdCount = 0;
foreach ($mysqlDdlProbe->ddl as $sql) {
    preg_match_all('/FOREIGN KEY \\(`([A-Za-z_][A-Za-z0-9_]*)`\\) REFERENCES `([^`]+)` \\(`id`\\)/',
        $sql, $foreignKeys, PREG_SET_ORDER);
    foreach ($foreignKeys as $foreignKey) {
        $foreignKeyCount++;
        $column = preg_quote('`' . $foreignKey[1] . '`', '/');
        if (preg_match('/^\\s*' . $column . ' BIGINT UNSIGNED\\b/m', $sql)) {
            $unsignedForeignKeyCount++;
        }
        $parentLogical = substr($foreignKey[2], strlen(Db::PREFIX));
        if (isset($mysqlDdlProbe->ddl[$parentLogical])
            && preg_match('/^\\s*`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT\\b/m',
                $mysqlDdlProbe->ddl[$parentLogical])) {
            $unsignedReferencedIdCount++;
        }
    }
}
T::is('module DDL declares all expected foreign keys', 21, $foreignKeyCount);
T::is('every MySQL FK child column is unsigned', $foreignKeyCount, $unsignedForeignKeyCount);
T::is('every referenced Blueprint id is unsigned', $foreignKeyCount, $unsignedReferencedIdCount);
T::ok('legacy FK repair migration is applied by the SQLite harness',
    in_array('0009_align_unsigned_foreign_key_types', $migrator->appliedIds(), true));
T::ok('the dedicated panel catalog migration is applied by the SQLite harness',
    in_array('0010_create_panel_catalog_tables', $migrator->appliedIds(), true));
T::ok('the WHMCS-bound panel-account workflow migration is applied by the SQLite harness',
    in_array('0011_create_panel_account_workflow', $migrator->appliedIds(), true));
T::ok('the queue has a distinct panel-account reference', $migrator->hasColumn('jobs', 'panel_account_id'));

section('Db layer is parameter bound and refuses unsafe operations');

$id = Db::insert('categories', [
    'name' => 'Test', 'slug' => 'test-' . bin2hex(random_bytes(3)), 'active' => 1,
    'created_at' => Clock::now(), 'updated_at' => Clock::now(),
]);
T::ok('insert returns a primary key', $id > 0);

$row = Db::first('categories', ['id' => $id]);
T::is('first() reads the row back', 'Test', $row['name']);

Db::update('categories', ['name' => 'Renamed'], ['id' => $id]);
T::is('update() writes', 'Renamed', Db::first('categories', ['id' => $id])['name']);

T::is('count() with a where map', 1, Db::count('categories', ['id' => $id]));
T::is('in() operator', 1, Db::count('categories', ['id' => ['in', [$id, 999999]]]));
T::is('empty in() matches nothing', 0, Db::count('categories', ['id' => ['in', []]]));
T::is('range operator', 1, Db::count('categories', ['created_at' => ['range', Clock::inDays(-1), Clock::inDays(1)]]));

T::ok('compareAndSet wins once', Db::compareAndSet('categories', ['name' => 'Won'], ['id' => $id, 'name' => 'Renamed']));
T::ok('compareAndSet loses the second time', !Db::compareAndSet('categories', ['name' => 'Lost'], ['id' => $id, 'name' => 'Renamed']));
T::is('the winner is persisted', 'Won', Db::first('categories', ['id' => $id])['name']);

T::throws('UPDATE without WHERE throws', \Ch247Apps\Core\AppsException::class, function () {
    Db::update('categories', ['name' => 'x'], []);
});
T::throws('DELETE without WHERE throws', \Ch247Apps\Core\AppsException::class, function () {
    Db::delete('categories', []);
});
T::throws('an illegal identifier is rejected', \Ch247Apps\Core\AppsException::class, function () {
    Db::quoteIdentifier('categories; DROP TABLE x');
});

// SQL injection through a value is impossible because values are bound.
Db::insert('categories', [
    'name' => "Robert'); DROP TABLE categories;--", 'slug' => 'xkcd-' . bin2hex(random_bytes(3)),
    'active' => 1, 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
]);
T::ok('a hostile value is stored, not executed', Db::tableExists('categories'));
T::is('and read back verbatim', "Robert'); DROP TABLE categories;--",
    Db::first('categories', ['name' => "Robert'); DROP TABLE categories;--"])['name']);

$rolled = false;
try {
    Db::transaction(function () use (&$rolled) {
        Db::insert('categories', [
            'name' => 'Tx', 'slug' => 'tx-' . bin2hex(random_bytes(3)), 'active' => 1,
            'created_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        throw new RuntimeException('boom');
    });
} catch (RuntimeException $e) {
    $rolled = true;
}
T::ok('a failed transaction rolls back', $rolled);
T::is('nothing from the rolled-back transaction is visible', 0, Db::count('categories', ['name' => 'Tx']));

/* ---------------------------------------------------------- settings ---- */

section('Settings resolve overrides → environment → database → module fields → defaults');

T::is('the harness override wins', '1', (string) Settings::get('marketplace_enabled'));
T::is('a documented default is returned when unset', '/opt/cloudhost247/apps', Settings::get('project_root'));
T::is('int cast', 7, Settings::int('grace_period_days'));
T::ok('bool cast', Settings::bool('install_requires_paid_order'));
T::ok('bool cast of a false default', !Settings::bool('kubernetes_enabled'));
T::is('default admin role fallback matches the module configuration', 'staff', Settings::get('default_admin_role'));
T::is('unmapped WHMCS admins receive the staff role', Actor::ROLE_STAFF, Identity::adminRole(77));

putenv('CH247APPS_GRACE_PERIOD_DAYS=21');
Settings::resetOverrides();
Settings::override('encryption_key', Harness::ENCRYPTION_KEY);
T::is('an environment variable overrides the default', 21, Settings::int('grace_period_days'));
putenv('CH247APPS_GRACE_PERIOD_DAYS');

Settings::set('grace_period_days', '10', 'admin:1');
T::is('a stored setting is read back', 10, Settings::int('grace_period_days'));
Settings::override('grace_period_days', '5');
T::is('a runtime override wins over the stored value', 5, Settings::int('grace_period_days'));
Settings::resetOverrides();
Settings::override('encryption_key', Harness::ENCRYPTION_KEY);
T::is('clearing the override returns to the stored value', 10, Settings::int('grace_period_days'));
T::throws('a secret cannot be stored in the database', ConfigurationException::class, function () {
    Settings::set('encryption_key', 'not-allowed');
});
T::throws('the signing key cannot be stored either', ConfigurationException::class, function () {
    Settings::set('agent_signing_key', 'not-allowed');
});

T::is('a missing subsystem requirement is reported', ['acme_email'], Settings::missingRequirements('ssl'));
Settings::override('acme_email', 'noc@cloudhost247.test');
T::is('and clears once it is configured', [], Settings::missingRequirements('ssl'));
T::ok('crypto requirements are met by the harness key', Settings::missingRequirements('crypto') === []);
T::is('a subsystem with no requirements reports none', [], Settings::missingRequirements('nope'));

/* ------------------------------------------------------------ crypto ---- */

section('Crypto seals, authenticates and rotates');

$sealed = Crypto::seal('ssh-ed25519 AAAA private key material', 'server_credentials.ssh_key');
T::ok('a sealed value is not the plaintext', strpos($sealed['ciphertext'], 'AAAAC3Nza') === false);
T::is('the key version is recorded', Crypto::CURRENT_KEY_VERSION, $sealed['key_version']);
T::is('decryption returns the plaintext', 'ssh-ed25519 AAAA private key material',
    Crypto::decrypt($sealed['ciphertext'], 'server_credentials.ssh_key'));
T::is('decrypting with the wrong context fails', null,
    Crypto::tryDecrypt($sealed['ciphertext'], 'environment.value'));

$tampered = $sealed['ciphertext'];
$tampered[strlen($tampered) - 3] = $tampered[strlen($tampered) - 3] === 'A' ? 'B' : 'A';
T::throws('a tampered ciphertext is rejected', \Ch247Apps\Core\AppsException::class, function () use ($tampered) {
    Crypto::decrypt($tampered, 'server_credentials.ssh_key');
});

$index = Crypto::blindIndex('Agent-Key-1', 'agents.secret');
T::is('blind indexes are stable', $index, Crypto::blindIndex('agent-key-1', 'agents.secret'));
T::isnt('blind indexes are context bound', $index, Crypto::blindIndex('agent-key-1', 'other.context'));
T::is('blind indexes do not reveal the value', null, Crypto::blindIndex('', 'agents.secret'));
$keyedFingerprint = Crypto::keyedFingerprint('low-entropy-secret', 'api-idempotency');
T::is('keyed fingerprints are stable for idempotent retries', $keyedFingerprint,
    Crypto::keyedFingerprint('low-entropy-secret', 'api-idempotency'));
T::isnt('keyed fingerprints are separated by context', $keyedFingerprint,
    Crypto::keyedFingerprint('low-entropy-secret', 'other-purpose'));
T::isnt('keyed fingerprints distinguish different secrets', $keyedFingerprint,
    Crypto::keyedFingerprint('different-secret', 'api-idempotency'));

$signature = Crypto::signMessage('shared-secret', 'POST', '/agent/v1/docker/up', '{"a":1}', 1700000000, 'nonce-1');
T::ok('a signature verifies', Crypto::verifySignature($signature, 'shared-secret', 'POST', '/agent/v1/docker/up', '{"a":1}', 1700000000, 'nonce-1'));
T::ok('a changed body breaks the signature', !Crypto::verifySignature($signature, 'shared-secret', 'POST', '/agent/v1/docker/up', '{"a":2}', 1700000000, 'nonce-1'));
T::ok('a changed path breaks the signature', !Crypto::verifySignature($signature, 'shared-secret', 'POST', '/agent/v1/docker/down', '{"a":1}', 1700000000, 'nonce-1'));
T::ok('a changed timestamp breaks the signature', !Crypto::verifySignature($signature, 'shared-secret', 'POST', '/agent/v1/docker/up', '{"a":1}', 1700000001, 'nonce-1'));
T::ok('a wrong secret breaks the signature', !Crypto::verifySignature($signature, 'other-secret', 'POST', '/agent/v1/docker/up', '{"a":1}', 1700000000, 'nonce-1'));

T::isnt('random tokens differ', Crypto::randomToken(), Crypto::randomToken());
T::is('masked output never contains the secret', 'sk' . str_repeat('•', 12), Str::mask('sk-live-abcdef', 2));
T::notContains('masked output hides the tail', 'abcdef', Str::mask('sk-live-abcdef', 2));

/* -------------------------------------------------------------- rbac ---- */

section('RBAC is enforced server-side for every role');

$customer = Actor::customer(42, 'Ada Obi');
$staff = Actor::admin(2, Actor::ROLE_STAFF, 'Support');
$admin = Actor::admin(3, Actor::ROLE_ADMIN, 'Ops');
$super = Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Root');
$system = Actor::system('Worker');
$agent = Actor::agent(7, 3, 'agent-node-1');
$guest = Actor::guest();

T::ok('a customer may install applications', $customer->can(Rbac::APP_INSTALL));
T::ok('a customer may manage their own installation', $customer->can(Rbac::INSTALL_STOP));
T::ok('a customer may not register servers', !$customer->can(Rbac::SERVER_MANAGE));
T::ok('a customer may not publish applications', !$customer->can(Rbac::APP_PUBLISH));
T::ok('a customer may not read the audit log', !$customer->can(Rbac::AUDIT_VIEW));
T::ok('a customer may browse panel catalog metadata', $customer->can(Rbac::PANEL_CATALOG_VIEW));
T::ok('a customer may not manage panel catalog records', !$customer->can(Rbac::PANEL_CATALOG_MANAGE));

T::ok('staff may read deployments', $staff->can(Rbac::DEPLOYMENT_VIEW_ALL));
T::ok('staff may not manage servers', !$staff->can(Rbac::SERVER_MANAGE));
T::ok('staff may not write server credentials', !$staff->can(Rbac::SERVER_CREDENTIAL_WRITE));
T::ok('staff may not publish', !$staff->can(Rbac::APP_PUBLISH));
T::ok('staff may not change settings', !$staff->can(Rbac::SETTINGS_MANAGE));
T::ok('staff may browse panel metadata', $staff->can(Rbac::PANEL_CATALOG_VIEW));
T::ok('staff may not edit panel metadata', !$staff->can(Rbac::PANEL_CATALOG_MANAGE));

T::ok('an admin may manage servers', $admin->can(Rbac::SERVER_MANAGE));
T::ok('an admin may publish applications', $admin->can(Rbac::APP_PUBLISH));
T::ok('an admin may manage panel catalog records', $admin->can(Rbac::PANEL_CATALOG_MANAGE));
T::ok('an admin may not change RBAC', !$admin->can(Rbac::RBAC_MANAGE));
T::ok('an admin may not change platform settings', !$admin->can(Rbac::SETTINGS_MANAGE));

T::ok('a super admin may change settings', $super->can(Rbac::SETTINGS_MANAGE));
T::ok('a super admin may rotate credentials', $super->can(Rbac::SERVER_CREDENTIAL_ROTATE));
T::ok('a super admin may manage panel catalog records', $super->can(Rbac::PANEL_CATALOG_MANAGE));

T::ok('the system actor may drive deployments', $system->can(Rbac::DEPLOYMENT_RETRY));
T::ok('the system actor may not publish', !$system->can(Rbac::APP_PUBLISH));
T::ok('the system actor may not rotate credentials', !$system->can(Rbac::SERVER_CREDENTIAL_ROTATE));
T::ok('the system actor may not change settings', !$system->can(Rbac::SETTINGS_MANAGE));

T::ok('an agent may report metrics', $agent->can(Rbac::INSTALL_METRICS_VIEW));
T::ok('an agent may not manage servers', !$agent->can(Rbac::SERVER_MANAGE));
T::ok('an agent may not delete installations', !$agent->can(Rbac::INSTALL_DELETE));

T::ok('a guest may browse the catalog', $guest->can(Rbac::APP_VIEW));
T::ok('a guest may browse published panel metadata', $guest->can(Rbac::PANEL_CATALOG_VIEW));
T::ok('a guest may not manage the panel catalog', !$guest->can(Rbac::PANEL_CATALOG_MANAGE));
T::ok('a guest may not install', !$guest->can(Rbac::APP_INSTALL));
T::ok('a guest may not view anything else', !$guest->can(Rbac::DEPLOYMENT_VIEW_ALL));

T::throws('assert() throws for a denied permission', AuthorizationException::class, function () use ($customer) {
    Rbac::assert($customer, Rbac::SERVER_MANAGE);
});

Rbac::setGrant(Actor::ROLE_STAFF, Rbac::SERVER_MANAGE, true);
T::ok('a granted permission takes effect immediately', $staff->can(Rbac::SERVER_MANAGE));
Rbac::setGrant(Actor::ROLE_STAFF, Rbac::SERVER_MANAGE, false);
T::ok('revoking takes effect immediately', !$staff->can(Rbac::SERVER_MANAGE));
T::throws('granting an unknown permission is rejected', ValidationException::class, function () {
    Rbac::setGrant(Actor::ROLE_STAFF, 'not.a.permission', true);
});
T::throws('machine roles are not editable', ValidationException::class, function () {
    Rbac::setGrant(Actor::ROLE_SYSTEM, Rbac::SERVER_MANAGE, true);
});

$other = Actor::customer(99);
T::ok('a customer owns their own resource', $customer->assertOwns(42));
T::throws('a customer cannot touch another account', AuthorizationException::class, function () use ($customer) {
    $customer->assertOwns(99);
});
T::ok('an admin may act on any resource', $admin->assertOwns(99));

/* ---------------------------------------------------------- identity ---- */

section('Identity is resolved from server state, never from input');

Identity::reset();
T::ok('an unauthenticated visitor is a guest', Identity::current()->isGuest());

$_SESSION = ['uid' => 42];
Identity::reset();
$resolved = Identity::current();
T::ok('a client session resolves to a customer', $resolved->isCustomer());
T::is('with the session client id', 42, $resolved->clientId);

$_SESSION = ['adminid' => 1];
Settings::override('bootstrap_admin_id', '1');
Identity::reset();
T::is('the bootstrap admin is a super admin', Actor::ROLE_SUPER_ADMIN, Identity::current()->role);

$_SESSION = ['adminid' => 77];
Identity::reset();
T::is('an unmapped admin gets the read-only staff role', Actor::ROLE_STAFF, Identity::current()->role);

Settings::set('role_admins_admin', '77', 'system');
Settings::flush();
Identity::reset();
T::is('a mapped admin gets the configured role', Actor::ROLE_ADMIN, Identity::current()->role);

$_SESSION = ['uid' => 42];
$token = 'ch247_test_token_' . bin2hex(random_bytes(8));
Db::insert('api_tokens', [
    'name' => 'CI token', 'token_hash' => hash('sha256', $token), 'token_prefix' => substr($token, 0, 8),
    'actor_type' => 'customer', 'actor_id' => 42, 'actor_label' => 'Ada Obi', 'scopes' => null,
    'ip_allowlist' => '', 'active' => 1, 'request_count' => 0,
    'created_at' => Clock::now(), 'updated_at' => Clock::now(),
]);
$tokenActor = Identity::fromApiToken($token);
T::ok('a bearer token resolves to its principal', $tokenActor && $tokenActor->isCustomer());
T::is('the token plaintext is never stored', 0, Db::count('api_tokens', ['token_hash' => $token]));
T::is('an unknown token resolves to nothing', null, Identity::fromApiToken('wrong-token'));

Db::insert('api_tokens', [
    'name' => 'Expired', 'token_hash' => hash('sha256', 'expired-token'), 'token_prefix' => 'expired-',
    'actor_type' => 'customer', 'actor_id' => 42, 'actor_label' => 'Ada', 'scopes' => null,
    'ip_allowlist' => '', 'active' => 1, 'request_count' => 0, 'expires_at' => Clock::inDays(-1),
    'created_at' => Clock::now(), 'updated_at' => Clock::now(),
]);
T::is('an expired token is refused', null, Identity::fromApiToken('expired-token'));
Identity::reset();

/* ------------------------------------------------------------- audit ---- */

section('Audit entries are hash chained and tamper-evident');

Audit::resetHead();
$auditActor = Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Root Admin');
Audit::record($auditActor, Audit::SERVER_ADDED, [
    'resource_type' => 'server', 'resource_id' => 1, 'metadata' => ['hostname' => 'node-1'],
]);
Audit::record($auditActor, Audit::SERVER_CREDENTIAL_ROTATED, [
    'resource_type' => 'server', 'resource_id' => 1, 'metadata' => ['type' => 'ssh_key'],
]);
$chain = Audit::verifyChain();
T::ok('the chain verifies', $chain['valid']);
T::is('two entries were chained', 2, $chain['checked']);

// Editing a row after the fact must break the chain.
Db::run('UPDATE ' . Db::quoteIdentifier(Db::t('audit_logs')) . ' SET action = ? WHERE id = ?',
    ['SERVER_DELETED', 1]);
$broken = Audit::verifyChain();
T::ok('tampering breaks the chain', !$broken['valid']);
T::is('and points at the edited row', 1, $broken['broken_at']);

$secretAudit = Audit::record($auditActor, 'TEST_REDACTION', ['metadata' => ['password' => 'hunter2', 'note' => 'safe']]);
$stored = Db::first('audit_logs', ['id' => $secretAudit]);
T::notContains('a secret in audit metadata is redacted', 'hunter2', $stored['metadata']);
T::contains('non-secret metadata survives', 'safe', $stored['metadata']);

/* ------------------------------------------------------- idempotency ---- */

section('Idempotency prevents duplicate operations');

$runs = 0;
$first = Idempotency::run('deployment.install', 'key-1', ['application' => 'n8n'], function () use (&$runs) {
    $runs++;
    return ['installation_id' => 55];
});
$second = Idempotency::run('deployment.install', 'key-1', ['application' => 'n8n'], function () use (&$runs) {
    $runs++;
    return ['installation_id' => 99];
});
T::is('the operation ran once', 1, $runs);
T::ok('the retry did not re-execute', $second['replayed']);
T::is('the retry replayed the original result', 55, $second['result']['installation_id']);

T::throws('the same key with a different payload is a conflict', ConflictException::class, function () {
    Idempotency::run('deployment.install', 'key-1', ['application' => 'nextcloud'], function () {
        return ['installation_id' => 1];
    });
});

T::throws('a missing idempotency key is rejected', ValidationException::class, function () {
    Idempotency::run('deployment.install', '   ', [], function () {
        return [];
    });
});

$failed = 0;
try {
    Idempotency::run('deployment.install', 'key-fail', ['x' => 1], function () use (&$failed) {
        $failed++;
        throw new RuntimeException('transient failure');
    });
} catch (RuntimeException $e) {
    // expected
}
Clock::travel(120);
$retry = Idempotency::run('deployment.install', 'key-fail', ['x' => 1], function () use (&$failed) {
    $failed++;
    return ['ok' => true];
});
T::is('a failed key may be retried', 2, $failed);
T::ok('and then succeeds', $retry['result']['ok']);

$derived = Idempotency::deriveKey('install', ['client' => 42, 'app' => 'n8n', 'plan' => 3]);
T::is('a derived key is deterministic regardless of order', $derived,
    Idempotency::deriveKey('install', ['plan' => 3, 'app' => 'n8n', 'client' => 42]));

/* ------------------------------------------------------ rate limiting ---- */

section('Rate limiting protects mutating endpoints');

RateLimiter::resetConfiguration();
RateLimiter::configure(['install.create' => [2, 60]]);
$hit1 = RateLimiter::hit('install.create', 'client:42');
T::is('the first request passes', 1, $hit1['remaining']);
RateLimiter::hit('install.create', 'client:42');
$limited = T::throws('the third request is limited', RateLimitException::class, function () {
    RateLimiter::hit('install.create', 'client:42');
});
T::ok('the error carries a retry hint', $limited && isset($limited->context()['retry_after']));
T::ok('another principal is unaffected', RateLimiter::hit('install.create', 'client:43'));
RateLimiter::resetConfiguration();
Harness::relaxRateLimits();

/* --------------------------------------------------------- validation ---- */

section('Validation collects every error at once');

$validator = Validator::make([
    'name' => '  My App  ',
    'slug' => 'My-App!',
    'domain' => 'not a domain',
    'cpu' => 'two',
    'environment' => ['TZ' => 'Africa/Lagos', 'BAD KEY' => 'x'],
]);
$values = $validator->required('name')->string('name', 100)
    ->optional('slug')->slug('slug')
    ->optional('domain')->domain('domain')
    ->optional('cpu', 1)->integer('cpu', 1, 64)
    ->arrayField('environment')->environment()
    ->errors();
T::ok('an invalid slug is reported', isset($values['slug']));
T::ok('an invalid domain is reported', isset($values['domain']));
T::ok('a non-numeric cpu is reported', isset($values['cpu']));
T::ok('an invalid environment key is reported', isset($values['environment']));
T::is('the trimmed name is valid', 'My App', $validator->value('name'));

$thrown = T::throws('validate() throws with the error map', ValidationException::class, function () use ($validator) {
    $validator->validate();
});
T::is('the exception carries a 422 status', 422, $thrown ? $thrown->status() : 0);

$clean = Validator::make(['domain' => 'app.customer.com', 'count' => '5'])->optional('domain')->domain('domain')
    ->optional('count')->integer('count', 1, 10)->validate();
T::is('a valid domain passes', 'app.customer.com', $clean['domain']);
T::is('a numeric string is cast', 5, $clean['count']);

/* ---------------------------------------------------------------- yaml ---- */

section('The YAML subset parser reads manifests faithfully');

$document = Yaml::parse(<<<'YAML'
# a comment
schema: 1
id: demo
name: "Demo App"
summary: 'Single quoted: it''s fine'
tags: [automation, ci]
requirements:
  cpu_min: 2
  memory_min_mb: 4096
  storage_min_mb: 20G
services:
  app:
    image: vendor/demo:1.2.3
    port: 8080
    primary: true
    command: ["sh", "-c", "echo hi"]
    environment:
      KEY: ${GENERATED}
  db:
    image: postgres:16
    internal: true
environment:
  required:
    - key: API_KEY
      description: The upstream API key.
      secret: true
  optional:
    - TZ
description: |
  Line one
  Line two
healthcheck:
  type: http
  path: /health
  expected_status: [200, 302]
YAML);

T::is('an integer scalar', 1, $document['schema']);
T::is('a double-quoted scalar', 'Demo App', $document['name']);
T::is("a single-quoted scalar keeps its apostrophe", "Single quoted: it's fine", $document['summary']);
T::is('a flow sequence', ['automation', 'ci'], $document['tags']);
T::is('a nested mapping', 2, $document['requirements']['cpu_min']);
T::is('a boolean', true, $document['services']['app']['primary']);
T::is('a port stays an int', 8080, $document['services']['app']['port']);
T::is('a nested flow sequence', ['sh', '-c', 'echo hi'], $document['services']['app']['command']);
T::is('a ${VARIABLE} reference is preserved verbatim', '${GENERATED}', $document['services']['app']['environment']['KEY']);
T::is('a list of mappings', 'API_KEY', $document['environment']['required'][0]['key']);
T::is('a shorthand list item', 'TZ', $document['environment']['optional'][0]);
T::is('a literal block scalar', "Line one\nLine two\n", $document['description']);
T::is('a flow list of ints', [200, 302], $document['healthcheck']['expected_status']);

T::throws('tabs are rejected', ValidationException::class, function () {
    Yaml::parse("a:\n\tb: 1\n");
});
T::throws('anchors are rejected', ValidationException::class, function () {
    Yaml::parse("a: &anchor 1\nb: *anchor\n");
});
T::throws('an unterminated quote is rejected', ValidationException::class, function () {
    Yaml::parse('a: "unterminated');
});
$roundTrip = Yaml::parse(Yaml::emit($document));
T::is('emitting and re-parsing round-trips', $document['id'], $roundTrip['id']);
T::is('nested values round-trip', 8080, $roundTrip['services']['app']['port']);

/* -------------------------------------------------------------- events ---- */

section('The event stream persists before dispatch');

$received = [];
Events::listen(Events::DEPLOYMENT_STEP_DONE, function ($event) use (&$received) {
    $received[] = $event;
});
$eventId = Events::emit(Events::DEPLOYMENT_STEP_DONE, ['step' => 'pull_image', 'token' => 'secret-value'],
    ['installation_id' => 12, 'deployment_id' => 34]);
T::ok('the event was persisted', $eventId > 0);
T::is('the listener fired once', 1, count($received));
T::is('the payload is redacted in storage', '[redacted]',
    Db::first('events', ['id' => $eventId])['payload'] ? json_decode(Db::first('events', ['id' => $eventId])['payload'], true)['token'] : null);

$since = Events::since(0, ['deployment_id' => 34]);
T::is('since() returns the scoped event', 1, count($since));
T::is('since() skips already-seen events', 0, count(Events::since($eventId, ['deployment_id' => 34])));

/* ----------------------------------------------------------------- str ---- */

section('String helpers normalise identifiers and hide secrets');

T::is('slug()', 'uptime-kuma', Str::slug('  Uptime Kuma!! '));
T::is('projectSlug() is docker safe', 'c123n8n', Str::projectSlug('C-123 n8n!'));
T::is('toMegabytes parses units', 20480, Str::toMegabytes('20G'));
T::is('toMegabytes passes ints through', 512, Str::toMegabytes(512));
T::is('toSeconds parses durations', 90, Str::toSeconds('90s'));
T::is('toSeconds parses minutes', 300, Str::toSeconds('5m'));
T::is('toMillicores parses cores', 2500, Str::toMillicores('2.5'));
T::is('toMillicores parses millicores', 500, Str::toMillicores('500m'));
T::ok('a reference has the right shape', preg_match('/^APP-[A-Z2-9]{8}$/', Str::reference('APP')) === 1);
T::ok('a uuid4 is valid', preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Str::uuid4()) === 1);
T::is('maskEmail keeps the domain', 'ad' . str_repeat('•', 3) . '@example.test', Str::maskEmail('ada@example.test'));
T::is('maskEmail keeps long local parts readable', 'gr' . str_repeat('•', 5) . '@example.test', Str::maskEmail('grace.h@example.test'));
T::is('e() escapes HTML', '&lt;script&gt;', Str::e('<script>'));

/* -------------------------------------------------------------- logger ---- */

section('Logging redacts secrets and stores context');

Logger::startCapture();
Logger::info('Deployment started', [
    'deployment_id' => 34, 'source' => 'worker',
    'authorization' => 'Bearer abc123', 'password' => 'hunter2',
    'environment' => ['DB_PASSWORD' => 'supersecret', 'TZ' => 'UTC'],
]);
$captured = Logger::captured();
Logger::stopCapture();
T::is('one line was captured', 1, count($captured));
T::is('the authorization header is redacted', '[redacted]', $captured[0]['context']['authorization']);
T::is('a nested secret is redacted', '[redacted]', $captured[0]['context']['environment']['DB_PASSWORD']);
T::is('a nested non-secret survives', 'UTC', $captured[0]['context']['environment']['TZ']);

$logRow = Db::first('logs', [], ['order' => 'id', 'dir' => 'desc']);
T::ok('the line reached the centralised log table', $logRow !== null);
T::is('with its deployment context', 34, $logRow ? (int) $logRow['deployment_id'] : 0);
T::notContains('and the stored line has no secret', 'hunter2', $logRow ? $logRow['context'] : '');

/* --------------------------------------------------------- host gateway ---- */

section('The host gateway is the only money/identity source');

T::ok('the harness installs the fake gateway', Harness::$gateway instanceof \Ch247Apps\Integration\FakeGateway);
T::ok('and the platform never believes it is live', !Gateway::isLive());
T::throws('write operations that need live WHMCS are refused in tests', ConfigurationException::class, function () {
    Gateway::requireLive();
});
$clientId = Harness::client(['firstname' => 'Grace', 'lastname' => 'Hopper']);
$client = Harness::$gateway->getClient($clientId);
T::is('a client record comes from the gateway', 'Grace', $client['firstname']);
T::is('an unknown client is null, never invented', null, Harness::$gateway->getClient(999999));

$order = Harness::$gateway->createOrder(['clientid' => $clientId, 'pid' => 1, 'billingcycle' => 'Monthly']);
T::ok('an order creates an invoice', $order['invoice_id'] > 0);
T::ok('an unpaid invoice is not paid', !Harness::$gateway->isInvoicePaid($order['invoice_id']));
Harness::$gateway->payInvoice($order['invoice_id']);
T::ok('a paid invoice is paid', Harness::$gateway->isInvoicePaid($order['invoice_id']));
T::ok('the gateway recorded the calls', Harness::$gateway->callCount('createOrder') === 1);

Harness::shutdown();
exit(T::summary());
