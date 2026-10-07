<?php
/**
 * CloudHost247 App Cloud — application catalog service.
 *
 * The read path for the marketplace and the write path for the admin application
 * manager. The approval workflow is enforced here, not in the UI: an application
 * moves draft → validating → testing → approved → published, and only a
 * published application with at least one published version is deployable. A
 * customer request for anything else is refused server-side.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Catalog;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class ApplicationService
{
    /* Approval workflow (specification §47). */
    const STATUS_DRAFT      = 'draft';
    const STATUS_VALIDATING = 'validating';
    const STATUS_TESTING    = 'testing';
    const STATUS_APPROVED   = 'approved';
    const STATUS_PUBLISHED  = 'published';
    const STATUS_SUSPENDED  = 'suspended';
    const STATUS_DEPRECATED = 'deprecated';

    const TRANSITIONS = [
        self::STATUS_DRAFT      => [self::STATUS_VALIDATING, self::STATUS_SUSPENDED],
        self::STATUS_VALIDATING => [self::STATUS_TESTING, self::STATUS_DRAFT, self::STATUS_SUSPENDED],
        self::STATUS_TESTING    => [self::STATUS_APPROVED, self::STATUS_DRAFT, self::STATUS_SUSPENDED],
        self::STATUS_APPROVED   => [self::STATUS_PUBLISHED, self::STATUS_DRAFT, self::STATUS_SUSPENDED],
        self::STATUS_PUBLISHED  => [self::STATUS_SUSPENDED, self::STATUS_DEPRECATED],
        self::STATUS_SUSPENDED  => [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_DEPRECATED],
        self::STATUS_DEPRECATED => [self::STATUS_SUSPENDED],
    ];

    const SORTS = ['popular', 'recent', 'name', 'featured', 'installs'];

    /** @var Actor */
    private $actor;

    public function __construct(Actor $actor = null)
    {
        $this->actor = $actor ?: Actor::system('ApplicationService');
    }

    /* ------------------------------------------------------------ read path */

    /**
     * Marketplace query.
     *
     * @param array $filters q, category, kind, featured, sort, deployable_only,
     *                       limit, page, status (admin only)
     * @return array{items: array[], total: int, page: int, pages: int, filters: array}
     */
    public function query(array $filters = [])
    {
        $isAdmin = $this->actor->isAdmin() && Rbac::allows($this->actor, Rbac::APP_VIEW_ALL);
        $limit = max(1, min(100, (int) (isset($filters['limit']) ? $filters['limit'] : 24)));
        $page = max(1, (int) (isset($filters['page']) ? $filters['page'] : 1));

        $where = ['deleted_at' => null];
        $params = [];

        if (!$isAdmin || !empty($filters['published_only'])) {
            $where['status'] = self::STATUS_PUBLISHED;
        } elseif (!empty($filters['status'])) {
            $where['status'] = (string) $filters['status'];
        }
        if (!empty($filters['category'])) {
            $category = Db::first('categories', ['slug' => Str::slug((string) $filters['category'], 80)]);
            $where['category_id'] = $category ? (int) $category['id'] : -1;
        }
        if (!empty($filters['kind'])) {
            $where['kind'] = (string) $filters['kind'];
        }
        if (!empty($filters['featured'])) {
            $where['featured'] = 1;
        }
        if (!array_key_exists('deployable', $filters) || $filters['deployable'] !== false) {
            // Customers only ever see applications that can actually be deployed.
            if (!$isAdmin) {
                $where['deployable'] = 1;
            }
        }

        $search = trim((string) (isset($filters['q']) ? $filters['q'] : ''));

        $sort = isset($filters['sort']) && in_array($filters['sort'], self::SORTS, true)
            ? $filters['sort'] : 'popular';

        list($orderSql, $orderParams) = $this->orderClause($sort);
        list($clause, $bindings) = Db::buildWhere($where);

        $searchSql = '';
        if ($search !== '') {
            $searchSql = ' AND (name LIKE ? OR slug LIKE ? OR summary LIKE ? OR description LIKE ? OR tags LIKE ?)';
            $like = '%' . $search . '%';
            $params = array_merge($bindings, [$like, $like, $like, $like, $like]);
        } else {
            $params = $bindings;
        }

        $table = Db::quoteIdentifier(Db::t('applications'));
        $total = (int) Db::scalar('SELECT COUNT(*) FROM ' . $table . $clause . $searchSql, $params);

        $rows = Db::select(
            'SELECT * FROM ' . $table . $clause . $searchSql . $orderSql
            . ' LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            $params
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->presentCard($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / $limit),
            'limit' => $limit,
            'filters' => ['q' => $search, 'sort' => $sort,
                'category' => isset($filters['category']) ? $filters['category'] : '',
                'kind' => isset($filters['kind']) ? $filters['kind'] : ''],
        ];
    }

    private function orderClause($sort)
    {
        switch ($sort) {
            case 'recent':
                return [' ORDER BY published_at DESC, id DESC', []];
            case 'name':
                return [' ORDER BY name ASC', []];
            case 'featured':
                return [' ORDER BY featured DESC, popularity DESC, name ASC', []];
            case 'installs':
                return [' ORDER BY install_count DESC, popularity DESC', []];
            case 'popular':
            default:
                return [' ORDER BY featured DESC, popularity DESC, install_count DESC, name ASC', []];
        }
    }

    /**
     * The marketplace card (specification §42): logo, name, summary, category,
     * supported hosting types, minimum requirements and current stable version.
     */
    public function presentCard(array $row)
    {
        $category = $row['category_id'] ? Db::first('categories', ['id' => (int) $row['category_id']]) : null;
        $version = $this->stableVersion((int) $row['id']);
        $requirements = $version ? $this->versionRequirements($version) : null;

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'summary' => isset($row['summary']) ? $row['summary'] : '',
            'logo_url' => isset($row['logo_url']) ? $row['logo_url'] : null,
            'kind' => $row['kind'],
            'category' => $category ? ['id' => (int) $category['id'], 'name' => $category['name'],
                'slug' => $category['slug'], 'icon' => $category['icon']] : null,
            'featured' => (bool) $row['featured'],
            'deployable' => (bool) $row['deployable'],
            'status' => $row['status'],
            'requires_admin_approval' => (bool) $row['requires_admin_approval'],
            'requires_domain' => (bool) $row['requires_domain'],
            'license' => isset($row['license']) ? $row['license'] : null,
            'hosting_types' => $this->compatibility((int) $row['id']),
            'current_version' => $version ? $version['version'] : null,
            'requirements' => $requirements,
            'install_count' => (int) $row['install_count'],
            'tags' => Str::jsonDecode(isset($row['tags']) ? $row['tags'] : null, []),
            'website_url' => isset($row['website_url']) ? $row['website_url'] : null,
            'documentation_url' => isset($row['documentation_url']) ? $row['documentation_url'] : null,
            'repository_url' => isset($row['repository_url']) ? $row['repository_url'] : null,
            'published_at' => isset($row['published_at']) ? $row['published_at'] : null,
        ];
    }

    /**
     * Full detail for /apps/[slug]: long description, every published version,
     * requirements, compatibility, dependencies and available plans.
     */
    public function detail($slugOrId)
    {
        $row = $this->row($slugOrId);
        $isAdmin = $this->actor->isAdmin() && Rbac::allows($this->actor, Rbac::APP_VIEW_ALL);

        if ($row['status'] !== self::STATUS_PUBLISHED && !$isAdmin) {
            // Drafts, suspended and deprecated entries do not exist as far as a
            // customer is concerned: a 404, not a 403, so the catalog cannot be
            // probed for unreleased products.
            throw new NotFoundException('That application is not available.');
        }

        $detail = $this->presentCard($row);
        $detail['long_description'] = isset($row['long_description']) ? $row['long_description'] : '';
        $detail['description'] = isset($row['description']) ? $row['description'] : '';
        $detail['vendor'] = isset($row['vendor']) ? $row['vendor'] : null;
        $detail['deployment_type'] = $row['deployment_type'];
        $detail['requires_ssl'] = (bool) $row['requires_ssl'];
        $detail['backup_supported'] = (bool) $row['backup_supported'];
        $detail['update_supported'] = (bool) $row['update_supported'];
        $detail['gpu_required'] = (bool) $row['gpu_required'];
        $detail['admin_notes'] = $isAdmin && isset($row['admin_notes']) ? $row['admin_notes'] : null;
        $detail['versions'] = [];
        foreach ($this->versions((int) $row['id'], $isAdmin) as $version) {
            $detail['versions'][] = $version;
        }
        $detail['dependencies'] = $this->dependencies((int) $row['id']);
        $detail['plans'] = $this->plansFor((int) $row['id']);
        $detail['manifest'] = null;
        if ($isAdmin && !empty($row['manifest_path'])) {
            $detail['manifest_path'] = $row['manifest_path'];
        }
        return $detail;
    }

    /** @throws NotFoundException */
    public function row($slugOrId)
    {
        $where = is_int($slugOrId) || ctype_digit((string) $slugOrId)
            ? ['id' => (int) $slugOrId]
            : ['slug' => Str::slug((string) $slugOrId, 100)];
        $where['deleted_at'] = null;
        $row = Db::first('applications', $where);
        if (!$row) {
            throw new NotFoundException('That application is not in the catalog.');
        }
        return $row;
    }

    public function find($slugOrId)
    {
        try {
            return $this->row($slugOrId);
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /** Published versions, newest first. */
    public function versions($applicationId, $includeUnpublished = false)
    {
        $where = ['application_id' => (int) $applicationId];
        if (!$includeUnpublished) {
            $where['status'] = 'published';
        }
        $rows = Db::fetch('application_versions', $where, ['order' => 'is_latest', 'dir' => 'desc', 'order2' => 'id']);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->presentVersion($row);
        }
        return $out;
    }

    public function presentVersion(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'application_id' => (int) $row['application_id'],
            'version' => $row['version'],
            'channel' => $row['channel'],
            'docker_image' => isset($row['docker_image']) ? $row['docker_image'] : null,
            'status' => $row['status'],
            'is_latest' => (bool) $row['is_latest'],
            'requirements' => $this->versionRequirements($row),
            'release_notes' => isset($row['release_notes']) ? $row['release_notes'] : null,
            'published_at' => isset($row['published_at']) ? $row['published_at'] : null,
            'manifest_hash' => isset($row['manifest_hash']) ? $row['manifest_hash'] : null,
        ];
    }

    public function versionRequirements(array $row)
    {
        return [
            'cpu_min' => (int) $row['minimum_cpu'],
            'cpu_recommended' => (int) $row['recommended_cpu'],
            'memory_min_mb' => (int) $row['minimum_memory_mb'],
            'memory_recommended_mb' => (int) $row['recommended_memory_mb'],
            'storage_min_mb' => (int) $row['minimum_storage_mb'],
            'storage_recommended_mb' => (int) $row['recommended_storage_mb'],
            'gpu_min' => (int) $row['minimum_gpu'],
        ];
    }

    public function stableVersion($applicationId)
    {
        $row = Db::first('application_versions', [
            'application_id' => (int) $applicationId, 'status' => 'published', 'is_latest' => 1,
        ]);
        if (!$row) {
            $row = Db::first('application_versions', [
                'application_id' => (int) $applicationId, 'status' => 'published',
            ], ['order' => 'id', 'dir' => 'desc']);
        }
        return $row;
    }

    /** Compatibility matrix: hosting type → supported/recommended (specification §48). */
    public function compatibility($applicationId)
    {
        $out = [];
        foreach (Manifest::HOSTING_TYPES as $type) {
            $out[$type] = ['supported' => false, 'recommended' => false, 'notes' => null];
        }
        foreach (Db::fetch('application_compatibility', ['application_id' => (int) $applicationId]) as $row) {
            if (!isset($out[$row['hosting_type']])) {
                continue;
            }
            $out[$row['hosting_type']] = [
                'supported' => (bool) $row['supported'],
                'recommended' => (bool) $row['recommended'],
                'notes' => isset($row['notes']) ? $row['notes'] : null,
            ];
        }
        return $out;
    }

    /** @return string[] the hosting types this application may be installed on */
    public function supportedHostingTypes($applicationId)
    {
        $supported = [];
        foreach ($this->compatibility($applicationId) as $type => $entry) {
            if (!empty($entry['supported'])) {
                $supported[] = $type;
            }
        }
        return $supported;
    }

    public function dependencies($applicationId)
    {
        $out = [];
        foreach (Db::fetch('application_dependencies', ['application_id' => (int) $applicationId]) as $row) {
            $dependency = $row['depends_on_application_id']
                ? Db::first('applications', ['id' => (int) $row['depends_on_application_id']])
                : null;
            $out[] = [
                'type' => $row['dependency_type'],
                'requirement' => $row['requirement'],
                'isolation' => $row['isolation'],
                'application' => $dependency ? ['id' => (int) $dependency['id'], 'name' => $dependency['name'],
                    'slug' => $dependency['slug'], 'kind' => $dependency['kind']] : null,
                'notes' => isset($row['notes']) ? $row['notes'] : null,
            ];
        }
        return $out;
    }

    /** Plans a customer may buy for this application (generic plans included). */
    public function plansFor($applicationId)
    {
        $rows = Db::select(
            'SELECT * FROM ' . Db::quoteIdentifier(Db::t('plans'))
            . ' WHERE active = 1 AND deleted_at IS NULL AND (application_id = ? OR application_id IS NULL)'
            . ' ORDER BY sort_order ASC, memory_mb ASC',
            [(int) $applicationId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'billing_interval' => $row['billing_interval'],
                'cpu_millicores' => (int) $row['cpu_millicores'],
                'memory_mb' => (int) $row['memory_mb'],
                'storage_mb' => (int) $row['storage_mb'],
                'bandwidth_mb' => (int) $row['bandwidth_mb'],
                'price_minor' => $row['price_minor'] === null ? null : (int) $row['price_minor'],
                'currency' => $row['currency'],
                'ssl_included' => (bool) $row['ssl_included'],
                'auto_backup' => (bool) $row['auto_backup'],
                'deployment_types' => Str::jsonDecode(isset($row['deployment_types']) ? $row['deployment_types'] : null, []),
                'server_types' => Str::jsonDecode(isset($row['server_types']) ? $row['server_types'] : null, []),
                'whmcs_product_id' => $row['whmcs_product_id'] ? (int) $row['whmcs_product_id'] : null,
                'description' => isset($row['description']) ? $row['description'] : null,
                'featured' => (bool) $row['featured'],
            ];
        }
        return $out;
    }

    /**
     * Assert an installation may proceed for this application.
     *
     * @throws NotFoundException|StateException
     */
    public function assertDeployable($applicationId, $hostingType = null)
    {
        $row = Db::first('applications', ['id' => (int) $applicationId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That application is not in the catalog.');
        }
        if ($row['status'] !== self::STATUS_PUBLISHED) {
            throw new StateException('That application is not published.', [
                'status' => $row['status'],
                'error_code' => 'APPLICATION_NOT_PUBLISHED',
            ]);
        }
        if (!(int) $row['deployable']) {
            throw new StateException('That application has no validated deployment version yet.', [
                'error_code' => 'APPLICATION_NOT_DEPLOYABLE',
            ]);
        }
        if ($hostingType !== null && !in_array((string) $hostingType, $this->supportedHostingTypes((int) $row['id']), true)) {
            throw new StateException('That application cannot run on the selected hosting type.', [
                'error_code' => 'APPLICATION_INCOMPATIBLE_HOSTING',
                'hosting_type' => $hostingType,
            ]);
        }
        if ($this->stableVersion((int) $row['id']) === null) {
            throw new StateException('That application has no published version.', [
                'error_code' => 'APPLICATION_NO_VERSION',
            ]);
        }
        return $row;
    }

    /* ----------------------------------------------------------- write path */

    /** Create or update a catalog entry (admin application manager). */
    public function upsert(array $input)
    {
        Rbac::assert($this->actor, Rbac::APP_MANAGE);

        $name = trim((string) (isset($input['name']) ? $input['name'] : ''));
        if ($name === '') {
            throw new ValidationException('An application name is required.', ['errors' => ['name' => 'Required']]);
        }
        $slug = Str::slug(isset($input['slug']) && $input['slug'] !== '' ? $input['slug'] : $name, 100);

        $categoryId = null;
        if (!empty($input['category'])) {
            $category = Db::first('categories', ['slug' => Str::slug((string) $input['category'], 80)]);
            if (!$category) {
                throw new ValidationException('Unknown category "' . $input['category'] . '".', [
                    'errors' => ['category' => 'Unknown category'],
                ]);
            }
            $categoryId = (int) $category['id'];
        }

        $kinds = [Manifest::KIND_APPLICATION, Manifest::KIND_INFRASTRUCTURE, Manifest::KIND_PLATFORM];
        $kind = isset($input['kind']) && in_array($input['kind'], $kinds, true)
            ? $input['kind'] : Manifest::KIND_APPLICATION;

        $fields = [
            'name' => Str::clip($name, 160),
            'slug' => $slug,
            'category_id' => $categoryId,
            'kind' => $kind,
            'summary' => isset($input['summary']) ? Str::clip($input['summary'], 255) : null,
            'description' => isset($input['description']) ? Str::cleanText($input['description'], 5000) : null,
            'long_description' => isset($input['long_description']) ? Str::cleanText($input['long_description'], 20000) : null,
            'logo_url' => isset($input['logo_url']) ? Str::clip($input['logo_url'], 255) : null,
            'website_url' => isset($input['website_url']) ? Str::clip($input['website_url'], 255) : null,
            'repository_url' => isset($input['repository_url']) ? Str::clip($input['repository_url'], 255) : null,
            'documentation_url' => isset($input['documentation_url']) ? Str::clip($input['documentation_url'], 255) : null,
            'license' => isset($input['license']) ? Str::clip($input['license'], 80) : null,
            'vendor' => isset($input['vendor']) ? Str::clip($input['vendor'], 120) : null,
            'deployment_type' => isset($input['deployment_type']) ? Str::clip($input['deployment_type'], 30) : 'docker-compose',
            'tags' => isset($input['tags']) ? Str::jsonEncode(array_values((array) $input['tags'])) : null,
            'featured' => !empty($input['featured']) ? 1 : 0,
            'requires_admin_approval' => !empty($input['requires_admin_approval']) ? 1 : 0,
            'requires_domain' => !empty($input['requires_domain']) ? 1 : 0,
            'requires_ssl' => !empty($input['requires_ssl']) ? 1 : 0,
            'backup_supported' => array_key_exists('backup_supported', $input) ? (!empty($input['backup_supported']) ? 1 : 0) : 1,
            'update_supported' => array_key_exists('update_supported', $input) ? (!empty($input['update_supported']) ? 1 : 0) : 1,
            'gpu_required' => !empty($input['gpu_required']) ? 1 : 0,
            'admin_notes' => isset($input['admin_notes']) ? Str::cleanText($input['admin_notes'], 2000) : null,
            'sort_order' => isset($input['sort_order']) ? (int) $input['sort_order'] : 0,
            'updated_at' => Clock::now(),
        ];

        $existing = Db::first('applications', ['slug' => $slug, 'deleted_at' => null]);
        if ($existing) {
            Db::update('applications', $fields, ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
            Audit::record($this->actor, Audit::APPLICATION_UPDATED, [
                'resource_type' => 'application', 'resource_id' => $id,
                'metadata' => ['slug' => $slug, 'changed' => array_keys($fields)],
            ]);
        } else {
            $conflict = Db::first('applications', ['slug' => $slug]);
            if ($conflict) {
                throw new ValidationException('That slug belongs to a deleted application; restore it instead.', [
                    'errors' => ['slug' => 'Taken'],
                ]);
            }
            $fields['status'] = self::STATUS_DRAFT;
            $fields['deployable'] = 0;
            $fields['created_at'] = Clock::now();
            $id = Db::insert('applications', $fields);
            Audit::record($this->actor, Audit::APPLICATION_CREATED, [
                'resource_type' => 'application', 'resource_id' => $id, 'metadata' => ['slug' => $slug],
            ]);
        }

        if (isset($input['compatibility']) && is_array($input['compatibility'])) {
            $this->setCompatibility($id, $input['compatibility']);
        }

        return Db::first('applications', ['id' => $id]);
    }

    /** Replace the compatibility matrix for an application. */
    public function setCompatibility($applicationId, array $compatibility)
    {
        Rbac::assert($this->actor, Rbac::APP_MANAGE);
        $applicationId = (int) $applicationId;
        $now = Clock::now();

        foreach (Manifest::HOSTING_TYPES as $type) {
            $entry = isset($compatibility[$type]) ? $compatibility[$type] : null;
            $supported = is_array($entry) ? !empty($entry['supported']) : (bool) $entry;
            $recommended = is_array($entry) ? !empty($entry['recommended']) : false;
            $notes = is_array($entry) && isset($entry['notes']) ? Str::clip($entry['notes'], 255) : null;

            $existing = Db::first('application_compatibility', [
                'application_id' => $applicationId, 'hosting_type' => $type,
            ]);
            if ($existing) {
                Db::update('application_compatibility', [
                    'supported' => $supported ? 1 : 0,
                    'recommended' => $recommended ? 1 : 0,
                    'notes' => $notes,
                    'updated_at' => $now,
                ], ['id' => (int) $existing['id']]);
            } else {
                Db::insert('application_compatibility', [
                    'application_id' => $applicationId,
                    'hosting_type' => $type,
                    'supported' => $supported ? 1 : 0,
                    'recommended' => $recommended ? 1 : 0,
                    'notes' => $notes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        return $this->compatibility($applicationId);
    }

    /**
     * Move an application through the approval workflow.
     *
     * Publishing requires an approved version whose validation report shows the
     * seven gates passed (manifest, security, deployment, health, backup, update,
     * uninstall) — an untested application cannot become available to customers.
     *
     * @throws StateException|ValidationException
     */
    public function transition($applicationId, $toStatus, array $options = [])
    {
        $row = Db::first('applications', ['id' => (int) $applicationId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That application is not in the catalog.');
        }
        $from = (string) $row['status'];
        $to = strtolower((string) $toStatus);

        // Permission first: an unauthorised caller gets a 403 and learns nothing
        // about where the application sits in the workflow.
        switch ($to) {
            case self::STATUS_PUBLISHED:
                Rbac::assert($this->actor, Rbac::APP_PUBLISH);
                break;
            case self::STATUS_APPROVED:
                Rbac::assert($this->actor, Rbac::APP_APPROVE);
                break;
            case self::STATUS_SUSPENDED:
                Rbac::assert($this->actor, Rbac::APP_SUSPEND);
                break;
            default:
                Rbac::assert($this->actor, Rbac::APP_MANAGE);
        }

        // Re-asserting the current status is a no-op, so an idempotent retry of
        // an admin action (or a re-import) does not fail.
        if ($from === $to) {
            return $row;
        }

        if (!isset(self::TRANSITIONS[$from]) || !in_array($to, self::TRANSITIONS[$from], true)) {
            throw new StateException('Cannot move an application from ' . $from . ' to ' . $to . '.', [
                'from' => $from, 'to' => $to, 'allowed' => isset(self::TRANSITIONS[$from]) ? self::TRANSITIONS[$from] : [],
                'error_code' => 'APPLICATION_INVALID_TRANSITION',
            ]);
        }

        if ($to === self::STATUS_PUBLISHED) {
            $this->assertPublishable((int) $row['id']);
        }

        $changes = ['status' => $to, 'updated_at' => Clock::now()];
        if ($to === self::STATUS_PUBLISHED) {
            $changes['published_at'] = Clock::now();
            $changes['deployable'] = 1;
            $changes['suspension_reason'] = null;
        }
        if ($to === self::STATUS_SUSPENDED) {
            $changes['deployable'] = 0;
            $changes['suspension_reason'] = isset($options['reason'])
                ? Str::clip($options['reason'], 255) : 'Suspended by an administrator.';
        }
        if ($to === self::STATUS_DEPRECATED) {
            $changes['deprecated_at'] = Clock::now();
            $changes['deployable'] = 0;
        }
        if ($to === self::STATUS_DRAFT) {
            $changes['deployable'] = 0;
            $changes['suspension_reason'] = null;
        }

        Db::update('applications', $changes, ['id' => (int) $row['id']]);

        $action = [
            self::STATUS_PUBLISHED => Audit::APPLICATION_PUBLISHED,
            self::STATUS_SUSPENDED => Audit::APPLICATION_SUSPENDED,
            self::STATUS_DEPRECATED => Audit::APPLICATION_UNPUBLISHED,
            self::STATUS_DRAFT => Audit::APPLICATION_UNPUBLISHED,
        ];
        Audit::transition(
            $this->actor,
            isset($action[$to]) ? $action[$to] : Audit::APPLICATION_UPDATED,
            'application',
            (int) $row['id'],
            $from,
            $to,
            ['metadata' => isset($options['reason']) ? ['reason' => $options['reason']] : []]
        );
        Logger::info('Application status changed.', [
            'application' => $row['slug'], 'from' => $from, 'to' => $to, 'source' => 'catalog',
        ]);

        return Db::first('applications', ['id' => (int) $row['id']]);
    }

    public function publish($applicationId)
    {
        return $this->transition($applicationId, self::STATUS_PUBLISHED);
    }

    /**
     * Walk the approval workflow forward to PUBLISHED from wherever an
     * application currently sits.
     *
     * Used by the catalog importer: re-importing a manifest must not fail just
     * because the application was already published, and a suspended application
     * must be re-approved rather than silently re-listed. Every step still goes
     * through transition(), so each hop is permission-checked and audited.
     */
    public function advanceToPublished($applicationId)
    {
        $row = Db::first('applications', ['id' => (int) $applicationId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That application is not in the catalog.');
        }
        $status = (string) $row['status'];
        if ($status === self::STATUS_PUBLISHED) {
            return $row;
        }

        $order = [
            self::STATUS_DRAFT, self::STATUS_VALIDATING, self::STATUS_TESTING,
            self::STATUS_APPROVED, self::STATUS_PUBLISHED,
        ];
        if (in_array($status, $order, true)) {
            $steps = array_slice($order, array_search($status, $order, true) + 1);
        } elseif ($status === self::STATUS_SUSPENDED) {
            $steps = [self::STATUS_APPROVED, self::STATUS_PUBLISHED];
        } elseif ($status === self::STATUS_DEPRECATED) {
            $steps = [self::STATUS_SUSPENDED, self::STATUS_APPROVED, self::STATUS_PUBLISHED];
        } else {
            throw new StateException('Unknown application status "' . $status . '".', ['status' => $status]);
        }

        foreach ($steps as $step) {
            $row = $this->transition((int) $row['id'], $step);
        }
        return $row;
    }

    public function unpublish($applicationId, $reason = '')
    {
        $row = Db::first('applications', ['id' => (int) $applicationId]);
        if (!$row) {
            throw new NotFoundException('That application is not in the catalog.');
        }
        $target = $row['status'] === self::STATUS_PUBLISHED ? self::STATUS_SUSPENDED : self::STATUS_DRAFT;
        return $this->transition((int) $row['id'], $target, ['reason' => $reason]);
    }

    /** The seven publication gates (specification §47, Phase 5). */
    public function assertPublishable($applicationId)
    {
        $versions = Db::fetch('application_versions', [
            'application_id' => (int) $applicationId, 'status' => 'published',
        ]);
        if ($versions === []) {
            throw new StateException('An application needs at least one published version.', [
                'error_code' => 'APPLICATION_NO_PUBLISHED_VERSION',
            ]);
        }
        foreach ($versions as $version) {
            $report = Str::jsonDecode(isset($version['validation_report']) ? $version['validation_report'] : null, []);
            $missing = [];
            foreach (VersionService::PUBLICATION_GATES as $gate) {
                if (empty($report[$gate]) || empty($report[$gate]['passed'])) {
                    $missing[] = $gate;
                }
            }
            if ($missing) {
                throw new StateException(
                    'Version ' . $version['version'] . ' has not passed: ' . implode(', ', $missing) . '.',
                    ['error_code' => 'APPLICATION_VALIDATION_INCOMPLETE', 'missing' => $missing,
                        'version' => $version['version']]
                );
            }
        }
        return true;
    }

    /** Record a successful install (drives "popular" ordering). */
    public function recordInstall($applicationId)
    {
        Db::run('UPDATE ' . Db::quoteIdentifier(Db::t('applications'))
            . ' SET install_count = install_count + 1 WHERE id = ?', [(int) $applicationId]);
    }

    /** Soft delete. Existing installations keep their history. */
    public function remove($applicationId)
    {
        Rbac::assert($this->actor, Rbac::APP_MANAGE);
        $row = Db::first('applications', ['id' => (int) $applicationId]);
        if (!$row) {
            throw new NotFoundException('That application is not in the catalog.');
        }
        $active = Db::count('installations', [
            'application_id' => (int) $applicationId,
            'status' => ['notin', ['deleted', 'terminated']],
            'deleted_at' => null,
        ]);
        if ($active > 0) {
            throw new StateException('That application still has ' . $active . ' active installation(s). '
                . 'Suspend it instead of deleting it.', ['active' => $active]);
        }
        Db::update('applications', ['deleted_at' => Clock::now(), 'deployable' => 0, 'updated_at' => Clock::now()],
            ['id' => (int) $applicationId]);
        Audit::record($this->actor, Audit::APPLICATION_UPDATED, [
            'resource_type' => 'application', 'resource_id' => (int) $applicationId,
            'metadata' => ['action' => 'soft_delete', 'slug' => $row['slug']], 'severity' => 'warning',
        ]);
        return true;
    }
}
