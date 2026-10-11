<?php
/**
 * CloudHost247 App Cloud — Kubernetes adapter.
 *
 * Implements the deployment contract for `kubernetes` manifests by talking to
 * the cluster API from the worker with the vault-held kubeconfig. It renders
 * objects (KubernetesManifests), applies them with server-side apply, and
 * translates the API's Status answers into platform errors.
 *
 * Safety rules this class exists to enforce:
 *
 *   • every object carries the installation selector labels; deletes always go
 *     through label selectors or verified names, never bare collections,
 *   • a namespace is deleted only when it is platform-derived AND its labels
 *     verify — a declared foreign namespace is never created with labels and
 *     never deleted,
 *   • PersistentVolumeClaims are deleted only when the caller asked for data
 *     removal, and never by rollback,
 *   • the adapter is gated by the kubernetes_enabled kill-switch (default
 *     off): scheduling refuses while it is off, and actuation fails closed.
 *
 * Every step is idempotent (server-side apply converges), so a worker that
 * crashes between steps can resume or retry without duplicating objects.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Catalog\Manifest;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\HealthCheckException;
use Ch247Apps\Core\KubernetesException;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Servers\CredentialVault;

class KubernetesAdapter implements AdapterInterface
{
    const ENGINE = 'kubernetes';

    /** Step keys, in order, for a fresh install. */
    const INSTALL_STEPS = [
        'ensure_namespace', 'apply_secrets', 'apply_workloads', 'apply_networking', 'wait_ready', 'health_check',
    ];

    const UPDATE_STEPS = [
        'ensure_namespace', 'apply_secrets', 'apply_workloads', 'apply_networking', 'wait_ready', 'health_check',
    ];

    const START_STEPS = ['scale_up', 'wait_ready'];
    const STOP_STEPS = ['scale_down'];
    const RESTART_STEPS = ['rollout_restart', 'wait_ready'];
    const DESTROY_STEPS = ['delete_networking', 'delete_workloads', 'delete_secrets', 'delete_namespace'];
    const HEALTH_STEPS = ['health_check'];
    const SSL_STEPS = ['apply_networking', 'health_check'];
    const DOMAIN_STEPS = ['apply_networking', 'health_check'];

    /** @var Actor */
    private $actor;

    /** @var KubernetesClient */
    private $client;

    /** @var CredentialVault */
    private $vault;

    public function __construct(Actor $actor = null, KubernetesClient $client = null,
        CredentialVault $vault = null)
    {
        $this->actor = $actor ?: Actor::system('KubernetesAdapter');
        $this->client = $client ?: new KubernetesClient($this->actor);
        $this->vault = $vault ?: new CredentialVault($this->actor);
    }

    public function name()
    {
        return 'kubernetes';
    }

    public function engine()
    {
        return self::ENGINE;
    }

    public function supports(Manifest $manifest, array $server)
    {
        if ($manifest->engine() !== self::ENGINE) {
            return false;
        }
        if (!Settings::bool('kubernetes_enabled', false)) {
            return false;
        }
        if (empty($server)) {
            return false;
        }
        if ((string) $server['server_type'] !== 'kubernetes' || empty($server['kubernetes_enabled'])) {
            return false;
        }
        if (isset($server['status']) && in_array((string) $server['status'], ['disabled', 'maintenance'], true)) {
            return false;
        }
        return true;
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
            'ensure_namespace' => ['Ensure the namespace exists', false, true, 60],
            'apply_secrets' => ['Apply the environment and pull secrets', true, false, 120],
            'apply_workloads' => ['Apply volumes and Deployments', true, true, 300],
            'apply_networking' => ['Apply Services and the Ingress', true, true, 180],
            'wait_ready' => ['Wait for the rollout to become available', false, false, 300],
            'health_check' => ['Verify the workloads are available', false, false, 120],
            'scale_up' => ['Scale the workloads to their declared replicas', true, false, 180],
            'scale_down' => ['Scale the workloads to zero', true, false, 180],
            'rollout_restart' => ['Trigger a rolling restart', true, false, 180],
            'delete_networking' => ['Delete the Ingress and Services', false, false, 180],
            'delete_workloads' => ['Delete the Deployments and claimed volumes', false, false, 300],
            'delete_secrets' => ['Delete the installation secrets', false, false, 120],
            'delete_namespace' => ['Delete the installation namespace', false, false, 300],
        ];
        $entry = isset($meta[$key]) ? $meta[$key] : [$key, true, false, 300];
        return ['name' => $entry[0], 'reversible' => $entry[1], 'creates_resource' => $entry[2],
            'timeout' => $entry[3]];
    }

    /* ------------------------------------------------------------- execute */

    public function executeStep($key, DeploymentContext $context)
    {
        $this->assertEnabled();
        $artifacts = $this->artifacts($context);
        $connection = $this->client->connect($context->serverId());
        $namespace = $artifacts['namespace']['name'];

        switch ((string) $key) {
            case 'ensure_namespace':
                return $this->stepEnsureNamespace($connection, $artifacts);

            case 'apply_secrets':
                return $this->stepApplySecrets($connection, $artifacts);

            case 'apply_workloads':
                return $this->stepApplyWorkloads($connection, $artifacts);

            case 'apply_networking':
                return $this->stepApplyNetworking($connection, $artifacts);

            case 'wait_ready':
                return $this->stepWaitReady($connection, $artifacts, (int) self::stepMeta('wait_ready')['timeout']);

            case 'health_check':
                $health = $this->health($context);
                if ($health['state'] !== 'healthy') {
                    throw new HealthCheckException(
                        'The Kubernetes installation is not healthy: ' . $health['message'], [
                            'installation_id' => $context->installationId(),
                        ]
                    );
                }
                return $this->result(['health' => $health], [], 'Workloads verified healthy.');

            case 'scale_up':
                return $this->stepScale($connection, $artifacts, false);

            case 'scale_down':
                return $this->stepScale($connection, $artifacts, true);

            case 'rollout_restart':
                return $this->stepRolloutRestart($connection, $artifacts);

            case 'delete_networking':
                return $this->stepDeleteNetworking($connection, $artifacts);

            case 'delete_workloads':
                return $this->stepDeleteWorkloads($connection, $context, $artifacts);

            case 'delete_secrets':
                return $this->stepDeleteSecrets($connection, $artifacts);

            case 'delete_namespace':
                return $this->stepDeleteNamespace($connection, $context, $artifacts);

            default:
                throw new ValidationException('Unknown deployment step "' . $key . '".', [
                    'errors' => ['step' => 'Not implemented by the kubernetes adapter'],
                ]);
        }
    }

    /**
     * Roll a step back. Compensation deletes applied Deployments, Services,
     * Ingresses and Secrets best-effort; it never deletes PersistentVolumeClaims
     * or namespaces (both may hold data or outlive the deployment), and it
     * never throws for a resource that is already gone.
     */
    public function rollbackStep($key, DeploymentContext $context)
    {
        $removed = [];
        try {
            $artifacts = $this->artifacts($context);
            $connection = $this->client->connect($context->serverId());
            $namespace = $artifacts['namespace']['name'];
            $selector = KubernetesManifests::labelSelector($artifacts['labels']);

            switch ((string) $key) {
                case 'apply_workloads':
                    $this->deleteCollection($connection, $namespace, 'deployments', $selector);
                    $removed[] = 'deployments';
                    break;

                case 'apply_networking':
                    $this->deleteCollection($connection, $namespace, 'ingresses', $selector);
                    $this->deleteCollection($connection, $namespace, 'services', $selector);
                    $removed[] = 'ingress and services';
                    break;

                case 'apply_secrets':
                    $this->deleteCollection($connection, $namespace, 'secrets', $selector);
                    $removed[] = 'secrets';
                    break;

                case 'scale_down':
                    // Restore serving after a stop that is being compensated.
                    $this->scale($connection, $artifacts, false);
                    $removed[] = 'scale-down';
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
            $artifacts = $this->artifacts($context);
            $connection = $this->client->connect($context->serverId());
            $namespace = $artifacts['namespace']['name'];
            if ($this->client->getOrNull($connection, '/api/v1/namespaces/' . $namespace) === null) {
                return ['state' => 'missing', 'namespace' => $namespace,
                    'checked_at' => Clock::now(), 'source' => 'kubernetes-api'];
            }
            $selector = KubernetesManifests::labelSelector($artifacts['labels']);
            $list = $this->client->get($connection,
                '/apis/apps/v1/namespaces/' . $namespace . '/deployments', ['labelSelector' => $selector]);
            $items = isset($list['items']) && is_array($list['items']) ? $list['items'] : [];
            if ($items === []) {
                return ['state' => 'missing', 'namespace' => $namespace,
                    'checked_at' => Clock::now(), 'source' => 'kubernetes-api'];
            }
            $services = [];
            $desired = 0;
            $available = 0;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemStatus = isset($item['status']) && is_array($item['status']) ? $item['status'] : [];
                $want = isset($item['spec']['replicas']) ? (int) $item['spec']['replicas'] : 0;
                $have = isset($itemStatus['availableReplicas']) ? (int) $itemStatus['availableReplicas'] : 0;
                $updated = isset($itemStatus['updatedReplicas']) ? (int) $itemStatus['updatedReplicas'] : 0;
                $desired += $want;
                $available += $have;
                $services[] = [
                    'name' => isset($item['metadata']['name']) ? (string) $item['metadata']['name'] : '',
                    'desired' => $want,
                    'available' => $have,
                    'updated' => $updated,
                ];
            }
            if ($desired === 0) {
                $state = 'stopped';
            } elseif ($available >= $desired && $desired > 0) {
                $state = 'running';
            } else {
                $state = 'degraded';
            }
            return ['state' => $state, 'namespace' => $namespace, 'services' => $services,
                'desired_replicas' => $desired, 'available_replicas' => $available,
                'checked_at' => Clock::now(), 'source' => 'kubernetes-api'];
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
        $artifacts = $this->artifacts($context);
        $connection = $this->client->connect($context->serverId());
        $namespace = $artifacts['namespace']['name'];
        $selector = KubernetesManifests::labelSelector($artifacts['labels']);
        if ($service !== null && $service !== '') {
            if (!isset($artifacts['deployments'][(string) $service])) {
                throw new ValidationException('Unknown service "' . $service . '".', [
                    'errors' => ['service' => 'Not part of this installation'],
                ]);
            }
            $selector .= ',app.kubernetes.io/name=' . $artifacts['deployments'][(string) $service]['name'];
        }
        $list = $this->client->get($connection, '/api/v1/namespaces/' . $namespace . '/pods',
            ['labelSelector' => $selector]);
        $items = isset($list['items']) && is_array($list['items']) ? $list['items'] : [];
        usort($items, function ($a, $b) {
            $left = isset($a['metadata']['creationTimestamp']) ? (string) $a['metadata']['creationTimestamp'] : '';
            $right = isset($b['metadata']['creationTimestamp']) ? (string) $b['metadata']['creationTimestamp'] : '';
            return strcmp($right, $left);
        });
        $items = array_slice($items, 0, 5);
        $wanted = max(1, min(5000, (int) $lines));
        $share = $items === [] ? $wanted : max(1, (int) ceil($wanted / count($items)));
        $out = [];
        $notes = [];
        foreach ($items as $item) {
            $pod = isset($item['metadata']['name']) ? (string) $item['metadata']['name'] : '';
            if ($pod === '') {
                continue;
            }
            try {
                $body = $this->client->get($connection,
                    '/api/v1/namespaces/' . $namespace . '/pods/' . $pod . '/log',
                    ['timestamps' => 'true', 'tailLines' => (string) $share],
                    ['max_bytes' => 2097152, 'raw_body' => true]);
            } catch (KubernetesException $e) {
                // A waiting or terminated container has no log endpoint; the
                // other pods still report rather than failing the whole call.
                $notes[] = $pod . ': ' . Str::clip($e->getMessage(), 160);
                continue;
            }
            $text = isset($body['body']) ? (string) $body['body'] : '';
            foreach (explode("\n", $text) as $line) {
                if ($line !== '') {
                    $out[] = '[' . $pod . '] ' . $line;
                }
            }
        }
        $truncated = count($out) >= $wanted;
        return ['lines' => array_slice($out, 0, $wanted), 'truncated' => $truncated, 'service' => $service,
            'notes' => $notes, 'fetched_at' => Clock::now()];
    }

    public function health(DeploymentContext $context)
    {
        try {
            $artifacts = $this->artifacts($context);
            $connection = $this->client->connect($context->serverId());
            $namespace = $artifacts['namespace']['name'];
            if ($this->client->getOrNull($connection, '/api/v1/namespaces/' . $namespace) === null) {
                return ['state' => 'unhealthy', 'message' => 'The installation namespace is missing.',
                    'namespace' => $namespace, 'checked_at' => Clock::now()];
            }
            $summary = $this->rolloutSummary($connection, $artifacts);
            if ($summary['deployments'] === 0) {
                return ['state' => 'unhealthy', 'message' => 'No workloads found in the namespace.',
                    'namespace' => $namespace, 'checked_at' => Clock::now()];
            }
            if ($summary['ready']) {
                return ['state' => 'healthy',
                    'message' => $summary['available'] . ' of ' . $summary['desired'] . ' replicas available.',
                    'namespace' => $namespace, 'checked_at' => Clock::now()];
            }
            return ['state' => 'unhealthy', 'message' => Str::clip($summary['detail'], 255),
                'namespace' => $namespace, 'checked_at' => Clock::now()];
        } catch (\Throwable $e) {
            return ['state' => 'unknown', 'message' => Str::clip($e->getMessage(), 255),
                'checked_at' => Clock::now()];
        }
    }

    public function metrics(DeploymentContext $context)
    {
        try {
            $artifacts = $this->artifacts($context);
            $connection = $this->client->connect($context->serverId());
            $namespace = $artifacts['namespace']['name'];
            $list = $this->client->getOrNull($connection,
                '/apis/metrics.k8s.io/v1beta1/namespaces/' . $namespace . '/pods');
            if ($list === null) {
                // metrics-server is optional; its absence is reported as no
                // data, never as zero usage.
                return null;
            }
            $items = isset($list['items']) && is_array($list['items']) ? $list['items'] : [];
            $cpu = 0;
            $memoryMb = 0;
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['containers']) || !is_array($item['containers'])) {
                    continue;
                }
                foreach ($item['containers'] as $container) {
                    $usage = isset($container['usage']) && is_array($container['usage'])
                        ? $container['usage'] : [];
                    $cpu += self::cpuToMillicores(isset($usage['cpu']) ? $usage['cpu'] : '');
                    $memoryMb += self::memoryToMegabytes(isset($usage['memory']) ? $usage['memory'] : '');
                }
            }
            return ['cpu_millicores' => $cpu, 'memory_used_mb' => $memoryMb, 'pod_count' => count($items),
                'fetched_at' => Clock::now()];
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
        $artifacts = $this->artifacts($context);
        $connection = $this->client->connect($context->serverId());
        return $this->scale($connection, $artifacts, true);
    }

    public function start(DeploymentContext $context)
    {
        $this->assertEnabled();
        $artifacts = $this->artifacts($context);
        $connection = $this->client->connect($context->serverId());
        return $this->scale($connection, $artifacts, false);
    }

    public function restart(DeploymentContext $context)
    {
        $this->assertEnabled();
        $artifacts = $this->artifacts($context);
        $connection = $this->client->connect($context->serverId());
        return $this->restartRollout($connection, $artifacts);
    }

    /**
     * Remove everything this deployment created.
     *
     * Volumes and the namespace survive unless $removeData is set: deleting a
     * namespace cascades to its volumes, so preservation and namespace removal
     * are mutually exclusive and preservation wins.
     */
    public function destroy(DeploymentContext $context, $removeData = true)
    {
        $artifacts = $this->artifacts($context);
        $connection = $this->client->connect($context->serverId());
        $namespace = $artifacts['namespace']['name'];
        $selector = KubernetesManifests::labelSelector($artifacts['labels']);
        $removed = [];

        foreach (['ingresses' => 'k8s_ingress', 'services' => 'k8s_service',
            'deployments' => 'k8s_deployment', 'secrets' => null] as $plural => $type) {
            $names = $this->deleteCollection($connection, $namespace, $plural, $selector);
            if ($type !== null) {
                foreach ($names as $name) {
                    $removed[] = ['type' => $type, 'name' => $name];
                }
            }
        }
        if ($removeData) {
            foreach ($this->deleteCollection($connection, $namespace, 'persistentvolumeclaims', $selector) as $name) {
                $removed[] = ['type' => 'k8s_pvc', 'name' => $name];
            }
            if ($this->deleteNamespace($connection, $artifacts)) {
                $removed[] = ['type' => 'k8s_namespace', 'name' => $namespace];
            }
            return ['removed' => $removed,
                'message' => 'Kubernetes objects removed with volumes and the namespace.'];
        }
        return ['removed' => $removed,
            'message' => 'Kubernetes workloads removed; volumes and the namespace preserved (remove_data off).'];
    }

    /* ---------------------------------------------------------------- steps */

    private function stepEnsureNamespace(array $connection, array $artifacts)
    {
        $name = $artifacts['namespace']['name'];
        $existing = $this->client->getOrNull($connection, '/api/v1/namespaces/' . $name);
        if ($existing === null) {
            if (!$artifacts['namespace']['managed']) {
                throw new ConfigurationException(
                    'The declared namespace "' . $name . '" does not exist; create it in the cluster first.', [
                        'error_code' => 'KUBERNETES_NAMESPACE_MISSING', 'namespace' => $name,
                    ]
                );
            }
            $this->client->post($connection, '/api/v1/namespaces', $artifacts['namespace']['object']);
            return $this->result(['namespace' => $name, 'created' => true],
                [['type' => 'k8s_namespace', 'name' => $name, 'metadata' => ['managed' => true]]],
                'Namespace created.');
        }
        if ($artifacts['namespace']['managed']) {
            $this->assertNamespaceOwned($existing, $artifacts);
        }
        return $this->result(['namespace' => $name, 'created' => false],
            [['type' => 'k8s_namespace', 'name' => $name,
                'metadata' => ['managed' => $artifacts['namespace']['managed']]]],
            'Namespace already existed and was verified.');
    }

    private function stepApplySecrets(array $connection, array $artifacts)
    {
        $applied = [];
        $pull = $artifacts['pull_auth'];
        if ($artifacts['image_pull_secret'] !== null && $pull !== null) {
            $object = KubernetesManifests::imagePullSecret($artifacts['base'], $artifacts['namespace']['name'],
                $pull['username'], $pull['token'], $pull['registry'], $artifacts['labels']);
            $this->client->apply($connection, $object['object']);
            $applied[] = $object['name'];
        }
        if ($artifacts['secret'] !== null) {
            $this->client->apply($connection, $artifacts['secret']['object']);
            $applied[] = $artifacts['secret']['name'];
        }
        return $this->result(['secrets' => $applied], [], $applied === []
            ? 'No secrets to apply.' : 'Secrets applied.');
    }

    private function stepApplyWorkloads(array $connection, array $artifacts)
    {
        $resources = [];
        foreach ($artifacts['pvcs'] as $volumeName => $pvc) {
            $this->client->apply($connection, $pvc['object']);
            $resources[] = ['type' => 'k8s_pvc', 'name' => $pvc['name'],
                'metadata' => ['volume' => $volumeName]];
        }
        foreach ($artifacts['deployments'] as $serviceName => $deployment) {
            $this->client->apply($connection, $deployment['object']);
            $resources[] = ['type' => 'k8s_deployment', 'name' => $deployment['name'],
                'metadata' => ['service' => $serviceName, 'replicas' => $deployment['replicas']]];
        }
        return $this->result(
            ['deployments' => array_keys($artifacts['deployments']), 'ignored' => $artifacts['ignored'],
                'warnings' => $artifacts['warnings']],
            $resources, 'Volumes and Deployments applied.');
    }

    private function stepApplyNetworking(array $connection, array $artifacts)
    {
        $resources = [];
        foreach ($artifacts['services'] as $serviceName => $service) {
            $this->client->apply($connection, $service['object']);
            $resources[] = ['type' => 'k8s_service', 'name' => $service['name'],
                'metadata' => ['service' => $serviceName]];
        }
        if ($artifacts['ingress'] !== null) {
            $this->client->apply($connection, $artifacts['ingress']['object']);
            $resources[] = ['type' => 'k8s_ingress', 'name' => $artifacts['ingress']['name'], 'metadata' => []];
        }
        return $this->result(['services' => array_keys($artifacts['services']),
            'ingress' => $artifacts['ingress'] !== null], $resources, 'Services and Ingress applied.');
    }

    private function stepWaitReady(array $connection, array $artifacts, $timeout)
    {
        $summary = $this->client->waitFor('the rollout', $timeout, function () use ($connection, $artifacts) {
            $summary = $this->rolloutSummary($connection, $artifacts);
            return ['done' => $summary['ready'], 'detail' => $summary['detail']];
        });
        return $this->result(['rollout' => $summary], [], 'Rollout available.');
    }

    private function stepScale(array $connection, array $artifacts, $toZero)
    {
        $result = $this->scale($connection, $artifacts, $toZero);
        return $this->result($result, [], $toZero ? 'Workloads scaled to zero.' : 'Workloads scaled up.');
    }

    private function stepRolloutRestart(array $connection, array $artifacts)
    {
        $result = $this->restartRollout($connection, $artifacts);
        return $this->result($result, [], 'Rolling restart triggered; readiness verification follows.');
    }

    private function stepDeleteNetworking(array $connection, array $artifacts)
    {
        $namespace = $artifacts['namespace']['name'];
        $selector = KubernetesManifests::labelSelector($artifacts['labels']);
        $deleted = [
            'ingresses' => $this->deleteCollection($connection, $namespace, 'ingresses', $selector),
            'services' => $this->deleteCollection($connection, $namespace, 'services', $selector),
        ];
        return $this->result(['deleted' => $deleted], [], 'Ingress and Services deleted.');
    }

    private function stepDeleteWorkloads(array $connection, DeploymentContext $context, array $artifacts)
    {
        $namespace = $artifacts['namespace']['name'];
        $selector = KubernetesManifests::labelSelector($artifacts['labels']);
        $deleted = $this->deleteCollection($connection, $namespace, 'deployments', $selector);
        $pvcs = [];
        $skipped = false;
        if (!empty($context->options['remove_data'])) {
            $pvcs = $this->deleteCollection($connection, $namespace, 'persistentvolumeclaims', $selector);
        } else {
            $skipped = true;
        }
        return $this->result(['deployments' => $deleted, 'pvcs' => $pvcs, 'pvcs_preserved' => $skipped], [],
            $skipped ? 'Deployments deleted; volumes preserved (remove_data off).'
                : 'Deployments and claimed volumes deleted.');
    }

    private function stepDeleteSecrets(array $connection, array $artifacts)
    {
        $namespace = $artifacts['namespace']['name'];
        $selector = KubernetesManifests::labelSelector($artifacts['labels']);
        $deleted = $this->deleteCollection($connection, $namespace, 'secrets', $selector);
        return $this->result(['secrets' => $deleted], [], 'Installation secrets deleted.');
    }

    private function stepDeleteNamespace(array $connection, DeploymentContext $context, array $artifacts)
    {
        if (empty($context->options['remove_data'])) {
            return $this->result(['namespace' => $artifacts['namespace']['name'], 'deleted' => false], [],
                'Namespace preserved (remove_data off).');
        }
        $existing = $this->client->getOrNull($connection, '/api/v1/namespaces/' . $artifacts['namespace']['name']);
        if ($existing === null) {
            return $this->result(['namespace' => $artifacts['namespace']['name'], 'deleted' => false], [],
                'Namespace already absent. Nothing to do.');
        }
        $deleted = $this->deleteNamespace($connection, $artifacts);
        return $this->result(['namespace' => $artifacts['namespace']['name'], 'deleted' => $deleted], [],
            $deleted ? 'Namespace deleted.' : 'Foreign namespace left in place; labeled objects were removed.');
    }

    /* ----------------------------------------------------------- internals */

    private function assertEnabled()
    {
        if (!Settings::bool('kubernetes_enabled', false)) {
            throw new ConfigurationException('The Kubernetes adapter is disabled on this platform.', [
                'error_code' => 'ADAPTER_DISABLED', 'adapter' => 'kubernetes',
            ]);
        }
    }

    /** Rendered artifacts plus the resolved pull-secret material for one context. */
    private function artifacts(DeploymentContext $context)
    {
        $base = KubernetesManifests::baseName($context);
        $pull = $this->registryAuth($context);
        $pullName = $pull !== null ? KubernetesManifests::imagePullSecretName($base) : '';
        $artifacts = KubernetesManifests::build($context, ['image_pull_secret' => $pullName]);
        $artifacts['base'] = $base;
        $artifacts['pull_auth'] = $pull;
        return $artifacts;
    }

    /** Private registry credentials, if the platform holds any for this server. */
    private function registryAuth(DeploymentContext $context)
    {
        if (!$context->server) {
            return null;
        }
        $token = $this->vault->revealFor((int) $context->server['id'], CredentialVault::TYPE_REGISTRY_TOKEN);
        if ($token === null) {
            return null;
        }
        $row = Db::first('server_credentials', [
            'server_id' => (int) $context->server['id'],
            'credential_type' => CredentialVault::TYPE_REGISTRY_TOKEN,
            'status' => 'active',
        ]);
        return [
            'username' => $row ? (string) $row['username'] : '',
            'token' => $token,
            'registry' => Settings::string('private_registry_host', ''),
        ];
    }

    /** A namespace is ours only when its labels say so; never adopt a foreign one. */
    private function assertNamespaceOwned(array $existing, array $artifacts)
    {
        $labels = isset($existing['metadata']['labels']) && is_array($existing['metadata']['labels'])
            ? $existing['metadata']['labels'] : [];
        foreach ($artifacts['labels'] as $key => $value) {
            if (!isset($labels[$key]) || (string) $labels[$key] !== (string) $value) {
                throw new KubernetesException('The namespace "' . $artifacts['namespace']['name']
                    . '" exists but is not owned by this installation; refusing to adopt it.', [
                    'error_code' => 'KUBERNETES_NAMESPACE_CONFLICT', 'namespace' => $artifacts['namespace']['name'],
                ]);
            }
        }
    }

    /**
     * Delete a namespace only when it is platform-derived and still ours.
     *
     * @return bool whether the namespace was deleted
     */
    private function deleteNamespace(array $connection, array $artifacts)
    {
        if (!$artifacts['namespace']['managed']) {
            return false;
        }
        $name = $artifacts['namespace']['name'];
        $existing = $this->client->getOrNull($connection, '/api/v1/namespaces/' . $name);
        if ($existing === null) {
            return false;
        }
        $this->assertNamespaceOwned($existing, $artifacts);
        $this->client->delete($connection, '/api/v1/namespaces/' . $name);
        return true;
    }

    /**
     * Delete one labeled collection. Returns the names that were matched
     * beforehand so the ledger can record exactly what was removed.
     */
    private function deleteCollection(array $connection, $namespace, $plural, $selector)
    {
        $api = $plural === 'deployments' ? '/apis/apps/v1'
            : ($plural === 'ingresses' ? '/apis/networking.k8s.io/v1' : '/api/v1');
        $path = $api . '/namespaces/' . $namespace . '/' . $plural;
        $names = [];
        try {
            $list = $this->client->get($connection, $path, ['labelSelector' => $selector]);
        } catch (KubernetesException $e) {
            $asArray = $e->toArray();
            $context = isset($asArray['context']) && is_array($asArray['context']) ? $asArray['context'] : [];
            if (!$e->isRetryable() && isset($context['http_status']) && (int) $context['http_status'] === 404) {
                return [];
            }
            throw $e;
        }
        $items = isset($list['items']) && is_array($list['items']) ? $list['items'] : [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['metadata']['name'])) {
                $names[] = (string) $item['metadata']['name'];
            }
        }
        if ($names === []) {
            return [];
        }
        $this->client->delete($connection, $path, ['labelSelector' => $selector]);
        return $names;
    }

    private function scale(array $connection, array $artifacts, $toZero)
    {
        $namespace = $artifacts['namespace']['name'];
        $scaled = [];
        foreach ($artifacts['deployments'] as $serviceName => $deployment) {
            $replicas = $toZero ? 0 : (int) $deployment['replicas'];
            $this->client->patch($connection,
                '/apis/apps/v1/namespaces/' . $namespace . '/deployments/' . $deployment['name'] . '/scale',
                ['spec' => ['replicas' => $replicas]], 'application/merge-patch+json');
            $scaled[$serviceName] = $replicas;
        }
        return ['scaled' => $scaled];
    }

    private function restartRollout(array $connection, array $artifacts)
    {
        $namespace = $artifacts['namespace']['name'];
        $restarted = [];
        foreach ($artifacts['deployments'] as $serviceName => $deployment) {
            $this->client->patch($connection,
                '/apis/apps/v1/namespaces/' . $namespace . '/deployments/' . $deployment['name'],
                ['spec' => ['template' => ['metadata' => ['annotations' => [
                    'kubectl.kubernetes.io/restartedAt' => gmdate('c'),
                ]]]]], 'application/strategic-merge-patch+json');
            $restarted[] = $deployment['name'];
        }
        return ['restarted' => $restarted];
    }

    /** Aggregate rollout state across the installation's Deployments. */
    private function rolloutSummary(array $connection, array $artifacts)
    {
        $namespace = $artifacts['namespace']['name'];
        $selector = KubernetesManifests::labelSelector($artifacts['labels']);
        $list = $this->client->get($connection,
            '/apis/apps/v1/namespaces/' . $namespace . '/deployments', ['labelSelector' => $selector]);
        $items = isset($list['items']) && is_array($list['items']) ? $list['items'] : [];
        $desired = 0;
        $available = 0;
        $ready = true;
        $pending = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = isset($item['metadata']['name']) ? (string) $item['metadata']['name'] : '';
            $spec = isset($item['spec']) && is_array($item['spec']) ? $item['spec'] : [];
            $itemStatus = isset($item['status']) && is_array($item['status']) ? $item['status'] : [];
            $want = isset($spec['replicas']) ? (int) $spec['replicas'] : 0;
            $have = isset($itemStatus['availableReplicas']) ? (int) $itemStatus['availableReplicas'] : 0;
            $updated = isset($itemStatus['updatedReplicas']) ? (int) $itemStatus['updatedReplicas'] : 0;
            $observed = isset($itemStatus['observedGeneration']) ? (int) $itemStatus['observedGeneration'] : 0;
            $generation = isset($item['metadata']['generation']) ? (int) $item['metadata']['generation'] : 0;
            $desired += $want;
            $available += $have;
            if ($generation > 0 && $observed < $generation) {
                $ready = false;
                $pending[] = $name . ': rollout not yet observed';
            } elseif ($have < $want || $updated < $want) {
                $ready = false;
                $pending[] = $name . ': ' . $have . ' of ' . $want . ' available';
            }
        }
        $detail = $pending === [] ? $available . ' of ' . $desired . ' available' : implode('; ', $pending);
        return ['ready' => $ready && $items !== [], 'deployments' => count($items), 'desired' => $desired,
            'available' => $available, 'detail' => $detail];
    }

    /** @return int millicores (unknown quantities count as zero, never guessed) */
    public static function cpuToMillicores($quantity)
    {
        $quantity = trim((string) $quantity);
        if (preg_match('/^([0-9]+)m$/', $quantity, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/^([0-9]+(?:\\.[0-9]+)?)$/', $quantity, $match)) {
            return (int) round((float) $match[1] * 1000);
        }
        return 0;
    }

    /** @return int megabytes (unknown quantities count as zero, never guessed) */
    public static function memoryToMegabytes($quantity)
    {
        $quantity = trim((string) $quantity);
        if (preg_match('/^([0-9]+)Ki$/', $quantity, $match)) {
            return (int) round((int) $match[1] / 1024);
        }
        if (preg_match('/^([0-9]+)Mi$/', $quantity, $match)) {
            return (int) $match[1];
        }
        if (preg_match('/^([0-9]+(?:\\.[0-9]+)?)Gi$/', $quantity, $match)) {
            return (int) round((float) $match[1] * 1024);
        }
        if (preg_match('/^([0-9]+(?:\\.[0-9]+)?)Ti$/', $quantity, $match)) {
            return (int) round((float) $match[1] * 1024 * 1024);
        }
        if (preg_match('/^([0-9]+)k$/', $quantity, $match)) {
            return (int) round((int) $match[1] / 1000);
        }
        if (preg_match('/^([0-9]+(?:\\.[0-9]+)?)M$/', $quantity, $match)) {
            return (int) round((float) $match[1]);
        }
        if (preg_match('/^([0-9]+(?:\\.[0-9]+)?)G$/', $quantity, $match)) {
            return (int) round((float) $match[1] * 1000);
        }
        if (preg_match('/^([0-9]+)$/', $quantity, $match)) {
            return (int) round((int) $match[1] / 1024 / 1024);
        }
        return 0;
    }

    private function result(array $output, array $resources, $message)
    {
        return ['output' => $output, 'resources' => $resources, 'message' => $message];
    }
}
