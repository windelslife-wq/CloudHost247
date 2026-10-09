<?php
/**
 * CloudHost247 App Cloud — installed infrastructure-provider adapters.
 *
 * Reviewed registration file loaded by ProviderBootstrap::boot() in WHMCS
 * requests, the REST API, and workers. It contains no secrets: provider
 * credentials live only in the encrypted provider-account vault. Add further
 * reviewed adapters here; never register a fake or test adapter in production.
 *
 * Registration makes an adapter available to the catalog and the provisioning
 * flow; it does not enable provisioning. The customer-server provisioning
 * switch stays off until adapter integration and staging checks pass.
 *
 * @package Ch247Apps
 */

use Ch247Apps\Infrastructure\Providers\HetznerCloudAdapter;
use Ch247Apps\Infrastructure\Providers\DigitalOceanAdapter;
use Ch247Apps\Infrastructure\Providers\VultrAdapter;
use Ch247Apps\Infrastructure\Providers\ContaboReadOnlyAdapter;

return [
    new HetznerCloudAdapter(),
    new DigitalOceanAdapter(),
    new VultrAdapter(),
    new ContaboReadOnlyAdapter(),
];
