<?php
/**
 * CloudHost247 App Cloud — fake adapter.
 *
 * A complete, in-memory implementation of the deployment contract. It is not a
 * stub that returns `true`: it renders the very same compose file, environment
 * file, resource limits and routing labels the Docker adapter would send to an
 * agent (through ComposeBuilder), records every step, and can be told to fail at
 * a chosen step with a chosen error code.
 *
 * That makes it useful for two things:
 *   • the test suite can exercise the whole engine — queue, orchestrator, steps,
 *     resource ledger, rollback, notifications — without a server
 *   • an administrator can dry-run a manifest and see exactly what would be
 *     written to a node before approving it for the catalog
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Catalog\Manifest;
use Ch247Apps\Core\AgentException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\HealthCheckException;
use Ch247Apps\Core\ImagePullException;

class FakeAdapter implements AdapterInterface
{
    /** @var array recorded calls: [['action','project','step','at'], …] */
    private $calls = [];

    /** @var array step key => exception to throw */
    private $failures = [];

    /** @var array project => state (running|stopped|removed) */
    private $states = [];

    /** @var array project => ['healthy'|'unhealthy'|'unknown'] */
    private $health = [];

    /** @var array project => log lines */
    private $logs = [];

    /** @var array project => artifacts (compose, env, limits, traefik) */
    private $artifacts = [];

    /** @var bool when true, destroy() also removes data */
    private $removeData = true;

    public function name()
    {
        return 'fake';
    }

    public function engine()
    {
        return 'docker-compose';
    }

    public function supports(Manifest $manifest, array $server)
    {
        return true;
    }

    public function plan(DeploymentContext $context)
    {
        $out = [];
        $order = 1;
        foreach (DockerAdapter::stepsFor((string) $context->action) as $key) {
            $meta = DockerAdapter::stepMeta($key);
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

    public function executeStep($key, DeploymentContext $context)
    {
        $this->record('step:' . $key, $context);
        $this->maybeFail($key, $context);

        // Render the real artifacts so a dry run shows what a node would receive.
        if (!isset($this->artifacts[$context->project])) {
            $this->artifacts[$context->project] = ComposeBuilder::build($context);
        }
        $artifacts = $this->artifacts[$context->project];

        $resources = [];
        $message = 'Step ' . $key . ' completed (simulated).';
        switch ((string) $key) {
            case 'prepare_directories':
                $resources[] = ['type' => 'directory', 'name' => $context->basePath,
                    'metadata' => ['directories' => $artifacts['directories']]];
                $message = 'Deployment directory prepared (simulated).';
                break;
            case 'write_artifacts':
                $resources[] = ['type' => 'compose_project', 'name' => $context->project,
                    'metadata' => ['compose_sha256' => hash('sha256', $artifacts['compose']),
                        'services' => array_keys($artifacts['services'])]];
                $message = 'Compose file and environment written (simulated).';
                break;
            case 'start_containers':
                foreach ($artifacts['services'] as $name => $service) {
                    $resources[] = ['type' => 'container', 'name' => $context->project . '-' . $name,
                        'metadata' => ['service' => $name, 'image' => $service['image']]];
                }
                $this->states[$context->project] = 'running';
                $message = 'Containers started (simulated).';
                break;
            case 'stop_containers':
                $this->states[$context->project] = 'stopped';
                $message = 'Containers stopped (simulated).';
                break;
            case 'restart_containers':
                $this->states[$context->project] = 'running';
                $message = 'Containers restarted (simulated).';
                break;
            case 'configure_routing':
                foreach ($artifacts['traefik'] as $name => $routing) {
                    $resources[] = ['type' => 'traefik_route', 'name' => $routing['router'],
                        'metadata' => ['rule' => $routing['rule'], 'domains' => $routing['domains']]];
                }
                $message = 'Routing configured (simulated).';
                break;
            case 'health_check':
                $message = 'Health check passed (simulated).';
                break;
            case 'remove_containers':
            case 'remove_routing':
            case 'remove_artifacts':
            case 'remove_directories':
                $this->states[$context->project] = 'removed';
                $message = ucfirst(str_replace('_', ' ', $key)) . ' (simulated).';
                break;
            default:
                break;
        }

        return [
            'output' => ['simulated' => true, 'step' => $key, 'project' => $context->project],
            'resources' => $resources,
            'message' => $message,
            'request_id' => 'FAKE-' . strtoupper(substr(md5($context->project . $key . Clock::now()), 0, 8)),
            'attempts' => 1,
            'duration_ms' => 0,
        ];
    }

    public function rollbackStep($key, DeploymentContext $context)
    {
        $this->record('rollback:' . $key, $context);
        $removed = [];
        if (in_array((string) $key, ['start_containers', 'stop_containers', 'restart_containers',
            'remove_containers'], true)) {
            $this->states[$context->project] = 'removed';
            $removed[] = 'containers';
        }
        if (in_array((string) $key, ['configure_routing', 'remove_routing'], true)) {
            $removed[] = 'routes';
        }
        if (in_array((string) $key, ['write_artifacts', 'remove_artifacts'], true)) {
            $removed[] = 'artifacts';
        }
        if (in_array((string) $key, ['prepare_directories', 'remove_directories'], true)) {
            $removed[] = 'directories';
        }
        return ['removed' => $removed, 'message' => $removed
            ? 'Rolled back (simulated): ' . implode(', ', $removed) . '.' : 'Nothing to roll back.'];
    }

    public function status(DeploymentContext $context)
    {
        $this->record('status', $context);
        $state = isset($this->states[$context->project]) ? $this->states[$context->project] : 'unknown';
        $services = [];
        if (isset($this->artifacts[$context->project])) {
            foreach ($this->artifacts[$context->project]['services'] as $name => $service) {
                $services[] = ['name' => $name, 'state' => $state === 'running' ? 'running' : $state,
                    'image' => $service['image']];
            }
        }
        return ['state' => $state, 'services' => $services, 'checked_at' => Clock::now(), 'source' => 'fake'];
    }

    public function logs(DeploymentContext $context, $service = null, $lines = 200)
    {
        $this->record('logs', $context);
        $stored = isset($this->logs[$context->project]) ? $this->logs[$context->project] : [];
        if ($stored === []) {
            $stored = [['timestamp' => Clock::now(), 'service' => $service ?: 'app',
                'message' => 'No log lines have been produced by the simulated workload.']];
        }
        return ['lines' => array_slice($stored, -max(1, (int) $lines)), 'truncated' => false,
            'service' => $service, 'fetched_at' => Clock::now()];
    }

    public function health(DeploymentContext $context)
    {
        $this->record('health', $context);
        $state = isset($this->health[$context->project]) ? $this->health[$context->project] : 'healthy';
        return ['state' => $state, 'message' => $state === 'healthy'
            ? 'Simulated health check passed.' : 'Simulated health check did not pass.',
            'attempts' => 1, 'checked_at' => Clock::now()];
    }

    public function metrics(DeploymentContext $context)
    {
        $this->record('metrics', $context);
        return [
            'cpu_percent' => 4.2,
            'memory_used_mb' => 256,
            'storage_used_mb' => 1024,
            'container_count' => isset($this->artifacts[$context->project])
                ? count($this->artifacts[$context->project]['services']) : 0,
            'restart_count' => 0,
            'sampled_at' => Clock::now(),
        ];
    }

    public function stop(DeploymentContext $context)
    {
        $this->record('stop', $context);
        $this->maybeFail('stop', $context);
        $this->states[$context->project] = 'stopped';
        return ['ok' => true, 'data' => ['state' => 'stopped'], 'request_id' => 'FAKE-STOP', 'attempts' => 1];
    }

    public function start(DeploymentContext $context)
    {
        $this->record('start', $context);
        $this->maybeFail('start', $context);
        $this->states[$context->project] = 'running';
        return ['ok' => true, 'data' => ['state' => 'running'], 'request_id' => 'FAKE-START', 'attempts' => 1];
    }

    public function restart(DeploymentContext $context)
    {
        $this->record('restart', $context);
        $this->maybeFail('restart', $context);
        $this->states[$context->project] = 'running';
        return ['ok' => true, 'data' => ['state' => 'running'], 'request_id' => 'FAKE-RESTART', 'attempts' => 1];
    }

    public function destroy(DeploymentContext $context, $removeData = true)
    {
        $this->record('destroy', $context);
        $this->maybeFail('destroy', $context);
        $this->removeData = (bool) $removeData;
        $this->states[$context->project] = 'removed';
        $removed = [
            ['type' => 'compose_project', 'name' => $context->project],
            ['type' => 'directory', 'name' => $context->basePath],
        ];
        if ($removeData && isset($this->artifacts[$context->project])) {
            foreach ($this->artifacts[$context->project]['services'] as $name => $service) {
                $removed[] = ['type' => 'container', 'name' => $context->project . '-' . $name];
            }
        }
        return ['ok' => true, 'data' => ['state' => 'removed'], 'removed' => $removed,
            'request_id' => 'FAKE-DESTROY', 'attempts' => 1];
    }

    /* --------------------------------------------------------- test seams */

    /** Make one step fail with a specific exception class. */
    public function failAt($stepKey, $exceptionClass = null, $message = '', array $context = [])
    {
        $class = $exceptionClass ?: 'Ch247Apps\Core\AgentException';
        $this->failures[(string) $stepKey] = [
            'class' => $class,
            'message' => $message !== '' ? $message : 'Simulated failure at ' . $stepKey,
            'context' => $context,
        ];
        return $this;
    }

    public function clearFailures()
    {
        $this->failures = [];
        return $this;
    }

    public function setHealth($project, $state)
    {
        $this->health[(string) $project] = in_array($state, ['healthy', 'unhealthy', 'unknown'], true)
            ? $state : 'unknown';
        return $this;
    }

    public function setState($project, $state)
    {
        $this->states[(string) $project] = (string) $state;
        return $this;
    }

    public function addLog($project, $message, $service = 'app', $level = 'info')
    {
        $this->logs[(string) $project][] = ['timestamp' => Clock::now(), 'service' => $service,
            'level' => $level, 'message' => (string) $message];
        return $this;
    }

    public function calls($prefix = null)
    {
        if ($prefix === null) {
            return $this->calls;
        }
        return array_values(array_filter($this->calls, function ($call) use ($prefix) {
            return strpos($call['action'], (string) $prefix) === 0;
        }));
    }

    public function callCount($prefix = null)
    {
        return count($this->calls($prefix));
    }

    public function artifacts($project = null)
    {
        if ($project === null) {
            return $this->artifacts;
        }
        return isset($this->artifacts[(string) $project]) ? $this->artifacts[(string) $project] : null;
    }

    public function state($project)
    {
        return isset($this->states[(string) $project]) ? $this->states[(string) $project] : 'unknown';
    }

    public function reset()
    {
        $this->calls = [];
        $this->failures = [];
        $this->states = [];
        $this->health = [];
        $this->logs = [];
        $this->artifacts = [];
        return $this;
    }

    private function record($action, DeploymentContext $context)
    {
        $this->calls[] = [
            'action' => (string) $action,
            'project' => $context->project,
            'installation_id' => $context->installationId(),
            'server_id' => $context->serverId(),
            'at' => Clock::now(),
        ];
    }

    private function maybeFail($key, DeploymentContext $context)
    {
        if (!isset($this->failures[(string) $key])) {
            return;
        }
        $failure = $this->failures[(string) $key];
        $class = $failure['class'];
        if (!class_exists($class)) {
            $class = AgentException::class;
        }
        $contextData = $failure['context'] + [
            'project' => $context->project,
            'step' => (string) $key,
            'installation_id' => $context->installationId(),
        ];
        // A few failure modes are used often enough to deserve a shorthand.
        if ($class === 'image_pull') {
            throw new ImagePullException($failure['message'], $contextData + ['error_code' => 'IMAGE_PULL_FAILED']);
        }
        if ($class === 'health') {
            throw new HealthCheckException($failure['message'], $contextData + ['error_code' => 'HEALTH_CHECK_FAILED']);
        }
        throw new $class($failure['message'], $contextData);
    }
}
