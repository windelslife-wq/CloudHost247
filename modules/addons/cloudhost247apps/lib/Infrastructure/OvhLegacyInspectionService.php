<?php
/** Read-only WHMCS snapshot of a legacy SoYouStart VPS service; not provider verification. */
namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\ProviderConfigurationException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Integration\Gateway;
use Ch247Apps\Integration\GatewayInterface;

class OvhLegacyInspectionService
{
    private $actor;
    private $gateway;

    public function __construct(Actor $actor, GatewayInterface $gateway = null)
    {
        $this->actor = $actor;
        $this->gateway = $gateway ?: Gateway::get();
    }

    public function inspect($serviceId)
    {
        Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_VIEW_ALL);
        Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_MANAGE);
        if (!Settings::bool('ovh_legacy_inspection_enabled', false)) {
            throw new ProviderConfigurationException('Legacy OVH service inspection is disabled pending WHMCS staging.');
        }
        if ((!is_int($serviceId) && !is_string($serviceId))
            || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $serviceId)
            || (string) (int) $serviceId !== (string) $serviceId) {
            throw new ValidationException('A valid WHMCS service ID is required.');
        }
        $serviceId = (int) $serviceId;
        // Reuse WHMCS's service/order/invoice ownership and payment checks.
        $billing = (new CustomerServerService($this->actor, $this->gateway))
            ->billingContextForExisting($serviceId);
        $service = $this->gateway->getService($serviceId);
        if (!$service || strtolower(trim((string) (isset($service['domainstatus']) ? $service['domainstatus'] : ''))) !== 'active') {
            throw new ProviderConfigurationException('Only an active legacy WHMCS service can be inspected.');
        }
        $productId = isset($service['packageid']) ? (int) $service['packageid'] : 0;
        $product = $productId > 0 ? $this->gateway->getProduct($productId) : null;
        if (!$product || (string) $product['servermodule'] !== 'soyoustart_vps'
            || (string) $product['type'] !== 'server'
            || (string) $product['paytype'] !== 'recurring') {
            throw new ProviderConfigurationException('This WHMCS service is not a legacy SoYouStart VPS.');
        }
        $fields = [];
        foreach ($this->gateway->getServiceCustomFields($serviceId, $productId) as $row) {
            if (!is_array($row) || !isset($row['fieldname'], $row['value'])) {
                throw new ProviderConfigurationException('Legacy WHMCS custom fields are incomplete.');
            }
            $name = explode('|', (string) $row['fieldname'], 2)[0];
            if (!in_array($name, ['ovh_order_id', 'ovh_server_name'], true)) { continue; }
            if (isset($fields[$name]) || !is_string($row['value'])) {
                throw new ProviderConfigurationException('Legacy WHMCS custom fields are ambiguous.');
            }
            $fields[$name] = trim($row['value']);
        }
        if (!isset($fields['ovh_order_id'], $fields['ovh_server_name'])
            || !preg_match('/^[1-9][0-9]{0,18}$/D', $fields['ovh_order_id'])
            || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,254}$/D', $fields['ovh_server_name'])) {
            throw new ProviderConfigurationException('Legacy WHMCS order/server identifiers are missing or invalid.');
        }
        // The legacy custom fields are WHMCS-sourced claims, not an OVH API
        // read-back. No binding, customer VM, provider account or job is created.
        return [
            'service_id' => $serviceId, 'client_id' => (int) $billing['client_id'],
            'whmcs_order_id' => (int) $billing['order_id'],
            'legacy_ovh_order_id' => $fields['ovh_order_id'],
            'legacy_server_name' => $fields['ovh_server_name'],
            'source' => 'whmcs_legacy_custom_fields', 'provider_verified' => false,
            'binding_created' => false,
        ];
    }
}
