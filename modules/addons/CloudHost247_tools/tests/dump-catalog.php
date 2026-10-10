<?php
/**
 * CloudHost247 Tools - registry dump for the JavaScript suites.
 *
 * Emits the authoritative tool registry as a JSON array so tests/tools.test.mjs
 * can check each route-split browser module against the same source of truth
 * the PHP side uses. Admin overrides are not available offline, so the output
 * is the shipped registry defaults.
 *
 *   php tests/dump-catalog.php > /tmp/tools.json
 */
define('CLOUDHOST247_TOOLS', true);

require __DIR__ . '/../includes/Catalog.php';

$tools = CloudHost247ToolsCatalog::tools(false);

echo json_encode(array_values($tools), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
