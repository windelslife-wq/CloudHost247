<?php
/**
 * CloudHost247 App Cloud — Docker adapter.
 *
 * Implements the deployment contract for `docker-compose` manifests by talking
 * to the server agent. The adapter never runs Docker itself and never holds a
 * socket: it renders artifacts (ComposeBuilder), sends them to the agent over a
 * signed channel, and translates what comes back into platform errors.
 *
 * Every step is idempotent, so a worker that crashes between steps can resume or
 * retry without duplicating containers, volumes or routes.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Catalog\Manifest;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Servers\AgentClient;

class DockerAdapter implements AdapterInterface
{
    const ENGINE = 'docker-compose';

    /** Step keys, in order, for a fresh install. */
    const INSTALL_STEPS = [
        'prepare_directories', 'write_artifacts', 'ensure_network', 'pull_images',
        'validate_compose', 'start_containers', 'apply_limits', 'configure_routing', 'health_check',
    ];

    const UPDATE_STEPS = ['write_artifacts', 'pull_images', 'validate_compose', 'start_containers',
        'apply_limits', 'configure_routing', 'health_check'];

    const START_STEPS = ['start_containers', 'health_check'];
    const STOP_STEPS = ['stop_containers'];
    const RESTART_STEPS = ['restart_containers', 'health_check'];
    const DESTROY_STEPS = ['remove_containers', 'remove_routing', 'remove_artifacts', 'remove_directories'];
    const HEALTH_STEPS = ['health_check'];
    const SSL_STEPS = ['configure_routing'];
    const DOMAIN_STEPS = ['write_artifacts', 'start_containers', 'configure_routing', 'health_check'];

    /** @var Actor */
    private $actor;

    /** @var AgentClient */
    private $client;

    public function __construct(Actor $actor = null, AgentClient $client = null)
    {
        $this->actor = $actor ?: Actor::system('DockerAdapter');
        $this->client = $client ?: new AgentClient($this->actor);
    }

    public function name()
    {
        return 'docker';
    }

    public function engine()
    {
        return self::ENGINE;
    }

    public function supports(Manifest $manifest, array $server)
    {
        $engine = $manifest->engine();
        if ($engine !== self::ENGINE && $engine !== 'docker') {
            return false;
        }
        if (empty($server) || !(int) $server['docker_enabled']) {
            return false;
        }
        return in_array((string) $server['server_type'], ['vps', 'dedicated', 'shared'], true);
    }

    public function plan(DeploymentContext $context)
    {
        $keys = self::stepsFor((string) $context->action);
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
            'prepare_directories' => ['Create the deployment directory', false, true, 60],
            'write_artifacts' => ['Write compose file and environment', true, true, 60],
            'ensure_network' => ['Ensure the proxy network exists', false, false, 60],
            'pull_images' => ['Pull container images', false, false, 900],
            'validate_compose' => ['Validate the compose definition', false, false, 120],
            'start_containers' => ['Start the containers', true, true, 300],
            'apply_limits' => ['Apply CPU and memory limits', true, false, 120],
            'configure_routing' => ['Configure reverse proxy routing', true, true, 180],
            'health_check' => ['Wait for the health check', false, false, 300],
            'stop_containers' => ['Stop the containers', true, false, 180],
            'restart_containers' => ['Restart the containers', true, false, 180],
            'remove_containers' => ['Remove the containers', true, false, 300],
            'remove_routing' => ['Remove reverse proxy routing', true, false, 120],
            'remove_artifacts' => ['Remove compose file and environment', true, false, 60],
            'remove_directories' => ['Remove the deployment directory', true, false, 120],
        ];
        $entry = isset($meta[$key]) ? $meta[$key] : [$key, true, false, 300];
        return ['name' => $entry[0], 'reversible' => $entry[1], 'creates_resource' => $entry[2],
            'timeout' => $entry[3]];
    }

    /* ------------------------------------------------------------- execute */

    public function executeStep($key, DeploymentContext $context)
    {
        $artifacts = ComposeBuilder::build($context);
        $serverId = $context->serverId();
        $base = ['project' => $context->project, 'base_path' => $context->basePath];

        switch ((string) $key) {
            case 'prepare_directories':
                $result = $this->client->dispatch($serverId, AgentClient::OP_PREPARE_PATHS, [
                    'base_path' => $context->basePath,
                    'directories' => $artifacts['directories'],
                    'directory_mode' => '0750',
                    'owner' => Settings::string('apps_directory_owner', 'root'),
                    'customer_id' => $context->customerId(),
                ], $this->options($context, 'prepare_directories'));
                return $this->result($result, [
                    ['type' => 'directory', 'name' => $context->basePath,
                        'metadata' => ['directories' => $artifacts['directories']]],
                ], 'Deployment directory prepared.');

            case 'write_artifacts':
                $result = $this->client->dispatch($serverId, AgentClient::OP_WRITE_FILES, [
                    'base_path' => $context->basePath,
                    'files' => [
                        ['path' => 'compose.yaml', 'content' => $artifacts['compose'], 'mode' => '0640'],
                        // 0600: this file holds every secret the app uses.
                        ['path' => '.env', 'content' => $artifacts['env'], 'mode' => '0600'],
                    ],
                    'checksums' => [
                        'compose.yaml' => hash('sha256', $artifacts['compose']),
                        '.env' => hash('sha256', $artifacts['env']),
                    ],
                ], $this->options($context, 'write_artifacts'));
                return $this->result($result, [
                    ['type' => 'compose_project', 'name' => $context->project,
                        'metadata' => ['compose_sha256' => hash('sha256', $artifacts['compose']),
                            'services' => array_keys($artifacts['services']),
                            'manifest_hash' => $context->manifest->hash()]],
                ], 'Compose file and environment written.');

            case 'ensure_network':
                $result = $this->client->dispatch($serverId, AgentClient::OP_PREPARE_PATHS, [
                    'network' => $artifacts['network'],
                    'network_driver' => 'bridge',
                ], $this->options($context, 'ensure_network'));
                return $this->result($result, [], 'Proxy network ready.');

            case 'pull_images':
                $images = [];
                foreach ($artifacts['services'] as $name => $service) {
                    $images[] = ['service' => $name, 'image' => $service['image']];
                }
                $result = $this->client->dispatch($serverId, AgentClient::OP_PULL_IMAGES, [
                    'images' => $images,
                    'registry_auth' => $this->registryAuth($context),
                ], $this->options($context, 'pull_images', 900));
                return $this->result($result, [], 'Images pulled.');

            case 'validate_compose':
                $result = $this->client->dispatch($serverId, AgentClient::OP_COMPOSE_VALIDATE, $base,
                    $this->options($context, 'validate_compose'));
                return $this->result($result, [], 'Compose definition accepted.');

            case 'start_containers':
                $result = $this->client->dispatch($serverId, AgentClient::OP_COMPOSE_UP, array_merge($base, [
                    'remove_orphans' => true,
                    'wait_healthy' => false,
                    'network' => $artifacts['network'],
                ]), $this->options($context, 'start_containers'));
                $resources = [];
                foreach ($artifacts['services'] as $name => $service) {
                    $resources[] = ['type' => 'container',
                        'name' => $context->project . '-' . $name,
                        'metadata' => ['service' => $name, 'image' => $service['image'],
                            'port' => $service['port']]];
                }
                return $this->result($result, $resources, 'Containers started.');

            case 'apply_limits':
                $limits = [];
                foreach ($artifacts['limits'] as $name => $limit) {
                    $limits[] = ['service' => $name, 'container' => $context->project . '-' . $name,
                        'cpus' => $limit['limits']['cpus'], 'memory' => $limit['limits']['memory']];
                }
                $result = $this->client->dispatch($serverId, AgentClient::OP_APPLY_LIMITS, [
                    'project' => $context->project, 'limits' => $limits,
                ], $this->options($context, 'apply_limits'));
                return $this->result($result, [], 'Resource limits applied.');

            case 'configure_routing':
                $routers = [];
                foreach ($artifacts['traefik'] as $name => $routing) {
                    $routers[] = [
                        'service' => $name,
                        'router' => $routing['router'],
                        'rule' => $routing['rule'],
                        'entrypoints' => $routing['entrypoints'],
                        'tls' => $routing['tls'],
                        'cert_resolver' => $routing['cert_resolver'],
                        'domains' => $routing['domains'],
                        'port' => $routing['port'],
                    ];
                }
                $result = $this->client->dispatch($serverId, AgentClient::OP_CONFIGURE_ROUTING, [
                    'project' => $context->project,
                    'network' => $artifacts['network'],
                    'routers' => $routers,
                    'action' => 'apply',
                ], $this->options($context, 'configure_routing'));
                $resources = [];
                foreach ($routers as $router) {
                    $resources[] = ['type' => 'traefik_route', 'name' => $router['router'],
                        'metadata' => ['rule' => $router['rule'], 'domains' => $router['domains'],
                            'service' => $router['service']]];
                }
                return $this->result($result, $resources, 'Routing configured.');

            case 'health_check':
                $health = $context->manifest->healthcheck();
                $result = $this->client->dispatch($serverId, AgentClient::OP_HEALTH, array_merge($base, [
                    'service' => $context->manifest->primaryService(),
                    'type' => $health['type'],
                    'path' => $health['path'],
                    'port' => $health['port'],
                    'interval' => $health['interval'],
                    'retries' => $health['retries'],
                    'timeout' => $health['timeout'],
                ]), $this->options($context, 'health_check', Settings::int('health_check_timeout_seconds', 180)));
                return $this->result($result, [], 'Health check passed.');

            case 'stop_containers':
                $result = $this->client->dispatch($serverId, AgentClient::OP_STOP, $base,
                    $this->options($context, 'stop_containers'));
                return $this->result($result, [], 'Containers stopped.');

            case 'restart_containers':
                $result = $this->client->dispatch($serverId, AgentClient::OP_RESTART, $base,
                    $this->options($context, 'restart_containers'));
                return $this->result($result, [], 'Containers restarted.');

            case 'remove_containers':
                $result = $this->client->dispatch($serverId, AgentClient::OP_REMOVE, array_merge($base, [
                    'remove_volumes' => $this->removesData($context),
                    'remove_images' => false,
                    'remove_paths' => false,
                ]), $this->options($context, 'remove_containers'));
                return $this->result($result, [], 'Containers removed.');

            case 'remove_routing':
                $result = $this->client->dispatch($serverId, AgentClient::OP_CONFIGURE_ROUTING, [
                    'project' => $context->project, 'action' => 'remove',
                ], $this->options($context, 'remove_routing'));
                return $this->result($result, [], 'Routing removed.');

            case 'remove_artifacts':
                $result = $this->client->dispatch($serverId, AgentClient::OP_WRITE_FILES, [
                    'base_path' => $context->basePath,
                    'remove' => ['compose.yaml', '.env'],
                ], $this->options($context, 'remove_artifacts'));
                return $this->result($result, [], 'Artifacts removed.');

            case 'remove_directories':
                $result = $this->client->dispatch($serverId, AgentClient::OP_PREPARE_PATHS, [
                    'base_path' => $context->basePath,
                    'remove_paths' => true,
                    // Only ever remove this deployment's own tree, and only when
                    // the caller asked for the data to go.
                    'remove_data' => $this->removesData($context),
                ], $this->options($context, 'remove_directories'));
                return $this->result($result, [], 'Deployment directory removed.');

            default:
                throw new \Ch247Apps\Core\ValidationException('Unknown deployment step "' . $key . '".', [
                    'errors' => ['step' => 'Not implemented by the docker adapter'],
                ]);
        }
    }

    /**
     * Roll a step back. Compensation is deliberately blunt and safe: it asks the
     * agent to remove what this deployment created, and it never throws when the
     * resource is already gone (the ledger records the outcome either way).
     */
    public function rollbackStep($key, DeploymentContext $context)
    {
        $serverId = $context->serverId();
        $base = ['project' => $context->project, 'base_path' => $context->basePath];
        $removed = [];

        try {
            switch ((string) $key) {
                case 'start_containers':
                case 'stop_containers':
                case 'restart_containers':
                case 'remove_containers':
                    $this->client->dispatch($serverId, AgentClient::OP_REMOVE, array_merge($base, [
                        'remove_volumes' => false, 'remove_images' => false, 'remove_paths' => false,
                    ]), ['retries' => 1, 'timeout' => 300]);
                    $removed[] = 'containers';
                    break;

                case 'configure_routing':
                case 'remove_routing':
                    $this->client->dispatch($serverId, AgentClient::OP_CONFIGURE_ROUTING, [
                        'project' => $context->project, 'action' => 'remove',
                    ], ['retries' => 1, 'timeout' => 120]);
                    $removed[] = 'routes';
                    break;

                case 'write_artifacts':
                case 'remove_artifacts':
                    $this->client->dispatch($serverId, AgentClient::OP_WRITE_FILES, [
                        'base_path' => $context->basePath, 'remove' => ['compose.yaml', '.env'],
                    ], ['retries' => 1, 'timeout' => 60]);
                    $removed[] = 'artifacts';
                    break;

                case 'prepare_directories':
                case 'remove_directories':
                    $this->client->dispatch($serverId, AgentClient::OP_PREPARE_PATHS, [
                        'base_path' => $context->basePath, 'remove_paths' => true, 'remove_data' => false,
                    ], ['retries' => 1, 'timeout' => 120]);
                    $removed[] = 'directories';
                    break;

                case 'apply_limits':
                    // Limits disappear with the containers; nothing to compensate.
                    break;

                default:
                    break;
            }
        } catch (\Throwable $e) {
            // A failed compensation must not mask the original failure. It is
            // logged loudly and the resource is marked leaked for an operator.
            Logger::error('Rollback step failed; resource may need manual cleanup.', [
                'step' => $key, 'installation_id' => $context->installationId(),
                'server_id' => $serverId, 'error' => $e->getMessage(), 'source' => 'deployments',
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
            $result = $this->client->dispatch($context->serverId(), AgentClient::OP_HEALTH, [
                'project' => $context->project, 'base_path' => $context->basePath, 'probe' => false,
            ], ['retries' => 0, 'timeout' => 30]);
            $data = $result['data'];
            return [
                'state' => isset($data['state']) ? (string) $data['state'] : 'unknown',
                'services' => isset($data['services']) && is_array($data['services']) ? $data['services'] : [],
                'checked_at' => \Ch247Apps\Core\Clock::now(),
                'source' => 'agent',
            ];
        } catch (\Throwable $e) {
            // Unverifiable is reported as unknown — never as stopped or healthy.
            Logger::warning('Installation status could not be verified.', [
                'installation_id' => $context->installationId(), 'error' => $e->getMessage(),
                'source' => 'deployments',
            ]);
            return ['state' => 'unknown', 'services' => [], 'reason' => Str::clip($e->getMessage(), 200),
                'checked_at' => \Ch247Apps\Core\Clock::now(), 'source' => 'unavailable'];
        }
    }

    public function logs(DeploymentContext $context, $service = null, $lines = 200)
    {
        $result = $this->client->dispatch($context->serverId(), AgentClient::OP_LOGS, [
            'project' => $context->project, 'base_path' => $context->basePath,
            'service' => $service, 'lines' => max(1, min(5000, (int) $lines)),
            'timestamps' => true,
        ], ['retries' => 0, 'timeout' => 60]);
        $data = $result['data'];
        return [
            'lines' => isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [],
            'truncated' => !empty($data['truncated']),
            'service' => $service,
            'fetched_at' => \Ch247Apps\Core\Clock::now(),
        ];
    }

    public function health(DeploymentContext $context)
    {
        $health = $context->manifest->healthcheck();
        try {
            $result = $this->client->dispatch($context->serverId(), AgentClient::OP_HEALTH, [
                'project' => $context->project, 'base_path' => $context->basePath,
                'service' => $context->manifest->primaryService(),
                'type' => $health['type'], 'path' => $health['path'], 'port' => $health['port'],
                'timeout' => $health['timeout'], 'retries' => 1,
            ], ['retries' => 0, 'timeout' => Settings::int('health_check_timeout_seconds', 180)]);
            $data = $result['data'];
            return [
                'state' => isset($data['state']) && in_array($data['state'], ['healthy', 'unhealthy'], true)
                    ? $data['state'] : 'unknown',
                'message' => isset($data['message']) ? Str::clip((string) $data['message'], 255) : null,
                'attempts' => isset($data['attempts']) ? (int) $data['attempts'] : null,
                'checked_at' => \Ch247Apps\Core\Clock::now(),
            ];
        } catch (\Throwable $e) {
            return ['state' => 'unknown', 'message' => Str::clip($e->getMessage(), 255),
                'checked_at' => \Ch247Apps\Core\Clock::now()];
        }
    }

    public function metrics(DeploymentContext $context)
    {
        try {
            $result = $this->client->dispatch($context->serverId(), AgentClient::OP_RESOURCE_USAGE, [
                'project' => $context->project,
            ], ['retries' => 0, 'timeout' => 30]);
            return $result['data'];
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
        return $this->client->dispatch($context->serverId(), AgentClient::OP_STOP, [
            'project' => $context->project, 'base_path' => $context->basePath,
        ], $this->options($context, 'stop'));
    }

    public function start(DeploymentContext $context)
    {
        return $this->client->dispatch($context->serverId(), AgentClient::OP_COMPOSE_UP, [
            'project' => $context->project, 'base_path' => $context->basePath, 'remove_orphans' => true,
        ], $this->options($context, 'start'));
    }

    public function restart(DeploymentContext $context)
    {
        return $this->client->dispatch($context->serverId(), AgentClient::OP_RESTART, [
            'project' => $context->project, 'base_path' => $context->basePath,
        ], $this->options($context, 'restart'));
    }

    public function destroy(DeploymentContext $context, $removeData = true)
    {
        $result = $this->client->dispatch($context->serverId(), AgentClient::OP_REMOVE, [
            'project' => $context->project,
            'base_path' => $context->basePath,
            'remove_volumes' => (bool) $removeData,
            'remove_images' => false,
            'remove_paths' => (bool) $removeData,
        ], $this->options($context, 'destroy', 600));

        // Record what the agent says it removed so the ledger can be closed out.
        $removed = [];
        $data = isset($result['data']) ? $result['data'] : [];
        foreach (['containers', 'volumes', 'networks', 'paths'] as $kind) {
            if (isset($data[$kind]) && is_array($data[$kind])) {
                foreach ($data[$kind] as $name) {
                    $removed[] = ['type' => $kind === 'paths' ? 'directory' : rtrim($kind, 's'),
                        'name' => (string) $name];
                }
            }
        }
        $result['removed'] = $removed;
        return $result;
    }

    /* ----------------------------------------------------------- internals */

    /** Dispatch options with the step's timeout and the deployment's request id. */
    private function options(DeploymentContext $context, $step, $timeout = null)
    {
        $meta = self::stepMeta($step);
        return [
            'timeout' => $timeout === null ? (int) $meta['timeout'] : (int) $timeout,
            'retries' => Settings::int('agent_request_retries', 2),
            'request_id' => $context->deploymentId
                ? 'DEP' . $context->deploymentId . '-' . $step : null,
            'idempotency_key' => $context->deploymentId
                ? 'dep:' . $context->deploymentId . ':' . $step : null,
        ];
    }

    private function removesData(DeploymentContext $context)
    {
        return !empty($context->options['remove_data']);
    }

    /** Private registry credentials, if the platform holds any for this manifest. */
    private function registryAuth(DeploymentContext $context)
    {
        if (!$context->server) {
            return null;
        }
        $vault = new \Ch247Apps\Servers\CredentialVault($this->actor);
        $token = $vault->revealFor((int) $context->server['id'],
            \Ch247Apps\Servers\CredentialVault::TYPE_REGISTRY_TOKEN);
        if ($token === null) {
            return null;
        }
        $username = Db::first('server_credentials', [
            'server_id' => (int) $context->server['id'],
            'credential_type' => \Ch247Apps\Servers\CredentialVault::TYPE_REGISTRY_TOKEN,
            'status' => 'active',
        ]);
        return [
            'username' => $username ? $username['username'] : null,
            'token' => $token,
            'registry' => Settings::string('private_registry_host', ''),
        ];
    }

    private function result(array $dispatch, array $resources, $message)
    {
        return [
            'output' => isset($dispatch['data']) ? $dispatch['data'] : [],
            'resources' => $resources,
            'message' => $message,
            'request_id' => isset($dispatch['request_id']) ? $dispatch['request_id'] : null,
            'attempts' => isset($dispatch['attempts']) ? (int) $dispatch['attempts'] : null,
            'duration_ms' => isset($dispatch['duration_ms']) ? (int) $dispatch['duration_ms'] : null,
        ];
    }
}
