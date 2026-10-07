<?php
/** Small JSON envelope helper with no-store semantics for ceremony payloads. */

namespace CloudHost247\Passkey\Http;

class JsonResponse
{
    public static function send(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(array $data = [])
    {
        self::send(['success' => true] + $data, 200);
    }

    /**
     * Generic failure envelope. $publicMessage must stay attacker-safe;
     * $code is one of CONFIGURATION_REQUIRED, SERVICE_UNAVAILABLE,
     * RATE_LIMITED, INVALID_REQUEST, AUTHENTICATION_FAILED, FORBIDDEN.
     */
    public static function fail($code, $publicMessage, $status = 400, array $extra = [])
    {
        $payload = ['success' => false, 'error_code' => (string) $code, 'message' => (string) $publicMessage];
        foreach ($extra as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z_]+$/', $key)) {
                $payload[$key] = $value;
            }
        }
        self::send($payload, (int) $status);
    }
}
