<?php
/** Local dependency inventory from Composer/npm lock files; no network or CVE claims. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Tools\ToolDefinition;

function ch247ai_parse_composer_lock(array $document, $module, $path)
{
    $out = [];
    foreach (['packages', 'packages-dev'] as $bucket) {
        if (empty($document[$bucket]) || !is_array($document[$bucket])) {
            continue;
        }
        foreach ($document[$bucket] as $package) {
            if (!is_array($package) || empty($package['name']) || empty($package['version'])) {
                continue;
            }
            $out[] = [
                'module' => (string) $module, 'ecosystem' => 'composer',
                'name' => (string) $package['name'], 'version' => (string) $package['version'],
                'development' => $bucket === 'packages-dev', 'lockfile' => (string) $path,
            ];
        }
    }
    return $out;
}

function ch247ai_parse_npm_lock(array $document, $module, $path)
{
    $out = [];
    if (isset($document['packages']) && is_array($document['packages'])) {
        foreach ($document['packages'] as $packagePath => $package) {
            if ($packagePath === '' || !is_array($package) || empty($package['version'])) {
                continue;
            }
            $name = isset($package['name']) ? (string) $package['name'] : preg_replace('#^node_modules/#', '', (string) $packagePath);
            $out[] = [
                'module' => (string) $module, 'ecosystem' => 'npm',
                'name' => $name, 'version' => (string) $package['version'],
                'development' => !empty($package['dev']), 'lockfile' => (string) $path,
            ];
        }
        return $out;
    }
    $walk = function (array $dependencies) use (&$walk, &$out, $module, $path) {
        foreach ($dependencies as $name => $package) {
            if (!is_array($package)) {
                continue;
            }
            if (!empty($package['version'])) {
                $out[] = [
                    'module' => (string) $module, 'ecosystem' => 'npm',
                    'name' => (string) $name, 'version' => (string) $package['version'],
                    'development' => !empty($package['dev']), 'lockfile' => (string) $path,
                ];
            }
            if (!empty($package['dependencies']) && is_array($package['dependencies'])) {
                $walk($package['dependencies']);
            }
        }
    };
    if (!empty($document['dependencies']) && is_array($document['dependencies'])) {
        $walk($document['dependencies']);
    }
    return $out;
}

/** Traverse only modules/, skipping vendor trees and symlinks; parse lockfiles, never manifests. */
function ch247ai_dependency_inventory(array $args = [])
{
    if (!defined('CH247AI_ROOT')) {
        throw new \Ch247Ai\Core\ServiceUnavailableException('DATA_UNAVAILABLE: module root is not configured.');
    }
    $root = realpath(CH247AI_ROOT . '/modules');
    if ($root === false || !is_dir($root)) {
        throw new \Ch247Ai\Core\ServiceUnavailableException('DATA_UNAVAILABLE: modules directory is not present.');
    }
    $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 200;
    $packages = [];
    $sources = [];
    $skipped = [];
    $filesScanned = 0;
    $maxFiles = 200;
    $maxBytes = 2097152;
    $walk = function ($directory, $depth) use (&$walk, &$packages, &$sources, &$skipped, &$filesScanned, $maxFiles, $maxBytes, $root) {
        if ($depth > 8 || is_link($directory)) {
            return;
        }
        $entries = @scandir($directory);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, ['vendor', 'node_modules', '.git', 'dist', 'build'], true)) {
                continue;
            }
            $full = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $walk($full, $depth + 1);
                continue;
            }
            if (!in_array($entry, ['composer.lock', 'package-lock.json'], true)) {
                continue;
            }
            if (++$filesScanned > $maxFiles) {
                $skipped[] = 'file scan limit reached';
                return;
            }
            $real = realpath($full);
            if ($real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
            $module = dirname($relative);
            $size = @filesize($real);
            if ($size === false || $size > $maxBytes) {
                $skipped[] = $relative . ': size limit';
                continue;
            }
            $contents = @file_get_contents($real);
            $document = is_string($contents) ? json_decode($contents, true) : null;
            if (!is_array($document)) {
                $skipped[] = $relative . ': invalid JSON';
                continue;
            }
            $sources[] = $relative;
            $found = $entry === 'composer.lock'
                ? ch247ai_parse_composer_lock($document, $module, $relative)
                : ch247ai_parse_npm_lock($document, $module, $relative);
            foreach ($found as $package) {
                $packages[] = $package;
            }
        }
    };
    $walk($root, 0);
    usort($packages, function ($a, $b) {
        return strcmp($a['module'] . '/' . $a['ecosystem'] . '/' . $a['name'] . '/' . $a['version'],
            $b['module'] . '/' . $b['ecosystem'] . '/' . $b['name'] . '/' . $b['version']);
    });
    $total = count($packages);
    $packages = array_slice($packages, 0, $limit);
    return [
        'packages' => $packages,
        'package_count' => $total,
        'returned_count' => count($packages),
        'truncated' => $total > $limit,
        'lockfiles_scanned' => $sources,
        'lockfiles_skipped' => $skipped,
        'limitations' => 'This inventories declared versions in local Composer/npm lock files only. It does not prove packages are installed, scan operating-system/server software, query vulnerability advisories, or establish that any version is vulnerable or safe.',
        '_citations' => array_map(function ($path) { return 'Local lockfile: ' . $path; }, $sources),
    ];
}

function ch247ai_dependency_inventory_tool()
{
    return new ToolDefinition(
        'read_dependency_inventory',
        'read.metrics',
        'READ',
        'Inventory package names and declared versions in local Composer/npm lock files. This is not a CVE scan or proof of deployed package versions.',
        [
            'limit' => ['type' => 'integer', 'description' => 'Maximum package records to return (1-500)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            return ch247ai_dependency_inventory($args);
        },
        ['entity' => 'local_dependency_lockfiles']
    );
}
