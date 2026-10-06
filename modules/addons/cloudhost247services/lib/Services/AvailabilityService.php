<?php
/**
 * Domain availability lookups. Every answer comes from the platform's own
 * live lookup (the registrar/reseller module configured in WHMCS), cached
 * briefly to absorb page refreshes — never invented.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\Platform;
use Chs\Core\Settings;

class AvailabilityService
{
    /**
     * @return array{domain:string, available:?bool, status:string, cached:bool, source:string}
     */
    public function check(DomainName $domain, $allowCache = true)
    {
        $fqdn = $domain->fqdn();
        $key = 'avail:' . $fqdn;

        if ($allowCache && Db::tableExists('lookup_cache')) {
            $row = Db::first('lookup_cache', ['cache_key' => $key]);
            if ($row && $row['expires_at'] && !Clock::isPast($row['expires_at'])) {
                $payload = json_decode((string) $row['payload'], true);
                if (is_array($payload)) {
                    $payload['cached'] = true;
                    return $payload;
                }
            }
        }

        $answer = Platform::gateway()->domainAvailability($fqdn);

        $payload = [
            'domain'    => $fqdn,
            'available' => $answer['available'],
            'status'    => $answer['status'],
            'cached'    => false,
            'source'    => Platform::isLive() ? 'whmcs-live-lookup' : 'gateway',
        ];

        if (Db::tableExists('lookup_cache') && $answer['available'] !== null) {
            $ttl = max(1, Settings::int('lookup_cache_minutes', 10)) * 60;
            $record = [
                'kind'       => 'availability',
                'payload'    => json_encode($payload),
                'expires_at' => Clock::in($ttl),
                'created_at' => Clock::now(),
            ];
            $existing = Db::first('lookup_cache', ['cache_key' => $key]);
            if ($existing) {
                Db::update('lookup_cache', ['cache_key' => $key], $record);
            } else {
                Db::insert('lookup_cache', array_merge(['cache_key' => $key], $record));
            }
        }

        return $payload;
    }

    /**
     * On-demand alternative-extension suggestions, checked live and capped so
     * one page render can never fan out into a registrar flood.
     *
     * @return array[]
     */
    public function suggestions(DomainName $domain, $limit = 6)
    {
        $limit = (int) max(1, min(8, $limit));
        $out = [];
        $catalog = new TldCatalogService();
        foreach ($catalog->spotlight($limit + 1) as $tldRow) {
            if ($tldRow['tld'] === $domain->tld()) {
                continue;
            }
            $candidate = DomainName::tryParse($domain->sld() . '.' . $tldRow['tld']);
            if (!$candidate) {
                continue;
            }
            $check = $this->check($candidate);
            $out[] = array_merge($check, [
                'register_minor' => $tldRow['register_minor'],
                'badge'          => $tldRow['badge'],
            ]);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}
