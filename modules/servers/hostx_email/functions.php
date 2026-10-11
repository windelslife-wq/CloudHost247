<?php
/**
 * HostX Email Module - Shared Functions
 * 
 * Helper functions for encryption, validation, data management,
 * and utility operations.
 * 
 * @package    WHMCS
 * @author     HostX Development Team
 * @copyright  2025 HostX
 * @license    Private
 * @version    1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Legacy encryption key for API credentials.
 *
 * DEPRECATED for new writes: the key is derived from the public SystemURL, so
 * anyone with database read access can decrypt (finding H-4). It is kept only
 * so hostx_email_decrypt_legacy() can still read rows that predate the v1
 * format. New ciphertext always uses hostx_email_get_v1_key().
 *
 * @return string
 */
function hostx_email_get_encryption_key()
{
    $systemUrl = Capsule::table('tblconfiguration')
        ->where('setting', 'SystemURL')
        ->value('value');

    return hash('sha256', $systemUrl . 'HostXEmail_v1.0.0');
}

/**
 * The real secret backing v1 ciphertext: WHMCS's own encryption hash from
 * configuration.php. Returns '' when unavailable (never a weak fallback).
 *
 * @return string
 */
function hostx_email_get_secret()
{
    if (isset($GLOBALS['cc_encryption_hash']) && is_string($GLOBALS['cc_encryption_hash']) && $GLOBALS['cc_encryption_hash'] !== '') {
        return $GLOBALS['cc_encryption_hash'];
    }
    return '';
}

/**
 * 32-byte v1 data key derived from the WHMCS secret with domain separation.
 * Returns '' when no secret is available; callers must fail closed.
 *
 * @return string
 */
function hostx_email_get_v1_key()
{
    $secret = hostx_email_get_secret();
    if ($secret === '') {
        return '';
    }
    return hash('sha256', 'hostx-email-v1:' . $secret, true);
}

/**
 * Encrypt sensitive data (v1 format: AES-256-GCM, authenticated).
 *
 * Output is 'hxem:v1:' followed by base64(nonce . tag . ciphertext). Fails
 * closed: throws when no secret is configured or the platform lacks GCM, so
 * a secret is never protected by a weak or missing key.
 *
 * @param string $data
 * @return string
 * @throws RuntimeException
 */
function hostx_email_encrypt($data)
{
    if (empty($data)) {
        return '';
    }

    $key = hostx_email_get_v1_key();
    if ($key === '') {
        throw new RuntimeException('HostX Email: no encryption secret configured ($cc_encryption_hash is missing). Refusing to encrypt.');
    }
    if (!in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
        throw new RuntimeException('HostX Email: this PHP build lacks AES-256-GCM. Refusing to encrypt.');
    }

    $nonce = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt((string) $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'hostx-email-v1', 16);
    if ($encrypted === false || $tag === '') {
        throw new RuntimeException('HostX Email: encryption failed.');
    }

    return 'hxem:v1:' . base64_encode($nonce . $tag . $encrypted);
}

/**
 * Decrypt a v1 payload. Returns the plaintext, or false when the value is not
 * verifiable (wrong key, tampered, truncated, or no secret configured).
 *
 * @param string $data Full stored value including the 'hxem:v1:' prefix.
 * @return string|false
 */
function hostx_email_decrypt_v1($data)
{
    if (!is_string($data) || substr($data, 0, 8) !== 'hxem:v1:') {
        return false;
    }
    $key = hostx_email_get_v1_key();
    if ($key === '') {
        return false;
    }
    $raw = base64_decode(substr($data, 8), true);
    if ($raw === false || strlen($raw) < 12 + 16 + 1) {
        return false;
    }
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $encrypted = substr($raw, 28);
    $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'hostx-email-v1');
    if ($decrypted === false) {
        return false;
    }
    return $decrypted;
}

/**
 * Decrypt a legacy (pre-v1) payload: AES-256-CBC under the SystemURL-derived
 * key, unauthenticated (findings H-4/H-6). Kept byte-for-byte so rows written
 * before the v1 upgrade keep reading during and after migration. Never used
 * for new writes.
 *
 * @param string $data
 * @return string '' on any failure (same contract as before).
 */
function hostx_email_decrypt_legacy($data)
{
    if (empty($data)) {
        return '';
    }

    try {
        $key = hostx_email_get_encryption_key();
        $data = base64_decode($data);

        if ($data === false || strlen($data) < 16) {
            return '';
        }

        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : '';

    } catch (Exception $e) {
        return '';
    }
}

/**
 * Decrypt sensitive data. Reads v1 ciphertext first and falls back to the
 * legacy format, so rows migrate transparently (see
 * upgrade/migrate-credentials.php). Returns '' on any failure.
 *
 * @param string $data
 * @return string
 */
function hostx_email_decrypt($data)
{
    if (empty($data)) {
        return '';
    }

    if (is_string($data) && substr($data, 0, 8) === 'hxem:v1:') {
        $decrypted = hostx_email_decrypt_v1($data);
        if ($decrypted !== false && $decrypted !== '') {
            return $decrypted;
        }
    }

    return hostx_email_decrypt_legacy($data);
}

/**
 * True when the value is v1 ciphertext that verifies under the current key.
 * Used by the migration to skip rows that are already upgraded.
 *
 * @param string $data
 * @return bool
 */
function hostx_email_is_current($data)
{
    if (!is_string($data) || $data === '' || substr($data, 0, 8) !== 'hxem:v1:') {
        return false;
    }
    $decrypted = hostx_email_decrypt_v1($data);
    return $decrypted !== false && $decrypted !== '';
}

/**
 * Migrate one stored inner value to v1.
 *
 * Returns the v1 ciphertext when $data holds a legacy value that decrypts;
 * otherwise returns $data unchanged (already v1, empty, or undecryptable and
 * needing manual review). Pure: it never touches the database.
 *
 * @param string $data
 * @return string
 * @throws RuntimeException when a migration is needed but no secret exists.
 */
function hostx_email_migrate_value($data)
{
    if (!is_string($data) || $data === '' || hostx_email_is_current($data)) {
        return $data;
    }
    $plain = hostx_email_decrypt_legacy($data);
    if ($plain === '') {
        return $data;
    }
    return hostx_email_encrypt($plain);
}

/**
 * Generate a secure random password
 * 
 * @param int $length
 * @return string
 */
function hostx_email_generate_password($length = 16)
{
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special = '!@#$%^&*()_+-=[]{}|;:,.<>?';
    
    $all = $uppercase . $lowercase . $numbers . $special;
    
    $password = '';
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];
    
    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }
    
    return str_shuffle($password);
}

/**
 * Validate input parameters
 * 
 * @param array $params
 * @return bool
 * @throws Exception
 */
function hostx_email_validate_input(array $params)
{
    $domain = $params['domain'] ?: '';
    $username = $params['username'] ?: '';
    
    if (empty($domain)) {
        throw new Exception('Domain name is required');
    }
    
    if (empty($username)) {
        throw new Exception('Username is required');
    }
    
    // Validate domain format
    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        throw new Exception('Invalid domain name format');
    }
    
    // Validate username format (alphanumeric, dots, hyphens, underscores)
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        throw new Exception('Invalid username format. Use only letters, numbers, dots, hyphens, and underscores.');
    }
    
    // Validate username length
    if (strlen($username) < 1 || strlen($username) > 64) {
        throw new Exception('Username must be between 1 and 64 characters');
    }
    
    return true;
}

/**
 * Validate password strength
 * 
 * @param string $password
 * @return bool
 * @throws Exception
 */
function hostx_email_validate_password($password)
{
    if (empty($password)) {
        throw new Exception('Password cannot be empty');
    }
    
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }
    
    if (strlen($password) > 128) {
        throw new Exception('Password cannot exceed 128 characters');
    }
    
    return true;
}

/**
 * Store account data in database
 * 
 * @param int $serviceId
 * @param array $data
 * @return bool
 */
function hostx_email_store_account_data($serviceId, array $data)
{
    try {
        // Check if table exists, create if not
        if (!Capsule::schema()->hasTable('mod_hostx_email_accounts')) {
            Capsule::schema()->create('mod_hostx_email_accounts', function ($table) {
                $table->increments('id');
                $table->integer('service_id')->unsigned();
                $table->string('provider', 50);
                $table->string('plan', 50);
                $table->string('email_address', 255);
                $table->string('external_id', 255)->nullable();
                $table->string('status', 50)->default('active');
                $table->text('additional_data')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique('service_id');
            });
        }
        
        $existing = Capsule::table('mod_hostx_email_accounts')
            ->where('service_id', $serviceId)
            ->first();
        
        $recordData = [
            'service_id' => $serviceId,
            'provider' => $data['provider'] ?: '',
            'plan' => $data['plan'] ?: '',
            'email_address' => $data['email_address'] ?: '',
            'external_id' => $data['external_id'] ?: '',
            'status' => $data['status'] ?: 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        
        if (!empty($data['additional_data'])) {
            $recordData['additional_data'] = json_encode($data['additional_data']);
        }
        
        if ($existing) {
            Capsule::table('mod_hostx_email_accounts')
                ->where('service_id', $serviceId)
                ->update($recordData);
        } else {
            $recordData['created_at'] = $data['created_at'] ?: date('Y-m-d H:i:s');
            Capsule::table('mod_hostx_email_accounts')->insert($recordData);
        }
        
        return true;
        
    } catch (Exception $e) {
        logModuleCall(
            'hostx_email',
            'StoreAccountData-Error',
            $e->getMessage(),
            ['service_id' => $serviceId],
            null,
            null
        );
        return false;
    }
}

/**
 * Get account data from database
 * 
 * @param int $serviceId
 * @return array
 */
function hostx_email_get_account_data($serviceId)
{
    try {
        if (!Capsule::schema()->hasTable('mod_hostx_email_accounts')) {
            return [];
        }
        
        $record = Capsule::table('mod_hostx_email_accounts')
            ->where('service_id', $serviceId)
            ->first();
        
        if ($record) {
            $data = (array) $record;
            if (!empty($data['additional_data'])) {
                $data['additional_data'] = json_decode($data['additional_data'], true);
            }
            return $data;
        }
        
        return [];
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Update account status
 * 
 * @param int $serviceId
 * @param string $status
 * @return bool
 */
function hostx_email_update_account_status($serviceId, $status)
{
    try {
        if (!Capsule::schema()->hasTable('mod_hostx_email_accounts')) {
            return false;
        }
        
        Capsule::table('mod_hostx_email_accounts')
            ->where('service_id', $serviceId)
            ->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        return true;
        
    } catch (Exception $e) {
        logModuleCall(
            'hostx_email',
            'UpdateAccountStatus-Error',
            $e->getMessage(),
            ['service_id' => $serviceId, 'status' => $status],
            null,
            null
        );
        return false;
    }
}

/**
 * Update account data
 * 
 * @param int $serviceId
 * @param array $data
 * @return bool
 */
function hostx_email_update_account_data($serviceId, array $data)
{
    try {
        if (!Capsule::schema()->hasTable('mod_hostx_email_accounts')) {
            return false;
        }
        
        $updateData = ['updated_at' => date('Y-m-d H:i:s')];
        
        if (isset($data['plan'])) {
            $updateData['plan'] = $data['plan'];
        }
        if (isset($data['external_id'])) {
            $updateData['external_id'] = $data['external_id'];
        }
        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }
        
        Capsule::table('mod_hostx_email_accounts')
            ->where('service_id', $serviceId)
            ->update($updateData);
        
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Delete account data
 * 
 * @param int $serviceId
 * @return bool
 */
function hostx_email_delete_account_data($serviceId)
{
    try {
        if (!Capsule::schema()->hasTable('mod_hostx_email_accounts')) {
            return false;
        }
        
        Capsule::table('mod_hostx_email_accounts')
            ->where('service_id', $serviceId)
            ->delete();
        
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get provider display name
 * 
 * @param string $provider
 * @return string
 */
function hostx_email_get_provider_name($provider)
{
    $providers = [
        'professional' => 'Professional Email (IMAP/SMTP)',
        'microsoft365' => 'Microsoft 365',
        'google_workspace' => 'Google Workspace',
    ];
    
    return $providers[$provider] ?: 'Unknown Provider';
}

/**
 * Get login URLs for provider
 * 
 * @param string $provider
 * @param string $domain
 * @return array
 */
function hostx_email_get_login_urls($provider, $domain = '')
{
    $urls = [
        'professional' => [
            'primary' => 'https://webmail.' . $domain,
            'webmail' => 'https://webmail.' . $domain,
            'smtp' => 'smtp.' . $domain,
            'imap' => 'imap.' . $domain,
        ],
        'microsoft365' => [
            'primary' => 'https://outlook.office365.com',
            'webmail' => 'https://outlook.office365.com/mail',
            'outlook' => 'https://outlook.office365.com',
            'admin' => 'https://admin.microsoft.com',
        ],
        'google_workspace' => [
            'primary' => 'https://mail.google.com',
            'webmail' => 'https://mail.google.com',
            'gmail' => 'https://mail.google.com',
            'admin' => 'https://admin.google.com',
        ],
    ];
    
    return $urls[$provider] ?: $urls['professional'];
}

/**
 * Get DNS records for domain configuration
 * 
 * @param string $provider
 * @param string $domain
 * @return array
 */
function hostx_email_get_dns_records($provider, $domain)
{
    $records = [];
    
    switch ($provider) {
        case 'microsoft365':
            $records = [
                [
                    'type' => 'MX',
                    'host' => '@',
                    'priority' => 0,
                    'value' => $domain . '.mail.protection.outlook.com',
                    'description' => 'Mail Exchanger for Microsoft 365',
                ],
                [
                    'type' => 'TXT',
                    'host' => '@',
                    'value' => 'v=spf1 include:spf.protection.outlook.com -all',
                    'description' => 'SPF record for Microsoft 365',
                ],
                [
                    'type' => 'CNAME',
                    'host' => 'autodiscover',
                    'value' => 'autodiscover.outlook.com',
                    'description' => 'AutoDiscover for Outlook clients',
                ],
                [
                    'type' => 'CNAME',
                    'host' => 'msoid',
                    'value' => 'clientconfig.microsoftonline-p.net',
                    'description' => 'Microsoft Online ID',
                ],
                [
                    'type' => 'TXT',
                    'host' => '@',
                    'value' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@' . $domain,
                    'description' => 'DMARC policy record',
                ],
            ];
            break;
            
        case 'google_workspace':
            $records = [
                [
                    'type' => 'MX',
                    'host' => '@',
                    'priority' => 1,
                    'value' => 'ASPMX.L.GOOGLE.COM',
                    'description' => 'Primary Google Mail Exchanger',
                ],
                [
                    'type' => 'MX',
                    'host' => '@',
                    'priority' => 5,
                    'value' => 'ALT1.ASPMX.L.GOOGLE.COM',
                    'description' => 'Backup Google Mail Exchanger 1',
                ],
                [
                    'type' => 'MX',
                    'host' => '@',
                    'priority' => 5,
                    'value' => 'ALT2.ASPMX.L.GOOGLE.COM',
                    'description' => 'Backup Google Mail Exchanger 2',
                ],
                [
                    'type' => 'MX',
                    'host' => '@',
                    'priority' => 10,
                    'value' => 'ALT3.ASPMX.L.GOOGLE.COM',
                    'description' => 'Backup Google Mail Exchanger 3',
                ],
                [
                    'type' => 'MX',
                    'host' => '@',
                    'priority' => 10,
                    'value' => 'ALT4.ASPMX.L.GOOGLE.COM',
                    'description' => 'Backup Google Mail Exchanger 4',
                ],
                [
                    'type' => 'TXT',
                    'host' => '@',
                    'value' => 'v=spf1 include:_spf.google.com -all',
                    'description' => 'SPF record for Google Workspace',
                ],
                [
                    'type' => 'CNAME',
                    'host' => 'mail',
                    'value' => 'ghs.googlehosted.com',
                    'description' => 'Webmail access alias',
                ],
                [
                    'type' => 'TXT',
                    'host' => '@',
                    'value' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@' . $domain,
                    'description' => 'DMARC policy record',
                ],
            ];
            break;
            
        case 'professional':
        default:
            $records = [
                [
                    'type' => 'MX',
                    'host' => '@',
                    'priority' => 10,
                    'value' => 'mx1.' . $domain,
                    'description' => 'Primary Mail Exchanger',
                ],
                [
                    'type' => 'MX',
                    'host' => '@',
                    'priority' => 20,
                    'value' => 'mx2.' . $domain,
                    'description' => 'Backup Mail Exchanger',
                ],
                [
                    'type' => 'A',
                    'host' => 'mail',
                    'value' => $_SERVER['SERVER_ADDR'] ?: 'YOUR_SERVER_IP',
                    'description' => 'Webmail access',
                ],
                [
                    'type' => 'A',
                    'host' => 'mx1',
                    'value' => $_SERVER['SERVER_ADDR'] ?: 'YOUR_SERVER_IP',
                    'description' => 'Primary Mail Server',
                ],
                [
                    'type' => 'A',
                    'host' => 'mx2',
                    'value' => $_SERVER['SERVER_ADDR'] ?: 'YOUR_SERVER_IP_BACKUP',
                    'description' => 'Backup Mail Server',
                ],
                [
                    'type' => 'TXT',
                    'host' => '@',
                    'value' => 'v=spf1 a mx include:' . $domain . ' -all',
                    'description' => 'SPF record',
                ],
                [
                    'type' => 'CNAME',
                    'host' => 'webmail',
                    'value' => 'mail.' . $domain,
                    'description' => 'Webmail alias',
                ],
                [
                    'type' => 'TXT',
                    'host' => '_dmarc',
                    'value' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@' . $domain,
                    'description' => 'DMARC policy record',
                ],
            ];
            break;
    }
    
    return $records;
}

/**
 * Build params array from service object
 * 
 * @param object $service
 * @return array|null
 */
function hostx_email_build_params_from_service($service)
{
    try {
        $product = Capsule::table('tblproducts')
            ->where('id', $service->packageid)
            ->first();
        
        $server = Capsule::table('tblservers')
            ->where('id', $service->server)
            ->first();
        
        $client = Capsule::table('tblclients')
            ->where('id', $service->userid)
            ->first();
        
        if (!$product) {
            return null;
        }
        
        return [
            'serviceid' => $service->id,
            'pid' => $service->packageid,
            'userid' => $service->userid,
            'domain' => $service->domain,
            'username' => $service->username,
            'password' => $service->password ? hostx_email_decrypt($service->password) : '',
            'configoption1' => $product->configoption1,
            'configoption2' => $product->configoption2,
            'configoption3' => $product->configoption3,
            'configoption4' => $product->configoption4,
            'configoption5' => $product->configoption5,
            'server' => $service->server,
            'serverusername' => $server ? hostx_email_decrypt($server->username) : '',
            'serverpassword' => $server ? hostx_email_decrypt($server->password) : '',
            'serveraccesshash' => $server ? $server->accesshash : '',
            'serverip' => $server ? $server->ipaddress : '',
            'serverhostname' => $server ? $server->hostname : '',
            'clientsdetails' => [
                'fullname' => $client ? $client->firstname . ' ' . $client->lastname : '',
                'firstname' => $client ? $client->firstname : '',
                'lastname' => $client ? $client->lastname : '',
                'email' => $client ? $client->email : '',
            ],
            'systemurl' => Capsule::table('tblconfiguration')
                ->where('setting', 'SystemURL')
                ->value('value'),
        ];
        
    } catch (Exception $e) {
        logActivity("HostX Email: Error building params - " . $e->getMessage());
        return null;
    }
}

/**
 * Log API request/response
 * 
 * @param string $provider
 * @param string $action
 * @param string $endpoint
 * @param array $request
 * @param array $response
 * @param int $httpCode
 */
function hostx_email_log_api_call($provider, $action, $endpoint, $request, $response, $httpCode = 200)
{
    try {
        // Check if log table exists, create if not
        if (!Capsule::schema()->hasTable('mod_hostx_email_api_logs')) {
            Capsule::schema()->create('mod_hostx_email_api_logs', function ($table) {
                $table->increments('id');
                $table->string('provider', 50);
                $table->string('action', 100);
                $table->text('endpoint');
                $table->text('request')->nullable();
                $table->text('response')->nullable();
                $table->integer('http_code')->default(0);
                $table->timestamp('created_at')->nullable();
            });
        }
        
        // Sanitize request to remove sensitive data
        $sanitizedRequest = $request;
        if (isset($sanitizedRequest['password'])) {
            $sanitizedRequest['password'] = '***REDACTED***';
        }
        if (isset($sanitizedRequest['client_secret'])) {
            $sanitizedRequest['client_secret'] = '***REDACTED***';
        }
        
        Capsule::table('mod_hostx_email_api_logs')->insert([
            'provider' => $provider,
            'action' => $action,
            'endpoint' => $endpoint,
            'request' => json_encode($sanitizedRequest),
            'response' => json_encode($response),
            'http_code' => $httpCode,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
    } catch (Exception $e) {
        // Silently fail - don't break functionality due to logging issues
    }
}

/**
 * Clean old API logs (keep last 30 days)
 */
function hostx_email_clean_old_logs()
{
    try {
        if (Capsule::schema()->hasTable('mod_hostx_email_api_logs')) {
            Capsule::table('mod_hostx_email_api_logs')
                ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-30 days')))
                ->delete();
        }
    } catch (Exception $e) {
        // Silently fail
    }
}

/**
 * Safe cURL execution with timeout and error handling
 * 
 * @param string $url
 * @param array $options
 * @return array
 */
function hostx_email_curl_execute($url, array $options = [])
{
    $ch = curl_init($url);
    
    $defaultOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'HostX-Email-Module/1.0',
    ];
    
    foreach ($defaultOptions as $option => $value) {
        curl_setopt($ch, $option, $value);
    }
    
    // Apply custom options
    foreach ($options as $option => $value) {
        curl_setopt($ch, $option, $value);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    
    curl_close($ch);
    
    if ($errno !== 0) {
        return [
            'success' => false,
            'error' => 'cURL Error (' . $errno . '): ' . $error,
            'http_code' => 0,
            'response' => null,
        ];
    }
    
    return [
        'success' => true,
        'http_code' => $httpCode,
        'response' => $response,
    ];
}

/**
 * Parse JSON response safely
 * 
 * @param string $json
 * @return array|null
 */
function hostx_email_parse_json($json)
{
    if (empty($json)) {
        return null;
    }
    
    $data = json_decode($json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['raw_response' => $json];
    }
    
    return $data;
}

/**
 * Check if required PHP extensions are loaded
 * 
 * @return array
 */
function hostx_email_check_requirements()
{
    $requirements = [
        'curl' => extension_loaded('curl'),
        'openssl' => extension_loaded('openssl'),
        'json' => extension_loaded('json'),
        'pdo' => extension_loaded('pdo'),
        'filter' => extension_loaded('filter'),
    ];
    
    $missing = array_filter($requirements, function ($loaded) {
        return !$loaded;
    });
    
    return [
        'met' => empty($missing),
        'missing' => array_keys($missing),
    ];
}

/**
 * Module installation routine
 */
function hostx_email_install()
{
    try {
        // Create accounts table
        if (!Capsule::schema()->hasTable('mod_hostx_email_accounts')) {
            Capsule::schema()->create('mod_hostx_email_accounts', function ($table) {
                $table->increments('id');
                $table->integer('service_id')->unsigned();
                $table->string('provider', 50);
                $table->string('plan', 50);
                $table->string('email_address', 255);
                $table->string('external_id', 255)->nullable();
                $table->string('status', 50)->default('active');
                $table->text('additional_data')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique('service_id');
            });
        }
        
        // Create API logs table
        if (!Capsule::schema()->hasTable('mod_hostx_email_api_logs')) {
            Capsule::schema()->create('mod_hostx_email_api_logs', function ($table) {
                $table->increments('id');
                $table->string('provider', 50);
                $table->string('action', 100);
                $table->text('endpoint');
                $table->text('request')->nullable();
                $table->text('response')->nullable();
                $table->integer('http_code')->default(0);
                $table->timestamp('created_at')->nullable();
            });
        }
        
        logActivity('HostX Email Module: Installation completed successfully');
        
    } catch (Exception $e) {
        logActivity('HostX Email Module: Installation error - ' . $e->getMessage());
    }
}

/**
 * Module upgrade routine
 * 
 * @param array $vars
 */
function hostx_email_upgrade($vars)
{
    $version = $vars['version'];
    
    // Future upgrade routines can be added here
    logActivity('HostX Email Module: Upgraded from version ' . $version);
}

/**
 * Module activation
 */
function hostx_email_activate()
{
    hostx_email_install();
    return [
        'status' => 'success',
        'description' => 'HostX Email module activated successfully.',
    ];
}

/**
 * Module deactivation
 */
function hostx_email_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'HostX Email module deactivated. Database tables preserved for data integrity.',
    ];
}

/**
 * Unwrap one stored cell from WHMCS core encryption.
 *
 * Returns [inner, wrapped]. A value is only accepted as wrapped when the
 * unwrapped content is itself meaningful (v1, or legacy-decryptable);
 * otherwise the raw value is analysed as-is, so a plaintext column is never
 * misread through a garbage unwrap.
 *
 * @param string $raw
 * @return array
 */
function hostx_email_unwrap_storage($raw)
{
    if (!function_exists('decrypt')) {
        return [$raw, false];
    }
    try {
        $inner = @decrypt($raw);
    } catch (\Throwable $e) {
        return [$raw, false];
    }
    if (!is_string($inner) || $inner === '' || $inner === $raw) {
        return [$raw, false];
    }
    if (hostx_email_is_current($inner) || hostx_email_decrypt_legacy($inner) !== '') {
        return [$inner, true];
    }
    return [$raw, false];
}

/**
 * Migrate one database cell to v1. Updates $report in place; writes only
 * when $dryRun is false. A cell already v1, empty, or undecryptable is never
 * written; undecryptable cells land in $report['manual'] for review.
 *
 * @param string $table
 * @param int|string $id
 * @param string $column
 * @param mixed $raw
 * @param bool $dryRun
 * @param array $report
 * @return void
 */
function hostx_email_migrate_cell($table, $id, $column, $raw, $dryRun, array &$report)
{
    $label = $table . ':' . $id . ':' . $column;
    $report['scanned']++;
    if ($raw === null || $raw === '') {
        $report['empty']++;
        return;
    }
    $raw = (string) $raw;
    list($inner, $wrapped) = hostx_email_unwrap_storage($raw);
    if (hostx_email_is_current($inner)) {
        $report['current']++;
        return;
    }
    try {
        $migrated = hostx_email_migrate_value($inner);
    } catch (\Throwable $e) {
        $report['manual'][] = $label;
        $report['errors'][] = $label . ': ' . $e->getMessage();
        return;
    }
    if ($migrated === $inner) {
        $report['manual'][] = $label;
        return;
    }
    if ($dryRun) {
        $report['migrated']++;
        return;
    }
    $store = $migrated;
    if ($wrapped) {
        if (!function_exists('encrypt')) {
            $report['manual'][] = $label;
            $report['errors'][] = $label . ': WHMCS encrypt() is unavailable; leaving the legacy value in place.';
            return;
        }
        try {
            $store = encrypt($migrated);
        } catch (\Throwable $e) {
            $report['manual'][] = $label;
            $report['errors'][] = $label . ': re-wrap failed: ' . $e->getMessage();
            return;
        }
        if (!is_string($store) || $store === '') {
            $report['manual'][] = $label;
            $report['errors'][] = $label . ': re-wrap produced no output; leaving the legacy value in place.';
            return;
        }
    }
    try {
        Capsule::table($table)->where('id', $id)->update([$column => $store]);
    } catch (\Throwable $e) {
        $report['manual'][] = $label;
        $report['errors'][] = $label . ': write failed: ' . $e->getMessage();
        return;
    }
    $report['migrated']++;
}

/**
 * One-time credential migration: legacy ciphertext -> v1 (findings H-4/H-6).
 *
 * Scans every column this module reads through hostx_email_decrypt():
 * tblservers (username, password), tblhosting (password) and tblproducts
 * (configoption7). Each cell is unwrapped from WHMCS core encryption when
 * wrapped, classified (v1 / legacy / unknown) and — unless 'dry_run' is set
 * — rewritten in v1 form, re-wrapped exactly as found.
 *
 * Options: ['dry_run' => bool] (default true: report only, write nothing).
 * In a dry run 'migrated' counts cells that WOULD be rewritten.
 *
 * @param array $options
 * @return array Report with scanned/migrated/current/empty counts, 'manual'
 *               (list of 'table:id:column' needing review) and 'errors'.
 */
function hostx_email_migrate_credentials(array $options = [])
{
    $dryRun = !isset($options['dry_run']) || !empty($options['dry_run']);
    $report = ['scanned' => 0, 'migrated' => 0, 'current' => 0, 'empty' => 0, 'manual' => [], 'errors' => []];

    if (hostx_email_get_secret() === '') {
        $report['errors'][] = 'no encryption secret configured ($cc_encryption_hash is missing); refusing to run.';
        return $report;
    }

    try {
        $hostingRows = Capsule::table('tblhosting')->where('servertype', 'hostx_email')->get();
    } catch (\Throwable $e) {
        $hostingRows = [];
        $report['errors'][] = 'could not read tblhosting: ' . $e->getMessage();
    }
    $linkedServerIds = [];
    foreach ($hostingRows as $row) {
        $row = (array) $row;
        if (!empty($row['server'])) {
            $linkedServerIds[(int) $row['server']] = true;
        }
        hostx_email_migrate_cell('tblhosting', $row['id'], 'password', isset($row['password']) ? $row['password'] : null, $dryRun, $report);
    }

    // Servers: match by module type, plus any server linked from a
    // hostx_email service (robust if the type column differs by version).
    $serverRows = [];
    try {
        $serverRows = Capsule::table('tblservers')->where('type', 'hostx_email')->get();
        if (!empty($linkedServerIds)) {
            $extra = Capsule::table('tblservers')->whereIn('id', array_keys($linkedServerIds))->get();
            foreach ($extra as $row) {
                $serverRows[] = $row;
            }
        }
    } catch (\Throwable $e) {
        $report['errors'][] = 'could not read tblservers: ' . $e->getMessage();
    }
    $seenServers = [];
    foreach ($serverRows as $row) {
        $row = (array) $row;
        if (isset($seenServers[(int) $row['id']])) {
            continue;
        }
        $seenServers[(int) $row['id']] = true;
        hostx_email_migrate_cell('tblservers', $row['id'], 'username', isset($row['username']) ? $row['username'] : null, $dryRun, $report);
        hostx_email_migrate_cell('tblservers', $row['id'], 'password', isset($row['password']) ? $row['password'] : null, $dryRun, $report);
    }

    try {
        $productRows = Capsule::table('tblproducts')->where('servertype', 'hostx_email')->get();
    } catch (\Throwable $e) {
        $productRows = [];
        $report['errors'][] = 'could not read tblproducts: ' . $e->getMessage();
    }
    foreach ($productRows as $row) {
        $row = (array) $row;
        hostx_email_migrate_cell('tblproducts', $row['id'], 'configoption7', isset($row['configoption7']) ? $row['configoption7'] : null, $dryRun, $report);
    }

    return $report;
}
