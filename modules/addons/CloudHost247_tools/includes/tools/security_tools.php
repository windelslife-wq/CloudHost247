<?php
/**
 * CloudHost247 Tools - Cyber Security Tools Implementation
 */

if (!defined("WHMCS") && !defined("CLOUDHOST247_TOOLS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/../functions.php';

function CloudHost247_tool_ssl_checker($post)
{
    $rawHost = $post['host'] ?? ($post['domain'] ?? '');
    $host = (is_string($rawHost) || is_numeric($rawHost)) ? trim((string) $rawHost) : '';
    if ($host === '') {
        return ['error' => 'Please enter a public hostname.'];
    }

    $rawPort = $post['port'] ?? 443;
    if ($rawPort === '' || $rawPort === null) {
        $rawPort = 443;
    }
    try {
        $port = CloudHost247ToolsSecurity::validatePort($rawPort);
        $tlsPorts = [443, 465, 636, 853, 993, 995, 2083, 2087, 2096, 8443];
        if (!in_array($port, $tlsPorts, true)) {
            return ['error' => 'TLS checks are limited to standard HTTPS and implicit-TLS ports.'];
        }
        $target = CloudHost247ToolsSecurity::publicConnectTarget($host, $port);
    } catch (CloudHost247ToolsSecurityException $e) {
        return ['error' => $e->getMessage()];
    }

    if (!function_exists('stream_socket_client') || !function_exists('openssl_x509_parse')) {
        return ['error' => 'TLS socket and OpenSSL support are required on this server.'];
    }

    $sslOptions = [
        'capture_peer_cert' => true,
        'capture_peer_cert_chain' => true,
        // Verification is intentionally deferred so expired/self-signed
        // certificates can still be inspected. Hostname matching is checked
        // below; this tool does not claim to validate the public trust chain.
        'verify_peer' => false,
        'verify_peer_name' => false,
    ];
    if (!CloudHost247ToolsSecurity::isIp($target['host'])) {
        $sslOptions['peer_name'] = $target['host'];
        $sslOptions['SNI_enabled'] = true;
    }
    $context = stream_context_create(['ssl' => $sslOptions]);

    $socket = false;
    $connectedIp = null;
    foreach (array_slice($target['ips'], 0, 3) as $ip) {
        $connectedIp = $ip;
        $socketHost = CloudHost247ToolsSecurity::isIpv6($ip) ? '[' . $ip . ']' : $ip;
        $socket = @stream_socket_client(
            'ssl://' . $socketHost . ':' . $port,
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (is_resource($socket)) {
            break;
        }
    }

    if (!is_resource($socket)) {
        return [
            'host' => $target['host'],
            'resolved_ip' => $connectedIp,
            'port' => $port,
            'ssl_enabled' => false,
            'error' => 'A TLS handshake could not be completed on this public host and port.',
        ];
    }

    $params = stream_context_get_params($socket);
    $meta = stream_get_meta_data($socket);
    fclose($socket);
    $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
    $cert = $certificate ? @openssl_x509_parse($certificate) : false;
    if (!$cert) {
        return [
            'host' => $target['host'],
            'resolved_ip' => $connectedIp,
            'port' => $port,
            'ssl_enabled' => false,
            'error' => 'The remote peer did not provide a parseable TLS certificate.',
        ];
    }

    $validFrom = (int) ($cert['validFrom_time_t'] ?? 0);
    $validTo = (int) ($cert['validTo_time_t'] ?? 0);
    $daysLeft = $validTo > 0 ? (int) floor(($validTo - time()) / 86400) : null;
    $hostnameMatch = null;
    if (CloudHost247ToolsSecurity::isIp($target['host']) && function_exists('openssl_x509_checkip')) {
        $hostnameMatch = @openssl_x509_checkip($certificate, $target['host']);
    } elseif (!CloudHost247ToolsSecurity::isIp($target['host']) && function_exists('openssl_x509_checkhost')) {
        $hostnameMatch = @openssl_x509_checkhost($certificate, $target['host']);
    }

    $sans = [];
    $sanText = $cert['extensions']['subjectAltName'] ?? '';
    if (is_string($sanText) && $sanText !== '') {
        foreach (preg_split('/,\s*/', $sanText) as $san) {
            if (strpos($san, ':') !== false) {
                [$type, $value] = explode(':', $san, 2);
                if (in_array(strtolower($type), ['dns', 'ip address'], true)) {
                    $sans[] = ['type' => strtolower($type), 'value' => trim($value)];
                }
            }
        }
    }

    $crypto = $meta['crypto'] ?? [];
    return [
        'host' => $target['host'],
        'domain' => $target['host'], // legacy renderer compatibility
        'resolved_ip' => $connectedIp,
        'port' => $port,
        'ssl_enabled' => true,
        'issuer' => $cert['issuer']['O'] ?? 'Unknown',
        'subject' => $cert['subject']['CN'] ?? 'Unknown',
        'subject_alt_names' => $sans,
        'hostname_match' => $hostnameMatch === null ? null : (bool) $hostnameMatch,
        'valid_from' => $validFrom > 0 ? date('Y-m-d', $validFrom) : null,
        'valid_to' => $validTo > 0 ? date('Y-m-d', $validTo) : null,
        'days_remaining' => $daysLeft,
        'expired' => $daysLeft === null ? null : $daysLeft < 0,
        'expiring_soon' => $daysLeft === null ? null : ($daysLeft >= 0 && $daysLeft <= 30),
        'serial_number' => $cert['serialNumber'] ?? 'N/A',
        'tls_protocol' => $crypto['protocol'] ?? null,
        'cipher' => $crypto['cipher_name'] ?? null,
        'trust_chain_checked' => false,
        'note' => 'This reports the certificate presented by the server and checks hostname matching when supported. It does not validate CA trust or the full certificate chain.',
    ];
}

function CloudHost247_tool_password_encryptor($post)
{
    $password = $post['password'] ?? '';
    $algorithm = CloudHost247_tools_sanitize($post['algorithm'] ?? 'md5', 'string');

    if (empty($password)) {
        return ['error' => 'Please enter a password'];
    }

    $result = [];
    switch ($algorithm) {
        case 'md5':
            $result['hash'] = md5($password);
            break;
        case 'sha1':
            $result['hash'] = sha1($password);
            break;
        case 'sha256':
            $result['hash'] = hash('sha256', $password);
            break;
        case 'sha512':
            $result['hash'] = hash('sha512', $password);
            break;
        case 'bcrypt':
            $result['hash'] = password_hash($password, PASSWORD_BCRYPT);
            break;
        case 'argon2':
            if (defined('PASSWORD_ARGON2ID')) {
                $result['hash'] = password_hash($password, PASSWORD_ARGON2ID);
            } elseif (defined('PASSWORD_ARGON2I')) {
                $result['hash'] = password_hash($password, PASSWORD_ARGON2I);
            } else {
                return ['error' => 'Argon2 not available on this server'];
            }
            break;
        default:
            return ['error' => 'Unknown algorithm'];
    }

    return array_merge($result, ['algorithm' => $algorithm, 'input_length' => strlen($password)]);
}

function CloudHost247_tool_password_generator($post)
{
    $length = min(max((int) ($post['length'] ?? 16), 4), 128);
    $upper = isset($post['uppercase']);
    $lower = isset($post['lowercase']);
    $numbers = isset($post['numbers']);
    $symbols = isset($post['symbols']);
    $count = min((int) ($post['count'] ?? 5), 20);

    $charset = '';
    if ($upper) $charset .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if ($lower) $charset .= 'abcdefghijklmnopqrstuvwxyz';
    if ($numbers) $charset .= '0123456789';
    if ($symbols) $charset .= '!@#$%^&*()_+-=[]{}|;:,.<>?';

    if (empty($charset)) {
        $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    }

    $passwords = [];
    for ($i = 0; $i < $count; $i++) {
        $password = '';
        for ($j = 0; $j < $length; $j++) {
            $password .= $charset[random_int(0, strlen($charset) - 1)];
        }
        $passwords[] = $password;
    }

    return ['length' => $length, 'count' => $count, 'passwords' => $passwords];
}

function CloudHost247_tool_password_strength($post)
{
    $password = $post['password'] ?? '';
    if (empty($password)) {
        return ['error' => 'Please enter a password'];
    }

    $score = 0;
    $feedback = [];
    $length = strlen($password);

    if ($length >= 8) $score += 10;
    if ($length >= 12) $score += 15;
    if ($length >= 16) $score += 15;
    if ($length < 8) $feedback[] = 'Password is too short (minimum 8 characters)';

    if (preg_match('/[a-z]/', $password)) $score += 10;
    else $feedback[] = 'Add lowercase letters';

    if (preg_match('/[A-Z]/', $password)) $score += 10;
    else $feedback[] = 'Add uppercase letters';

    if (preg_match('/[0-9]/', $password)) $score += 10;
    else $feedback[] = 'Add numbers';

    if (preg_match('/[^a-zA-Z0-9]/', $password)) $score += 15;
    else $feedback[] = 'Add special characters';

    if (preg_match('/(.+)\1{2,}/', $password)) {
        $score -= 10;
        $feedback[] = 'Avoid repeating characters';
    }

    $score = max(0, min(100, $score));

    if ($score < 30) $strength = 'Very Weak';
    elseif ($score < 50) $strength = 'Weak';
    elseif ($score < 70) $strength = 'Moderate';
    elseif ($score < 85) $strength = 'Strong';
    else $strength = 'Very Strong';

    // Check against common passwords
    $common = ['password', '123456', 'qwerty', 'admin', 'letmein', 'welcome', 'monkey', 'dragon'];
    if (in_array(strtolower($password), $common)) {
        $score = 0;
        $strength = 'Very Weak';
        $feedback[] = 'This is a commonly used password';
    }

    return [
        'score' => $score,
        'strength' => $strength,
        'length' => $length,
        'feedback' => $feedback,
        'estimated_crack_time' => $score > 80 ? 'Centuries' : ($score > 60 ? 'Years' : ($score > 40 ? 'Months' : 'Minutes')),
    ];
}
