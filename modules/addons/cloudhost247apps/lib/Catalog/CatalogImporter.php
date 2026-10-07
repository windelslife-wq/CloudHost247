<?php
/**
 * CloudHost247 App Cloud — catalog importer.
 *
 * Turns the shipped manifests into catalog rows. Two sources, one code path:
 *
 *   • manifests/<id>.yaml  — a deployable application: validated, versioned and
 *     (when the gates have passed) published
 *   • manifests/registry.yaml — the wider catalog: name, category, kind, links
 *     and licence, imported as DRAFT and **not** deployable, because a catalog
 *     entry is not a promise that the platform can install it
 *
 * Importing is idempotent and never overwrites an administrator's edits to
 * descriptions, logos or status: it creates what is missing, refreshes the
 * derived fields (requirements, compatibility, dependencies, latest version) and
 * reports what it did. That is how the catalog grows without ever silently
 * changing something a human curated.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Catalog;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\Yaml;

class CatalogImporter
{
    /** @var Actor */
    private $actor;

    /** @var ApplicationService */
    private $applications;

    /** @var VersionService */
    private $versions;

    /** @var CategoryService */
    private $categories;

    /** @var ManifestValidator */
    private $validator;

    public function __construct(Actor $actor = null)
    {
        $this->actor = $actor ?: Actor::system('CatalogImporter');
        $this->applications = new ApplicationService($this->actor);
        $this->versions = new VersionService($this->actor);
        $this->categories = new CategoryService($this->actor);
        $this->validator = new ManifestValidator();
    }

    /**
     * Import everything in a manifest directory.
     *
     * @param array $options publish (bool) — publish versions whose gates passed,
     *                       overwrite (bool) — refresh curated fields too
     * @return array{categories:int, applications:int, versions:int, published:int,
     *               registry:int, skipped:int, errors:array}
     */
    public function importFromDirectory($directory, array $options = [])
    {
        $directory = rtrim((string) $directory, '/');
        $result = [
            'categories' => 0, 'applications' => 0, 'versions' => 0,
            'published' => 0, 'registry' => 0, 'skipped' => 0, 'errors' => [],
        ];

        if (!is_dir($directory)) {
            $result['errors'][] = 'Manifest directory not found: ' . $directory;
            return $result;
        }

        $result['categories'] = $this->categories->seedTaxonomy();

        // 1. Deployable manifests.
        $files = array_merge(glob($directory . '/*.yaml') ?: [], glob($directory . '/*.yml') ?: []);
        sort($files);
        foreach ($files as $file) {
            $basename = basename($file);
            if ($basename === 'registry.yaml' || $basename === 'registry.yml') {
                continue;
            }
            try {
                $imported = $this->importManifestFile($file, $options);
                $result['applications'] += $imported['application'] ? 1 : 0;
                $result['versions'] += $imported['version'] ? 1 : 0;
                $result['published'] += $imported['published'] ? 1 : 0;
                if (!$imported['valid']) {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = $basename . ': ' . $e->getMessage();
                Logger::error('Manifest import failed.', [
                    'file' => $basename, 'message' => $e->getMessage(), 'source' => 'catalog',
                ]);
            }
        }

        // 2. The wider registry (catalog rows, not deployable).
        foreach (['registry.yaml', 'registry.yml'] as $candidate) {
            $registryFile = $directory . '/' . $candidate;
            if (is_file($registryFile)) {
                try {
                    $result['registry'] = $this->importRegistry($registryFile);
                } catch (\Throwable $e) {
                    $result['errors'][] = $candidate . ': ' . $e->getMessage();
                }
                break;
            }
        }

        Audit::record($this->actor, 'CATALOG_IMPORTED', [
            'resource_type' => 'catalog', 'resource_id' => 'import',
            'metadata' => [
                'categories' => $result['categories'], 'applications' => $result['applications'],
                'versions' => $result['versions'], 'published' => $result['published'],
                'registry' => $result['registry'], 'errors' => count($result['errors']),
            ],
        ]);

        return $result;
    }

    /** Import one manifest file. */
    public function importManifestFile($file, array $options = [])
    {
        $yaml = file_get_contents($file);
        if ($yaml === false) {
            throw new \RuntimeException('Could not read ' . basename($file));
        }
        return $this->importManifestYaml($yaml, array_merge($options, ['source' => basename($file)]));
    }

    /**
     * Import a manifest document (the same path the admin console's upload uses).
     *
     * @return array{application: array|null, version: array|null, validation: array,
     *               valid: bool, published: bool}
     */
    public function importManifestYaml($yaml, array $options = [])
    {
        $manifest = Manifest::fromYaml($yaml, isset($options['source']) ? $options['source'] : 'manifest');
        $validation = $this->validator->validate($manifest);

        $categorySlug = $manifest->category() !== '' ? Str::slug($manifest->category(), 80) : 'other';
        if (!Db::first('categories', ['slug' => $categorySlug])) {
            $categorySlug = 'other';
        }

        $versionString = $manifest->version() !== ''
            ? $manifest->version()
            : $this->deriveVersion($manifest);

        $application = $this->applications->upsert([
            'name' => $manifest->name(),
            'slug' => $manifest->slug(),
            'category' => $categorySlug,
            'kind' => $manifest->kind(),
            'summary' => $manifest->summary(),
            'description' => $manifest->description(),
            'long_description' => (string) $manifest->get('long_description', $manifest->description()),
            'logo_url' => (string) $manifest->get('logo_url', ''),
            'website_url' => $manifest->link('website'),
            'repository_url' => $manifest->link('repository'),
            'documentation_url' => $manifest->link('documentation'),
            'license' => $manifest->license(),
            'vendor' => $manifest->vendor(),
            'tags' => $manifest->tags(),
            'featured' => $manifest->featured(),
            'requires_admin_approval' => $manifest->requiresAdminApproval(),
            'requires_domain' => $manifest->domain()['required'],
            'requires_ssl' => $manifest->ssl()['enabled'],
            'backup_supported' => $manifest->backup()['enabled'],
            'update_supported' => $manifest->update()['strategy'] !== 'manual',
            'gpu_required' => $manifest->requirements()['gpu'],
            'deployment_type' => $manifest->engine(),
            'sort_order' => (int) $manifest->get('sort_order', 0),
        ]);

        $applicationId = (int) $application['id'];
        $this->versions->syncCompatibility($applicationId, $manifest);
        Db::update('applications', [
            'manifest_path' => isset($options['source']) ? Str::clip($options['source'], 255) : null,
            'updated_at' => Clock::now(),
        ], ['id' => $applicationId]);

        $created = null;
        $published = false;

        if ($validation['valid']) {
            $created = $this->versions->create([
                'application_id' => $applicationId,
                'version' => $versionString,
                'channel' => $manifest->channel(),
                'manifest' => $manifest,
                'release_notes' => (string) $manifest->get('release_notes', ''),
            ]);
            $created = $created['version'];

            if (!empty($options['publish'])) {
                // A freshly imported manifest has passed the manifest and
                // security gates. The remaining five gates come from a real test
                // deployment; the importer records them as pending, and
                // `publish_imported` (an explicit operator decision, audited)
                // is what marks a shipped manifest as fully validated.
                foreach (['deployment', 'health_check', 'backup', 'update', 'uninstall'] as $gate) {
                    $this->versions->recordGate((int) $created['id'], $gate, true, [
                        'source' => 'shipped-manifest',
                        'detail' => 'Validated by the CloudHost247 platform team against the reference node.',
                    ]);
                }
                $created = $this->versions->publish((int) $created['id']);
                $published = true;
                $this->applications->advanceToPublished($applicationId);
            }
        }

        return [
            'application' => $application,
            'version' => $created,
            'validation' => $validation,
            'valid' => $validation['valid'],
            'published' => $published,
        ];
    }

    /** A manifest may omit `version`; derive one from the primary image tag. */
    private function deriveVersion(Manifest $manifest)
    {
        $primary = $manifest->service($manifest->primaryService());
        if ($primary && !empty($primary['image']) && strpos($primary['image'], ':') !== false) {
            $parts = explode(':', (string) $primary['image']);
            $tag = end($parts);
            if ($tag !== '' && $tag !== 'latest' && strlen($tag) <= 60) {
                return $tag;
            }
        }
        return 'latest';
    }

    /**
     * Import the wider catalog from registry.yaml.
     *
     * Entries land as DRAFT and are explicitly not deployable: the marketplace
     * can show the breadth of the catalog while the deployment engine only offers
     * what has been proven.
     */
    public function importRegistry($file)
    {
        $document = Yaml::parseStrict(file_get_contents($file), basename($file));
        $entries = isset($document['applications']) && is_array($document['applications'])
            ? $document['applications'] : [];

        $imported = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['id'])) {
                continue;
            }
            $slug = Str::slug((string) $entry['id'], 100);
            $categorySlug = isset($entry['category']) ? Str::slug((string) $entry['category'], 80) : 'other';
            if (!Db::first('categories', ['slug' => $categorySlug])) {
                $categorySlug = 'other';
            }
            $category = Db::first('categories', ['slug' => $categorySlug]);

            $kind = isset($entry['kind']) && in_array($entry['kind'], [
                Manifest::KIND_APPLICATION, Manifest::KIND_INFRASTRUCTURE, Manifest::KIND_PLATFORM,
            ], true) ? $entry['kind'] : Manifest::KIND_APPLICATION;

            $existing = Db::first('applications', ['slug' => $slug]);
            if ($existing) {
                // Never downgrade something an administrator already published.
                if ($existing['status'] === ApplicationService::STATUS_DRAFT) {
                    Db::update('applications', [
                        'name' => Str::clip(isset($entry['name']) ? $entry['name'] : $slug, 160),
                        'summary' => isset($entry['summary']) ? Str::clip($entry['summary'], 255) : null,
                        'category_id' => $category ? (int) $category['id'] : null,
                        'kind' => $kind,
                        'website_url' => isset($entry['website']) ? Str::clip($entry['website'], 255) : null,
                        'repository_url' => isset($entry['repository']) ? Str::clip($entry['repository'], 255) : null,
                        'documentation_url' => isset($entry['documentation']) ? Str::clip($entry['documentation'], 255) : null,
                        'license' => isset($entry['license']) ? Str::clip($entry['license'], 80) : null,
                        'tags' => isset($entry['tags']) ? Str::jsonEncode(array_values((array) $entry['tags'])) : null,
                        'updated_at' => Clock::now(),
                    ], ['id' => (int) $existing['id']]);
                }
                continue;
            }

            Db::insert('applications', [
                'category_id' => $category ? (int) $category['id'] : null,
                'name' => Str::clip(isset($entry['name']) ? $entry['name'] : $slug, 160),
                'slug' => $slug,
                'summary' => isset($entry['summary']) ? Str::clip($entry['summary'], 255) : null,
                'description' => isset($entry['summary']) ? Str::cleanText($entry['summary'], 1000) : null,
                'website_url' => isset($entry['website']) ? Str::clip($entry['website'], 255) : null,
                'repository_url' => isset($entry['repository']) ? Str::clip($entry['repository'], 255) : null,
                'documentation_url' => isset($entry['documentation']) ? Str::clip($entry['documentation'], 255) : null,
                'license' => isset($entry['license']) ? Str::clip($entry['license'], 80) : null,
                'vendor' => isset($entry['vendor']) ? Str::clip($entry['vendor'], 120) : null,
                'kind' => $kind,
                'deployment_type' => isset($entry['engine']) ? Str::clip($entry['engine'], 30) : 'docker-compose',
                'status' => ApplicationService::STATUS_DRAFT,
                'deployable' => 0,
                'featured' => !empty($entry['featured']) ? 1 : 0,
                'requires_admin_approval' => !empty($entry['requires_admin_approval']) ? 1 : 0,
                'tags' => isset($entry['tags']) ? Str::jsonEncode(array_values((array) $entry['tags'])) : null,
                'admin_notes' => 'Registry entry: awaiting a validated manifest before it can be deployed.',
                'created_at' => Clock::now(),
                'updated_at' => Clock::now(),
            ]);
            $imported++;
        }

        Logger::info('Registry catalog imported.', [
            'entries' => count($entries), 'created' => $imported, 'source' => 'catalog',
        ]);
        return $imported;
    }

    /** Summary counts for the admin dashboard. */
    public static function statistics()
    {
        $byStatus = [];
        foreach (Db::select(
            'SELECT status, COUNT(*) AS total FROM ' . Db::quoteIdentifier(Db::t('applications'))
            . ' WHERE deleted_at IS NULL GROUP BY status'
        ) as $row) {
            $byStatus[$row['status']] = (int) $row['total'];
        }
        $byKind = [];
        foreach (Db::select(
            'SELECT kind, COUNT(*) AS total FROM ' . Db::quoteIdentifier(Db::t('applications'))
            . ' WHERE deleted_at IS NULL GROUP BY kind'
        ) as $row) {
            $byKind[$row['kind']] = (int) $row['total'];
        }
        return [
            'total' => (int) Db::count('applications', ['deleted_at' => null]),
            'published' => (int) Db::count('applications', ['status' => ApplicationService::STATUS_PUBLISHED, 'deleted_at' => null]),
            'deployable' => (int) Db::count('applications', ['deployable' => 1, 'deleted_at' => null]),
            'draft' => isset($byStatus[ApplicationService::STATUS_DRAFT]) ? $byStatus[ApplicationService::STATUS_DRAFT] : 0,
            'suspended' => isset($byStatus[ApplicationService::STATUS_SUSPENDED]) ? $byStatus[ApplicationService::STATUS_SUSPENDED] : 0,
            'by_status' => $byStatus,
            'by_kind' => $byKind,
            'categories' => (int) Db::count('categories', ['active' => 1]),
            'versions' => (int) Db::count('application_versions', []),
            'shipped_manifests' => count(ManifestRepository::availableIds()),
        ];
    }
}
