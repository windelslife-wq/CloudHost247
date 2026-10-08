<?php
/**
 * WHMCS admin page for the App Cloud control-panel catalog.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Http;

use Ch247Apps\Catalog\PanelCatalogService;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Csrf;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Str;

class PanelCatalogAdmin
{
    private $vars;
    private $actor;
    private $catalog;
    private $baseUrl;

    public function __construct(array $vars = [])
    {
        $this->vars = $vars;
        $this->actor = Identity::current();
        $this->catalog = new PanelCatalogService($this->actor);
        $this->baseUrl = isset($vars['modulelink']) && is_scalar($vars['modulelink'])
            ? (string) $vars['modulelink'] : 'addonmodules.php?module=cloudhost247apps';
    }

    public function render()
    {
        try {
            Rbac::assert($this->actor, Rbac::PANEL_CATALOG_MANAGE);
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">You do not have permission to manage the control-panel catalog.</div>';
            return;
        }

        $notice = $this->handlePost();
        try {
            $data = $this->catalog->adminCatalog();
        } catch (\Throwable $e) {
            Logger::warning('Control-panel catalog admin page could not load.', [
                'exception' => get_class($e), 'source' => 'panel_catalog',
            ]);
            echo '<div class="alert alert-danger">The control-panel catalog is not ready. Confirm that App Cloud migrations completed.</div>';
            return;
        }

        $selectedCategoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
        if ($selectedCategoryId <= 0 && isset($_POST['category_record_id'])) {
            $selectedCategoryId = (int) $_POST['category_record_id'];
        }
        $selectedCategory = null;
        foreach ($data['categories'] as $category) {
            if ((int) $category['id'] === $selectedCategoryId) {
                $selectedCategory = $category;
                break;
            }
        }

        $selectedPanelId = isset($_GET['panel_id']) ? (int) $_GET['panel_id'] : 0;
        $selectedPlanId = isset($_GET['plan_id']) ? (int) $_GET['plan_id'] : 0;
        if ($selectedPanelId <= 0 && isset($_POST['panel_id'])) {
            $selectedPanelId = (int) $_POST['panel_id'];
        }
        if ($selectedPlanId <= 0 && isset($_POST['plan_id'])) {
            $selectedPlanId = (int) $_POST['plan_id'];
        }
        $selectedPanel = null;
        $selectedPlan = null;
        foreach ($data['panels'] as $panel) {
            if ((int) $panel['id'] === $selectedPanelId) {
                $selectedPanel = $panel;
            }
            foreach ($panel['plans'] as $plan) {
                if ((int) $plan['id'] === $selectedPlanId) {
                    $selectedPanel = $panel;
                    $selectedPlan = $plan;
                }
            }
        }

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Control-panel catalog</strong></div><div class="panel-body">';
        echo '<div class="alert alert-warning"><strong>Catalog-only phase.</strong> Publishing makes metadata visible; it does not enable checkout, '
            . 'installation, provisioning, or license activation. WHMCS product IDs below are price references only; WHMCS remains the billing source of truth.</div>';
        if ($notice !== '') {
            echo $notice;
        }
        echo '<p><a class="btn btn-default btn-sm" href="' . $this->e($this->baseUrl) . '">App Cloud overview</a></p>';
        $this->renderCategories($data['categories'], $selectedCategory);
        $this->renderPanels($data['categories'], $data['panels'], $selectedPanel, $selectedPlan);
        echo '</div></div>';
    }

    private function handlePost()
    {
        if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST'
            || !isset($_POST['catalog_action'])) {
            return '';
        }

        try {
            Csrf::verify(isset($_POST['ch247_token']) && is_string($_POST['ch247_token']) ? $_POST['ch247_token'] : null);
            $action = (string) $_POST['catalog_action'];
            switch ($action) {
                case 'category_save':
                    $input = $this->pick($_POST, ['name', 'slug', 'description', 'sort_order', 'active']);
                    $id = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
                    if ($id > 0) {
                        $this->catalog->updateCategory($id, $input);
                        return '<div class="alert alert-success">Control-panel category updated. Published panels remain non-deployable.</div>';
                    }
                    $this->catalog->createCategory($input);
                    return '<div class="alert alert-success">Control-panel category created.</div>';

                case 'panel_save':
                    $input = $this->pick($_POST, [
                        'category_id', 'name', 'slug', 'vendor', 'summary', 'description',
                        'official_source_url', 'documentation_url', 'support_url', 'license_model',
                        'license_terms_url', 'license_terms_summary', 'minimum_cpu_cores',
                        'minimum_memory_mb', 'minimum_storage_gb', 'resource_notes',
                        'catalog_status', 'integration_status', 'installation_status', 'sort_order',
                    ]);
                    $input['supported_os'] = isset($_POST['supported_os']) ? (string) $_POST['supported_os'] : '';
                    $input['capabilities'] = isset($_POST['capabilities']) && is_array($_POST['capabilities'])
                        ? $_POST['capabilities'] : [];
                    $id = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
                    if ($id > 0) {
                        $this->catalog->updatePanel($id, $input);
                        return '<div class="alert alert-success">Control-panel metadata updated. Installation and licensing remain unavailable.</div>';
                    }
                    $this->catalog->createPanel($input);
                    return '<div class="alert alert-success">Control-panel metadata created as a non-deployable catalog record.</div>';

                case 'plan_save':
                    $input = $this->pick($_POST, [
                        'control_panel_id', 'name', 'slug', 'summary', 'description',
                        'whmcs_product_id', 'billing_cycle', 'cpu_cores', 'memory_mb',
                        'storage_gb', 'bandwidth_gb', 'max_accounts', 'catalog_status', 'sort_order',
                    ]);
                    $id = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
                    if ($id > 0) {
                        $this->catalog->updatePlan($id, $input);
                        return '<div class="alert alert-success">Control-panel plan metadata updated. No order or installation was created.</div>';
                    }
                    $this->catalog->createPlan($input);
                    return '<div class="alert alert-success">Control-panel plan created. The WHMCS product reference remains non-orderable here.</div>';

                default:
                    return '<div class="alert alert-danger">Unsupported catalog action.</div>';
            }
        } catch (AppsException $e) {
            return '<div class="alert alert-danger">' . $this->e($e->getMessage()) . '</div>';
        } catch (\Throwable $e) {
            Logger::error('Control-panel catalog admin write failed.', [
                'exception' => get_class($e), 'source' => 'panel_catalog',
            ]);
            return '<div class="alert alert-danger">The catalog change could not be saved. Check the WHMCS activity log and try again.</div>';
        }
    }

    private function renderCategories(array $categories, $selectedCategory)
    {
        echo '<h3>Categories</h3><div class="table-responsive"><table class="table table-striped">'
            . '<thead><tr><th>Name</th><th>Slug</th><th>Published panels</th><th>State</th><th>Action</th></tr></thead><tbody>';
        if (!$categories) {
            echo '<tr><td colspan="5">No panel categories have been configured.</td></tr>';
        }
        foreach ($categories as $category) {
            echo '<tr><td>' . $this->e($category['name']) . '</td><td><code>' . $this->e($category['slug']) . '</code></td>'
                . '<td>' . (int) $category['published_panel_count'] . '</td><td>'
                . (!empty($category['active']) ? 'Active' : 'Inactive') . '</td><td>'
                . '<a class="btn btn-xs btn-default" href="' . $this->e($this->catalogUrl(['category_id' => (int) $category['id']]))
                . '">Edit</a> ';
            if (empty($category['active'])) {
                echo '<form method="post" action="' . $this->e($this->catalogUrl()) . '" class="form-inline">'
                    . Csrf::field() . '<input type="hidden" name="catalog_action" value="category_save">'
                    . '<input type="hidden" name="record_id" value="' . (int) $category['id'] . '">'
                    . '<input type="hidden" name="name" value="' . $this->e($category['name']) . '">'
                    . '<input type="hidden" name="slug" value="' . $this->e($category['slug']) . '">'
                    . '<input type="hidden" name="description" value="' . $this->e($category['description']) . '">'
                    . '<input type="hidden" name="sort_order" value="' . (int) $category['sort_order'] . '">'
                    . '<input type="hidden" name="active" value="1"><button class="btn btn-xs btn-success" type="submit">Activate</button></form>';
            } else {
                echo '<form method="post" action="' . $this->e($this->catalogUrl()) . '" class="form-inline">'
                    . Csrf::field() . '<input type="hidden" name="catalog_action" value="category_save">'
                    . '<input type="hidden" name="record_id" value="' . (int) $category['id'] . '">'
                    . '<input type="hidden" name="name" value="' . $this->e($category['name']) . '">'
                    . '<input type="hidden" name="slug" value="' . $this->e($category['slug']) . '">'
                    . '<input type="hidden" name="description" value="' . $this->e($category['description']) . '">'
                    . '<input type="hidden" name="sort_order" value="' . (int) $category['sort_order'] . '">'
                    . '<input type="hidden" name="active" value="0"><button class="btn btn-xs btn-warning" type="submit"'
                    . (!empty($category['published_panel_count']) ? ' disabled title="Unpublish or move published panels first"' : '')
                    . '>Deactivate</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';

        $category = is_array($selectedCategory) ? $selectedCategory : [
            'id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'sort_order' => 100, 'active' => true,
        ];
        $isEditing = !empty($category['id']);
        echo '<h4>' . ($isEditing ? 'Edit category' : 'Add category') . '</h4>'
            . '<form method="post" action="' . $this->e($this->catalogUrl()) . '" class="form-horizontal">' . Csrf::field()
            . '<input type="hidden" name="catalog_action" value="category_save">'
            . '<input type="hidden" name="record_id" value="' . (int) $category['id'] . '">'
            . '<input type="hidden" name="category_record_id" value="' . (int) $category['id'] . '">';
        $this->formInput('Category name', 'name', $category['name'], true);
        $this->formInput('Slug', 'slug', $category['slug'], false, 'Leave blank to derive from the category name.');
        $this->formTextarea('Description', 'description', $category['description'], 2);
        $this->formInput('Sort order', 'sort_order', $category['sort_order'], false, '', 'number');
        echo '<div class="form-group"><div class="col-sm-offset-3 col-sm-9">'
            . '<input type="hidden" name="active" value="0">'
            . '<label><input type="checkbox" name="active" value="1"' . (!empty($category['active']) ? ' checked' : '')
            . '> Active</label> <button class="btn btn-primary" type="submit">'
            . ($isEditing ? 'Save category' : 'Add category') . '</button></div></div></form>';
    }

    private function renderPanels(array $categories, array $panels, $selectedPanel, $selectedPlan)
    {
        echo '<hr><h3>Control panels</h3><div class="table-responsive"><table class="table table-striped">'
            . '<thead><tr><th>Panel</th><th>Category</th><th>Catalog</th><th>Integration</th><th>Plans</th><th>Manage</th></tr></thead><tbody>';
        if (!$panels) {
            echo '<tr><td colspan="6">No control-panel records exist yet. Add an entry below; no starter entry is fabricated.</td></tr>';
        }
        foreach ($panels as $panel) {
            echo '<tr><td><strong>' . $this->e($panel['name']) . '</strong><br><small>' . $this->e($panel['vendor'])
                . ' &middot; <code>' . $this->e($panel['slug']) . '</code></small></td>'
                . '<td>' . $this->e($panel['category_name'] ?: '—') . '</td>'
                . '<td>' . $this->e($panel['catalog_status']) . '</td>'
                . '<td><span class="label label-default">' . $this->e($panel['integration_status']) . '</span>'
                . '<br><small>installation: ' . $this->e($panel['installation_status']) . ' &middot; not deployable</small></td>'
                . '<td>' . count($panel['plans']) . '</td><td><a class="btn btn-xs btn-default" href="'
                . $this->e($this->catalogUrl(['panel_id' => (int) $panel['id']])) . '">Edit / plans</a></td></tr>';
        }
        echo '</tbody></table></div>';

        $panel = is_array($selectedPanel) ? $selectedPanel : $this->emptyPanel();
        $isEditing = !empty($panel['id']);
        echo '<h4>' . ($isEditing ? 'Edit control-panel record' : 'Add control-panel record') . '</h4>';
        echo '<form method="post" action="' . $this->e($this->catalogUrl()) . '" class="form-horizontal">' . Csrf::field()
            . '<input type="hidden" name="catalog_action" value="panel_save">'
            . '<input type="hidden" name="record_id" value="' . (int) $panel['id'] . '">'
            . '<input type="hidden" name="panel_id" value="' . (int) $panel['id'] . '">';
        $this->formInput('Name', 'name', $panel['name'], true);
        $this->formInput('Slug', 'slug', $panel['slug'], false, 'Leave blank to derive from the name.');
        $this->formInput('Vendor', 'vendor', $panel['vendor'], true);
        echo '<div class="form-group"><label class="col-sm-3 control-label" for="panel-category-id">Category</label><div class="col-sm-9">'
            . '<select class="form-control" id="panel-category-id" name="category_id"><option value="">Unassigned (draft only)</option>';
        foreach ($categories as $category) {
            $selected = (int) $category['id'] === (int) $panel['category_id'] ? ' selected' : '';
            $disabled = empty($category['active']) ? ' disabled' : '';
            echo '<option value="' . (int) $category['id'] . '"' . $selected . $disabled . '>'
                . $this->e($category['name']) . (empty($category['active']) ? ' (inactive)' : '') . '</option>';
        }
        echo '</select><p class="help-block">Published panels must belong to an active category.</p></div></div>';
        $this->formInput('Short summary', 'summary', $panel['summary'], false);
        $this->formTextarea('Description', 'description', $panel['description'], 4);
        $this->formInput('Official source URL (HTTPS)', 'official_source_url', $panel['official_source_url'], false, 'Required before publication.');
        $this->formInput('Documentation URL (HTTPS)', 'documentation_url', $panel['documentation_url']);
        $this->formInput('Support URL (HTTPS)', 'support_url', $panel['support_url']);
        echo '<div class="form-group"><label class="col-sm-3 control-label" for="panel-license-model">License model</label><div class="col-sm-9"><select class="form-control" id="panel-license-model" name="license_model">';
        foreach (PanelCatalogService::LICENSE_MODELS as $license) {
            echo '<option value="' . $this->e($license) . '"' . ($panel['license_model'] === $license ? ' selected' : '') . '>'
                . $this->e(ucwords(str_replace('_', ' ', $license))) . '</option>';
        }
        echo '</select><p class="help-block">Metadata only; this module does not activate, validate, or renew licenses.</p></div></div>';
        $this->formInput('License terms URL (HTTPS)', 'license_terms_url', $panel['license_terms_url']);
        $this->formTextarea('License terms summary', 'license_terms_summary', $panel['license_terms_summary'], 3);
        $this->formTextarea('Supported operating systems', 'supported_os', implode("\n", $panel['supported_os']), 3, 'One entry per line.');
        $this->formInput('Minimum CPU cores', 'minimum_cpu_cores', $panel['requirements']['minimum_cpu_cores'], false, '', 'number');
        $this->formInput('Minimum memory (MiB)', 'minimum_memory_mb', $panel['requirements']['minimum_memory_mb'], false, '', 'number');
        $this->formInput('Minimum storage (GiB)', 'minimum_storage_gb', $panel['requirements']['minimum_storage_gb'], false, '', 'number');
        $this->formTextarea('Resource requirement notes', 'resource_notes', $panel['requirements']['notes'], 2);
        echo '<div class="form-group"><label class="col-sm-3 control-label">Documented capabilities</label><div class="col-sm-9"><div class="row">';
        foreach (PanelCatalogService::CAPABILITIES as $key => $label) {
            $checked = !empty($panel['capabilities'][$key]) ? ' checked' : '';
            echo '<div class="col-sm-6"><input type="hidden" name="capabilities[' . $this->e($key) . ']" value="0">'
                . '<label><input type="checkbox" name="capabilities[' . $this->e($key) . ']" value="1"' . $checked . '> '
                . $this->e($label) . '</label></div>';
        }
        echo '</div><p class="help-block">Describe verified product features only; these are not implemented CloudHost247 integrations.</p></div></div>';
        $this->formSelect('Catalog status', 'catalog_status', $panel['catalog_status'], PanelCatalogService::CATALOG_STATUSES);
        $this->formSelect('Integration status', 'integration_status', $panel['integration_status'], PanelCatalogService::INTEGRATION_STATUSES);
        $this->formSelect('Installation status', 'installation_status', $panel['installation_status'], PanelCatalogService::INSTALLATION_STATUSES);
        $this->formInput('Sort order', 'sort_order', $panel['sort_order'], false, '', 'number');
        echo '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary" type="submit">'
            . ($isEditing ? 'Save panel metadata' : 'Create panel record') . '</button> '
            . '<span class="text-muted">Only non-operational status values are allowed; deployable is always false.</span></div></div></form>';

        if ($isEditing) {
            $this->renderPlans($panel, $selectedPlan);
        }
    }

    private function renderPlans(array $panel, $selectedPlan)
    {
        echo '<h4>Plans for ' . $this->e($panel['name']) . '</h4>';
        echo '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Plan</th><th>Catalog</th><th>WHMCS reference</th><th>Resources</th><th></th></tr></thead><tbody>';
        if (!$panel['plans']) {
            echo '<tr><td colspan="5">No control-panel plans are configured.</td></tr>';
        }
        foreach ($panel['plans'] as $plan) {
            $reference = $plan['whmcs_product_id'] ? '#' . (int) $plan['whmcs_product_id'] . ' / ' . $this->e($plan['billing_cycle']) : 'Not linked';
            echo '<tr><td>' . $this->e($plan['name']) . '<br><small><code>' . $this->e($plan['slug']) . '</code></small></td>'
                . '<td>' . $this->e($plan['catalog_status']) . '</td><td>' . $reference . '</td><td>'
                . (int) $plan['resources']['cpu_cores'] . ' vCPU &middot; ' . (int) $plan['resources']['memory_mb'] . ' MiB &middot; '
                . (int) $plan['resources']['storage_gb'] . ' GiB</td><td><a class="btn btn-xs btn-default" href="'
                . $this->e($this->catalogUrl(['panel_id' => (int) $panel['id'], 'plan_id' => (int) $plan['id']])) . '">Edit</a></td></tr>';
        }
        echo '</tbody></table></div>';

        $plan = is_array($selectedPlan) ? $selectedPlan : $this->emptyPlan((int) $panel['id']);
        $isEditing = !empty($plan['id']);
        echo '<form method="post" action="' . $this->e($this->catalogUrl()) . '" class="form-horizontal">' . Csrf::field()
            . '<input type="hidden" name="catalog_action" value="plan_save">'
            . '<input type="hidden" name="record_id" value="' . (int) $plan['id'] . '">'
            . '<input type="hidden" name="panel_id" value="' . (int) $panel['id'] . '">'
            . '<input type="hidden" name="plan_id" value="' . (int) $plan['id'] . '">'
            . '<input type="hidden" name="control_panel_id" value="' . (int) $panel['id'] . '">';
        $this->formInput('Plan name', 'name', $plan['name'], true);
        $this->formInput('Slug', 'slug', $plan['slug'], false, 'Leave blank to derive from the plan name.');
        $this->formInput('Short summary', 'summary', $plan['summary']);
        $this->formTextarea('Description', 'description', $plan['description'], 2);
        $this->formInput('WHMCS product ID', 'whmcs_product_id', $plan['whmcs_product_id'], false,
            'Reference only. A real WHMCS product is verified and remains the sole price source; this catalog has no checkout.', 'number');
        $this->formSelect('Referenced billing cycle', 'billing_cycle', $plan['billing_cycle'], PanelCatalogService::BILLING_CYCLES);
        $this->formInput('CPU cores', 'cpu_cores', $plan['resources']['cpu_cores'], false, '', 'number');
        $this->formInput('Memory (MiB)', 'memory_mb', $plan['resources']['memory_mb'], false, '', 'number');
        $this->formInput('Storage (GiB)', 'storage_gb', $plan['resources']['storage_gb'], false, '', 'number');
        $this->formInput('Bandwidth (GiB)', 'bandwidth_gb', $plan['resources']['bandwidth_gb'], false, '', 'number');
        $this->formInput('Maximum accounts', 'max_accounts', $plan['resources']['max_accounts'], false, 'Zero means unspecified.', 'number');
        $this->formSelect('Catalog status', 'catalog_status', $plan['catalog_status'], PanelCatalogService::CATALOG_STATUSES);
        $this->formInput('Sort order', 'sort_order', $plan['sort_order'], false, '', 'number');
        echo '<div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary" type="submit">'
            . ($isEditing ? 'Save plan metadata' : 'Add plan') . '</button> '
            . '<span class="text-muted">Plan publication never enables orders or panel deployment.</span></div></div></form>';
    }

    private function formInput($label, $name, $value, $required = false, $help = '', $type = 'text')
    {
        $id = 'panel-catalog-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
        echo '<div class="form-group"><label class="col-sm-3 control-label" for="' . $this->e($id) . '">' . $this->e($label)
            . '</label><div class="col-sm-9"><input class="form-control" type="' . $this->e($type) . '" id="' . $this->e($id)
            . '" name="' . $this->e($name) . '" value="' . $this->e($value === null ? '' : $value) . '"'
            . ($type === 'number' ? ' min="0" step="1"' : ' maxlength="255"') . ($required ? ' required' : '') . '>';
        if ($help !== '') {
            echo '<p class="help-block">' . $this->e($help) . '</p>';
        }
        echo '</div></div>';
    }

    private function formTextarea($label, $name, $value, $rows = 3, $help = '')
    {
        $id = 'panel-catalog-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
        echo '<div class="form-group"><label class="col-sm-3 control-label" for="' . $this->e($id) . '">' . $this->e($label)
            . '</label><div class="col-sm-9"><textarea class="form-control" id="' . $this->e($id) . '" name="' . $this->e($name)
            . '" rows="' . (int) $rows . '">' . $this->e($value) . '</textarea>';
        if ($help !== '') {
            echo '<p class="help-block">' . $this->e($help) . '</p>';
        }
        echo '</div></div>';
    }

    private function formSelect($label, $name, $value, array $options)
    {
        $id = 'panel-catalog-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
        echo '<div class="form-group"><label class="col-sm-3 control-label" for="' . $this->e($id) . '">' . $this->e($label)
            . '</label><div class="col-sm-9"><select class="form-control" id="' . $this->e($id) . '" name="' . $this->e($name) . '">';
        foreach ($options as $option) {
            echo '<option value="' . $this->e($option) . '"' . ((string) $value === (string) $option ? ' selected' : '') . '>'
                . $this->e(ucwords(str_replace('_', ' ', (string) $option))) . '</option>';
        }
        echo '</select></div></div>';
    }

    private function pick(array $source, array $fields)
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $source) && is_scalar($source[$field])) {
                $out[$field] = $source[$field];
            }
        }
        return $out;
    }

    private function emptyPanel()
    {
        return [
            'id' => 0, 'category_id' => null, 'name' => '', 'slug' => '', 'vendor' => '',
            'summary' => '', 'description' => '', 'official_source_url' => '',
            'documentation_url' => '', 'support_url' => '', 'license_model' => 'unknown',
            'license_terms_url' => '', 'license_terms_summary' => '', 'supported_os' => [],
            'requirements' => ['minimum_cpu_cores' => 0, 'minimum_memory_mb' => 0,
                'minimum_storage_gb' => 0, 'notes' => ''],
            'capabilities' => array_fill_keys(array_keys(PanelCatalogService::CAPABILITIES), false),
            'catalog_status' => 'draft', 'integration_status' => 'not_implemented',
            'installation_status' => 'not_implemented', 'sort_order' => 100, 'plans' => [],
        ];
    }

    private function emptyPlan($panelId)
    {
        return [
            'id' => 0, 'control_panel_id' => (int) $panelId, 'name' => '', 'slug' => '',
            'summary' => '', 'description' => '', 'whmcs_product_id' => null,
            'billing_cycle' => 'monthly',
            'resources' => ['cpu_cores' => 0, 'memory_mb' => 0, 'storage_gb' => 0,
                'bandwidth_gb' => 0, 'max_accounts' => 0],
            'catalog_status' => 'draft', 'sort_order' => 100,
        ];
    }

    private function catalogUrl(array $query = [])
    {
        $base = preg_replace('/([&?])action=[^&]*/', '', $this->baseUrl);
        $base = rtrim((string) $base, '&?');
        $url = $base . (strpos($base, '?') === false ? '?' : '&') . 'action=panel_catalog';
        foreach ($query as $key => $value) {
            $url .= '&' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        return $url;
    }

    private function e($value)
    {
        return Str::e($value);
    }
}
