<?php
/** Loads the Cloudflare module's WHMCS hooks from the standard hook location. */
if (!defined('WHMCS')) die('This file cannot be accessed directly');
$cloudflareHooks = dirname(__DIR__, 2) . '/modules/addons/cloudhost247cloudflare/hooks.php';
if (is_file($cloudflareHooks)) require_once $cloudflareHooks;
