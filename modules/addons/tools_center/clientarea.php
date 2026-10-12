<?php
/**
 * WHMCS Tools Center - Client Area Page
 * Main entry point for client area
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\ClientArea;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/csrf.php';

$clientId = $_SESSION['uid'] ?? 0;

// Check access
if (!$clientId || !tools_center_check_access($clientId)) {
    // Redirect to login or show error
    header('Location: ' . $CONFIG['SystemURL'] . '/clientarea.php');
    exit;
}

// Get module settings
$settings = getToolsCenterSettings();

// Keep raw route query values separate until they have been matched against
// the static category/action registry below. The templates interpolate these
// selected values in links and inline JavaScript.
$requestedCategory = $_GET['cat'] ?? 'all';
$requestedTool = $_GET['tool'] ?? '';
$category = 'all';
$toolAction = '';

// Tool categories definition
$toolCategories = [
    'search' => [
        'name' => 'Search',
        'icon' => 'fa-search',
        'description' => 'Domain availability and search tools',
        'tools' => [
            ['action' => 'domainSearch', 'name' => 'Domain Name Search', 'desc' => 'Preliminary registry lookup; inconclusive results stay unknown'],
        ]
    ],
    'dns' => [
        'name' => 'DNS',
        'icon' => 'fa-server',
        'description' => 'Comprehensive DNS analysis and validation tools',
        'tools' => [
            ['action' => 'spfChecker', 'name' => 'SPF Record Checker', 'desc' => 'Verify SPF email authentication'],
            ['action' => 'dnsValidation', 'name' => 'Domain DNS Validation', 'desc' => 'Complete DNS health check'],
            ['action' => 'reverseIpLookup', 'name' => 'Reverse IP Lookup', 'desc' => 'Find hostname from IP'],
            ['action' => 'dnsLookup', 'name' => 'DNS Lookup', 'desc' => 'Query any DNS record type'],
            ['action' => 'cnameLookup', 'name' => 'CNAME Lookup', 'desc' => 'Trace canonical names'],
            ['action' => 'nsLookup', 'name' => 'NS Lookup', 'desc' => 'Find nameservers'],
            ['action' => 'mxLookup', 'name' => 'MX Lookup', 'desc' => 'Mail server records'],
            ['action' => 'dnsPropagation', 'name' => 'DNS Propagation', 'desc' => 'Check global DNS propagation'],
            ['action' => 'dmarcValidation', 'name' => 'DMARC Validation', 'desc' => 'Verify DMARC policy'],
            ['action' => 'dnsHealth', 'name' => 'DNS Health', 'desc' => 'Overall DNS health score'],
            ['action' => 'dmarcGenerator', 'name' => 'DMARC Generator', 'desc' => 'Create DMARC records'],
            ['action' => 'dnskeyLookup', 'name' => 'DNSKEY Lookup', 'desc' => 'DNSSEC public keys'],
            ['action' => 'dsLookup', 'name' => 'DS Lookup', 'desc' => 'Delegation signer records'],
            ['action' => 'dkimChecker', 'name' => 'DKIM Checker', 'desc' => 'Verify email signing'],
        ]
    ],
    'ip' => [
        'name' => 'IP',
        'icon' => 'fa-globe',
        'description' => 'IP analysis, geolocation, and conversion tools',
        'tools' => [
            ['action' => 'ping', 'name' => 'Ping', 'desc' => 'IPv4/IPv6 ping test'],
            ['action' => 'whatIsMyIP', 'name' => 'What is My IP', 'desc' => 'Show your public IP details'],
            ['action' => 'traceroute', 'name' => 'Traceroute', 'desc' => 'Trace network path'],
            ['action' => 'ipLocation', 'name' => 'IP Location', 'desc' => 'Geolocation lookup'],
            ['action' => 'emailHeaderAnalyzer', 'name' => 'Email Header Analyzer', 'desc' => 'Analyze email security'],
            ['action' => 'ipBlacklist', 'name' => 'IP Blacklist', 'desc' => 'Check IP reputation'],
            ['action' => 'ipToDecimal', 'name' => 'IP to Decimal', 'desc' => 'Convert IP formats'],
            ['action' => 'resolveIPtoHostname', 'name' => 'Resolve IP to Hostname', 'desc' => 'Reverse DNS lookup'],
            ['action' => 'ipWhois', 'name' => 'IP WHOIS', 'desc' => 'IP ownership lookup'],
            ['action' => 'ipv6Whois', 'name' => 'IPv6 WHOIS', 'desc' => 'IPv6 ownership lookup'],
            ['action' => 'ipv4ToIPv6', 'name' => 'IPv4 to IPv6', 'desc' => 'Convert IPv4 to IPv6'],
            ['action' => 'localIPv6Generator', 'name' => 'Local IPv6 Generator', 'desc' => 'Generate IPv6 addresses'],
            ['action' => 'ipv6CIDRtoRange', 'name' => 'IPv6 CIDR to Range', 'desc' => 'Expand IPv6 CIDR'],
            ['action' => 'ipv6RangeToCIDR', 'name' => 'IPv6 Range to CIDR', 'desc' => 'Compress IPv6 range'],
            ['action' => 'ipv6Compatibility', 'name' => 'IPv6 Compatibility', 'desc' => 'Check IPv6 readiness'],
            ['action' => 'ipv6Compression', 'name' => 'IPv6 Compression', 'desc' => 'Compress IPv6 address'],
            ['action' => 'ipv6Expand', 'name' => 'IPv6 Expand', 'desc' => 'Expand IPv6 address'],
            ['action' => 'subnetCalculator', 'name' => 'Subnet Calculator', 'desc' => 'Calculate subnets'],
            ['action' => 'ipv6ToIPv4', 'name' => 'IPv6 to IPv4', 'desc' => 'Extract IPv4 from IPv6'],
            ['action' => 'ispChecker', 'name' => 'ISP Checker', 'desc' => 'Identify ISP details'],
            ['action' => 'websiteToIP', 'name' => 'Website to IP', 'desc' => 'Resolve domain to IP'],
        ]
    ],
    'developer' => [
        'name' => 'Developer',
        'icon' => 'fa-code',
        'description' => 'Web development and debugging tools',
        'tools' => [
            ['action' => 'httpHeadersCheck', 'name' => 'HTTP Headers Check', 'desc' => 'Analyze response headers'],
            ['action' => 'websiteOSChecker', 'name' => 'Website OS Checker', 'desc' => 'Detect server OS'],
            ['action' => 'hashGenerator', 'name' => 'MD5 & Base64 Generator', 'desc' => 'Hash and encode text'],
            ['action' => 'multiUrlOpener', 'name' => 'Multi URL Opener', 'desc' => 'Open multiple URLs'],
            ['action' => 'smtpTest', 'name' => 'SMTP Test', 'desc' => 'Test mail server connectivity'],
            ['action' => 'htaccessRedirectGenerator', 'name' => 'htaccess Redirect', 'desc' => 'Generate redirects'],
            ['action' => 'urlRewriteGenerator', 'name' => 'URL Rewrite', 'desc' => 'Generate rewrite rules'],
            ['action' => 'brokenLinksChecker', 'name' => 'Broken Links', 'desc' => 'Find broken links'],
            ['action' => 'openGraphGenerator', 'name' => 'Open Graph Generator', 'desc' => 'Generate OG tags'],
            ['action' => 'raidCalculator', 'name' => 'RAID Calculator', 'desc' => 'Calculate RAID storage'],
            ['action' => 'binaryTextConverter', 'name' => 'Binary/Text Converter', 'desc' => 'Convert binary/text'],
            ['action' => 'jsonTool', 'name' => 'JSON Tool', 'desc' => 'Format/validate/minify JSON'],
        ]
    ],
    'designer' => [
        'name' => 'Designer',
        'icon' => 'fa-paint-brush',
        'description' => 'Color conversion and design tools',
        'tools' => [
            ['action' => 'rgbToPantone', 'name' => 'RGB to Pantone', 'desc' => 'Convert RGB to Pantone'],
            ['action' => 'hexToPantone', 'name' => 'HEX to Pantone', 'desc' => 'Convert HEX to Pantone'],
            ['action' => 'cmykToPantone', 'name' => 'CMYK to Pantone', 'desc' => 'Convert CMYK to Pantone'],
            ['action' => 'hsvToPantone', 'name' => 'HSV to Pantone', 'desc' => 'Convert HSV to Pantone'],
        ]
    ],
    'webmaster' => [
        'name' => 'Webmaster',
        'icon' => 'fa-sitemap',
        'description' => 'SEO and website analysis tools',
        'tools' => [
            ['action' => 'websiteLinkAnalyzer', 'name' => 'Website Link Analyzer', 'desc' => 'Analyze page links'],
            ['action' => 'userAgentChecker', 'name' => 'User Agent Checker', 'desc' => 'Parse browser info'],
            ['action' => 'pageRankChecker', 'name' => 'PageRank Data (Unavailable)', 'desc' => 'No provider configured; returns no PageRank or alternative score.'],
            ['action' => 'punycodeConverter', 'name' => 'Punycode Converter', 'desc' => 'IDN domain conversion'],
            ['action' => 'serpSimulator', 'name' => 'SERP Simulator', 'desc' => 'Preview search results'],
            ['action' => 'robotsGenerator', 'name' => 'Robots.txt Generator', 'desc' => 'Generate robots.txt'],
        ]
    ],
    'network' => [
        'name' => 'Network',
        'icon' => 'fa-network-wired',
        'description' => 'Network analysis and lookup tools',
        'tools' => [
            ['action' => 'portChecker', 'name' => 'Port Checker', 'desc' => 'Check open ports'],
            ['action' => 'macLookup', 'name' => 'MAC Lookup', 'desc' => 'Find MAC vendor'],
            ['action' => 'macGenerator', 'name' => 'MAC Generator', 'desc' => 'Generate MAC addresses'],
            ['action' => 'asnWhois', 'name' => 'ASN WHOIS', 'desc' => 'AS number lookup'],
        ]
    ],
    'security' => [
        'name' => 'Security',
        'icon' => 'fa-shield-alt',
        'description' => 'SSL, password, and encryption tools',
        'tools' => [
            ['action' => 'sslChecker', 'name' => 'SSL Checker', 'desc' => 'Check SSL certificate'],
            ['action' => 'passwordEncrypt', 'name' => 'Password Encrypt', 'desc' => 'Hash passwords securely'],
            ['action' => 'passwordGenerator', 'name' => 'Password Generator', 'desc' => 'Generate strong passwords'],
            ['action' => 'passwordStrength', 'name' => 'Password Strength', 'desc' => 'Check password security'],
        ]
    ],
    'productivity' => [
        'name' => 'Productivity',
        'icon' => 'fa-bolt',
        'description' => 'Generators, calculators, and utilities',
        'tools' => [
            ['action' => 'loremIpsum', 'name' => 'Lorem Ipsum', 'desc' => 'Generate placeholder text'],
            ['action' => 'timeCard', 'name' => 'Time Card', 'desc' => 'Calculate work hours'],
            ['action' => 'binChecker', 'name' => 'BIN Checker', 'desc' => 'Bank identification lookup'],
            ['action' => 'creditCardValidator', 'name' => 'CC Validator', 'desc' => 'Validate card numbers'],
            ['action' => 'reverseImageSearch', 'name' => 'Reverse Image', 'desc' => 'Find image sources'],
            ['action' => 'usernameChecker', 'name' => 'Username Format & Profile Links', 'desc' => 'Validate a common format and build profile links; availability is not checked'],
            ['action' => 'notepad', 'name' => 'Notepad', 'desc' => 'Online scratchpad'],
            ['action' => 'smallText', 'name' => 'Small Text', 'desc' => 'Generate tiny text'],
            ['action' => 'wordCounter', 'name' => 'Word Counter', 'desc' => 'Count words and characters'],
            ['action' => 'rot13', 'name' => 'ROT13', 'desc' => 'ROT13 encoder/decoder'],
            ['action' => 'morseCode', 'name' => 'Morse Code', 'desc' => 'Morse code translator'],
            ['action' => 'bimiChecker', 'name' => 'BIMI', 'desc' => 'Check/generate BIMI records'],
            ['action' => 'imageToText', 'name' => 'Image to Text', 'desc' => 'OCR text extraction'],
        ]
    ],
    'gaming' => [
        'name' => 'Gaming',
        'icon' => 'fa-gamepad',
        'description' => 'Gaming and Minecraft tools',
        'tools' => [
            ['action' => 'minecraftColorCodes', 'name' => 'MC Color Codes', 'desc' => 'Minecraft colors reference'],
            ['action' => 'minecraftFormatCodes', 'name' => 'MC Format Codes', 'desc' => 'Minecraft formatting codes'],
        ]
    ],
];

if (is_string($requestedCategory) && isset($toolCategories[$requestedCategory])) {
    $category = $requestedCategory;
}
if ($category !== 'all' && is_string($requestedTool)) {
    foreach ($toolCategories[$category]['tools'] as $toolDefinition) {
        if (isset($toolDefinition['action']) && $toolDefinition['action'] === $requestedTool) {
            $toolAction = $requestedTool;
            break;
        }
    }
}

if (!function_exists('tools_center_ajax_response')) {
    function tools_center_ajax_response($payload, $status = 200) {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        echo is_string($json) ? $json : '{"success":false,"error":"Unable to encode response."}';
        exit;
    }
}

if (!function_exists('tools_center_valid_ajax_params')) {
    function tools_center_valid_ajax_params($params) {
        if (!is_array($params) || count($params) > 50) {
            return false;
        }
        foreach ($params as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $key)
                || (!is_string($value) && !is_numeric($value) && !is_bool($value) && $value !== null)
                || (is_string($value) && strlen($value) > 65536)) {
                return false;
            }
        }
        return true;
    }
}

// Handle same-origin AJAX API calls. Session login/access checks above are
// necessary but do not replace CSRF validation for cookie-authenticated POSTs.
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        tools_center_ajax_response(['success' => false, 'error' => 'Only POST requests are supported.'], 405);
    }

    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['token'] ?? '');
    if (!tools_center_verify_csrf_token($csrfToken)) {
        tools_center_ajax_response(['success' => false, 'error' => 'Security token mismatch. Reload the page and try again.'], 403);
    }

    $ajaxCategory = $_POST['category'] ?? '';
    $ajaxAction = $_POST['action'] ?? '';
    $ajaxParams = $_POST['params'] ?? [];
    if (!is_string($ajaxCategory) || !isset($toolCategories[$ajaxCategory])
        || !is_string($ajaxAction) || !is_array($ajaxParams)) {
        tools_center_ajax_response(['success' => false, 'error' => 'Invalid tool request.'], 400);
    }

    $actionAllowed = false;
    foreach ($toolCategories[$ajaxCategory]['tools'] as $toolDefinition) {
        if (isset($toolDefinition['action']) && $toolDefinition['action'] === $ajaxAction) {
            $actionAllowed = true;
            break;
        }
    }
    if (!$actionAllowed) {
        tools_center_ajax_response(['success' => false, 'error' => 'Tool action not found.'], 404);
    }
    if (!tools_center_valid_ajax_params($ajaxParams)) {
        tools_center_ajax_response(['success' => false, 'error' => 'Invalid tool parameters.'], 400);
    }

    $startTime = microtime(true);
    $result = tools_center_api_request($ajaxCategory, $ajaxAction, $ajaxParams, $settings);
    $status = isset($result['success']) && $result['success'] ? 'success' : 'error';
    $responseTime = round((microtime(true) - $startTime) * 1000);
    tools_center_log_usage($clientId, $ajaxCategory, $ajaxAction, $status, $responseTime);
    tools_center_ajax_response($result, 200);
}


// Build client area page
$ca = new ClientArea();
$ca->setPageTitle($settings['module_link_name'] ?? 'Tools Center');
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('index.php?m=tools_center', $settings['module_link_name'] ?? 'Tools Center');

// Assign variables to template
$ca->assign('toolCategories', $toolCategories);
$ca->assign('currentCategory', $category);
$ca->assign('currentTool', $toolAction);
$ca->assign('apiEndpoint', $settings['api_endpoint'] ?? '');
// The API token is used only server-side (tools_center_api_request). It is never sent to the template.
$ca->assign('modulePath', 'modules/addons/tools_center');

// Load specific template based on view
if (!empty($toolAction) && $category !== 'all') {
    $ca->setTemplate('tools/tool');
} elseif ($category !== 'all') {
    $ca->setTemplate('tools/category');
} else {
    $ca->setTemplate('tools/dashboard');
}

$ca->output();