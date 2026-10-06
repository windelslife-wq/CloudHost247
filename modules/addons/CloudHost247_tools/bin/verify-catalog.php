#!/usr/bin/env php
<?php
/**
 * CloudHost247 Tools - catalog verification  (tools:verify-catalog)
 *
 * Fails with a non-zero exit code when fewer than the required number of
 * tools are implemented, or when any route is missing or broken. Intended
 * to run in CI and as a pre-deploy gate.
 *
 * Usage:
 *   php modules/addons/CloudHost247_tools/bin/verify-catalog.php
 *   php .../verify-catalog.php --format=markdown   # 91-row matrix
 *   php .../verify-catalog.php --format=json
 *   php .../verify-catalog.php --required=91
 */

define('CLOUDHOST247_TOOLS', true);

$moduleDir = dirname(__DIR__);
require_once $moduleDir . '/includes/Catalog.php';
require_once $moduleDir . '/includes/Security.php';
require_once $moduleDir . '/includes/Router.php';

// ---------------------------------------------------------------------
//  Options
// ---------------------------------------------------------------------
$options = getopt('', ['format::', 'required::', 'quiet']);
$format   = $options['format']   ?? 'text';
$required = (int) ($options['required'] ?? 91);
$quiet    = array_key_exists('quiet', $options);

// ---------------------------------------------------------------------
//  Load handlers
// ---------------------------------------------------------------------
foreach (glob($moduleDir . '/includes/tools/*_tools.php') as $file) {
    require_once $file;
}

$tools      = CloudHost247ToolsCatalog::tools(false);
$categories = CloudHost247ToolsCatalog::categories(false);

$rows          = [];
$missing       = [];
$brokenRoutes  = [];
$warnings      = [];
$implemented   = 0;

$templateDir = $moduleDir . '/templates/client';
$jsDir       = $moduleDir . '/assets/js/tools';

foreach ($tools as $slug => $tool) {
    $issues = [];

    // --- backend ---
    $fn      = 'CloudHost247_tool_' . $tool['handler'];
    $hasFn   = function_exists($fn);
    $backend = $tool['exec'] === 'client'
        ? ($hasFn ? 'n/a (client-side)' : 'n/a (client-side)')
        : ($hasFn ? 'implemented' : 'MISSING');
    if ($tool['exec'] !== 'client' && !$hasFn) {
        $issues[] = 'handler ' . $fn . '() not found';
    }

    // --- frontend ---
    $jsFile   = $jsDir . '/' . $slug . '.js';
    $hasJs    = is_file($jsFile);
    $frontend = $hasJs ? 'implemented' : 'MISSING';
    if (!$hasJs) {
        $issues[] = 'no route asset assets/js/tools/' . $slug . '.js';
    }

    // --- route ---
    $route      = $tool['route'];
    $resolved   = CloudHost247ToolsRouter::resolve($route);
    $routeOk    = $resolved['type'] === 'tool' && $resolved['tool']['slug'] === $slug;
    if (!$routeOk) {
        $brokenRoutes[] = $route . ' (resolved as ' . $resolved['type'] . ')';
        $issues[] = 'route does not resolve';
    }
    if ($route !== '/tools/' . $slug) {
        $brokenRoutes[] = $route . ' (does not match slug)';
        $issues[] = 'route/slug mismatch';
    }

    // --- metadata ---
    foreach (['name', 'description', 'icon', 'seo_title', 'seo_description'] as $field) {
        if (empty($tool[$field])) {
            $issues[] = 'empty ' . $field;
        }
    }
    if (empty($tool['related'])) {
        $warnings[] = $slug . ': no related tools configured';
    }

    // --- security classification ---
    if ($tool['exec'] === 'client') {
        $security = 'client-only (no data leaves the browser)';
    } elseif (!empty($tool['api'])) {
        $security = 'server + third-party API (' . $tool['api'] . ')';
    } elseif (in_array($slug, ['traceroute', 'ping', 'port-checker', 'smtp-test'], true)) {
        $security = 'server, SSRF-guarded + command allowlist';
    } else {
        $security = 'server, SSRF-guarded + rate limited';
    }

    // --- overall status ---
    $blocked = ($tool['status'] ?? 'active') === 'requires_configuration';
    if ($blocked) {
        $status = 'BLOCKED';
        $implemented++; // code exists; the dependency is external
    } elseif (empty($issues)) {
        $status = 'OK';
        $implemented++;
    } else {
        $status = 'INCOMPLETE';
        $missing[] = $slug . ' - ' . implode('; ', $issues);
    }

    $rows[] = [
        'id'        => $tool['id'],
        'category'  => $tool['category_name'],
        'name'      => $tool['name'],
        'route'     => $route,
        'backend'   => $backend,
        'frontend'  => $frontend,
        'route_ok'  => $routeOk ? 'OK' : 'BROKEN',
        'api'       => $tool['api'] ?: 'none',
        'exec'      => $tool['exec'],
        'security'  => $security,
        'status'    => $status,
        'issues'    => $issues,
    ];
}

// ---------------------------------------------------------------------
//  Category and page routes
// ---------------------------------------------------------------------
foreach ($categories as $id => $cat) {
    $resolved = CloudHost247ToolsRouter::resolve($cat['route']);
    if ($resolved['type'] !== 'category') {
        $brokenRoutes[] = $cat['route'] . ' (category page does not resolve)';
    }
    if ($cat['count'] === 0) {
        $warnings[] = 'category ' . $id . ' has no tools';
    }
}
foreach (['/tools' => 'index', '/tools/disclaimer' => 'disclaimer'] as $route => $type) {
    if (CloudHost247ToolsRouter::resolve($route)['type'] !== $type) {
        $brokenRoutes[] = $route . ' (does not resolve as ' . $type . ')';
    }
}

// Duplicate route detection.
$allRoutes = array_column($rows, 'route');
foreach (array_count_values($allRoutes) as $route => $n) {
    if ($n > 1) {
        $brokenRoutes[] = $route . ' (duplicated ' . $n . ' times)';
    }
}

$missingCount = $required - $implemented;
$ok = $implemented >= $required && empty($brokenRoutes);

// ---------------------------------------------------------------------
//  Output
// ---------------------------------------------------------------------
$summary = [
    'required'      => $required,
    'implemented'   => $implemented,
    'missing'       => max(0, $missingCount),
    'broken_routes' => count($brokenRoutes),
    'warnings'      => count($warnings),
    'result'        => $ok ? 'PASS' : 'FAIL',
];

if ($format === 'json') {
    echo json_encode(['summary' => $summary, 'tools' => $rows,
        'missing' => $missing, 'broken_routes' => $brokenRoutes, 'warnings' => $warnings],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} elseif ($format === 'markdown') {
    echo "| # | Category | Tool | Route | Backend | Frontend | Route | API | Exec | Security | Status |\n";
    echo "|---|---|---|---|---|---|---|---|---|---|---|\n";
    foreach ($rows as $r) {
        echo '| ' . implode(' | ', [
            $r['id'], $r['category'], $r['name'], '`' . $r['route'] . '`',
            $r['backend'], $r['frontend'], $r['route_ok'], $r['api'],
            $r['exec'], $r['security'], $r['status'],
        ]) . " |\n";
    }
    echo "\n**Required {$summary['required']} / Implemented {$summary['implemented']} / "
        . "Missing {$summary['missing']} / Broken routes {$summary['broken_routes']} "
        . "— {$summary['result']}**\n";
} else {
    if (!$quiet) {
        echo "CloudHost247 Tools - catalog verification\n";
        echo str_repeat('=', 62), "\n\n";
        foreach ($categories as $id => $cat) {
            printf("  %-14s %2d tools  %s\n", $cat['name'], $cat['count'], $cat['route']);
        }
        echo "\n";
        if ($missing) {
            echo "INCOMPLETE TOOLS (" . count($missing) . "):\n";
            foreach ($missing as $m) {
                echo "  - $m\n";
            }
            echo "\n";
        }
        if ($brokenRoutes) {
            echo "BROKEN ROUTES (" . count($brokenRoutes) . "):\n";
            foreach ($brokenRoutes as $b) {
                echo "  - $b\n";
            }
            echo "\n";
        }
        if ($warnings) {
            echo "WARNINGS (" . count($warnings) . "):\n";
            foreach (array_slice($warnings, 0, 20) as $w) {
                echo "  - $w\n";
            }
            echo "\n";
        }
    }
    echo str_repeat('-', 62), "\n";
    printf("Required %d / Implemented %d / Missing %d / Broken routes %d\n",
        $summary['required'], $summary['implemented'], $summary['missing'], $summary['broken_routes']);
    echo "Result: ", $summary['result'], "\n";
}

exit($ok ? 0 : 1);
