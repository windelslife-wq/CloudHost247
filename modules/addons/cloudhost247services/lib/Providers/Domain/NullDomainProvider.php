<?php
/**
 * The honest null provider: selected when no provider is configured for a
 * TLD and no default provider exists. Every operation fails closed with
 * ProviderNotConfiguredException — the UI surfaces DOMAIN_PROVIDER_NOT_CONFIGURED
 * instead of inventing an answer.
 *
 * @package Chs\Providers\Domain
 */

namespace Chs\Providers\Domain;

use Chs\Core\ProviderNotConfiguredException;

class NullDomainProvider extends AbstractDomainProvider
{
    public function providerId()
    {
        return 'null';
    }

    public function providerName()
    {
        return 'No provider configured';
    }

    public function providerType()
    {
        return 'null';
    }

    public function isConfigured()
    {
        return false;
    }

    public function checkAvailability($fqdn)
    {
        throw new ProviderNotConfiguredException(
            'DOMAIN_PROVIDER_NOT_CONFIGURED: no domain provider is configured for ' . $fqdn . '.'
        );
    }

    public function getPricing($tld)
    {
        throw new ProviderNotConfiguredException(
            'DOMAIN_PROVIDER_NOT_CONFIGURED: no domain provider is configured for .' . $tld . '.'
        );
    }

    public function getWhois($fqdn)
    {
        throw new ProviderNotConfiguredException(
            'DOMAIN_PROVIDER_NOT_CONFIGURED: WHOIS is unavailable for ' . $fqdn . '.'
        );
    }

    public function getDomain($fqdn)
    {
        throw new ProviderNotConfiguredException(
            'DOMAIN_PROVIDER_NOT_CONFIGURED: domain details are unavailable for ' . $fqdn . '.'
        );
    }
}
