<?php
/**
 * Caching decorator — whois data changes slowly, registries rate-limit
 * aggressively, and our own cache makes both sides happier.
 *
 * @package Chs\Providers\Whois
 */

namespace Chs\Providers\Whois;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Settings;

class CachedWhoisProvider implements WhoisProviderInterface
{
    /** @var WhoisProviderInterface */
    private $inner;

    public function __construct(WhoisProviderInterface $inner)
    {
        $this->inner = $inner;
    }

    public function lookup($domain, $tld)
    {
        if (Db::tableExists('whois_cache')) {
            $row = Db::first('whois_cache', ['domain' => $domain]);
            if ($row && $row['expires_at'] && !Clock::isPast($row['expires_at'])) {
                return ['server' => $row['server'], 'raw' => (string) $row['raw']];
            }
        }

        $result = $this->inner->lookup($domain, $tld);

        if (Db::tableExists('whois_cache')) {
            $payload = [
                'server'     => $result['server'],
                'raw'        => $result['raw'],
                'parsed'     => json_encode(WhoisParser::parse($result['raw'])),
                'fetched_at' => Clock::now(),
                'expires_at' => Clock::in(max(5, Settings::int('whois_cache_minutes', 120)) * 60),
            ];
            $existing = Db::first('whois_cache', ['domain' => $domain]);
            if ($existing) {
                Db::update('whois_cache', ['domain' => $domain], $payload);
            } else {
                Db::insert('whois_cache', array_merge(['domain' => $domain, 'tld' => $tld], $payload));
            }
        }

        return $result;
    }
}
