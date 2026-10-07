<?php
/**
 * CloudHost247 App Cloud — deployment context.
 *
 * Everything an adapter needs to deploy, inspect or remove one installation,
 * loaded once and passed down the step plan: the installation row, the approved
 * manifest snapshot, the target server, the resource limits from the plan, the
 * decrypted environment, the attached domains and the volume set.
 *
 * The context is the only place plaintext secrets exist in memory during a
 * deployment. It is never serialised wholesale: toArray() redacts, and the
 * orchestrator logs only the redacted form.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Catalog\Manifest;
use Ch247Apps\Catalog\ManifestRepository;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;

class DeploymentContext
{
    /** Environment encryption context prefix. */
    const ENV_CONTEXT = 'environment.value|installation:';

    /** @var Actor */
    public $actor;

    /** @var string install|update|start|stop|restart|destroy|backup|restore|ssl|domain_configure|healthcheck */
    public $action;

    /** @var array installation row */
    public $installation;

    /** @var array application row */
    public $application;

    /** @var array application_versions row */
    public $version;

    /** @var Manifest */
    public $manifest;

    /** @var array|null servers row */
    public $server;

    /** @var array|null plans row */
    public $plan;

    /** @var array<string,string> plaintext environment */
    public $environment = [];

    /** @var array<string,array> env_key => ['is_secret','source','locked','description'] */
    public $environmentMeta = [];

    /** @var array[] attached domains */
    public $domains = [];

    /** @var array[] volume rows */
    public $volumes = [];

    /** @var int|null deployment id */
    public $deploymentId;

    /** @var string compose project name */
    public $project;

    /** @var string per-deployment base directory on the server */
    public $basePath;

    /** @var array extra options passed by the caller (target version, backup id, …) */
    public $options = [];

    private function __construct()
    {
    }

    /**
     * Load a context for an installation.
     *
     * @param array $options version (deploy a specific version), deployment_id,
     *                       with_secrets (default true; false for read-only paths)
     * @throws NotFoundException
     */
    public static function load($installationId, $action = 'install', Actor $actor = null, array $options = [])
    {
        $installation = Db::first('installations', ['id' => (int) $installationId, 'deleted_at' => null]);
        if (!$installation) {
            throw new NotFoundException('That installation does not exist.');
        }
        $application = Db::first('applications', ['id' => (int) $installation['application_id']]);
        if (!$application) {
            throw new NotFoundException('The application for that installation is no longer in the catalog.');
        }

        $versionId = isset($options['version_id']) ? (int) $options['version_id']
            : (int) $installation['application_version_id'];
        $version = Db::first('application_versions', ['id' => $versionId]);
        if (!$version) {
            throw new NotFoundException('The application version for that installation no longer exists.');
        }

        $context = new self();
        $context->actor = $actor ?: Actor::system('DeploymentContext');
        $context->action = (string) $action;
        $context->installation = $installation;
        $context->application = $application;
        $context->version = $version;
        // Always from the approved snapshot: the file on disk may have moved on.
        $context->manifest = ManifestRepository::forVersion($versionId);
        $context->server = $installation['server_id']
            ? Db::first('servers', ['id' => (int) $installation['server_id']]) : null;
        $context->plan = $installation['plan_id']
            ? Db::first('plans', ['id' => (int) $installation['plan_id']]) : null;
        $context->deploymentId = isset($options['deployment_id']) ? (int) $options['deployment_id']
            : ($installation['deployment_id'] ? (int) $installation['deployment_id'] : null);
        $context->options = $options;

        $context->project = self::projectName((int) $installation['customer_id'], (string) $application['slug']);
        $context->basePath = self::basePath((int) $installation['customer_id'], (string) $application['slug'],
            (int) $installation['id']);

        $withSecrets = !isset($options['with_secrets']) || (bool) $options['with_secrets'];
        $context->loadEnvironment($withSecrets);
        $context->loadDomains();
        $context->loadVolumes();

        return $context;
    }

    /** Build a context for a not-yet-created installation (wizard preview). */
    public static function preview(Manifest $manifest, array $installation, array $server = [], array $plan = [],
        Actor $actor = null)
    {
        $context = new self();
        $context->actor = $actor ?: Actor::system('DeploymentContext');
        $context->action = 'preview';
        $context->manifest = $manifest;
        $context->installation = $installation + ['id' => 0, 'customer_id' => 0];
        $context->application = ['slug' => $manifest->slug(), 'name' => $manifest->name()];
        $context->version = ['id' => 0, 'version' => $manifest->version()];
        $context->server = $server;
        $context->plan = $plan;
        $context->project = self::projectName((int) $context->installation['customer_id'], $manifest->slug());
        $context->basePath = self::basePath((int) $context->installation['customer_id'], $manifest->slug(),
            (int) $context->installation['id']);
        return $context;
    }

    /** Compose project name: unique per customer + application, DNS/label safe. */
    public static function projectName($customerId, $slug)
    {
        return Str::projectSlug('c' . (int) $customerId . '-' . $slug, 40);
    }

    /**
     * The isolated directory for one deployment.
     *
     * Every customer/application pair gets its own tree — compose file, env file,
     * volumes, logs and backups — so two installations can never share state or
     * read each other's data (specification §7).
     */
    public static function basePath($customerId, $slug, $installationId = 0)
    {
        $root = rtrim(Settings::string('apps_base_path', '/opt/cloudhost247/apps'), '/');
        $slug = Str::projectSlug((string) $slug, 60);
        $suffix = (int) $installationId > 0 ? '-' . (int) $installationId : '';
        return $root . '/customer-' . (int) $customerId . '/' . $slug . $suffix;
    }

    private function loadEnvironment($withSecrets)
    {
        $rows = Db::fetch('environment', ['installation_id' => (int) $this->installation['id']],
            ['order' => 'env_key']);
        foreach ($rows as $row) {
            $key = (string) $row['env_key'];
            $this->environmentMeta[$key] = [
                'is_secret' => (bool) $row['is_secret'],
                'source' => $row['source'],
                'locked' => (bool) $row['locked'],
                'description' => isset($row['description']) ? $row['description'] : null,
            ];
            if ($withSecrets) {
                $plain = Crypto::tryDecrypt($row['encrypted_value'], self::envContext($this->installation['id'], $key));
                if ($plain === null) {
                    // A value that cannot be decrypted must not be silently
                    // replaced with an empty string: that would deploy a broken
                    // configuration and look like success.
                    Logger::error('Installation environment value could not be decrypted.', [
                        'installation_id' => (int) $this->installation['id'], 'env_key' => $key,
                        'key_version' => (int) $row['key_version'], 'source' => 'deployments',
                    ]);
                    throw new \Ch247Apps\Core\ConfigurationException(
                        'The stored value for ' . $key . ' could not be decrypted with the current key.'
                    );
                }
                $this->environment[$key] = $plain;
            }
        }
        // Platform-provided values the manifest may interpolate. They are derived,
        // never customer-editable, and always present so a manifest can rely on them.
        $defaults = $this->platformEnvironment();
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $this->environment)) {
                $this->environment[$key] = $value;
                $this->environmentMeta[$key] = [
                    'is_secret' => false, 'source' => 'platform', 'locked' => true,
                    'description' => 'Provided by the platform.',
                ];
            }
        }
    }

    /** Values every deployment gets, computed from the real configuration. */
    public function platformEnvironment()
    {
        $primary = $this->primaryDomain();
        $scheme = $this->sslEnabled() ? 'https' : 'http';
        $out = [
            'PRIMARY_DOMAIN' => $primary !== null ? $primary : '',
            'APP_URL' => $primary !== null ? $scheme . '://' . $primary : '',
            'APP_SCHEME' => $scheme,
            'COMPOSE_PROJECT_NAME' => $this->project,
            'INSTALLATION_ID' => (string) (int) $this->installation['id'],
            'INSTALLATION_REFERENCE' => isset($this->installation['reference'])
                ? (string) $this->installation['reference'] : '',
            'APP_VERSION' => (string) $this->version['version'],
            'TZ' => Settings::string('default_timezone', 'UTC'),
        ];
        if (!empty($this->installation['internal_port'])) {
            $out['APP_PORT'] = (string) (int) $this->installation['internal_port'];
        }
        return $out;
    }

    private function loadDomains()
    {
        foreach (Db::fetch('installation_domains', ['installation_id' => (int) $this->installation['id']]) as $link) {
            $domain = Db::first('domains', ['id' => (int) $link['domain_id']]);
            if (!$domain) {
                continue;
            }
            $this->domains[] = [
                'id' => (int) $domain['id'],
                'domain' => $domain['domain'],
                'primary' => (bool) $link['primary_domain'],
                'path_prefix' => isset($link['path_prefix']) ? $link['path_prefix'] : null,
                'status' => $link['status'],
                'verification_status' => $domain['verification_status'],
                'ssl_status' => $domain['ssl_status'],
                'ssl_expires_at' => isset($domain['ssl_expires_at']) ? $domain['ssl_expires_at'] : null,
            ];
        }
        // Primary first so an adapter can rely on domains[0].
        usort($this->domains, function ($a, $b) {
            if ($a['primary'] === $b['primary']) {
                return strcmp($a['domain'], $b['domain']);
            }
            return $a['primary'] ? -1 : 1;
        });
    }

    private function loadVolumes()
    {
        $this->volumes = Db::fetch('volumes', ['installation_id' => (int) $this->installation['id']],
            ['order' => 'name']);
    }

    public function primaryDomain()
    {
        foreach ($this->domains as $domain) {
            if ($domain['primary']) {
                return $domain['domain'];
            }
        }
        return isset($this->domains[0]) ? $this->domains[0]['domain']
            : (isset($this->installation['domain']) && $this->installation['domain'] !== ''
                ? $this->installation['domain'] : null);
    }

    public function sslEnabled()
    {
        // Intent, not proof: the manifest says this application is served over TLS,
        // so routing is generated with a certificate resolver from the very first
        // deployment and Traefik issues the certificate on first request. Whether a
        // certificate actually exists is certificateIssued() — that is the honest
        // signal, and it is what ssl_status reports to the customer.
        $ssl = $this->manifest->ssl();
        if (!empty($ssl['enabled'])) {
            return true;
        }
        return $this->certificateIssued();
    }

    /** Is there a certificate on record for the primary domain? Never guessed. */
    public function certificateIssued()
    {
        foreach ($this->domains as $domain) {
            if ($domain['primary'] && in_array($domain['ssl_status'], ['issued', 'expiring'], true)) {
                return true;
            }
        }
        return (string) (isset($this->installation['ssl_status']) ? $this->installation['ssl_status'] : 'none')
            === 'issued';
    }

    public function customerId()
    {
        return (int) $this->installation['customer_id'];
    }

    public function installationId()
    {
        return (int) $this->installation['id'];
    }

    public function serverId()
    {
        return $this->server ? (int) $this->server['id'] : 0;
    }

    public function adapterName()
    {
        return (string) (isset($this->installation['adapter']) && $this->installation['adapter'] !== ''
            ? $this->installation['adapter'] : 'docker');
    }

    /**
     * Resource limits for the deployment.
     *
     * The plan the customer bought wins; the manifest's minimums are the floor
     * (an application must never get less than it declares it needs), and the
     * per-service shares in the manifest decide how the total is divided.
     */
    public function resourceLimits()
    {
        $requirements = $this->manifest->requirements();
        $plan = $this->plan ?: [];
        $cpu = (int) (isset($plan['cpu_millicores']) && (int) $plan['cpu_millicores'] > 0
            ? $plan['cpu_millicores'] : 0);
        $memory = (int) (isset($plan['memory_mb']) && (int) $plan['memory_mb'] > 0 ? $plan['memory_mb'] : 0);
        $storage = (int) (isset($plan['storage_mb']) && (int) $plan['storage_mb'] > 0 ? $plan['storage_mb'] : 0);

        return [
            'cpu_millicores' => max($cpu, (int) $requirements['cpu_min_millicores']),
            'memory_mb' => max($memory, (int) $requirements['memory_min_mb']),
            'storage_mb' => max($storage, (int) $requirements['storage_min_mb']),
            'from_plan' => $cpu > 0 || $memory > 0 || $storage > 0,
            'recommended' => [
                'cpu_millicores' => (int) $requirements['cpu_recommended_millicores'],
                'memory_mb' => (int) $requirements['memory_recommended_mb'],
            ],
        ];
    }

    /** A copy with a different action (the orchestrator reuses one context). */
    public function withAction($action, array $options = [])
    {
        $clone = clone $this;
        $clone->action = (string) $action;
        $clone->options = array_merge($this->options, $options);
        return $clone;
    }

    /** Redacted snapshot: safe for logs, events and the deployment record. */
    public function toArray()
    {
        $environment = [];
        foreach ($this->environment as $key => $value) {
            $secret = isset($this->environmentMeta[$key]) && !empty($this->environmentMeta[$key]['is_secret']);
            $environment[$key] = $secret ? '[redacted]' : $value;
        }
        return [
            'action' => $this->action,
            'installation_id' => $this->installationId(),
            'reference' => isset($this->installation['reference']) ? $this->installation['reference'] : null,
            'customer_id' => $this->customerId(),
            'application' => isset($this->application['slug']) ? $this->application['slug'] : null,
            'version' => isset($this->version['version']) ? $this->version['version'] : null,
            'manifest_hash' => $this->manifest->hash(),
            'server_id' => $this->serverId(),
            'adapter' => $this->adapterName(),
            'project' => $this->project,
            'base_path' => $this->basePath,
            'primary_domain' => $this->primaryDomain(),
            'domains' => array_map(function ($domain) {
                return $domain['domain'];
            }, $this->domains),
            'ssl_enabled' => $this->sslEnabled(),
            'resources' => $this->resourceLimits(),
            'environment_keys' => array_keys($environment),
            'environment' => $environment,
            'volumes' => array_map(function ($volume) {
                return ['name' => $volume['name'], 'mount_path' => $volume['mount_path'],
                    'size_mb' => (int) $volume['size_mb']];
            }, $this->volumes),
        ];
    }

    public static function envContext($installationId, $key)
    {
        return self::ENV_CONTEXT . (int) $installationId . '|' . $key;
    }
}
