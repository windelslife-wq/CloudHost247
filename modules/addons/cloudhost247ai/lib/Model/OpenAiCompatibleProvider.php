<?php
/**
 * OpenAI-compatible provider: works against any /chat/completions endpoint
 * (OpenAI, Azure proxies, self-hosted vLLM/Ollama gateways), so a fully
 * on-premise deployment is configuration, not code.
 */

namespace Ch247Ai\Model;

use Ch247Ai\Core\ProviderException;
use Ch247Ai\Core\ProviderNotConfiguredException;
use Ch247Ai\Core\Settings;

class OpenAiCompatibleProvider
{
    private $endpoint;
    private $model;
    /** @var callable|null test seam */
    private $poster;

    public function __construct($endpoint, $model, callable $poster = null)
    {
        $this->endpoint = rtrim((string) $endpoint, '/');
        $this->model = (string) $model;
        $this->poster = $poster;
    }
    public function providerId()
    {
        return 'openai-compatible:' . $this->model;
    }
    public function isConfigured()
    {
        return $this->endpoint !== '' && $this->model !== '';
    }
    public function model()
    {
        return $this->model;
    }

    /** @return array{content:string, tokens_in:int, tokens_out:int} */
    public function complete(array $messages, array $opts = [])
    {
        if (!$this->isConfigured()) {
            throw new ProviderNotConfiguredException('CONFIGURATION_REQUIRED: endpoint or model missing for this model profile.');
        }
        $payload = json_encode([
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => isset($opts['temperature']) ? (float) $opts['temperature'] : 0.2,
            'max_tokens' => isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : 900,
        ]);
        $headers = ['Content-Type: application/json'];
        $key = getenv('CH247AI_API_KEY');
        if ($key !== false && $key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $response = $this->post($this->endpoint . '/chat/completions', $headers, $payload, max(10, Settings::int('model_timeout_seconds', 60)));
        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new ProviderException('The AI provider answered HTTP ' . $response['code'] . '.');
        }
        $envelope = json_decode($response['body'], true);
        $content = isset($envelope['choices'][0]['message']['content']) ? $envelope['choices'][0]['message']['content'] : null;
        if (!is_string($content) || trim($content) === '') {
            throw new ProviderException('The AI provider returned no content.');
        }
        return [
            'content' => trim($content),
            'tokens_in' => isset($envelope['usage']['prompt_tokens']) ? (int) $envelope['usage']['prompt_tokens'] : 0,
            'tokens_out' => isset($envelope['usage']['completion_tokens']) ? (int) $envelope['usage']['completion_tokens'] : 0,
        ];
    }

    protected function post($url, array $headers, $body, $timeout)
    {
        if ($this->poster) {
            return call_user_func($this->poster, $url, $headers, $body, $timeout);
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
        $result = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $code = (int) $m[1];
                    break;
                }
            }
        }
        if ($result === false) {
            throw new ProviderException('The AI provider could not be reached.');
        }
        return ['code' => $code ?: 200, 'body' => $result];
    }
}
