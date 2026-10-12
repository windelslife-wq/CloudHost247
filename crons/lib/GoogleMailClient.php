<?php
namespace CloudHost247\SoYouStart\Cron;

/** Small, read-only Gmail API transport for the email cron. */
final class GoogleMailClient
{
    public static function request($method, $url, $accessToken)
    {
        $method = strtoupper(trim((string) $method));
        if ($method !== 'GET') {
            throw new \InvalidArgumentException('Unsupported Gmail API method.');
        }
        if (!is_string($accessToken) || $accessToken === '' || preg_match('/[\x00-\x20\x7f]/', $accessToken)) {
            throw new \InvalidArgumentException('Invalid Gmail API access token.');
        }
        self::assertGmailUrl($url);

        $curl = curl_init();
        if ($curl === false) {
            throw new \RuntimeException('Gmail API request could not be initialized.');
        }

        try {
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPGET, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
            // Never forward a bearer token to an HTTP redirect destination.
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 0);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
            ]);

            $response = curl_exec($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($response === false) {
                throw new \RuntimeException('Gmail API request failed.');
            }

            return ['httpcode' => $httpCode, 'result' => json_decode($response)];
        } finally {
            curl_close($curl);
        }
    }

    private static function assertGmailUrl($url)
    {
        if (!is_string($url) || strlen($url) > 2048) {
            throw new \InvalidArgumentException('Invalid Gmail API URL.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'gmail.googleapis.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || !preg_match('#\\A/gmail/v1/users/me/messages(?:/[A-Za-z0-9_-]{1,256})?\\z#D', (string) ($parts['path'] ?? ''))) {
            throw new \InvalidArgumentException('Gmail API URL is outside the allowed endpoint.');
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (array_diff(array_keys($query), ['q', 'fields'])) {
            throw new \InvalidArgumentException('Unsupported Gmail API query parameter.');
        }
        foreach ($query as $name => $value) {
            $limit = $name === 'q' ? 512 : 64;
            if (!is_string($value) || strlen($value) > $limit) {
                throw new \InvalidArgumentException('Invalid Gmail API query value.');
            }
        }
    }
}
