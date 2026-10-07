<?php
namespace CloudHost247\Cloudflare\Provider;
class CurlTransport implements TransportInterface
{
    public function send($method, $url, array $headers, $body, $timeoutSeconds)
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('The PHP cURL extension is required for Cloudflare API access.');
        $ch = curl_init($url);
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper((string) $method), CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => max(5, (int) $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $length = strlen($line); $parts = explode(':', $line, 2);
                if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return $length;
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($ch);
        $errorNo = curl_errno($ch); $error = curl_error($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $errorNo !== 0) {
            throw new \RuntimeException('Cloudflare API transport failed (' . $errorNo . '): ' . substr($error, 0, 120));
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $response];
    }
}
