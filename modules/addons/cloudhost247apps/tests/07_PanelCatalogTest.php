<?php
/**
 * Suite 07 — dedicated control-panel catalog and plan metadata.
 *
 * Covers separate additive schema, admin RBAC, WHMCS product references,
 * record-driven customer listings, and the non-deployable integration boundary.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Catalog\PanelCatalogService;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\ValidationException;

Harness::boot();
Harness::relaxRateLimits();

$admin = Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Catalog Admin');
$staff = Actor::admin(4, Actor::ROLE_STAFF, 'Read-only Staff');
$customerId = Harness::client();
$customer = Actor::customer($customerId, 'Catalog Customer');
$catalog = new PanelCatalogService($admin);

section('Panel catalog schema is additive and independent from the application catalog');

T::ok('panel category table is separate', Db::tableExists('panel_categories'));
T::ok('control panel table is separate', Db::tableExists('control_panels'));
T::ok('panel plan table is separate', Db::tableExists('control_panel_plans'));
T::ok('existing application categories remain present', Db::tableExists('categories'));
T::ok('existing application plans remain present', Db::tableExists('plans'));
T::is('no catalog rows are fabricated on activation', 0, Db::count('control_panels'));

section('Admin-configurable categories and secure panel metadata');

$customerCatalog = new PanelCatalogService($customer);
T::throws('customers cannot create categories', AuthorizationException::class, function () use ($customerCatalog) {
    $customerCatalog->createCategory(['name' => 'Unauthorized']);
});
T::throws('read-only staff cannot open the admin catalog', AuthorizationException::class, function () use ($staff) {
    (new PanelCatalogService($staff))->adminCatalog();
});

$category = $catalog->createCategory([
    'name' => 'Hosting Control Panels',
    'slug' => 'hosting-control-panels',
    'description' => 'Panel software catalog metadata.',
    'sort_order' => 10,
]);
T::is('category is stored in the dedicated table', 'hosting-control-panels', $category['slug']);
T::is('new category is active by default', 1, (int) $category['active']);

T::throws('publication requires an official HTTPS source', ValidationException::class, function () use ($catalog, $category) {
    $catalog->createPanel([
        'category_id' => $category['id'], 'name' => 'Unverified Panel', 'vendor' => 'Example Vendor',
        'catalog_status' => 'published',
    ]);
});
T::throws('non-HTTPS source links are refused', ValidationException::class, function () use ($catalog, $category) {
    $catalog->createPanel([
        'category_id' => $category['id'], 'name' => 'Unsafe Panel', 'vendor' => 'Example Vendor',
        'official_source_url' => 'http://vendor.example.test/panel', 'catalog_status' => 'draft',
    ]);
});

$panel = $catalog->createPanel([
    'category_id' => $category['id'],
    'name' => 'Example Panel',
    'slug' => 'example-panel',
    'vendor' => 'Example Vendor',
    'summary' => 'Vendor panel metadata only.',
    'description' => 'This entry describes software; it does not deploy it.',
    'official_source_url' => 'https://vendor.example.test/panel',
    'documentation_url' => 'https://docs.vendor.example.test/panel',
    'support_url' => 'https://vendor.example.test/support',
    'license_model' => 'commercial',
    'license_terms_url' => 'https://vendor.example.test/terms',
    'license_terms_summary' => 'License terms are descriptive only.',
    'supported_os' => ['Ubuntu 22.04 LTS', 'Debian 12'],
    'minimum_cpu_cores' => 2,
    'minimum_memory_mb' => 2048,
    'minimum_storage_gb' => 40,
    'resource_notes' => 'Minimums are transcribed from vendor documentation.',
    'capabilities' => [
        'account_management' => true,
        'reseller_management' => false,
        'dns_management' => true,
        'api_access' => true,
    ],
    'catalog_status' => 'draft',
    'integration_status' => 'planned',
    'installation_status' => 'metadata_only',
]);
T::is('panel starts as a draft', 'draft', $panel['catalog_status']);
T::is('integration status is administrator-configurable within safe states', 'planned', $panel['integration_status']);
T::is('installation status is administrator-configurable within safe states', 'metadata_only', $panel['installation_status']);
T::ok('draft panel is never deployable', !$panel['deployable']);
T::ok('panel requirements are structured', $panel['requirements']['minimum_memory_mb'] === 2048);
T::ok('only allowlisted documented capabilities are stored', $panel['capabilities']['api_access']);
T::throws('admins cannot override non-deployable status', ValidationException::class, function () use ($catalog, $panel) {
    $catalog->updatePanel($panel['id'], ['deployable' => true]);
});
T::throws('admins cannot claim an implemented integration', ValidationException::class, function () use ($catalog, $panel) {
    $catalog->updatePanel($panel['id'], ['integration_status' => 'available']);
});
T::throws('admins cannot claim a completed installation method', ValidationException::class, function () use ($catalog, $panel) {
    $catalog->updatePanel($panel['id'], ['installation_status' => 'installed']);
});
T::throws('unknown capability flags are rejected', ValidationException::class, function () use ($catalog, $panel) {
    $catalog->updatePanel($panel['id'], ['capabilities' => ['fake_install_success' => true]]);
});

$panel = $catalog->updatePanel($panel['id'], ['catalog_status' => 'published']);
T::is('published record retains its explicitly non-operational status', 'planned', $panel['integration_status']);
T::ok('published record remains non-deployable', !$panel['deployable']);
T::is('database stores the administrator-selected non-operational status', 'planned',
    Db::first('control_panels', ['id' => (int) $panel['id']])['integration_status']);
T::is('database enforces deployable false', 0,
    (int) Db::first('control_panels', ['id' => (int) $panel['id']])['deployable']);

section('WHMCS product references are verified and are not local price copies');

T::throws('a missing WHMCS product cannot be linked', ValidationException::class, function () use ($catalog, $panel) {
    $catalog->createPlan([
        'control_panel_id' => $panel['id'], 'name' => 'Unknown Product Plan',
        'whmcs_product_id' => 987654, 'catalog_status' => 'draft',
    ]);
});

T::throws('panel plans reject a duplicate local price amount', ValidationException::class, function () use ($catalog, $panel) {
    $catalog->createPlan([
        'control_panel_id' => $panel['id'], 'name' => 'Local Price Is Not Allowed', 'price_minor' => 2500,
    ]);
});

$productId = Harness::$gateway->addProduct(['name' => 'Example Panel Monthly']);
$plan = $catalog->createPlan([
    'control_panel_id' => $panel['id'],
    'name' => 'Standard',
    'slug' => 'standard',
    'summary' => 'Standard panel plan metadata.',
    'description' => 'Resource tier; WHMCS retains billing authority.',
    'whmcs_product_id' => $productId,
    'billing_cycle' => 'monthly',
    'cpu_cores' => 4,
    'memory_mb' => 4096,
    'storage_gb' => 80,
    'bandwidth_gb' => 1000,
    'max_accounts' => 25,
    'catalog_status' => 'draft',
]);
T::is('plan retains only the WHMCS product reference', $productId, (int) $plan['whmcs_product_id']);
T::ok('plan has no mirrored local price field', !array_key_exists('price_minor', $plan));
T::ok('WHMCS product reference was checked through the gateway', Harness::$gateway->callCount('getProduct') > 0);
T::throws('a WHMCS product cannot be assigned to two panel plans', ValidationException::class, function () use ($catalog, $panel, $productId) {
    $catalog->createPlan([
        'control_panel_id' => $panel['id'], 'name' => 'Duplicate Mapping',
        'whmcs_product_id' => $productId,
    ]);
});
T::throws('a plan cannot be published before its panel', StateException::class, function () use ($catalog, $panel) {
    $draftPanel = $catalog->createPanel([
        'category_id' => Db::first('panel_categories', ['slug' => 'hosting-control-panels'])['id'],
        'name' => 'Draft Parent', 'vendor' => 'Example Vendor',
        'official_source_url' => 'https://vendor.example.test/draft-parent', 'catalog_status' => 'draft',
    ]);
    $catalog->createPlan([
        'control_panel_id' => $draftPanel['id'], 'name' => 'Early Plan', 'catalog_status' => 'published',
    ]);
});
$plan = $catalog->updatePlan($plan['id'], ['catalog_status' => 'published']);
T::is('published plan remains non-deployable', false, $plan['deployable']);

section('Customer catalog is record-driven and explicitly non-orderable');

$listing = $customerCatalog->customerCatalog();
T::is('published categories are rendered from stored rows', 1, count($listing['categories']));
T::is('published panel is rendered from stored rows', 1, count($listing['panels']));
T::is('published plan is nested under its panel', 1, count($listing['panels'][0]['plans']));
T::is('customer sees administrator-configured integration status', 'planned', $listing['panels'][0]['integration_status']);
T::is('customer sees administrator-configured installation status', 'metadata_only', $listing['panels'][0]['installation_status']);
T::ok('customer sees no deployable panel', !$listing['panels'][0]['deployable']);
T::ok('customer sees no orderable panel', !$listing['panels'][0]['orderable']);
T::ok('customer sees documented operating systems', in_array('Debian 12', $listing['panels'][0]['supported_os'], true));
T::ok('customer can see that a WHMCS price reference exists', $listing['panels'][0]['plans'][0]['price_reference_configured']);
T::ok('customer is not exposed to the internal WHMCS product id',
    !array_key_exists('whmcs_product_id', $listing['panels'][0]['plans'][0]));
T::is('availability flags make installation, licensing, and orders unavailable', [
    'integration_status' => 'not_implemented', 'installation_available' => false,
    'licensing_available' => false, 'ordering_available' => false, 'deployable' => false,
], $listing['availability']);

T::throws('a published category cannot be disabled while it contains a published panel', StateException::class, function () use ($catalog, $category) {
    $catalog->updateCategory($category['id'], ['active' => false]);
});

$panel = $catalog->updatePanel($panel['id'], ['catalog_status' => 'retired']);
T::is('retiring is a catalog-only status transition', 'retired', $panel['catalog_status']);
T::ok('retirement does not make the panel deployable', !$panel['deployable']);
$category = $catalog->updateCategory($category['id'], ['active' => false]);
T::is('administrator may disable an unused category', 0, (int) $category['active']);
T::is('retired/inactive records disappear from customer catalog', 0, count($customerCatalog->customerCatalog()['panels']));

section('Admin listing keeps the dedicated data manageable without changing App Cloud application catalog');

$adminData = $catalog->adminCatalog();
T::is('panel category remains stored after deactivation', 1, count($adminData['categories']));
T::ok('admin view contains the retired panel', count($adminData['panels']) >= 2);
T::ok('admin view contains the WHMCS reference', isset($adminData['panels'][0]['plans']));
T::is('original App Cloud application table is still separate and empty', 0, Db::count('applications'));
T::is('original App Cloud hosting plans table is still separate and empty', 0, Db::count('plans'));

exit(T::summary());
