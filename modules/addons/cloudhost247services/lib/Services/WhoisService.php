<?php
/**
 * Public WHOIS lookup.
 *
 * Answers come from the registry's own whois server, verbatim: whatever the
 * registry publishes we show, whatever it redacts stays redacted. The parser
 * detects privacy/GDPR patterns and tells the user plainly when registrant
 * data is withheld, instead of showing an empty form.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\DomainName;
use Chs\Core\Http;
use Chs\Core\RateLimiter;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Providers\Whois\CachedWhoisProvider;
use Chs\Providers\Whois\WhoisParser;
use Chs\Providers\Whois\WhoisProviderInterface;

class WhoisService
{
    /** @var WhoisProviderInterface */
    private $provider;

    public function __construct(WhoisProviderInterface $provider = null)
    {
        $this->provider = $provider;
    }

    protected function provider()
    {
        if ($this->provider === null) {
            $this->provider = new CachedWhoisProvider(new \Chs\Providers\Whois\SocketWhoisProvider());
        }
        return $this->provider;
    }

    /**
     * @return array{domain:string, tld:string, server:string, parsed:array,
     *               raw:string, privacy_protected:bool, cached:bool}
     */
    public function lookup($domainInput)
    {
        if (!Settings::bool('whois_enabled', true)) {
            throw new ServiceUnavailableException('WHOIS lookup is temporarily unavailable.');
        }

        RateLimiter::hitOrFail(
            'whois',
            RateLimiter::bucketForCurrentRequest(),
            Settings::int('whois_daily_limit_per_ip', 60),
            86400
        );

        $domain = DomainName::parse($domainInput);
        $raw = $this->provider()->lookup($domain->fqdn(), $domain->tld());
        $parsed = WhoisParser::parse($raw['raw']);

        Audit::system('whois.lookup', ['domain' => $domain->fqdn(), 'server' => $raw['server']]);

        return [
            'domain'            => $domain->fqdn(),
            'tld'               => $domain->tld(),
            'server'            => $raw['server'],
            'parsed'            => $parsed,
            'raw'               => $raw['raw'],
            'privacy_protected' => $parsed['privacy_protected'],
        ];
    }
}
