<?php
/**
 * Domain valuation engine abstraction. The rule-based engine ships in the
 * box; a market-data engine can be installed behind the same interface
 * (see HttpValuationEngine) without touching the service, the UI or the API.
 *
 * @package Chs\Providers\Valuation
 */

namespace Chs\Providers\Valuation;

use Chs\Core\DomainName;

interface ValuationEngineInterface
{
    /** Stable engine id stored with each valuation row. */
    public function engineId();

    public function engineVersion();

    /**
     * @return array{
     *   estimate_minor:int, currency:string, score:int,
     *   breakdown:array<int,array{key:string,label:string,detail:string,score:int,direction:string}>,
     *   confidence:string, summary:string
     * }
     */
    public function evaluate(DomainName $domain, array $context = []);
}
