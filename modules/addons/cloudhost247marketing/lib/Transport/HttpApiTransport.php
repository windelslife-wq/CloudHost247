<?php
/**
 * HTTP API transport for the common ESPs.
 *
 * One class, four payload shapes. Using an ESP's HTTP API rather than its SMTP
 * relay is what gives you real bounce and complaint webhooks, so this is the
 * recommended production path for anything above a few thousand recipients.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Transport;

use Ch247Mkt\Core\Logger;

class HttpApiTransport implements TransportInterface
{
    public const PROVIDERS = ['sendgrid', 'mailgun', 'postmark', 'generic'];

    public const ENDPOINTS = [
        'sendgrid' => 'https://api.sendgrid.com/v3/mail/send',
        'postmark' => 'https://api.postmarkapp.com/email',
        'mailgun'  => 'https://api.mailgun.net/v3/{domain}/messages',
    ];

    protected $provider;
    protected $apiKey;
    protected $endpoint;
    protected $region;
    protected $timeout;

    public function __construct(array $config)
    {
        $this->provider = in_array($config['provider'] ?? '', self::PROVIDERS, true) ? $config['provider'] : 'generic';
        $this->apiKey   = (string) ($config['api_key'] ?? '');
        $this->region   = trim((string) ($config['region'] ?? ''));
        $this->timeout  = max(5, (int) ($config['timeout'] ?? 20));
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        if ($endpoint === '' && isset(self::ENDPOINTS[$this->provider])) {
            $endpoint = self::ENDPOINTS[$this->provider];
        }
        if ($this->provider === 'mailgun') {
            $endpoint = str_replace('{domain}', rawurlencode($this->region), $endpoint);
            if ($this->region !== '' && strpos($this->region, 'eu') === 0) {
                $endpoint = str_replace('api.mailgun.net', 'api.eu.mailgun.net', $endpoint);
            }
        }
        $this->endpoint = $endpoint;
    }

    public function name()
    {
        return 'api:' . $this->provider;
    }

    public function isConfigured()
    {
        if ($this->apiKey === '' || $this->endpoint === '') {
            return false;
        }
        if ($this->provider === 'mailgun' && $this->region === '') {
            return false;
        }
        return true;
    }

    public function configurationProblem()
    {
        if ($this->apiKey === '') {
            return 'No provider API key is set.';
        }
        if ($this->endpoint === '') {
            return 'No provider endpoint is set.';
        }
        if ($this->provider === 'mailgun' && $this->region === '') {
            return 'Mailgun needs your sending domain in the "region / domain" field.';
        }
        return '';
    }

    public function send(Message $message)
    {
        if (!$this->isConfigured()) {
            return Result::permanent($this->configurationProblem());
        }
        if (!function_exists('curl_init')) {
            return Result::permanent('PHP cURL is not available; switch to the SMTP transport.');
        }

        list($headers, $body, $contentType) = $this->payload($message);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['Content-Type: ' . $contentType]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'CloudHost247-Marketing/1.0',
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return Result::transient('Provider request failed: ' . $error);
        }
        if ($status >= 200 && $status < 300) {
            return Result::success($this->messageId($response), ['status' => $status]);
        }
        if ($status === 429 || $status >= 500) {
            return Result::transient('Provider returned HTTP ' . $status . ': ' . substr((string) $response, 0, 300), ['status' => $status]);
        }
        Logger::warning('Provider rejected message', ['provider' => $this->provider, 'status' => $status]);
        return Result::permanent('Provider returned HTTP ' . $status . ': ' . substr((string) $response, 0, 300), 'hard', ['status' => $status]);
    }

    /** @return array{0:string[],1:string|array,2:string} headers, body, content type */
    protected function payload(Message $message)
    {
        $text = $message->text !== '' ? $message->text : \Ch247Mkt\Core\Str::htmlToText($message->html);

        switch ($this->provider) {
            case 'sendgrid':
                $payload = [
                    'personalizations' => [[
                        'to' => [array_filter(['email' => $message->toEmail, 'name' => $message->toName])],
                    ]],
                    'from'    => array_filter(['email' => $message->fromEmail, 'name' => $message->fromName]),
                    'subject' => $message->subject,
                    'content' => [
                        ['type' => 'text/plain', 'value' => $text],
                        ['type' => 'text/html', 'value' => $message->html],
                    ],
                ];
                if ($message->replyTo !== '') {
                    $payload['reply_to'] = ['email' => $message->replyTo];
                }
                if ($message->headers !== []) {
                    $payload['headers'] = $message->headers;
                }
                return [['Authorization: Bearer ' . $this->apiKey], json_encode($payload), 'application/json'];

            case 'postmark':
                $payload = array_filter([
                    'From'          => $message->fromHeader(),
                    'To'            => $message->toHeader(),
                    'Subject'       => $message->subject,
                    'HtmlBody'      => $message->html,
                    'TextBody'      => $text,
                    'ReplyTo'       => $message->replyTo,
                    'MessageStream' => $this->region !== '' ? $this->region : 'broadcast',
                ], 'strlen');
                if ($message->headers !== []) {
                    $payload['Headers'] = array_map(function ($name, $value) {
                        return ['Name' => $name, 'Value' => $value];
                    }, array_keys($message->headers), array_values($message->headers));
                }
                return [['X-Postmark-Server-Token: ' . $this->apiKey, 'Accept: application/json'], json_encode($payload), 'application/json'];

            case 'mailgun':
                $fields = array_filter([
                    'from'    => $message->fromHeader(),
                    'to'      => $message->toHeader(),
                    'subject' => $message->subject,
                    'html'    => $message->html,
                    'text'    => $text,
                    'h:Reply-To' => $message->replyTo,
                ], 'strlen');
                foreach ($message->headers as $name => $value) {
                    $fields['h:' . $name] = $value;
                }
                return [['Authorization: Basic ' . base64_encode('api:' . $this->apiKey)], http_build_query($fields), 'application/x-www-form-urlencoded'];
        }

        // Generic JSON shape for a self-hosted relay.
        $payload = [
            'from'     => ['email' => $message->fromEmail, 'name' => $message->fromName],
            'to'       => [['email' => $message->toEmail, 'name' => $message->toName]],
            'reply_to' => $message->replyTo,
            'subject'  => $message->subject,
            'html'     => $message->html,
            'text'     => $text,
            'headers'  => $message->headers,
        ];
        return [['Authorization: Bearer ' . $this->apiKey], json_encode($payload), 'application/json'];
    }

    protected function messageId($response)
    {
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return '';
        }
        foreach (['MessageID', 'message_id', 'id'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return trim((string) $data[$key], '<>');
            }
        }
        return '';
    }
}
