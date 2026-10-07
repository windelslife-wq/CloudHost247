<?php
/**
 * CloudHost247 App Cloud — the application manifest.
 *
 * One declarative document per deployable application. It is the only place that
 * knows how an application is built: services, images, ports, environment,
 * volumes, databases, dependencies, health check, domain/SSL requirements,
 * backup policy and update strategy. The deployment engine, the Docker adapter,
 * the Kubernetes adapter, the health checker, the backup service and the install
 * wizard all read this object — which is why adding an application never means
 * writing an installer.
 *
 * A manifest is immutable once parsed: `fromArray()` normalises it, `toArray()`
 * round-trips it, and the version row stores the exact YAML that was validated
 * together with its SHA-256 so a deployment can always be reproduced.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Catalog;

use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Core\Yaml;

class Manifest
{
    const SCHEMA_VERSION = 1;

    const KIND_APPLICATION   = 'application';
    const KIND_INFRASTRUCTURE = 'infrastructure';
    const KIND_PLATFORM      = 'platform';

    const ENGINE_DOCKER_COMPOSE = 'docker-compose';
    const ENGINE_DOCKER         = 'docker';
    const ENGINE_CPANEL         = 'cpanel';
    const ENGINE_KUBERNETES     = 'kubernetes';
    const ENGINE_EXTERNAL       = 'external';

    const HOSTING_TYPES = ['shared', 'cpanel', 'vps', 'dedicated', 'docker', 'kubernetes'];

    /** @var array the normalised manifest */
    private $data;

    /** @var string sha256 of the source document */
    private $hash = '';

    /**
     * @param array $data          the parsed manifest document
     * @param string $hash         ignored; the digest is always recomputed from
     *                             the document so a caller cannot assert a hash
     *                             that the content does not support
     */
    public function __construct(array $data, $hash = '')
    {
        $this->data = self::normalise($data);
        // The hash commits to the *document*, not to how it was written: an
        // approved manifest must hash identically after being stored as YAML and
        // read back, or the deployment engine could never verify a snapshot.
        $this->hash = self::canonicalHash($this->data);
    }

    /** Parse a YAML document into a manifest. */
    public static function fromYaml($yaml, $label = 'manifest')
    {
        $parsed = Yaml::parseStrict($yaml, $label);
        if (!is_array($parsed) || $parsed === []) {
            throw new ValidationException('The manifest is empty or is not a YAML mapping.', ['label' => $label]);
        }
        return new self($parsed);
    }

    public static function fromArray(array $data)
    {
        return new self($data);
    }

    /**
     * Format-independent digest of a manifest document.
     *
     * Key order, quoting style, comments and indentation do not change it; a
     * changed image tag, port, volume or environment variable does. That is what
     * makes `manifest_hash` a meaningful approval artefact.
     */
    public static function canonicalHash(array $data)
    {
        return hash('sha256', Str::jsonEncode(self::canonicalise(self::normalise($data))));
    }

    private static function canonicalise($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonicalise($item);
        }
        if (!$isList) {
            ksort($out);
        }
        return $out;
    }

    public function hash()
    {
        return $this->hash;
    }

    public function toArray()
    {
        return $this->data;
    }

    public function toYaml()
    {
        return Yaml::emit($this->data);
    }

    /* ------------------------------------------------------------- identity */

    public function schemaVersion()
    {
        return (int) $this->get('schema', self::SCHEMA_VERSION);
    }

    public function id()
    {
        return (string) $this->get('id', '');
    }

    public function name()
    {
        return (string) $this->get('name', $this->id());
    }

    public function slug()
    {
        $slug = (string) $this->get('slug', '');
        return $slug !== '' ? $slug : Str::slug($this->id(), 80);
    }

    public function category()
    {
        return (string) $this->get('category', 'other');
    }

    public function kind()
    {
        return (string) $this->get('kind', self::KIND_APPLICATION);
    }

    public function summary()
    {
        return (string) $this->get('summary', '');
    }

    public function description()
    {
        return trim((string) $this->get('description', ''));
    }

    public function license()
    {
        return (string) $this->get('license', '');
    }

    public function vendor()
    {
        return (string) $this->get('vendor', '');
    }

    public function tags()
    {
        $tags = $this->get('tags', []);
        return is_array($tags) ? array_values(array_filter(array_map('strval', $tags))) : [];
    }

    public function link($key)
    {
        return (string) $this->get($key, '');
    }

    public function version()
    {
        return (string) $this->get('version', '');
    }

    public function channel()
    {
        return (string) $this->get('channel', 'stable');
    }

    /* ----------------------------------------------------------- deployment */

    public function engine()
    {
        return (string) $this->get('deployment.engine', self::ENGINE_DOCKER_COMPOSE);
    }

    /** Hosting types this application may run on (specification §48). */
    public function supportedHostingTypes()
    {
        $types = $this->get('deployment.supported_hosting_types', []);
        if (!is_array($types) || $types === []) {
            // Derived from the engine when the author did not spell it out.
            switch ($this->engine()) {
                case self::ENGINE_CPANEL:
                    return ['shared', 'cpanel'];
                case self::ENGINE_KUBERNETES:
                    return ['kubernetes'];
                default:
                    return ['vps', 'dedicated', 'docker'];
            }
        }
        return array_values(array_intersect(array_map('strval', $types), self::HOSTING_TYPES));
    }

    public function supportsHostingType($type)
    {
        return in_array((string) $type, $this->supportedHostingTypes(), true);
    }

    public function updateStrategy()
    {
        $strategy = strtolower((string) $this->get('deployment.update_strategy', 'recreate'));
        return in_array($strategy, ['recreate', 'rolling', 'in_place', 'manual'], true) ? $strategy : 'recreate';
    }

    /** @return array<string,array> services keyed by name */
    public function services()
    {
        $services = $this->get('services', []);
        if (!is_array($services)) {
            return [];
        }
        $out = [];
        foreach ($services as $name => $service) {
            if (!is_array($service)) {
                continue;
            }
            $out[(string) $name] = $service + [
                'image' => '',
                'port' => null,
                'primary' => false,
                'internal' => false,
                'environment' => [],
                'volumes' => [],
                'command' => null,
                'entrypoint' => null,
                'depends_on' => [],
                'restart' => 'unless-stopped',
                'user' => null,
                'network_mode' => null,
            ];
        }
        return $out;
    }

    /** The service the customer's domain routes to. */
    public function primaryService()
    {
        $services = $this->services();
        foreach ($services as $name => $service) {
            if (!empty($service['primary'])) {
                return $name;
            }
        }
        foreach ($services as $name => $service) {
            if (empty($service['internal']) && !empty($service['port'])) {
                return $name;
            }
        }
        if (!$services) {
            return '';
        }
        $names = array_keys($services);
        return (string) $names[0];
    }

    public function service($name)
    {
        $services = $this->services();
        return isset($services[$name]) ? $services[$name] : null;
    }

    /** The port Traefik load-balances to. */
    public function primaryPort()
    {
        $primary = $this->service($this->primaryService());
        if ($primary && !empty($primary['port'])) {
            return (int) $primary['port'];
        }
        $ports = $this->ports();
        return $ports ? (int) $ports[0] : 0;
    }

    public function ports()
    {
        $ports = $this->get('ports', []);
        if (!is_array($ports) || $ports === []) {
            $ports = [];
            foreach ($this->services() as $service) {
                if (!empty($service['port']) && empty($service['internal'])) {
                    $ports[] = (int) $service['port'];
                }
            }
        }
        return array_values(array_unique(array_map('intval', $ports)));
    }

    /* --------------------------------------------------------- requirements */

    /**
     * Resource requirements in canonical units.
     *
     * @return array{cpu_min:int,cpu_recommended:int,memory_min_mb:int,memory_recommended_mb:int,
     *               storage_min_mb:int,storage_recommended_mb:int,gpu:bool}
     */
    public function requirements()
    {
        $req = $this->get('requirements', []);
        $req = is_array($req) ? $req : [];
        return [
            'cpu_min' => max(1, (int) $this->pick($req, ['cpu_min', 'cpu', 'min_cpu'], 1)),
            'cpu_recommended' => max(1, (int) $this->pick($req, ['cpu_recommended', 'recommended_cpu'], 0))
                ?: max(1, (int) $this->pick($req, ['cpu_min', 'cpu', 'min_cpu'], 1)),
            'memory_min_mb' => Str::toMegabytes($this->pick($req, ['memory_min_mb', 'memory', 'min_memory', 'memory_mb'], 512)),
            'memory_recommended_mb' => Str::toMegabytes(
                $this->pick($req, ['memory_recommended_mb', 'recommended_memory'], 0)
            ) ?: Str::toMegabytes($this->pick($req, ['memory_min_mb', 'memory', 'memory_mb'], 1024)),
            'storage_min_mb' => Str::toMegabytes($this->pick($req, ['storage_min_mb', 'storage', 'min_storage'], 1024)),
            'storage_recommended_mb' => Str::toMegabytes(
                $this->pick($req, ['storage_recommended_mb', 'recommended_storage'], 0)
            ) ?: Str::toMegabytes($this->pick($req, ['storage_min_mb', 'storage'], 5120)),
            'gpu' => (bool) $this->pick($req, ['gpu', 'gpu_required', 'requires_gpu'], false),
        ];
    }

    /* ---------------------------------------------------------- environment */

    /**
     * Environment definition: required, optional and platform-generated keys.
     *
     * @return array{required:array[],optional:array[],generated:array[]}
     */
    /**
     * Environment variables, split by who supplies the value.
     *
     * `required` and `optional` are what the install wizard may ask the customer
     * for; `generated` is what the platform creates itself (database names,
     * encryption keys, initial passwords). An entry declared under `required`
     * with a `generate` strategy is moved into `generated`, because asking a
     * customer for a value the platform must produce would either leak a secret
     * into a browser form or invite a weak one.
     *
     * @return array{required: array<string,array>, optional: array<string,array>, generated: array<string,array>}
     */
    public function environment()
    {
        $env = $this->get('environment', []);
        $env = is_array($env) ? $env : [];

        $required = $this->normaliseEnvList($this->pick($env, ['required'], []), true);
        $optional = $this->normaliseEnvList($this->pick($env, ['optional'], []), false);
        $generated = $this->normaliseEnvList($this->pick($env, ['generated'], []), false, true);

        foreach (['required' => $required, 'optional' => $optional] as $bucket => $entries) {
            foreach ($entries as $name => $entry) {
                if ($entry['generate'] === 'none') {
                    continue;
                }
                if (!isset($generated[$name])) {
                    $entry['required'] = false;
                    $entry['secret'] = $entry['secret']
                        || in_array($entry['generate'], ['secret', 'password', 'token', 'key'], true);
                    $generated[$name] = $entry;
                }
                if ($bucket === 'required') {
                    unset($required[$name]);
                } else {
                    unset($optional[$name]);
                }
            }
        }

        return ['required' => $required, 'optional' => $optional, 'generated' => $generated];
    }

    private function normaliseEnvList($list, $required, $generated = false)
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $key => $item) {
            if (is_string($item) || is_int($item)) {
                // Shorthand: a bare list of key names.
                $item = ['key' => (string) $item];
            }
            if (!is_array($item)) {
                continue;
            }
            $name = isset($item['key']) ? (string) $item['key'] : (is_string($key) ? (string) $key : '');
            if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $name)) {
                continue;
            }
            $out[$name] = [
                'key' => $name,
                'description' => isset($item['description']) ? (string) $item['description'] : '',
                'default' => array_key_exists('default', $item) ? $item['default'] : null,
                'required' => $required && empty($item['optional']),
                'secret' => !empty($item['secret']) || !empty($item['is_secret'])
                    || ($generated && in_array($this->generateType($item), ['secret', 'password', 'token'], true)),
                'generate' => $this->generateType($item),
                'options' => isset($item['options']) && is_array($item['options']) ? $item['options'] : [],
                'example' => isset($item['example']) ? (string) $item['example'] : '',
                'validation' => isset($item['validation']) ? (string) $item['validation'] : '',
            ];
        }
        return $out;
    }

    private function generateType(array $item)
    {
        $type = isset($item['generate']) ? strtolower((string) $item['generate']) : '';
        return in_array($type, ['secret', 'password', 'uuid', 'token', 'key', 'email', 'none'], true) ? $type : 'none';
    }

    /* -------------------------------------------------------------- volumes */

    /**
     * Volumes the platform must create, keyed by volume name.
     *
     * A volume may be declared at the top level or inside a service — both styles
     * are folded together and de-duplicated by name, because one named volume can
     * legitimately be mounted into several services of the same project (Immich
     * mounts `upload` into both the API server and its worker). `service` is the
     * owning/primary service; `services` lists every service that mounts it.
     *
     * @return array<string,array>
     */
    public function volumes()
    {
        $declared = $this->get('volumes', []);
        $out = [];

        if (is_array($declared)) {
            foreach ($declared as $key => $volume) {
                if (is_string($volume)) {
                    // "/home/node/.n8n" shorthand belongs to the primary service.
                    $volume = ['name' => 'data' . (count($out) + 1), 'mount' => $volume];
                }
                if (!is_array($volume)) {
                    continue;
                }
                $this->mergeVolume($out, $this->normaliseVolume($volume, is_string($key) ? $key : null));
            }
        }

        // Volumes declared inside a service are folded in so an author may use
        // either style without the engine caring.
        foreach ($this->services() as $serviceName => $service) {
            if (empty($service['volumes']) || !is_array($service['volumes'])) {
                continue;
            }
            foreach ($service['volumes'] as $volume) {
                if (is_string($volume)) {
                    $volume = ['mount' => $volume];
                }
                if (!is_array($volume)) {
                    continue;
                }
                $this->mergeVolume($out, $this->normaliseVolume($volume, null, $serviceName));
            }
        }

        // An unspecified backup flag defaults to "back this volume up".
        foreach ($out as $name => $volume) {
            if ($volume['backup'] === null) {
                $out[$name]['backup'] = true;
            }
        }
        return $out;
    }

    /** @param array<string,array> $volumes */
    private function mergeVolume(array &$volumes, array $candidate)
    {
        $name = $candidate['name'];
        if (!isset($volumes[$name])) {
            $volumes[$name] = $candidate;
            return;
        }
        $existing = $volumes[$name];
        foreach ($candidate['services'] as $service) {
            if ($service !== '' && !in_array($service, $existing['services'], true)) {
                $existing['services'][] = $service;
            }
        }
        if ($existing['service'] === '' && $candidate['service'] !== '') {
            $existing['service'] = $candidate['service'];
        }
        // The largest declared size wins: an author who sizes a volume in one
        // place must not have it silently shrunk by another declaration.
        $existing['size_mb'] = max((int) $existing['size_mb'], (int) $candidate['size_mb']);
        // The first *explicit* backup flag wins; an implicit default never
        // overrides a deliberate decision made elsewhere in the manifest.
        if ($existing['backup'] === null) {
            $existing['backup'] = $candidate['backup'];
        }
        if ($existing['mount'] === '') {
            $existing['mount'] = $candidate['mount'];
        }
        if ($existing['driver'] === 'local' && $candidate['driver'] !== 'local') {
            $existing['driver'] = $candidate['driver'];
        }
        $volumes[$name] = $existing;
    }

    private function normaliseVolume(array $volume, $fallbackName = null, $fallbackService = null)
    {
        $mount = isset($volume['mount']) ? (string) $volume['mount']
            : (isset($volume['mount_path']) ? (string) $volume['mount_path'] : '');
        $name = isset($volume['name']) ? (string) $volume['name'] : (string) $fallbackName;
        if ($name === '') {
            $name = Str::projectSlug(basename($mount) ?: 'data', 40);
        }
        $service = isset($volume['service']) ? (string) $volume['service'] : (string) $fallbackService;
        $services = isset($volume['services']) && is_array($volume['services'])
            ? array_values(array_map('strval', $volume['services'])) : [];
        if ($service !== '' && !in_array($service, $services, true)) {
            array_unshift($services, $service);
        }
        return [
            'name' => Str::projectSlug($name, 60),
            'service' => $service,
            'services' => $services,
            'mount' => $mount,
            'size_mb' => Str::toMegabytes(isset($volume['size_mb']) ? $volume['size_mb']
                : (isset($volume['size']) ? $volume['size'] : 1024)),
            // null = not stated; resolved to true once every declaration is merged.
            'backup' => array_key_exists('backup', $volume) ? !empty($volume['backup']) : null,
            'driver' => isset($volume['driver']) ? (string) $volume['driver'] : 'local',
        ];
    }

    /* ------------------------------------------------------------ databases */

    /**
     * Databases the application needs. `isolated` decides whether the engine
     * provisions a private container/service or may safely reuse a shared
     * managed instance (specification §61).
     *
     * @return array[]
     */
    public function databases()
    {
        $declared = $this->get('databases', []);
        $out = [];
        if (is_array($declared)) {
            foreach ($declared as $key => $database) {
                if (is_string($database)) {
                    $database = ['engine' => $database];
                }
                if (!is_array($database)) {
                    continue;
                }
                $out[] = [
                    'name' => Str::projectSlug(isset($database['name']) ? $database['name']
                        : (is_string($key) ? $key : 'db'), 40),
                    'engine' => strtolower((string) (isset($database['engine']) ? $database['engine'] : 'postgres')),
                    'version' => isset($database['version']) ? (string) $database['version'] : '',
                    'isolated' => !array_key_exists('isolated', $database) || !empty($database['isolated']),
                    'service' => isset($database['service']) ? (string) $database['service'] : '',
                    'size_mb' => Str::toMegabytes(isset($database['size_mb']) ? $database['size_mb'] : 1024),
                ];
            }
        }
        return $out;
    }

    /** @return array[] dependency declarations (specification §61) */
    public function dependencies()
    {
        $declared = $this->get('dependencies', []);
        $out = [];
        if (!is_array($declared)) {
            return $out;
        }
        foreach ($declared as $key => $dependency) {
            if (is_string($dependency)) {
                $dependency = ['id' => $dependency];
            }
            if (!is_array($dependency)) {
                continue;
            }
            $out[] = [
                'id' => isset($dependency['id']) ? Str::slug($dependency['id'], 60) : (is_string($key) ? Str::slug($key, 60) : ''),
                'type' => isset($dependency['type']) ? (string) $dependency['type'] : 'service',
                'requirement' => isset($dependency['requirement']) ? (string) $dependency['requirement'] : 'required',
                'isolation' => isset($dependency['isolation']) ? (string) $dependency['isolation'] : 'isolated',
                'version' => isset($dependency['version']) ? (string) $dependency['version'] : '',
            ];
        }
        return array_values(array_filter($out, function ($d) {
            return $d['id'] !== '';
        }));
    }

    /* ---------------------------------------------------------- health check */

    /**
     * @return array{type:string,path:string,port:int,interval:int,timeout:int,retries:int,
     *               expected_status:int[],command:string}
     */
    public function healthcheck()
    {
        $hc = $this->get('healthcheck', []);
        $hc = is_array($hc) ? $hc : [];
        $type = strtolower((string) $this->pick($hc, ['type'], 'http'));
        if (!in_array($type, ['http', 'https', 'tcp', 'exec', 'container', 'none'], true)) {
            $type = 'http';
        }
        $expected = $this->pick($hc, ['expected_status', 'expected_status_codes', 'status'], [200]);
        if (!is_array($expected)) {
            $expected = [(int) $expected];
        }
        return [
            'type' => $type,
            'path' => (string) $this->pick($hc, ['path', 'endpoint'], '/'),
            'port' => (int) $this->pick($hc, ['port'], $this->primaryPort()),
            'interval' => Str::toSeconds($this->pick($hc, ['interval'], '30s'), 30),
            'timeout' => Str::toSeconds($this->pick($hc, ['timeout'], '5s'), 5),
            'retries' => (int) $this->pick($hc, ['retries', 'retry'], 3),
            'expected_status' => array_values(array_map('intval', $expected)),
            'command' => (string) $this->pick($hc, ['command', 'test'], ''),
        ];
    }

    /* ---------------------------------------------------------- domain / SSL */

    public function domain()
    {
        $domain = $this->get('domain', []);
        $domain = is_array($domain) ? $domain : [];
        return [
            'enabled' => (bool) $this->pick($domain, ['enabled'], true),
            'required' => (bool) $this->pick($domain, ['required'], false),
            'path' => (string) $this->pick($domain, ['path'], '/'),
            'wildcard' => (bool) $this->pick($domain, ['wildcard'], false),
            'www_redirect' => (bool) $this->pick($domain, ['www_redirect'], false),
        ];
    }

    public function ssl()
    {
        $ssl = $this->get('ssl', []);
        $ssl = is_array($ssl) ? $ssl : [];
        return [
            'enabled' => (bool) $this->pick($ssl, ['enabled'], true),
            'force_https' => (bool) $this->pick($ssl, ['force_https', 'redirect'], true),
            'resolver' => (string) $this->pick($ssl, ['resolver'], 'letsencrypt'),
            'min_tls' => (string) $this->pick($ssl, ['min_tls'], 'VersionTLS12'),
        ];
    }

    /* ---------------------------------------------------------------- backup */

    public function backup()
    {
        $backup = $this->get('backup', []);
        $backup = is_array($backup) ? $backup : [];
        $includeVolumes = $this->pick($backup, ['include_volumes', 'volumes'], null);
        $includeDatabases = $this->pick($backup, ['include_databases', 'databases'], null);

        return [
            'enabled' => (bool) $this->pick($backup, ['enabled'], true),
            'schedule' => (string) $this->pick($backup, ['schedule'], 'daily'),
            'retention_days' => (int) $this->pick($backup, ['retention_days', 'retention'], 14),
            'include_volumes' => $includeVolumes === null
                ? array_column(array_filter($this->volumes(), function ($v) {
                    return !empty($v['backup']);
                }), 'name')
                : array_values(array_map('strval', (array) $includeVolumes)),
            'include_databases' => $includeDatabases === null
                ? array_column($this->databases(), 'name')
                : array_values(array_map('strval', (array) $includeDatabases)),
            'pre_update' => (bool) $this->pick($backup, ['pre_update'], true),
        ];
    }

    public function update()
    {
        $update = $this->get('update', []);
        $update = is_array($update) ? $update : [];
        return [
            'strategy' => (string) $this->pick($update, ['strategy'], $this->updateStrategy()),
            'backup_before' => (bool) $this->pick($update, ['backup_before', 'backup'], true),
            'health_check_after' => (bool) $this->pick($update, ['health_check_after', 'health_check'], true),
            'rollback_on_failure' => (bool) $this->pick($update, ['rollback_on_failure', 'rollback'], true),
            'auto_update_allowed' => (bool) $this->pick($update, ['auto_update_allowed', 'auto'], true),
        ];
    }

    /* ----------------------------------------------------------- kubernetes */

    /**
     * Optional Kubernetes definition (Phase 6). When absent the Kubernetes
     * adapter derives resources from the compose services, which keeps one
     * manifest valid for both engines.
     */
    public function kubernetes()
    {
        $k8s = $this->get('kubernetes', []);
        return is_array($k8s) ? $k8s : [];
    }

    /* --------------------------------------------------------------- policy */

    public function requiresAdminApproval()
    {
        return (bool) $this->get('requires_admin_approval', false);
    }

    public function featured()
    {
        return (bool) $this->get('featured', false);
    }

    /** Arbitrary adapter hints (e.g. cPanel script name, WordPress install mode). */
    public function adapterOptions($adapter)
    {
        $options = $this->get('adapters.' . $adapter, []);
        return is_array($options) ? $options : [];
    }

    /* -------------------------------------------------------------- helpers */

    public function get($path, $default = null)
    {
        $segments = explode('.', (string) $path);
        $value = $this->data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value === null ? $default : $value;
    }

    private function pick(array $source, array $keys, $default)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                return $source[$key];
            }
        }
        return $default;
    }

    /**
     * Fill in defaults so downstream code never has to defend against a missing
     * key. Normalisation is idempotent: normalise(normalise(x)) === normalise(x).
     */
    public static function normalise(array $data)
    {
        $data['schema'] = isset($data['schema']) ? (int) $data['schema'] : self::SCHEMA_VERSION;
        $data['id'] = isset($data['id']) ? Str::slug($data['id'], 60) : '';
        $data['name'] = isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : $data['id'];
        $data['slug'] = isset($data['slug']) && $data['slug'] !== '' ? Str::slug($data['slug'], 80) : $data['id'];
        $data['kind'] = isset($data['kind']) && in_array($data['kind'], [self::KIND_APPLICATION, self::KIND_INFRASTRUCTURE, self::KIND_PLATFORM], true)
            ? $data['kind'] : self::KIND_APPLICATION;

        if (!isset($data['deployment']) || !is_array($data['deployment'])) {
            $data['deployment'] = [];
        }
        $data['deployment']['engine'] = isset($data['deployment']['engine']) ? strtolower((string) $data['deployment']['engine'])
            : self::ENGINE_DOCKER_COMPOSE;

        if (!isset($data['services']) || !is_array($data['services'])) {
            $data['services'] = [];
        }
        if (!isset($data['ports']) || !is_array($data['ports'])) {
            $data['ports'] = [];
        }
        return $data;
    }
}
