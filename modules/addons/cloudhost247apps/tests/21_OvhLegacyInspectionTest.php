<?php
/** Existing SoYouStart ownership remains in WHMCS: inspect, never adopt or call OVH. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Api\InfrastructureApi;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Settings;

Harness::boot();
Harness::relaxRateLimits();
$api = new InfrastructureApi(Harness::adminActor());
$client = Harness::client();
$product = Harness::$gateway->addProduct(['type' => 'server', 'paytype' => 'recurring',
    'servermodule' => 'soyoustart_vps']);
$order = Harness::$gateway->createOrder(['clientid' => $client, 'pid' => $product]);
$id = (int) $order['service_id'];
$url = '/v1/ovh/legacy-services/' . $id;
Harness::$gateway->setServiceCustomFields($id, [
    ['fieldname' => 'ovh_order_id|OVH Order Id', 'value' => '73512001'],
    ['fieldname' => 'ovh_server_name|OVH Server Name', 'value' => 'vps-123.ovh.net'],
    ['fieldname' => 'ovh_account|OVH Account Name', 'value' => 'private-legacy-account'],
    ['fieldname' => 'unrelated_password|secret', 'value' => 'private-password'],
]);

section('Independent default-off, staff-only legacy inspection');
T::ok('switch defaults off', !Settings::bool('ovh_legacy_inspection_enabled', false));
T::is('addon setting defaults off', '', (function () {
    define('WHMCS', true);
    require_once dirname(__DIR__) . '/cloudhost247apps.php';
    return cloudhost247apps_config()['fields']['ovh_legacy_inspection_enabled']['Default'];
})());
T::is('disabled route unavailable', 503, $api->dispatch('GET', $url)['status']);
Settings::override('ovh_legacy_inspection_enabled', '1');
T::is('customer cannot inspect', 403,
    (new InfrastructureApi(Actor::customer($client)))->dispatch('GET', $url)['status']);
T::is('read-only staff cannot inspect paid legacy service', 403,
    (new InfrastructureApi(Actor::admin(9, Actor::ROLE_STAFF)))->dispatch('GET', $url)['status']);
T::is('unpaid service blocked', 402, $api->dispatch('GET', $url)['status']);
Harness::$gateway->payInvoice((int) $order['invoice_id']);
T::is('paid but pending service blocked', 503, $api->dispatch('GET', $url)['status']);
Harness::$gateway->setServiceStatus($id, 'Active');

section('WHMCS identifiers are only unverified, operator-visible claims');
$response = $api->dispatch('GET', $url);
T::is('active paid legacy VPS can be inspected', 200, $response['status']);
$data = $response['body']['data'];
T::is('service owner is WHMCS-derived', $client, $data['client_id']);
T::is('legacy order ID is not a provider read-back', '73512001', $data['legacy_ovh_order_id']);
T::is('server name comes from whitelisted field', 'vps-123.ovh.net', $data['legacy_server_name']);
T::is('provider never marked verified', false, $data['provider_verified']);
T::is('binding never claimed', false, $data['binding_created']);
T::notContains('account and password fields never projected', 'private-', json_encode($data));
T::is('no customer VM', 0, Db::count('customer_servers'));
T::is('no provider or adoption jobs', 0, Db::count('jobs'));
T::is('no Contabo binding or other adoption row', 0, Db::count('contabo_adoptions'));

section('Missing or conflicting legacy schema fails closed');
Harness::$gateway->setServiceCustomFields($id, [
    ['fieldname' => 'ovh_order_id|OVH Order Id', 'value' => '73512001'],
    ['fieldname' => 'ovh_server_name|OVH Server Name', 'value' => 'vps-123.ovh.net'],
    ['fieldname' => 'ovh_server_name|Duplicate', 'value' => 'other.ovh.net'],
]);
T::is('duplicate names are ambiguous', 503, $api->dispatch('GET', $url)['status']);
Harness::$gateway->setServiceCustomFields($id, []);
T::is('missing instance identity blocked', 503, $api->dispatch('GET', $url)['status']);
T::is('overflowing service ID rejected before int casting', 422,
    $api->dispatch('GET', '/v1/ovh/legacy-services/9999999999999999999')['status']);
$otherProduct = Harness::$gateway->addProduct(['type' => 'server', 'paytype' => 'recurring',
    'servermodule' => 'contabo']);
$otherOrder = Harness::$gateway->createOrder(['clientid' => $client, 'pid' => $otherProduct]);
Harness::$gateway->payInvoice((int) $otherOrder['invoice_id']);
Harness::$gateway->setServiceStatus((int) $otherOrder['service_id'], 'Active');
T::is('non-legacy module rejected without custom-field reads', 503,
    $api->dispatch('GET', '/v1/ovh/legacy-services/' . $otherOrder['service_id'])['status']);
T::is('only eligible service triggered custom-field read', 3,
    Harness::$gateway->callCount('getServiceCustomFields'));

exit(T::summary());
