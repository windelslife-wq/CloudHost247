<?php
/**
 * CloudHost247 Tools - JSON execution endpoint (direct-access alias).
 *
 * The canonical API URL is /tools/api/<slug> (POST, rewritten to front.php).
 * This file serves the same endpoint when the module is addressed directly:
 *
 *   POST .../CloudHost247_tools/api/?tool=<slug>   with a JSON body
 *
 * Dispatch, CSRF, rate limits and execution all live in the shared front
 * controller, so both spellings behave identically.
 */

$ch247ApiEntry = true;

require __DIR__ . '/../front.php';
