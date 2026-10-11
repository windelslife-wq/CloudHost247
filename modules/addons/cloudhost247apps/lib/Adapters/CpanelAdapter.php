<?php
/**
 * CloudHost247 App Cloud — cPanel deployment adapter.
 *
 * Implements the deployment contract for `cpanel` manifests by provisioning
 * one dedicated cPanel account per installation through WHM API 1 and the
 * fixed UAPI bridge: the account, its primary domain and its MySQL databases.
 * That is the whole scope — the manifest validator says it plainly ("the
 * adapter will create the account, domain and database only"), and this class
 * does not pretend otherwise:
 *
 *   • application files are never uploaded (no verified Fileman write path),
 *   • declared script install modes fail closed naming the mode,
 *   • extra attached domains fail closed (primary domain only),
 *   • non-MySQL database engines fail closed,
 *   • health verifies provisioning (account present, unsuspended, domain
 *     bound) — it never probes HTTP, so there is no SSRF surface,
 *   • logs are unavailable through the implemented UAPI surface and fail
 *     closed instead of returning empty lines.
 *
 * Identity is deterministic: the cPanel username (8 chars), database names
 * and database users all derive from the installation, so a retried step
 * converges instead of duplicating. Database passwords are generated per
 * database and sealed into the installation environment as locked,
 * platform-managed values; the account password itself is never stored
 * because every later operation goes through the WHM API token.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Catalog\Manifest;
use Ch247Apps\ControlPanels\ControlPanelConnectionFactory;
use Ch247Apps\ControlPanels\CpanelWhmAdapter;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\CpanelException;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\HealthCheckException;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\EnvironmentService;
use Ch247Apps\Servers\ServerService;

class CpanelAdapter implements AdapterInterface
{
    const ENGINE = 'cpanel';

    /** Step keys, in order, for a fresh install. */
    const INSTALL_STEPS = [
        'ensure_account', 'ensure_domain', 'ensure_databases', 'install_application', 'health_check',
    ];

    const UPDATE_STEPS = ['ensure_account', 'ensure_databases', 'install_application', 'health_check'];

    const START_STEPS = ['start_account'];
    const STOP_STEPS = ['stop_account'];
    const RESTART_STEPS = ['restart_account', 'health_check'];
    const DESTROY_STEPS = ['remove_databases', 'remove_account'];
    const HEALTH_STEPS = ['health_check'];
    const SSL_STEPS = ['health_check'];
    const DOMAIN_STEPS = ['ensure_domain'];

    /** @var Actor */
    private $actor;

    /** @var CpanelWhmAdapter */
    private $panel;

    /** @var ControlPanelConnectionFactory */
    private $connections;

    /** @var EnvironmentService */
    private $environment;

    public function __construct(Actor $actor = null, CpanelWhmAdapter $panel = null,
        ControlPanelConnectionFactory $connections = null, EnvironmentService $environment = null)
    {
        $this->actor = $actor ?: Actor::system('CpanelAdapter');
        $this->panel = $panel ?: new CpanelWhmAdapter();
        $this->connections = $connections ?: new ControlPanelConnectionFactory($this->actor);
        $this->environment = $environment ?: new EnvironmentService($this->actor);
    }

    public function name()
    {
        return 'cpanel';
    }

    public function engine()
    {
        return self::ENGINE;
    }

    public function supports(Manifest $manifest, array $server)
    {
        if (!in_array($manifest->engine(), [self::ENGINE, 'whm', 'uapi'], true)) {
            return false;
        }
        if (!Settings::bool('cpanel_enabled', true)) {
            return false;
        }
        if (empty($server)) {
            return false;
        }
        if (!in_array((string) $server['server_type'], [ServerService::TYPE_CPANEL, ServerService::TYPE_SHARED], true)) {
            return false;
        }
        if (empty($server['cpanel_enabled'])) {
            return false;
        }
        // Mirror the connection factory: supports() must imply executable, and
        // the factory refuses disabled and maintenance servers at connect time.
        if (isset($server['status']) && in_array((string) $server['status'],
            [ServerService::STATUS_DISABLED, ServerService::STATUS_MAINTENANCE], true)) {
            return false;
        }
        return true;
    }

    public function plan(DeploymentContext $context)
    {
        $keys = self::stepsFor((string) $context->action);
        // Fail fast, before any step runs: the adapter serves the primary
        // domain only, and the wizard preview calls plan() too.
        if (in_array((string) $context->action, ['install', 'update', 'reinstall', 'domain_configure'], true)) {
            $this->assertSingleDomain($context);
        }
        $out = [];
        $order = 1;
        foreach ($keys as $key) {
            $meta = self::stepMeta($key);
            $out[] = [
                'key' => $key,
                'name' => $meta['name'],
                'step_order' => $order++,
                'reversible' => $meta['reversible'],
                'creates_resource' => $meta['creates_resource'],
                'timeout' => $meta['timeout'],
            ];
        }
        return $out;
    }

    public static function stepsFor($action)
    {
        switch (strtolower((string) $action)) {
            case 'update':
            case 'reinstall':
                return self::UPDATE_STEPS;
            case 'start':
                return self::START_STEPS;
            case 'stop':
                return self::STOP_STEPS;
            case 'restart':
                return self::RESTART_STEPS;
            case 'destroy':
            case 'uninstall':
            case 'delete':
                return self::DESTROY_STEPS;
            case 'healthcheck':
                return self::HEALTH_STEPS;
            case 'ssl':
            case 'ssl_provision':
                // TLS on cPanel is managed by AutoSSL; re-proving the account
                // serves its domain is the meaningful ssl action.
                return self::SSL_STEPS;
            case 'domain_configure':
                return self::DOMAIN_STEPS;
            default:
                return self::INSTALL_STEPS;
        }
    }

    public static function stepMeta($key)
    {
        $meta = [
            'ensure_account' => ['Ensure the cPanel account exists', true, true, 120],
            'ensure_domain' => ['Verify the primary domain binding', false, false, 60],
            'ensure_databases' => ['Ensure the MySQL databases exist', true, true, 180],
            'install_application' => ['Apply the declared cPanel install mode', false, false, 60],
            'health_check' => ['Verify the account is provisioned and serving', false, false, 300],
            'start_account' => ['Unsuspend the cPanel account', true, false, 180],
            'stop_account' => ['Suspend the cPanel account', true, false, 180],
            'restart_account' => ['Ensure the cPanel account is unsuspended', false, false, 180],
            'remove_databases' => ['Remove the provisioned databases', false, false, 180],
            'remove_account' => ['Remove or park the cPanel account', true, false, 300],
        ];
        $entry = isset($meta[$key]) ? $meta[$key] : [$key, true, false, 300];
        return ['name' => $entry[0], 'reversible' => $entry[1], 'creates_resource' => $entry[2],
            'timeout' => $entry[3]];
    }

    /* ------------------------------------------------------------- execute */

    public function executeStep($key, DeploymentContext $context)
    {
        $this->assertEnabled();
        $connection = $this->connections->forServer($context->serverId());
        $username = self::accountUsername($context);

        switch ((string) $key) {
            case 'ensure_account':
                return $this->stepEnsureAccount($connection, $context, $username);

            case 'ensure_domain':
                return $this->stepEnsureDomain($connection, $context, $username);

            case 'ensure_databases':
                return $this->stepEnsureDatabases($connection, $context, $username);

            case 'install_application':
                return $this->stepInstallApplication($context);

            case 'health_check':
                $health = $this->health($context);
                if ($health['state'] !== 'healthy') {
                    throw new HealthCheckException(
                        'The cPanel installation is not healthy: ' . $health['message'], [
                            'installation_id' => $context->installationId(),
                        ]
                    );
                }
                return $this->result(['health' => $health], [], 'Account verified healthy.');

            case 'start_account':
                $account = $this->panel->unsuspendAccount($connection, $username);
                return $this->result(['account' => $account['account'], 'changed' => $account['changed']],
                    [], $account['changed'] ? 'Account unsuspended.' : 'Account was already active.');

            case 'stop_account':
                $account = $this->panel->suspendAccount($connection, $username, $this->stopReason($context));
                return $this->result(['account' => $account['account'], 'changed' => $account['changed']],
                    [], $account['changed'] ? 'Account suspended.' : 'Account was already suspended.');

            case 'restart_account':
                // cPanel accounts have no restart primitive: restart ensures the
                // account is unsuspended and lets the following health_check
                // prove it. Rollback deliberately does nothing here — suspending
                // an account this step did not suspend would be wrong.
                $account = $this->panel->unsuspendAccount($connection, $username);
                return $this->result(['account' => $account['account'], 'changed' => $account['changed']],
                    [], 'Account active; health verification follows.');

            case 'remove_databases':
                return $this->stepRemoveDatabases($connection, $context, $username);

            case 'remove_account':
                return $this->stepRemoveAccount($connection, $context, $username);

            default:
                throw new ValidationException('Unknown deployment step "' . $key . '".', [
                    'errors' => ['step' => 'Not implemented by the cpanel adapter'],
                ]);
        }
    }

    /**
     * Roll a step back. Compensation is blunt and safe: databases that carry
     * this deployment's marker are deleted best-effort, accounts are suspended
     * (never terminated — termination is irreversible and rollback runs
     * automatically), and anything already gone is simply reported.
     */
    public function rollbackStep($key, DeploymentContext $context)
    {
        $username = self::accountUsername($context);
        $removed = [];

        try {
            $connection = $this->connections->forServer($context->serverId());
            switch ((string) $key) {
                case 'ensure_databases':
                case 'remove_databases':
                    foreach ($this->mysqlDatabases($context) as $index => $database) {
                        $names = $this->databaseNames($context, $username, $database, $index);
                        if (!$this->isProvisioned($context, $index)) {
                            continue;
                        }
                        try {
                            $this->panel->deleteDatabase($connection, $username, $names['short']);
                        } catch (CpanelException $e) {
                            // Absence and failure are indistinguishable on this
                            // vendor surface; the marker said it existed, so a
                            // failed delete is leaked, not assumed gone.
                            Logger::error('Rollback could not delete a cPanel database.', [
                                'installation_id' => $context->installationId(),
                                'database' => $names['full'], 'source' => 'deployments',
                            ]);
                            return ['removed' => $removed, 'leaked' => true,
                                'message' => 'Database ' . $names['full'] . ' may need manual cleanup.'];
                        }
                        $this->clearProvisioned($context, $index);
                        $removed[] = 'database ' . $names['full'];
                    }
                    break;

                case 'ensure_account':
                case 'start_account':
                case 'stop_account':
                    // Restore the prior resting state without destroying data:
                    // a step that (un)suspended an account is compensated by
                    // parking it suspended for an operator to review.
                    if ($this->panel->getAccount($connection, $username) !== null) {
                        $this->panel->suspendAccount($connection, $username, $this->stopReason($context));
                        $removed[] = 'account serving state';
                    }
                    break;

                case 'remove_account':
                    // Only the parking variant (remove_data off) is compensable:
                    // re-activate an account that was suspended, never one that
                    // was terminated.
                    if (empty($context->options['remove_data'])
                        && $this->panel->getAccount($connection, $username) !== null) {
                        $this->panel->unsuspendAccount($connection, $username);
                        $removed[] = 'account suspension';
                    }
                    break;

                default:
                    break;
            }
        } catch (\Throwable $e) {
            Logger::error('Rollback step failed; resource may need manual cleanup.', [
                'step' => $key, 'installation_id' => $context->installationId(),
                'server_id' => $context->serverId(), 'error' => $e->getMessage(), 'source' => 'deployments',
            ]);
            return ['removed' => [], 'message' => 'Compensation failed: ' . Str::clip($e->getMessage(), 200),
                'leaked' => true];
        }

        return ['removed' => $removed, 'message' => $removed ? 'Rolled back: ' . implode(', ', $removed) . '.'
            : 'Nothing to roll back.'];
    }

    /* ---------------------------------------------------------------- read */

    public function status(DeploymentContext $context)
    {
        try {
            $username = self::accountUsername($context);
            $connection = $this->connections->forServer($context->serverId());
            $account = $this->panel->getAccount($connection, $username);
            if ($account === null) {
                return ['state' => 'missing', 'username' => $username,
                    'checked_at' => Clock::now(), 'source' => 'whm'];
            }
            return [
                'state' => !empty($account['suspended']) ? 'suspended' : 'running',
                'username' => $username,
                'domain' => $account['domain'],
                'package' => $account['package'],
                'suspended' => (bool) $account['suspended'],
                'checked_at' => Clock::now(),
                'source' => 'whm',
            ];
        } catch (\Throwable $e) {
            Logger::warning('Installation status could not be verified.', [
                'installation_id' => $context->installationId(), 'error' => $e->getMessage(),
                'source' => 'deployments',
            ]);
            return ['state' => 'unknown', 'reason' => Str::clip($e->getMessage(), 200),
                'checked_at' => Clock::now(), 'source' => 'unavailable'];
        }
    }

    public function logs(DeploymentContext $context, $service = null, $lines = 200)
    {
        throw new ConfigurationException(
            'cPanel application logs are not available: the implemented UAPI surface covers account,'
            . ' domain and database operations only. Read logs in cPanel or over SSH.', [
                'error_code' => 'CPANEL_LOGS_UNAVAILABLE',
            ]
        );
    }

    public function health(DeploymentContext $context)
    {
        try {
            $username = self::accountUsername($context);
            $connection = $this->connections->forServer($context->serverId());
            $account = $this->panel->getAccount($connection, $username);
            if ($account === null) {
                return ['state' => 'unhealthy', 'message' => 'The cPanel account is missing.',
                    'username' => $username, 'checked_at' => Clock::now()];
            }
            if (!empty($account['suspended'])) {
                return ['state' => 'unhealthy', 'message' => 'The cPanel account is suspended.',
                    'username' => $username, 'checked_at' => Clock::now()];
            }
            $primary = $context->primaryDomain();
            if ($primary !== null && $account['domain'] !== strtolower($primary)) {
                return ['state' => 'unhealthy',
                    'message' => 'The cPanel account serves ' . $account['domain'] . ', not ' . $primary . '.',
                    'username' => $username, 'checked_at' => Clock::now()];
            }
            return ['state' => 'healthy', 'message' => 'The cPanel account is provisioned and unsuspended.',
                'username' => $username, 'domain' => $account['domain'], 'checked_at' => Clock::now()];
        } catch (\Throwable $e) {
            return ['state' => 'unknown', 'message' => Str::clip($e->getMessage(), 255),
                'checked_at' => Clock::now()];
        }
    }

    public function metrics(DeploymentContext $context)
    {
        $quotaEnabled = Settings::bool('cpanel_uapi_quota_usage_enabled', false);
        $bandwidthEnabled = Settings::bool('cpanel_uapi_bandwidth_usage_enabled', false);
        if (!$quotaEnabled && !$bandwidthEnabled) {
            return null;
        }
        try {
            $username = self::accountUsername($context);
            $connection = $this->connections->forServer($context->serverId());
            $account = $this->panel->getAccount($connection, $username);
            if ($account === null) {
                return null;
            }
            // The verified account binding, never caller-supplied state.
            $domain = $account['domain'];
            return [
                'quota' => $quotaEnabled ? $this->panel->getQuotaUsage($connection, $username, $domain) : null,
                'bandwidth' => $bandwidthEnabled
                    ? $this->panel->getBandwidthUsage($connection, $username, $domain) : null,
                'fetched_at' => Clock::now(),
            ];
        } catch (\Throwable $e) {
            Logger::warning('Installation metrics could not be collected.', [
                'installation_id' => $context->installationId(), 'error' => $e->getMessage(),
                'source' => 'deployments',
            ]);
            return null;
        }
    }

    public function stop(DeploymentContext $context)
    {
        $this->assertEnabled();
        $connection = $this->connections->forServer($context->serverId());
        return $this->panel->suspendAccount($connection, self::accountUsername($context),
            $this->stopReason($context));
    }

    public function start(DeploymentContext $context)
    {
        $this->assertEnabled();
        $connection = $this->connections->forServer($context->serverId());
        return $this->panel->unsuspendAccount($connection, self::accountUsername($context));
    }

    public function restart(DeploymentContext $context)
    {
        $this->assertEnabled();
        $connection = $this->connections->forServer($context->serverId());
        $account = $this->panel->unsuspendAccount($connection, self::accountUsername($context));
        return ['account' => $account, 'health' => $this->health($context)];
    }

    /**
     * Remove everything this deployment created.
     *
     * WHM account termination always removes files and databases with the
     * account, so $removeData=false cannot terminate: it suspends instead and
     * preserves everything for an operator to review.
     */
    public function destroy(DeploymentContext $context, $removeData = true)
    {
        $connection = $this->connections->forServer($context->serverId());
        $username = self::accountUsername($context);
        $removed = [];

        if ($removeData) {
            foreach ($this->mysqlDatabases($context) as $index => $database) {
                $names = $this->databaseNames($context, $username, $database, $index);
                if ($this->isProvisioned($context, $index)) {
                    try {
                        $this->panel->deleteDatabase($connection, $username, $names['short']);
                    } catch (CpanelException $e) {
                        // Convergent destroy: a database that is already gone
                        // must not fail the removal of everything else.
                        Logger::warning('Destroy could not confirm a cPanel database deletion.', [
                            'installation_id' => $context->installationId(),
                            'database' => $names['full'], 'source' => 'deployments',
                        ]);
                    }
                    $this->clearProvisioned($context, $index);
                    $removed[] = ['type' => 'database', 'name' => $names['full']];
                    $removed[] = ['type' => 'database_user', 'name' => $names['user_full']];
                }
                $this->clearDatabaseEnvironment($context, $index);
            }
            $result = $this->panel->terminateAccount($connection, $username, 'TERMINATE ' . $username);
            $removed[] = ['type' => 'cpanel_account', 'name' => $username];
            return ['terminated' => $result['terminated'], 'confirmed' => $result['confirmed'],
                'already_absent' => $result['already_absent'], 'removed' => $removed,
                'message' => 'cPanel account terminated with its files and databases.'];
        }

        $account = $this->panel->suspendAccount($connection, $username, $this->stopReason($context));
        return ['terminated' => false, 'suspended' => true, 'account' => $account['account'],
            'removed' => [], 'message' => 'cPanel account suspended; files and databases preserved.'];
    }

    /* ---------------------------------------------------------------- steps */

    private function stepEnsureAccount(array $connection, DeploymentContext $context, $username)
    {
        $domain = $context->primaryDomain();
        if ($domain === null || trim((string) $domain) === '') {
            throw new ValidationException('A primary domain is required before a cPanel account can be created.', [
                'error_code' => 'CPANEL_DOMAIN_REQUIRED',
            ]);
        }
        $package = $this->package($context);
        // The password is used for this create call only and never stored: all
        // later operations authenticate with the WHM API token.
        $created = $this->panel->createAccount($connection, [
            'username' => $username,
            'domain' => strtolower(trim((string) $domain)),
            'package' => $package,
            'password' => Crypto::randomToken(24),
            'contact_email' => null,
        ], 'install.' . $context->installationId() . '.ensure_account');
        $account = $created['account'];
        if (!empty($account['suspended'])) {
            // A replayed install must converge on serving, including one whose
            // previous attempt was parked suspended by rollback.
            $account = $this->panel->unsuspendAccount($connection, $username)['account'];
            Logger::warning('A replayed cPanel install unsuspended its account.', [
                'installation_id' => $context->installationId(), 'username' => $username,
                'source' => 'deployments',
            ]);
        }
        return $this->result(
            ['account' => $account, 'created' => $created['created'],
                'idempotent_replay' => $created['idempotent_replay']],
            [['type' => 'cpanel_account', 'name' => $username,
                'metadata' => ['domain' => $account['domain'], 'package' => $account['package'],
                    'created' => $created['created']]]],
            $created['created'] ? 'cPanel account created.' : 'cPanel account already existed and was verified.'
        );
    }

    private function stepEnsureDomain(array $connection, DeploymentContext $context, $username)
    {
        $this->assertSingleDomain($context);
        $account = $this->panel->getAccount($connection, $username);
        if ($account === null) {
            throw new CpanelException('The cPanel account is missing; run the install steps in order.', [
                'error_code' => 'CPANEL_ACCOUNT_MISSING',
            ]);
        }
        $primary = $context->primaryDomain();
        if ($primary !== null && $account['domain'] !== strtolower($primary)) {
            throw new CpanelException('The cPanel account serves "' . $account['domain']
                . '" but the installation expects "' . strtolower($primary) . '".', [
                'error_code' => 'CPANEL_ACCOUNT_CONFLICT',
            ]);
        }
        return $this->result(['account' => $account], [], 'Primary domain binding verified.');
    }

    private function stepEnsureDatabases(array $connection, DeploymentContext $context, $username)
    {
        $databases = $this->mysqlDatabases($context);
        if ($databases === []) {
            return $this->result(['databases' => []], [], 'No databases declared; nothing to provision.');
        }
        if (!Settings::bool('cpanel_mysql_enabled', false)) {
            throw new ConfigurationException(
                'MySQL provisioning is disabled: enable cpanel_mysql_enabled after the Mysql UAPI'
                . ' permissions and staging are validated.', [
                    'error_code' => 'CPANEL_MYSQL_DISABLED',
                ]
            );
        }
        $resources = [];
        $output = [];
        $ignored = [];
        foreach ($databases as $index => $database) {
            $names = $this->databaseNames($context, $username, $database, $index);
            // Convergent, not check-then-act: there is no verified list
            // operation, so a best-effort delete of the deterministic name
            // first guarantees create() starts from a known state.
            try {
                $this->panel->deleteDatabase($connection, $username, $names['short']);
            } catch (CpanelException $e) {
                // Absent already — the only state that lets create() proceed.
            }
            $this->panel->createDatabase($connection, $username, $names['short']);
            $password = Crypto::randomToken(24);
            try {
                $this->panel->createDatabaseUser($connection, $username, $names['user_short'], $password);
            } catch (CpanelException $e) {
                $this->recoverDatabaseUser($connection, $context, $username, $names, $e);
            }
            // The grant proves both the user and the database exist: this is
            // the read-back that confirms the whole sequence.
            $this->panel->grantDatabasePrivileges($connection, $username, $names['short'], $names['user_short']);
            $this->writeDatabaseEnvironment($context, $index, $names, $password);
            $this->markProvisioned($context, $index, $names);
            $resources[] = ['type' => 'database', 'name' => $names['full'],
                'metadata' => ['engine' => $database['engine'], 'manifest' => $database['name']]];
            $resources[] = ['type' => 'database_user', 'name' => $names['user_full'],
                'metadata' => ['database' => $names['full'], 'privileges' => 'ALL PRIVILEGES']];
            $output[] = $names['full'];
            if ((int) $database['size_mb'] > 0) {
                $ignored[] = $database['name'] . ': size_mb has no cPanel equivalent; quotas apply per account.';
            }
        }
        return $this->result(['databases' => $output, 'ignored' => $ignored], $resources,
            'MySQL databases provisioned.');
    }

    /**
     * Recover a failed database-user create.
     *
     * The grant probe succeeds only when the user already exists — which means
     * a previous attempt crashed between user creation and the grant, leaving
     * a user whose password nobody recorded. That state is reported with a
     * manual cleanup path, never papered over with a wrong password.
     */
    private function recoverDatabaseUser(array $connection, DeploymentContext $context, $username, array $names,
        CpanelException $original)
    {
        try {
            $this->panel->grantDatabasePrivileges($connection, $username, $names['short'], $names['user_short']);
        } catch (CpanelException $probeError) {
            throw $original;
        }
        throw new ConfigurationException(
            'The database user "' . $names['user_full'] . '" already exists from an incomplete attempt and its'
            . ' password was never recorded. Remove the user in cPanel (or terminate the account) and redeploy.', [
                'error_code' => 'CPANEL_DATABASE_USER_CONFLICT', 'user' => $names['user_full'],
            ]
        );
    }

    private function stepInstallApplication(DeploymentContext $context)
    {
        $options = $context->manifest->adapterOptions('cpanel');
        $mode = strtolower(trim((string) (isset($options['mode']) ? $options['mode'] : '')));
        if ($mode === '' || $mode === 'none' || $mode === 'account-only') {
            if ($mode === '' && !empty($options['script'])) {
                throw new ConfigurationException(
                    'The manifest declares cPanel script "' . Str::clip((string) $options['script'], 80)
                    . '" without an implemented install mode. Declare mode "none" for account, domain and'
                    . ' database provisioning only.', [
                        'error_code' => 'CPANEL_INSTALL_MODE_UNSUPPORTED',
                    ]
                );
            }
            return $this->result(['mode' => $mode === '' ? 'none' : $mode], [],
                'Account, domain and database ready; no application install declared.');
        }
        throw new ConfigurationException(
            'The cPanel install mode "' . Str::clip($mode, 80) . '" is not implemented. Declare mode "none" for'
            . ' account, domain and database provisioning only.', [
                'error_code' => 'CPANEL_INSTALL_MODE_UNSUPPORTED', 'mode' => $mode,
            ]
        );
    }

    private function stepRemoveDatabases(array $connection, DeploymentContext $context, $username)
    {
        $databases = $this->mysqlDatabases($context);
        if ($databases === []) {
            return $this->result(['databases' => []], [], 'No databases declared; nothing to remove.');
        }
        // Termination removes databases with the account, so a disabled Mysql
        // surface degrades to a documented skip here rather than a failure.
        if (!Settings::bool('cpanel_mysql_enabled', false)) {
            return $this->result(['databases' => [], 'skipped' => 'cpanel_mysql_enabled is off'],
                [], 'Database removal skipped (Mysql surface disabled); account termination covers it.');
        }
        $output = [];
        foreach ($databases as $index => $database) {
            $names = $this->databaseNames($context, $username, $database, $index);
            if (!$this->isProvisioned($context, $index)) {
                continue;
            }
            $this->panel->deleteDatabase($connection, $username, $names['short']);
            $this->clearProvisioned($context, $index);
            $this->clearDatabaseEnvironment($context, $index);
            $output[] = $names['full'];
        }
        return $this->result(['databases' => $output], [], 'Provisioned databases removed.');
    }

    private function stepRemoveAccount(array $connection, DeploymentContext $context, $username)
    {
        if (!empty($context->options['remove_data'])) {
            $result = $this->panel->terminateAccount($connection, $username, 'TERMINATE ' . $username);
            return $this->result($result, [], $result['already_absent']
                ? 'Account was already absent.' : 'Account terminated with its files and databases.');
        }
        $account = $this->panel->suspendAccount($connection, $username, $this->stopReason($context));
        return $this->result(['account' => $account['account']], [],
            'Account suspended; files and databases preserved (remove_data off).');
    }

    /* ----------------------------------------------------------- internals */

    private function assertEnabled()
    {
        if (!Settings::bool('cpanel_enabled', true)) {
            throw new ConfigurationException('The cPanel adapter is disabled on this platform.', [
                'error_code' => 'ADAPTER_DISABLED', 'adapter' => 'cpanel',
            ]);
        }
    }

    private function assertSingleDomain(DeploymentContext $context)
    {
        if (count($context->domains) > 1) {
            $extra = [];
            foreach (array_slice($context->domains, 1) as $domain) {
                $extra[] = $domain['domain'];
            }
            throw new ValidationException(
                'The cPanel adapter serves the primary domain only; detach or remove: '
                . implode(', ', $extra) . '.', [
                    'error_code' => 'CPANEL_MULTI_DOMAIN_UNSUPPORTED', 'domains' => $extra,
                ]
            );
        }
    }

    /**
     * The deterministic cPanel username for an installation: 8 lowercase chars.
     *
     * Eight characters is load-bearing, not cosmetic: it makes the account
     * name identical to every MySQL prefix variant cPanel derives from it
     * (first-8 on MySQL 5.6, full name elsewhere), so the platform's echoed
     * database names always match what the server created.
     */
    public static function accountUsername(DeploymentContext $context)
    {
        $seed = 'cpanel-account|' . $context->customerId() . '|' . $context->project
            . '|' . $context->installationId();
        return 'c' . substr(str_pad(base_convert(substr(sha1($seed), 0, 12), 16, 36), 7, '0', STR_PAD_LEFT), 0, 7);
    }

    /** Short deterministic database and user names ([a-z0-9], underscore-free). */
    private function databaseNames(DeploymentContext $context, $username, array $database, $index)
    {
        $seed = 'cpanel-database|' . $context->customerId() . '|' . $context->project
            . '|' . $context->installationId() . '|' . $database['name'];
        $short = 'db' . substr(str_pad(base_convert(substr(sha1($seed . '|db'), 0, 12), 16, 36), 6, '0',
            STR_PAD_LEFT), 0, 6);
        $userShort = substr(str_pad(base_convert(substr(sha1($seed . '|user'), 0, 12), 16, 36), 7, '0',
            STR_PAD_LEFT), 0, 7);
        return [
            'short' => $short,
            'full' => $username . '_' . $short,
            'user_short' => $userShort,
            'user_full' => $username . '_' . $userShort,
            'index' => (int) $index,
        ];
    }

    /** MySQL-family databases, sorted for deterministic numbering. Anything else fails closed. */
    private function mysqlDatabases(DeploymentContext $context)
    {
        $databases = $context->manifest->databases();
        usort($databases, function ($a, $b) {
            return strcmp((string) $a['name'], (string) $b['name']);
        });
        foreach ($databases as $database) {
            if (!in_array(strtolower((string) $database['engine']), ['mysql', 'mariadb'], true)) {
                throw new ConfigurationException(
                    'Database engine "' . $database['engine'] . '" is not supported on cPanel; declare mysql'
                    . ' or mariadb.', [
                        'error_code' => 'CPANEL_DATABASE_ENGINE_UNSUPPORTED', 'engine' => $database['engine'],
                    ]
                );
            }
        }
        return $databases;
    }

    private function package(DeploymentContext $context)
    {
        $options = $context->manifest->adapterOptions('cpanel');
        $package = trim((string) (isset($options['package']) ? $options['package'] : ''));
        if ($package === '') {
            $package = trim(Settings::string('cpanel_default_package', ''));
        }
        if ($package === '' || strlen($package) > 80 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $package)) {
            throw new ConfigurationException(
                'A WHM package is required: declare adapters.cpanel.package in the manifest or set'
                . ' cpanel_default_package.', [
                    'error_code' => 'CPANEL_PACKAGE_REQUIRED',
                ]
            );
        }
        return $package;
    }

    private function stopReason(DeploymentContext $context)
    {
        return 'Stopped from App Cloud (installation #' . $context->installationId() . ')';
    }

    /** Persisted provisioning outputs: sealed, locked, platform-managed. */
    private function writeDatabaseEnvironment(DeploymentContext $context, $index, array $names, $password)
    {
        $prefix = 'CPANEL_DB_' . ((int) $index + 1) . '_';
        $installationId = $context->installationId();
        $this->environment->write($installationId, $prefix . 'NAME', $names['full'], $this->envMeta(false,
            'Provisioned cPanel database name.'));
        $this->environment->write($installationId, $prefix . 'USER', $names['user_full'], $this->envMeta(false,
            'Provisioned cPanel database user.'));
        $this->environment->write($installationId, $prefix . 'PASSWORD', $password, $this->envMeta(true,
            'Provisioned cPanel database password.'));
        $this->environment->write($installationId, $prefix . 'HOST', 'localhost', $this->envMeta(false,
            'Provisioned cPanel database host.'));
        $this->environment->write($installationId, $prefix . 'PORT', '3306', $this->envMeta(false,
            'Provisioned cPanel database port.'));
    }

    private function envMeta($secret, $description)
    {
        return ['platform' => true, 'is_secret' => $secret, 'source' => EnvironmentService::SOURCE_PLATFORM,
            'locked' => true, 'description' => $description];
    }

    private function markerKey($index)
    {
        return 'CPANEL_DB_' . ((int) $index + 1) . '_PROVISIONED';
    }

    private function markProvisioned(DeploymentContext $context, $index, array $names)
    {
        $this->environment->write($context->installationId(), $this->markerKey($index), $names['full'],
            $this->envMeta(false, 'The adapter provisioned this database.'));
    }

    private function isProvisioned(DeploymentContext $context, $index)
    {
        return Db::first('environment', [
            'installation_id' => $context->installationId(), 'env_key' => $this->markerKey($index),
        ]) !== null;
    }

    private function clearProvisioned(DeploymentContext $context, $index)
    {
        Db::delete('environment', [
            'installation_id' => $context->installationId(), 'env_key' => $this->markerKey($index),
        ]);
    }

    private function clearDatabaseEnvironment(DeploymentContext $context, $index)
    {
        $prefix = 'CPANEL_DB_' . ((int) $index + 1) . '_';
        foreach (['NAME', 'USER', 'PASSWORD', 'HOST', 'PORT'] as $suffix) {
            Db::delete('environment', [
                'installation_id' => $context->installationId(), 'env_key' => $prefix . $suffix,
            ]);
        }
    }

    private function result(array $output, array $resources, $message)
    {
        return ['output' => $output, 'resources' => $resources, 'message' => $message];
    }
}
