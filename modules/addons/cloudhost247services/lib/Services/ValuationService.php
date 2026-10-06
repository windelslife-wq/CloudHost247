<?php
/**
 * Domain valuation.
 *
 * Estimates come from the configured engine (built-in transparent rules by
 * default; an external market-data engine when one is installed) and every
 * estimate is stored with its full factor breakdown, so historical estimates
 * stay auditable after engine upgrades.
 *
 * The disclaimer travels with every result and is rendered wherever an
 * estimate appears — an estimate is never a selling-price promise.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Http;
use Chs\Core\DomainName;
use Chs\Core\Identity;
use Chs\Core\Platform;
use Chs\Core\RateLimiter;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\Settings;
use Chs\Providers\Valuation\HttpValuationEngine;
use Chs\Providers\Valuation\RuleBasedValuationEngine;
use Chs\Providers\Valuation\ValuationEngineInterface;

class ValuationService
{
    const DISCLAIMER = 'This is an automated estimate only, not a guaranteed selling price. '
        . 'Real market value depends on buyer demand, timing, negotiation and many factors no '
        . 'automated model can fully capture. Treat this figure as an orientation, and consider a '
        . 'professional appraisal or our Domain Broker service before making financial decisions.';

    /** @var ValuationEngineInterface|null test seam */
    private $engine;

    public function __construct(ValuationEngineInterface $engine = null)
    {
        $this->engine = $engine;
    }

    protected function engine()
    {
        if ($this->engine !== null) {
            return $this->engine;
        }
        if (HttpValuationEngine::isConfigured()) {
            return new HttpValuationEngine();
        }
        return new RuleBasedValuationEngine();
    }

    /** Engine metadata for the admin console and result pages. */
    public function engineInfo()
    {
        $engine = $this->engine();
        return [
            'id'        => $engine->engineId(),
            'version'   => $engine->engineVersion(),
            'is_fallback' => $engine instanceof RuleBasedValuationEngine
                && Settings::string('valuation_engine', 'rules') === 'http',
        ];
    }

    /**
     * @return array the stored valuation row (with decoded breakdown)
     */
    public function estimate($domainInput, $clientId = null)
    {
        if (!Settings::bool('valuation_enabled', true)) {
            throw new ServiceUnavailableException('Domain valuation is temporarily unavailable.');
        }

        $limit = $clientId
            ? Settings::int('valuation_client_daily_limit', 50)
            : Settings::int('valuation_guest_daily_limit', 10);
        RateLimiter::hitOrFail('valuation', RateLimiter::bucketForCurrentRequest($clientId), $limit, 86400);

        $domain = DomainName::parse($domainInput);

        $currency = $clientId
            ? Platform::gateway()->clientCurrency($clientId)
            : Platform::gateway()->defaultCurrency();

        $engine = $this->engine();
        $result = $engine->evaluate($domain, ['currency' => $currency]);

        $id = Db::insert('valuations', [
            'client_id'      => $clientId ? (int) $clientId : null,
            'domain'         => $domain->fqdn(),
            'sld'            => $domain->sld(),
            'tld'            => $domain->tld(),
            'estimate_minor' => (int) $result['estimate_minor'],
            'currency'       => strtoupper(substr((string) $result['currency'], 0, 3)),
            'engine'         => $engine->engineId(),
            'engine_version' => $engine->engineVersion(),
            'score'          => (int) $result['score'],
            'breakdown'      => json_encode($result['breakdown'], JSON_UNESCAPED_SLASHES),
            'ip_hash'        => \Chs\Core\Str::pseudonym(Http::clientIp()),
            'created_at'     => Clock::now(),
        ]);

        Audit::log($clientId ? Audit::ACTOR_CLIENT : Audit::ACTOR_SYSTEM, (int) $clientId, 'valuation.estimate', [
            'domain' => $domain->fqdn(),
            'engine' => $engine->engineId(),
        ], Http::clientIp());

        $row = Db::first('valuations', ['id' => $id]);
        $row['breakdown'] = json_decode((string) $row['breakdown'], true);
        $row['confidence'] = $result['confidence'];
        $row['summary'] = $result['summary'];
        $row['disclaimer'] = self::DISCLAIMER;
        return $row;
    }

    /** @return array[] most recent first, newest 50 */
    public function history($clientId, $limit = 50)
    {
        $rows = Db::all('valuations', ['client_id' => (int) $clientId], 'id DESC', (int) $limit);
        foreach ($rows as &$row) {
            $row['breakdown'] = json_decode((string) $row['breakdown'], true);
            $row['disclaimer'] = self::DISCLAIMER;
        }
        unset($row);
        return $rows;
    }

    /** Recent estimates for the admin report (no client identity columns chosen). */
    public function recentForAdmin($limit = 100)
    {
        $rows = Db::all('valuations', [], 'id DESC', (int) $limit);
        foreach ($rows as &$row) {
            unset($row['ip_hash']);
            $row['breakdown'] = json_decode((string) $row['breakdown'], true);
        }
        unset($row);
        return $rows;
    }
}
