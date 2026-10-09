<?php
/**
 * Standalone, outbound-only heartbeat client. Copy the agent/ directory to a
 * server outside the web root; this class never boots WHMCS or runs Docker.
 */
namespace Ch247Agent;

class HeartbeatSender
{
    const SIGNING_PATH = '/agent/v1/heartbeat';
    const ENDPOINT = '/modules/addons/cloudhost247apps/api/agent.php';

    private $url;
    private $uuid;
    private $secret;
    private $version;
    private $transport;

    /** $transport is injectable only for offline tests; otherwise cURL is used. */
    public function __construct($baseUrl, $uuid, $secret, $version = '1.0.0', callable $transport = null)
    {
        $this->url = self::endpoint($baseUrl);
        $this->uuid = (string) $uuid;
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $this->uuid)) {
            throw new \InvalidArgumentException('A registered agent UUID is required.');
        }
        $this->secret = (string) $secret;
        if (strlen($this->secret) < 32 || strlen($this->secret) > 4096) {
            throw new \InvalidArgumentException('The agent shared secret must be 32–4096 bytes.');
        }
        $this->version = (string) $version;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,39}$/D', $this->version)) {
            throw new \InvalidArgumentException('Invalid agent version.');
        }
        $this->transport = $transport;
    }

    /** Build only a fixed HTTPS target. No credentials, query or fragment in its URL. */
    public static function endpoint($baseUrl)
    {
        $baseUrl = (string) $baseUrl;
        if (preg_match('/[\x00-\x20\x7f]/', $baseUrl)) {
            throw new \InvalidArgumentException('Invalid WHMCS base URL.');
        }
        try {
            $parts = parse_url($baseUrl);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid WHMCS base URL.');
        }
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('A credential-free HTTPS WHMCS base URL is required.');
        }
        $host = (string) $parts['host'];
        if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host)
            && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new \InvalidArgumentException('Invalid WHMCS host.');
        }
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($path !== '' && (!preg_match('#^/[A-Za-z0-9/._~-]*$#D', $path)
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path))) {
            throw new \InvalidArgumentException('Invalid WHMCS base path.');
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return 'https://' . $host . $port . rtrim($path, '/') . self::ENDPOINT;
    }

    /** Load the secret from a local private file, never a CLI argument or env value. */
    public static function fromEnvironment()
    {
        $path = (string) getenv('CH247_AGENT_SECRET_FILE');
        if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path)
            || fileperms($path) === false || (fileperms($path) & 0077) !== 0
            || filesize($path) === false || filesize($path) > 4096) {
            throw new \RuntimeException('A private agent secret file (mode 0600) is required.');
        }
        $secret = file_get_contents($path);
        if ($secret === false) {
            throw new \RuntimeException('The agent secret file cannot be read.');
        }
        return new self(getenv('CH247_AGENT_WHMCS_URL'), getenv('CH247_AGENT_UUID'),
            rtrim($secret, "\r\n"), getenv('CH247_AGENT_VERSION') ?: '1.0.0');
    }

    /** Sign the exact JSON bytes that will be sent on the wire. */
    public function buildRequest($uptimeSeconds = null)
    {
        $fields = ['agent_version' => $this->version];
        if ($uptimeSeconds !== null) {
            if (!is_int($uptimeSeconds) || $uptimeSeconds < 0 || $uptimeSeconds > 2147483647) {
                throw new \InvalidArgumentException('Uptime must be a bounded nonnegative integer in seconds.');
            }
            $fields['uptime_seconds'] = $uptimeSeconds;
        }
        $body = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = "POST\n" . self::SIGNING_PATH . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, $this->secret);
        return [
            'url' => $this->url,
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-CH247-Agent-Uuid' => $this->uuid,
                'X-CH247-Timestamp' => (string) $timestamp,
                'X-CH247-Nonce' => $nonce,
                'X-CH247-Signature' => $signature,
                'X-CH247-Agent-Version' => $this->version,
            ],
        ];
    }

    /** Send once; never retry a signed request, and never log response bodies. */
    public function send($uptimeSeconds = null)
    {
        $request = $this->buildRequest($uptimeSeconds);
        $response = $this->transport !== null
            ? call_user_func($this->transport, $request) : self::sendHttp($request);
        $status = is_array($response) && isset($response['status']) ? (int) $response['status'] : 0;
        if ($status !== 200) {
            throw new \RuntimeException('Heartbeat was not accepted (HTTP ' . $status . ').');
        }
        $body = isset($response['body']) && is_string($response['body']) ? $response['body'] : '';
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])
            || !in_array($decoded['data']['status'] ?? '', ['online', 'degraded', 'maintenance'], true)
            || !isset($decoded['data']['recorded_at'])
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) $decoded['data']['recorded_at'])) {
            throw new \RuntimeException('Heartbeat response was invalid.');
        }
        return ['status' => $decoded['data']['status'], 'recorded_at' => $decoded['data']['recorded_at']];
    }

    private static function sendHttp(array $request)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL is required for heartbeat delivery.');
        }
        $ch = curl_init($request['url']);
        if ($ch === false) {
            throw new \RuntimeException('Heartbeat transport is unavailable.');
        }
        $headers = [];
        foreach ($request['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $response = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request['body'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$response) {
                if (strlen($response) + strlen($chunk) > 4096) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // Do not include libcurl's error string: proxy or provider responses
        // may contain operator configuration or identifying information.
        if ($ok === false) {
            throw new \RuntimeException('Heartbeat transport failed.');
        }
        return ['status' => $status, 'body' => $response];
    }
}
