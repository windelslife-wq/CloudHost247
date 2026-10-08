<?php
/** Suite 06 — WHMCS addon activation, upgrade and deactivation boundary. */

require_once __DIR__ . '/bootstrap.php';

define('WHMCS', true);
require_once dirname(__DIR__) . '/cloudhost247apps.php';

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Csrf;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Infrastructure\ProviderAccountService;

Harness::boot();

section('WHMCS addon configuration matches fail-closed defaults');
$config = cloudhost247apps_config();
T::is('addon identity is registered with WHMCS', 'CloudHost247 App Cloud', $config['name']);
T::is('Phase 4 service-bound panel workflow ships in a WHMCS addon upgrade', '1.3.0', $config['version']);
T::is('unmapped admin default matches staff RBAC', 'staff', $config['fields']['default_admin_role']['Default']);
T::is('customer VM provisioning is disabled in module config', '', $config['fields']['customer_server_provisioning_enabled']['Default']);
T::is('cPanel account workflow is disabled in module config', '', $config['fields']['panel_account_workflow_enabled']['Default']);

section('Activation and upgrade are additive and rerunnable');
$account = (new ProviderAccountService(Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN)))->create([
    'provider_code' => 'hetzner', 'name' => 'Activation preservation fixture', 'region' => 'fsn1',
]);
$activation = cloudhost247apps_activate();
T::is('WHMCS activation succeeds against migrated schema', 'success', $activation['status']);
T::ok('activation leaves provider-account data intact', Db::first('provider_accounts', ['id' => (int) $account['id']]) !== null);
T::ok('activation preserves the distinct customer-server table', Db::tableExists('customer_servers'));
T::ok('activation creates the WHMCS-bound panel-account workflow table', Db::tableExists('panel_accounts'));
T::ok('activation reports both service workflows stay disabled by default', strpos($activation['description'], 'cPanel account workflow remain disabled by default') !== false);
T::is('staff panel-account view permission is seeded read-only', 1,
    (int) Db::first('role_permissions', ['role' => Actor::ROLE_STAFF, 'permission' => Rbac::PANEL_ACCOUNT_VIEW])['granted']);
T::is('admin panel-account manage permission is seeded', 1,
    (int) Db::first('role_permissions', ['role' => Actor::ROLE_ADMIN, 'permission' => Rbac::PANEL_ACCOUNT_MANAGE])['granted']);
T::is('admin termination permission stays denied', 0,
    (int) Db::first('role_permissions', ['role' => Actor::ROLE_ADMIN, 'permission' => Rbac::PANEL_ACCOUNT_TERMINATE])['granted']);
T::is('super-admin termination permission is seeded', 1,
    (int) Db::first('role_permissions', ['role' => Actor::ROLE_SUPER_ADMIN, 'permission' => Rbac::PANEL_ACCOUNT_TERMINATE])['granted']);

$upgrade = cloudhost247apps_upgrade(['version' => '1.3.0']);
T::ok('WHMCS upgrade callback is rerunnable', Db::tableExists('customer_servers'));
T::ok('upgrade preserves provider-account data', Db::first('provider_accounts', ['id' => (int) $account['id']]) !== null);

section('Deactivation preserves customer and provider records');
$deactivation = cloudhost247apps_deactivate();
T::is('deactivation is successful', 'success', $deactivation['status']);
T::ok('deactivation does not drop provider-account records', Db::first('provider_accounts', ['id' => (int) $account['id']]) !== null);
T::ok('deactivation does not drop customer-server schema', Db::tableExists('customer_servers'));
T::ok('deactivation preserves panel-account history schema', Db::tableExists('panel_account_events'));

section('WHMCS admin landing page discloses actual integration readiness');
Identity::override(Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN));
ob_start();
cloudhost247apps_output([]);
$output = ob_get_clean();
Identity::reset();
T::contains('readiness page clearly warns that no adapter is installed',
    'No real infrastructure-provider adapter is registered.', $output);
T::contains('readiness page exposes the fail-closed error code', 'PROVIDER_UNAVAILABLE', $output);
T::contains('readiness page does not claim provisioning is enabled', 'Disabled (safe default)', $output);
T::contains('readiness page discloses the safe cPanel workflow default',
    'cPanel account workflow</dt><dd>Disabled (safe default)</dd>', $output);

section('Customer panel catalog uses WHMCS client-area routing and remains read-only');
Identity::override(Actor::guest());
$page = cloudhost247apps_clientarea([]);
T::is('catalog page uses the record-driven Smarty template', 'templates/client/control-panels', $page['templatefile']);
T::is('published catalog remains publicly browsable', false, $page['requirelogin']);
T::is('empty catalog is a valid customer page', [], $page['vars']['control_panels']);
T::contains('customer sidebar links to the existing App Cloud module', 'index.php?m=cloudhost247apps',
    cloudhost247apps_sidebar(['modulelink' => 'index.php?m=cloudhost247apps']));
Identity::reset();

section('Admin catalog manager is permission-protected and explicitly metadata-only');
Identity::override(Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN));
$_GET['action'] = 'panel_catalog';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
cloudhost247apps_output(['modulelink' => 'addonmodules.php?module=cloudhost247apps']);
$catalogAdminOutput = ob_get_clean();
T::contains('admin catalog screen warns that publication does not enable operations', 'Catalog-only phase', $catalogAdminOutput);
T::contains('admin catalog screen exposes the non-operational status selector', 'Integration status', $catalogAdminOutput);
T::contains('admin catalog screen offers WHMCS product references', 'WHMCS product ID', $catalogAdminOutput);
T::is('catalog manager starts without fabricated categories', 0, Db::count('panel_categories'));

$csrfToken = Csrf::token();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'catalog_action' => 'category_save', 'ch247_token' => $csrfToken,
    'name' => 'Admin-created Category', 'slug' => 'admin-created-category', 'active' => '1',
];
ob_start();
cloudhost247apps_output(['modulelink' => 'addonmodules.php?module=cloudhost247apps']);
$categoryPostOutput = ob_get_clean();
T::contains('valid CSRF allows the admin to create a category', 'Control-panel category created.', $categoryPostOutput);
T::is('admin form writes through the dedicated catalog service', 1, Db::count('panel_categories', ['slug' => 'admin-created-category']));

$_POST['ch247_token'] = 'invalid-token';
$_POST['name'] = 'CSRF Attack';
$_POST['slug'] = 'csrf-attack';
ob_start();
cloudhost247apps_output(['modulelink' => 'addonmodules.php?module=cloudhost247apps']);
$invalidPostOutput = ob_get_clean();
T::contains('invalid CSRF is rejected', 'session expired or the form was tampered with', $invalidPostOutput);
T::is('rejected CSRF does not create a category', 0, Db::count('panel_categories', ['slug' => 'csrf-attack']));
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_GET['action']);
Identity::reset();

exit(T::summary());
