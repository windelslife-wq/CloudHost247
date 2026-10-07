<?php
/**
 * CloudHost247 App Cloud — manifest repository.
 *
 * Loads manifests from disk (the shipped set plus an operator-managed directory)
 * and from the validated snapshots stored on each application version. The
 * deployment engine always builds from the **version snapshot**, never from the
 * file on disk, so an edited manifest can never silently change how an existing
 * customer installation is rebuilt.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Catalog;

use Ch247Apps\Core\Db;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class ManifestRepository
{
    /** @var array<string,Manifest> */
    private static $cache = [];

    /** @var string[] */
    private static $directories;

    /** Directories scanned for manifests, in priority order (later wins). */
    public static function directories()
    {
        if (self::$directories !== null) {
            return self::$directories;
        }
        $paths = [CH247APPS_MANIFEST_PATH];
        $custom = Settings::string('manifest_custom_path', '');
        if ($custom !== '' && is_dir($custom)) {
            $paths[] = rtrim($custom, '/');
        }
        return self::$directories = $paths;
    }

    public static function reset()
    {
        self::$cache = [];
        self::$directories = null;
    }

    /**
     * Files that live in the manifest directory but are not manifests.
     * `registry.yaml` is the wide catalog (see CatalogImporter::importRegistry).
     */
    const NON_MANIFEST_FILES = ['registry'];

    /** Every manifest id available on disk. */
    public static function availableIds()
    {
        $ids = [];
        foreach (self::directories() as $directory) {
            foreach (glob($directory . '/*.yaml') ?: [] as $file) {
                $ids[] = basename($file, '.yaml');
            }
            foreach (glob($directory . '/*.yml') ?: [] as $file) {
                $ids[] = basename($file, '.yml');
            }
        }
        $ids = array_diff(array_unique($ids), self::NON_MANIFEST_FILES);
        sort($ids);
        return array_values($ids);
    }

    /** Path of a manifest file on disk, or null. */
    public static function path($id)
    {
        $id = Str::slug((string) $id, 60);
        if (in_array($id, self::NON_MANIFEST_FILES, true)) {
            return null;
        }
        foreach (array_reverse(self::directories()) as $directory) {
            foreach ([$directory . '/' . $id . '.yaml', $directory . '/' . $id . '.yml'] as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * Read and parse a manifest from disk.
     *
     * @throws NotFoundException
     */
    public static function load($id)
    {
        $id = Str::slug((string) $id, 60);
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $path = self::path($id);
        if ($path === null) {
            throw new NotFoundException('No manifest is shipped for "' . $id . '".');
        }
        $maxBytes = Settings::int('manifest_max_bytes', 262144);
        if (filesize($path) > $maxBytes) {
            throw new ValidationException('The manifest is larger than the permitted ' . $maxBytes . ' bytes.');
        }
        $yaml = file_get_contents($path);
        if ($yaml === false) {
            throw new ValidationException('The manifest could not be read.');
        }
        $manifest = Manifest::fromYaml($yaml, basename($path));
        if ($manifest->id() !== '' && $manifest->id() !== $id) {
            throw new ValidationException('The manifest id "' . $manifest->id()
                . '" does not match its file name "' . $id . '".');
        }
        self::$cache[$id] = $manifest;
        return $manifest;
    }

    /** Parse a manifest from user-supplied YAML (admin upload). */
    public static function fromYaml($yaml)
    {
        $maxBytes = Settings::int('manifest_max_bytes', 262144);
        if (strlen((string) $yaml) > $maxBytes) {
            throw new ValidationException('The manifest is larger than the permitted ' . $maxBytes . ' bytes.');
        }
        return Manifest::fromYaml((string) $yaml, 'uploaded manifest');
    }

    /**
     * The manifest a specific application version was validated with.
     *
     * @throws NotFoundException|StateException when the snapshot was edited after approval
     */
    public static function forVersion($versionId)
    {
        $row = Db::first('application_versions', ['id' => (int) $versionId]);
        if (!$row) {
            throw new NotFoundException('That application version does not exist.');
        }
        if (empty($row['manifest'])) {
            throw new NotFoundException('That version has no manifest snapshot.');
        }
        $manifest = Manifest::fromYaml($row['manifest'], 'version ' . $row['version']);
        if (!empty($row['manifest_hash']) && $manifest->hash() !== $row['manifest_hash']) {
            // The snapshot was edited after validation: refuse rather than deploy
            // something nobody approved.
            throw new StateException(
                'The stored manifest no longer matches its validation hash. Re-validate this version before deploying.',
                ['error_code' => 'MANIFEST_SNAPSHOT_TAMPERED', 'version_id' => (int) $versionId,
                    'expected' => (string) $row['manifest_hash'], 'actual' => $manifest->hash()]
            );
        }
        return $manifest;
    }

    /** The published (or specified) version manifest for an application slug. */
    public static function forApplication($slug, $version = null)
    {
        $application = Db::first('applications', ['slug' => Str::slug((string) $slug, 100), 'deleted_at' => null]);
        if (!$application) {
            throw new NotFoundException('That application is not in the catalog.');
        }
        $where = ['application_id' => (int) $application['id']];
        if ($version !== null && $version !== '') {
            $where['version'] = (string) $version;
        } else {
            $where['status'] = 'published';
        }
        $row = Db::first('application_versions', $where, ['order' => 'is_latest', 'dir' => 'desc']);
        if (!$row) {
            throw new NotFoundException('That application has no published version.');
        }
        return self::forVersion((int) $row['id']);
    }

    /** Cache a parsed manifest (used after an upload is validated). */
    public static function remember(Manifest $manifest)
    {
        self::$cache[$manifest->id()] = $manifest;
        return $manifest;
    }
}
