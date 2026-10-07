<?php
/**
 * CloudHost247 App Cloud — compose builder.
 *
 * Turns an approved manifest plus a deployment context into the exact artifacts
 * the agent writes on the server: a compose file, an environment file, the
 * directory layout, the resource limits per container and the Traefik routing
 * labels.
 *
 * This is what makes "no per-application installer code" true. Nothing in here
 * knows what WordPress or n8n is: it reads the manifest, and the manifest is the
 * only place an application's shape is described.
 *
 * Security properties enforced here:
 *   • no container port is ever published on the host — traffic reaches an app
 *     only through the Traefik network, so a customer cannot bypass the proxy
 *   • secrets live in the env file, never in the compose document
 *   • backend services (databases, caches) sit on an internal network with no
 *     route to the proxy or the internet
 *   • every container gets a CPU and memory ceiling; the sum of the ceilings
 *     never exceeds what the plan reserved on the server
 *   • routing labels are generated from the customer's real domain, never a
 *     hardcoded host
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Core\Yaml;

class ComposeBuilder
{
    /** Network the reverse proxy shares with application containers. */
    const PROXY_NETWORK = 'proxy';

    /** Internal, per-project network for inter-service traffic. */
    const INTERNAL_NETWORK = 'internal';

    /**
     * Build every artifact for a deployment.
     *
     * @return array{project, base_path, compose, env, network, directories,
     *               services, limits, traefik, volumes}
     */
    public static function build(DeploymentContext $context)
    {
        $manifest = $context->manifest;
        $services = $manifest->services();
        if ($services === []) {
            throw new ValidationException('That manifest declares no services.', [
                'errors' => ['services' => 'At least one service is required'],
            ]);
        }

        $proxyNetwork = Settings::string('docker_network', 'ch247app');
        $limits = self::serviceLimits($context);
        $environment = self::environmentMap($context);

        // Which of those values are secrets: they stay in .env and are referenced
        // from compose.yaml as ${VAR} placeholders instead of being written out.
        $secretKeys = [];
        foreach ($context->environmentMeta as $envKey => $meta) {
            if (!empty($meta['is_secret'])) {
                $secretKeys[] = (string) $envKey;
            }
        }

        $compose = [
            'name' => $context->project,
            'services' => [],
            'networks' => [
                self::PROXY_NETWORK => ['external' => true, 'name' => $proxyNetwork],
                self::INTERNAL_NETWORK => self::internalNetwork(),
            ],
        ];

        $directories = ['.', './volumes', './logs', './backups', './config'];
        $traefik = [];
        $serviceSummary = [];

        foreach ($services as $name => $service) {
            $definition = [
                'image' => $service['image'],
                'restart' => self::restartPolicy($service),
                'env_file' => '.env',
                'networks' => self::networksFor($service, $name),
                'security_opt' => ['no-new-privileges:true'],
            ];
            if (!empty($service['cap_drop'])) {
                $definition['cap_drop'] = $service['cap_drop'];
            }
            if (!empty($service['read_only'])) {
                $definition['read_only'] = true;
                $definition['tmpfs'] = ['/tmp'];
            }
            if (!empty($service['user'])) {
                $definition['user'] = (string) $service['user'];
            }
            if (!empty($service['depends_on'])) {
                $definition['depends_on'] = array_values($service['depends_on']);
            }
            if (!empty($service['command'])) {
                $definition['command'] = $service['command'];
            }
            if (!empty($service['entrypoint'])) {
                $definition['entrypoint'] = $service['entrypoint'];
            }

            // Volumes: bind mounts inside this deployment's own directory. Nothing
            // is ever mounted from a host path the manifest invented.
            $mounts = self::volumeMounts($context, $name, $service);
            if ($mounts !== []) {
                $definition['volumes'] = $mounts;
                foreach ($mounts as $mount) {
                    $host = explode(':', $mount);
                    if (isset($host[0]) && strpos($host[0], './') === 0) {
                        $directories[] = $host[0];
                    }
                }
            }

            // Service-scoped environment additions (non-secret interpolation only;
            // anything secret stays in the env file).
            $inline = self::inlineEnvironment($service, $environment, $secretKeys);
            if ($inline !== []) {
                $definition['environment'] = $inline;
            }

            // Resource ceilings. Compose v2 honours `deploy.resources.limits`
            // outside Swarm as well, and the agent additionally applies
            // `docker update` limits so the ceiling holds either way.
            $definition['deploy'] = ['resources' => ['limits' => $limits[$name]['limits'],
                'reservations' => $limits[$name]['reservations']]];

            $healthcheck = self::healthcheckFor($service, $name, $manifest);
            if ($healthcheck !== null) {
                $definition['healthcheck'] = $healthcheck;
            }

            $exposed = self::isExposed($service, $name, $manifest);
            if ($exposed) {
                $labels = self::traefikLabels($context, $name, $service, $proxyNetwork);
                $definition['labels'] = $labels['labels'];
                $traefik[$name] = $labels;
            }

            $compose['services'][$name] = $definition;
            $serviceSummary[$name] = [
                'image' => $service['image'],
                'port' => (int) $service['port'],
                'exposed' => $exposed,
                'limits' => $limits[$name],
                'networks' => $definition['networks'],
                'healthcheck' => $healthcheck !== null,
                'read_only' => !empty($service['read_only']),
                'privileged' => !empty($service['privileged']),
            ];
        }

        $directories = array_values(array_unique($directories));
        sort($directories);

        return [
            'project' => $context->project,
            'base_path' => $context->basePath,
            'compose_file' => 'compose.yaml',
            'env_file' => '.env',
            'compose' => Yaml::emit($compose),
            'env' => self::renderEnvFile($context),
            'network' => $proxyNetwork,
            'internal_network' => $context->project . '_' . self::INTERNAL_NETWORK,
            'directories' => $directories,
            'services' => $serviceSummary,
            'limits' => $limits,
            'traefik' => $traefik,
            'environment_keys' => array_keys($environment),
        ];
    }

    /** An internal network keeps databases and caches off the proxy and the internet. */
    private static function internalNetwork()
    {
        $network = ['driver' => 'bridge'];
        if (Settings::bool('docker_isolate_backend_networks', true)) {
            $network['internal'] = true;
        }
        return $network;
    }

    private static function networksFor(array $service, $name)
    {
        if (!empty($service['network_mode'])) {
            return [(string) $service['network_mode']];
        }
        if (self::serviceIsBackend($service, $name)) {
            return [self::INTERNAL_NETWORK];
        }
        return [self::PROXY_NETWORK, self::INTERNAL_NETWORK];
    }

    /** A service nobody routes to and that declares no primary role is backend. */
    private static function serviceIsBackend(array $service, $name)
    {
        if (isset($service['expose'])) {
            return !(bool) $service['expose'];
        }
        if (!empty($service['primary'])) {
            return false;
        }
        // Ports below 1024 or well-known datastore images are treated as backend.
        $image = strtolower((string) $service['image']);
        foreach (['postgres', 'mariadb', 'mysql', 'redis', 'valkey', 'rabbitmq', 'mongo', 'qdrant',
            'minio', 'elasticsearch', 'clickhouse'] as $backend) {
            if (strpos($image, $backend) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function isExposed(array $service, $name, \Ch247Apps\Catalog\Manifest $manifest)
    {
        if (isset($service['expose'])) {
            return (bool) $service['expose'];
        }
        if (!empty($service['primary'])) {
            return true;
        }
        return $name === $manifest->primaryService() && (int) $service['port'] > 0;
    }

    private static function restartPolicy(array $service)
    {
        $policy = isset($service['restart']) && $service['restart'] !== '' ? (string) $service['restart']
            : Settings::string('container_restart_policy', 'unless-stopped');
        return in_array($policy, ['no', 'always', 'unless-stopped', 'on-failure'], true) ? $policy : 'unless-stopped';
    }

    /**
     * Divide the reserved CPU/memory across services.
     *
     * A manifest may declare per-service ceilings; when it does they are honoured
     * and scaled down proportionally if together they exceed what the plan
     * reserved. Services with no declared ceiling share what is left, and the
     * primary service never drops below the manifest's own minimum — an
     * application must not be squeezed under what it says it needs.
     */
    public static function serviceLimits(DeploymentContext $context)
    {
        $total = $context->resourceLimits();
        $totalCpu = (int) $total['cpu_millicores'];
        $totalMemory = (int) $total['memory_mb'];
        $services = $context->manifest->services();
        $requirements = $context->manifest->requirements();
        $primary = $context->manifest->primaryService();

        $declaredCpu = [];
        $declaredMemory = [];
        $sumCpu = 0;
        $sumMemory = 0;
        foreach ($services as $name => $service) {
            $cpu = Str::toMillicores(isset($service['cpu_limit']) ? $service['cpu_limit'] : 0);
            $memory = Str::toMegabytes(isset($service['memory_limit_mb']) ? $service['memory_limit_mb'] : 0);
            $declaredCpu[$name] = $cpu;
            $declaredMemory[$name] = $memory;
            $sumCpu += $cpu;
            $sumMemory += $memory;
        }

        $cpuScale = $sumCpu > 0 ? min(1.0, $totalCpu / $sumCpu) : 0.0;
        $memoryScale = $sumMemory > 0 ? min(1.0, $totalMemory / $sumMemory) : 0.0;
        $remainingCpu = max(0, $totalCpu - (int) round($sumCpu * $cpuScale));
        $remainingMemory = max(0, $totalMemory - (int) round($sumMemory * $memoryScale));

        $undeclared = [];
        foreach ($services as $name => $service) {
            if ($declaredCpu[$name] <= 0 || $declaredMemory[$name] <= 0) {
                $undeclared[] = $name;
            }
        }
        // The primary service takes the larger share of what is left.
        $weights = [];
        $weightTotal = 0;
        foreach ($undeclared as $name) {
            $weights[$name] = $name === $primary ? 3 : 1;
            $weightTotal += $weights[$name];
        }

        $out = [];
        foreach ($services as $name => $service) {
            $cpu = $declaredCpu[$name] > 0 ? (int) round($declaredCpu[$name] * $cpuScale) : 0;
            $memory = $declaredMemory[$name] > 0 ? (int) round($declaredMemory[$name] * $memoryScale) : 0;
            if ($weightTotal > 0 && isset($weights[$name])) {
                if ($cpu <= 0) {
                    $cpu = (int) floor($remainingCpu * $weights[$name] / $weightTotal);
                }
                if ($memory <= 0) {
                    $memory = (int) floor($remainingMemory * $weights[$name] / $weightTotal);
                }
            }
            if ($name === $primary) {
                $cpu = max($cpu, (int) $requirements['cpu_min_millicores']);
                $memory = max($memory, (int) $requirements['memory_min_mb']);
            }
            $cpu = max(50, min($cpu, $totalCpu > 0 ? $totalCpu : $cpu));
            $memory = max(64, min($memory, $totalMemory > 0 ? $totalMemory : $memory));

            $out[$name] = [
                'cpu_millicores' => $cpu,
                'memory_mb' => $memory,
                'limits' => [
                    'cpus' => number_format($cpu / 1000, 2, '.', ''),
                    'memory' => $memory . 'M',
                ],
                // Reservations are half the ceiling: enough for the scheduler to
                // pack a node honestly, not so much that idle apps block capacity.
                'reservations' => [
                    'cpus' => number_format(max(0.05, round($cpu / 2000, 2)), 2, '.', ''),
                    'memory' => max(32, (int) round($memory / 2)) . 'M',
                ],
                'declared' => $declaredCpu[$name] > 0 || $declaredMemory[$name] > 0,
            ];
        }
        return $out;
    }

    /** Bind mounts for one service, rooted in the deployment's own directory. */
    public static function volumeMounts(DeploymentContext $context, $name, array $service)
    {
        $mounts = [];
        $declared = $context->manifest->volumes();

        // Volumes the service declares inline.
        foreach ((array) (isset($service['volumes']) ? $service['volumes'] : []) as $volume) {
            if (is_string($volume)) {
                $parts = explode(':', $volume);
                $volume = ['name' => isset($parts[0]) ? $parts[0] : '',
                    'mount' => isset($parts[1]) ? $parts[1] : ''];
            }
            if (!is_array($volume) || empty($volume['mount'])) {
                continue;
            }
            $volumeName = isset($volume['name']) && $volume['name'] !== '' ? $volume['name'] : 'data';
            $mounts[] = './volumes/' . Str::projectSlug($volumeName, 60) . ':' . self::cleanMount($volume['mount'])
                . (isset($volume['read_only']) && $volume['read_only'] ? ':ro' : '');
        }

        // Volumes declared at manifest level that belong to this service.
        foreach ($declared as $volumeName => $volume) {
            $owners = isset($volume['services']) && $volume['services'] ? $volume['services']
                : [$volume['service']];
            if (!in_array($name, $owners, true)) {
                continue;
            }
            $mount = './volumes/' . Str::projectSlug((string) $volumeName, 60) . ':'
                . self::cleanMount($volume['mount']);
            if (!in_array($mount, $mounts, true)) {
                $mounts[] = $mount;
            }
        }

        return array_values(array_unique($mounts));
    }

    /** A mount target must be an absolute path inside the container. */
    private static function cleanMount($mount)
    {
        $mount = trim((string) $mount);
        if ($mount === '' || $mount[0] !== '/') {
            $mount = '/' . ltrim($mount, '/');
        }
        // No traversal, no host paths: the container path is normalised and the
        // host side is always inside the deployment directory.
        $mount = str_replace(['..', "\0"], '', $mount);
        return rtrim($mount, '/') === '' ? '/' : rtrim($mount, '/');
    }

    /**
     * Environment for the deployment: manifest defaults, generated secrets and
     * customer input merged in that order of precedence (customer wins).
     *
     * @return array<string,string>
     */
    public static function environmentMap(DeploymentContext $context)
    {
        $manifest = $context->manifest;
        $out = [];

        foreach ($manifest->environment()['optional'] as $key => $entry) {
            if (isset($entry['default']) && $entry['default'] !== null && $entry['default'] !== '') {
                $out[$key] = (string) $entry['default'];
            }
        }
        foreach ($context->platformEnvironment() as $key => $value) {
            $out[$key] = (string) $value;
        }
        foreach ($context->environment as $key => $value) {
            $out[$key] = (string) $value;
        }

        // Interpolate ${VAR} references against the merged map so a manifest can
        // compose a URL from the customer's domain without any per-app code.
        foreach ($out as $key => $value) {
            $out[$key] = self::interpolate($value, $out, 0);
        }
        return $out;
    }

    private static function interpolate($value, array $map, $depth)
    {
        if ($depth > 5 || strpos((string) $value, '${') === false) {
            return $value;
        }
        $replaced = preg_replace_callback('/\$\{([A-Za-z0-9_]+)(?::-([^}]*))?\}/', function ($matches) use ($map) {
            $name = $matches[1];
            $fallback = isset($matches[2]) ? $matches[2] : '';
            return array_key_exists($name, $map) && $map[$name] !== '' ? (string) $map[$name] : $fallback;
        }, (string) $value);
        // Unchanged means every remaining reference is a deliberate placeholder
        // (a secret left for compose to interpolate from .env): stop here.
        if ($replaced === null || $replaced === (string) $value) {
            return (string) $value;
        }
        return self::interpolate($replaced, $map, $depth + 1);
    }

    /**
     * Non-secret service environment that is safe to inline in the compose file.
     * Anything secret stays in the env file only.
     */
    private static function inlineEnvironment(array $service, array $environment, array $secretKeys = [])
    {
        // A secret is never written into compose.yaml. That file is readable by
        // every process on the node and is copied into backups; the .env file next
        // to it is 0600 and stays inside the deployment directory. Compose itself
        // interpolates ${VAR} from .env at runtime, so the container still receives
        // the real value while the artifact we store and audit does not.
        $safe = [];
        foreach ($environment as $name => $value) {
            $safe[$name] = in_array((string) $name, $secretKeys, true) ? '${' . $name . '}' : $value;
        }

        $inline = [];
        foreach ((array) (isset($service['environment']) ? $service['environment'] : []) as $key => $value) {
            if (is_int($key)) {
                // "KEY" form: pull the value from the merged environment.
                $name = (string) $value;
                if (array_key_exists($name, $safe)) {
                    $inline[$name] = $safe[$name];
                }
                continue;
            }
            $inline[(string) $key] = self::interpolate((string) $value, $safe, 0);
        }
        return $inline;
    }

    /** The .env file: one KEY=VALUE per line, no quoting, no comments in values. */
    public static function renderEnvFile(DeploymentContext $context)
    {
        $environment = self::environmentMap($context);
        $lines = [
            '# Generated by CloudHost247 App Cloud for installation '
                . (isset($context->installation['reference']) ? $context->installation['reference'] : ''),
            '# Permissions on the server must be 0600: this file holds secrets.',
            'COMPOSE_PROJECT_NAME=' . $context->project,
        ];
        ksort($environment);
        foreach ($environment as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key)) {
                throw new ValidationException('Invalid environment key "' . $key . '".', [
                    'errors' => [$key => 'Environment keys must match [A-Za-z_][A-Za-z0-9_]*'],
                ]);
            }
            if (strpos((string) $value, "\n") !== false || strpos((string) $value, "\r") !== false) {
                throw new ValidationException(
                    'The value for ' . $key . ' contains a line break, which a compose env file cannot represent.',
                    ['errors' => [$key => 'Must be a single line']]
                );
            }
            $lines[] = $key . '=' . $value;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Traefik routing labels for one exposed service.
     *
     * Generated from the customer's actual domain(s) and the manifest's port —
     * never a hardcoded host, and never a port published on the host interface.
     *
     * @return array{labels: string[], router: string, service: string, rule: string,
     *               entrypoints: string[], tls: bool, domains: string[]}
     */
    public static function traefikLabels(DeploymentContext $context, $name, array $service, $proxyNetwork = null)
    {
        $proxyNetwork = $proxyNetwork ?: Settings::string('docker_network', 'ch247app');
        $project = $context->project;
        $router = Str::projectSlug($project . '-' . $name, 60);
        $serviceName = Str::projectSlug($project . '-' . $name, 60);
        $port = (int) $service['port'];

        $domains = [];
        foreach ($context->domains as $domain) {
            if ($domain['domain'] !== '') {
                $domains[] = $domain['domain'];
            }
        }
        $domainConfig = $context->manifest->domain();
        $sslEnabled = $context->sslEnabled() || (isset($domainConfig['ssl']) && (bool) $domainConfig['ssl']);
        $resolver = Settings::string('acme_resolver_name', 'letsencrypt');

        $hosts = [];
        foreach ($domains as $domain) {
            $hosts[] = 'Host(`' . $domain . '`)';
        }
        if ($hosts === []) {
            // No domain attached yet: the deployment is reachable on the platform
            // preview host only, and the labels say so explicitly rather than
            // silently routing everything.
            $previewHost = Settings::string('app_preview_domain', '');
            if ($previewHost !== '') {
                $hosts[] = 'Host(`' . $project . '.' . $previewHost . '`)';
            }
        }
        $rule = $hosts === [] ? 'PathPrefix(`/__no_domain__/' . $project . '`)' : implode(' || ', $hosts);

        $pathPrefix = '';
        foreach ($context->domains as $domain) {
            if ($domain['primary'] && !empty($domain['path_prefix'])) {
                $pathPrefix = (string) $domain['path_prefix'];
                break;
            }
        }
        $middlewares = [];
        if ($pathPrefix !== '') {
            $rule .= ' && PathPrefix(`' . $pathPrefix . '`)';
            $strip = Str::projectSlug($project . '-' . $name . '-strip', 60);
            $middlewares[] = $strip;
        }
        if (Settings::bool('traefik_security_headers', true)) {
            $middlewares[] = Str::projectSlug($project . '-security', 60);
        }

        $labels = [
            'traefik.enable=true',
            'traefik.docker.network=' . $proxyNetwork,
            'traefik.http.routers.' . $router . '.rule=' . $rule,
            'traefik.http.routers.' . $router . '.entrypoints=' . ($sslEnabled
                ? Settings::string('traefik_entrypoint_secure', 'websecure')
                : Settings::string('traefik_entrypoint', 'web')),
            'traefik.http.services.' . $serviceName . '.loadbalancer.server.port=' . $port,
        ];
        if ($sslEnabled) {
            $labels[] = 'traefik.http.routers.' . $router . '.tls=true';
            $labels[] = 'traefik.http.routers.' . $router . '.tls.certresolver=' . $resolver;
            if (!empty($domainConfig['ssl']) && Settings::bool('traefik_tls_options', false)) {
                $labels[] = 'traefik.http.routers.' . $router . '.tls.options=' . $project . '-tls@docker';
            }
        }
        if ($middlewares !== []) {
            $labels[] = 'traefik.http.routers.' . $router . '.middlewares=' . implode(',', $middlewares);
        }
        foreach ($middlewares as $middleware) {
            if (substr($middleware, -6) === '-strip') {
                $labels[] = 'traefik.http.middlewares.' . $middleware . '.stripprefix.prefixes=' . $pathPrefix;
            }
        }
        if (Settings::bool('traefik_security_headers', true)) {
            $security = Str::projectSlug($project . '-security', 60);
            $labels[] = 'traefik.http.middlewares.' . $security . '.headers.stsSeconds=31536000';
            $labels[] = 'traefik.http.middlewares.' . $security . '.headers.stsIncludeSubdomains=true';
            $labels[] = 'traefik.http.middlewares.' . $security . '.headers.frameDeny=true';
            $labels[] = 'traefik.http.middlewares.' . $security . '.headers.contentTypeNosniff=true';
        }

        return [
            'labels' => $labels,
            'router' => $router,
            'service' => $serviceName,
            'rule' => $rule,
            'entrypoints' => [$sslEnabled ? Settings::string('traefik_entrypoint_secure', 'websecure')
                : Settings::string('traefik_entrypoint', 'web')],
            'tls' => (bool) $sslEnabled,
            'cert_resolver' => $sslEnabled ? $resolver : null,
            'domains' => $domains,
            'path_prefix' => $pathPrefix !== '' ? $pathPrefix : null,
            'port' => $port,
        ];
    }

    /** Healthcheck block for one service. */
    public static function healthcheckFor(array $service, $name, \Ch247Apps\Catalog\Manifest $manifest)
    {
        $config = !empty($service['healthcheck']) && is_array($service['healthcheck'])
            ? $service['healthcheck'] : [];
        if ($config === [] && !empty($service['primary'])) {
            $config = $manifest->healthcheck();
        }
        if ($config === [] || !isset($config['type']) || $config['type'] === 'none') {
            return null;
        }

        $interval = Str::clip(isset($config['interval']) ? (string) $config['interval'] : '30s', 8);
        $timeout = Str::clip(isset($config['timeout']) ? (string) $config['timeout'] : '5s', 8);
        $retries = max(1, (int) (isset($config['retries']) ? $config['retries'] : 3));
        $startPeriod = Str::clip(isset($config['start_period']) ? (string) $config['start_period']
            : Settings::string('healthcheck_start_period', '40s'), 8);
        $port = isset($config['port']) && (int) $config['port'] > 0 ? (int) $config['port'] : (int) $service['port'];
        $path = isset($config['path']) && $config['path'] !== '' ? (string) $config['path'] : '/';

        switch ((string) $config['type']) {
            case 'http':
                // wget is present in almost every base image; curl is the fallback
                // the agent can be told about through the manifest command form.
                $test = ['CMD-SHELL',
                    'wget -q --spider --tries=1 --timeout=' . (int) max(1, (int) preg_replace('/[^0-9]/', '', $timeout))
                        . ' http://127.0.0.1:' . $port . $path . ' || exit 1'];
                break;
            case 'tcp':
                $test = ['CMD-SHELL',
                    '(exec 3<>/dev/tcp/127.0.0.1/' . $port . ') 2>/dev/null || exit 1'];
                break;
            case 'command':
                $command = isset($config['command']) ? (string) $config['command'] : '';
                if ($command === '') {
                    return null;
                }
                $test = ['CMD-SHELL', $command];
                break;
            default:
                return null;
        }

        return [
            'test' => $test,
            'interval' => $interval,
            'timeout' => $timeout,
            'retries' => $retries,
            'start_period' => $startPeriod,
        ];
    }
}
