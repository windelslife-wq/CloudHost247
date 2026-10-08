<?php
/** Suite 08 — separate cPanel/WHM control-panel adapter boundary. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Adapters\AdapterFactory;
use Ch247Apps\ControlPanels\ControlPanelAdapterRegistry;
use Ch247Apps\ControlPanels\ControlPanelConnectionFactory;
use Ch247Apps\ControlPanels\ControlPanelService;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\CpanelException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\PanelAdapterUnavailableException;
use Ch247Apps\Core\RetryableCpanelException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Servers\ServerService;

Harness::boot();
Harness::relaxRateLimits();

function whmResponse(array $data = [], $result = 1)
{
    return [
        'status' => 200,
        'body' => json_encode(['data' => $data, 'metadata' => ['result' => $result, 'reason' => 'OK']]),
    ];
}

function whmAccount($username, $domain, $package, $suspended = 0)
{
    return [
        'user' => $username,
        'domain' => $domain,
        'plan' => $package,
        'suspended' => $suspended,
        // WHM extensions may return extra values; the adapter must not surface them.
        'password' => 'WHM-ECHO-PRIVATE-ACCOUNT-SECRET',
    ];
}

$whmToken = 'WHM-API-TOKEN-PRIVATE-123456';
$serverAdmin = Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN);
$cpanelServer = (new ServerService($serverAdmin))->register([
    'name' => 'WHM adapter test host', 'hostname' => 'whm.example.test',
    'ip_address' => '198.51.100.65', 'server_type' => 'cpanel',
    'credentials' => ['whm_api_token' => ['secret' => $whmToken, 'username' => 'root']],
]);
$connection = (new ControlPanelConnectionFactory(Actor::system('WHM adapter test worker')))
    ->forServer((int) $cpanelServer['id']);

section('Control-panel adapters remain separate from application deployment adapters');

ControlPanelAdapterRegistry::reset();
$panelCatalog = ControlPanelAdapterRegistry::boot();
$cpanelEntry = null;
foreach ($panelCatalog as $entry) {
    if ($entry['panel_key'] === 'cpanel_whm') {
        $cpanelEntry = $entry;
    }
}
T::ok('a dedicated cPanel & WHM adapter is code-registered', $cpanelEntry !== null && $cpanelEntry['code_registered']);
T::notContains('registry does not claim operational availability', 'available', json_encode($cpanelEntry));
T::notContains('registry does not claim panel installation', 'panel.install', json_encode($cpanelEntry['capabilities']));
T::notContains('registry does not claim license activation', 'license.activate', json_encode($cpanelEntry['capabilities']));
$adapter = ControlPanelAdapterRegistry::forPanel('cpanel_whm');
T::is('the cPanel adapter has its own stable key', 'cpanel_whm', $adapter->key());
T::ok('WHM account lookup is implemented', !empty($adapter->capabilities()['account.get']));
T::ok('WHM account creation is implemented', !empty($adapter->capabilities()['account.create']));
T::throws('license activation is not an implemented capability', PanelAdapterUnavailableException::class, function () use ($adapter) {
    ControlPanelAdapterRegistry::assertSupports($adapter, ['license.activate']);
});
T::throws('unimplemented Plesk does not get an adapter', PanelAdapterUnavailableException::class, function () {
    ControlPanelAdapterRegistry::forPanel('plesk');
});
T::ok('the application deployment factory still has no cPanel application adapter',
    empty(AdapterFactory::availability()['cpanel']['installed']));
T::ok('customers cannot manage WHM accounts', !Rbac::allows(Actor::customer(42), Rbac::PANEL_ACCOUNT_MANAGE));
T::ok('read-only staff may view but not manage panel accounts',
    Rbac::allows(Actor::admin(4, Actor::ROLE_STAFF), Rbac::PANEL_ACCOUNT_VIEW)
        && !Rbac::allows(Actor::admin(4, Actor::ROLE_STAFF), Rbac::PANEL_ACCOUNT_MANAGE));
T::ok('admins can manage but cannot terminate accounts',
    Rbac::allows(Actor::admin(3, Actor::ROLE_ADMIN), Rbac::PANEL_ACCOUNT_MANAGE)
        && !Rbac::allows(Actor::admin(3, Actor::ROLE_ADMIN), Rbac::PANEL_ACCOUNT_TERMINATE));
T::ok('super-admins and approved workers can execute termination jobs',
    Rbac::allows(Actor::admin(1, Actor::ROLE_SUPER_ADMIN), Rbac::PANEL_ACCOUNT_TERMINATE)
        && Rbac::allows(Actor::system('test worker'), Rbac::PANEL_ACCOUNT_TERMINATE));

section('Connection material is resolved from the existing server registry and credential vault');
T::is('the resolved endpoint comes from the registered server row', 'whm.example.test', $connection['hostname']);
T::is('the server type is checked from the registry', 'cpanel', $connection['server_type']);
T::is('the token username comes from the vault metadata', 'root', $connection['api_username']);
T::is('the token is revealed only to the worker adapter connection', $whmToken, $connection['api_token']);
T::notContains('server presentation never includes the plaintext token', $whmToken,
    json_encode((new ServerService($serverAdmin))->present((int) $cpanelServer['id'])));
$storedCredential = Db::first('server_credentials', [
    'server_id' => (int) $cpanelServer['id'], 'credential_type' => 'whm_api_token',
]);
T::notContains('the credential database row contains ciphertext rather than the token', $whmToken,
    (string) $storedCredential['encrypted_secret']);
T::throws('a customer cannot resolve control-panel credentials', AuthorizationException::class, function () use ($cpanelServer) {
    (new ControlPanelConnectionFactory(Actor::guest()))->forServer((int) $cpanelServer['id']);
});
$vpsServer = (new ServerService($serverAdmin))->register([
    'name' => 'Not a WHM host', 'hostname' => 'not-whm.example.test',
    'ip_address' => '198.51.100.66', 'server_type' => 'vps',
]);
T::throws('a VPS cannot be used as a cPanel endpoint', ConfigurationException::class, function () use ($vpsServer) {
    (new ControlPanelConnectionFactory(Actor::system('WHM adapter test worker')))->forServer((int) $vpsServer['id']);
});
$originalCpanelStatus = (string) Db::first('servers', ['id' => (int) $cpanelServer['id']])['status'];
Db::update('servers', ['status' => ServerService::STATUS_DISABLED], ['id' => (int) $cpanelServer['id']]);
T::throws('an operator-disabled WHM server cannot resolve credentials', ConfigurationException::class, function () use ($cpanelServer) {
    (new ControlPanelConnectionFactory(Actor::system('WHM adapter test worker')))->forServer((int) $cpanelServer['id']);
});
Db::update('servers', ['status' => $originalCpanelStatus], ['id' => (int) $cpanelServer['id']]);

section('WHM API calls use the registered host, encrypted-token contract, and verified TLS');

$rawOptions = null;
Http::setClientFake(function ($method, $url, $options) use (&$rawOptions) {
    $rawOptions = $options;
    return whmResponse(['version' => '11.126.0.99']);
});
$verified = $adapter->verify($connection);
T::is('verification reflects the version returned by WHM', '11.126.0.99', $verified['version']);
T::is('verification is only true after the WHM API response passed its result check', true, $verified['verified']);
$calls = Http::clientCalls();
T::is('the version probe is a GET', 'GET', $calls[0]['method']);
T::ok('it uses the fixed HTTPS WHM API endpoint', strpos($calls[0]['url'], 'https://whm.example.test:2087/json-api/version?') === 0);
T::contains('the API version is fixed by the adapter', 'api.version=1', $calls[0]['url']);
T::is('TLS verification is mandatory', true, $rawOptions['verify_tls']);
T::is('WHM auth uses the token header, not a URL parameter',
    'whm root:WHM-API-TOKEN-PRIVATE-123456', $rawOptions['headers']['Authorization']);
T::notContains('the token is not in the request URL', $connection['api_token'], $calls[0]['url']);
T::notContains('the token is redacted from recorded request options', $connection['api_token'], json_encode($calls));
T::is('sensitive WHM response bodies are not retained in the shared HTTP recorder', '[redacted]', $calls[0]['response']['body']);

section('Invalid configuration and negative WHM responses fail closed');

T::throws('a WHM credential cannot be sent to an unregistered server type', ConfigurationException::class,
    function () use ($adapter, $connection) {
        $invalid = $connection;
        $invalid['server_type'] = 'vps';
        $adapter->verify($invalid);
    });
T::throws('a URL cannot be smuggled in as a server hostname', ConfigurationException::class,
    function () use ($adapter, $connection) {
        $invalid = $connection;
        $invalid['hostname'] = 'https://attacker.example.test/path';
        $adapter->verify($invalid);
    });
T::throws('the adapter rejects the wrong credential type', ConfigurationException::class,
    function () use ($adapter, $connection) {
        $invalid = $connection;
        $invalid['credential_type'] = 'ssh_key';
        $adapter->verify($invalid);
    });
T::throws('a false string cannot enable a disabled cPanel server', ConfigurationException::class,
    function () use ($adapter, $connection) {
        $invalid = $connection;
        $invalid['cpanel_enabled'] = 'false';
        $adapter->verify($invalid);
    });
T::throws('header-injection characters in a token are rejected', ConfigurationException::class,
    function () use ($adapter, $connection) {
        $invalid = $connection;
        $invalid['api_token'] = "token-with-newline\r\nInjected: yes";
        $adapter->verify($invalid);
    });

Http::setClientFake(function () {
    return whmResponse([], 0);
});
$apiRejected = T::throws('an API-level rejection is not treated as success', CpanelException::class,
    function () use ($adapter, $connection) {
        $adapter->verify($connection);
    });
T::is('the rejection has a stable code', 'CPANEL_API_REJECTED', $apiRejected->errorCode());
T::ok('a permanent API rejection is not blindly retried', !$apiRejected->isRetryable());

Http::setClientFake(function () {
    return ['status' => 503, 'body' => 'maintenance window'];
});
$temporarilyUnavailable = T::throws('a WHM 5xx response fails safely', RetryableCpanelException::class,
    function () use ($adapter, $connection) {
        $adapter->verify($connection);
    });
T::ok('a WHM service failure is explicitly retryable', $temporarilyUnavailable->isRetryable());
T::notContains('provider error text is not copied into the exception', 'maintenance window',
    $temporarilyUnavailable->getMessage());

Http::setClientFake(function () {
    return ['status' => 200, 'body' => '{not-json'];
});
$invalidResponse = T::throws('malformed WHM JSON is not treated as success', CpanelException::class,
    function () use ($adapter, $connection) {
        $adapter->verify($connection);
    });
T::is('malformed JSON has a stable code', 'CPANEL_RESPONSE_INVALID', $invalidResponse->errorCode());

section('Account reads are allowlisted and missing records are explicit');

Http::setClientFake(function ($method, $url) {
    if (strpos($url, '/json-api/listaccts?') === false) {
        return ['status' => 404, 'body' => 'unexpected endpoint'];
    }
    return whmResponse(['acct' => [whmAccount('acctuser', 'example.test', 'starter', 0)]]);
});
$account = $adapter->getAccount($connection, 'acctuser');
T::is('the account username is normalized', 'acctuser', $account['username']);
T::is('the account package is normalized', 'starter', $account['package']);
T::is('the account status is sourced from WHM', false, $account['suspended']);
T::notContains('unrecognized account fields are not surfaced', 'password', json_encode($account));
T::contains('account lookup uses a parameterized user search', 'searchtype=user', Http::clientCalls()[0]['url']);
T::contains('account lookup searches by the validated username', 'search=acctuser', Http::clientCalls()[0]['url']);

Http::setClientFake(function () {
    return whmResponse(['acct' => []]);
});
T::is('a valid empty WHM list means confirmed absence', null, $adapter->getAccount($connection, 'missinguser'));

Http::setClientFake(function () {
    return whmResponse(['unexpected' => []]);
});
$badList = T::throws('a malformed account list is not interpreted as absence', CpanelException::class,
    function () use ($adapter, $connection) {
        $adapter->getAccount($connection, 'missinguser');
    });
T::is('malformed lists have a stable code', 'CPANEL_ACCOUNT_RESPONSE_INVALID', $badList->errorCode());

section('WHM account creation reconciles retries and never leaks the password');

$accounts = [];
$passwordSeenByFake = null;
$whmStateFake = function ($method, $url, $options) use (&$accounts, &$passwordSeenByFake) {
    $endpoint = basename((string) parse_url($url, PHP_URL_PATH));
    if ($endpoint === 'listaccts') {
        // GET arguments are in the URL, not the form body.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $username = isset($query['search']) ? (string) $query['search'] : '';
        $rows = [];
        foreach ($accounts as $candidate) {
            if ($candidate['user'] === $username) {
                $rows[] = $candidate;
            }
        }
        return whmResponse(['acct' => $rows]);
    }
    if ($endpoint === 'createacct') {
        $passwordSeenByFake = isset($options['form']['password']) ? $options['form']['password'] : null;
        $accounts[] = whmAccount($options['form']['username'], $options['form']['domain'], $options['form']['plan'], 0);
        return whmResponse(['user' => $options['form']['username']]);
    }
    if ($endpoint === 'suspendacct') {
        foreach ($accounts as &$candidate) {
            if ($candidate['user'] === $options['form']['username']) {
                $candidate['suspended'] = 1;
            }
        }
        unset($candidate);
        return whmResponse(['status' => 1]);
    }
    if ($endpoint === 'unsuspendacct') {
        foreach ($accounts as &$candidate) {
            if ($candidate['user'] === $options['form']['username']) {
                $candidate['suspended'] = 0;
            }
        }
        unset($candidate);
        return whmResponse(['status' => 1]);
    }
    if ($endpoint === 'removeacct') {
        $accounts = array_values(array_filter($accounts, function ($candidate) use ($options) {
            return $candidate['user'] !== $options['form']['username'];
        }));
        return whmResponse(['status' => 1]);
    }
    return ['status' => 404, 'body' => 'unexpected endpoint'];
};

Http::setClientFake($whmStateFake);
$created = $adapter->createAccount($connection, [
    'username' => 'acctuser', 'domain' => 'example.test', 'package' => 'starter',
    'password' => 'Strong-Private-Password-123!', 'contact_email' => 'owner@example.test',
], 'cpanel-create-service-4001');
T::is('account create is confirmed from a subsequent WHM read', true, $created['created']);
T::is('newly created account starts unsuspended according to WHM', false, $created['account']['suspended']);
T::is('the password was sent only to the WHM request', 'Strong-Private-Password-123!', $passwordSeenByFake);
T::notContains('the adapter result never returns the account password', 'Strong-Private-Password-123!', json_encode($created));
$calls = Http::clientCalls();
T::is('create performs a lookup, mutation, and read-back', 3, count($calls));
T::notContains('the password is redacted from recorded form data', 'Strong-Private-Password-123!', json_encode($calls));
T::notContains('the WHM token is redacted from every recorded request', $connection['api_token'], json_encode($calls));
T::is('the remote response recorder redacts account information', '[redacted]', $calls[1]['response']['body']);

Http::setClientFake($whmStateFake);
$replayed = $adapter->createAccount($connection, [
    'username' => 'acctuser', 'domain' => 'example.test', 'package' => 'starter',
    'password' => 'Another-Private-Password-456!',
], 'cpanel-create-service-4001');
T::is('a retry recovers the matching WHM account without creating another', true, $replayed['idempotent_replay']);
T::is('retry reconciliation does not call createacct', 1, count(Http::clientCalls()));
T::throws('a username collision with a different domain is a conflict', CpanelException::class, function () use ($adapter, $connection) {
    $adapter->createAccount($connection, [
        'username' => 'acctuser', 'domain' => 'other.example.test', 'package' => 'starter',
        'password' => 'Strong-Private-Password-123!',
    ], 'cpanel-create-service-4002');
});
Http::setClientFake($whmStateFake);
T::throws('a weak cPanel password is rejected before any network call', ValidationException::class, function () use ($adapter, $connection) {
    $adapter->createAccount($connection, [
        'username' => 'newuser', 'domain' => 'new.example.test', 'package' => 'starter', 'password' => 'weak',
    ], 'cpanel-create-service-4003');
});
T::is('invalid account input sends no request to WHM', 0, count(Http::clientCalls()));

section('Suspend, resume, and termination require WHM read-back confirmation');

Http::setClientFake($whmStateFake);
$suspended = $adapter->suspendAccount($connection, 'acctuser', 'WHMCS service suspended');
T::is('suspension is confirmed by WHM read-back', true, $suspended['confirmed']);
T::is('suspended account state is true', true, $suspended['account']['suspended']);
$mutationCalls = array_filter(Http::clientCalls(), function ($call) {
    return strpos($call['url'], '/json-api/suspendacct?') !== false;
});
T::is('the suspend action invokes only the supported WHM endpoint once', 1, count($mutationCalls));

Http::setClientFake($whmStateFake);
$stillSuspended = $adapter->suspendAccount($connection, 'acctuser', 'Repeated suspension');
T::is('repeated suspension is idempotent', false, $stillSuspended['changed']);
T::is('an already-suspended retry does not send a second mutation', 1, count(Http::clientCalls()));

Http::setClientFake($whmStateFake);
$unsuspended = $adapter->unsuspendAccount($connection, 'acctuser');
T::is('unsuspension is confirmed by WHM read-back', false, $unsuspended['account']['suspended']);
T::ok('the account state change reports confirmed', $unsuspended['confirmed']);

Http::setClientFake($whmStateFake);
T::throws('termination refuses a mismatched confirmation phrase', ValidationException::class, function () use ($adapter, $connection) {
    $adapter->terminateAccount($connection, 'acctuser', 'yes');
});
T::is('mismatched termination does not contact WHM', 0, count(Http::clientCalls()));

Http::setClientFake($whmStateFake);
$terminated = $adapter->terminateAccount($connection, 'acctuser', 'TERMINATE acctuser');
T::is('termination is reported only after WHM confirms absence', true, $terminated['confirmed']);
T::is('terminated account is no longer present', null, $adapter->getAccount($connection, 'acctuser'));

section('The worker service audits WHM mutations without recording account secrets');

$panelService = new ControlPanelService(Actor::system('WHM audited worker'));
Http::setClientFake(function () {
    return whmResponse(['version' => '11.126.0.99']);
});
$serviceVerification = $panelService->verifyServer((int) $cpanelServer['id']);
T::is('service verification is backed by WHM', true, $serviceVerification['verified']);
$verifiedCredential = Db::first('server_credentials', ['id' => (int) $storedCredential['id']]);
T::is('successful WHM verification updates the existing vault metadata', 1, (int) $verifiedCredential['verified']);
T::ok('successful verification is hash-chain audited',
    Db::count('audit_logs', ['action' => 'CPANEL_SERVER_VERIFIED']) > 0);

Http::setClientFake($whmStateFake);
$servicePassword = 'Service-Only-Password-67890!';
$serviceCreate = $panelService->createAccount((int) $cpanelServer['id'], [
    'username' => 'svcuser', 'domain' => 'service.example.test', 'package' => 'starter',
    'password' => $servicePassword,
], 'cpanel-create-service-4101');
T::is('audited worker service returns only a WHM-confirmed account', true, $serviceCreate['created']);
T::ok('the write-ahead audit entry exists',
    Db::count('audit_logs', ['action' => 'CPANEL_ACCOUNT_CREATE_REQUESTED']) > 0);
T::ok('the completion audit entry exists only after read-back',
    Db::count('audit_logs', ['action' => 'CPANEL_ACCOUNT_CREATE_CONFIRMED']) > 0);
T::notContains('the account password never reaches audit or log records', $servicePassword,
    json_encode(Db::fetch('audit_logs', [], ['order' => 'id', 'dir' => 'desc', 'limit' => 20])));
T::notContains('the WHM token never reaches audit or log records', $whmToken,
    json_encode(Db::fetch('audit_logs', [], ['order' => 'id', 'dir' => 'desc', 'limit' => 20])));
T::throws('an admin cannot terminate from the HTTP-facing service context', AuthorizationException::class, function () use ($cpanelServer) {
    (new ControlPanelService(Harness::adminActor(3, Actor::ROLE_ADMIN)))
        ->terminateAccount((int) $cpanelServer['id'], 'svcuser', 'TERMINATE svcuser');
});

exit(T::summary());
