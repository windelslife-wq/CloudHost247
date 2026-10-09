<?php
/** Operator-owned WHMCS VPS product → provider/spec entitlement. No customer-selected credentials or sizing. */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\ProviderConfigurationException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Integration\Gateway;
use Ch247Apps\Integration\GatewayInterface;

class ServerProductMappingService
{
    private $actor;
    private $gateway;

    public function __construct(Actor $actor, GatewayInterface $gateway = null)
    {
        $this->actor = $actor;
        $this->gateway = $gateway ?: Gateway::get();
    }

    public function listing()
    {
        $this->assertManager();
        return array_map([__CLASS__, 'present'], Db::fetch('server_product_mappings', [], ['order' => 'id']));
    }

    /** Public product names only; pricing and checkout remain entirely in WHMCS. */
    public function customerCatalog()
    {
        if (!Settings::bool('customer_server_provisioning_enabled', false)
            || !Settings::bool('customer_server_self_service_enabled', false)) {
            return [];
        }
        $out = [];
        foreach (Db::fetch('server_product_mappings', ['enabled' => 1], ['order' => 'id']) as $row) {
            try {
                $this->assertVpsProduct((int) $row['whmcs_product_id']);
                (new ProviderAccountService($this->actor))->assertOperational(
                    (int) $row['provider_account_id'], ['server.create', 'server.get']
                );
                $product = $this->gateway->getProduct((int) $row['whmcs_product_id']);
                if (!empty($product['hidden'])) {
                    continue;
                }
                $out[] = ['product_id' => (int) $row['whmcs_product_id'], 'name' => (string) $product['name']];
            } catch (\Throwable $e) {
                // A disabled/unavailable provider is never advertised as orderable.
                continue;
            }
        }
        return $out;
    }

    /** Mapping changes are explicitly operator-authorized and audited. Never auto-enable. */
    public function save(array $input)
    {
        $this->assertManager();
        foreach (array_keys($input) as $key) {
            if (!in_array($key, ['product_id', 'provider_account_id', 'spec', 'enabled'], true)) {
                throw new ValidationException('Unsupported product mapping field.', ['field' => $key]);
            }
        }
        $productId = self::positiveId(isset($input['product_id']) ? $input['product_id'] : null);
        $accountId = self::positiveId(isset($input['provider_account_id']) ? $input['provider_account_id'] : null);
        $this->assertVpsProduct($productId);
        // No credentials are returned or stored in a mapping. A provider account
        // must be real and verified before an operator can enable self-service.
        if (!isset($input['enabled']) || !in_array($input['enabled'], [true, false], true)) {
            throw new ValidationException('An explicit boolean enabled value is required.');
        }
        if ($input['enabled']) {
            (new ProviderAccountService($this->actor))->assertOperational($accountId, ['server.create', 'server.get']);
        } elseif (!Db::first('provider_accounts', ['id' => $accountId])) {
            throw new NotFoundException('Provider account not found.');
        }
        if (!isset($input['spec']) || !is_array($input['spec'])) {
            throw new ValidationException('A fixed VM specification is required.');
        }
        $template = $input['spec'];
        foreach (array_keys($template) as $key) {
            if (!in_array($key, ['region', 'image', 'cpu_cores', 'memory_mb', 'storage_gb'], true)) {
                throw new ValidationException('A product mapping cannot supply a server name or hostname.', ['field' => $key]);
            }
        }
        $template = ServerSpec::normalise(array_merge(['name' => 'vm-template'], $template));
        unset($template['name'], $template['hostname']);
        $existing = Db::first('server_product_mappings', ['whmcs_product_id' => $productId]);
        $id = Db::transaction(function () use ($existing, $productId, $accountId, $template, $input) {
            $data = [
                'provider_account_id' => $accountId,
                'spec_template' => Str::jsonEncode($template),
                'enabled' => $input['enabled'] ? 1 : 0,
                'updated_at' => Clock::now(),
            ];
            if ($existing) {
                Db::update('server_product_mappings', $data, ['id' => (int) $existing['id']]);
                return (int) $existing['id'];
            }
            return Db::insert('server_product_mappings', array_merge($data, [
                'whmcs_product_id' => $productId,
                'created_at' => Clock::now(),
            ]));
        });
        Audit::record($this->actor, 'SERVER_PRODUCT_MAPPING_SAVED', [
            'resource_type' => 'server_product_mapping', 'resource_id' => $id,
            'metadata' => ['product_id' => $productId, 'provider_account_id' => $accountId,
                'enabled' => $input['enabled']],
        ]);
        return self::present(Db::first('server_product_mappings', ['id' => $id]));
    }

    /** Resolve from WHMCS service, not from browser-provided product/provider/spec. */
    public function forService($serviceId)
    {
        $service = $this->gateway->getService((int) $serviceId);
        if (!$service) {
            throw new NotFoundException('WHMCS service not found.');
        }
        if ($this->actor->isCustomer() && (int) $service['userid'] !== $this->actor->clientId) {
            throw new NotFoundException('WHMCS service not found.');
        }
        $productId = isset($service['packageid']) ? (int) $service['packageid'] : 0;
        $mapping = $productId > 0 ? Db::first('server_product_mappings', ['whmcs_product_id' => $productId]) : null;
        if (!$mapping || (int) $mapping['enabled'] !== 1) {
            throw new ProviderConfigurationException('This WHMCS product is not enabled for self-service VPS provisioning.');
        }
        $this->assertVpsProduct($productId);
        $template = Str::jsonDecode($mapping['spec_template'], null);
        if (!is_array($template)) {
            throw new ProviderConfigurationException('The server product mapping has an invalid specification.');
        }
        // Never accept an arbitrary hostname, name, size or provider account from a customer.
        $spec = ServerSpec::normalise(array_merge($template, ['name' => 'vm-service-' . (int) $serviceId]));
        return ['id' => (int) $mapping['id'], 'provider_account_id' => (int) $mapping['provider_account_id'],
            'spec' => $spec];
    }

    /** Worker must recheck before CREATE; disabling/remapping a product cancels queued creates. */
    public function assertCurrent(array $server)
    {
        $mapping = $this->forService((int) $server['whmcs_service_id']);
        if ($mapping['id'] !== (int) $server['product_mapping_id']
            || $mapping['provider_account_id'] !== (int) $server['provider_account_id']
            || $mapping['spec'] !== Str::jsonDecode($server['requested_spec'], null)) {
            throw new ProviderConfigurationException('Self-service product mapping changed before VM creation.');
        }
    }

    private function assertManager()
    {
        Rbac::assert($this->actor, Rbac::PLAN_MANAGE);
        Rbac::assert($this->actor, Rbac::PROVIDER_ACCOUNT_MANAGE);
    }

    private function assertVpsProduct($id)
    {
        $product = $this->gateway->getProduct($id);
        if (!$product || (string) $product['type'] !== 'server' || (string) $product['paytype'] !== 'recurring') {
            throw new ValidationException('Select a recurring WHMCS server product (VPS).');
        }
        // The WGS OVH/SoYouStart module (and other WHMCS provisioning modules)
        // already orders infrastructure on payment. Allowing the same product
        // here would place a second provider order and could double-charge.
        if (trim((string) (isset($product['servermodule']) ? $product['servermodule'] : '')) !== '') {
            throw new ValidationException('A product managed by another WHMCS server module cannot also provision through App Cloud.');
        }
    }

    private static function positiveId($value)
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)))
            || (int) $value <= 0) {
            throw new ValidationException('Positive product and provider account IDs are required.');
        }
        return (int) $value;
    }

    private static function present(array $row)
    {
        return ['id' => (int) $row['id'], 'product_id' => (int) $row['whmcs_product_id'],
            'provider_account_id' => (int) $row['provider_account_id'],
            'spec' => Str::jsonDecode($row['spec_template'], []), 'enabled' => (bool) $row['enabled']];
    }
}
