<?php
/**
 * Domain search — the single-name flow.
 *
 *   normalize → validate syntax → TLD supported by the catalogue → provider
 *   availability check → server-side register/renew/transfer quote (Discount
 *   Club discount applied server-side) → persist + audit.
 *
 * Availability answers come only from the configured provider chain (WHMCS'
 * own lookup by default). When the provider for a TLD is not configured the
 * result says DOMAIN_PROVIDER_NOT_CONFIGURED with available=null — never a
 * guessed answer. Prices are the operator's own catalogue prices (WHMCS
 * tbldomainpricing/tblpricing via the TLD catalogue), never client-supplied.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\Http;
use Chs\Core\Platform;
use Chs\Core\ProviderException;
use Chs\Core\ProviderNotConfiguredException;
use Chs\Core\RateLimiter;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;
use Chs\Providers\Domain\ProviderRegistry;

class DomainSearchService
{
    /** @var ProviderRegistry|null test seam */
    private $registry;

    public function __construct(ProviderRegistry $registry = null)
    {
        $this->registry = $registry;
    }

    protected function registry()
    {
        if ($this->registry === null) {
            $this->registry = new ProviderRegistry();
        }
        return $this->registry;
    }

    /**
     * Interactive single-domain search (rate-limited, audited, persisted).
     *
     * @return array result row (see class docblock shape)
     */
    public function search($input, $clientId = null)
    {
        if (!Settings::bool('domain_search_enabled', true)) {
            throw new ServiceUnavailableException('Domain search is temporarily unavailable.');
        }

        $clientId = $clientId === null ? null : (int) $clientId;
        $limit = $clientId
            ? Settings::int('search_daily_limit_per_client', 100)
            : Settings::int('search_daily_limit_per_ip', 30);
        RateLimiter::hitOrFail('domain_search', RateLimiter::bucketForCurrentRequest($clientId), $limit, 86400);

        $domain = DomainName::parse($input); // throws ValidationException
        $currency = $clientId
            ? Platform::gateway()->clientCurrency($clientId)
            : Platform::gateway()->defaultCurrency();

        $catalog = new TldCatalogService();
        $tldRow = $catalog->detail($domain->tld());

        $result = $this->checkOne($domain, $tldRow, $clientId, $currency);

        // Persist: one search row + one result row.
        $searchId = Db::insert('domain_searches', [
            'client_id'      => $clientId,
            'ip_hash'        => Str::pseudonym(Http::clientIp() ?: 'cli'),
            'mode'           => 'single',
            'status'         => 'completed',
            'total'          => 1,
            'completed'      => 1,
            'currency'       => $result['currency'],
            'correlation_id' => Str::random(12),
            'created_at'     => Clock::now(),
            'completed_at'   => Clock::now(),
        ]);
        $this->persistResult($searchId, $result);

        $actorType = $clientId ? \Chs\Core\Audit::ACTOR_CLIENT : \Chs\Core\Audit::ACTOR_SYSTEM;
        (new DomainEventService())->record(DomainEventService::DOMAIN_SEARCHED, $domain->fqdn(),
            $actorType, (int) $clientId, [
                'available' => $result['available'],
                'status'    => $result['status'],
                'tld'       => $domain->tld(),
            ]);

        $result['search_id'] = $searchId;
        return $result;
    }

    /**
     * One availability+quote check. Used by the interactive search (above)
     * and by the bulk worker (no rate limit, no per-row audit there — the bulk
     * job is audited once, as a unit).
     *
     * @param DomainName    $domain
     * @param array|null    $tldRow  catalogue row for the TLD (null = not sold)
     * @param int|null      $clientId
     * @param string|null   $currency
     * @return array
     */
    public function checkOne(DomainName $domain, $tldRow = null, $clientId = null, $currency = null)
    {
        $clientId = $clientId === null ? null : (int) $clientId;
        $currency = $currency ?: ($clientId
            ? Platform::gateway()->clientCurrency($clientId)
            : Platform::gateway()->defaultCurrency());

        $fqdn = $domain->fqdn();
        $tld = $domain->tld();

        $result = [
            'domain'                 => $fqdn,
            'sld'                    => $domain->sld(),
            'tld'                    => $tld,
            'available'              => null,
            'status'                 => 'unknown',
            'provider'               => '',
            'provider_configured'    => false,
            'register_minor'         => null,
            'renew_minor'            => null,
            'transfer_minor'         => null,
            'register_final_minor'   => null,
            'renew_final_minor'      => null,
            'transfer_final_minor'   => null,
            'discount_percent'       => null,
            'currency'               => strtoupper(substr((string) $currency, 0, 3)),
            'tld_features'           => null,
            'add_to_cart_url'        => 'cart.php?a=add&domain=register&query=' . rawurlencode($fqdn),
            'transfer_url'           => 'cart.php?a=add&domain=transfer&query=' . rawurlencode($fqdn),
            'whois_url'              => 'whois-lookup.php?domain=' . rawurlencode($fqdn),
            'appraisal_url'          => 'domain-valuation.php?domain=' . rawurlencode($fqdn),
            'searched_at'            => Clock::now(),
        ];

        if (!$tldRow) {
            $result['status'] = 'unsupported_tld';
            return $result;
        }

        // Server-side quote from the operator's catalogue.
        $quote = $this->quoteFor($tldRow, $clientId);
        $result['register_minor']       = $quote['register_minor'];
        $result['renew_minor']          = $quote['renew_minor'];
        $result['transfer_minor']       = $quote['transfer_minor'];
        $result['register_final_minor'] = $quote['register_final_minor'];
        $result['renew_final_minor']    = $quote['renew_final_minor'];
        $result['transfer_final_minor'] = $quote['transfer_final_minor'];
        $result['discount_percent']     = $quote['discount_percent'];
        $result['tld_features']         = $tldRow['features'];

        // Availability through the configured provider chain.
        $provider = $this->registry()->forTld($tld);
        $result['provider'] = $provider->providerName();
        $result['provider_configured'] = $provider->isConfigured();
        try {
            $answer = $provider->checkAvailability($fqdn);
            $result['available'] = $answer['available'];
            $result['status'] = $answer['available'] === null
                ? 'unknown'
                : ($answer['available'] ? 'available' : 'taken');
            if (!empty($answer['status']) && $answer['available'] === null) {
                $result['status'] = (string) $answer['status'];
            }
        } catch (ProviderNotConfiguredException $e) {
            $result['available'] = null;
            $result['status'] = 'DOMAIN_PROVIDER_NOT_CONFIGURED';
        } catch (ProviderException $e) {
            $result['available'] = null;
            $result['status'] = 'DOMAIN_LOOKUP_UNAVAILABLE';
        }

        return $result;
    }

    /**
     * Server-side price quote for a catalogue row, with the client's active
     * Discount Domain Club discount applied where the plan covers it.
     *
     * @return array{register_minor:?int, renew_minor:?int, transfer_minor:?int,
     *               register_final_minor:?int, renew_final_minor:?int, transfer_final_minor:?int,
     *               discount_percent:?float}
     */
    public function quoteFor(array $tldRow, $clientId = null)
    {
        $out = [
            'register_minor'        => $tldRow['register_minor'],
            'renew_minor'           => $tldRow['renew_minor'],
            'transfer_minor'        => $tldRow['transfer_minor'],
            'register_final_minor'  => $tldRow['register_minor'],
            'renew_final_minor'     => $tldRow['renew_minor'],
            'transfer_final_minor'  => $tldRow['transfer_minor'],
            'discount_percent'      => null,
        ];
        $clientId = $clientId === null ? null : (int) $clientId;
        if (!$clientId) {
            return $out;
        }
        $club = new ClubService();
        $membership = $club->activeMembership($clientId);
        if (!$membership) {
            return $out;
        }
        $plan = $membership['plan'];
        $planFlag = [
            'register' => !empty($plan['applies_register']),
            'renew'    => !empty($plan['applies_renew']),
            'transfer' => !empty($plan['applies_transfer']),
        ];
        $settingFlag = [
            'register' => Settings::bool('club_allow_registrations', true),
            'renew'    => Settings::bool('club_allow_renewals', true),
            'transfer' => Settings::bool('club_allow_transfers', false),
        ];
        $applied = false;
        $percent = null;
        foreach (['register', 'renew', 'transfer'] as $type) {
            $key = $type . '_minor';
            $finalKey = $type . '_final_minor';
            if ($out[$key] === null || !$planFlag[$type] || !$settingFlag[$type]) {
                continue;
            }
            $pct = null;
            foreach ($plan['tlds'] as $tldRule) {
                if ($tldRule['tld'] === $tldRow['tld']) {
                    $pct = $tldRule['discount_percent'] !== null
                        ? (float) $tldRule['discount_percent']
                        : (float) $plan['discount_percent'];
                    break;
                }
            }
            if ($pct === null || $pct <= 0) {
                continue;
            }
            $out[$finalKey] = \Chs\Core\Money::discount((int) $out[$key], $pct);
            $percent = $pct;
            $applied = true;
        }
        $out['discount_percent'] = $applied ? $percent : null;
        return $out;
    }

    /**
     * Alternative-extension suggestions with live checks (capped — one render
     * must never fan out into a registrar flood).
     *
     * @return array[] result rows (checkOne shape)
     */
    public function suggestions(DomainName $domain, $clientId = null, $limit = 6)
    {
        $limit = max(1, min(8, (int) $limit));
        $catalog = new TldCatalogService();
        $currency = $clientId
            ? Platform::gateway()->clientCurrency((int) $clientId)
            : Platform::gateway()->defaultCurrency();
        $out = [];
        foreach ($catalog->spotlight($limit + 2) as $tldRow) {
            if ($tldRow['tld'] === $domain->tld()) {
                continue;
            }
            $candidate = DomainName::tryParse($domain->sld() . '.' . $tldRow['tld']);
            if (!$candidate) {
                continue;
            }
            $row = $this->checkOne($candidate, $tldRow, $clientId, $currency);
            $row['is_suggestion'] = true;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Persist one result row under a search. */
    public function persistResult($searchId, array $result)
    {
        Db::insert('domain_search_results', [
            'search_id'                  => (int) $searchId,
            'domain'                     => $result['domain'],
            'tld'                        => $result['tld'],
            'available'                  => $result['available'] === null ? null : ($result['available'] ? 1 : 0),
            'status'                     => $result['status'],
            'register_minor'             => $result['register_minor'],
            'renew_minor'                => $result['renew_minor'],
            'transfer_minor'             => $result['transfer_minor'],
            'register_discounted_minor'  => $result['register_final_minor'],
            'currency'                   => $result['currency'],
            'is_suggestion'              => !empty($result['is_suggestion']) ? 1 : 0,
            'created_at'                 => Clock::now(),
        ]);
    }
}
