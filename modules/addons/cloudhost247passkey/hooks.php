<?php
/**
 * Native WHMCS hooks for CloudHost247 Passkey.
 *
 * Hooks stay lightweight and never break the surrounding page: every handler
 * fails silent (login/theme/cron flows continue on password auth) while the
 * JSON boundary itself fails closed.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use CloudHost247\Passkey\Core\Db;
use CloudHost247\Passkey\Core\PasskeyMaintenanceService;
use CloudHost247\Passkey\Core\SettingsRepository;
use CloudHost247\Passkey\Http\CsrfProtection;

function ch247pk_hook_settings()
{
    static $cached;
    if ($cached !== null) {
        return $cached;
    }
    $cached = [];
    try {
        if (!Db::tableExists('settings')) {
            return $cached;
        }
        $cached = (new SettingsRepository())->values();
    } catch (\Throwable $error) {
        $cached = [];
    }
    return $cached;
}

function ch247pk_hook_enabled($audience)
{
    $settings = ch247pk_hook_settings();
    if (!isset($settings['service_enabled']) || $settings['service_enabled'] !== '1') {
        return false;
    }
    if (empty($settings['rp_id']) || !isset($settings['allowed_origins']) || $settings['allowed_origins'] === '[]') {
        return false;
    }
    $key = $audience === 'admin' ? 'admin_policy' : 'client_policy';
    return isset($settings[$key]) && in_array($settings[$key], ['optional', 'required'], true);
}

function ch247pk_base_url($vars)
{
    if (is_array($vars) && isset($vars['WEB_ROOT']) && is_string($vars['WEB_ROOT']) && $vars['WEB_ROOT'] !== '') {
        return rtrim($vars['WEB_ROOT'], '/');
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    // Admin scripts live one level below the WHMCS root.
    if (substr($dir, -6) === '/admin') {
        $dir = substr($dir, 0, -6);
    }
    $dir = rtrim($dir, '/');
    return $dir === '/' ? '' : $dir;
}

function ch247pk_asset_tags($vars, $userType)
{
    $base = ch247pk_base_url($vars);
    $root = $base . '/modules/addons/cloudhost247passkey/assets';
    $csrf = '';
    try {
        $csrf = CsrfProtection::token();
    } catch (\Throwable $error) {
        $csrf = '';
    }
    $config = [
        'ajaxUrl' => $base . '/index.php?m=cloudhost247passkey',
        'userType' => $userType,
        'csrfToken' => $csrf,
        'csrfField' => CsrfProtection::FIELD,
    ];
    return '<link rel="stylesheet" href="' . htmlspecialchars($root . '/css/passkey.css', ENT_QUOTES, 'UTF-8') . '">' . "\n"
        . '<script>window.CH247PK = ' . json_encode($config, JSON_UNESCAPED_SLASHES) . ';</script>' . "\n"
        . '<script src="' . htmlspecialchars($root . '/js/passkey.js', ENT_QUOTES, 'UTF-8') . '" defer></script>';
}

function ch247pk_is_login_page($vars)
{
    $filename = is_array($vars) && isset($vars['filename']) ? (string) $vars['filename'] : '';
    if (in_array($filename, ['login', 'clientarea'], true)) {
        return true;
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? strtolower((string) $_SERVER['SCRIPT_NAME']) : '';
    return substr($script, -10) === '/login.php' || substr($script, -9) === 'login.php';
}

if (!function_exists('add_hook')) {
    return;
}

// Client login + Passkeys pages: assets and boot configuration.
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    try {
        $onModule = (isset($_GET['m']) && $_GET['m'] === 'cloudhost247passkey');
        $onLogin = ch247pk_is_login_page($vars);
        if (!$onModule && !$onLogin) {
            return '';
        }
        if (!ch247pk_hook_enabled('client')) {
            return '';
        }
        return ch247pk_asset_tags($vars, 'client');
    } catch (\Throwable $error) {
        return '';
    }
});

// Client login page: inject a theme-agnostic "Sign in with Passkey" button.
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        if (!ch247pk_is_login_page($vars) || !ch247pk_hook_enabled('client')) {
            return '';
        }
        if ((int) ($_SESSION['uid'] ?? 0) > 0) {
            return '';
        }
        return '<script>(function () {'
            . 'if (document.readyState === "loading") {'
            . 'document.addEventListener("DOMContentLoaded", function () {'
            . 'if (window.CH247PKLogin) { window.CH247PKLogin.mountLoginButton(); }'
            . '});} else if (window.CH247PKLogin) { window.CH247PKLogin.mountLoginButton(); }'
            . '})();</script>';
    } catch (\Throwable $error) {
        return '';
    }
});

// Client navigation: Passkeys entry for authenticated clients.
add_hook('ClientAreaPrimaryNavbar', 1, function ($navbar) {
    try {
        if ((int) ($_SESSION['uid'] ?? 0) < 1 || !is_object($navbar) || !ch247pk_hook_enabled('client')) {
            return;
        }
        $item = $navbar->addChild('Passkeys', [
            'label' => 'Passkeys',
            'uri' => 'index.php?m=cloudhost247passkey',
            'order' => 71,
        ]);
        if (is_object($item) && method_exists($item, 'setIcon')) {
            $item->setIcon('fa-key');
        }
    } catch (\Throwable $error) {
        // Navigation stays on the default WHMCS menu.
    }
});

add_hook('ClientAreaPrimarySidebar', 1, function ($sidebar) {
    try {
        if ((int) ($_SESSION['uid'] ?? 0) < 1 || !is_object($sidebar) || !ch247pk_hook_enabled('client')) {
            return;
        }
        $sidebar->addChild('CloudHost247Passkeys', [
            'label' => 'Passkeys',
            'uri' => 'index.php?m=cloudhost247passkey',
            'icon' => 'fa-key',
            'order' => 71,
        ]);
    } catch (\Throwable $error) {
        // Navigation stays on the default WHMCS menu.
    }
});

// Admin login page: assets for administrator Passkey sign-in.
add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    try {
        $filename = is_array($vars) && isset($vars['filename']) ? (string) $vars['filename'] : '';
        $onAdminLogin = ($filename === 'login');
        $onModule = (isset($_GET['module']) && $_GET['module'] === 'cloudhost247passkey');
        if (!$onAdminLogin && !$onModule) {
            return '';
        }
        if (!ch247pk_hook_enabled('admin')) {
            return $onModule ? '' : '';
        }
        return ch247pk_asset_tags(is_array($vars) ? $vars : [], 'admin');
    } catch (\Throwable $error) {
        return '';
    }
});

add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    try {
        $filename = is_array($vars) && isset($vars['filename']) ? (string) $vars['filename'] : '';
        if ($filename !== 'login' || !ch247pk_hook_enabled('admin')) {
            return '';
        }
        if ((int) ($_SESSION['adminid'] ?? 0) > 0) {
            return '';
        }
        return '<script>(function () {'
            . 'function mount() { if (window.CH247PKLogin) { window.CH247PKLogin.mountAdminLoginButton(); } }'
            . 'if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", mount); }'
            . 'else { mount(); }'
            . '})();</script>';
    } catch (\Throwable $error) {
        return '';
    }
});

// Bounded daily retention cleanup for expired ephemeral Passkey state.
add_hook('DailyCronJob', 1, function () {
    try {
        if (!Db::tableExists('settings')) {
            return;
        }
        $result = (new PasskeyMaintenanceService())->run(time(), 500);
        if (function_exists('logActivity') && $result['total_deleted'] > 0) {
            logActivity('CloudHost247 Passkey maintenance purged ' . (int) $result['total_deleted'] . ' expired row(s).');
        }
    } catch (\Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 Passkey maintenance skipped: ' . get_class($error) . '.');
        }
    }
});
