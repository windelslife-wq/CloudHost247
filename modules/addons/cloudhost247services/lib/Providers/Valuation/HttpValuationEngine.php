<?php
/**
 * External valuation engine adapter: posts the domain to a configured
 * valuation endpoint and normalises the answer into the module's result
 * shape. Enabled only when valuation_engine=http and both the endpoint URL
 * and the CHS_VALUATION_API_KEY environment variable are set — until then the
 * module stays on the built-in rules engine and says so honestly.
 *
 * @package Chs\Providers\Valuation
 */

namespace Chs\Providers\Valuation;

use Chs\Core\DomainName;
use Chs\Core\ProviderNotConfiguredException;
use Chs\Core\ProviderException;
use Chs\Core\Settings;

class HttpValuationEngine implements ValuationEngineInterface
{
    /** @var callable|null fn(url, headers, body, timeout): array{code:int, body:string} — test seam */
    private $poster;

    public function __construct(callable $poster = null)
    {
        $this->poster = $poster;
    }

    public function engineId()
    {
        return 'http';
    }

    public function engineVersion()
    {
        return '1.0';
    }

    public static function isConfigured()
    {
        return Settings::string('valuation_engine', 'rules') === 'http'
            && Settings::string('valuation_api_url', '') !== ''
            && Settings::string('valuation_api_key', '') !== '';
    }

    public function evaluate(DomainName $domain, array $context = [])
    {
        $url = Settings::string('valuation_api_url', '');
        $key = Settings::string('valuation_api_key', '');
        if ($url === '' || $key === '') {
            throw new ProviderNotConfiguredException(
                'The external valuation provider is not configured; set the endpoint and CHS_VALUATION_API_KEY.'
            );
        }

        $payload = json_encode([
            'domain'   => $domain->fqdn(),
            'sld'      => $domain->sld(),
            'tld'      => $domain->tld(),
            'currency' => isset($context['currency']) ? $context['currency'] : 'USD',
        ]);

        $response = $this->post($url, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $payload, 15);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new ProviderException('The valuation provider returned HTTP ' . $response['code'] . '.');
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['estimate_minor'])) {
            throw new ProviderException('The valuation provider returned an unreadable payload.');
        }

        $estimate = (int) $data['estimate_minor'];
        if ($estimate <= 0) {
            throw new ProviderException('The valuation provider returned a non-positive estimate.');
        }

        return [
            'estimate_minor' => min($estimate, RuleBasedValuationEngine::CEILING_MINOR),
            'currency'       => isset($data['currency']) ? strtoupper((string) $data['currency']) : 'USD',
            'score'          => isset($data['score']) ? max(1, min(99, (int) $data['score'])) : 50,
            'breakdown'      => isset($data['breakdown']) && is_array($data['breakdown']) ? $data['breakdown'] : [],
            'confidence'     => isset($data['confidence']) ? (string) $data['confidence'] : 'medium',
            'summary'        => isset($data['summary']) ? (string) $data['summary'] : '',
        ];
    }

    protected function post($url, array $headers, $body, $timeout)
    {
        if ($this->poster) {
            return call_user_func($this->poster, $url, $headers, $body, $timeout);
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
        $body = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $code = (int) $m[1];
                    break;
                }
            }
        }
        if ($body === false) {
            throw new ProviderException('The valuation provider could not be reached.');
        }
        return ['code' => $code ?: 200, 'body' => $body];
    }
}
