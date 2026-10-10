<?php
/**
 * OpenAI-compatible chat-completions provider. Works against any endpoint
 * implementing that contract (OpenAI, Azure OpenAI, Anthropic-compat proxies,
 * self-hosted vLLM/Ollama gateways...). The prompt demands strict JSON and
 * the answer is validated before it is stored.
 *
 * @package Chs\Providers\Ai
 */

namespace Chs\Providers\Ai;

use Chs\Core\ProviderException;
use Chs\Core\ProviderNotConfiguredException;
use Chs\Core\Settings;

class HttpAiProvider implements AiProviderInterface
{
    /** @var callable|null test seam — see HttpValuationEngine::post */
    private $poster;

    public function __construct(callable $poster = null)
    {
        $this->poster = $poster;
    }

    public function providerId()
    {
        return 'http';
    }

    public function isConfigured()
    {
        return Settings::bool('ai_enabled', false)
            && Settings::string('ai_endpoint', '') !== ''
            && Settings::string('ai_model', '') !== ''
            && Settings::string('ai_api_key', '') !== '';
    }

    public function generateSiteOutline($brief, array $opts = [])
    {
        if (!$this->isConfigured()) {
            throw new ProviderNotConfiguredException(
                'The AI website builder is not configured. An administrator must set ai_enabled, '
                . 'ai_endpoint, ai_model and the CHS_AI_API_KEY environment variable.'
            );
        }

        $industry = isset($opts['industry']) ? (string) $opts['industry'] : '';
        $locale = isset($opts['locale']) ? (string) $opts['locale'] : 'en';
        $pagesWanted = isset($opts['pages']) && (int) $opts['pages'] > 0 ? (int) $opts['pages'] : 4;

        $system = 'You are a senior web strategist and copywriter. Given a business brief you reply with '
            . 'STRICT JSON ONLY (no markdown fences) matching this schema: '
            . '{"site_title":string,"tagline":string,"pages":[{"slug":string,"title":string,'
            . '"purpose":string,"sections":[{"type":string,"heading":string,"body":string,'
            . '"cta":string|null}]}],"seo":{"title":string,"description":string,"keywords":[string]},'
            . '"design":{"palette":[string],"mood":string,"typography":string},'
            . '"images":[string]}. Sections must cover hero, features/services, about, testimonial or '
            . 'proof, and contact. Copy must be original, professional and free of placeholder text.';

        $user = 'Brief: ' . $brief
            . ($industry !== '' ? "\nIndustry: " . $industry : '')
            . "\nLanguage: " . $locale
            . "\nPages wanted: " . $pagesWanted;

        $payload = json_encode([
            'model'    => Settings::string('ai_model'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.7,
        ]);

        $endpoint = rtrim(Settings::string('ai_endpoint'), '/') . '/chat/completions';
        $response = $this->post($endpoint, [
            'Authorization: Bearer ' . Settings::string('ai_api_key'),
            'Content-Type: application/json',
        ], $payload, max(10, Settings::int('ai_timeout_seconds', 60)));

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new ProviderException('The AI provider answered HTTP ' . $response['code'] . '.');
        }

        $envelope = json_decode($response['body'], true);
        $content = isset($envelope['choices'][0]['message']['content'])
            ? $envelope['choices'][0]['message']['content'] : null;
        if (!is_string($content) || trim($content) === '') {
            throw new ProviderException('The AI provider returned no content.');
        }

        $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content));
        $outline = json_decode($content, true);
        if (!is_array($outline) || empty($outline['pages']) || !is_array($outline['pages'])) {
            throw new ProviderException('The AI provider returned content that was not a valid site outline.');
        }

        return $this->normalise($outline);
    }

    /** Whitelist schema keys and cap sizes defensively. */
    protected function normalise(array $outline)
    {
        $pages = [];
        foreach (array_slice($outline['pages'], 0, 8) as $page) {
            if (empty($page['title'])) {
                continue;
            }
            $sections = [];
            if (!empty($page['sections']) && is_array($page['sections'])) {
                foreach (array_slice($page['sections'], 0, 12) as $section) {
                    $sections[] = [
                        'type'    => substr((string) (isset($section['type']) ? $section['type'] : 'content'), 0, 24),
                        'heading' => substr((string) (isset($section['heading']) ? $section['heading'] : ''), 0, 190),
                        'body'    => substr((string) (isset($section['body']) ? $section['body'] : ''), 0, 4000),
                        'cta'     => isset($section['cta']) ? substr((string) $section['cta'], 0, 80) : null,
                    ];
                }
            }
            $pages[] = [
                'slug'     => preg_replace('/[^a-z0-9-]/', '-', strtolower((string) (isset($page['slug']) ? $page['slug'] : $page['title']))),
                'title'    => substr((string) $page['title'], 0, 120),
                'purpose'  => substr((string) (isset($page['purpose']) ? $page['purpose'] : ''), 0, 300),
                'sections' => $sections,
            ];
        }
        if (!$pages) {
            throw new ProviderException('The AI provider returned an outline with no usable pages.');
        }

        $seo = isset($outline['seo']) && is_array($outline['seo']) ? $outline['seo'] : [];
        $design = isset($outline['design']) && is_array($outline['design']) ? $outline['design'] : [];

        return [
            'site_title' => substr((string) (isset($outline['site_title']) ? $outline['site_title'] : 'New website'), 0, 120),
            'tagline'    => substr((string) (isset($outline['tagline']) ? $outline['tagline'] : ''), 0, 190),
            'pages'      => $pages,
            'seo'        => [
                'title'       => substr((string) (isset($seo['title']) ? $seo['title'] : ''), 0, 120),
                'description' => substr((string) (isset($seo['description']) ? $seo['description'] : ''), 0, 300),
                'keywords'    => array_slice(array_map('strval', isset($seo['keywords']) && is_array($seo['keywords']) ? $seo['keywords'] : []), 0, 12),
            ],
            'design' => [
                'palette'    => array_slice(array_map('strval', isset($design['palette']) && is_array($design['palette']) ? $design['palette'] : []), 0, 6),
                'mood'       => substr((string) (isset($design['mood']) ? $design['mood'] : 'professional'), 0, 60),
                'typography' => substr((string) (isset($design['typography']) ? $design['typography'] : ''), 0, 120),
            ],
            'images' => array_slice(array_map('strval', isset($outline['images']) && is_array($outline['images']) ? $outline['images'] : []), 0, 10),
        ];
    }

    protected function post($url, array $headers, $body, $timeout)
    {
        if ($this->poster) {
            return call_user_func($this->poster, $url, $headers, $body, $timeout);
        }
        // C-5: file_get_contents() would accept file:// and other wrappers; allow http(s) only.
        $scheme = strtolower((string) parse_url((string) $url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('AI endpoint must be an http or https URL.');
        }
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);
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
