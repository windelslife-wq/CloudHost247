<?php
/**
 * Suite 28 — the Kubernetes application deployment adapter.
 *
 * Covers the renderer (manifest services become Namespaces, Secrets,
 * Deployments, Services, Ingresses and PVCs — and only those), the worker-only
 * API transport (kubeconfig parsing, TLS handling, error taxonomy, waits) and
 * the adapter behind the `kubernetes` engine (plans, applies, rollouts,
 * scaling, reads, rollback and destroy).
 *
 * The cluster API is a stateful in-memory fake: applied objects persist, label
 * selectors filter, and 404s answer with real Status documents, so adoption
 * rules, convergence and collection deletes are asserted against observable
 * state.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Adapters\AdapterFactory;
use Ch247Apps\Adapters\DeploymentContext;
use Ch247Apps\Adapters\KubernetesAdapter;
use Ch247Apps\Adapters\KubernetesClient;
use Ch247Apps\Adapters\KubernetesManifests;
use Ch247Apps\Catalog\Manifest;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\HealthCheckException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\KubernetesException;
use Ch247Apps\Core\RetryableKubernetesException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\EnvironmentService;
use Ch247Apps\Servers\ServerService;

Harness::boot();
Harness::relaxRateLimits();
Settings::override('kubernetes_enabled', '1');

$worker = Actor::system('Worker');
$super = Harness::adminActor(1);
$now = Clock::now();

/* ------------------------------------------------------------- fixtures */

function k8sYaml($strategy = 'rolling', $replicas = 2)
{
    return <<<YAML
schema: 1
id: demo-web
name: Demo Web
slug: demo-web
category: web
kind: application
version: 1.4.0
channel: stable
deployment:
  engine: kubernetes
  supported_hosting_types: [kubernetes]
  update_strategy: {$strategy}
requirements:
  cpu_min: 1
  memory_min_mb: 1024
  storage_min_mb: 5120
services:
  web:
    image: registry.example.test/demo-web:1.4.0
    port: 8080
    primary: true
    environment:
      APP_MODE: production
      APP_URL: https://\${PRIMARY_DOMAIN}
  worker:
    image: registry.example.test/demo-worker:1.4.0
    internal: true
    depends_on: [web]
volumes:
  - name: uploads
    service: web
    mount: /var/uploads
    size_mb: 512
    backup: true
  - name: cache
    service: web
    mount: /tmp/cache
    backup: false
healthcheck:
  type: http
  path: /healthz
  port: 8080
  interval: 30s
  timeout: 5s
  retries: 3
domain:
  enabled: true
ssl:
  enabled: true
kubernetes:
  replicas: {$replicas}
  ingress_class: nginx
  storage_class: fast
  annotations:
    ingress:
      cert-manager.io/cluster-issuer: letsencrypt
YAML;
}

function kubeconfigJson($server = 'https://k8s.example.test:6443')
{
    return Str::jsonEncode([
        'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'test',
        'contexts' => [['name' => 'test', 'context' => ['cluster' => 'test', 'user' => 'test']]],
        'clusters' => [['name' => 'test', 'cluster' => ['server' => $server]]],
        'users' => [['name' => 'test', 'user' => ['token' => 'K8S-BEARER-PRIVATE-1234567890']]],
    ]);
}

$appSeq = 0;
$makeVersion = function ($yaml) use (&$appSeq, $now) {
    $appSeq++;
    $appId = Db::insert('applications', [
        'name' => 'K8s Fixture ' . $appSeq, 'slug' => 'k8s-fixture-' . $appSeq,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $versionId = Db::insert('application_versions', [
        'application_id' => $appId, 'version' => '1.4.' . $appSeq, 'channel' => 'stable',
        'manifest' => $yaml, 'manifest_hash' => Manifest::fromYaml($yaml)->hash(),
        'status' => 'published', 'created_at' => $now,
    ]);
    return [$appId, $versionId];
};

$installSeq = 0;
$makeInstallation = function ($serverId, $appId, $versionId, $domain = 'app.example.test') use (&$installSeq, $now) {
    $installSeq++;
    $installationId = Db::insert('installations', [
        'reference' => 'APP-K8S-' . $installSeq, 'uuid' => Str::uuid4(), 'customer_id' => 42,
        'application_id' => $appId, 'application_version_id' => $versionId,
        'server_id' => $serverId, 'name' => 'K8s install ' . $installSeq,
        'container_project' => 'c42k8s' . $installSeq, 'domain' => $domain,
        'adapter' => 'kubernetes', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $domainId = Db::insert('domains', [
        'customer_id' => 42, 'domain' => $domain, 'created_at' => $now, 'updated_at' => $now,
    ]);
    Db::insert('installation_domains', [
        'installation_id' => $installationId, 'domain_id' => $domainId,
        'primary_domain' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    return $installationId;
};

$k8sServer = (new ServerService($super))->register([
    'name' => 'k8s adapter test cluster', 'hostname' => 'k8s.example.test',
    'ip_address' => '198.51.100.70', 'server_type' => 'kubernetes', 'kubernetes_enabled' => true,
    'credentials' => ['kube_config' => ['secret' => kubeconfigJson()]],
]);

list($appId, $versionId) = $makeVersion(k8sYaml());
$installationId = $makeInstallation((int) $k8sServer['id'], $appId, $versionId);
$environment = new EnvironmentService($worker);
$environment->write($installationId, 'APP_SECRET', 'env-secret-private', [
    'platform' => true, 'is_secret' => true, 'source' => 'platform', 'locked' => true,
]);
$environment->write($installationId, 'APP_MODE', 'staging', [
    'platform' => true, 'is_secret' => false, 'source' => 'platform', 'locked' => true,
]);

$context = DeploymentContext::load($installationId, 'install', $worker, ['deployment_id' => 9]);
$noSleep = function ($seconds) {
};
$client = new KubernetesClient($worker, null, $noSleep);
$adapter = new KubernetesAdapter($worker, $client);

// A stateful cluster fake: applied objects persist and list calls filter.
$k8sObjects = [];
$k8sCalls = [];
$k8sFailures = [];
$fakeK8s = function ($method, $url, $options) use (&$k8sObjects, &$k8sCalls, &$k8sFailures) {
    $path = (string) parse_url((string) $url, PHP_URL_PATH);
    $query = [];
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    $k8sCalls[] = ['method' => strtoupper((string) $method), 'path' => $path,
        'query' => $query, 'options' => $options];
    $key = strtoupper((string) $method) . ' ' . $path;
    if (isset($k8sFailures[$key])) {
        $failure = $k8sFailures[$key];
        if (is_callable($failure)) {
            $failure = call_user_func($failure, $query, $options);
        }
        if ($failure !== null) {
            return $failure;
        }
    }
    $json = function ($data, $status = 200) {
        return ['status' => $status, 'headers' => [], 'body' => Str::jsonEncode($data), 'error' => null];
    };
    $notFound = function ($what) use ($json) {
        return $json(['kind' => 'Status', 'apiVersion' => 'v1', 'status' => 'Failure',
            'message' => $what . ' not found', 'reason' => 'NotFound', 'code' => 404], 404);
    };
    $matches = function ($object, $selector) {
        if ($selector === '') {
            return true;
        }
        $labels = isset($object['metadata']['labels']) && is_array($object['metadata']['labels'])
            ? $object['metadata']['labels'] : [];
        foreach (explode(',', $selector) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2 || !isset($labels[$parts[0]]) || (string) $labels[$parts[0]] !== $parts[1]) {
                return false;
            }
        }
        return true;
    };
    $namespaceMissing = function ($namespace) use (&$k8sObjects) {
        return !isset($k8sObjects['namespace/' . $namespace]);
    };
    // Pod logs are text, like the real endpoint.
    if (preg_match('|/api/v1/namespaces/([^/]+)/pods/([^/]+)/log$|', $path, $match)) {
        if ($namespaceMissing($match[1])) {
            return $notFound('namespace "' . $match[1] . '"');
        }
        $podKey = 'pod/' . $match[1] . '/' . $match[2];
        if (!isset($k8sObjects[$podKey])) {
            return $notFound('pod "' . $match[2] . '"');
        }
        $lines = [];
        $tail = isset($query['tailLines']) ? max(1, (int) $query['tailLines']) : 200;
        for ($i = 1; $i <= $tail; $i++) {
            $lines[] = '2026-10-11T00:00:0' . ($i % 10) . 'Z log line ' . $i;
        }
        return ['status' => 200, 'headers' => [], 'body' => implode("\n", $lines), 'error' => null];
    }
    if (preg_match('|/api/v1/namespaces/([^/]+)/pods$|', $path, $match) && strtoupper((string) $method) === 'GET') {
        if ($namespaceMissing($match[1])) {
            return $notFound('namespace "' . $match[1] . '"');
        }
        $items = [];
        foreach ($k8sObjects as $storeKey => $object) {
            if (strpos($storeKey, 'pod/' . $match[1] . '/') === 0
                && $matches($object, isset($query['labelSelector']) ? $query['labelSelector'] : '')) {
                $items[] = $object;
            }
        }
        return $json(['kind' => 'PodList', 'apiVersion' => 'v1', 'items' => $items]);
    }
    if (preg_match('|/apis/metrics.k8s.io/v1beta1/namespaces/([^/]+)/pods$|', $path, $match)) {
        if ($namespaceMissing($match[1])) {
            return $notFound('namespace "' . $match[1] . '"');
        }
        // One healthy pod and one pod whose agent reports garbage: invalid
        // quantities must count as zero, never abort the aggregation.
        return $json(['kind' => 'PodMetricsList', 'apiVersion' => 'metrics.k8s.io/v1beta1', 'items' => [
            ['metadata' => ['name' => 'metrics-ok'],
                'containers' => [['name' => 'c', 'usage' => ['cpu' => '250m', 'memory' => '128Mi']]]],
            ['metadata' => ['name' => 'metrics-garbage'],
                'containers' => [['name' => 'c', 'usage' => ['cpu' => 'bogus', 'memory' => 'also-bogus']]]],
        ]]);
    }
    // Reads pretend the cluster converges instantly: stored deployments
    // report fully available unless a scripted failure says otherwise.
    $withStatus = function ($object) {
        if (isset($object['kind']) && $object['kind'] === 'Deployment') {
            $want = isset($object['spec']['replicas']) ? (int) $object['spec']['replicas'] : 1;
            $object['metadata']['generation'] = 1;
            $object['status'] = ['observedGeneration' => 1, 'replicas' => $want,
                'updatedReplicas' => $want, 'availableReplicas' => $want];
        }
        return $object;
    };
    // Scale subresource.
    if (preg_match('|/namespaces/([^/]+)/deployments/([^/]+)/scale$|', $path, $match)) {
        if ($namespaceMissing($match[1])) {
            return $notFound('namespace "' . $match[1] . '"');
        }
        $storeKey = 'deployment/' . $match[1] . '/' . $match[2];
        if (!isset($k8sObjects[$storeKey])) {
            return $notFound('deployment "' . $match[2] . '"');
        }
        if (strtoupper((string) $method) === 'PATCH') {
            $patch = json_decode(isset($options['body']) ? (string) $options['body'] : '', true);
            if (isset($patch['spec']['replicas'])) {
                $k8sObjects[$storeKey]['spec']['replicas'] = (int) $patch['spec']['replicas'];
            }
        }
        return $json(['kind' => 'Scale', 'apiVersion' => 'apps/v1',
            'spec' => ['replicas' => $k8sObjects[$storeKey]['spec']['replicas']]]);
    }
    // Named object routes.
    $routes = [
        '|/api/v1/namespaces/([^/]+)$|' => 'namespace',
        '|/api/v1/namespaces/([^/]+)/secrets/([^/]+)$|' => 'secret',
        '|/api/v1/namespaces/([^/]+)/services/([^/]+)$|' => 'service',
        '|/api/v1/namespaces/([^/]+)/persistentvolumeclaims/([^/]+)$|' => 'pvc',
        '|/apis/apps/v1/namespaces/([^/]+)/deployments/([^/]+)$|' => 'deployment',
        '|/apis/networking.k8s.io/v1/namespaces/([^/]+)/ingresses/([^/]+)$|' => 'ingress',
    ];
    foreach ($routes as $pattern => $kind) {
        if (!preg_match($pattern, $path, $match)) {
            continue;
        }
        $storeKey = $kind === 'namespace'
            ? 'namespace/' . $match[1]
            : $kind . '/' . $match[1] . '/' . $match[2];
        $verb = strtoupper((string) $method);
        if ($verb === 'GET') {
            if ($kind !== 'namespace' && $namespaceMissing($match[1])) {
                return $notFound('namespace "' . $match[1] . '"');
            }
            return isset($k8sObjects[$storeKey]) ? $json($withStatus($k8sObjects[$storeKey])) : $notFound($kind);
        }
        if ($verb === 'DELETE') {
            if (!isset($k8sObjects[$storeKey])) {
                return $notFound($kind);
            }
            unset($k8sObjects[$storeKey]);
            return $json(['kind' => 'Status', 'apiVersion' => 'v1', 'status' => 'Success']);
        }
        if ($verb === 'POST' || $verb === 'PUT' || $verb === 'PATCH') {
            $body = json_decode(isset($options['body']) ? (string) $options['body'] : '', true);
            $body = is_array($body) ? $body : [];
            if ($verb === 'PATCH' && isset($body['spec']['template']['metadata']['annotations'])) {
                // Strategic merge: fold the restart annotation into the stored object.
                $existing = isset($k8sObjects[$storeKey]) ? $k8sObjects[$storeKey] : [];
                $existingAnnotations = isset($existing['spec']['template']['metadata']['annotations'])
                    ? $existing['spec']['template']['metadata']['annotations'] : [];
                $sentAnnotations = $body['spec']['template']['metadata']['annotations'];
                $body = $existing;
                $body['spec']['template']['metadata']['annotations'] = array_merge($existingAnnotations,
                    $sentAnnotations);
            } elseif ($verb === 'PATCH' && isset($k8sObjects[$storeKey])) {
                $body = array_replace_recursive($k8sObjects[$storeKey], $body);
            }
            $k8sObjects[$storeKey] = $body;
            return $json($body);
        }
    }
    // Collection routes (list + label-selector delete).
    $collections = [
        '|/api/v1/namespaces/([^/]+)/secrets$|' => 'secret',
        '|/api/v1/namespaces/([^/]+)/services$|' => 'service',
        '|/api/v1/namespaces/([^/]+)/persistentvolumeclaims$|' => 'pvc',
        '|/apis/apps/v1/namespaces/([^/]+)/deployments$|' => 'deployment',
        '|/apis/networking.k8s.io/v1/namespaces/([^/]+)/ingresses$|' => 'ingress',
    ];
    foreach ($collections as $pattern => $kind) {
        if (!preg_match($pattern, $path, $match)) {
            continue;
        }
        $verb = strtoupper((string) $method);
        $selector = isset($query['labelSelector']) ? (string) $query['labelSelector'] : '';
        if ($verb === 'GET' || $verb === 'DELETE') {
            if ($namespaceMissing($match[1])) {
                return $notFound('namespace "' . $match[1] . '"');
            }
        }
        if ($verb === 'GET') {
            $items = [];
            foreach ($k8sObjects as $storeKey => $object) {
                if (strpos($storeKey, $kind . '/' . $match[1] . '/') === 0 && $matches($object, $selector)) {
                    $items[] = $withStatus($object);
                }
            }
            return $json(['kind' => 'List', 'apiVersion' => 'v1', 'items' => $items]);
        }
        if ($verb === 'DELETE') {
            foreach (array_keys($k8sObjects) as $storeKey) {
                if (strpos($storeKey, $kind . '/' . $match[1] . '/') === 0
                    && $matches($k8sObjects[$storeKey], $selector)) {
                    unset($k8sObjects[$storeKey]);
                }
            }
            return $json(['kind' => 'Status', 'apiVersion' => 'v1', 'status' => 'Success']);
        }
        if ($verb === 'POST') {
            $body = json_decode(isset($options['body']) ? (string) $options['body'] : '', true);
            $body = is_array($body) ? $body : [];
            if (isset($body['metadata']['name'])) {
                $k8sObjects[$kind . '/' . $match[1] . '/' . $body['metadata']['name']] = $body;
            }
            return $json($body);
        }
    }
    if ($path === '/api/v1/namespaces' && strtoupper((string) $method) === 'POST') {
        $body = json_decode(isset($options['body']) ? (string) $options['body'] : '', true);
        $body = is_array($body) ? $body : [];
        if (isset($body['metadata']['name'])) {
            $k8sObjects['namespace/' . $body['metadata']['name']] = $body;
        }
        return $json($body);
    }
    return ['status' => 404, 'headers' => [], 'body' => 'unexpected endpoint ' . $path, 'error' => null];
};
Http::setClientFake($fakeK8s);

/* ------------------------------------------------- renderer: happy path */

section('The renderer derives fixed-kind objects from the compose services');

$artifacts = KubernetesManifests::build($context);
$base = KubernetesManifests::baseName($context);
T::ok('the base name is DNS safe', (bool) preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $base));
T::ok('the base carries the installation id', substr($base, -2) === '-i' . $installationId
    || strpos($base, '-i' . $installationId) !== false);
T::is('the namespace derives from the base', 'ch247-' . $base, $artifacts['namespace']['name']);
T::ok('derived namespaces are managed', $artifacts['namespace']['managed'] === true);
T::is('namespace object kind', 'Namespace', $artifacts['namespace']['object']['kind']);
T::is('managed-by label', 'cloudhost247-apps', $artifacts['labels']['app.kubernetes.io/managed-by']);

foreach (['namespace', 'secret', 'deployments', 'services', 'ingress', 'pvcs'] as $group) {
    $objects = $group === 'deployments' || $group === 'services' || $group === 'pvcs'
        ? array_column($artifacts[$group], 'object')
        : ($artifacts[$group] !== null ? [$artifacts[$group]['object']] : []);
    foreach ($objects as $object) {
        T::ok('only fixed kinds are emitted (' . $object['kind'] . ')',
            in_array($object['kind'], KubernetesManifests::KINDS, true));
    }
}

$secret = $artifacts['secret'];
T::ok('a secret is rendered', $secret !== null);
T::ok('secret keys land in stringData', isset($secret['object']['stringData']['APP_SECRET']));
T::ok('plain values never land in the secret',
    !isset($secret['object']['stringData']['APP_MODE']));

$web = $artifacts['deployments']['web'];
T::is('deployment apiVersion', 'apps/v1', $web['object']['apiVersion']);
T::is('the image passes through', 'registry.example.test/demo-web:1.4.0',
    $web['object']['spec']['template']['spec']['containers'][0]['image']);
T::is('manifest replicas win', 2, $web['object']['spec']['replicas']);
T::is('rolling strategy maps with zero unavailable', 0,
    $web['object']['spec']['strategy']['rollingUpdate']['maxUnavailable']);
$container = $web['object']['spec']['template']['spec']['containers'][0];
T::ok('secrets arrive via envFrom', $container['envFrom'][0]['secretRef']['name'] === $secret['name']);
$envNames = array_column($container['env'], 'name');
T::notContains('secrets are never inline', 'APP_SECRET', $envNames);
T::contains('plain values are inline', 'APP_MODE', $envNames);
T::is('service values beat context values', 'production',
    $container['env'][array_search('APP_MODE', $envNames)]['value']);
T::is('service references interpolate against the merged map', 'https://app.example.test',
    $container['env'][array_search('APP_URL', $envNames)]['value']);
T::is('http probes map to httpGet', '/healthz',
    $container['livenessProbe']['httpGet']['path']);
T::is('probe intervals map', 30, $container['livenessProbe']['periodSeconds']);
T::ok('limits carry millicore and megabyte quantities',
    strpos($container['resources']['limits']['cpu'], 'm') !== false
    && strpos($container['resources']['limits']['memory'], 'Mi') !== false);
$mounts = array_column($container['volumeMounts'], 'mountPath');
T::contains('persistent volumes mount', '/var/uploads', $mounts);
T::contains('ephemeral volumes mount', '/tmp/cache', $mounts);
T::is('one PVC for the backed volume', 1, count($artifacts['pvcs']));
$pvc = reset($artifacts['pvcs']);
T::is('PVC storage converts megabytes', '512Mi',
    $pvc['object']['spec']['resources']['requests']['storage']);
T::is('storage class override applies', 'fast', $pvc['object']['spec']['storageClassName']);

$workerDeployment = $artifacts['deployments']['worker'];
T::ok('internal services still deploy', isset($workerDeployment['object']));
T::ok('services without ports get no Service', !isset($artifacts['services']['worker']));
T::ok('the web service is ClusterIP', $artifacts['services']['web']['object']['spec']['type'] === 'ClusterIP');

$ingress = $artifacts['ingress'];
T::is('ingress apiVersion', 'networking.k8s.io/v1', $ingress['object']['apiVersion']);
T::is('ingress host', 'app.example.test', $ingress['object']['spec']['rules'][0]['host']);
T::is('ingress class override applies', 'nginx', $ingress['object']['spec']['ingressClassName']);
T::is('TLS references the derived secret', $base . '-tls',
    $ingress['object']['spec']['tls'][0]['secretName']);
T::is('ingress annotations pass validation', 'letsencrypt',
    $ingress['object']['metadata']['annotations']['cert-manager.io/cluster-issuer']);
T::ok('a TLS warning names the secret', (bool) preg_grep('/' . preg_quote($base . '-tls', '/') . '/',
    $artifacts['warnings']));
T::ok('depends_on is reported, not dropped', (bool) preg_grep('/depends_on/', $artifacts['ignored']));

/* ------------------------------------------- renderer: failure modes */

section('The renderer fails closed on whatever it cannot honestly map');

$preview = function (array $data) use ($worker) {
    $data = array_merge(['id' => 'preview-app', 'slug' => 'preview-app'], $data);
    return DeploymentContext::preview(new Manifest($data, ''), ['id' => 0, 'customer_id' => 42], [], [], $worker);
};
$oneService = ['deployment' => ['engine' => 'kubernetes'],
    'services' => ['web' => ['image' => 'registry.example.test/a:1', 'port' => 8080, 'primary' => true]]];

T::throws('manifests without services fail closed', ValidationException::class, function () use ($preview) {
    KubernetesManifests::build($preview(['deployment' => ['engine' => 'kubernetes']]));
});
$noImage = $oneService;
$noImage['services']['web']['image'] = '';
T::throws('a missing image fails closed', ValidationException::class, function () use ($preview, $noImage) {
    KubernetesManifests::build($preview($noImage));
});
$hostNetwork = $oneService;
$hostNetwork['services']['web']['network_mode'] = 'host';
T::throws('host networking fails closed', ValidationException::class, function () use ($preview, $hostNetwork) {
    KubernetesManifests::build($preview($hostNetwork));
});
$namedUser = $oneService;
$namedUser['services']['web']['user'] = 'nobody';
T::throws('named users fail closed', ValidationException::class, function () use ($preview, $namedUser) {
    KubernetesManifests::build($preview($namedUser));
});
$numericUser = $oneService;
$numericUser['services']['web']['user'] = '1000:1000';
$uidArtifacts = KubernetesManifests::build($preview($numericUser));
T::is('numeric UIDs map to runAsUser', 1000,
    $uidArtifacts['deployments']['web']['object']['spec']['template']['spec']['securityContext']['runAsUser']);
$manyReplicas = $oneService;
$manyReplicas['kubernetes'] = ['replicas' => 64];
T::throws('absurd replica counts fail closed', ValidationException::class, function () use ($preview, $manyReplicas) {
    KubernetesManifests::build($preview($manyReplicas));
});
$badAnnotation = $oneService;
$badAnnotation['kubernetes'] = ['annotations' => ['ingress' => ['not an annotation!' => 'x']]];
$annotated = $preview($badAnnotation);
$annotated->domains = [['domain' => 'annotated.example.test', 'primary' => true, 'path_prefix' => null]];
T::throws('invalid annotations fail closed', ValidationException::class, function () use ($annotated) {
    KubernetesManifests::build($annotated);
});
$namespaceOverride = $oneService;
$namespaceOverride['kubernetes'] = ['namespace' => 'shared-team'];
$nsArtifacts = KubernetesManifests::build($preview($namespaceOverride));
T::is('declared namespaces are honoured', 'shared-team', $nsArtifacts['namespace']['name']);
T::ok('declared namespaces are never managed', $nsArtifacts['namespace']['managed'] === false);

T::is('rolling is the rendered strategy', 'RollingUpdate',
    $artifacts['deployments']['web']['object']['spec']['strategy']['type']);
$manual = $oneService;
$manual['deployment']['update_strategy'] = 'manual';
$manualArtifacts = KubernetesManifests::build($preview($manual));
T::is('manual degrades to Recreate', 'Recreate',
    $manualArtifacts['deployments']['web']['object']['spec']['strategy']['type']);
T::ok('the degradation is reported', (bool) preg_grep('/manual/', $manualArtifacts['ignored']));

$pull = KubernetesManifests::imagePullSecret($base, 'somens', 'robot', 'tok', '');
T::is('pull secrets use dockerconfigjson', 'kubernetes.io/dockerconfigjson', $pull['object']['type']);
T::ok('pull secret names derive from the base', strpos($pull['name'], $base) === 0);

/* ------------------------------------------------- transport: config */

section('The transport parses kubeconfigs strictly and fails closed');

$connection = $client->connect((int) $k8sServer['id']);
T::is('the server URL parses', 'https://k8s.example.test:6443', $connection['server_url']);
T::is('bearer auth is detected', 'bearer', $connection['auth']);

$badServer = (new ServerService($super))->register([
    'name' => 'no kubeconfig', 'hostname' => 'empty.example.test', 'ip_address' => '198.51.100.71',
    'server_type' => 'kubernetes', 'kubernetes_enabled' => true,
]);
T::throws('a missing kubeconfig fails closed', ConfigurationException::class,
    function () use ($client, $badServer) {
        $client->connect((int) $badServer['id']);
    });

$configVariants = [
    'insecure' => ['cluster' => ['server' => 'https://k8s.example.test:6443', 'insecure-skip-tls-verify' => true],
        'code' => 'KUBECONFIG_INSECURE'],
    'http' => ['cluster' => ['server' => 'http://k8s.example.test:8080'], 'code' => 'KUBECONFIG_SERVER_INVALID'],
    'exec' => ['user' => ['exec' => ['command' => 'aws']], 'code' => 'KUBECONFIG_AUTH_UNSUPPORTED'],
];
foreach ($configVariants as $label => $variant) {
    $config = json_decode(kubeconfigJson(), true);
    if (isset($variant['cluster'])) {
        $config['clusters'][0]['cluster'] = $variant['cluster'];
    }
    if (isset($variant['user'])) {
        $config['users'][0]['user'] = $variant['user'];
    }
    $variantServer = (new ServerService($super))->register([
        'name' => 'kubeconfig ' . $label, 'hostname' => $label . '.example.test',
        'ip_address' => '198.51.100.72', 'server_type' => 'kubernetes', 'kubernetes_enabled' => true,
        'credentials' => ['kube_config' => ['secret' => Str::jsonEncode($config)]],
    ]);
    T::throws('kubeconfig ' . $label . ' fails closed', ConfigurationException::class,
        function () use ($client, $variantServer) {
            $client->connect((int) $variantServer['id']);
        });
    try {
        $client->connect((int) $variantServer['id']);
    } catch (ConfigurationException $e) {
        T::is('kubeconfig ' . $label . ' carries its code', $variant['code'], $e->errorCode());
    }
}

// YAML kubeconfigs parse too.
$yamlConfig = "apiVersion: v1\nkind: Config\ncurrent-context: test\n"
    . "contexts:\n  - name: test\n    context:\n      cluster: test\n      user: test\n"
    . "clusters:\n  - name: test\n    cluster:\n      server: 'https://k8s.example.test:6443'\n"
    . "users:\n  - name: test\n    user:\n      token: YAML-BEARER-PRIVATE-1234567890\n";
$yamlServer = (new ServerService($super))->register([
    'name' => 'yaml kubeconfig', 'hostname' => 'yaml.example.test', 'ip_address' => '198.51.100.73',
    'server_type' => 'kubernetes', 'kubernetes_enabled' => true,
    'credentials' => ['kube_config' => ['secret' => $yamlConfig]],
]);
T::is('YAML kubeconfigs connect', 'bearer', $client->connect((int) $yamlServer['id'])['auth']);

// Client-certificate users authenticate over mutual TLS with 0600 material.
$fakeCert = base64_encode("-----BEGIN CERTIFICATE-----\nZmFrZS1jZXJ0\n-----END CERTIFICATE-----\n");
$fakeKey = base64_encode("-----BEGIN PRIVATE KEY-----\nZmFrZS1rZXk=\n-----END PRIVATE KEY-----\n");
$certConfig = json_decode(kubeconfigJson(), true);
$certConfig['users'][0]['user'] = ['client-certificate-data' => $fakeCert, 'client-key-data' => $fakeKey];
$certServer = (new ServerService($super))->register([
    'name' => 'cert kubeconfig', 'hostname' => 'cert.example.test', 'ip_address' => '198.51.100.74',
    'server_type' => 'kubernetes', 'kubernetes_enabled' => true,
    'credentials' => ['kube_config' => ['secret' => Str::jsonEncode($certConfig)]],
]);
$certConnection = $client->connect((int) $certServer['id']);
T::is('certificate auth is detected', 'certificate', $certConnection['auth']);
$seenTls = [];
Http::setClientFake(function ($method, $url, $options) use (&$seenTls) {
    $seenTls = $options;
    $seenTls['files_existed'] = file_exists($options['client_cert']) && file_exists($options['client_key']);
    return ['status' => 200, 'headers' => [],
        'body' => Str::jsonEncode(['kind' => 'Namespace', 'apiVersion' => 'v1']), 'error' => null];
});
$client->getOrNull($certConnection, '/api/v1/namespaces/default');
T::ok('TLS material exists during the call', $seenTls['files_existed'] === true);
T::is('TLS material vanishes after the call', 0,
    (int) file_exists($seenTls['client_cert']) + (int) file_exists($seenTls['client_key']));
Http::setClientFake($fakeK8s);

/* ------------------------------------------------- transport: calls */

section('The transport maps HTTP outcomes onto the documented taxonomy');

$seenAuth = [];
Http::setClientFake(function ($method, $url, $options) use (&$seenAuth) {
    $seenAuth = $options['headers'];
    return ['status' => 200, 'headers' => [],
        'body' => Str::jsonEncode(['kind' => 'Namespace', 'apiVersion' => 'v1']), 'error' => null];
});
$client->getOrNull($connection, '/api/v1/namespaces/default');
T::is('the bearer travels in the Authorization header', 'Bearer K8S-BEARER-PRIVATE-1234567890',
    $seenAuth['Authorization']);
Http::setClientFake($fakeK8s);

T::is('missing objects answer null', null,
    $client->getOrNull($connection, '/api/v1/namespaces/does-not-exist'));

Http::setClientFake(function ($method, $url, $options) {
    return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'connection refused'];
});
T::throws('transport failures are retryable', RetryableKubernetesException::class,
    function () use ($client, $connection) {
        $client->getOrNull($connection, '/api/v1/namespaces/default');
    });
Http::setClientFake(function ($method, $url, $options) {
    return ['status' => 403, 'headers' => [],
        'body' => Str::jsonEncode(['kind' => 'Status', 'status' => 'Failure',
            'message' => 'forbidden: User cannot list', 'reason' => 'Forbidden', 'code' => 403]),
        'error' => null];
});
try {
    $client->getOrNull($connection, '/api/v1/namespaces/default');
    T::ok('auth failures are terminal', false);
} catch (KubernetesException $e) {
    T::is('auth failures are terminal', 'KUBERNETES_AUTH_FAILED', $e->errorCode());
}
Http::setClientFake(function ($method, $url, $options) {
    return ['status' => 422, 'headers' => [],
        'body' => Str::jsonEncode(['kind' => 'Status', 'status' => 'Failure',
            'message' => 'Deployment.apps "x" is invalid: spec: Forbidden', 'reason' => 'Invalid', 'code' => 422]),
        'error' => null];
});
try {
    $client->getOrNull($connection, '/api/v1/namespaces/default');
    T::ok('API rejections surface the Status reason', false);
} catch (KubernetesException $e) {
    T::is('API rejections surface their code', 'KUBERNETES_API_REJECTED', $e->errorCode());
    T::contains('API rejections surface the Status message', 'is invalid', $e->getMessage());
    $failure = $e->toArray();
    T::is('API rejections carry the Status reason', 'Invalid', $failure['context']['reason']);
}
Http::setClientFake(function ($method, $url, $options) {
    return ['status' => 200, 'headers' => [], 'body' => 'this is not json', 'error' => null];
});
T::throws('invalid JSON fails closed', KubernetesException::class,
    function () use ($client, $connection) {
        $client->getOrNull($connection, '/api/v1/namespaces/default');
    });
Http::setClientFake($fakeK8s);

try {
    $client->getOrNull($connection, '/api/v1/namespaces/../secrets');
    T::ok('traversal paths stay uncalled', false);
} catch (ConfigurationException $e) {
    T::is('traversal paths stay uncalled', 'KUBERNETES_PATH_INVALID', $e->errorCode());
}
T::is('no request escaped for the traversal path', 0, count(array_filter($k8sCalls, function ($call) {
    return strpos($call['path'], '..') !== false;
})));

// Server-side apply carries its content type and field manager.
$client->apply($connection, ['kind' => 'Secret', 'apiVersion' => 'v1',
    'metadata' => ['name' => 'probe', 'namespace' => 'apply-probe']]);
$applyCalls = array_values(array_filter($k8sCalls, function ($call) {
    return $call['path'] === '/api/v1/namespaces/apply-probe/secrets/probe';
}));
$lastApply = end($applyCalls);
T::is('apply uses PATCH', 'PATCH', $lastApply['method']);
T::is('apply carries its content type', 'application/apply-patch+yaml',
    $lastApply['options']['headers']['Content-Type']);
T::is('apply names its field manager', 'cloudhost247-apps',
    $lastApply['query']['fieldManager']);
T::is('secret writes redact the response body', true, $lastApply['options']['redact_response']);

/* ------------------------------------------------- engine and plans */

section('The engine resolves through the factory and publishes its plans');

T::is('the factory resolves the kubernetes engine', KubernetesAdapter::class,
    get_class(AdapterFactory::forEngine('kubernetes', $worker)));
$availability = AdapterFactory::availability();
T::ok('the adapter is available once enabled',
    $availability['kubernetes']['installed'] && $availability['kubernetes']['enabled']);
T::ok('the adapter matches kubernetes manifests on its servers',
    $adapter->supports($context->manifest, $context->server));
$vpsServer = (new ServerService($super))->register([
    'name' => 'plain vps', 'hostname' => 'vps.example.test', 'ip_address' => '198.51.100.75',
    'server_type' => 'vps',
]);
$vpsRow = Db::first('servers', ['id' => (int) $vpsServer['id']]);
T::ok('it refuses a VPS server', !$adapter->supports($context->manifest, $vpsRow));

$plan = $adapter->plan($context);
T::is('install plans six steps', 6, count($plan));
T::is('namespaces come first', 'ensure_namespace', $plan[0]['key']);
T::is('readiness gates the health step', 'health_check', $plan[5]['key']);
$restartPlan = $adapter->plan($context->withAction('restart'));
T::is('restart rolls out then waits', ['rollout_restart', 'wait_ready'],
    array_column($restartPlan, 'key'));
$scalePlan = $adapter->plan($context->withAction('stop'));
T::is('stop scales down', ['scale_down'], array_column($scalePlan, 'key'));
T::throws('unknown steps are rejected', ValidationException::class, function () use ($adapter, $context) {
    $adapter->executeStep('launch_missiles', $context);
});

/* ------------------------------------------------- apply */

section('Apply converges namespaces, secrets, workloads and networking');

$ensure = $adapter->executeStep('ensure_namespace', $context);
T::is('namespaces create on first ensure', true, $ensure['output']['created']);
T::is('ensure ledgers the namespace', 'k8s_namespace', $ensure['resources'][0]['type']);
$nsName = $artifacts['namespace']['name'];
T::is('the fake holds the namespace', $nsName,
    $k8sObjects['namespace/' . $nsName]['metadata']['name']);
T::is('ownership labels land on the namespace', (string) $installationId,
    $k8sObjects['namespace/' . $nsName]['metadata']['labels']['ch247apps.cloudhost247.net/installation-id']);

$applySecrets = $adapter->executeStep('apply_secrets', $context);
$secretName = $artifacts['secret']['name'];
T::is('one Secret applies', [$secretName], $applySecrets['output']['secrets']);
T::is('the Secret carries stringData', 'env-secret-private',
    $k8sObjects['secret/' . $nsName . '/' . $secretName]['stringData']['APP_SECRET']);

$applyWorkloads = $adapter->executeStep('apply_workloads', $context);
$deploymentName = $artifacts['deployments']['web']['name'];
T::ok('the web deployment applies', isset($k8sObjects['deployment/' . $nsName . '/' . $deploymentName]));
$pvcResources = array_values(array_filter($applyWorkloads['resources'], function ($resource) {
    return $resource['type'] === 'k8s_pvc';
}));
T::is('one PVC applies', 1, count($pvcResources));
T::is('apply reports the PVC first', 'k8s_pvc', $applyWorkloads['resources'][0]['type']);

$applyNetworking = $adapter->executeStep('apply_networking', $context);
T::is('service plus ingress', 2, count($applyNetworking['resources']));
$ingressName = $artifacts['ingress']['name'];
T::ok('the ingress applies',
    isset($k8sObjects['ingress/' . $nsName . '/' . $ingressName]));

// Re-applying is a no-op against identical state.
$repeat = $adapter->executeStep('apply_workloads', $context);
T::is('re-apply keeps the resource count', count($applyWorkloads['resources']), count($repeat['resources']));

/* ------------------------------------------------- actuate */

section('Rollouts, restarts and scaling drive the workloads');

$ready = $adapter->executeStep('wait_ready', $context);
T::ok('converged rollouts report ready', $ready['output']['rollout']['done'] === true);

// A lagging rollout waits, then reports ready once the cluster converges.
$polls = 0;
$deploymentPath = '/apis/apps/v1/namespaces/' . $nsName . '/deployments';
$k8sFailures['GET ' . $deploymentPath] = function () use (&$polls, $nsName, $deploymentName) {
    $polls++;
    if ($polls < 3) {
        return ['status' => 200, 'headers' => [],
            'body' => Str::jsonEncode(['kind' => 'DeploymentList', 'apiVersion' => 'apps/v1', 'items' => [[
                'kind' => 'Deployment', 'apiVersion' => 'apps/v1',
                'metadata' => ['name' => $deploymentName, 'generation' => 1],
                'spec' => ['replicas' => 2],
                'status' => ['observedGeneration' => 1, 'replicas' => 1,
                    'updatedReplicas' => 1, 'availableReplicas' => 0]]]]),
            'error' => null];
    }
    return null;
};
$converged = $adapter->executeStep('wait_ready', $context);
T::ok('polling converges', $converged['output']['rollout']['done'] === true && $polls >= 3);
unset($k8sFailures['GET ' . $deploymentPath]);

$goneId = $makeInstallation((int) $k8sServer['id'], $appId, $versionId, 'gone.example.test');
$goneContext = DeploymentContext::load($goneId, 'install', $worker);
$goneAdapter = new KubernetesAdapter($worker, new KubernetesClient($worker, null, $noSleep));
T::throws('readiness on a missing namespace fails, not hangs', KubernetesException::class,
    function () use ($goneAdapter, $goneContext) {
        $goneAdapter->executeStep('wait_ready', $goneContext);
    });
// The wait primitive itself: immediate success, and timeouts that report.
$immediate = (new KubernetesClient($worker, null, $noSleep))->waitFor('already ready', 5, function () {
    return ['done' => true, 'detail' => 'converged'];
});
T::ok('ready probes return at once', $immediate['done'] === true);
try {
    (new KubernetesClient($worker, null, $noSleep))->waitFor('never ready', 1, function () {
        return ['done' => false, 'detail' => 'still converging'];
    });
    T::ok('rollouts report timeout, not success', false);
} catch (HealthCheckException $e) {
    T::is('the timeout carries its code', 'KUBERNETES_ROLLOUT_TIMEOUT', $e->errorCode());
}

$restarted = $adapter->executeStep('rollout_restart', $context);
T::ok('restarts annotate the pod template',
    isset($k8sObjects['deployment/' . $nsName . '/' . $deploymentName]['spec']['template']
        ['metadata']['annotations']['kubectl.kubernetes.io/restartedAt']));

$adapter->executeStep('scale_down', $context);
T::is('scale-down parks zero replicas', 0,
    $k8sObjects['deployment/' . $nsName . '/' . $deploymentName]['spec']['replicas']);
T::is('stopped replicas read stopped', 'stopped', $adapter->status($context)['state']);
$adapter->executeStep('scale_up', $context);
T::is('scale-up restores manifest replicas', 2,
    $k8sObjects['deployment/' . $nsName . '/' . $deploymentName]['spec']['replicas']);

/* ------------------------------------------------- reads */

section('Status, logs, health and metrics never leak secrets or guess');

T::is('converged workloads read running', 'running', $adapter->status($context)['state']);
T::is('absent deployments read missing', 'missing', $goneAdapter->status($goneContext)['state']);
$freshId = $makeInstallation((int) $k8sServer['id'], $appId, $versionId, 'fresh.example.test');
$freshContext = DeploymentContext::load($freshId, 'install', $worker);
$freshAdapter = new KubernetesAdapter($worker, new KubernetesClient($worker, null, $noSleep));
T::is('a missing namespace reads missing', 'missing', $freshAdapter->status($freshContext)['state']);
T::is('missing namespaces read unhealthy', 'unhealthy', $freshAdapter->health($freshContext)['state']);
Http::setClientFake(function ($method, $url, $options) {
    return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'connection refused'];
});
T::is('transport failures read unknown', 'unknown', $freshAdapter->status($freshContext)['state']);
T::is('transport failures read unknown health', 'unknown', $freshAdapter->health($freshContext)['state']);
Http::setClientFake($fakeK8s);
// A lagging rollout reads degraded.
$k8sFailures['GET ' . $deploymentPath] = function () {
    return ['status' => 200, 'headers' => [],
        'body' => Str::jsonEncode(['kind' => 'DeploymentList', 'apiVersion' => 'apps/v1', 'items' => [[
            'kind' => 'Deployment', 'apiVersion' => 'apps/v1',
            'metadata' => ['name' => 'lagging', 'generation' => 2],
            'spec' => ['replicas' => 2], 'status' => ['observedGeneration' => 1,
                'replicas' => 2, 'updatedReplicas' => 1, 'availableReplicas' => 1]]]]),
        'error' => null];
};
T::is('lagging rollouts read degraded', 'degraded', $adapter->status($context)['state']);
unset($k8sFailures['GET ' . $deploymentPath]);

// Pod logs aggregate across the newest pods, prefixed and attributed.
$podLabels = $artifacts['labels'] + ['app.kubernetes.io/name' => $deploymentName];
$k8sObjects['pod/' . $nsName . '/' . $deploymentName . '-aaa'] = ['kind' => 'Pod', 'apiVersion' => 'v1',
    'metadata' => ['name' => $deploymentName . '-aaa', 'namespace' => $nsName,
        'creationTimestamp' => '2026-10-11T00:00:01Z', 'labels' => $podLabels]];
$k8sObjects['pod/' . $nsName . '/' . $deploymentName . '-bbb'] = ['kind' => 'Pod', 'apiVersion' => 'v1',
    'metadata' => ['name' => $deploymentName . '-bbb', 'namespace' => $nsName,
        'creationTimestamp' => '2026-10-11T00:00:02Z', 'labels' => $podLabels]];
$logs = $adapter->logs($context, 'web', 10);
T::is('both pods contribute ten lines', 10, count($logs['lines']));
T::ok('lines carry their pod name', strpos($logs['lines'][0], '[' . $deploymentName . '-bbb]') === 0);
T::contains('both pods are attributed', '[' . $deploymentName . '-aaa]', implode("\n", $logs['lines']));
T::throws('unknown services have no logs', ValidationException::class,
    function () use ($adapter, $context) {
        $adapter->logs($context, 'nope', 10);
    });

$health = $adapter->health($context);
T::is('rollouts report healthy once available', 'healthy', $health['state']);
$healthStep = $adapter->executeStep('health_check', $context);
T::is('the health step passes when healthy', 'healthy', $healthStep['output']['health']['state']);
T::throws('readiness gates the health step', HealthCheckException::class,
    function () use ($goneAdapter, $goneContext) {
        $goneAdapter->executeStep('health_check', $goneContext);
    });

$metrics = $adapter->metrics($context);
T::is('pod CPU aggregates in millicores', 250, $metrics['cpu_millicores']);
T::is('invalid quantities count as zero', 128, $metrics['memory_used_mb']);
T::is('pod count aggregates', 2, $metrics['pod_count']);
T::is('metrics without a namespace answer null', null, $goneAdapter->metrics($goneContext));

/* ------------------------------------------------- rollback */

section('Rollback removes workloads, never data or namespaces');

$rebuild = function () use ($adapter, $context) {
    $adapter->executeStep('ensure_namespace', $context);
    $adapter->executeStep('apply_secrets', $context);
    $adapter->executeStep('apply_workloads', $context);
    $adapter->executeStep('apply_networking', $context);
};
$rebuild();
$rollback = $adapter->rollbackStep('apply_workloads', $context);
T::contains('rollback reports the workload', 'deployments', implode(',', $rollback['removed']));
T::ok('deployments are gone',
    !isset($k8sObjects['deployment/' . $nsName . '/' . $deploymentName]));
$pvcName = $artifacts['pvcs']['uploads']['name'];
T::ok('PVCs survive rollback', isset($k8sObjects['pvc/' . $nsName . '/' . $pvcName]));
T::ok('namespaces survive rollback', isset($k8sObjects['namespace/' . $nsName]));
$quietRollback = $adapter->rollbackStep('apply_workloads', $context);
T::is('repeated rollback repeats the quiet compensation', ['deployments'], $quietRollback['removed']);
T::ok('quiet rollback leaks nothing', !isset($quietRollback['leaked']));

/* ------------------------------------------------- deletes */

section('Delete steps converge each layer with guarded data removal');

$rebuild();
$destroyContext = $context->withAction('destroy', ['remove_data' => true]);
$deleteNetworking = $adapter->executeStep('delete_networking', $destroyContext);
T::is('ingress and service delete', 2, count($deleteNetworking['output']['deleted']));
$deleteWorkloads = $adapter->executeStep('delete_workloads', $destroyContext);
$deletedDeployments = $deleteWorkloads['output']['deployments'];
sort($deletedDeployments);
T::is('deployments delete', [$deploymentName, $artifacts['deployments']['worker']['name']], $deletedDeployments);
T::is('PVCs delete only with data removal', [$pvcName], $deleteWorkloads['output']['pvcs']);
$deleteSecrets = $adapter->executeStep('delete_secrets', $destroyContext);
T::is('secrets delete', [$secretName], $deleteSecrets['output']['secrets']);
$deleteNamespace = $adapter->executeStep('delete_namespace', $destroyContext);
T::is('the namespace closes', true, $deleteNamespace['output']['deleted']);
T::ok('the fake confirms the namespace is gone', !isset($k8sObjects['namespace/' . $nsName]));

// Without data removal the namespace and its PVCs stay standing.
$rebuild();
$keepContext = $context->withAction('destroy', ['remove_data' => false]);
$keptWorkloads = $adapter->executeStep('delete_workloads', $keepContext);
T::is('PVCs stay without data removal', [], $keptWorkloads['output']['pvcs']);
$keptNamespace = $adapter->executeStep('delete_namespace', $keepContext);
T::is('namespaces stay without data removal', false, $keptNamespace['output']['deleted']);

// Foreign namespaces are never touched; declared ones are never deleted.
$foreignName = KubernetesManifests::build($freshContext)['namespace']['name'];
$k8sObjects['namespace/' . $foreignName] = ['kind' => 'Namespace', 'apiVersion' => 'v1',
    'metadata' => ['name' => $foreignName]];
T::throws('foreign namespaces are never adopted', KubernetesException::class,
    function () use ($freshAdapter, $freshContext) {
        $freshAdapter->executeStep('ensure_namespace', $freshContext);
    });
$overrideArtifacts = KubernetesManifests::build($preview($namespaceOverride));
T::is('override namespaces stay unmanaged', false, $overrideArtifacts['namespace']['managed']);

/* ------------------------------------------------- destroy */

section('Destroy tears down in dependency order and never strands data silently');

$rebuild();
$destroyed = $adapter->destroy($context, false);
$destroyedTypes = array_column($destroyed['removed'], 'type');
T::notContains('destroy without data removal keeps the namespace', 'k8s_namespace', $destroyedTypes);
T::contains('workloads still tear down', 'k8s_deployment', $destroyedTypes);
T::ok('the namespace survives', isset($k8sObjects['namespace/' . $nsName]));
T::ok('PVCs survive', isset($k8sObjects['pvc/' . $nsName . '/' . $pvcName]));
$destroyedFull = $adapter->destroy($context, true);
T::contains('destroy with data removal closes the namespace', 'k8s_namespace',
    array_column($destroyedFull['removed'], 'type'));
T::ok('the namespace is gone', !isset($k8sObjects['namespace/' . $nsName]));
T::is('namespaces close the ledger', 'k8s_namespace',
    $destroyedFull['removed'][count($destroyedFull['removed']) - 1]['type']);
$destroyedAgain = $adapter->destroy($context, true);
T::is('destroying twice stays quiet', [], $destroyedAgain['removed']);

// Declared namespaces keep standing while their workloads tear down.
list($sharedApp, $sharedVersion) = $makeVersion(str_replace("kubernetes:\n  replicas: 2\n",
    "kubernetes:\n  replicas: 2\n  namespace: shared-team\n", k8sYaml()));
$sharedId = $makeInstallation((int) $k8sServer['id'], $sharedApp, $sharedVersion, 'shared.example.test');
$sharedContext = DeploymentContext::load($sharedId, 'install', $worker);
T::throws('missing declared namespaces fail closed', ConfigurationException::class,
    function () use ($adapter, $sharedContext) {
        $adapter->executeStep('ensure_namespace', $sharedContext);
    });
$k8sObjects['namespace/shared-team'] = ['kind' => 'Namespace', 'apiVersion' => 'v1',
    'metadata' => ['name' => 'shared-team']];
$adapter->executeStep('ensure_namespace', $sharedContext);
$adapter->executeStep('apply_workloads', $sharedContext);
$adapter->destroy($sharedContext, true);
T::ok('declared namespaces survive destroy', isset($k8sObjects['namespace/shared-team']));

/* ------------------------------------------------- gates */

section('Kill switches gate every entry point');

Settings::override('kubernetes_enabled', '0');
T::ok('support drops when disabled', !$adapter->supports($context->manifest, $context->server));
T::throws('steps refuse when disabled', ConfigurationException::class, function () use ($adapter, $context) {
    $adapter->executeStep('ensure_namespace', $context);
});
try {
    $adapter->executeStep('ensure_namespace', $context);
    T::ok('the kill switch carries its code', false);
} catch (ConfigurationException $e) {
    T::is('the kill switch carries its code', 'ADAPTER_DISABLED', $e->errorCode());
}
Settings::override('kubernetes_enabled', '1');

exit(T::summary());
