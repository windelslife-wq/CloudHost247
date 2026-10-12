<?php
/**
 * API Configuration
 */

$config = [
    // API Authentication
    'api_token' => getenv('TOOLS_API_TOKEN') ?: '',
    'api_secret' => getenv('TOOLS_API_SECRET') ?: '',
    // HMAC tokens are intentionally short-lived and single-use.
    'hmac_ttl_seconds' => 300,
    'hmac_clock_skew_seconds' => 30,
    'max_request_bytes' => 65536,
    
    // Exact browser origins permitted to call this API directly. Empty by default;
    // the WHMCS addon uses server-to-server requests and does not need CORS.
    'allowed_origins' => [],
    
    // Rate Limiting
    'rate_limit' => [
        'enabled' => true,
        'requests_per_minute' => 60,
        'requests_per_hour' => 500,
        'requests_per_day' => 5000,
    ],
    
    // Caching
    'cache_enabled' => true,
    'cache_driver' => 'file', // 'file' or 'redis'
    'cache_duration' => 300, // seconds (5 minutes)
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'database' => 0,
    ],
    'cache_path' => __DIR__ . '/cache/',
    
    // External APIs
    'virustotal_api_key' => getenv('VIRUSTOTAL_API_KEY') ?: '',
    'ipgeolocation_api_key' => getenv('IPGEOLOCATION_API_KEY') ?: '',
    
    // Security
    'max_input_length' => 1000,
    'allowed_domains_regex' => '/^[a-zA-Z0-9][a-zA-Z0-9\-]{0,63}(\.[a-zA-Z0-9][a-zA-Z0-9\-]{0,63})+$/',
    'allowed_ip_regex' => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$|^([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}$|^([0-9a-fA-F]{1,4}:){1,7}:$/',
    
    // Paths
    'log_path' => __DIR__ . '/logs/',
    'temp_path' => __DIR__ . '/temp/',
];

// Create necessary directories
$dirs = [$config['cache_path'], $config['log_path'], $config['temp_path']];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    // Cache, rate-limit and diagnostic files can contain operational data.
    // Keep the default runtime directories private to the service account.
    @chmod($dir, 0700);
}
