<?php
/**
 * AI Website Builder orchestration.
 *
 * The service only ever uses the configured provider: when no provider is
 * configured it reports exactly which settings are missing (and never fakes a
 * generation). When configured, briefs go to the provider, the structured
 * outline it returns is validated, stored and rendered for the customer, who
 * can then iterate.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\NotFoundException;
use Chs\Core\ProviderNotConfiguredException;
use Chs\Core\RateLimiter;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Providers\Ai\AiProviderInterface;
use Chs\Providers\Ai\HttpAiProvider;
use Chs\Providers\Ai\NullAiProvider;

class AiBuilderService
{
    /** @var AiProviderInterface|null test seam */
    private $provider;

    public function __construct(AiProviderInterface $provider = null)
    {
        $this->provider = $provider;
    }

    protected function provider()
    {
        if ($this->provider === null) {
            $http = new HttpAiProvider();
            $this->provider = $http->isConfigured() ? $http : new NullAiProvider();
        }
        return $this->provider;
    }

    /** Drives the honest "configuration required" UI and the admin checklist. */
    public function status()
    {
        $missing = [];
        if (!Settings::bool('ai_enabled', false)) {
            $missing[] = 'ai_enabled';
        }
        foreach (['ai_endpoint', 'ai_model'] as $key) {
            if (Settings::string($key, '') === '') {
                $missing[] = $key;
            }
        }
        if (Settings::string('ai_api_key', '') === '') {
            $missing[] = 'CHS_AI_API_KEY (environment variable)';
        }
        return [
            'configured' => $missing === [],
            'missing'    => $missing,
            'provider'   => $this->provider()->providerId(),
        ];
    }

    /**
     * Generate a structured website outline from a natural-language brief.
     *
     * @return array stored generation row (result decoded)
     */
    public function generate($clientId, $brief, array $opts = [])
    {
        $started = microtime(true);

        if (!Settings::bool('ai_enabled', false) || !$this->provider()->isConfigured()) {
            throw new ProviderNotConfiguredException(
                'The AI website builder needs an administrator to finish its setup first. '
                . 'You can still use our professional services team to get the same result.');
        }
        RateLimiter::hitOrFail('ai_generate', 'client:' . (int) $clientId,
            Settings::int('ai_daily_limit_per_client', 10), 86400);

        $brief = trim((string) $brief);
        if (mb_strlen($brief, 'UTF-8') < 20) {
            throw new ValidationException(['brief' =>
                'Describe the website you want in a sentence or two (at least 20 characters).']);
        }
        if (mb_strlen($brief, 'UTF-8') > 500) {
            $brief = mb_substr($brief, 0, 500, 'UTF-8');
        }

        $outline = $this->provider()->generateSiteOutline($brief, [
            'industry' => isset($opts['industry']) ? (string) $opts['industry'] : '',
            'locale'   => isset($opts['locale']) ? (string) $opts['locale'] : 'en',
            'pages'    => isset($opts['pages']) ? (int) $opts['pages'] : 4,
        ]);

        $id = Db::insert('ai_generations', [
            'client_id'   => (int) $clientId,
            'prompt'      => $brief,
            'industry'    => isset($opts['industry']) ? substr((string) $opts['industry'], 0, 64) : '',
            'status'      => 'complete',
            'result'      => json_encode($outline, JSON_UNESCAPED_SLASHES),
            'provider'    => $this->provider()->providerId(),
            'model'       => Settings::string('ai_model', ''),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'created_at'  => Clock::now(),
        ]);

        \Chs\Core\Audit::client((int) $clientId, 'ai.generated', ['generation' => $id]);
        return $this->getFor($clientId, $id);
    }

    public function getFor($clientId, $generationId)
    {
        $row = Db::first('ai_generations', ['id' => (int) $generationId, 'client_id' => (int) $clientId]);
        if (!$row) {
            throw new NotFoundException('Generation not found.');
        }
        $row['result'] = json_decode((string) $row['result'], true);
        return $row;
    }

    /** @return array[] newest first, results kept light for the list */
    public function history($clientId, $limit = 20)
    {
        $rows = Db::all('ai_generations', ['client_id' => (int) $clientId], 'id DESC', (int) $limit);
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['result'], true);
            $row['summary'] = is_array($decoded)
                ? ['site_title' => isset($decoded['site_title']) ? $decoded['site_title'] : '', 'pages' => count(isset($decoded['pages']) ? $decoded['pages'] : [])]
                : ['site_title' => '', 'pages' => 0];
            unset($row['result']);
        }
        unset($row);
        return $rows;
    }
}
