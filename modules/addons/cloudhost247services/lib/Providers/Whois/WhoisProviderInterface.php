<?php
/**
 * WHOIS lookup provider abstraction. Swap registries, RDAP gateways or
 * enterprise feeds in without touching the service layer.
 *
 * @package Chs\Providers\Whois
 */

namespace Chs\Providers\Whois;

interface WhoisProviderInterface
{
    /**
     * @param string $domain  FQDN, lower-case ASCII
     * @param string $tld
     * @return array{server:string, raw:string}
     * @throws \Chs\Core\ProviderException when the registry cannot be reached
     */
    public function lookup($domain, $tld);
}
