<?php
/**
 * Fixed HTTPS destinations for legacy OVH/SoYouStart transport.
 * Signed OVH credentials must never be forwarded to a caller-supplied URL or
 * a redirect. Public product catalogs are a separate, unsigned allowlist.
 */
namespace WHMCS\Module\Addon\Soyoustart;

class TrustedEndpoint
{
    const API_HOSTS = [
        'api.ovh.com', 'eu.api.ovh.com', 'ca.api.ovh.com',
        'api.ca.ovhcloud.com', 'api.us.ovhcloud.com',
    ];
    const CATALOG_HOSTS = ['www.ovh.com', 'ca.ovh.com', 'us.ovhcloud.com'];

    public static function assertOvhUrl($url, array $headers = [])
    {
        $parts = self::parts($url);
        $host = strtolower($parts['host']);
        if (in_array($host, self::API_HOSTS, true)) {
            if (!preg_match('#^/(?:1\.0|v1)/#', $parts['path'])) {
                throw new \InvalidArgumentException('Untrusted OVH API endpoint.');
            }
            return;
        }
        // Public catalogs are unsigned; never allow even an application key
        // (or any other header) to travel to the catalog web hosts.
        if (in_array($host, self::CATALOG_HOSTS, true)
            && !$headers
            && strpos($parts['path'], '/engine/apiv6/order/catalog/public/') === 0) {
            return;
        }
        throw new \InvalidArgumentException('Untrusted OVH API endpoint.');
    }

    public static function assertGoogleUrl($url)
    {
        $parts = self::parts($url);
        if (!in_array(strtolower($parts['host']), ['accounts.google.com', 'oauth2.googleapis.com', 'www.googleapis.com'], true)) {
            throw new \InvalidArgumentException('Untrusted Google OAuth endpoint.');
        }
    }

    private static function parts($url)
    {
        if (!is_string($url) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \InvalidArgumentException('A trusted HTTPS endpoint is required.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || strtolower($parts['scheme']) !== 'https' || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('A trusted HTTPS endpoint is required.');
        }
        return $parts;
    }
}
