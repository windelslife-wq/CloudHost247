<?php
/** Authentication boundary for the addon REST API. */

namespace Ch247Apps\Api;

use Ch247Apps\Core\AuthenticationException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Identity;

class ApiAuth
{
    public static function resolve()
    {
        $authorization = trim((string) Http::header('Authorization'));
        if ($authorization !== '') {
            if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
                throw new AuthenticationException('The Authorization header is invalid.');
            }
            $actor = Identity::fromApiToken(trim($match[1]));
            if ($actor === null) {
                // An invalid explicit bearer token must not silently fall back to
                // an ambient WHMCS session.
                throw new AuthenticationException('The bearer token is invalid or expired.');
            }
            return $actor;
        }
        $actor = Identity::current();
        if (!$actor->isAuthenticated()) {
            throw new AuthenticationException('Sign in to WHMCS or use a valid bearer token.');
        }
        return $actor;
    }
}
