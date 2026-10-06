<?php
/**
 * Best-effort parser for raw whois responses across registry formats.
 *
 * The parser is deliberately conservative: it surfaces what the registry
 * published, flags privacy protection when present, and never invents data.
 *
 * @package Chs\Providers\Whois
 */

namespace Chs\Providers\Whois;

class WhoisParser
{
    const REDACTION_MARKERS = [
        'redacted for privacy',
        'redacted | eu registrant',
        'gdpr masked',
        'data protected',
        'privacy protect',
        'whois privacy',
        'withheld for privacy',
        'contact privacy inc',
        'domains by proxy',
        'perfect privacy',
        'non-public data',
        'statutory masking enabled',
    ];

    /**
     * @return array{
     *   registrar:?string, created:?string, updated:?string, expires:?string,
     *   statuses:string[], nameservers:string[], registrant_org:?string,
     *   privacy_protected:bool, dnssec:?string, raw_available:bool
     * }
     */
    public static function parse($raw)
    {
        $out = [
            'registrar'         => null,
            'created'           => null,
            'updated'           => null,
            'expires'           => null,
            'statuses'          => [],
            'nameservers'       => [],
            'registrant_org'    => null,
            'abuse_email'       => null,
            'privacy_protected' => false,
            'dnssec'            => null,
            'raw_available'     => is_string($raw) && trim($raw) !== '',
        ];

        if (!$out['raw_available']) {
            return $out;
        }

        $lower = strtolower($raw);
        foreach (self::REDACTION_MARKERS as $marker) {
            if (strpos($lower, $marker) !== false) {
                $out['privacy_protected'] = true;
                break;
            }
        }

        $grab = function (array $keys) use ($raw) {
            foreach ($keys as $key) {
                if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*:\s*(.+)\s*$/mi', $raw, $m)) {
                    $value = trim($m[1]);
                    if ($value !== '' && stripos($value, 'redacted') === false) {
                        return $value;
                    }
                }
            }
            return null;
        };

        $grabAll = function (array $keys) use ($raw) {
            $found = [];
            foreach ($keys as $key) {
                if (preg_match_all('/^\s*' . preg_quote($key, '/') . '\s*:\s*(.+)\s*$/mi', $raw, $m)) {
                    foreach ($m[1] as $v) {
                        $v = trim($v);
                        if ($v !== '') {
                            $found[] = $v;
                        }
                    }
                }
            }
            return array_values(array_unique($found));
        };

        $out['registrar'] = $grab(['Registrar', 'Sponsoring Registrar', 'registrar-name', 'registrar_name']);
        $out['created']   = self::normaliseDate($grab(['Creation Date', 'created', 'Created On', 'Registration Date', 'registered', 'created date']));
        $out['updated']   = self::normaliseDate($grab(['Updated Date', 'updated', 'Last Updated On', 'last-modified', 'modified']));
        $out['expires']   = self::normaliseDate($grab([
            'Registry Expiry Date', 'Registrar Registration Expiration Date', 'Expiry Date', 'Expiration Date',
            'paid-till', 'expires', 'Registry Expiry Date',
        ]));
        $out['registrant_org'] = $grab(['Registrant Organization', 'Registrant Organisation', 'Registrant', 'org']);
        if ($out['registrant_org'] !== null && preg_match('/privacy|protect|redacted|proxy/i', $out['registrant_org'])) {
            $out['privacy_protected'] = true;
            $out['registrant_org'] = null;
        }
        $out['statuses']    = array_slice($grabAll(['Domain Status', 'status']), 0, 12);
        $out['nameservers'] = array_slice(array_map(function ($ns) {
            return strtolower(preg_replace('/\s+.*$/', '', $ns));
        }, $grabAll(['Name Server', 'Nameserver', 'nserver'])), 0, 12);
        $out['abuse_email'] = $grab(['Registrar Abuse Contact Email']);
        $dnssec = $grab(['DNSSEC', 'dnssec']);
        $out['dnssec'] = $dnssec !== null ? $dnssec : null;

        // A response with no registrant block at all is, by current registry
        // policy, privacy-limited — say so rather than printing blanks.
        if ($out['registrant_org'] === null && $out['registrar'] === null
            && !$out['created'] && $out['raw_available']) {
            $out['privacy_protected'] = true;
        }

        return $out;
    }

    /** Convert the many registry date spellings to Y-m-d H:i:s (UTC) or null. */
    protected static function normaliseDate($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime(trim($value));
        if ($ts === false || $ts < 315532800 || $ts > 4102444800) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }
}
