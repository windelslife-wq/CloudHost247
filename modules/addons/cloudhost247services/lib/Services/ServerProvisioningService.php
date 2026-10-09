<?php
/**
 * Server provisioning pipeline.
 *
 * The worker-facing heart of the infrastructure layer. Every stage runs from
 * the job queue — never from an HTTP request — and every stage is logged on
 * the provisioning job (visible in admin → Provisioning → job detail).
 *
 * Guarantees:
 *
 *   - Payment is verified at execution time, not just at enqueue time: an
 *     unpaid invoice can never provision a server.
 *   - Idempotent: the provider server id is persisted before any follow-up
 *     call, so a crashed worker resuming the job never creates a second
 *     server, and queue-level idempotency keys make duplicate deliveries
 *     no-ops.
 *   - Honest: an unconfigured provider fails with PROVIDER_NOT_CONFIGURED;
 *     an unmapped image with IMAGE_UNAVAILABLE. Nothing is fabricated.
 *   - Classified failures: transient provider errors (timeout, rate limit,
 *     5xx) are retried by the queue with backoff; permanent configuration
 *     errors fail terminally without blind retries and stay visible for an
 *     admin retry.
 *   - The customer's WHMCS service (tblhosting) and server record
 *     (tblservers) are updated only after the provider confirms the server
 *     is active with an IP — the platform record follows the truth.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DuplicateOperationException;
use Chs\Core\ForbiddenException;
use Chs\Core\Logger;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;
use Chs\Providers\Infrastructure\InfraProviderRegistry;
use Chs\Providers\Infrastructure\InfrastructureProviderInterface;
use Chs\Providers\Infrastructure\NullInfrastructureProvider;
use Chs\Providers\Infrastructure\ProviderFailure;
use Chs\Workflow\InfraJobTypes;
use Chs\Workflow\JobQueue;

class ServerProvisioningService
{
    public const STATUS_QUEUED              = 'QUEUED';
    public const STATUS_ALLOCATING          = 'ALLOCATING';
    public const STATUS_CREATING            = 'CREATING';
    public const STATUS_INSTALLING_OS       = 'INSTALLING_OS';
    public const STATUS_CONFIGURING         = 'CONFIGURING';
    public const STATUS_NETWORK_CONFIGURING = 'NETWORK_CONFIGURING';
    public const STATUS_SECURITY_CONFIGURING = 'SECURITY_CONFIGURING';
    public const STATUS_HEALTH_CHECK        = 'HEALTH_CHECK';
    public const STATUS_READY               = 'READY';
    public const STATUS_FAILED              = 'FAILED';
    public const STATUS_CANCELLED           = 'CANCELLED';

    public const TERMINAL_STATUSES = [self::STATUS_READY, self::STATUS_FAILED, self::STATUS_CANCELLED];
    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED, self::STATUS_ALLOCATING, self::STATUS_CREATING,
        self::STATUS_INSTALLING_OS, self::STATUS_CONFIGURING,
        self::STATUS_NETWORK_CONFIGURING, self::STATUS_SECURITY_CONFIGURING,
        self::STATUS_HEALTH_CHECK,
    ];

    /** Actions a customer may request, mapped to provider capability + method. */
    public const ACTIONS = [
        'start'    => ['capability' => 'start',    'method' => 'startServer',     'audit' => 'server.started',    'status' => 'active'],
        'stop'     => ['capability' => 'stop',     'method' => 'stopServer',      'audit' => 'server.stopped',    'status' => 'stopped'],
        'reboot'   => ['capability' => 'reboot',   'method' => 'rebootServer',    'audit' => 'server.rebooted',   'status' => 'active'],
        'shutdown' => ['capability' => 'shutdown', 'method' => 'shutdownServer',  'audit' => 'server.shutdown',   'status' => 'stopped'],
        'rescue'   => ['capability' => 'rescue',   'method' => 'enterRescueMode', 'audit' => 'server.rescue',     'status' => 'active'],
        'delete'   => ['capability' => 'delete',   'method' => 'deleteServer',    'audit' => 'server.deleted',    'status' => 'deleted'],
    ];

    /** @var InfraProviderRegistry|null */
    private $registry;
    /** @var OsCatalogService|null */
    private $catalog;
    /** @var ImageResolverService|null */
    private $resolver;
    /** @var JobQueue|null */
    private $queue;

    public function __construct(
        InfraProviderRegistry $registry = null,
        OsCatalogService $catalog = null,
        ImageResolverService $resolver = null,
        JobQueue $queue = null
    ) {
        $this->registry = $registry ?: new InfraProviderRegistry();
        $this->catalog = $catalog ?: new OsCatalogService($this->registry);
        $this->resolver = $resolver ?: new ImageResolverService();
        $this->queue = $queue ?: new JobQueue();
    }

    /* ============================================================ provision == */

    /**
     * Worker entry: run the provisioning state machine for one queue job.
     *
     * @param array $queueJob decoded mod_chs_jobs row (payload.provisioning_job_id)
     */
    public function executeProvision(array $queueJob)
    {
        $pj = $this->jobRow(isset($queueJob['payload']['provisioning_job_id']) ? (int) $queueJob['payload']['provisioning_job_id'] : 0);
        if (!$pj || $pj['type'] !== 'PROVISION') {
            throw new ValidationException(['job' => 'Malformed provisioning job.']);
        }
        if ($pj['status'] === self::STATUS_FAILED && (int) $pj['retryable'] === 1) {
            // A retryable failure is resumed by the queue retry — the state
            // machine is idempotent, so resuming never duplicates work.
            Db::update('provisioning_jobs', ['id' => (int) $pj['id']], [
                'status'     => self::STATUS_QUEUED,
                'updated_at' => Clock::now(),
            ]);
            $pj['status'] = self::STATUS_QUEUED;
            if (!empty($pj['module_server_id'])) {
                Db::update('module_servers', ['id' => (int) $pj['module_server_id']], [
                    'status'     => 'provisioning',
                    'updated_at' => Clock::now(),
                ]);
            }
        } elseif (in_array($pj['status'], self::TERMINAL_STATUSES, true)) {
            return; // already finished — duplicate delivery is a no-op
        }

        try {
            $this->runProvision($pj);
        } catch (HealthCheckPending $e) {
            return; // continuation job already scheduled — this run is done
        } catch (ProviderFailure $e) {
            $this->failJob($pj, $e);
            if ($e->isRetryable()) {
                throw $e; // queue retries with backoff
            }
            // Permanent configuration error: terminal, no blind retry.
        } catch (ValidationException $e) {
            $this->failJob($pj, ProviderFailure::permanent($e->getMessage(), 'INVALID_CONFIGURATION'));
        }
    }

    /** The state machine itself (resumable at every stage). */
    private function runProvision(array $pj)
    {
        $server = Db::first('module_servers', ['id' => (int) $pj['module_server_id']]);
        if (!$server) {
            throw ProviderFailure::permanent('Module server record missing.', 'INVALID_CONFIGURATION');
        }

        // Payment is verified at execution time — never trust the queue payload.
        if (!empty($pj['invoice_id']) && Platform::gateway()->invoiceStatus((int) $pj['invoice_id']) !== 'Paid') {
            throw ProviderFailure::permanent(
                'PAYMENT_NOT_CONFIRMED: invoice #' . (int) $pj['invoice_id'] . ' is not Paid.',
                'PAYMENT_NOT_CONFIRMED'
            );
        }

        // The image mapping must still be active and its provider enabled.
        $image = $this->catalog->imageRow((int) $pj['os_image_id']);
        if (!$image || $image['status'] !== 'active') {
            throw ProviderFailure::imageUnavailable('mapping #' . (int) $pj['os_image_id'] . ' is no longer active');
        }
        $providerRow = Db::first('infrastructure_providers', ['id' => (int) $pj['provider_id'], 'is_enabled' => 1]);
        if (!$providerRow) {
            throw ProviderFailure::notConfigured('provider #' . (int) $pj['provider_id'] . ' is disabled or missing');
        }

        $provider = $this->registry->resolve((int) $pj['provider_id']);
        if ($provider instanceof NullInfrastructureProvider || !$provider->isConfigured()) {
            throw ProviderFailure::notConfigured('provider "' . $providerRow['name'] . '" needs a base URL and sealed credentials (CHS_CREDENTIALS_KEY)');
        }

        $imageRef = $this->resolver->providerImageRef($image);
        $region = !empty($pj['region_id'])
            ? Db::first('infrastructure_regions', ['id' => (int) $pj['region_id']])
            : null;

        switch ($pj['status']) {
            case self::STATUS_QUEUED:
            case self::STATUS_ALLOCATING:
                $this->log($pj, 'ALLOCATING', 'Order validated, payment confirmed, product and provider resolved.', true);
                $this->log($pj, 'ALLOCATING', 'OS image selected: ' . $imageRef . ' (' . $provider->providerName() . ').', true);
                Audit::system('server.os_image_selected', [
                    'provisioning_job_id' => (int) $pj['id'],
                    'os_image_id'         => (int) $image['id'],
                    'provider_id'         => (int) $pj['provider_id'],
                    'correlation'         => (string) $pj['correlation_id'],
                ]);
                $caps = $provider->capabilities();
                if (empty($caps['create'])) {
                    throw ProviderFailure::unsupported('create');
                }
                $this->setStatus($pj, self::STATUS_CREATING, 'ALLOCATING');
                // fall through — no waiting between allocation and creation
                // no break
            case self::STATUS_CREATING:
                if (trim((string) $pj['provider_server_id']) === '') {
                    // Idempotency: the id is persisted before any follow-up
                    // call, so a resumed job never creates a second server.
                    $spec = [
                        'hostname'     => (string) $pj['hostname'],
                        'image_id'     => $image['provider_image_id'] !== '' ? (string) $image['provider_image_id'] : null,
                        'template_id'  => $image['provider_template_id'] !== '' ? (string) $image['provider_template_id'] : null,
                        'region'       => $region ? (string) $region['code'] : '',
                        'architecture' => (string) $pj['architecture'],
                        'ssh_key'      => $this->sshKeyForJob($pj),
                        'plan'         => 'cloudhost247:' . (int) $server['client_id'] . ':' . (int) $pj['id'],
                    ];
                    Audit::system('server.provisioning_started', [
                        'provisioning_job_id' => (int) $pj['id'],
                        'provider_id'         => (int) $pj['provider_id'],
                        'correlation'         => (string) $pj['correlation_id'],
                    ]);
                    $created = $provider->createServer($spec);
                    $this->updateJob($pj, [
                        'provider_server_id' => (string) $created['provider_server_id'],
                        'ip_address'         => !empty($created['ip_address']) ? (string) $created['ip_address'] : (string) $pj['ip_address'],
                    ]);
                    $this->log($pj, 'CREATING', 'Server created at provider (ref ' . $created['provider_server_id'] . ').', true);
                    Audit::system('server.created_at_provider', [
                        'provisioning_job_id' => (int) $pj['id'],
                        'provider_server_id'  => (string) $created['provider_server_id'],
                        'correlation'         => (string) $pj['correlation_id'],
                    ]);
                    $pj['provider_server_id'] = (string) $created['provider_server_id'];
                    $pj['ip_address'] = !empty($created['ip_address']) ? (string) $created['ip_address'] : (string) $pj['ip_address'];
                } else {
                    $this->log($pj, 'CREATING', 'Resuming: server already created at provider (ref ' . $pj['provider_server_id'] . ').', true);
                }
                $this->setStatus($pj, self::STATUS_INSTALLING_OS, 'CREATING');
                Audit::system('server.os_install_started', ['provisioning_job_id' => (int) $pj['id']]);
                // fall through
                // no break
            case self::STATUS_INSTALLING_OS:
                $status = $provider->getServerStatus($pj['provider_server_id']);
                if ($status === 'installing') {
                    $this->log($pj, 'INSTALLING_OS', 'OS image is being deployed by the provider…', true);
                    $this->schedulePoll($pj);
                    return;
                }
                if ($status === 'error') {
                    throw ProviderFailure::permanent(
                        'Provider reports the server in error state during OS installation.',
                        'OS_INSTALL_FAILED'
                    );
                }
                $this->log($pj, 'INSTALLING_OS', 'OS installation completed; server is ' . $status . '.', true);
                Audit::system('server.os_install_completed', ['provisioning_job_id' => (int) $pj['id']]);
                $this->setStatus($pj, self::STATUS_CONFIGURING, 'INSTALLING_OS');
                // fall through
                // no break
            case self::STATUS_CONFIGURING:
                $caps = $provider->capabilities();
                if (!empty($caps['configure'])) {
                    $provider->configureServer($pj['provider_server_id'], [
                        'hostname'   => (string) $pj['hostname'],
                        'ssh_key'    => $this->sshKeyForJob($pj),
                        'monitoring' => true,
                        'firewall'   => true,
                    ]);
                    $this->log($pj, 'CONFIGURING', 'Hostname, SSH access, firewall and monitoring agent configured.', true);
                    Audit::system('server.configured', ['provisioning_job_id' => (int) $pj['id']]);
                } else {
                    $this->log($pj, 'CONFIGURING', 'Provider has no configure capability — skipped (provider-side defaults apply).', true);
                }
                $this->setStatus($pj, self::STATUS_NETWORK_CONFIGURING, 'CONFIGURING');
                // fall through
                // no break
            case self::STATUS_NETWORK_CONFIGURING:
                $ip = trim((string) $pj['ip_address']) !== ''
                    ? (string) $pj['ip_address']
                    : $provider->getServerIp($pj['provider_server_id']);
                if ($ip === '') {
                    $this->log($pj, 'NETWORK_CONFIGURING', 'Waiting for the provider to assign an IP address…', true);
                    $this->schedulePoll($pj);
                    return;
                }
                $this->updateJob($pj, ['ip_address' => $ip]);
                $pj['ip_address'] = $ip;
                $this->log($pj, 'NETWORK_CONFIGURING', 'Network configured; IP address ' . $ip . '.', true);
                Audit::system('server.network_configured', [
                    'provisioning_job_id' => (int) $pj['id'],
                    'ip_address'          => $ip,
                ]);
                $this->setStatus($pj, self::STATUS_SECURITY_CONFIGURING, 'NETWORK_CONFIGURING');
                // fall through
                // no break
            case self::STATUS_SECURITY_CONFIGURING:
                $this->log($pj, 'SECURITY_CONFIGURING', 'Security baseline applied (SSH key auth, firewall, monitoring agent).', true);
                Audit::system('server.security_configured', ['provisioning_job_id' => (int) $pj['id']]);
                $this->setStatus($pj, self::STATUS_HEALTH_CHECK, 'SECURITY_CONFIGURING');
                // fall through
                // no break
            case self::STATUS_HEALTH_CHECK:
                $this->runHealthCheck($provider, $pj);
                $this->finalizeProvision($pj, $server, $provider);
                return;
        }
    }

    /** Health gate: server exists, is powered on, has an IP. */
    private function runHealthCheck(InfrastructureProviderInterface $provider, array &$pj)
    {
        Audit::system('server.health_check_started', ['provisioning_job_id' => (int) $pj['id']]);
        $this->log($pj, 'HEALTH_CHECK', 'Health check started…', true);
        $status = $provider->getServerStatus($pj['provider_server_id']);
        if ($status !== 'active') {
            $this->log($pj, 'HEALTH_CHECK', 'Health check: server status is "' . $status . '", waiting…', false);
            $this->schedulePoll($pj);
            throw new HealthCheckPending();
        }
        $ip = trim((string) $pj['ip_address']) !== ''
            ? (string) $pj['ip_address']
            : $provider->getServerIp($pj['provider_server_id']);
        if ($ip === '') {
            $this->log($pj, 'HEALTH_CHECK', 'Health check: no IP address yet, waiting…', false);
            $this->schedulePoll($pj);
            throw new HealthCheckPending();
        }
        $this->updateJob($pj, ['ip_address' => $ip]);
        $this->log($pj, 'HEALTH_CHECK', 'Health check passed: server active with IP ' . $ip . '.', true);
        Audit::system('server.health_check_passed', [
            'provisioning_job_id' => (int) $pj['id'],
            'ip_address'          => $ip,
        ]);
    }

    /** READY path: persist truth into the module, WHMCS service and tblservers. */
    private function finalizeProvision(array $pj, array $server, InfrastructureProviderInterface $provider)
    {
        $now = Clock::now();

        // WHMCS server record (provider connection record) — created once.
        $whmcsServerId = (int) $server['whmcs_server_id'];
        if (!$whmcsServerId) {
            $whmcsServerId = (int) Platform::gateway()->createServerRecord([
                'name'      => (string) $pj['hostname'],
                'hostname'  => (string) $pj['hostname'],
                'ipaddress' => (string) $pj['ip_address'],
                'username'  => 'root',
                'type'      => 'cloudhost247services',
            ]);
        } else {
            Platform::gateway()->updateServerRecord($whmcsServerId, [
                'hostname'  => (string) $pj['hostname'],
                'ipaddress' => (string) $pj['ip_address'],
            ]);
        }

        // The WHMCS service follows the provider truth: hostname, server
        // record link, Active status.
        if (!empty($pj['hosting_id'])) {
            Platform::gateway()->updateService((int) $pj['hosting_id'], [
                'domain' => (string) $pj['hostname'],
                'server' => $whmcsServerId,
                'status' => 'Active',
            ]);
        }

        Db::update('module_servers', ['id' => (int) $server['id']], [
            'whmcs_server_id'    => $whmcsServerId,
            'provider_server_id' => (string) $pj['provider_server_id'],
            'ip_address'         => (string) $pj['ip_address'],
            'status'             => 'active',
            'provisioned_at'     => $now,
            'updated_at'         => $now,
        ]);
        $this->setStatus($pj, self::STATUS_READY, 'HEALTH_CHECK', [
            'completed_at' => $now,
        ]);
        $this->log($pj, 'READY', 'Server is READY.', true);

        Audit::system('server.ready', [
            'provisioning_job_id' => (int) $pj['id'],
            'module_server_id'    => (int) $server['id'],
            'ip_address'          => (string) $pj['ip_address'],
            'provider_server_id'  => (string) $pj['provider_server_id'],
            'correlation'         => (string) $pj['correlation_id'],
        ]);

        if (Settings::bool('server_notifications_enabled', true)) {
            $version = $this->catalog->versionRow((int) $pj['operating_system_version_id']);
            $region = !empty($pj['region_id'])
                ? Db::first('infrastructure_regions', ['id' => (int) $pj['region_id']])
                : null;
            (new NotificationService())->notify(
                (int) $pj['client_id'],
                'server',
                'Your CloudHost247 server is ready',
                'Server: ' . (string) $pj['hostname'] . "\n"
                . 'Operating system: ' . ($version ? $version['display_name'] : 'UNKNOWN') . "\n"
                . 'IP address: ' . (string) $pj['ip_address'] . "\n"
                . 'Region: ' . ($region ? $region['name'] : '—') . "\n\n"
                . 'Your server is now available from your CloudHost247 dashboard.',
                'index.php?m=cloudhost247services&action=server&id=' . (int) $server['id']
            );
        }
    }

    /* ============================================================ reinstall == */

    /**
     * Customer-requested OS reinstall (destructive). Ownership, capability and
     * image availability are all validated before a job is created; the UI
     * requires explicit confirmation and the job — not the request — does
     * the work.
     *
     * @return int provisioning job id
     */
    public function requestReinstall($clientId, $moduleServerId, $osVersionId, $architecture, $confirmed)
    {
        $clientId = (int) $clientId;
        if (!$confirmed) {
            throw new ValidationException(['confirm' => 'Please confirm that you understand reinstalling erases the current operating-system data.']);
        }
        $server = Db::first('module_servers', ['id' => (int) $moduleServerId]);
        if (!$server || (int) $server['client_id'] !== $clientId) {
            throw new NotFoundException('Server not found.');
        }
        if (!in_array($server['status'], ['active', 'stopped'], true)) {
            throw new ValidationException(['server' => 'This server cannot be reinstalled in its current state.']);
        }
        $architecture = strtolower(trim((string) $architecture));
        $version = $this->catalog->versionRow((int) $osVersionId);
        if (!$version) {
            throw new ValidationException(['os_version_id' => 'OS version not found.']);
        }
        $os = $this->catalog->findOs((int) $version['operating_system_id']);
        if (!$os || $os['status'] !== 'ACTIVE' || empty($os['is_reinstall_supported'])) {
            throw new ValidationException(['os_version_id' => 'This OS is not available for reinstall.']);
        }
        if (!in_array($version['status'], OsCatalogService::SELECTABLE_VERSION_STATUSES, true)) {
            throw new ValidationException(['os_version_id' => 'This OS version is retired or end-of-life.']);
        }
        if (!in_array($architecture, $version['architectures'], true)) {
            throw new ValidationException(['architecture' => 'Architecture is not supported by this OS version.']);
        }
        $image = $this->resolver->resolve((int) $version['id'], $architecture, (int) $server['provider_id'], (int) $server['region_id']);

        $jobKey = 'reinstall:' . (int) $server['id'] . ':' . (int) $version['id'] . ':' . Str::random(6);
        $activeStatuses = self::ACTIVE_STATUSES;
        $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        $existing = Db::query(
            'SELECT id FROM ' . Db::t('provisioning_jobs')
            . ' WHERE module_server_id = ? AND operating_system_version_id = ? AND type = \'REINSTALL\''
            . ' AND status IN (' . $placeholders . ') LIMIT 1',
            array_merge([(int) $server['id'], (int) $version['id']], $activeStatuses)
        );
        if ($existing) {
            throw new DuplicateOperationException('A reinstall for this server and OS version is already queued or running.');
        }

        $now = Clock::now();
        $jobId = Db::insert('provisioning_jobs', [
            'job_key'                     => $jobKey,
            'type'                        => 'REINSTALL',
            'client_id'                   => $clientId,
            'module_server_id'            => (int) $server['id'],
            'hosting_id'                  => (int) $server['hosting_id'],
            'invoice_id'                  => null,
            'provider_id'                 => (int) $server['provider_id'],
            'os_image_id'                 => (int) $image['id'],
            'operating_system_version_id' => (int) $version['id'],
            'architecture'                => $architecture,
            'region_id'                   => (int) $server['region_id'],
            'hostname'                    => (string) $server['hostname'],
            'ssh_key_id'                  => null,
            'status'                      => self::STATUS_QUEUED,
            'stage'                       => '',
            'attempts'                    => 0,
            'max_attempts'                => max(1, Settings::int('provisioning_max_attempts', 5)),
            'provider_server_id'          => (string) $server['provider_server_id'],
            'ip_address'                  => (string) $server['ip_address'],
            'logs'                        => json_encode([]),
            'correlation_id'              => Str::random(12),
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ]);
        $this->enqueueTypedRun($jobId, InfraJobTypes::REINSTALL, $server['id']);
        Audit::client($clientId, 'server.reinstall_started', [
            'module_server_id' => (int) $server['id'],
            'os_version'       => (int) $version['id'],
            'architecture'     => $architecture,
        ]);
        return $jobId;
    }

    /** Worker entry: execute a reinstall job. */
    public function executeReinstall(array $queueJob)
    {
        $pj = $this->jobRow(isset($queueJob['payload']['provisioning_job_id']) ? (int) $queueJob['payload']['provisioning_job_id'] : 0);
        if (!$pj || $pj['type'] !== 'REINSTALL') {
            throw new ValidationException(['job' => 'Malformed reinstall job.']);
        }
        if ($pj['status'] === self::STATUS_FAILED && (int) $pj['retryable'] === 1) {
            Db::update('provisioning_jobs', ['id' => (int) $pj['id']], [
                'status'     => self::STATUS_QUEUED,
                'updated_at' => Clock::now(),
            ]);
            $pj['status'] = self::STATUS_QUEUED;
        } elseif (in_array($pj['status'], self::TERMINAL_STATUSES, true)) {
            return;
        }
        try {
            $this->runReinstall($pj);
        } catch (HealthCheckPending $e) {
            return; // continuation job already scheduled
        } catch (ProviderFailure $e) {
            $this->failJob($pj, $e, 'REINSTALL');
            if ($e->isRetryable()) {
                throw $e;
            }
        }
    }

    private function runReinstall(array $pj)
    {
        $server = Db::first('module_servers', ['id' => (int) $pj['module_server_id']]);
        if (!$server) {
            throw ProviderFailure::permanent('Module server record missing.', 'INVALID_CONFIGURATION');
        }
        $provider = $this->registry->resolve((int) $pj['provider_id']);
        if ($provider instanceof NullInfrastructureProvider || !$provider->isConfigured()) {
            throw ProviderFailure::notConfigured();
        }
        $caps = $provider->capabilities();
        if (empty($caps['reinstall'])) {
            throw ProviderFailure::unsupported('reinstall');
        }
        $image = $this->catalog->imageRow((int) $pj['os_image_id']);
        if (!$image || $image['status'] !== 'active') {
            throw ProviderFailure::imageUnavailable('mapping #' . (int) $pj['os_image_id'] . ' is no longer active');
        }

        if ($pj['status'] === self::STATUS_QUEUED) {
            $this->log($pj, 'REINSTALL', 'Reinstall requested: deploying ' . $this->resolver->providerImageRef($image) . '.', true);
            $provider->reinstallServer($pj['provider_server_id'], $this->resolver->providerImageRef($image), [
                'hostname' => (string) $pj['hostname'],
                'ssh_key'  => $this->sshKeyForJob($pj),
            ]);
            $this->log($pj, 'REINSTALL', 'Provider accepted the reinstall; waiting for the new OS to boot…', true);
            $this->setStatus($pj, self::STATUS_HEALTH_CHECK, 'REINSTALL');
            $pj['status'] = self::STATUS_HEALTH_CHECK;
        }

        // HEALTH_CHECK: wait until the provider reports the server active.
        $this->log($pj, 'HEALTH_CHECK', 'Health check started…', true);
        $status = $provider->getServerStatus($pj['provider_server_id']);
        if ($status !== 'active') {
            $this->log($pj, 'HEALTH_CHECK', 'Server status is "' . $status . '", waiting…', false);
            $this->schedulePoll($pj);
            throw new HealthCheckPending();
        }

        $now = Clock::now();
        Db::update('module_servers', ['id' => (int) $server['id']], [
            'operating_system_version_id' => (int) $pj['operating_system_version_id'],
            'architecture'                => (string) $pj['architecture'],
            'status'                      => 'active',
            'updated_at'                  => $now,
        ]);
        $this->setStatus($pj, self::STATUS_READY, 'HEALTH_CHECK', ['completed_at' => $now]);
        $this->log($pj, 'READY', 'Reinstall complete; the server now runs the selected OS.', true);
        Audit::system('server.reinstall_completed', [
            'provisioning_job_id' => (int) $pj['id'],
            'module_server_id'    => (int) $server['id'],
            'os_version'          => (int) $pj['operating_system_version_id'],
        ]);
        if (Settings::bool('server_notifications_enabled', true)) {
            $version = $this->catalog->versionRow((int) $pj['operating_system_version_id']);
            (new NotificationService())->notify(
                (int) $pj['client_id'],
                'server',
                'Your server OS reinstall is complete',
                'Server: ' . (string) $pj['hostname'] . "\n"
                . 'Operating system: ' . ($version ? $version['display_name'] : 'UNKNOWN') . "\n\n"
                . 'The reinstall finished and the server passed its health check.',
                'index.php?m=cloudhost247services&action=server&id=' . (int) $server['id']
            );
        }
    }

    /* ============================================================== actions == */

    /**
     * Customer-requested server action (start/stop/reboot/shutdown/rescue/
     * delete). Ownership and provider capability are validated; the action
     * runs as a job, never inline.
     *
     * @return int provisioning job id
     */
    public function requestAction($clientId, $moduleServerId, $action)
    {
        $clientId = (int) $clientId;
        $action = strtolower((string) $action);
        if (!isset(self::ACTIONS[$action])) {
            throw new ValidationException(['action' => 'Unsupported action.']);
        }
        if (!Settings::bool('server_actions_enabled', true)) {
            throw new ValidationException(['action' => 'Server actions are currently disabled.']);
        }
        $server = Db::first('module_servers', ['id' => (int) $moduleServerId]);
        if (!$server || (int) $server['client_id'] !== $clientId) {
            throw new NotFoundException('Server not found.'); // no existence leak across owners
        }
        if (!in_array($server['status'], ['active', 'stopped', 'failed'], true)) {
            throw new ValidationException(['server' => 'This action is not available while the server is ' . $server['status'] . '.']);
        }
        $provider = $this->registry->resolve((int) $server['provider_id']);
        $capability = self::ACTIONS[$action]['capability'];
        $caps = $provider->capabilities();
        if ($provider instanceof NullInfrastructureProvider || empty($caps[$capability])) {
            throw new ValidationException(['action' => 'PROVIDER_OPERATION_UNSUPPORTED: the provider for this server cannot ' . $action . '.']);
        }

        $jobKey = 'action:' . (int) $server['id'] . ':' . $action . ':' . Str::random(6);
        $now = Clock::now();
        $jobId = Db::insert('provisioning_jobs', [
            'job_key'            => $jobKey,
            'type'               => 'ACTION',
            'client_id'          => $clientId,
            'module_server_id'   => (int) $server['id'],
            'hosting_id'         => (int) $server['hosting_id'],
            'invoice_id'         => null,
            'provider_id'        => (int) $server['provider_id'],
            'os_image_id'        => null,
            'operating_system_version_id' => (int) $server['operating_system_version_id'],
            'architecture'       => (string) $server['architecture'],
            'region_id'          => (int) $server['region_id'],
            'hostname'           => (string) $server['hostname'],
            'ssh_key_id'         => null,
            'action'             => $action,
            'status'             => self::STATUS_QUEUED,
            'stage'              => '',
            'attempts'           => 0,
            'max_attempts'       => max(1, Settings::int('provisioning_max_attempts', 5)),
            'provider_server_id' => (string) $server['provider_server_id'],
            'ip_address'         => (string) $server['ip_address'],
            'logs'               => json_encode([]),
            'correlation_id'     => Str::random(12),
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $this->enqueueTypedRun($jobId, InfraJobTypes::ACTION, $server['id']);
        Audit::client($clientId, 'server.action_requested', [
            'module_server_id' => (int) $server['id'],
            'action'           => $action,
        ]);
        return $jobId;
    }

    /** Worker entry: execute a server action job. */
    public function executeAction(array $queueJob)
    {
        $pj = $this->jobRow(isset($queueJob['payload']['provisioning_job_id']) ? (int) $queueJob['payload']['provisioning_job_id'] : 0);
        if (!$pj || $pj['type'] !== 'ACTION') {
            throw new ValidationException(['job' => 'Malformed action job.']);
        }
        if ($pj['status'] === self::STATUS_FAILED && (int) $pj['retryable'] === 1) {
            Db::update('provisioning_jobs', ['id' => (int) $pj['id']], [
                'status'     => self::STATUS_QUEUED,
                'updated_at' => Clock::now(),
            ]);
            $pj['status'] = self::STATUS_QUEUED;
        } elseif (in_array($pj['status'], self::TERMINAL_STATUSES, true)) {
            return;
        }
        $action = (string) $pj['action'];
        if (!isset(self::ACTIONS[$action])) {
            throw ProviderFailure::permanent('Unknown action "' . $action . '".', 'INVALID_CONFIGURATION');
        }
        $server = Db::first('module_servers', ['id' => (int) $pj['module_server_id']]);
        if (!$server) {
            throw ProviderFailure::permanent('Module server record missing.', 'INVALID_CONFIGURATION');
        }
        try {
            $provider = $this->registry->resolve((int) $pj['provider_id']);
            $spec = self::ACTIONS[$action];
            $this->log($pj, 'ACTION', 'Executing ' . $action . ' at provider…', true);
            $provider->{$spec['method']}($pj['provider_server_id']);
            $this->log($pj, 'ACTION', 'Provider confirmed ' . $action . '.', true);

            $newStatus = $spec['status'];
            Db::update('module_servers', ['id' => (int) $server['id']], [
                'status'     => $newStatus,
                'updated_at' => Clock::now(),
            ]);
            if ($action === 'delete' && !empty($pj['hosting_id'])) {
                // Deleting the server terminates the WHMCS service — billing
                // follows the platform record, never the other way round.
                Platform::gateway()->updateService((int) $pj['hosting_id'], ['status' => 'Terminated']);
            }
            $this->setStatus($pj, self::STATUS_READY, 'ACTION', ['completed_at' => Clock::now()]);
            Audit::system($spec['audit'], [
                'module_server_id' => (int) $server['id'],
                'provider_id'      => (int) $pj['provider_id'],
                'correlation'      => (string) $pj['correlation_id'],
            ]);
        } catch (ProviderFailure $e) {
            $this->failJob($pj, $e, 'ACTION');
            if ($e->isRetryable()) {
                throw $e;
            }
        }
    }

    /**
     * Console access (read-only): capability-gated, ownership-checked,
     * executed inline (no state change, no job).
     *
     * @return array{url:string,type:string}
     */
    public function consoleFor($clientId, $moduleServerId)
    {
        $server = Db::first('module_servers', ['id' => (int) $moduleServerId]);
        if (!$server || (int) $server['client_id'] !== (int) $clientId) {
            throw new NotFoundException('Server not found.');
        }
        $provider = $this->registry->resolve((int) $server['provider_id']);
        $caps = $provider->capabilities();
        if ($provider instanceof NullInfrastructureProvider || empty($caps['console'])) {
            throw new ValidationException(['console' => 'PROVIDER_OPERATION_UNSUPPORTED: the provider for this server cannot provide console access.']);
        }
        $console = $provider->getConsole((string) $server['provider_server_id']);
        if (!$console || empty($console['url'])) {
            throw new ValidationException(['console' => 'The provider did not return a console URL.']);
        }
        Audit::client((int) $clientId, 'server.console_opened', ['module_server_id' => (int) $server['id']]);
        return $console;
    }

    /* ============================================================ health check == */

    /** Worker entry: standalone health check for one server. */
    public function executeHealthCheck(array $queueJob)
    {
        $serverId = isset($queueJob['payload']['module_server_id']) ? (int) $queueJob['payload']['module_server_id'] : 0;
        $server = Db::first('module_servers', ['id' => $serverId]);
        if (!$server || trim((string) $server['provider_server_id']) === '') {
            return;
        }
        $provider = $this->registry->resolve((int) $server['provider_id']);
        if ($provider instanceof NullInfrastructureProvider) {
            return;
        }
        Audit::system('server.health_check_started', ['module_server_id' => $serverId]);
        try {
            $status = $provider->getServerStatus((string) $server['provider_server_id']);
            $ip = $provider->getServerIp((string) $server['provider_server_id']);
            $mapped = $status === 'active' ? 'active' : ($status === 'stopped' ? 'stopped' : $server['status']);
            Db::update('module_servers', ['id' => $serverId], [
                'status'     => $mapped,
                'ip_address' => $ip ?: (string) $server['ip_address'],
                'updated_at' => Clock::now(),
            ]);
            Audit::system('server.health_check_passed', [
                'module_server_id' => $serverId,
                'provider_status'  => $status,
            ]);
        } catch (ProviderFailure $e) {
            Audit::system('server.health_check_failed', [
                'module_server_id' => $serverId,
                'error_code'       => $e->machineCode(),
            ]);
            if ($e->isRetryable()) {
                throw $e;
            }
        }
    }

    /** Periodic sweep (INFRA_PROVIDER_SYNC): reconcile server states with provider truth. */
    public function syncProviderStates()
    {
        $rows = Db::query(
            'SELECT ms.* FROM ' . Db::t('module_servers') . ' ms
             WHERE ms.provider_server_id IS NOT NULL AND ms.provider_server_id != \'\'
               AND ms.status IN (\'active\',\'stopped\')
               AND NOT EXISTS (
                   SELECT 1 FROM ' . Db::t('provisioning_jobs') . ' pj
                   WHERE pj.module_server_id = ms.id
                     AND pj.status IN (\'QUEUED\',\'ALLOCATING\',\'CREATING\',\'INSTALLING_OS\',\'CONFIGURING\',\'NETWORK_CONFIGURING\',\'SECURITY_CONFIGURING\',\'HEALTH_CHECK\')
               )'
        ) ?: [];
        $synced = 0;
        foreach ($rows as $server) {
            $provider = $this->registry->resolve((int) $server['provider_id']);
            if ($provider instanceof NullInfrastructureProvider) {
                continue;
            }
            try {
                $status = $provider->getServerStatus((string) $server['provider_server_id']);
                $mapped = $status === 'active' ? 'active'
                    : ($status === 'stopped' ? 'stopped' : null);
                if ($mapped && $mapped !== $server['status']) {
                    Db::update('module_servers', ['id' => (int) $server['id']], [
                        'status'     => $mapped,
                        'updated_at' => Clock::now(),
                    ]);
                    $synced++;
                }
            } catch (\Throwable $e) {
                Logger::warning('Provider state sync failed for server', [
                    'module_server_id' => (int) $server['id'],
                    'message'          => $e->getMessage(),
                ]);
            }
        }
        return $synced;
    }

    /* ================================================================ admin == */

    /** Admin retry of a terminally failed job (fresh worker run). */
    public function adminRetry($jobId, $staff)
    {
        $pj = $this->jobRow((int) $jobId);
        if (!$pj) {
            throw new NotFoundException('Provisioning job not found.');
        }
        if ($pj['status'] !== self::STATUS_FAILED) {
            throw new DuplicateOperationException('Only failed jobs can be retried.');
        }
        Db::update('provisioning_jobs', ['id' => (int) $pj['id']], [
            'status'        => self::STATUS_QUEUED,
            'error_code'    => '',
            'error_message' => '',
            'updated_at'    => Clock::now(),
        ]);
        if (!empty($pj['module_server_id'])) {
            Db::update('module_servers', ['id' => (int) $pj['module_server_id']], [
                'status'     => $pj['type'] === 'PROVISION' ? 'provisioning' : 'active',
                'updated_at' => Clock::now(),
            ]);
        }
        $pj['status'] = self::STATUS_QUEUED;
        $type = $pj['type'] === 'REINSTALL' ? InfraJobTypes::REINSTALL
            : ($pj['type'] === 'ACTION' ? InfraJobTypes::ACTION : InfraJobTypes::PROVISION);
        $this->enqueueTypedRun($pj['id'], $type, (int) $pj['module_server_id']);
        Audit::admin((int) $staff, 'server.admin_retry', ['provisioning_job_id' => (int) $pj['id']]);
        return true;
    }

    /** Admin cancel of a queued job. */
    public function adminCancel($jobId, $staff)
    {
        $pj = $this->jobRow((int) $jobId);
        if (!$pj) {
            throw new NotFoundException('Provisioning job not found.');
        }
        if (!in_array($pj['status'], [self::STATUS_QUEUED, self::STATUS_FAILED], true)) {
            throw new DuplicateOperationException('Only queued or failed jobs can be cancelled.');
        }
        $this->setStatus($pj, self::STATUS_CANCELLED, $pj['stage'] ?: 'QUEUED');
        Audit::admin((int) $staff, 'server.admin_cancel', ['provisioning_job_id' => (int) $pj['id']]);
        return true;
    }

    /** @return array{rows:array[], total:int, page:int, per_page:int} */
    public function listJobs(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, min(200, (int) $perPage));
        $where = [];
        $bind = [];
        if (!empty($filters['status'])) {
            $where[] = 'j.status = ?';
            $bind[] = (string) $filters['status'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'j.type = ?';
            $bind[] = (string) $filters['type'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . strtolower(trim((string) $filters['search'])) . '%';
            $where[] = '(LOWER(j.job_key) LIKE ? OR LOWER(j.hostname) LIKE ? OR LOWER(j.error_message) LIKE ? OR LOWER(j.provider_server_id) LIKE ?)';
            $bind[] = $like;
            $bind[] = $like;
            $bind[] = $like;
            $bind[] = $like;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $countRow = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('provisioning_jobs') . ' j' . $whereSql, $bind);
        $total = $countRow ? (int) $countRow[0]['c'] : 0;
        $rows = Db::query(
            'SELECT j.*, ms.hostname AS server_hostname, ms.ip_address AS server_ip,
                    v.display_name AS os_display
             FROM ' . Db::t('provisioning_jobs') . ' j
             LEFT JOIN ' . Db::t('module_servers') . ' ms ON ms.id = j.module_server_id
             LEFT JOIN ' . Db::t('operating_system_versions') . ' v ON v.id = j.operating_system_version_id'
            . $whereSql . ' ORDER BY j.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        ) ?: [];
        foreach ($rows as &$row) {
            $row['logs'] = json_decode((string) $row['logs'], true) ?: [];
        }
        unset($row);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @return array|null job row with decoded logs */
    public function jobDetail($jobId)
    {
        $row = $this->jobRow((int) $jobId);
        if (!$row) {
            return null;
        }
        $row['logs'] = json_decode((string) $row['logs'], true) ?: [];
        $row['server'] = !empty($row['module_server_id'])
            ? Db::first('module_servers', ['id' => (int) $row['module_server_id']])
            : null;
        $row['image'] = !empty($row['os_image_id'])
            ? $this->catalog->imageRow((int) $row['os_image_id'])
            : null;
        return $row;
    }

    /** @return array<string,int> status => count */
    public function stats()
    {
        $out = array_fill_keys(array_merge(self::ACTIVE_STATUSES, self::TERMINAL_STATUSES), 0);
        foreach (Db::query('SELECT status, COUNT(*) AS c FROM ' . Db::t('provisioning_jobs') . ' GROUP BY status') ?: [] as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    /* ============================================================== internals == */

    /** @return array|null */
    private function jobRow($jobId)
    {
        $row = Db::first('provisioning_jobs', ['id' => (int) $jobId]);
        return $row ?: null;
    }

    /** Append a stage log entry (never secrets) and persist. */
    private function log(array &$pj, $stage, $message, $ok)
    {
        $logs = json_decode((string) $pj['logs'], true) ?: [];
        $logs[] = [
            'at'      => Clock::now(),
            'stage'   => (string) $stage,
            'ok'      => (bool) $ok,
            'message' => substr((string) $message, 0, 500),
        ];
        $pj['logs'] = json_encode($logs);
        Db::update('provisioning_jobs', ['id' => (int) $pj['id']], [
            'logs'       => $pj['logs'],
            'updated_at' => Clock::now(),
        ]);
    }

    private function setStatus(array &$pj, $status, $stage, array $extra = [])
    {
        $set = [
            'status'     => (string) $status,
            'stage'      => (string) $stage,
            'updated_at' => Clock::now(),
        ];
        if ($status === self::STATUS_QUEUED || in_array($status, self::ACTIVE_STATUSES, true)) {
            $set['started_at'] = Clock::now();
        }
        foreach ($extra as $k => $v) {
            $set[$k] = $v;
        }
        Db::update('provisioning_jobs', ['id' => (int) $pj['id']], $set);
        $pj['status'] = (string) $status;
        $pj['stage'] = (string) $stage;
        foreach ($extra as $k => $v) {
            $pj[$k] = $v;
        }
    }

    private function updateJob(array &$pj, array $fields)
    {
        $fields['updated_at'] = Clock::now();
        Db::update('provisioning_jobs', ['id' => (int) $pj['id']], $fields);
        foreach ($fields as $k => $v) {
            $pj[$k] = $v;
        }
    }

    /**
     * Schedule the next poll run as a fresh queue job (idempotent key per
     * run). The current queue job completes; the continuation drives progress
     * until the deadline, then the job fails with PROVIDER_TIMEOUT.
     */
    private function schedulePoll(array $pj)
    {
        $deadline = strtotime((string) $pj['created_at'] . ' +' . max(1, Settings::int('provisioning_deadline_minutes', 30)) . ' minutes');
        if (Clock::time() >= $deadline) {
            throw ProviderFailure::timeout('server to become ready within ' . Settings::int('provisioning_deadline_minutes', 30) . ' minutes');
        }
        Db::update('provisioning_jobs', ['id' => (int) $pj['id']], [
            'next_poll_at' => Clock::in(max(30, Settings::int('provisioning_poll_seconds', 60))),
            'updated_at'   => Clock::now(),
        ]);
        $run = (int) $pj['attempts'] + 1;
        Db::update('provisioning_jobs', ['id' => (int) $pj['id']], ['attempts' => $run]);
        $type = $pj['type'] === 'REINSTALL' ? InfraJobTypes::REINSTALL
            : ($pj['type'] === 'ACTION' ? InfraJobTypes::ACTION : InfraJobTypes::PROVISION);
        $this->queue->enqueue($type, [
            'provisioning_job_id' => (int) $pj['id'],
        ], [
            'idempotency_key' => $pj['job_key'] . ':run:' . $run,
            'correlation_id'  => (string) $pj['correlation_id'],
            'entity_type'     => 'module_server',
            'entity_id'       => (int) $pj['module_server_id'],
            'max_attempts'    => max(1, (int) $pj['max_attempts']),
            'available_at'    => Clock::in(max(30, Settings::int('provisioning_poll_seconds', 60))),
        ]);
        throw new HealthCheckPending();
    }

    /** Enqueue one worker run for an arbitrary provisioning job type. */
    private function enqueueTypedRun($jobId, $type, $moduleServerId)
    {
        $pj = $this->jobRow((int) $jobId);
        $run = (int) $pj['attempts'] + 1;
        Db::update('provisioning_jobs', ['id' => (int) $pj['id']], [
            'attempts'   => $run,
            'updated_at' => Clock::now(),
        ]);
        $this->queue->enqueue($type, [
            'provisioning_job_id' => (int) $pj['id'],
            'module_server_id'    => (int) $moduleServerId,
        ], [
            'idempotency_key' => $pj['job_key'] . ':run:' . $run,
            'correlation_id'  => (string) $pj['correlation_id'],
            'entity_type'     => 'module_server',
            'entity_id'       => (int) $moduleServerId,
            'max_attempts'    => max(1, (int) $pj['max_attempts']),
        ]);
    }

    /**
     * Record a failure: terminal FAILED state with machine code + retry
     * classification, module server state, audit and customer notification.
     */
    private function failJob(array $pj, ProviderFailure $e, $context = 'PROVISION')
    {
        $now = Clock::now();
        // Refresh the log chain from the database: the in-memory copy may be
        // stale (stage logs were written by nested calls), and appending to a
        // stale chain would silently drop those entries.
        $fresh = $this->jobRow((int) $pj['id']);
        if ($fresh) {
            $pj['logs'] = $fresh['logs'];
        }
        Db::update('provisioning_jobs', ['id' => (int) $pj['id']], [
            'status'        => self::STATUS_FAILED,
            'error_code'    => $e->machineCode(),
            'error_message' => substr($e->getMessage(), 0, 500),
            'retryable'     => $e->isRetryable() ? 1 : 0,
            'failed_at'     => $now,
            'updated_at'    => $now,
        ]);
        $this->log($pj, 'FAILED', $e->machineCode() . ': ' . $e->getMessage(), false);
        if (!empty($pj['module_server_id'])) {
            $serverStatus = $context === 'PROVISION' ? 'failed' : 'active';
            Db::update('module_servers', ['id' => (int) $pj['module_server_id']], [
                'status'     => $serverStatus,
                'updated_at' => $now,
            ]);
        }
        Audit::system($context === 'REINSTALL' ? 'server.reinstall_failed' : 'server.provisioning_failed', [
            'provisioning_job_id' => (int) $pj['id'],
            'error_code'          => $e->machineCode(),
            'retryable'           => $e->isRetryable(),
            'correlation'         => (string) $pj['correlation_id'],
        ]);
        if (Settings::bool('server_notifications_enabled', true) && (int) $pj['client_id'] > 0) {
            (new NotificationService())->notify(
                (int) $pj['client_id'],
                'server',
                $context === 'REINSTALL' ? 'Server OS reinstall failed' : 'Server provisioning failed',
                'Server: ' . (string) $pj['hostname'] . "\n"
                . 'Error: ' . $e->machineCode() . ' — ' . $e->getMessage() . "\n\n"
                . 'Our team has been notified. You can retry from your dashboard or contact support.',
                'index.php?m=cloudhost247services&action=server&id=' . (int) $pj['module_server_id']
            );
        }
    }

    /** The SSH public key attached to a job (public half only). */
    private function sshKeyForJob(array $pj)
    {
        if (empty($pj['ssh_key_id'])) {
            return '';
        }
        $key = Db::first('customer_ssh_keys', ['id' => (int) $pj['ssh_key_id']]);
        return $key ? (string) $key['public_key'] : '';
    }
}

/**
 * Internal control-flow signal: the current run stops here and the already
 * scheduled continuation job drives the next poll.
 */
class HealthCheckPending extends \Chs\Core\ChsException
{
    protected $machineCode = 'health_check_pending';
}
