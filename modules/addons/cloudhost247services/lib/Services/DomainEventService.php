<?php
/**
 * Domain event journal + audit bridge.
 *
 * Every important domain lifecycle action is recorded twice: once in the
 * append-only mod_chs_domain_events journal (queryable per domain, feeds the
 * customer "domain history" view) and once in the suite audit log (actor, IP
 * hash, timestamp). Payloads are sanitised — credential-shaped keys (EPP
 * codes, passwords, tokens, secrets) are stripped before storage.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\Clock;
use Chs\Core\Db;

class DomainEventService
{
    public const DOMAIN_SEARCHED            = 'DOMAIN_SEARCHED';
    public const DOMAIN_REGISTERED          = 'DOMAIN_REGISTERED';
    public const DOMAIN_REGISTRATION_FAILED = 'DOMAIN_REGISTRATION_FAILED';
    public const DOMAIN_TRANSFER_STARTED    = 'DOMAIN_TRANSFER_STARTED';
    public const DOMAIN_TRANSFER_COMPLETED  = 'DOMAIN_TRANSFER_COMPLETED';
    public const DOMAIN_TRANSFER_FAILED     = 'DOMAIN_TRANSFER_FAILED';
    public const DOMAIN_RENEWED             = 'DOMAIN_RENEWED';
    public const DOMAIN_RENEWAL_FAILED      = 'DOMAIN_RENEWAL_FAILED';
    public const DOMAIN_DNS_UPDATED         = 'DOMAIN_DNS_UPDATED';
    public const DOMAIN_AUTO_RENEW_CHANGED  = 'DOMAIN_AUTO_RENEW_CHANGED';
    public const DOMAIN_AUCTION_CREATED     = 'DOMAIN_AUCTION_CREATED';
    public const DOMAIN_BID_PLACED          = 'DOMAIN_BID_PLACED';
    public const DOMAIN_AUCTION_CLOSED      = 'DOMAIN_AUCTION_CLOSED';
    public const DOMAIN_APPRAISAL_REQUESTED = 'DOMAIN_APPRAISAL_REQUESTED';
    public const DOMAIN_CLUB_SUBSCRIBED     = 'DOMAIN_CLUB_SUBSCRIBED';
    public const DOMAIN_CLUB_CANCELLED      = 'DOMAIN_CLUB_CANCELLED';
    public const DOMAIN_ADMIN_UPDATED       = 'DOMAIN_ADMIN_UPDATED';
    public const DOMAIN_EXPIRING            = 'DOMAIN_EXPIRING';
    public const DOMAIN_EXPIRED             = 'DOMAIN_EXPIRED';

    /** Keys that must never reach a journal/audit payload. */
    private const REDACTED_KEYS = [
        'epp', 'epp_code', 'eppcode', 'auth_code', 'authcode', 'password',
        'passwd', 'secret', 'token', 'api_key', 'apikey', 'credentials',
        'credentials_enc', 'authorization', 'card', 'cvv',
    ];

    /**
     * Record one domain event.
     *
     * @param string $type     one of the DOMAIN_* constants (or a domain.* action)
     * @param string $domain   FQDN ('' for non-domain events)
     * @param string $actorType Audit::ACTOR_*
     * @param int    $actorId
     * @param array  $payload  sanitised context (secrets stripped)
     */
    public function record($type, $domain, $actorType, $actorId, array $payload = [], $domainServiceId = null)
    {
        $payload = $this->sanitize($payload);
        $domain = strtolower(trim((string) $domain));

        Db::insert('domain_events', [
            'domain'            => substr($domain, 0, 255),
            'domain_service_id' => $domainServiceId === null ? null : (int) $domainServiceId,
            'type'              => substr((string) $type, 0, 64),
            'actor_type'        => substr((string) $actorType, 0, 16),
            'actor_id'          => (int) $actorId,
            'payload'           => $payload === [] ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at'        => Clock::now(),
        ]);

        Audit::log($actorType, (int) $actorId, (string) $type, $payload + ['domain' => $domain]);
    }

    /** Convenience wrappers. */
    public function client($type, $domain, $clientId, array $payload = [], $domainServiceId = null)
    {
        $this->record($type, $domain, Audit::ACTOR_CLIENT, (int) $clientId, $payload, $domainServiceId);
    }

    public function admin($type, $domain, $adminId, array $payload = [], $domainServiceId = null)
    {
        $this->record($type, $domain, Audit::ACTOR_ADMIN, (int) $adminId, $payload, $domainServiceId);
    }

    public function system($type, $domain, array $payload = [], $domainServiceId = null)
    {
        $this->record($type, $domain, Audit::ACTOR_SYSTEM, 0, $payload, $domainServiceId);
    }

    /** Append-only history for one domain (customer view + admin). */
    public function historyForDomain($domain, $limit = 100)
    {
        return Db::all('domain_events', ['domain' => strtolower(trim((string) $domain))], 'id DESC', (int) $limit);
    }

    /** @return array[] */
    public function historyForService($domainServiceId, $limit = 100)
    {
        return Db::all('domain_events', ['domain_service_id' => (int) $domainServiceId], 'id DESC', (int) $limit);
    }

    /** Recent domain events across the platform (admin overview). */
    public function recent($limit = 200)
    {
        return Db::all('domain_events', [], 'id DESC', (int) $limit);
    }

    /** Strip credential-shaped keys (recursively) from a payload. */
    public function sanitize(array $payload)
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }
        return $out;
    }
}
