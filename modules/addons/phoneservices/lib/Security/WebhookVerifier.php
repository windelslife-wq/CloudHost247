<?php
/**
 * Webhook request verification.
 *
 * Every verifier fails closed: a missing secret, a missing header or any
 * mismatch returns false.
 */

namespace PhoneServices\Security;

class WebhookVerifier
{
    /**
     * Twilio request signature (X-Twilio-Signature).
     *
     * Algorithm: take the full URL Twilio called, append each POST parameter
     * name and value sorted by name, HMAC-SHA1 with the auth token, base64.
     *
     * @param string $authToken Twilio auth token
     * @param string $url       Exact URL Twilio called (including query string)
     * @param array  $params    POST parameters
     * @param string $signature Value of the X-Twilio-Signature header
     */
    public static function twilio($authToken, $url, array $params, $signature)
    {
        if ($authToken === '' || $signature === '' || $url === '') {
            return false;
        }

        ksort($params, SORT_STRING);
        $data = $url;
        foreach ($params as $name => $value) {
            if (is_array($value)) {
                // Multi-valued parameters are not expected for this module.
                return false;
            }
            $data .= $name . $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
        return hash_equals($expected, (string) $signature);
    }

    /**
     * Vonage signed webhook (Authorization: Bearer <JWT>, HS256).
     *
     * Checks the HS256 signature with the account signature secret and rejects
     * expired tokens. Note: the payload_hash claim is not checked here, because
     * the exact bytes Vonage hashes for form and JSON webhooks need a live test.
     *
     * @param string $signatureSecret Vonage signature secret
     * @param string $authorization   Value of the Authorization header
     */
    public static function vonageJwt($signatureSecret, $authorization)
    {
        if ($signatureSecret === '' || stripos((string) $authorization, 'Bearer ') !== 0) {
            return false;
        }

        $token = trim(substr($authorization, 7));
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        list($headerB64, $payloadB64, $sigB64) = $parts;

        $header = json_decode(self::base64UrlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return false;
        }

        $expected = self::base64UrlEncode(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $signatureSecret, true));
        if (!hash_equals($expected, $sigB64)) {
            return false;
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            return false;
        }
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return false;
        }

        return true;
    }

    private static function base64UrlEncode($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data)
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
