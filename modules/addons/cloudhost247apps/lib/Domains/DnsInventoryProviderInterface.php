<?php
/** Read-only provider contract for customer-scoped DNS inventory. */

namespace Ch247Apps\Domains;

interface DnsInventoryProviderInterface
{
    /** Stable provider key, for example `cloudflare`. */
    public function key();

    /**
     * Return validated records for a domain already resolved in the caller's
     * customer scope. Providers must not mutate DNS or persist record content.
     *
     * @return array{provider:string,domain:string,records:array}
     */
    public function listForDomain($customerId, $domain);
}
