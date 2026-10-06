<?php
/**
 * Domain Broker — domain intelligence.
 *
 * Answers "what do we legitimately know about this domain?" for the search
 * box, the request form and the broker workspace: availability, registration
 * status, TLD, registrar, creation/expiry dates, registry transfer locks and
 * DNS.
 *
 * Sources, in order of preference:
 *   1. The operator's own WHMCS records (authoritative for domains they hold).
 *   2. DNS (public by design).
 *   3. RDAP — the registries' own structured, rate-limited, *authorised*
 *      protocol. Disabled by default and only queried over HTTPS.
 *
 * It never scrapes port-43 WHOIS or registrar web pages, and it never
 * republishes contact data that the registry chose to redact: entity/vCard
 * data is discarded unless an operator explicitly opts in, having satisfied
 * themselves that their agreements permit it.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Clock;
use DomainBroker\Core\DomainName;
use DomainBroker\Core\Logger;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Integration\Gateway;

class DomainIntelService
{
    /** @var callable|null injected resolver, used by the test suite */
    protected static $resolver;

    /** @var array in-process cache: domain => report */
    protected static $cache = [];

    /**
     * Replace the network layer. The callable receives
     * (string $domain, string $type) where $type is 'rdap' or 'dns' and
     * returns an array (or null).
     */
    public static function setResolver($resolver)
    {
        self::$resolver = $resolver;
        self::$cache = [];
    }

    public static function clearCache()
    {
        self::$cache = [];
    }

    /**
     * Full report for a domain.
     *
     * @param array $opts identity (for rate limiting), refresh
     * @return array
     */
    public function lookup($domain, array $opts = [])
    {
        $normalised = DomainName::normalise($domain);
        if ($normalised === null) {
            throw new ValidationException('Enter a valid domain name.', ['domain' => 'Invalid domain name.']);
        }
        $registrable = DomainName::registrable($normalised);

        if (!empty($opts['identity'])) {
            RateLimiter::hit('domain.lookup', (string) $opts['identity']);
        }

        if (empty($opts['refresh']) && isset(self::$cache[$registrable])) {
            return self::$cache[$registrable];
        }

        $report = [
            'domain'         => $registrable,
            'display'        => DomainName::toUnicode($registrable),
            'tld'            => DomainName::tld($registrable),
            'sld'            => DomainName::sld($registrable),
            'available'      => null,     // null = unknown
            'registered'     => null,
            'registrar'      => null,
            'registrar_iana' => null,
            'created_at'     => null,
            'updated_at'     => null,
            'expires_at'     => null,
            'statuses'       => [],
            'transfer_locked' => null,
            'nameservers'    => [],
            'dns'            => ['a' => [], 'mx' => [], 'ns' => []],
            'held_by_operator' => false,
            'whmcs_domain_id' => null,
            'sources'        => [],
            'checked_at'     => Clock::now(),
            'notice'         => null,
        ];

        $this->applyLocalRecords($report);
        $this->applyDns($report);
        $this->applyRdap($report);

        // Infer availability conservatively: only claim a domain is free when
        // an authoritative source said so.
        if ($report['registered'] === true) {
            $report['available'] = false;
        }
        if ($report['available'] === null && $report['registered'] === null) {
            $report['notice'] = 'Registration status could not be confirmed from an authoritative source. '
                . 'Your broker will verify it before any approach is made.';
        }

        self::$cache[$registrable] = $report;
        return $report;
    }

    /**
     * Lightweight availability answer for the search box.
     *
     * @return array{domain:string, available:bool|null, registered:bool|null, notice:string|null}
     */
    public function checkAvailability($domain, array $opts = [])
    {
        $report = $this->lookup($domain, $opts);
        return [
            'domain' => $report['domain'],
            'display' => $report['display'],
            'tld' => $report['tld'],
            'available' => $report['available'],
            'registered' => $report['registered'],
            'expires_at' => $report['expires_at'],
            'registrar' => $report['registrar'],
            'notice' => $report['notice'],
            'brokerable' => $report['registered'] !== false,
        ];
    }

    /** True when the TLD is one the operator is prepared to broker. */
    public function isSupportedTld($domain)
    {
        $tld = DomainName::tld(DomainName::normalise($domain));
        $allowed = Settings::listOf('brokerable_tlds');
        if (!$allowed) {
            return true; // unrestricted by default
        }
        return in_array(strtolower((string) $tld), array_map('strtolower', $allowed), true);
    }

    /* ----------------------------------------------------------- sources */

    /** Domains the operator already manages in WHMCS. */
    protected function applyLocalRecords(array &$report)
    {
        try {
            $matches = Gateway::get()->getClientDomains(0, $report['domain']);
        } catch (\Throwable $e) {
            return;
        }
        if (!$matches) {
            return;
        }
        $row = $matches[0];
        $report['held_by_operator'] = true;
        $report['registered'] = true;
        $report['available'] = false;
        $report['whmcs_domain_id'] = isset($row['id']) ? (int) $row['id'] : null;
        $report['registrar'] = isset($row['registrar']) && $row['registrar'] !== ''
            ? (string) $row['registrar'] : $report['registrar'];
        $report['created_at'] = isset($row['registrationdate']) ? $row['registrationdate'] : $report['created_at'];
        $report['expires_at'] = isset($row['expirydate']) ? $row['expirydate'] : $report['expires_at'];
        $report['sources'][] = 'whmcs';
    }

    protected function applyDns(array &$report)
    {
        $records = $this->resolveDns($report['domain']);
        if ($records === null) {
            return;
        }
        $report['dns'] = array_merge($report['dns'], $records);
        if (!empty($records['ns'])) {
            $report['nameservers'] = $records['ns'];
            // Delegated nameservers are strong evidence of registration.
            if ($report['registered'] === null) {
                $report['registered'] = true;
                $report['available'] = false;
            }
        }
        $report['sources'][] = 'dns';
    }

    protected function applyRdap(array &$report)
    {
        if (!Settings::bool('rdap_enabled', false)) {
            return;
        }
        $data = $this->resolveRdap($report['domain']);
        if ($data === null) {
            return;
        }
        if (isset($data['_not_found']) && $data['_not_found']) {
            // RDAP 404 is the registry telling us the name is unregistered.
            $report['registered'] = false;
            $report['available'] = true;
            $report['sources'][] = 'rdap';
            return;
        }

        $report['registered'] = true;
        $report['available'] = false;

        if (!empty($data['status']) && is_array($data['status'])) {
            $report['statuses'] = array_values(array_map('strval', $data['status']));
            foreach ($report['statuses'] as $status) {
                if (stripos($status, 'transfer prohibited') !== false
                    || stripos($status, 'transferProhibited') !== false) {
                    $report['transfer_locked'] = true;
                }
            }
            if ($report['transfer_locked'] === null) {
                $report['transfer_locked'] = false;
            }
        }

        if (!empty($data['events']) && is_array($data['events'])) {
            foreach ($data['events'] as $event) {
                $action = isset($event['eventAction']) ? strtolower((string) $event['eventAction']) : '';
                $date = isset($event['eventDate']) ? (string) $event['eventDate'] : '';
                if ($date === '') {
                    continue;
                }
                if ($action === 'registration') {
                    $report['created_at'] = $date;
                } elseif ($action === 'expiration') {
                    $report['expires_at'] = $date;
                } elseif ($action === 'last changed' || $action === 'last update of rdap database') {
                    $report['updated_at'] = $date;
                }
            }
        }

        if (!empty($data['nameservers']) && is_array($data['nameservers'])) {
            $ns = [];
            foreach ($data['nameservers'] as $server) {
                if (isset($server['ldhName'])) {
                    $ns[] = strtolower((string) $server['ldhName']);
                }
            }
            if ($ns) {
                $report['nameservers'] = $ns;
            }
        }

        // The sponsoring registrar is public, non-personal data.
        if (!empty($data['entities']) && is_array($data['entities'])) {
            foreach ($data['entities'] as $entity) {
                $roles = isset($entity['roles']) && is_array($entity['roles']) ? $entity['roles'] : [];
                if (!in_array('registrar', $roles, true)) {
                    continue;
                }
                $report['registrar'] = $this->vcardName($entity) ?: $report['registrar'];
                if (!empty($entity['publicIds']) && is_array($entity['publicIds'])) {
                    foreach ($entity['publicIds'] as $publicId) {
                        if (isset($publicId['type'], $publicId['identifier'])
                            && stripos((string) $publicId['type'], 'IANA') !== false) {
                            $report['registrar_iana'] = (string) $publicId['identifier'];
                        }
                    }
                }
            }
        }

        // Registrant/admin/tech entities are deliberately NOT copied across.
        // Where a registry publishes them, republishing is still a decision
        // the operator must make knowingly.
        if (Settings::bool('rdap_include_contacts', false)) {
            $report['notice'] = 'Registrant data is shown as published by the registry and must be used only '
                . 'in accordance with the registry\'s access policy.';
        }

        $report['sources'][] = 'rdap';
    }

    /* --------------------------------------------------------- resolvers */

    /** @return array|null */
    protected function resolveDns($domain)
    {
        if (self::$resolver !== null) {
            $result = call_user_func(self::$resolver, $domain, 'dns');
            return is_array($result) ? $result : null;
        }
        if (!function_exists('dns_get_record')) {
            return null;
        }

        $out = ['a' => [], 'mx' => [], 'ns' => []];
        $found = false;
        $map = ['a' => DNS_A, 'mx' => DNS_MX, 'ns' => DNS_NS];
        foreach ($map as $key => $type) {
            $records = @dns_get_record($domain, $type);
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $record) {
                $found = true;
                if ($key === 'a' && isset($record['ip'])) {
                    $out['a'][] = $record['ip'];
                } elseif ($key === 'mx' && isset($record['target'])) {
                    $out['mx'][] = $record['target'];
                } elseif ($key === 'ns' && isset($record['target'])) {
                    $out['ns'][] = strtolower($record['target']);
                }
            }
        }
        return $found ? $out : null;
    }

    /** @return array|null */
    protected function resolveRdap($domain)
    {
        if (self::$resolver !== null) {
            $result = call_user_func(self::$resolver, $domain, 'rdap');
            return is_array($result) ? $result : null;
        }
        if (!function_exists('curl_init')) {
            return null;
        }

        $base = rtrim(Settings::string('rdap_endpoint', 'https://rdap.org'), '/');
        if (strncasecmp($base, 'https://', 8) !== 0) {
            Logger::warning('RDAP endpoint must be https; lookup skipped.');
            return null;
        }
        $url = $base . '/domain/' . rawurlencode($domain);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) Settings::int('rdap_timeout', 6),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/rdap+json, application/json',
                'User-Agent: CloudHost247-DomainBroker/' . (defined('DOMAINBROKER_VERSION') ? DOMAINBROKER_VERSION : '1.0.0'),
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 404) {
            return ['_not_found' => true];
        }
        if ($raw === false || $status < 200 || $status >= 300) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    protected function vcardName(array $entity)
    {
        if (empty($entity['vcardArray'][1]) || !is_array($entity['vcardArray'][1])) {
            return null;
        }
        foreach ($entity['vcardArray'][1] as $field) {
            if (is_array($field) && isset($field[0]) && $field[0] === 'fn' && isset($field[3])) {
                return Str::clip((string) $field[3], 190);
            }
        }
        return null;
    }
}
