<?php
/**
 * WHMCS Tools Center - Hooks
 * Client area integration using WHMCS hooks
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/csrf.php';

/**
 * Add Tools Center to client area navigation
 */
add_hook('ClientAreaPrimaryNavbar', 1, function ($primaryNavbar) {
    $settings = getToolsCenterSettings();
    
    if ($settings['show_in_menu'] !== 'yes') {
        return;
    }
    
    // Check if user is logged in
    $client = Menu::context('client');
    if (!$client) {
        return;
    }
    
    // Check permissions
    if (!tools_center_check_access($client->id, $settings)) {
        return;
    }
    
    $menuName = $settings['module_link_name'] ?? 'Tools Center';
    
    // Add to primary navbar
    $primaryNavbar->addChild($menuName, [
        'uri' => 'index.php?m=tools_center',
        'order' => 80,
        'icon' => 'fa-wrench',
    ]);
});

/**
 * Add Tools Center to client area secondary sidebar
 */
add_hook('ClientAreaSecondarySidebar', 1, function ($secondarySidebar) {
    $currentPage = basename($_SERVER['PHP_SELF']);
    $isToolsPage = isset($_GET['m']) && $_GET['m'] === 'tools_center';
    
    if (!$isToolsPage) {
        return;
    }
    
    $settings = getToolsCenterSettings();
    
    // Add quick links panel
    $panel = $secondarySidebar->addChild('Tools Quick Links');
    $panel->addChild('All Tools', [
        'uri' => 'index.php?m=tools_center',
        'icon' => 'fa-list',
        'order' => 1,
    ]);
    $panel->addChild('DNS Tools', [
        'uri' => 'index.php?m=tools_center&cat=dns',
        'icon' => 'fa-server',
        'order' => 2,
    ]);
    $panel->addChild('IP Tools', [
        'uri' => 'index.php?m=tools_center&cat=ip',
        'icon' => 'fa-globe',
        'order' => 3,
    ]);
    $panel->addChild('Security Tools', [
        'uri' => 'index.php?m=tools_center&cat=security',
        'icon' => 'fa-shield',
        'order' => 4,
    ]);
});

/**
 * Head output for tools page
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    $isToolsPage = isset($_GET['m']) && $_GET['m'] === 'tools_center';
    
    if (!$isToolsPage) {
        return '';
    }
    
    $css = '<link rel="stylesheet" href="modules/addons/tools_center/css/tools-center.css" />';
    $csrfToken = function_exists('tools_center_issue_csrf_token') ? tools_center_issue_csrf_token() : null;
    $clientConfig = [
        'apiUrl' => 'index.php?m=tools_center',
        'csrfToken' => is_string($csrfToken) ? $csrfToken : '',
    ];
    $configJson = json_encode($clientConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $configScript = is_string($configJson)
        ? '<script>window.toolsCenterConfig = ' . $configJson . ';</script>'
        : '';
    $js = '<script src="modules/addons/tools_center/js/tools-center.js"></script>';
    
    return $css . "\n" . $configScript . "\n" . $js;
});

/**
 * Check if client has access to tools
 */
function tools_center_check_access($clientId, $settings = null) {
    if (!$clientId) {
        return false;
    }
    
    if (!$settings) {
        $settings = getToolsCenterSettings();
    }
    
    // Check client groups. A missing WHMCS client row or invalid group setting
    // must not grant access when an allowlist is configured.
    $allowedGroups = $settings['allowed_client_groups'] ?? '';
    if (!empty($allowedGroups)) {
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) $allowedGroups)), function ($id) {
            return $id !== '' && ctype_digit($id);
        }));
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$client || !$allowed) {
            return false;
        }
        $allowed = array_map('strval', array_map('intval', $allowed));
        if (!in_array((string) (int) $client->groupid, $allowed, true)) {
            return false;
        }
    }
    
    // Check active product requirement
    if (($settings['require_active_product'] ?? 'no') === 'yes') {
        $activeProducts = Capsule::table('tblhosting')
            ->where('userid', $clientId)
            ->whereIn('domainstatus', ['Active', 'Completed'])
            ->count();
        
        if ($activeProducts == 0) {
            return false;
        }
    }
    
    return true;
}

/**
 * Get module settings
 */
function getToolsCenterSettings() {
    $settings = [];
    
    try {
        $result = Capsule::table('tbladdonmodules')
            ->where('module', 'tools_center')
            ->get();
        
        foreach ($result as $row) {
            $settings[$row->setting] = $row->value;
        }
    } catch (Exception $e) {
        // Fallback defaults
    }
    
    // Set defaults
    $defaults = [
        'api_endpoint' => '',
        'api_token' => '',
        'require_active_product' => 'no',
        'allowed_client_groups' => '',
        'show_in_menu' => 'yes',
        'module_link_name' => 'Tools Center',
    ];
    
    return array_merge($defaults, $settings);
}

/**
 * Log tool usage
 */
function tools_center_log_usage($clientId, $category, $action, $status, $responseTime = 0) {
    try {
        // Log individual request
        Capsule::table('mod_tools_center_logs')->insert([
            'client_id' => $clientId,
            'tool_category' => $category,
            'tool_action' => $action,
            // Retain the legacy column without fingerprinting sensitive user input.
            'input_hash' => hash('sha256', 'tools-center:' . $category . ':' . $action),
            'status' => $status,
            'response_time_ms' => $responseTime,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Update daily usage
        $today = date('Y-m-d');
        $exists = Capsule::table('mod_tools_center_usage')
            ->where('client_id', $clientId)
            ->where('date', $today)
            ->first();
        
        if ($exists) {
            Capsule::table('mod_tools_center_usage')
                ->where('id', $exists->id)
                ->update([
                    'requests_count' => $exists->requests_count + 1,
                    'tools_used' => $exists->tools_used . ',' . $category . '.' . $action,
                ]);
        } else {
            Capsule::table('mod_tools_center_usage')->insert([
                'client_id' => $clientId,
                'date' => $today,
                'requests_count' => 1,
                'tools_used' => $category . '.' . $action,
            ]);
        }
    } catch (Exception $e) {
        // Silently fail - don't break user experience
    }
}

/**
 * API request helper
 */
function tools_center_api_request($category, $action, $params = [], $settings = null) {
    if (!$settings) {
        $settings = getToolsCenterSettings();
    }
    
    $endpoint = $settings['api_endpoint'] ?? '';
    $token = $settings['api_token'] ?? '';
    
    if (empty($endpoint) || empty($token)) {
        return [
            'success' => false,
            'error' => 'API not configured. Please contact support.',
        ];
    }
    
    $url = trim($endpoint);
    $urlParts = parse_url($url);
    if (!is_array($urlParts) || strtolower($urlParts['scheme'] ?? '') !== 'https'
        || empty($urlParts['host']) || isset($urlParts['user']) || isset($urlParts['pass'])) {
        return [
            'success' => false,
            'error' => 'API endpoint must be a valid HTTPS URL.',
        ];
    }

    if (!is_array($params)) {
        return [
            'success' => false,
            'error' => 'Invalid tool parameters.',
        ];
    }
    // These fields are controlled by the module, not by browser form values.
    unset($params['api_token'], $params['category'], $params['action'], $params['token'], $params['csrf_token']);
    $postData = array_merge($params, [
        'category' => $category,
        'action' => $action,
    ]);
    $encodedBody = json_encode($postData, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encodedBody)) {
        return [
            'success' => false,
            'error' => 'Invalid tool parameters.',
        ];
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encodedBody,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Token: ' . $token,
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        // Redirects are not followed: the API token is sent in a header and must not be forwarded.
        // HTTPS only (the README requires HTTPS for all API traffic).
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $responseTime = round((microtime(true) - $startTime) * 1000);
    
    if ($curlError) {
        return [
            'success' => false,
            'error' => 'Connection error: ' . $curlError,
            'response_time_ms' => $responseTime,
        ];
    }
    
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => 'API returned HTTP ' . $httpCode,
            'response_time_ms' => $responseTime,
        ];
    }
    
    $data = json_decode($response, true);
    
    if ($data === null) {
        return [
            'success' => false,
            'error' => 'Invalid API response',
            'response_time_ms' => $responseTime,
        ];
    }
    
    $data['response_time_ms'] = $responseTime;
    return $data;
}