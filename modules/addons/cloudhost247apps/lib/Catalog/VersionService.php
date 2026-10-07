<?php
/**
 * CloudHost247 App Cloud — application versions and the publication gates.
 *
 * A version is a manifest snapshot plus the evidence that it works. Creating one
 * parses and validates the manifest (structure **and** security) and stores the
 * exact YAML with its hash, so the deployment engine always builds from what was
 * approved. Publishing requires all seven gates the specification demands —
 * manifest, security, deployment, health check, backup, update and uninstall —
 * each recorded with who ran it and when. A test deployment run by the admin
 * console fills the deployment/health/backup/update/uninstall gates from real
 * results; nothing marks itself passed.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Catalog;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class VersionService
{
    /** Every gate that must pass before a version can be published. */
    const PUBLICATION_GATES = [
        'manifest',
        'security',
        'deployment',
        'health_check',
        'backup',
        'update',
        'uninstall',
    ];

    const STATUS_DRAFT      = 'draft';
    const STATUS_VALIDATING = 'validating';
    const STATUS_TESTING    = 'testing';
    const STATUS_APPROVED   = 'approved';
    const STATUS_PUBLISHED  = 'published';
    const STATUS_DEPRECATED = 'deprecated';
    const STATUS_REJECTED   = 'rejected';

    /** @var Actor */
    private $actor;

    /** @var ManifestValidator */
    private $validator;

    public function __construct(Actor $actor = null, ManifestValidator $validator = null)
    {
        $this->actor = $actor ?: Actor::system('VersionService');
        $this->validator = $validator ?: new ManifestValidator();
    }

    /**
     * Create (or replace) a version from a manifest.
     *
     * @param array $input application_id, version, channel, manifest (YAML),
     *                     release_notes, docker_image
     * @return array{version: array, validation: array}
     */
    public function create(array $input)
    {
        Rbac::assert($this->actor, Rbac::APP_VERSION_MANAGE);

        $applicationId = (int) (isset($input['application_id']) ? $input['application_id'] : 0);
        $application = Db::first('applications', ['id' => $applicationId, 'deleted_at' => null]);
        if (!$application) {
            throw new NotFoundException('That application is not in the catalog.');
        }

        $version = trim((string) (isset($input['version']) ? $input['version'] : ''));
        if ($version === '' || strlen($version) > 60) {
            throw new ValidationException('A version string of 1–60 characters is required.', [
                'errors' => ['version' => 'Required'],
            ]);
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-+]{0,59}$/', $version)) {
            throw new ValidationException('Versions may contain letters, numbers, dots, dashes and plus signs only.', [
                'errors' => ['version' => 'Invalid format'],
            ]);
        }
        $channel = isset($input['channel']) ? strtolower((string) $input['channel']) : 'stable';
        if (!in_array($channel, ['stable', 'lts', 'beta'], true)) {
            throw new ValidationException('The channel must be stable, lts or beta.', ['errors' => ['channel' => 'Invalid']]);
        }

        $manifest = isset($input['manifest']) && $input['manifest'] instanceof Manifest
            ? $input['manifest']
            : ManifestRepository::fromYaml(isset($input['manifest']) ? $input['manifest'] : '');

        if ($manifest->id() !== '' && $manifest->id() !== $application['slug']) {
            throw new ValidationException(
                'The manifest id "' . $manifest->id() . '" does not match the application "' . $application['slug'] . '".'
            );
        }

        $validation = $this->validator->validate($manifest);
        $requirements = $manifest->requirements();
        $primary = $manifest->service($manifest->primaryService());
        $dockerImage = isset($input['docker_image']) && $input['docker_image'] !== ''
            ? (string) $input['docker_image']
            : ($primary ? (string) $primary['image'] : null);

        $now = Clock::now();
        $fields = [
            'version' => $version,
            'channel' => $channel,
            'docker_image' => $dockerImage === null ? null : Str::clip($dockerImage, 255),
            'manifest' => $manifest->toYaml(),
            'manifest_hash' => $manifest->hash(),
            'manifest_errors' => $validation['errors'] ? Str::jsonEncode($validation['errors']) : null,
            'minimum_cpu' => $requirements['cpu_min'],
            'minimum_memory_mb' => $requirements['memory_min_mb'],
            'minimum_storage_mb' => $requirements['storage_min_mb'],
            'recommended_cpu' => $requirements['cpu_recommended'],
            'recommended_memory_mb' => $requirements['memory_recommended_mb'],
            'recommended_storage_mb' => $requirements['storage_recommended_mb'],
            'minimum_gpu' => $requirements['gpu'] ? 1 : 0,
            'release_notes' => isset($input['release_notes']) ? Str::cleanText($input['release_notes'], 5000) : null,
            'status' => $validation['valid'] ? self::STATUS_TESTING : self::STATUS_DRAFT,
            'validation_report' => Str::jsonEncode($this->initialReport($validation)),
            'updated_at' => $now,
        ];

        $existing = Db::first('application_versions', [
            'application_id' => $applicationId, 'version' => $version, 'channel' => $channel,
        ]);
        if ($existing) {
            Db::update('application_versions', $fields, ['id' => (int) $existing['id']]);
            $versionId = (int) $existing['id'];
        } else {
            $fields['application_id'] = $applicationId;
            $fields['is_latest'] = 0;
            $fields['created_at'] = $now;
            $versionId = Db::insert('application_versions', $fields);
        }

        // Keep the application's own flags in step with its best version.
        Db::update('applications', [
            'requires_domain' => $manifest->domain()['required'] ? 1 : 0,
            'requires_ssl' => $manifest->ssl()['enabled'] ? 1 : 0,
            'backup_supported' => $manifest->backup()['enabled'] ? 1 : 0,
            'update_supported' => $manifest->update()['strategy'] !== 'manual' ? 1 : 0,
            'gpu_required' => $requirements['gpu'] ? 1 : 0,
            'deployment_type' => $manifest->engine(),
            'latest_version_id' => $versionId,
            'updated_at' => $now,
        ], ['id' => $applicationId]);

        if (!$this->compatibilityExists($applicationId)) {
            $this->syncCompatibility($applicationId, $manifest);
        }
        $this->syncDependencies($applicationId, $manifest);
        $this->refreshLatestFlag($applicationId);

        Audit::record($this->actor, Audit::APPLICATION_VERSION_CREATED, [
            'resource_type' => 'application_version', 'resource_id' => $versionId,
            'metadata' => [
                'application' => $application['slug'], 'version' => $version, 'channel' => $channel,
                'valid' => $validation['valid'], 'errors' => count($validation['errors']),
                'warnings' => count($validation['warnings']),
            ],
        ]);

        return [
            'version' => Db::first('application_versions', ['id' => $versionId]),
            'validation' => $validation,
            'manifest' => $manifest,
        ];
    }

    private function compatibilityExists($applicationId)
    {
        return Db::count('application_compatibility', ['application_id' => (int) $applicationId]) > 0;
    }

    /** Derive the compatibility matrix from the manifest's hosting types. */
    public function syncCompatibility($applicationId, Manifest $manifest)
    {
        $supported = $manifest->supportedHostingTypes();
        $now = Clock::now();
        foreach (Manifest::HOSTING_TYPES as $type) {
            $isSupported = in_array($type, $supported, true);
            $existing = Db::first('application_compatibility', [
                'application_id' => (int) $applicationId, 'hosting_type' => $type,
            ]);
            $row = [
                'supported' => $isSupported ? 1 : 0,
                'recommended' => $isSupported && $type === ($manifest->engine() === Manifest::ENGINE_CPANEL ? 'cpanel' : 'vps') ? 1 : 0,
                'updated_at' => $now,
            ];
            if ($existing) {
                Db::update('application_compatibility', $row, ['id' => (int) $existing['id']]);
            } else {
                Db::insert('application_compatibility', array_merge($row, [
                    'application_id' => (int) $applicationId,
                    'hosting_type' => $type,
                    'notes' => null,
                    'created_at' => $now,
                ]));
            }
        }
    }

    /** Link manifest dependencies to catalog entries when they exist. */
    public function syncDependencies($applicationId, Manifest $manifest)
    {
        $now = Clock::now();
        foreach ($manifest->dependencies() as $dependency) {
            $target = Db::first('applications', ['slug' => $dependency['id'], 'deleted_at' => null]);
            if (!$target || (int) $target['id'] === (int) $applicationId) {
                continue;
            }
            $existing = Db::first('application_dependencies', [
                'application_id' => (int) $applicationId,
                'depends_on_application_id' => (int) $target['id'],
                'dependency_type' => $dependency['type'],
            ]);
            if ($existing) {
                Db::update('application_dependencies', [
                    'requirement' => $dependency['requirement'],
                    'isolation' => $dependency['isolation'],
                    'updated_at' => $now,
                ], ['id' => (int) $existing['id']]);
                continue;
            }
            Db::insert('application_dependencies', [
                'application_id' => (int) $applicationId,
                'depends_on_application_id' => (int) $target['id'],
                'dependency_type' => $dependency['type'],
                'requirement' => $dependency['requirement'],
                'isolation' => $dependency['isolation'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Exactly one version per channel is "latest". */
    public function refreshLatestFlag($applicationId)
    {
        foreach (['stable', 'lts', 'beta'] as $channel) {
            $rows = Db::fetch('application_versions', [
                'application_id' => (int) $applicationId, 'channel' => $channel,
                'status' => ['in', [self::STATUS_PUBLISHED, self::STATUS_APPROVED]],
            ], ['order' => 'id', 'dir' => 'desc']);
            foreach ($rows as $index => $row) {
                $shouldBeLatest = $index === 0 ? 1 : 0;
                if ((int) $row['is_latest'] !== $shouldBeLatest) {
                    Db::update('application_versions', ['is_latest' => $shouldBeLatest], ['id' => (int) $row['id']]);
                }
            }
        }
    }

    private function initialReport(array $validation)
    {
        $now = Clock::now();
        $report = [];
        foreach (self::PUBLICATION_GATES as $gate) {
            $report[$gate] = ['passed' => false, 'detail' => null, 'at' => null, 'by' => null];
        }
        $report['manifest'] = [
            'passed' => $validation['valid'] && $validation['errors'] === [],
            'detail' => [
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ],
            'at' => $now,
            'by' => $this->actor->identity(),
        ];
        $securityErrors = array_values(array_filter($validation['errors'], function ($error) {
            $field = isset($error['field']) ? (string) $error['field'] : '';
            return strpos($field, 'services.') === 0 && (
                strpos($field, 'privileged') !== false
                || strpos($field, 'network_mode') !== false
                || strpos($field, 'cap_add') !== false
                || strpos($field, 'devices') !== false
                || strpos($field, 'security_opt') !== false
                || strpos($field, 'volumes') !== false
                || strpos($field, 'pid') !== false
                || strpos($field, 'ipc') !== false
                || strpos($field, 'sysctls') !== false
            );
        }));
        $report['security'] = [
            'passed' => $securityErrors === [] && $validation['valid'],
            'detail' => ['violations' => $securityErrors, 'warnings' => $validation['warnings']],
            'at' => $now,
            'by' => $this->actor->identity(),
        ];
        return $report;
    }

    /**
     * Record the result of a publication gate. The deployment/health/backup/
     * update/uninstall gates are written from real test-deployment results by the
     * admin console's "Test deployment" flow — never by a flag in a form.
     */
    public function recordGate($versionId, $gate, $passed, $detail = null)
    {
        Rbac::assert($this->actor, Rbac::APP_VERSION_MANAGE);
        if (!in_array($gate, self::PUBLICATION_GATES, true)) {
            throw new ValidationException('Unknown publication gate "' . $gate . '".');
        }
        $row = Db::first('application_versions', ['id' => (int) $versionId]);
        if (!$row) {
            throw new NotFoundException('That version does not exist.');
        }

        $report = Str::jsonDecode(isset($row['validation_report']) ? $row['validation_report'] : null, []);
        foreach (self::PUBLICATION_GATES as $name) {
            if (!isset($report[$name])) {
                $report[$name] = ['passed' => false, 'detail' => null, 'at' => null, 'by' => null];
            }
        }
        $report[$gate] = [
            'passed' => (bool) $passed,
            'detail' => $detail === null ? null : Logger::redact(is_array($detail) ? $detail : ['detail' => (string) $detail]),
            'at' => Clock::now(),
            'by' => $this->actor->identity(),
        ];

        Db::update('application_versions', [
            'validation_report' => Str::jsonEncode($report),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);

        Audit::record($this->actor, Audit::MANIFEST_VALIDATED, [
            'resource_type' => 'application_version', 'resource_id' => (int) $row['id'],
            'metadata' => ['gate' => $gate, 'passed' => (bool) $passed],
            'severity' => $passed ? 'info' : 'warning',
        ]);

        return $report;
    }

    /** Publish a version once every gate has passed. */
    public function publish($versionId, array $options = [])
    {
        Rbac::assert($this->actor, Rbac::APP_PUBLISH);
        $row = Db::first('application_versions', ['id' => (int) $versionId]);
        if (!$row) {
            throw new NotFoundException('That version does not exist.');
        }
        $report = Str::jsonDecode(isset($row['validation_report']) ? $row['validation_report'] : null, []);
        $missing = [];
        foreach (self::PUBLICATION_GATES as $gate) {
            if (empty($report[$gate]) || empty($report[$gate]['passed'])) {
                $missing[] = $gate;
            }
        }
        if ($missing && empty($options['force'])) {
            throw new StateException('This version has not passed: ' . implode(', ', $missing) . '.', [
                'error_code' => 'VERSION_GATES_INCOMPLETE', 'missing' => $missing,
            ]);
        }
        if ($missing && !empty($options['force'])) {
            Rbac::assert($this->actor, Rbac::SETTINGS_MANAGE);
        }

        $now = Clock::now();
        Db::update('application_versions', [
            'status' => self::STATUS_PUBLISHED,
            'published_at' => $now,
            'published_by' => $this->actor->adminId ?: null,
            'is_latest' => 1,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        Db::run('UPDATE ' . Db::quoteIdentifier(Db::t('application_versions'))
            . ' SET is_latest = 0 WHERE id != ? AND application_id = ? AND channel = ?',
            [(int) $row['id'], (int) $row['application_id'], $row['channel']]);

        Db::update('applications', [
            'deployable' => 1,
            'latest_version_id' => (int) $row['id'],
            'updated_at' => $now,
        ], ['id' => (int) $row['application_id']]);

        Audit::record($this->actor, Audit::APPLICATION_VERSION_CREATED, [
            'resource_type' => 'application_version', 'resource_id' => (int) $row['id'],
            'metadata' => ['version' => $row['version'], 'action' => 'published', 'forced' => !empty($options['force'])],
            'severity' => empty($options['force']) ? 'info' : 'warning',
        ]);

        return Db::first('application_versions', ['id' => (int) $row['id']]);
    }

    public function deprecate($versionId, $reason = '')
    {
        Rbac::assert($this->actor, Rbac::APP_VERSION_MANAGE);
        $row = Db::first('application_versions', ['id' => (int) $versionId]);
        if (!$row) {
            throw new NotFoundException('That version does not exist.');
        }
        Db::update('application_versions', [
            'status' => self::STATUS_DEPRECATED,
            'is_latest' => 0,
            'auto_update_eligible' => 0,
            'release_notes' => trim((string) $row['release_notes'] . ($reason !== '' ? "\n\nDeprecated: " . $reason : '')),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);
        $this->refreshLatestFlag((int) $row['application_id']);

        Audit::record($this->actor, Audit::APPLICATION_UPDATED, [
            'resource_type' => 'application_version', 'resource_id' => (int) $row['id'],
            'metadata' => ['version' => $row['version'], 'action' => 'deprecated', 'reason' => Str::clip($reason, 200)],
            'severity' => 'warning',
        ]);
        return Db::first('application_versions', ['id' => (int) $row['id']]);
    }

    /** The version an installation should run (latest published, by channel). */
    public function latestFor($applicationId, $channel = 'stable')
    {
        $row = Db::first('application_versions', [
            'application_id' => (int) $applicationId, 'channel' => (string) $channel, 'status' => self::STATUS_PUBLISHED,
        ], ['order' => 'is_latest', 'dir' => 'desc']);
        if (!$row) {
            $row = Db::first('application_versions', [
                'application_id' => (int) $applicationId, 'status' => self::STATUS_PUBLISHED,
            ], ['order' => 'id', 'dir' => 'desc']);
        }
        return $row;
    }

    /** Is a newer published version available for an installed version? */
    public function updateAvailable(array $installationVersionRow)
    {
        $latest = $this->latestFor(
            (int) $installationVersionRow['application_id'],
            isset($installationVersionRow['channel']) ? $installationVersionRow['channel'] : 'stable'
        );
        if (!$latest || (int) $latest['id'] === (int) $installationVersionRow['id']) {
            return null;
        }
        return $latest;
    }
}
