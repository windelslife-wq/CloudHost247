<?php
/**
 * API Authentication Middleware
 * Validates JWT or session tokens for REST API requests
 */

namespace PhoneServices\API\Middleware;

use PhoneServices\Core\Logger;
use PhoneServices\Core\ApiKeyStore;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware
{
    /**
     * Handle authentication
     */
    public function handle()
    {
        // A stale client scope from an earlier request in this session must
        // never leak into the current request (finding P-5).
        unset($_SESSION['api_client_id']);

        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        // Webhooks are not routed through this REST middleware. They authenticate
        // themselves (api/webhooks/*.php). No path-based bypass exists here.

        // Check WHMCS session auth for browser requests
        if (isset($_SESSION['uid']) && $_SESSION['uid'] > 0) {
            return;
        }
        
        // Check API key / JWT for programmatic requests
        if (!empty($authHeader)) {
            if (strpos($authHeader, 'Bearer ') === 0) {
                $token = substr($authHeader, 7);
                if ($this->validateJwt($token)) {
                    return;
                }
            }
            
            if (strpos($authHeader, 'ApiKey ') === 0) {
                $apiKey = substr($authHeader, 7);
                if ($this->validateApiKey($apiKey)) {
                    return;
                }
            }
        }
        
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Unauthorized',
            'hint' => 'Authenticate with a per-client API key (Authorization: ApiKey psk_...) or a signed JWT.',
        ]);
        exit;
    }
    
    /**
     * Validate JWT token
     */
    private function validateJwt(string $token): bool
    {
        try {
            $secret = \PhoneServices\Core\Config::get('jwt_secret', '');
            if (empty($secret)) {
                return false;
            }
            
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            // Only a positive user ID is accepted. sub=0 would otherwise act as "no user".
            if (isset($decoded->sub) && (int) $decoded->sub > 0) {
                $_SESSION['api_user_id'] = $decoded->sub;
                return true;
            }
        } catch (\Exception $e) {
            Logger::error('JWT validation failed', ['error' => $e->getMessage()]);
        }
        return false;
    }
    
    /**
     * Validate API key (finding P-5)
     *
     * Only per-client keys are accepted: a valid key scopes the request to
     * exactly one WHMCS client. The legacy single shared "api_key" setting
     * granted all-tenant access (any customer's numbers could be listed,
     * suspended or released) and is rejected fail-closed.
     */
    private function validateApiKey(string $apiKey): bool
    {
        $row = ApiKeyStore::lookup($apiKey);
        if ($row !== null) {
            $_SESSION['api_client_id'] = $row['clientid'];
            return true;
        }

        $legacyKey = \PhoneServices\Core\Config::get('api_key', '');
        if (!empty($legacyKey) && hash_equals($legacyKey, $apiKey)) {
            Logger::warning('Legacy shared phoneservices API key rejected; per-client keys are required (P-5)');
        }
        return false;
    }
}
