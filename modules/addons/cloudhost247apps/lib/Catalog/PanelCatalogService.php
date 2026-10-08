<?php
/**
 * CloudHost247 App Cloud — control-panel catalog metadata.
 *
 * This is deliberately separate from the application catalog and application
 * plans. In this phase the service manages descriptive panel/plan records only:
 * it never installs panel software, activates a licence, creates a WHMCS order,
 * or reports a panel integration as available.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Catalog;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Integration\Gateway;
use Ch247Apps\Integration\GatewayInterface;

class PanelCatalogService
{
    const CATALOG_STATUSES = ['draft', 'published', 'retired'];
    const LICENSE_MODELS = ['commercial', 'open_source', 'byol', 'included', 'free', 'unknown'];
    // Safe descriptive states only. No admin setting can claim that an adapter or installer exists.
    const INTEGRATION_STATUSES = ['not_implemented', 'metadata_only', 'planned'];
    const INSTALLATION_STATUSES = ['not_implemented', 'metadata_only', 'planned'];
    const BILLING_CYCLES = [
        'free', 'hourly', 'monthly', 'quarterly', 'semiannually', 'annually',
        'biennially', 'triennially', 'onetime',
    ];
    const CAPABILITIES = [
        'account_management' => 'Account management',
        'reseller_management' => 'Reseller management',
        'dns_management' => 'DNS management',
        'database_management' => 'Database management',
        'email_management' => 'Email management',
        'backup_management' => 'Backup management',
        'ssl_automation' => 'SSL automation',
        'api_access' => 'API access',
        'multi_server_management' => 'Multi-server management',
        'wordpress_management' => 'WordPress management',
    ];

    /** @var Actor */
    private $actor;

    /** @var GatewayInterface */
    private $gateway;

    public function __construct(Actor $actor = null, GatewayInterface $gateway = null)
    {
        $this->actor = $actor ?: Actor::system('PanelCatalogService');
        $this->gateway = $gateway ?: Gateway::get();
    }

    /** Active categories with catalog-only published panel counts. */
    public function categories($includeInactive = false)
    {
        Rbac::assert($this->actor, $includeInactive ? Rbac::PANEL_CATALOG_MANAGE : Rbac::PANEL_CATALOG_VIEW);
        $where = ['deleted_at' => null];
        if (!$includeInactive) {
            $where['active'] = 1;
        }
        $rows = Db::fetch('panel_categories', $where, ['order' => 'sort_order', 'dir' => 'asc', 'order2' => 'name']);
        $counts = [];
        foreach (Db::select(
            'SELECT category_id, COUNT(*) AS total FROM ' . Db::quoteIdentifier(Db::t('control_panels'))
            . ' WHERE deleted_at IS NULL AND catalog_status = ? GROUP BY category_id',
            ['published']
        ) as $row) {
            $counts[(int) $row['category_id']] = (int) $row['total'];
        }
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['active'] = (bool) $row['active'];
            $row['sort_order'] = (int) $row['sort_order'];
            $row['published_panel_count'] = isset($counts[$row['id']]) ? $counts[$row['id']] : 0;
        }
        unset($row);
        return $rows;
    }

    /**
     * Customer/public catalog. Only published metadata in active categories is
     * returned; every panel and plan is hard-coded non-deployable in the
     * presentation even if a database row is edited outside this service.
     */
    public function customerCatalog()
    {
        Rbac::assert($this->actor, Rbac::PANEL_CATALOG_VIEW);
        $rows = Db::select(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug, '
            . 'c.description AS category_description '
            . 'FROM ' . Db::quoteIdentifier(Db::t('control_panels')) . ' AS p '
            . 'INNER JOIN ' . Db::quoteIdentifier(Db::t('panel_categories')) . ' AS c ON c.id = p.category_id '
            . 'WHERE p.catalog_status = ? AND p.deleted_at IS NULL '
            . 'AND c.active = 1 AND c.deleted_at IS NULL '
            . 'ORDER BY c.sort_order ASC, c.name ASC, p.sort_order ASC, p.name ASC',
            ['published']
        );

        $panels = [];
        $categories = [];
        foreach ($rows as $row) {
            $categoryId = (int) $row['category_id'];
            if (!isset($categories[$categoryId])) {
                $categories[$categoryId] = [
                    'id' => $categoryId,
                    'name' => (string) $row['category_name'],
                    'slug' => (string) $row['category_slug'],
                    'description' => (string) ($row['category_description'] ?: ''),
                    'panel_count' => 0,
                ];
            }
            $panel = $this->presentPanel($row, false);
            $panel['category'] = [
                'id' => $categoryId,
                'name' => (string) $row['category_name'],
                'slug' => (string) $row['category_slug'],
            ];
            $panel['plans'] = [];
            $planRows = Db::fetch('control_panel_plans', [
                'control_panel_id' => (int) $row['id'],
                'catalog_status' => 'published',
                'deleted_at' => null,
            ], ['order' => 'sort_order', 'dir' => 'asc', 'order2' => 'name']);
            foreach ($planRows as $planRow) {
                $panel['plans'][] = $this->presentPlan($planRow, false);
            }
            $panels[] = $panel;
            $categories[$categoryId]['panel_count']++;
        }

        return [
            'categories' => array_values($categories),
            'panels' => $panels,
            'availability' => [
                'integration_status' => 'not_implemented',
                'installation_available' => false,
                'licensing_available' => false,
                'ordering_available' => false,
                'deployable' => false,
            ],
        ];
    }

    /** Full admin view; WHMCS references are included, but no local prices. */
    public function adminCatalog()
    {
        $this->assertManage();
        $categories = $this->categories(true);
        $categoryNames = [];
        foreach ($categories as $category) {
            $categoryNames[(int) $category['id']] = $category['name'];
        }

        $panels = [];
        $rows = Db::fetch('control_panels', ['deleted_at' => null], [
            'order' => 'sort_order', 'dir' => 'asc', 'order2' => 'name',
        ]);
        foreach ($rows as $row) {
            $panel = $this->presentPanel($row, true);
            $panel['category_name'] = isset($categoryNames[(int) $row['category_id']])
                ? $categoryNames[(int) $row['category_id']] : '';
            $panel['plans'] = [];
            foreach (Db::fetch('control_panel_plans', [
                'control_panel_id' => (int) $row['id'], 'deleted_at' => null,
            ], ['order' => 'sort_order', 'dir' => 'asc', 'order2' => 'name']) as $planRow) {
                $panel['plans'][] = $this->presentPlan($planRow, true);
            }
            $panels[] = $panel;
        }
        return ['categories' => $categories, 'panels' => $panels];
    }

    public function createCategory(array $input)
    {
        $this->assertManage();
        $this->assertAllowedFields($input, ['name', 'slug', 'description', 'sort_order', 'active']);
        $values = $this->validateCategory($input);
        $this->assertUniqueSlug('panel_categories', $values['slug']);

        $now = Clock::now();
        $id = Db::insert('panel_categories', array_merge($values, [
            'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
        ]));
        Audit::record($this->actor, 'PANEL_CATEGORY_CREATED', [
            'resource_type' => 'panel_category', 'resource_id' => $id,
            'metadata' => ['slug' => $values['slug'], 'active' => (bool) $values['active']],
        ]);
        return $this->categoryRow($id);
    }

    public function updateCategory($categoryId, array $input)
    {
        $this->assertManage();
        $row = $this->categoryRow($categoryId);
        $this->assertAllowedFields($input, ['name', 'slug', 'description', 'sort_order', 'active']);
        $values = $this->validateCategory(array_merge($this->rawCategory($row), $input));
        $this->assertUniqueSlug('panel_categories', $values['slug'], (int) $row['id']);
        if (!$values['active'] && (int) $row['active'] === 1
            && Db::count('control_panels', [
                'category_id' => (int) $row['id'], 'catalog_status' => 'published', 'deleted_at' => null,
            ]) > 0) {
            throw new StateException(
                'Unpublish or move the published control panels before deactivating this category.',
                ['error_code' => 'PANEL_CATEGORY_HAS_PUBLISHED_PANELS']
            );
        }

        $values['updated_at'] = Clock::now();
        Db::update('panel_categories', $values, ['id' => (int) $row['id'], 'deleted_at' => null]);
        Audit::record($this->actor, 'PANEL_CATEGORY_UPDATED', [
            'resource_type' => 'panel_category', 'resource_id' => (int) $row['id'],
            'metadata' => ['changed' => array_values(array_unique(array_merge(array_keys($input), ['updated_at'])))],
        ]);
        return $this->categoryRow((int) $row['id']);
    }

    public function createPanel(array $input)
    {
        $this->assertManage();
        $this->assertEditablePanelInput($input);
        $values = $this->validatePanel($input);
        $this->assertUniqueSlug('control_panels', $values['slug']);
        $this->assertPanelPublishable($values);

        $now = Clock::now();
        $values['supported_os'] = Str::jsonEncode($values['supported_os']);
        $values['capabilities'] = Str::jsonEncode($values['capabilities']);
        $values['deployable'] = 0;
        $values['published_at'] = $values['catalog_status'] === 'published' ? $now : null;
        $id = Db::insert('control_panels', array_merge($values, [
            'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
        ]));
        Audit::record($this->actor, 'CONTROL_PANEL_CREATED', [
            'resource_type' => 'control_panel', 'resource_id' => $id,
            'metadata' => [
                'slug' => $values['slug'], 'catalog_status' => $values['catalog_status'],
                'integration_status' => $values['integration_status'],
                'installation_status' => $values['installation_status'], 'deployable' => false,
            ],
        ]);
        return $this->presentPanel($this->panelRow($id), true);
    }

    public function updatePanel($panelId, array $input)
    {
        $this->assertManage();
        $row = $this->panelRow($panelId);
        $this->assertEditablePanelInput($input);
        $values = $this->validatePanel(array_merge($this->rawPanel($row), $input));
        $this->assertUniqueSlug('control_panels', $values['slug'], (int) $row['id']);
        $this->assertPanelPublishable($values);

        $wasPublished = $row['catalog_status'] === 'published';
        $values['published_at'] = $values['catalog_status'] === 'published'
            ? ($wasPublished && !empty($row['published_at']) ? $row['published_at'] : Clock::now())
            : null;
        $values['supported_os'] = Str::jsonEncode($values['supported_os']);
        $values['capabilities'] = Str::jsonEncode($values['capabilities']);
        // The status fields are administrator-configurable metadata, but only
        // safe non-operational values are accepted and deployable stays false.
        $values['deployable'] = 0;
        $values['updated_at'] = Clock::now();
        Db::update('control_panels', $values, ['id' => (int) $row['id'], 'deleted_at' => null]);
        Audit::record($this->actor, 'CONTROL_PANEL_UPDATED', [
            'resource_type' => 'control_panel', 'resource_id' => (int) $row['id'],
            'metadata' => [
                'changed' => array_values(array_unique(array_merge(array_keys($input), ['deployable']))),
                'catalog_status' => $values['catalog_status'],
                'integration_status' => $values['integration_status'],
                'installation_status' => $values['installation_status'], 'deployable' => false,
            ],
        ]);
        return $this->presentPanel($this->panelRow((int) $row['id']), true);
    }

    public function createPlan(array $input)
    {
        $this->assertManage();
        $this->assertAllowedFields($input, $this->planFields());
        $values = $this->validatePlan($input);
        $this->assertUniquePlanSlug($values['control_panel_id'], $values['slug']);
        $this->assertUniqueWhmcsProduct($values['whmcs_product_id']);
        $this->assertPlanPublishable($values);

        $now = Clock::now();
        $values['deployable'] = 0;
        $values['published_at'] = $values['catalog_status'] === 'published' ? $now : null;
        $id = Db::insert('control_panel_plans', array_merge($values, [
            'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
        ]));
        Audit::record($this->actor, 'CONTROL_PANEL_PLAN_CREATED', [
            'resource_type' => 'control_panel_plan', 'resource_id' => $id,
            'metadata' => [
                'control_panel_id' => $values['control_panel_id'], 'slug' => $values['slug'],
                'whmcs_product_id' => $values['whmcs_product_id'], 'deployable' => false,
            ],
        ]);
        return $this->presentPlan($this->planRow($id), true);
    }

    public function updatePlan($planId, array $input)
    {
        $this->assertManage();
        $row = $this->planRow($planId);
        $this->assertAllowedFields($input, $this->planFields());
        $values = $this->validatePlan(array_merge($this->rawPlan($row), $input), (int) $row['id']);
        $this->assertUniquePlanSlug($values['control_panel_id'], $values['slug'], (int) $row['id']);
        $this->assertUniqueWhmcsProduct($values['whmcs_product_id'], (int) $row['id']);
        $this->assertPlanPublishable($values);

        $wasPublished = $row['catalog_status'] === 'published';
        $values['published_at'] = $values['catalog_status'] === 'published'
            ? ($wasPublished && !empty($row['published_at']) ? $row['published_at'] : Clock::now())
            : null;
        $values['deployable'] = 0;
        $values['updated_at'] = Clock::now();
        Db::update('control_panel_plans', $values, ['id' => (int) $row['id'], 'deleted_at' => null]);
        Audit::record($this->actor, 'CONTROL_PANEL_PLAN_UPDATED', [
            'resource_type' => 'control_panel_plan', 'resource_id' => (int) $row['id'],
            'metadata' => [
                'changed' => array_values(array_unique(array_merge(array_keys($input), ['deployable']))),
                'control_panel_id' => $values['control_panel_id'],
                'whmcs_product_id' => $values['whmcs_product_id'], 'deployable' => false,
            ],
        ]);
        return $this->presentPlan($this->planRow((int) $row['id']), true);
    }

    private function validateCategory(array $input)
    {
        $name = $this->text($input, 'name', 120, true);
        $slug = $this->slugValue($input, 'slug', $name, 80);
        if ($slug === 'item') {
            throw new ValidationException('Enter a category name or valid slug.', ['field' => 'slug']);
        }
        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $this->nullableText($input, 'description', 1200),
            'sort_order' => $this->integer($input, 'sort_order', 100, -1000000, 1000000),
            'active' => $this->boolean($input, 'active', true) ? 1 : 0,
        ];
    }

    private function validatePanel(array $input)
    {
        $name = $this->text($input, 'name', 160, true);
        $slug = $this->slugValue($input, 'slug', $name, 100);
        if ($slug === 'item') {
            throw new ValidationException('Enter a control-panel name or valid slug.', ['field' => 'slug']);
        }
        $categoryId = $this->nullablePositiveId($input, 'category_id');
        if ($categoryId !== null) {
            // Draft and retired records remain editable if an administrator has
            // since deactivated their category. Publishing is checked separately.
            $this->categoryRow($categoryId);
        }
        $licenseModel = $this->enumValue($input, 'license_model', 'unknown', self::LICENSE_MODELS);
        $status = $this->enumValue($input, 'catalog_status', 'draft', self::CATALOG_STATUSES);
        $integrationStatus = $this->enumValue(
            $input, 'integration_status', 'not_implemented', self::INTEGRATION_STATUSES,
            'Integration status must remain non-operational in this phase.'
        );
        $installationStatus = $this->enumValue(
            $input, 'installation_status', 'not_implemented', self::INSTALLATION_STATUSES,
            'Installation status must remain non-deployable in this phase.'
        );

        return [
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => $slug,
            'vendor' => $this->text($input, 'vendor', 160, true),
            'summary' => $this->nullableText($input, 'summary', 255),
            'description' => $this->nullableText($input, 'description', 5000),
            'official_source_url' => $this->httpsUrl($input, 'official_source_url', false),
            'documentation_url' => $this->httpsUrl($input, 'documentation_url', false),
            'support_url' => $this->httpsUrl($input, 'support_url', false),
            'license_model' => $licenseModel,
            'license_terms_url' => $this->httpsUrl($input, 'license_terms_url', false),
            'license_terms_summary' => $this->nullableText($input, 'license_terms_summary', 3000),
            'supported_os' => $this->textList($input, 'supported_os', 30, 100),
            'minimum_cpu_cores' => $this->integer($input, 'minimum_cpu_cores', 0, 0, 1024),
            'minimum_memory_mb' => $this->integer($input, 'minimum_memory_mb', 0, 0, 4194304),
            'minimum_storage_gb' => $this->integer($input, 'minimum_storage_gb', 0, 0, 1048576),
            'resource_notes' => $this->nullableText($input, 'resource_notes', 2000),
            'capabilities' => $this->capabilities($input),
            'catalog_status' => $status,
            'integration_status' => $integrationStatus,
            'installation_status' => $installationStatus,
            'sort_order' => $this->integer($input, 'sort_order', 100, -1000000, 1000000),
        ];
    }

    private function validatePlan(array $input, $existingId = null)
    {
        $panelId = $this->positiveId($input, 'control_panel_id');
        $this->panelRow($panelId);
        $name = $this->text($input, 'name', 160, true);
        $slug = $this->slugValue($input, 'slug', $name, 100);
        if ($slug === 'item') {
            throw new ValidationException('Enter a plan name or valid slug.', ['field' => 'slug']);
        }
        $status = $this->enumValue($input, 'catalog_status', 'draft', self::CATALOG_STATUSES);
        $productId = $this->nullablePositiveId($input, 'whmcs_product_id');
        if ($productId !== null) {
            $existing = $existingId ? Db::first('control_panel_plans', ['id' => (int) $existingId]) : null;
            if (!$existing || (int) $existing['whmcs_product_id'] !== $productId) {
                $this->assertWhmcsProductExists($productId);
            }
        }
        $cycle = $this->enumValue(
            $input, 'billing_cycle', 'monthly', self::BILLING_CYCLES,
            'Choose a supported WHMCS billing cycle reference.'
        );

        return [
            'control_panel_id' => $panelId,
            'name' => $name,
            'slug' => $slug,
            'summary' => $this->nullableText($input, 'summary', 255),
            'description' => $this->nullableText($input, 'description', 4000),
            'whmcs_product_id' => $productId,
            'billing_cycle' => $cycle,
            'cpu_cores' => $this->integer($input, 'cpu_cores', 0, 0, 1024),
            'memory_mb' => $this->integer($input, 'memory_mb', 0, 0, 4194304),
            'storage_gb' => $this->integer($input, 'storage_gb', 0, 0, 1048576),
            'bandwidth_gb' => $this->integer($input, 'bandwidth_gb', 0, 0, 1048576),
            'max_accounts' => $this->integer($input, 'max_accounts', 0, 0, 1000000),
            'catalog_status' => $status,
            'sort_order' => $this->integer($input, 'sort_order', 100, -1000000, 1000000),
        ];
    }

    private function assertPanelPublishable(array $values)
    {
        if ($values['catalog_status'] !== 'published') {
            return;
        }
        if (empty($values['category_id'])) {
            throw new ValidationException('Assign a category before publishing a control panel.', ['field' => 'category_id']);
        }
        $category = $this->categoryRow((int) $values['category_id']);
        if (!(bool) $category['active']) {
            throw new ValidationException('Choose an active control-panel category before publishing.', ['field' => 'category_id']);
        }
        if (empty($values['official_source_url'])) {
            throw new ValidationException('An official HTTPS source is required before publishing a control panel.', [
                'field' => 'official_source_url',
            ]);
        }
    }

    private function assertPlanPublishable(array $values)
    {
        if ($values['catalog_status'] !== 'published') {
            return;
        }
        $panel = $this->panelRow((int) $values['control_panel_id']);
        if ($panel['catalog_status'] !== 'published') {
            throw new StateException('Publish the control-panel catalog record before publishing its plan.', [
                'error_code' => 'CONTROL_PANEL_NOT_PUBLISHED',
            ]);
        }
    }

    private function assertWhmcsProductExists($productId)
    {
        try {
            $product = $this->gateway->getProduct((int) $productId);
        } catch (\Throwable $e) {
            throw new StateException('WHMCS could not verify that product reference. No catalog change was saved.', [
                'error_code' => 'WHMCS_PRODUCT_LOOKUP_FAILED',
            ], $e);
        }
        if (!$product) {
            throw new ValidationException('That WHMCS product reference does not exist.', [
                'field' => 'whmcs_product_id', 'error_code' => 'WHMCS_PRODUCT_NOT_FOUND',
            ]);
        }
    }

    private function assertManage()
    {
        Rbac::assert($this->actor, Rbac::PANEL_CATALOG_MANAGE);
    }

    private function assertEditablePanelInput(array $input)
    {
        foreach (['deployable', 'install_enabled', 'license_key', 'license_status', 'price', 'price_minor'] as $field) {
            if (array_key_exists($field, $input)) {
                throw new ValidationException(
                    'Installation enablement, license operations, deployment, and local price fields are not configurable in this phase.',
                    ['field' => $field, 'error_code' => 'PANEL_INTEGRATION_NOT_IMPLEMENTED']
                );
            }
        }
        $this->assertAllowedFields($input, [
            'category_id', 'name', 'slug', 'vendor', 'summary', 'description',
            'official_source_url', 'documentation_url', 'support_url', 'license_model',
            'license_terms_url', 'license_terms_summary', 'supported_os',
            'minimum_cpu_cores', 'minimum_memory_mb', 'minimum_storage_gb', 'resource_notes',
            'capabilities', 'catalog_status', 'integration_status', 'installation_status', 'sort_order',
        ]);
    }

    private function planFields()
    {
        return [
            'control_panel_id', 'name', 'slug', 'summary', 'description', 'whmcs_product_id',
            'billing_cycle', 'cpu_cores', 'memory_mb', 'storage_gb', 'bandwidth_gb',
            'max_accounts', 'catalog_status', 'sort_order',
        ];
    }

    private function assertAllowedFields(array $input, array $allowed)
    {
        foreach (array_keys($input) as $field) {
            if (!in_array((string) $field, $allowed, true)) {
                throw new ValidationException('Field ' . (string) $field . ' is not supported for this catalog record.', [
                    'field' => (string) $field,
                ]);
            }
        }
    }

    private function assertUniqueSlug($table, $slug, $exceptId = null)
    {
        $row = Db::first($table, ['slug' => (string) $slug, 'deleted_at' => null]);
        if ($row && ($exceptId === null || (int) $row['id'] !== (int) $exceptId)) {
            throw new ValidationException('That slug is already in use.', ['field' => 'slug', 'slug' => $slug]);
        }
    }

    private function assertUniquePlanSlug($panelId, $slug, $exceptId = null)
    {
        $row = Db::first('control_panel_plans', [
            'control_panel_id' => (int) $panelId, 'slug' => (string) $slug, 'deleted_at' => null,
        ]);
        if ($row && ($exceptId === null || (int) $row['id'] !== (int) $exceptId)) {
            throw new ValidationException('That plan slug is already in use for this control panel.', [
                'field' => 'slug', 'slug' => $slug,
            ]);
        }
    }

    private function assertUniqueWhmcsProduct($productId, $exceptId = null)
    {
        if ($productId === null) {
            return;
        }
        $row = Db::first('control_panel_plans', ['whmcs_product_id' => (int) $productId]);
        if ($row && ($exceptId === null || (int) $row['id'] !== (int) $exceptId)) {
            throw new ValidationException('That WHMCS product is already referenced by another control-panel plan.', [
                'field' => 'whmcs_product_id', 'error_code' => 'WHMCS_PRODUCT_ALREADY_LINKED',
            ]);
        }
    }

    private function categoryRow($categoryId)
    {
        $row = Db::first('panel_categories', ['id' => (int) $categoryId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That control-panel category does not exist.');
        }
        return $row;
    }

    private function panelRow($panelId)
    {
        $row = Db::first('control_panels', ['id' => (int) $panelId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That control-panel catalog record does not exist.');
        }
        return $row;
    }

    private function planRow($planId)
    {
        $row = Db::first('control_panel_plans', ['id' => (int) $planId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That control-panel plan does not exist.');
        }
        return $row;
    }

    private function rawCategory(array $row)
    {
        return [
            'name' => (string) $row['name'], 'slug' => (string) $row['slug'],
            'description' => (string) ($row['description'] ?: ''),
            'sort_order' => (int) $row['sort_order'], 'active' => (bool) $row['active'],
        ];
    }

    private function rawPanel(array $row)
    {
        return [
            'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
            'name' => (string) $row['name'], 'slug' => (string) $row['slug'],
            'vendor' => (string) $row['vendor'], 'summary' => (string) ($row['summary'] ?: ''),
            'description' => (string) ($row['description'] ?: ''),
            'official_source_url' => (string) ($row['official_source_url'] ?: ''),
            'documentation_url' => (string) ($row['documentation_url'] ?: ''),
            'support_url' => (string) ($row['support_url'] ?: ''),
            'license_model' => (string) $row['license_model'],
            'license_terms_url' => (string) ($row['license_terms_url'] ?: ''),
            'license_terms_summary' => (string) ($row['license_terms_summary'] ?: ''),
            'supported_os' => Str::jsonDecode($row['supported_os'], []),
            'minimum_cpu_cores' => (int) $row['minimum_cpu_cores'],
            'minimum_memory_mb' => (int) $row['minimum_memory_mb'],
            'minimum_storage_gb' => (int) $row['minimum_storage_gb'],
            'resource_notes' => (string) ($row['resource_notes'] ?: ''),
            'capabilities' => Str::jsonDecode($row['capabilities'], []),
            'catalog_status' => (string) $row['catalog_status'],
            'integration_status' => (string) $row['integration_status'],
            'installation_status' => (string) $row['installation_status'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    private function rawPlan(array $row)
    {
        return [
            'control_panel_id' => (int) $row['control_panel_id'],
            'name' => (string) $row['name'], 'slug' => (string) $row['slug'],
            'summary' => (string) ($row['summary'] ?: ''),
            'description' => (string) ($row['description'] ?: ''),
            'whmcs_product_id' => $row['whmcs_product_id'] === null ? null : (int) $row['whmcs_product_id'],
            'billing_cycle' => (string) $row['billing_cycle'],
            'cpu_cores' => (int) $row['cpu_cores'], 'memory_mb' => (int) $row['memory_mb'],
            'storage_gb' => (int) $row['storage_gb'], 'bandwidth_gb' => (int) $row['bandwidth_gb'],
            'max_accounts' => (int) $row['max_accounts'],
            'catalog_status' => (string) $row['catalog_status'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    private function presentPanel(array $row, $admin)
    {
        $panel = [
            'id' => (int) $row['id'], 'name' => (string) $row['name'], 'slug' => (string) $row['slug'],
            'vendor' => (string) $row['vendor'], 'summary' => (string) ($row['summary'] ?: ''),
            'description' => (string) ($row['description'] ?: ''),
            'official_source_url' => (string) ($row['official_source_url'] ?: ''),
            'documentation_url' => (string) ($row['documentation_url'] ?: ''),
            'support_url' => (string) ($row['support_url'] ?: ''),
            'license_model' => (string) $row['license_model'],
            'license_terms_url' => (string) ($row['license_terms_url'] ?: ''),
            'license_terms_summary' => (string) ($row['license_terms_summary'] ?: ''),
            'supported_os' => Str::jsonDecode($row['supported_os'], []),
            'requirements' => [
                'minimum_cpu_cores' => (int) $row['minimum_cpu_cores'],
                'minimum_memory_mb' => (int) $row['minimum_memory_mb'],
                'minimum_storage_gb' => (int) $row['minimum_storage_gb'],
                'notes' => (string) ($row['resource_notes'] ?: ''),
            ],
            'capabilities' => $this->normaliseStoredCapabilities(Str::jsonDecode($row['capabilities'], [])),
            'catalog_status' => (string) $row['catalog_status'],
            // Only non-operational values are permitted; an unknown database value
            // fails closed rather than being surfaced as support.
            'integration_status' => $this->safeStatus($row['integration_status'], self::INTEGRATION_STATUSES),
            'installation_status' => $this->safeStatus($row['installation_status'], self::INSTALLATION_STATUSES),
            'deployable' => false,
            'orderable' => false,
            'sort_order' => (int) $row['sort_order'],
            'published_at' => isset($row['published_at']) ? $row['published_at'] : null,
        ];
        if ($admin) {
            $panel['category_id'] = $row['category_id'] === null ? null : (int) $row['category_id'];
            $panel['created_at'] = isset($row['created_at']) ? $row['created_at'] : null;
            $panel['updated_at'] = isset($row['updated_at']) ? $row['updated_at'] : null;
        }
        return $panel;
    }

    private function presentPlan(array $row, $admin)
    {
        $plan = [
            'id' => (int) $row['id'], 'name' => (string) $row['name'],
            'slug' => (string) $row['slug'], 'summary' => (string) ($row['summary'] ?: ''),
            'description' => (string) ($row['description'] ?: ''),
            'resources' => [
                'cpu_cores' => (int) $row['cpu_cores'], 'memory_mb' => (int) $row['memory_mb'],
                'storage_gb' => (int) $row['storage_gb'], 'bandwidth_gb' => (int) $row['bandwidth_gb'],
                'max_accounts' => (int) $row['max_accounts'],
            ],
            'catalog_status' => (string) $row['catalog_status'],
            'price_reference_configured' => $row['whmcs_product_id'] !== null
                && (int) $row['whmcs_product_id'] > 0,
            'deployable' => false,
            'orderable' => false,
            'sort_order' => (int) $row['sort_order'],
        ];
        if ($admin) {
            $plan['control_panel_id'] = (int) $row['control_panel_id'];
            $plan['whmcs_product_id'] = $row['whmcs_product_id'] === null ? null : (int) $row['whmcs_product_id'];
            $plan['billing_cycle'] = (string) $row['billing_cycle'];
            $plan['published_at'] = isset($row['published_at']) ? $row['published_at'] : null;
        }
        return $plan;
    }

    private function normaliseStoredCapabilities(array $stored)
    {
        $out = [];
        foreach (self::CAPABILITIES as $key => $label) {
            $out[$key] = isset($stored[$key])
                && in_array($stored[$key], [true, 1, '1', 'on', 'true'], true);
        }
        return $out;
    }

    private function safeStatus($value, array $allowed)
    {
        $value = (string) $value;
        return in_array($value, $allowed, true) ? $value : 'not_implemented';
    }

    private function slugValue(array $input, $field, $fallback, $maxLength)
    {
        if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null) {
            return Str::slug($fallback, $maxLength);
        }
        if (!is_scalar($input[$field])) {
            throw new ValidationException('Enter a text slug for ' . $field . '.', ['field' => $field]);
        }
        return Str::slug($input[$field], $maxLength);
    }

    private function enumValue(array $input, $field, $default, array $allowed, $message = null)
    {
        $value = array_key_exists($field, $input) ? $input[$field] : $default;
        if (!is_scalar($value)) {
            throw new ValidationException('Choose a valid value for ' . $field . '.', ['field' => $field]);
        }
        $value = strtolower(trim((string) $value));
        if (!in_array($value, $allowed, true)) {
            throw new ValidationException($message ?: 'Choose a supported value for ' . $field . '.', ['field' => $field]);
        }
        return $value;
    }

    private function text(array $input, $field, $max, $required)
    {
        $value = isset($input[$field]) && is_scalar($input[$field]) ? Str::cleanText($input[$field], $max) : '';
        if ($required && $value === '') {
            throw new ValidationException('A value is required for ' . $field . '.', ['field' => $field]);
        }
        return $value;
    }

    private function nullableText(array $input, $field, $max)
    {
        $value = $this->text($input, $field, $max, false);
        return $value === '' ? null : $value;
    }

    private function httpsUrl(array $input, $field, $required)
    {
        $value = isset($input[$field]) && is_scalar($input[$field]) ? trim((string) $input[$field]) : '';
        if ($value === '') {
            if ($required) {
                throw new ValidationException('A value is required for ' . $field . '.', ['field' => $field]);
            }
            return null;
        }
        $parts = parse_url($value);
        if (strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || empty($parts['host']) || empty($parts['scheme'])
            || strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationException('Enter a valid HTTPS URL without embedded credentials.', ['field' => $field]);
        }
        return $value;
    }

    private function textList(array $input, $field, $maxItems, $maxLength)
    {
        $values = isset($input[$field]) ? $input[$field] : [];
        if (is_string($values)) {
            $values = preg_split('/\r\n|\r|\n/', $values);
        }
        if (!is_array($values)) {
            throw new ValidationException('Enter a list of values for ' . $field . '.', ['field' => $field]);
        }
        $out = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                throw new ValidationException('Every ' . $field . ' entry must be text.', ['field' => $field]);
            }
            $value = Str::cleanText($value, $maxLength);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
            if (count($out) > $maxItems) {
                throw new ValidationException('Too many values were supplied for ' . $field . '.', ['field' => $field]);
            }
        }
        return $out;
    }

    private function capabilities(array $input)
    {
        $values = isset($input['capabilities']) ? $input['capabilities'] : [];
        if (!is_array($values)) {
            throw new ValidationException('Capabilities must be a keyed set of boolean flags.', ['field' => 'capabilities']);
        }
        foreach (array_keys($values) as $key) {
            if (!array_key_exists((string) $key, self::CAPABILITIES)) {
                throw new ValidationException('Unknown panel capability flag.', ['field' => 'capabilities']);
            }
        }
        $out = [];
        foreach (self::CAPABILITIES as $key => $label) {
            $out[$key] = isset($values[$key]) ? $this->parseBoolean($values[$key], 'capabilities.' . $key) : false;
        }
        return $out;
    }

    private function integer(array $input, $field, $default, $min, $max)
    {
        if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null) {
            return (int) $default;
        }
        $value = $input[$field];
        if (is_bool($value) || !is_scalar($value) || !preg_match('/^-?[0-9]+$/', trim((string) $value))) {
            throw new ValidationException('Enter a whole number for ' . $field . '.', ['field' => $field]);
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new ValidationException('The value for ' . $field . ' is outside the allowed range.', ['field' => $field]);
        }
        return $value;
    }

    private function positiveId(array $input, $field)
    {
        $id = $this->integer($input, $field, 0, 1, PHP_INT_MAX);
        if ($id < 1) {
            throw new ValidationException('A valid record id is required for ' . $field . '.', ['field' => $field]);
        }
        return $id;
    }

    private function nullablePositiveId(array $input, $field)
    {
        if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null || $input[$field] === 0 || $input[$field] === '0') {
            return null;
        }
        return $this->positiveId($input, $field);
    }

    private function boolean(array $input, $field, $default)
    {
        return array_key_exists($field, $input) ? $this->parseBoolean($input[$field], $field) : (bool) $default;
    }

    private function parseBoolean($value, $field)
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === '' || $value === 'false') {
            return false;
        }
        throw new ValidationException('Enter a valid boolean value for ' . $field . '.', ['field' => $field]);
    }
}
