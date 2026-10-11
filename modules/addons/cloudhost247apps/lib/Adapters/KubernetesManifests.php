<?php
/**
 * CloudHost247 App Cloud — manifest to Kubernetes resource renderer.
 *
 * Derives Deployments, Services, an Ingress, Secrets and PersistentVolumeClaims
 * from the compose services, so one manifest stays valid for both engines. The
 * optional `kubernetes:` manifest section only tunes the rendering (replicas,
 * annotations, ingress class, storage class, namespace); it can never inject
 * arbitrary kinds — only the six fixed kinds below are ever emitted, and the
 * validator's container-security rules (no host networking, no privileged
 * mode, no host paths) are re-asserted at render time.
 *
 * Everything that has no Kubernetes equivalent is reported, never silently
 * dropped: build() returns `ignored` and `warnings` lists the adapter surfaces
 * in its step output. Rendering is pure and static so it is fully testable
 * without a cluster.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class KubernetesManifests
{
    /** Fixed kinds this renderer may emit. Nothing else, ever. */
    const KINDS = ['Namespace', 'Secret', 'PersistentVolumeClaim', 'Deployment', 'Service', 'Ingress'];

    const MANAGED_BY = 'cloudhost247-apps';
    const FIELD_MANAGER = 'cloudhost247-apps';
    const MAX_REPLICAS = 32;
    const MAX_NAME = 63;

    /**
     * Render every object for a deployment.
     *
     * @param array $options image_pull_secret (secret name or null)
     * @return array{namespace,secret,image_pull_secret,deployments,services,ingress,pvcs,
     *               labels,ignored,warnings}
     */
    public static function build(DeploymentContext $context, array $options = [])
    {
        $services = $context->manifest->services();
        if ($services === []) {
            throw new ValidationException('That manifest declares no services.', [
                'errors' => ['services' => 'At least one service is required'],
            ]);
        }
        $base = self::baseName($context);
        $namespace = self::namespaceName($context, $base);
        $labels = self::labels($context, $base);
        $overrides = $context->manifest->kubernetes();
        $overrides = is_array($overrides) ? $overrides : [];

        $ignored = [];
        $warnings = [];
        $environment = ComposeBuilder::environmentMap($context);
        $secretKeys = [];
        foreach ($context->environmentMeta as $envKey => $meta) {
            if (!empty($meta['is_secret'])) {
                $secretKeys[] = (string) $envKey;
            }
        }
        $limits = ComposeBuilder::serviceLimits($context);
        $volumes = $context->manifest->volumes();
        $primary = $context->manifest->primaryService();

        $secret = self::envSecret($base, $namespace['name'], $labels, $environment, $secretKeys);
        $pullSecretName = isset($options['image_pull_secret']) ? (string) $options['image_pull_secret'] : '';

        $usedNames = [];
        $deployments = [];
        $k8sServices = [];
        foreach ($services as $serviceName => $service) {
            $slug = self::uniqueSlug((string) $serviceName, $usedNames);
            $deployments[$serviceName] = self::deployment($context, $base, $namespace['name'], $labels,
                (string) $serviceName, $slug, $service, $environment, $secretKeys,
                isset($limits[$serviceName]) ? $limits[$serviceName] : [], $volumes, $secret, $pullSecretName,
                $overrides, $serviceName === $primary, $ignored);
            $port = self::servicePort($service);
            if ($port > 0) {
                $k8sServices[$serviceName] = self::service($base, $namespace['name'], $labels, $slug,
                    $deployments[$serviceName]['name'], $port, $overrides);
            } elseif ($serviceName === $primary) {
                $warnings[] = 'The primary service "' . $serviceName . '" declares no port; no Service or'
                    . ' Ingress is rendered for it.';
            }
        }

        $ingress = self::ingress($context, $base, $namespace['name'], $labels, $primary,
            isset($deployments[$primary]) ? $deployments[$primary] : null,
            isset($k8sServices[$primary]) ? $k8sServices[$primary] : null, $overrides, $ignored, $warnings);

        $pvcs = [];
        foreach ($volumes as $volume) {
            if (empty($volume['backup'])) {
                continue;
            }
            $pvcName = self::objectName($base . '-' . $volume['name']);
            $pvcs[$volume['name']] = [
                'name' => $pvcName,
                'object' => self::persistentVolumeClaim($namespace['name'], $labels, $pvcName,
                    (int) $volume['size_mb'], $overrides),
            ];
        }

        return [
            'namespace' => $namespace,
            'secret' => $secret,
            'image_pull_secret' => null,
            'deployments' => $deployments,
            'services' => $k8sServices,
            'ingress' => $ingress,
            'pvcs' => $pvcs,
            'labels' => $labels,
            'ignored' => $ignored,
            'warnings' => $warnings,
        ];
    }

    /** A dockerconfigjson pull secret for a private registry. */
    public static function imagePullSecret($base, $namespace, $username, $token, $registry,
        array $labels = [])
    {
        $registry = trim((string) $registry) !== '' ? trim((string) $registry) : 'https://index.docker.io/v1/';
        $name = self::imagePullSecretName($base);
        $metadata = ['name' => $name, 'namespace' => $namespace];
        if ($labels !== []) {
            $metadata['labels'] = $labels;
        }
        return [
            'name' => $name,
            'object' => [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => $metadata,
                'type' => 'kubernetes.io/dockerconfigjson',
                'stringData' => [
                    '.dockerconfigjson' => Str::jsonEncode([
                        'auths' => [
                            $registry => [
                                'username' => (string) $username,
                                'password' => (string) $token,
                                'auth' => base64_encode((string) $username . ':' . (string) $token),
                            ],
                        ],
                    ]),
                ],
            ],
        ];
    }

    public static function imagePullSecretName($base)
    {
        return self::objectName($base . '-registry');
    }

    /** Selector labels shared by every object of one installation. */
    public static function labels(DeploymentContext $context, $base)
    {
        return [
            'app.kubernetes.io/instance' => $base,
            'app.kubernetes.io/managed-by' => self::MANAGED_BY,
            'ch247apps.cloudhost247.net/installation-id' => (string) $context->installationId(),
        ];
    }

    public static function labelSelector(array $labels)
    {
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        return implode(',', $parts);
    }

    /* ---------------------------------------------------------------- names */

    /** Per-installation base: the compose project plus the installation id. */
    public static function baseName(DeploymentContext $context)
    {
        return substr((string) $context->project, 0, 30) . '-i' . $context->installationId();
    }

    private static function namespaceName(DeploymentContext $context, $base)
    {
        $overrides = $context->manifest->kubernetes();
        $override = is_array($overrides) && isset($overrides['namespace'])
            ? strtolower(trim((string) $overrides['namespace'])) : '';
        if ($override !== '') {
            if (!preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $override) || strlen($override) > self::MAX_NAME) {
                throw new ValidationException('The declared kubernetes.namespace is not a valid DNS-1123 label.', [
                    'errors' => ['kubernetes.namespace' => 'Use 1–63 lowercase letters, digits and dashes'],
                ]);
            }
            return ['name' => $override, 'managed' => false, 'object' => null];
        }
        $name = self::objectName('ch247-' . $base);
        return [
            'name' => $name,
            'managed' => true,
            'object' => [
                'apiVersion' => 'v1',
                'kind' => 'Namespace',
                'metadata' => [
                    'name' => $name,
                    'labels' => self::labels($context, $base),
                    'annotations' => self::traceAnnotations($context),
                ],
            ],
        ];
    }

    private static function objectName($value)
    {
        $name = strtolower(trim((string) $value));
        $name = preg_replace('/[^a-z0-9-]+/', '-', $name);
        $name = trim((string) $name, '-');
        if ($name === '') {
            $name = 'app';
        }
        return substr($name, 0, self::MAX_NAME);
    }

    private static function uniqueSlug($serviceName, array &$usedNames)
    {
        $slug = strtolower(trim((string) $serviceName));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string) $slug, '-');
        $slug = $slug === '' ? 'svc' : substr($slug, 0, 20);
        $candidate = $slug;
        $suffix = 2;
        while (isset($usedNames[$candidate])) {
            $candidate = substr($slug, 0, 17) . '-' . $suffix++;
        }
        $usedNames[$candidate] = true;
        return $candidate;
    }

    private static function traceAnnotations(DeploymentContext $context)
    {
        return [
            'ch247apps.cloudhost247.net/manifest-hash' => substr($context->manifest->hash(), 0, 63),
            'ch247apps.cloudhost247.net/application' => substr((string) $context->manifest->slug(), 0, 63),
            'ch247apps.cloudhost247.net/version' => substr((string) $context->manifest->version(), 0, 63),
        ];
    }

    /* -------------------------------------------------------------- objects */

    private static function envSecret($base, $namespace, array $labels, array $environment, array $secretKeys)
    {
        $data = [];
        foreach ($secretKeys as $key) {
            if (array_key_exists($key, $environment)) {
                $data[$key] = (string) $environment[$key];
            }
        }
        if ($data === []) {
            return null;
        }
        ksort($data);
        $name = self::objectName($base . '-env');
        return [
            'name' => $name,
            'object' => [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
                'type' => 'Opaque',
                'stringData' => $data,
            ],
        ];
    }

    private static function deployment(DeploymentContext $context, $base, $namespace, array $labels, $serviceName,
        $slug, array $service, array $environment, array $secretKeys, array $limit, array $volumes, $secret,
        $pullSecretName, array $overrides, $isPrimary, array &$ignored)
    {
        $image = trim((string) (isset($service['image']) ? $service['image'] : ''));
        if ($image === '') {
            throw new ValidationException('Service "' . $serviceName . '" declares no image.', [
                'errors' => ['services.' . $serviceName . '.image' => 'An image is required'],
            ]);
        }
        if (!empty($service['network_mode'])) {
            throw new ValidationException('Service "' . $serviceName . '" declares network_mode, which has no'
                . ' Kubernetes equivalent.', [
                    'errors' => ['services.' . $serviceName . '.network_mode' => 'Remove network_mode'],
                ]);
        }
        $name = self::objectName($base . '-' . $slug);
        $serviceLabels = $labels + ['app.kubernetes.io/name' => $name];

        if (!empty($service['depends_on'])) {
            $ignored[] = 'Service "' . $serviceName . '" depends_on has no Kubernetes equivalent; readiness'
                . ' probes gate traffic instead.';
        }
        if (isset($service['restart']) && !in_array($service['restart'], ['', 'unless-stopped', 'always'], true)) {
            $ignored[] = 'Service "' . $serviceName . '" restart "' . $service['restart'] . '" is not rendered;'
                . ' Deployments always restart failed pods.';
        }

        $container = [
            'name' => $slug,
            'image' => $image,
            'env' => self::containerEnv($service, $environment, $secretKeys),
            'resources' => self::containerResources($limit),
        ];
        if ($secret !== null) {
            $container['envFrom'] = [['secretRef' => ['name' => $secret['name']]]];
        }
        $port = self::servicePort($service);
        if ($port > 0) {
            $container['ports'] = [['containerPort' => $port]];
        }
        $entrypoint = self::commandForm(isset($service['entrypoint']) ? $service['entrypoint'] : null);
        if ($entrypoint !== null) {
            $container['command'] = $entrypoint;
        }
        $command = self::commandForm(isset($service['command']) ? $service['command'] : null);
        if ($command !== null) {
            $container['args'] = $command;
        }
        if ($isPrimary) {
            $probe = self::probe($context, $service, $port, $ignored);
            if ($probe !== null) {
                $container['livenessProbe'] = $probe;
                $container['readinessProbe'] = $probe;
            }
        }

        $mounts = [];
        $podVolumes = [];
        foreach ($volumes as $volume) {
            if (!in_array($serviceName, $volume['services'], true)) {
                continue;
            }
            if (!is_string($volume['mount']) || $volume['mount'] === '' || $volume['mount'][0] !== '/') {
                throw new ValidationException('Volume "' . $volume['name'] . '" has no absolute mount path.', [
                    'errors' => ['volumes.' . $volume['name'] . '.mount' => 'An absolute path is required'],
                ]);
            }
            if ($volume['driver'] !== 'local') {
                throw new ValidationException('Volume "' . $volume['name'] . '" uses driver "' . $volume['driver']
                    . '", which has no Kubernetes mapping.', [
                        'errors' => ['volumes.' . $volume['name'] . '.driver' => 'Only local volumes map'],
                    ]);
            }
            $claimName = self::objectName($base . '-' . $volume['name']);
            $mounts[] = ['name' => $claimName, 'mountPath' => $volume['mount']];
            $podVolumes[] = !empty($volume['backup'])
                ? ['name' => $claimName, 'persistentVolumeClaim' => ['claimName' => $claimName]]
                : ['name' => $claimName, 'emptyDir' => new \stdClass()];
        }
        if ($mounts !== []) {
            $container['volumeMounts'] = $mounts;
        }

        $podSpec = ['containers' => [$container]];
        if ($podVolumes !== []) {
            $podSpec['volumes'] = $podVolumes;
        }
        $security = self::securityContext($serviceName, $service);
        if ($security !== null) {
            $podSpec['securityContext'] = $security;
        }
        if ($pullSecretName !== '') {
            $podSpec['imagePullSecrets'] = [['name' => $pullSecretName]];
        }

        return [
            'name' => $name,
            'replicas' => self::replicas($serviceName, $overrides),
            'service' => $serviceName,
            'port' => $port,
            'object' => [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $namespace,
                    'labels' => $serviceLabels,
                    'annotations' => self::traceAnnotations($context)
                        + self::overrideAnnotations($overrides, 'deployment'),
                ],
                'spec' => [
                    'replicas' => self::replicas($serviceName, $overrides),
                    'selector' => ['matchLabels' => $serviceLabels],
                    'strategy' => self::strategy($context, $ignored),
                    'template' => [
                        'metadata' => ['labels' => $serviceLabels],
                        'spec' => $podSpec,
                    ],
                ],
            ],
        ];
    }

    private static function service($base, $namespace, array $labels, $slug, $deploymentName, $port, array $overrides)
    {
        $name = self::objectName($base . '-' . $slug);
        return [
            'name' => $name,
            'object' => [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $namespace,
                    'labels' => $labels,
                    'annotations' => self::overrideAnnotations($overrides, 'service'),
                ],
                'spec' => [
                    'type' => 'ClusterIP',
                    'selector' => $labels + ['app.kubernetes.io/name' => $deploymentName],
                    'ports' => [['port' => $port, 'targetPort' => $port]],
                ],
            ],
        ];
    }

    private static function ingress(DeploymentContext $context, $base, $namespace, array $labels, $primary,
        $deployment, $service, array $overrides, array &$ignored, array &$warnings)
    {
        if (empty($context->domains) || $deployment === null || $service === null) {
            return null;
        }
        $hosts = [];
        foreach ($context->domains as $domain) {
            $hosts[] = strtolower((string) $domain['domain']);
        }
        $rules = [];
        foreach ($context->domains as $domain) {
            $path = isset($domain['path_prefix']) && is_string($domain['path_prefix'])
                && $domain['path_prefix'] !== '' ? $domain['path_prefix'] : '/';
            if ($path === '' || $path[0] !== '/') {
                $path = '/';
            }
            $rules[] = [
                'host' => strtolower((string) $domain['domain']),
                'http' => [
                    'paths' => [[
                        'path' => $path,
                        'pathType' => 'Prefix',
                        'backend' => ['service' => ['name' => $service['name'],
                            'port' => ['number' => $deployment['port']]]],
                    ]],
                ],
            ];
        }
        foreach ($context->manifest->services() as $serviceName => $candidate) {
            if ($serviceName !== $primary && empty($candidate['internal']) && self::servicePort($candidate) > 0) {
                $ignored[] = 'Service "' . $serviceName . '" exposes a port but only the primary service is'
                    . ' routed through the Ingress.';
            }
        }
        $name = self::objectName($base . '-web');
        $spec = ['rules' => $rules];
        if ($context->sslEnabled()) {
            $tlsSecret = $base . '-tls';
            $spec['tls'] = [['hosts' => $hosts, 'secretName' => $tlsSecret]];
            $warnings[] = 'TLS secret "' . $tlsSecret . '" must exist in the namespace (or be issued by a'
                . ' controller declared in kubernetes.annotations); the adapter never invents certificates.';
        }
        if (isset($overrides['ingress_class']) && trim((string) $overrides['ingress_class']) !== '') {
            $spec['ingressClassName'] = substr(trim((string) $overrides['ingress_class']), 0, 63);
        }
        return [
            'name' => $name,
            'object' => [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'Ingress',
                'metadata' => [
                    'name' => $name,
                    'namespace' => $namespace,
                    'labels' => $labels,
                    'annotations' => self::overrideAnnotations($overrides, 'ingress'),
                ],
                'spec' => $spec,
            ],
        ];
    }

    private static function persistentVolumeClaim($namespace, array $labels, $name, $sizeMb, array $overrides)
    {
        $sizeMb = max(64, (int) $sizeMb);
        $spec = [
            'accessModes' => ['ReadWriteOnce'],
            'resources' => ['requests' => ['storage' => self::storageQuantity($sizeMb)]],
        ];
        if (isset($overrides['storage_class']) && trim((string) $overrides['storage_class']) !== '') {
            $spec['storageClassName'] = substr(trim((string) $overrides['storage_class']), 0, 63);
        }
        return [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => $spec,
        ];
    }

    /* --------------------------------------------------------------- mapping */

    private static function servicePort(array $service)
    {
        $port = isset($service['port']) ? (int) $service['port'] : 0;
        return $port >= 1 && $port <= 65535 ? $port : 0;
    }

    /** Non-secret values inline; secrets stay in the Secret and arrive via envFrom. */
    private static function containerEnv(array $service, array $environment, array $secretKeys)
    {
        $merged = [];
        foreach ($environment as $name => $value) {
            if (!in_array((string) $name, $secretKeys, true)) {
                $merged[(string) $name] = (string) $value;
            }
        }
        $safe = $environment;
        foreach ((array) (isset($service['environment']) ? $service['environment'] : []) as $key => $value) {
            if (is_int($key)) {
                $name = (string) $value;
                if (array_key_exists($name, $merged)) {
                    $merged[$name] = $merged[$name];
                } elseif (array_key_exists($name, $safe) && !in_array($name, $secretKeys, true)) {
                    $merged[$name] = (string) $safe[$name];
                }
                continue;
            }
            // A manifest-authored override for a secret key must not inline the
            // value; the Secret (via envFrom) already carries the real one.
            if (!in_array((string) $key, $secretKeys, true)) {
                $merged[(string) $key] = ComposeBuilder::interpolate((string) $value, $safe, 0);
            }
        }
        ksort($merged);
        $out = [];
        foreach ($merged as $name => $value) {
            $out[] = ['name' => $name, 'value' => $value];
        }
        return $out;
    }

    private static function containerResources(array $limit)
    {
        $cpu = isset($limit['cpu_millicores']) ? max(50, (int) $limit['cpu_millicores']) : 50;
        $memory = isset($limit['memory_mb']) ? max(64, (int) $limit['memory_mb']) : 64;
        return [
            'requests' => [
                'cpu' => max(25, (int) round($cpu / 2)) . 'm',
                'memory' => max(32, (int) round($memory / 2)) . 'Mi',
            ],
            'limits' => ['cpu' => $cpu . 'm', 'memory' => $memory . 'Mi'],
        ];
    }

    /** The manifest health check, applied to the primary service only. */
    private static function probe(DeploymentContext $context, array $service, $port, array &$ignored)
    {
        $health = $context->manifest->healthcheck();
        $probePort = $health['port'] > 0 ? $health['port'] : $port;
        switch ($health['type']) {
            case 'http':
            case 'https':
                if ($probePort <= 0) {
                    $ignored[] = 'The http health check has no port; no probes are rendered.';
                    return null;
                }
                $probe = ['httpGet' => ['path' => $health['path'], 'port' => $probePort]];
                if ($health['type'] === 'https') {
                    $probe['httpGet']['scheme'] = 'HTTPS';
                }
                break;
            case 'tcp':
                if ($probePort <= 0) {
                    $ignored[] = 'The tcp health check has no port; no probes are rendered.';
                    return null;
                }
                $probe = ['tcpSocket' => ['port' => $probePort]];
                break;
            case 'exec':
                if (trim($health['command']) === '') {
                    $ignored[] = 'The exec health check has no command; no probes are rendered.';
                    return null;
                }
                $probe = ['exec' => ['command' => ['sh', '-c', $health['command']]]];
                break;
            default:
                return null;
        }
        $probe['periodSeconds'] = max(1, (int) $health['interval']);
        $probe['timeoutSeconds'] = max(1, (int) $health['timeout']);
        $probe['failureThreshold'] = max(1, (int) $health['retries']);
        $probe['successThreshold'] = 1;
        $probe['initialDelaySeconds'] = max(1, (int) $health['interval']);
        return $probe;
    }

    private static function commandForm($value)
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_string($value)) {
            return ['sh', '-c', $value];
        }
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach (array_values($value) as $part) {
            if (is_scalar($part)) {
                $out[] = (string) $part;
            }
        }
        return $out === [] ? null : $out;
    }

    /** Numeric UIDs map to runAsUser/runAsGroup; names fail closed. */
    private static function securityContext($serviceName, array $service)
    {
        if (!isset($service['user']) || $service['user'] === null || trim((string) $service['user']) === '') {
            return null;
        }
        $parts = explode(':', (string) $service['user'], 2);
        if (!preg_match('/^[0-9]{1,10}$/', $parts[0])) {
            throw new ValidationException('Service "' . $serviceName . '" runs as user "' . $parts[0]
                . '"; only numeric UIDs map to Kubernetes.', [
                    'errors' => ['services.' . $serviceName . '.user' => 'Use a numeric UID'],
                ]);
        }
        $security = ['runAsUser' => (int) $parts[0], 'runAsNonRoot' => (int) $parts[0] !== 0];
        if (isset($parts[1]) && preg_match('/^[0-9]{1,10}$/', $parts[1])) {
            $security['runAsGroup'] = (int) $parts[1];
        }
        return $security;
    }

    private static function strategy(DeploymentContext $context, array &$ignored)
    {
        switch ($context->manifest->updateStrategy()) {
            case 'recreate':
                return ['type' => 'Recreate'];
            case 'rolling':
                return ['type' => 'RollingUpdate',
                    'rollingUpdate' => ['maxUnavailable' => 0, 'maxSurge' => 1]];
            default:
                $ignored[] = 'Update strategy "' . $context->manifest->updateStrategy() . '" has no Kubernetes'
                    . ' equivalent; Deployments use Recreate.';
                return ['type' => 'Recreate'];
        }
    }

    private static function replicas($serviceName, array $overrides)
    {
        $replicas = 1;
        if (isset($overrides['replicas'])) {
            $replicas = $overrides['replicas'];
        }
        if (isset($overrides['services'][$serviceName]['replicas'])) {
            $replicas = $overrides['services'][$serviceName]['replicas'];
        }
        if (!is_int($replicas) && !(is_string($replicas) && preg_match('/^[0-9]+$/', $replicas))) {
            throw new ValidationException('Replica counts must be whole numbers.', [
                'errors' => ['kubernetes.replicas' => 'Use 1–' . self::MAX_REPLICAS],
            ]);
        }
        $replicas = (int) $replicas;
        if ($replicas < 1 || $replicas > self::MAX_REPLICAS) {
            throw new ValidationException('Replica counts must stay within 1–' . self::MAX_REPLICAS . '.', [
                'errors' => ['kubernetes.replicas' => 'Use 1–' . self::MAX_REPLICAS],
            ]);
        }
        return $replicas;
    }

    /** Validated annotation overrides; keys must be qualified names. */
    private static function overrideAnnotations(array $overrides, $scope)
    {
        if (!isset($overrides['annotations'][$scope]) || !is_array($overrides['annotations'][$scope])) {
            return [];
        }
        $out = [];
        foreach ($overrides['annotations'][$scope] as $key => $value) {
            $key = (string) $key;
            if (strlen($key) > 253 || !preg_match('|^[a-z0-9]([-a-z0-9.]*[a-z0-9])?(/[a-z0-9]([-a-z0-9.]*[a-z0-9])?)?$|i', $key)) {
                throw new ValidationException('Annotation "' . Str::clip($key, 80) . '" is not a qualified name.', [
                    'errors' => ['kubernetes.annotations.' . $scope => 'Use qualified annotation names'],
                ]);
            }
            if (!is_scalar($value) || strlen((string) $value) > 4096) {
                throw new ValidationException('Annotation "' . Str::clip($key, 80) . '" exceeds 4 KB.', [
                    'errors' => ['kubernetes.annotations.' . $scope => 'Keep values under 4 KB'],
                ]);
            }
            $out[$key] = (string) $value;
        }
        return $out;
    }

    private static function storageQuantity($sizeMb)
    {
        if ($sizeMb < 1024) {
            return $sizeMb . 'Mi';
        }
        return (int) ceil($sizeMb / 1024) . 'Gi';
    }
}
