<?php
/**
 * Domain platform job types.
 *
 * Every long-running or provider-touching domain operation runs as a job on
 * the mod_chs_jobs queue — never inline in an HTTP request. Registration,
 * renewal, transfer and auction settlement jobs are idempotent: their
 * idempotency keys make a retried delivery a no-op instead of a double charge
 * or a double registration.
 *
 * @package Chs\Workflow
 */

namespace Chs\Workflow;

final class DomainJobTypes
{
    public const AVAILABILITY_CHECK      = 'DOMAIN_AVAILABILITY_CHECK';
    public const BULK_SEARCH             = 'DOMAIN_BULK_SEARCH';
    public const REGISTRATION            = 'DOMAIN_REGISTRATION';
    public const TRANSFER                = 'DOMAIN_TRANSFER';
    public const RENEWAL                 = 'DOMAIN_RENEWAL';
    public const DNS_SYNC                = 'DOMAIN_DNS_SYNC';
    public const WHOIS_LOOKUP            = 'DOMAIN_WHOIS_LOOKUP';
    public const AUCTION_CLOSE           = 'DOMAIN_AUCTION_CLOSE';
    public const AUCTION_PAYMENT_CHECK   = 'DOMAIN_AUCTION_PAYMENT_CHECK';
    public const EXPIRATION_CHECK        = 'DOMAIN_EXPIRATION_CHECK';
    public const PROVIDER_SYNC           = 'DOMAIN_PROVIDER_SYNC';
    public const RECONCILIATION          = 'DOMAIN_RECONCILIATION';
    public const NOTIFICATION            = 'DOMAIN_NOTIFICATION';

    /** @return array<string,string> type => human label */
    public static function labels()
    {
        return [
            self::AVAILABILITY_CHECK    => 'Domain availability check',
            self::BULK_SEARCH           => 'Bulk domain search',
            self::REGISTRATION          => 'Domain registration',
            self::TRANSFER              => 'Domain transfer',
            self::RENEWAL               => 'Domain renewal',
            self::DNS_SYNC              => 'DNS record sync',
            self::WHOIS_LOOKUP          => 'WHOIS lookup',
            self::AUCTION_CLOSE         => 'Auction closing sweep',
            self::AUCTION_PAYMENT_CHECK => 'Auction payment check',
            self::EXPIRATION_CHECK      => 'Expiration & renewal sweep',
            self::PROVIDER_SYNC         => 'Provider/domain sync',
            self::RECONCILIATION        => 'Platform reconciliation',
            self::NOTIFICATION          => 'Customer notification',
        ];
    }

    /** @param string $type @return bool */
    public static function isKnown($type)
    {
        return array_key_exists((string) $type, self::labels());
    }
}
