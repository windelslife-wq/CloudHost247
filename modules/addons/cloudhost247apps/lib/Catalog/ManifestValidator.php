<?php
/**
 * CloudHost247 App Cloud — manifest validation.
 *
 * Two passes over the same document:
 *
 *   1. structure — schema version, identity, category, engine, services, images,
 *      ports, requirements, environment, volumes, health check, domain/SSL/backup
 *   2. security  — the rules that keep a customer manifest from becoming a
 *      container escape or a noisy-neighbour attack: no host networking, no
 *      privileged mode, no capability escalation, no bind mount of the Docker
 *      socket or of host system paths, no `latest` tag on a published version,
 *      pinned images, declared resource ceilings, no secrets in plain defaults,
 *      no host PID/IPC sharing, registry allow-list enforcement.
 *
 * An application cannot be PUBLISHED until both passes are clean; a warning is
 * recorded but does not block. Every result is returned as a structured report
 * so the admin console shows exactly what to fix (specification §46, §47, §49).
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Catalog;

use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;

class ManifestValidator
{
    /** Host paths that may never be bind-mounted into a customer container. */
    const FORBIDDEN_HOST_PATHS = [
        '/', '/etc', '/var/run', '/var/run/docker.sock', '/run/docker.sock', '/root', '/home',
        '/proc', '/sys', '/dev', '/boot', '/usr', '/lib', '/lib64', '/bin', '/sbin', '/opt',
    ];

    /** Capabilities that must never be granted to a tenant container. */
    const FORBIDDEN_CAPABILITIES = [
        'SYS_ADMIN', 'SYS_MODULE', 'SYS_RAWIO', 'SYS_PTRACE', 'SYS_BOOT', 'DAC_OVERRIDE',
        'NET_ADMIN', 'NET_RAW', 'ALL',
    ];

    const MAX_SERVICES = 12;
    const MAX_PORTS = 8;
    const MAX_ENV_KEYS = 120;

    /**
     * Validate a manifest.
     *
     * @return array{valid:bool, errors:array, warnings:array, report:array}
     */
    public function validate(Manifest $manifest, array $context = [])
    {
        $errors = [];
        $warnings = [];

        $this->validateIdentity($manifest, $errors, $warnings);
        $this->validateDeployment($manifest, $errors, $warnings);
        $this->validateServices($manifest, $errors, $warnings);
        $this->validateRequirements($manifest, $errors, $warnings);
        $this->validateEnvironment($manifest, $errors, $warnings);
        $this->validateVolumes($manifest, $errors, $warnings);
        $this->validateDatabases($manifest, $errors, $warnings);
        $this->validateHealthcheck($manifest, $errors, $warnings);
        $this->validateDomainAndSsl($manifest, $errors, $warnings);
        $this->validateBackupAndUpdate($manifest, $errors, $warnings);
        $this->validateSecurity($manifest, $errors, $warnings);

        $environment = $manifest->environment();
        $volumes = $manifest->volumes();
        $volumeTotal = 0;
        $volumeNames = [];
        foreach ($volumes as $name => $volume) {
            $volumeNames[] = $name;
            $volumeTotal += (int) $volume['size_mb'];
        }
        $secrets = 0;
        foreach ([$environment['required'], $environment['optional'], $environment['generated']] as $bucket) {
            foreach ($bucket as $entry) {
                if (!empty($entry['secret'])) {
                    $secrets++;
                }
            }
        }

        // What the admin console shows on the manifest review screen: enough to
        // decide without opening the YAML, plus every objection the validator had.
        $report = [
            'manifest_hash' => $manifest->hash(),
            'identity' => [
                'id' => $manifest->id(),
                'name' => $manifest->name(),
                'slug' => $manifest->slug(),
                'category' => $manifest->category(),
                'kind' => $manifest->kind(),
                'license' => $manifest->license(),
                'vendor' => $manifest->vendor(),
                'version' => $manifest->version(),
                'channel' => $manifest->channel(),
            ],
            'schema' => $manifest->schemaVersion(),
            'deployment' => [
                'engine' => $manifest->engine(),
                'hosting_types' => $manifest->supportedHostingTypes(),
                'update_strategy' => $manifest->updateStrategy(),
                'requires_admin_approval' => $manifest->requiresAdminApproval(),
            ],
            'services' => count($manifest->services()),
            'service_names' => array_keys($manifest->services()),
            'primary_service' => $manifest->primaryService(),
            'ports' => $manifest->ports(),
            'requirements' => $manifest->requirements(),
            'environment' => [
                'required' => count($environment['required']),
                'optional' => count($environment['optional']),
                'generated' => count($environment['generated']),
                'secrets' => $secrets,
                'required_keys' => array_keys($environment['required']),
                'generated_keys' => array_keys($environment['generated']),
            ],
            'volumes' => [
                'count' => count($volumes),
                'names' => $volumeNames,
                'total_mb' => $volumeTotal,
                'backed_up' => array_values(array_filter($volumeNames, function ($name) use ($volumes) {
                    return !empty($volumes[$name]['backup']);
                })),
            ],
            'databases' => count($manifest->databases()),
            'dependencies' => count($manifest->dependencies()),
            'healthcheck' => $manifest->healthcheck(),
            'domain' => $manifest->domain(),
            'ssl' => $manifest->ssl(),
            'backup' => $manifest->backup(),
            'update' => $manifest->update(),
            'security' => [
                'violations' => count(array_filter($errors, function ($error) {
                    return strpos((string) $error['field'], 'services.') === 0;
                })),
                'warnings' => count($warnings),
                'privileged_containers' => $this->countFlag($manifest, 'privileged'),
                'host_paths' => $this->countHostPaths($manifest),
            ],
            'errors' => $errors,
            'warnings' => $warnings,
            'validated_at' => \Ch247Apps\Core\Clock::now(),
        ];

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'report' => $report,
        ];
    }

    /** Convenience: validate a YAML string. */
    public function validateYaml($yaml)
    {
        try {
            $manifest = Manifest::fromYaml($yaml);
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'errors' => [['field' => 'document', 'message' => $e->getMessage()]],
                'warnings' => [],
                'report' => ['errors' => [$e->getMessage()]],
            ];
        }
        return $this->validate($manifest);
    }

    /* --------------------------------------------------------------- checks */

    private function validateIdentity(Manifest $m, array &$errors, array &$warnings)
    {
        if ($m->schemaVersion() !== Manifest::SCHEMA_VERSION) {
            $errors[] = ['field' => 'schema', 'message' => 'Unsupported manifest schema version '
                . $m->schemaVersion() . '; this platform validates version ' . Manifest::SCHEMA_VERSION . '.'];
        }
        if ($m->id() === '') {
            $errors[] = ['field' => 'id', 'message' => 'An application id is required.'];
        } elseif (!preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,58}[a-z0-9])?$/', $m->id())) {
            $errors[] = ['field' => 'id', 'message' => 'The id must be lowercase letters, numbers and dashes.'];
        }
        if (trim($m->name()) === '') {
            $errors[] = ['field' => 'name', 'message' => 'A display name is required.'];
        }
        if (trim($m->summary()) === '') {
            $warnings[] = ['field' => 'summary', 'message' => 'A short summary is shown on the marketplace card.'];
        } elseif (strlen($m->summary()) > 200) {
            $warnings[] = ['field' => 'summary', 'message' => 'The summary is longer than 200 characters and will be truncated.'];
        }
        if (!in_array($m->kind(), [Manifest::KIND_APPLICATION, Manifest::KIND_INFRASTRUCTURE, Manifest::KIND_PLATFORM], true)) {
            $errors[] = ['field' => 'kind', 'message' => 'kind must be application, infrastructure or platform.'];
        }
        foreach (['website' => 'website_url', 'repository' => 'repository_url', 'documentation' => 'documentation_url'] as $key => $label) {
            $value = (string) $m->get($key, '');
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $errors[] = ['field' => $key, 'message' => $label . ' must be a valid URL.'];
            }
        }
        if (trim((string) $m->get('license', '')) === '') {
            $warnings[] = ['field' => 'license', 'message' => 'Declare the upstream license so customers can evaluate it.'];
        }
    }

    private function validateDeployment(Manifest $m, array &$errors, array &$warnings)
    {
        $engines = [
            Manifest::ENGINE_DOCKER_COMPOSE, Manifest::ENGINE_DOCKER,
            Manifest::ENGINE_CPANEL, Manifest::ENGINE_KUBERNETES, Manifest::ENGINE_EXTERNAL,
        ];
        if (!in_array($m->engine(), $engines, true)) {
            $errors[] = ['field' => 'deployment.engine', 'message' => 'Unknown deployment engine "'
                . $m->engine() . '". Supported: ' . implode(', ', $engines) . '.'];
        }
        $hosting = $m->supportedHostingTypes();
        if ($hosting === []) {
            $errors[] = ['field' => 'deployment.supported_hosting_types',
                'message' => 'Declare at least one supported hosting type.'];
        }
        if ($m->engine() === Manifest::ENGINE_CPANEL && !in_array('cpanel', $hosting, true)
            && !in_array('shared', $hosting, true)) {
            $errors[] = ['field' => 'deployment.supported_hosting_types',
                'message' => 'A cPanel engine must declare cpanel or shared hosting support.'];
        }
        if (!in_array($m->updateStrategy(), ['recreate', 'rolling', 'in_place', 'manual'], true)) {
            $errors[] = ['field' => 'deployment.update_strategy', 'message' => 'Unknown update strategy.'];
        }
    }

    private function validateServices(Manifest $m, array &$errors, array &$warnings)
    {
        $services = $m->services();

        if ($m->engine() === Manifest::ENGINE_CPANEL) {
            // cPanel provisioning is script/adapter driven, not compose driven.
            $options = $m->adapterOptions('cpanel');
            if (empty($options['script']) && empty($options['package']) && empty($options['mode'])) {
                $warnings[] = ['field' => 'adapters.cpanel',
                    'message' => 'No cPanel install mode declared; the adapter will create the account, domain and database only.'];
            }
            return;
        }

        if ($services === []) {
            $errors[] = ['field' => 'services', 'message' => 'At least one service must be defined.'];
            return;
        }
        if (count($services) > self::MAX_SERVICES) {
            $errors[] = ['field' => 'services', 'message' => 'A manifest may define at most '
                . self::MAX_SERVICES . ' services.'];
        }
        if ($m->primaryService() === '') {
            $errors[] = ['field' => 'services', 'message' => 'No primary (public) service could be determined.'];
        }

        $allowLatest = Settings::bool('manifest_allow_latest_tag', false);
        $registryAllowList = Settings::listOf('image_registry_allowlist');

        foreach ($services as $name => $service) {
            $field = 'services.' . $name;

            if (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,62}$/', (string) $name)) {
                $errors[] = ['field' => $field, 'message' => 'Service names must be lowercase alphanumeric, dash or underscore.'];
            }

            $image = isset($service['image']) ? trim((string) $service['image']) : '';
            if ($image === '') {
                $errors[] = ['field' => $field . '.image', 'message' => 'An image reference is required.'];
                continue;
            }
            if (!preg_match('#^[a-z0-9._\-/]+(:[A-Za-z0-9._\-]+)?(@sha256:[a-f0-9]{64})?$#', $image)) {
                $errors[] = ['field' => $field . '.image', 'message' => 'Malformed image reference "'
                    . Str::clip($image, 80) . '".'];
            }
            if (!$allowLatest && (Str::endsWith($image, ':latest') || strpos($image, ':') === false)
                && strpos($image, '@sha256:') === false) {
                // An unpinned image is not reproducible: two deploys of the same
                // approved manifest could run different code, and a rollback
                // could pull something newer than what it is rolling back to.
                $errors[] = ['field' => $field . '.image',
                    'message' => 'Pin a specific tag (or a sha256 digest) instead of "latest" so deployments '
                        . 'are reproducible and rollbacks are real.'];
            }
            if ($registryAllowList && !$this->registryAllowed($image, $registryAllowList)) {
                $errors[] = ['field' => $field . '.image',
                    'message' => 'Image registry is not on the allow-list: ' . Str::clip($image, 80)
                        . '. Allowed: ' . implode(', ', $registryAllowList) . '.'];
            }

            if (!empty($service['port'])) {
                $port = (int) $service['port'];
                if ($port < 1 || $port > 65535) {
                    $errors[] = ['field' => $field . '.port', 'message' => 'Port must be between 1 and 65535.'];
                }
                if ($port < 1024 && empty($service['allow_privileged_port'])) {
                    $errors[] = ['field' => $field . '.port',
                        'message' => 'Ports below 1024 require a root container and are not permitted.'];
                }
            } elseif (empty($service['internal']) && $name === $m->primaryService()) {
                $errors[] = ['field' => $field . '.port', 'message' => 'The primary service must declare the port it listens on.'];
            }

            foreach ((array) (isset($service['depends_on']) ? $service['depends_on'] : []) as $dependency) {
                $dependencyName = is_array($dependency) && isset($dependency['service'])
                    ? (string) $dependency['service'] : (string) $dependency;
                if ($dependencyName !== '' && !isset($services[$dependencyName])) {
                    $errors[] = ['field' => $field . '.depends_on',
                        'message' => 'Depends on unknown service "' . $dependencyName . '".'];
                }
                if ($dependencyName === (string) $name) {
                    $errors[] = ['field' => $field . '.depends_on', 'message' => 'A service cannot depend on itself.'];
                }
            }

            if (!empty($service['restart'])
                && !in_array((string) $service['restart'], ['always', 'unless-stopped', 'on-failure', 'no'], true)) {
                $errors[] = ['field' => $field . '.restart', 'message' => 'Unknown restart policy.'];
            }
        }

        $ports = $m->ports();
        if (count($ports) > self::MAX_PORTS) {
            $errors[] = ['field' => 'ports', 'message' => 'A manifest may expose at most ' . self::MAX_PORTS . ' ports.'];
        }
    }

    /**
     * Registry allow-list check.
     *
     * An image reference without a registry host is Docker Hub shorthand
     * (`postgres:16`, `n8nio/n8n:1.70.1`), so the tag is stripped before the
     * host is inferred — otherwise every pinned official image would be refused.
     *
     * @param string[] $allowList
     */
    private function registryAllowed($image, array $allowList)
    {
        $image = strtolower(trim((string) $image));
        $repository = preg_split('/@sha256:/', $image)[0];
        $colon = strrpos($repository, ':');
        $slash = strrpos($repository, '/');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $repository = substr($repository, 0, $colon);
        }

        $segments = explode('/', $repository);
        $first = isset($segments[0]) ? $segments[0] : '';
        $host = (strpos($first, '.') !== false || strpos($first, ':') !== false || $first === 'localhost')
            ? $first
            : 'docker.io';

        foreach ($allowList as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            $allowed = preg_replace('#^https?://#', '', $allowed);
            $allowed = rtrim($allowed, '/');
            if ($allowed === '' ) {
                continue;
            }
            if ($allowed === $host) {
                return true;
            }
            // "docker.io" also covers the bare shorthand form.
            if ($allowed === 'docker.io' && $host === 'docker.io') {
                return true;
            }
            if (Str::startsWith($repository, $allowed . '/')) {
                return true;
            }
        }
        return false;
    }

    /** True when a service would run as uid 0. */
    private function isRootUser($user)
    {
        $user = strtolower(trim((string) $user));
        if ($user === 'root') {
            return true;
        }
        $uid = strpos($user, ':') !== false ? substr($user, 0, strpos($user, ':')) : $user;
        return $uid === '0';
    }

    /** How many services set a boolean flag (report summary only). */
    private function countFlag(Manifest $manifest, $flag)
    {
        $count = 0;
        foreach ($manifest->services() as $service) {
            if (!empty($service[$flag])) {
                $count++;
            }
        }
        return $count;
    }

    /** How many declared volumes bind-mount a host path. */
    private function countHostPaths(Manifest $manifest)
    {
        $count = 0;
        foreach ($manifest->services() as $service) {
            foreach ((array) (isset($service['volumes']) ? $service['volumes'] : []) as $volume) {
                if (is_array($volume) && !empty($volume['host_path'])) {
                    $count++;
                }
                if (is_string($volume) && strpos($volume, ':') !== false) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function validateRequirements(Manifest $m, array &$errors, array &$warnings)
    {
        $req = $m->requirements();

        if ($req['cpu_min'] < 1 || $req['cpu_min'] > 64) {
            $errors[] = ['field' => 'requirements.cpu_min', 'message' => 'Minimum CPU must be between 1 and 64 cores.'];
        }
        if ($req['cpu_recommended'] < $req['cpu_min']) {
            $errors[] = ['field' => 'requirements.cpu_recommended',
                'message' => 'Recommended CPU cannot be below the minimum.'];
        }
        if ($req['memory_min_mb'] < 64 || $req['memory_min_mb'] > 1048576) {
            $errors[] = ['field' => 'requirements.memory_min_mb',
                'message' => 'Minimum memory must be between 64 MB and 1 TB.'];
        }
        if ($req['memory_recommended_mb'] < $req['memory_min_mb']) {
            $errors[] = ['field' => 'requirements.memory_recommended_mb',
                'message' => 'Recommended memory cannot be below the minimum.'];
        }
        if ($req['storage_min_mb'] < 64 || $req['storage_min_mb'] > 10485760) {
            $errors[] = ['field' => 'requirements.storage_min_mb',
                'message' => 'Minimum storage must be between 64 MB and 10 TB.'];
        }
        if ($req['storage_recommended_mb'] < $req['storage_min_mb']) {
            $warnings[] = ['field' => 'requirements.storage_recommended_mb',
                'message' => 'Recommended storage is below the declared minimum.'];
        }
        if ($req['gpu'] && !$m->requiresAdminApproval()) {
            $warnings[] = ['field' => 'requirements.gpu',
                'message' => 'GPU workloads should require admin approval until capacity is guaranteed.'];
        }
    }

    private function validateEnvironment(Manifest $m, array &$errors, array &$warnings)
    {
        $env = $m->environment();
        $total = count($env['required']) + count($env['optional']) + count($env['generated']);

        if ($total > self::MAX_ENV_KEYS) {
            $errors[] = ['field' => 'environment', 'message' => 'Too many environment keys (' . $total . ').'];
        }

        $seen = [];
        foreach (['required', 'optional', 'generated'] as $group) {
            foreach ($env[$group] as $key => $definition) {
                if (isset($seen[$key])) {
                    $errors[] = ['field' => 'environment.' . $group . '.' . $key,
                        'message' => 'Environment key "' . $key . '" is declared more than once.'];
                }
                $seen[$key] = $group;

                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', (string) $key)) {
                    $errors[] = ['field' => 'environment.' . $group,
                        'message' => 'Invalid environment key "' . Str::clip((string) $key, 40) . '".'];
                }

                // A required key the customer must type needs guidance; one the
                // platform generates must say how.
                if ($group === 'required' && $definition['generate'] === 'none' && $definition['description'] === '') {
                    $warnings[] = ['field' => 'environment.required.' . $key,
                        'message' => 'Describe what the customer must provide for ' . $key . '.'];
                }
                if ($group === 'generated' && $definition['generate'] === 'none') {
                    $errors[] = ['field' => 'environment.generated.' . $key,
                        'message' => 'A generated key must declare generate: secret|password|uuid|token.'];
                }
                if ($definition['secret'] && $definition['default'] !== null && $definition['default'] !== '') {
                    $errors[] = ['field' => 'environment.' . $group . '.' . $key,
                        'message' => 'A secret must not ship a default value.'];
                }
                if (!empty($definition['options']) && $definition['default'] !== null
                    && !in_array((string) $definition['default'], array_map('strval', $definition['options']), true)) {
                    $errors[] = ['field' => 'environment.' . $group . '.' . $key,
                        'message' => 'The default value is not one of the declared options.'];
                }
            }
        }
    }

    private function validateVolumes(Manifest $m, array &$errors, array &$warnings)
    {
        $services = $m->services();
        $names = [];

        foreach ($m->volumes() as $volume) {
            $field = 'volumes.' . $volume['name'];

            if (isset($names[$volume['name'] . '|' . $volume['service']])) {
                $errors[] = ['field' => $field, 'message' => 'Duplicate volume declaration.'];
            }
            $names[$volume['name'] . '|' . $volume['service']] = true;

            if ($volume['mount'] === '') {
                $errors[] = ['field' => $field . '.mount', 'message' => 'A mount path is required.'];
            } elseif ($volume['mount'][0] !== '/') {
                $errors[] = ['field' => $field . '.mount', 'message' => 'Mount paths must be absolute.'];
            }
            if ($volume['size_mb'] < 1) {
                $errors[] = ['field' => $field . '.size_mb', 'message' => 'Volume size must be at least 1 MB.'];
            }
            if ($volume['size_mb'] > 10485760) {
                $errors[] = ['field' => $field . '.size_mb', 'message' => 'Volume size may not exceed 10 TB.'];
            }
            if ($volume['service'] !== '' && !isset($services[$volume['service']])) {
                $errors[] = ['field' => $field . '.service',
                    'message' => 'Volume references unknown service "' . $volume['service'] . '".'];
            }
            if ($volume['driver'] !== 'local' && !Settings::bool('manifest_allow_remote_volumes', false)) {
                $errors[] = ['field' => $field . '.driver',
                    'message' => 'Only the local volume driver is enabled on this platform.'];
            }
        }

        // Total declared volume size must fit inside the minimum storage.
        $total = 0;
        foreach ($m->volumes() as $volume) {
            $total += (int) $volume['size_mb'];
        }
        $req = $m->requirements();
        if ($total > $req['storage_recommended_mb'] * 2 && $total > 0) {
            $warnings[] = ['field' => 'volumes',
                'message' => 'Declared volume sizes (' . $total . ' MB) far exceed the recommended storage.'];
        }
    }

    private function validateDatabases(Manifest $m, array &$errors, array &$warnings)
    {
        $engines = ['postgres', 'postgresql', 'mysql', 'mariadb', 'sqlite', 'redis', 'mongodb', 'valkey'];
        foreach ($m->databases() as $database) {
            $field = 'databases.' . $database['name'];
            if (!in_array($database['engine'], $engines, true)) {
                $errors[] = ['field' => $field . '.engine',
                    'message' => 'Unsupported database engine "' . $database['engine'] . '".'];
            }
            if ($database['engine'] === 'sqlite' && !$database['isolated']) {
                $errors[] = ['field' => $field, 'message' => 'A SQLite database must be isolated per installation.'];
            }
        }
        foreach ($m->dependencies() as $dependency) {
            if (!in_array($dependency['type'], ['service', 'database', 'cache', 'storage', 'queue'], true)) {
                $errors[] = ['field' => 'dependencies.' . $dependency['id'] . '.type',
                    'message' => 'Unknown dependency type.'];
            }
            if (!in_array($dependency['isolation'], ['isolated', 'shared'], true)) {
                $errors[] = ['field' => 'dependencies.' . $dependency['id'] . '.isolation',
                    'message' => 'isolation must be isolated or shared.'];
            }
        }
    }

    private function validateHealthcheck(Manifest $m, array &$errors, array &$warnings)
    {
        $hc = $m->healthcheck();

        if ($hc['type'] === 'none') {
            $warnings[] = ['field' => 'healthcheck',
                'message' => 'Without a health check the platform cannot verify the application is running; '
                    . 'it will be reported as UNKNOWN, never ONLINE.'];
            return;
        }
        if (in_array($hc['type'], ['http', 'https'], true)) {
            if ($hc['path'] === '' || $hc['path'][0] !== '/') {
                $errors[] = ['field' => 'healthcheck.path', 'message' => 'An absolute HTTP path is required.'];
            }
            if ($hc['port'] < 1 || $hc['port'] > 65535) {
                $errors[] = ['field' => 'healthcheck.port', 'message' => 'A valid port is required.'];
            }
            if ($hc['expected_status'] === []) {
                $errors[] = ['field' => 'healthcheck.expected_status', 'message' => 'Declare the accepted status codes.'];
            }
            foreach ($hc['expected_status'] as $status) {
                if ($status < 100 || $status > 599) {
                    $errors[] = ['field' => 'healthcheck.expected_status', 'message' => 'Invalid HTTP status ' . $status . '.'];
                }
            }
        }
        if ($hc['type'] === 'exec' && trim($hc['command']) === '') {
            $errors[] = ['field' => 'healthcheck.command', 'message' => 'An exec health check needs a command.'];
        }
        if ($hc['type'] === 'tcp' && ($hc['port'] < 1 || $hc['port'] > 65535)) {
            $errors[] = ['field' => 'healthcheck.port', 'message' => 'A valid port is required.'];
        }
        if ($hc['interval'] < 5 || $hc['interval'] > 3600) {
            $errors[] = ['field' => 'healthcheck.interval', 'message' => 'Interval must be between 5s and 1h.'];
        }
        if ($hc['timeout'] < 1 || $hc['timeout'] > 120) {
            $errors[] = ['field' => 'healthcheck.timeout', 'message' => 'Timeout must be between 1s and 120s.'];
        }
        if ($hc['timeout'] >= $hc['interval']) {
            $errors[] = ['field' => 'healthcheck.timeout', 'message' => 'Timeout must be shorter than the interval.'];
        }
        if ($hc['retries'] < 1 || $hc['retries'] > 10) {
            $errors[] = ['field' => 'healthcheck.retries', 'message' => 'Retries must be between 1 and 10.'];
        }
    }

    private function validateDomainAndSsl(Manifest $m, array &$errors, array &$warnings)
    {
        $domain = $m->domain();
        $ssl = $m->ssl();

        if ($domain['required'] && !$domain['enabled']) {
            $errors[] = ['field' => 'domain', 'message' => 'A required domain implies domain.enabled.'];
        }
        if ($domain['enabled'] && $m->primaryPort() < 1 && $m->engine() !== Manifest::ENGINE_CPANEL) {
            $errors[] = ['field' => 'domain', 'message' => 'Domain routing needs a service port to route to.'];
        }
        if ($ssl['enabled'] && !$domain['enabled']) {
            $errors[] = ['field' => 'ssl', 'message' => 'SSL requires a domain.'];
        }
        if ($ssl['enabled'] && !$ssl['force_https']) {
            $warnings[] = ['field' => 'ssl.force_https',
                'message' => 'HTTP → HTTPS redirection is off; the platform recommends enabling it.'];
        }
        if (!in_array($ssl['min_tls'], ['VersionTLS12', 'VersionTLS13', 'VersionMinTLS12', 'VersionMinTLS13'], true)) {
            $errors[] = ['field' => 'ssl.min_tls', 'message' => 'Only TLS 1.2 or 1.3 minimums are accepted.'];
        }
    }

    private function validateBackupAndUpdate(Manifest $m, array &$errors, array &$warnings)
    {
        $backup = $m->backup();
        $update = $m->update();

        if ($backup['enabled']) {
            if ($backup['include_volumes'] === [] && $backup['include_databases'] === []) {
                $warnings[] = ['field' => 'backup',
                    'message' => 'Backup is enabled but includes no volumes or databases.'];
            }
            if ($backup['retention_days'] < 1 || $backup['retention_days'] > 365) {
                $errors[] = ['field' => 'backup.retention_days', 'message' => 'Retention must be between 1 and 365 days.'];
            }
            if (!in_array($backup['schedule'], ['hourly', 'daily', 'weekly', 'monthly', 'off'], true)) {
                $errors[] = ['field' => 'backup.schedule', 'message' => 'Unknown backup schedule.'];
            }
        }
        if (!in_array($update['strategy'], ['recreate', 'rolling', 'in_place', 'manual'], true)) {
            $errors[] = ['field' => 'update.strategy', 'message' => 'Unknown update strategy.'];
        }
        if ($update['strategy'] !== 'manual' && !$update['backup_before']) {
            $warnings[] = ['field' => 'update.backup_before',
                'message' => 'Updating without a pre-update backup cannot be rolled back safely.'];
        }
    }

    /**
     * Security pass. These are invariants, not preferences: a manifest that
     * violates one can never be published, regardless of who wrote it.
     */
    private function validateSecurity(Manifest $m, array &$errors, array &$warnings)
    {
        foreach ($m->services() as $name => $service) {
            $field = 'services.' . $name;

            if (!empty($service['privileged'])) {
                $errors[] = ['field' => $field . '.privileged', 'message' => 'Privileged containers are not permitted.'];
            }
            if (!empty($service['network_mode']) && in_array((string) $service['network_mode'], ['host', 'container:*'], true)) {
                $errors[] = ['field' => $field . '.network_mode', 'message' => 'Host networking is not permitted.'];
            }
            if (!empty($service['pid']) && (string) $service['pid'] === 'host') {
                $errors[] = ['field' => $field . '.pid', 'message' => 'Sharing the host PID namespace is not permitted.'];
            }
            if (!empty($service['ipc']) && (string) $service['ipc'] === 'host') {
                $errors[] = ['field' => $field . '.ipc', 'message' => 'Sharing the host IPC namespace is not permitted.'];
            }

            foreach ((array) (isset($service['cap_add']) ? $service['cap_add'] : []) as $capability) {
                if (in_array(strtoupper((string) $capability), self::FORBIDDEN_CAPABILITIES, true)) {
                    $errors[] = ['field' => $field . '.cap_add',
                        'message' => 'Capability ' . strtoupper((string) $capability) . ' is not permitted.'];
                }
            }
            foreach ((array) (isset($service['devices']) ? $service['devices'] : []) as $device) {
                $errors[] = ['field' => $field . '.devices',
                    'message' => 'Host device access is not permitted (' . Str::clip((string) $device, 40) . ').'];
            }
            foreach ((array) (isset($service['security_opt']) ? $service['security_opt'] : []) as $option) {
                if (strpos((string) $option, 'seccomp=unconfined') !== false
                    || strpos((string) $option, 'apparmor=unconfined') !== false
                    || strpos((string) $option, 'label:disable') !== false) {
                    $errors[] = ['field' => $field . '.security_opt',
                        'message' => 'Disabling sandboxing is not permitted (' . Str::clip((string) $option, 40) . ').'];
                }
            }
            foreach ((array) (isset($service['sysctls']) ? $service['sysctls'] : []) as $sysctl => $value) {
                $errors[] = ['field' => $field . '.sysctls',
                    'message' => 'Kernel parameter changes are not permitted (' . Str::clip((string) $sysctl, 40) . ').'];
            }

            // Bind mounts: only the deployment's own project directory is allowed.
            foreach ((array) (isset($service['volumes']) ? $service['volumes'] : []) as $volume) {
                $host = is_array($volume) ? (isset($volume['host_path']) ? (string) $volume['host_path'] : '') : '';
                if (is_string($volume) && strpos($volume, ':') !== false) {
                    list($host) = explode(':', $volume, 2);
                }
                if ($host === '') {
                    continue;
                }
                if (Str::startsWith($host, '${') || Str::startsWith($host, '$')) {
                    // Substituted at render time by the engine; checked there.
                    continue;
                }
                $normalised = rtrim($host, '/');
                if ($normalised === '' || in_array($normalised, self::FORBIDDEN_HOST_PATHS, true)) {
                    $errors[] = ['field' => $field . '.volumes',
                        'message' => 'Bind mounting "' . $host . '" is not permitted.'];
                }
                if (strpos($normalised, 'docker.sock') !== false) {
                    $errors[] = ['field' => $field . '.volumes',
                        'message' => 'Mounting the Docker socket into a customer container is not permitted.'];
                }
                if (!Str::startsWith($normalised, '${PROJECT_ROOT}')
                    && !Str::startsWith($normalised, '/opt/cloudhost247')
                    && $normalised[0] === '/') {
                    $warnings[] = ['field' => $field . '.volumes',
                        'message' => 'Bind mount "' . Str::clip($host, 60) . '" is outside the deployment project directory.'];
                }
            }

            foreach ((array) (isset($service['environment']) ? $service['environment'] : []) as $key => $value) {
                if (is_string($value) && preg_match('/(password|secret|token|key)\s*=\s*[^\$\s]{6,}/i', $key . '=' . $value)) {
                    if (strpos((string) $value, '${') === false) {
                        $errors[] = ['field' => $field . '.environment.' . $key,
                            'message' => 'A credential must not be hardcoded in the manifest; use a generated secret.'];
                    }
                }
            }

            if (!empty($service['user']) && $this->isRootUser((string) $service['user'])) {
                $warnings[] = ['field' => $field . '.user',
                    'message' => 'The container runs as root; prefer a non-root user.'];
            }
        }

        // The engine enforces limits from the plan; a manifest may not opt out.
        $limits = $m->get('deployment.disable_resource_limits', false);
        if (!empty($limits)) {
            $errors[] = ['field' => 'deployment.disable_resource_limits',
                'message' => 'Resource limits are mandatory for every tenant container.'];
        }
    }
}
