<?php
/**
 * Session CSRF helpers for the Tools Center client-area AJAX endpoint.
 * WHMCS owns the session token; fail closed if its token helper is absent.
 */

if (!function_exists('tools_center_issue_csrf_token')) {
    function tools_center_issue_csrf_token() {
        if (!function_exists('generate_token')) {
            return null;
        }
        $token = generate_token('plain');
        if (!is_string($token) || strlen($token) < 16 || strlen($token) > 512 || trim($token) !== $token) {
            return null;
        }
        return $token;
    }
}

if (!function_exists('tools_center_verify_csrf_token')) {
    function tools_center_verify_csrf_token($submitted) {
        $expected = tools_center_issue_csrf_token();
        return is_string($expected)
            && is_string($submitted)
            && strlen($submitted) >= 16
            && strlen($submitted) <= 512
            && hash_equals($expected, $submitted);
    }
}
