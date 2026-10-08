<?php
/** Read-only bridge to the separately gated Cloudflare addon inventory service. */

namespace Ch247Apps\Domains;

use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Core\Validator;

class CloudflareDnsInventoryAdapter implements DnsInventoryProviderInterface
{
    private $inventoryService;

    /** An injected service is primarily useful for offline contract tests. */
    public function __construct($inventoryService = null)
    {
        if ($inventoryService !== null
            && (!is_object($inventoryService) || !method_exists($inventoryService, 'listForDomain'))) {
            throw new ConfigurationException('The Cloudflare DNS inventory service is invalid.');
        }
        $this->inventoryService = $inventoryService;
    }

    public function key()
    {
        return 'cloudflare';
    }

    /**
     * Return a neutral read-only inventory projection. Phase 12 still owns all
     * service ownership, entitlement, feature-gate and provider-response checks.
     */
    public function listForDomain($customerId, $domain)
    {
        $customerId = (int) $customerId;
        if ($customerId <= 0 || !is_string($domain)) {
            throw new ValidationException('A valid customer and domain are required for DNS inventory.');
        }
        $domain = trim($domain);
        if (substr($domain, -1) === '.') $domain = substr($domain, 0, -1);
        $validated = Validator::make(['domain' => $domain])
            ->required('domain')
            ->domain('domain')
            ->validate();
        $domain = (string) $validated['domain'];

        try {
            $inventory = $this->inventoryService()->listForDomain($customerId, $domain);
        } catch (AppsException $e) {
            throw $e;
        } catch (\CloudHost247\Cloudflare\Core\AuthorizationException $e) {
            throw new AuthorizationException('Cloudflare DNS inventory is not available for this domain.', [], $e);
        } catch (\CloudHost247\Cloudflare\Core\NotFoundException $e) {
            throw new NotFoundException('DNS inventory not found.', [], $e);
        } catch (\CloudHost247\Cloudflare\Core\CloudflareException $e) {
            throw $this->unavailable($e);
        } catch (\Throwable $e) {
            throw $this->unavailable($e);
        }
        if (!is_array($inventory) || !isset($inventory['zone_name'], $inventory['records'])
            || !is_string($inventory['zone_name']) || !is_array($inventory['records'])) {
            throw new ProviderUnavailableException(
                'Cloudflare returned an invalid DNS inventory to the App Cloud bridge.',
                ['provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_INVALID']
            );
        }
        try {
            $actualValues = Validator::make(['domain' => $inventory['zone_name']])
                ->required('domain')->domain('domain')->validate();
            $actualDomain = (string) $actualValues['domain'];
        } catch (\Throwable $e) {
            throw new ProviderUnavailableException(
                'Cloudflare returned an invalid domain in the inventory.',
                ['provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_INVALID']
            );
        }
        if ($actualDomain !== $domain) {
            throw new ProviderUnavailableException(
                'Cloudflare returned an inventory for a different domain.',
                ['provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_SCOPE_MISMATCH']
            );
        }

        return [
            'provider' => $this->key(),
            'domain' => $domain,
            'records' => $this->projectRecords($inventory['records'], $domain),
        ];
    }

    /** Keep the cross-addon contract allowlisted even if the source projection evolves. */
    private function projectRecords(array $records, $domain)
    {
        $out = [];
        $seenIds = [];
        $expectedKey = 0;
        $supportedTypes = ['A', 'AAAA', 'CAA', 'CERT', 'CNAME', 'DNSKEY', 'DS', 'HTTPS', 'LOC',
            'MX', 'NAPTR', 'NS', 'OPENPGPKEY', 'PTR', 'SMIMEA', 'SPF', 'SRV', 'SSHFP', 'SVCB', 'TLSA', 'TXT', 'URI'];
        foreach ($records as $key => $record) {
            if ($key !== $expectedKey || !is_array($record)) {
                throw new ProviderUnavailableException(
                    'Cloudflare returned an invalid DNS record list.',
                    ['provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_INVALID']
                );
            }
            $expectedKey++;
            foreach (['id', 'type', 'name', 'content', 'ttl', 'proxied', 'priority', 'comment'] as $requiredKey) {
                if (!array_key_exists($requiredKey, $record)) {
                    throw new ProviderUnavailableException(
                        'Cloudflare returned an incomplete DNS record.',
                        ['provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_INVALID']
                    );
                }
            }
            $id = $record['id'];
            $type = $record['type'];
            $rawName = $record['name'];
            $content = $record['content'];
            $ttl = $record['ttl'];
            $proxied = $record['proxied'];
            $priority = $record['priority'];
            $comment = $record['comment'];
            $name = is_string($rawName) ? strtolower(rtrim($rawName, '.')) : '';
            $type = is_string($type) ? strtoupper(trim($type)) : '';
            $validName = $this->validOwnerName($rawName, $domain);
            $validTtl = is_int($ttl) && ($ttl === 1 || ($ttl >= 60 && $ttl <= 86400));
            $validPriority = $priority === null || (is_int($priority) && $priority >= 0 && $priority <= 65535);
            $priorityType = in_array($type, ['MX', 'SRV'], true);
            $validComment = $comment === null || (is_string($comment) && strlen($comment) <= 512
                && !preg_match('/[\\x00-\\x1F\\x7F]/', $comment));
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/i', $id)
                || isset($seenIds[strtolower($id)]) || !in_array($type, $supportedTypes, true)
                || !$validName || !is_string($content) || trim($content) === ''
                || strlen($content) > 4096 || preg_match('/[\\x00-\\x1F\\x7F]/', $content)
                || !$validTtl || (!is_bool($proxied) && $proxied !== null)
                || ($proxied === true && !in_array($type, ['A', 'AAAA', 'CNAME'], true))
                || !$validPriority || ($priorityType && $priority === null)
                || (!$priorityType && $priority !== null) || !$validComment) {
                throw new ProviderUnavailableException(
                    'Cloudflare returned an invalid DNS record.',
                    ['provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_INVALID']
                );
            }
            $seenIds[strtolower($id)] = true;
            $out[] = [
                'id' => strtolower($id), 'type' => $type, 'name' => $name,
                'content' => $content, 'ttl' => $ttl, 'proxied' => $proxied,
                'priority' => $priority, 'comment' => $comment,
            ];
        }
        return $out;
    }

    private function validOwnerName($rawName, $domain)
    {
        if (!is_string($rawName) || $rawName === '' || trim($rawName) !== $rawName
            || strlen($rawName) > 254 || substr($rawName, -2) === '..') return false;
        $name = strtolower(rtrim($rawName, '.'));
        if ($name === '' || strlen($name) > 253
            || ($name !== $domain && substr($name, -strlen('.' . $domain)) !== '.' . $domain)) return false;
        $labels = explode('.', $name);
        foreach ($labels as $index => $label) {
            if ($label === '*' && $index === 0) continue;
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|_[a-z0-9](?:[a-z0-9-]{0,60}[a-z0-9])?)$/', $label)) return false;
        }
        return !in_array('*', array_slice($labels, 1), true);
    }

    private function unavailable(\Throwable $previous = null)
    {
        return new ProviderUnavailableException(
            'Cloudflare DNS inventory is temporarily unavailable.',
            ['provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_UNAVAILABLE'],
            $previous
        );
    }

    private function inventoryService()
    {
        if ($this->inventoryService !== null) return $this->inventoryService;

        $className = 'CloudHost247\\Cloudflare\\Service\\DnsInventoryService';
        if (!class_exists($className)) {
            $autoloaders = [];
            if (defined('CH247APPS_ROOT')) {
                $autoloaders[] = dirname(CH247APPS_ROOT) . '/cloudhost247cloudflare/autoload.php';
            }
            $autoloaders[] = dirname(__DIR__, 3) . '/cloudhost247cloudflare/autoload.php';
            $autoloaders[] = '/cloudflare/autoload.php';
            foreach (array_unique($autoloaders) as $autoload) {
                if (is_file($autoload)) require_once $autoload;
                if (class_exists($className)) break;
            }
        }
        if (!class_exists($className)) {
            throw new ProviderUnavailableException(
                'The Cloudflare DNS inventory service is not installed.',
                ['provider_code' => 'cloudflare', 'error_code' => 'DNS_PROVIDER_UNAVAILABLE']
            );
        }
        $this->inventoryService = new $className();
        return $this->inventoryService;
    }
}
