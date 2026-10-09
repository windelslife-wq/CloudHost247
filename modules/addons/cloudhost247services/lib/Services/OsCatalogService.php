<?php
/**
 * Operating-system catalog: the admin-managed OS / version / provider-image
 * layer that the ordering flow, the reinstall flow and the customer dashboards
 * all read from.
 *
 * The frontend never hard-codes operating systems: every OS card, version
 * option and architecture choice is computed here from database records,
 * intersected with the provider image mappings that are actually active.
 *
 * Availability is always a function of:
 *
 *   product rules → server type → OS flags → version lifecycle status
 *     → architecture support → enabled provider → active image mapping
 *     → region
 *
 * Nothing is deployable merely because it exists in the catalog: an OS
 * version becomes selectable only when at least one active provider image
 * covers it for the requested architecture/region.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DuplicateOperationException;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\Str;
use Chs\Core\ValidationException;
use Chs\Providers\Infrastructure\InfraProviderRegistry;
use Chs\Providers\Infrastructure\ProviderFailure;

class OsCatalogService
{
    public const OS_STATUSES = ['ACTIVE', 'DISABLED', 'ARCHIVED'];
    public const VERSION_STATUSES = ['ACTIVE', 'MAINTENANCE', 'EOL_WARNING', 'EOL', 'ARCHIVED'];
    /** Version states a customer may still select for a NEW deployment. */
    public const SELECTABLE_VERSION_STATUSES = ['ACTIVE', 'MAINTENANCE'];
    public const SERVER_TYPES = ['vps', 'dedicated', 'cloud'];
    public const ARCHITECTURES = ['x86_64', 'arm64'];

    /** @var InfraProviderRegistry|null */
    private $registry;

    public function __construct(InfraProviderRegistry $registry = null)
    {
        $this->registry = $registry ?: new InfraProviderRegistry();
    }

    /* ------------------------------------------------------ OS CRUD (admin) -- */

    /** @return array[] all OSes with version counts, admin order */
    public function listAdmin()
    {
        $rows = Db::all('operating_systems', [], 'sort_order ASC, id ASC');
        foreach ($rows as &$row) {
            $row['version_count'] = Db::count('operating_system_versions', ['operating_system_id' => (int) $row['id']]);
            $row['active_version_count'] = Db::count('operating_system_versions', [
                'operating_system_id' => (int) $row['id'],
                'status' => 'ACTIVE',
            ]);
        }
        unset($row);
        return $rows;
    }

    /** @return array|null */
    public function findOs($osId)
    {
        $row = Db::first('operating_systems', ['id' => (int) $osId]);
        return $row ?: null;
    }

    /** @return array|null */
    public function getOsBySlug($slug)
    {
        $row = Db::first('operating_systems', ['slug' => Str::slug($slug)]);
        return $row ?: null;
    }

    /**
     * Create or update an OS. No code deployment is needed to add an OS.
     *
     * @return int OS id
     */
    public function saveOs($osId, array $data)
    {
        $errors = [];
        $name = trim((string) (isset($data['name']) ? $data['name'] : ''));
        if ($name === '') {
            $errors['name'] = 'OS name is required.';
        }
        $slug = Str::slug(isset($data['slug']) ? $data['slug'] : $name);
        if ($slug === '') {
            $errors['slug'] = 'A slug is required.';
        }
        $status = strtoupper((string) (isset($data['status']) ? $data['status'] : 'ACTIVE'));
        if (!in_array($status, self::OS_STATUSES, true)) {
            $errors['status'] = 'Status must be one of: ' . implode(', ', self::OS_STATUSES);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $existing = Db::first('operating_systems', ['slug' => $slug]);
        if ($existing && (int) $existing['id'] !== (int) $osId) {
            throw new DuplicateOperationException('An operating system with that slug already exists.');
        }

        $now = Clock::now();
        $row = [
            'name'                   => $name,
            'slug'                   => $slug,
            'vendor'                 => trim((string) (isset($data['vendor']) ? $data['vendor'] : '')),
            'description'            => trim((string) (isset($data['description']) ? $data['description'] : '')),
            'logo_url'               => trim((string) (isset($data['logo_url']) ? $data['logo_url'] : '')),
            'status'                 => $status,
            'sort_order'             => max(0, (int) (isset($data['sort_order']) ? $data['sort_order'] : 0)),
            'is_vps_supported'       => !empty($data['is_vps_supported']) ? 1 : 0,
            'is_dedicated_supported' => !empty($data['is_dedicated_supported']) ? 1 : 0,
            'is_cloud_supported'     => !empty($data['is_cloud_supported']) ? 1 : 0,
            'is_reinstall_supported' => !empty($data['is_reinstall_supported']) ? 1 : 0,
            'updated_at'             => $now,
        ];
        if ($osId && $this->findOs($osId)) {
            Db::update('operating_systems', ['id' => (int) $osId], $row);
            return (int) $osId;
        }
        $row['created_at'] = $now;
        return Db::insert('operating_systems', $row);
    }

    /**
     * Delete an OS where safe: refused while versions (and therefore image
     * mappings) still reference it.
     */
    public function deleteOs($osId)
    {
        $osId = (int) $osId;
        if (!$this->findOs($osId)) {
            throw new NotFoundException('Operating system not found.');
        }
        if (Db::count('operating_system_versions', ['operating_system_id' => $osId]) > 0) {
            throw new DuplicateOperationException('This OS still has versions — delete them first.');
        }
        return Db::delete('operating_systems', ['id' => $osId]) > 0;
    }

    /* ------------------------------------------------- versions (admin) -- */

    /** @return array[] versions for one OS (lifecycle order) */
    public function versions($osId)
    {
        return Db::all('operating_system_versions', ['operating_system_id' => (int) $osId], 'is_default DESC, id ASC');
    }

    /** @return array|null */
    public function versionRow($versionId)
    {
        $row = Db::first('operating_system_versions', ['id' => (int) $versionId]);
        if ($row) {
            $row['architectures'] = json_decode((string) $row['architecture_support'], true) ?: ['x86_64'];
        }
        return $row ?: null;
    }

    /**
     * Create or update a version. Architecture support is a list, never a
     * hard-coded constant; exactly one default per OS is enforced.
     *
     * @return int version id
     */
    public function saveVersion($osId, $versionId, array $data)
    {
        $osId = (int) $osId;
        if (!$this->findOs($osId)) {
            throw new NotFoundException('Operating system not found.');
        }
        $errors = [];
        $version = trim((string) (isset($data['version']) ? $data['version'] : ''));
        if ($version === '' || strlen($version) > 64) {
            $errors['version'] = 'Version is required (max 64 characters).';
        }
        $display = trim((string) (isset($data['display_name']) ? $data['display_name'] : ''));
        if ($display === '') {
            $errors['display_name'] = 'Display name is required.';
        }
        $status = strtoupper((string) (isset($data['status']) ? $data['status'] : 'ACTIVE'));
        if (!in_array($status, self::VERSION_STATUSES, true)) {
            $errors['status'] = 'Status must be one of: ' . implode(', ', self::VERSION_STATUSES);
        }
        $archs = isset($data['architectures']) ? (array) $data['architectures'] : ['x86_64'];
        $archs = array_values(array_intersect(array_map('strtolower', array_map('trim', $archs)), self::ARCHITECTURES));
        if (!$archs) {
            $errors['architectures'] = 'At least one supported architecture (x86_64, arm64) is required.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $existing = Db::first('operating_system_versions', ['operating_system_id' => $osId, 'version' => $version]);
        if ($existing && (int) $existing['id'] !== (int) $versionId) {
            throw new DuplicateOperationException('This OS already has that version.');
        }

        $now = Clock::now();
        $row = [
            'version'              => $version,
            'display_name'         => $display,
            'release_name'         => trim((string) (isset($data['release_name']) ? $data['release_name'] : '')),
            'architecture_support' => json_encode($archs),
            'status'               => $status,
            'is_default'           => !empty($data['is_default']) ? 1 : 0,
            'is_lts'               => !empty($data['is_lts']) ? 1 : 0,
            'release_date'         => (isset($data['release_date']) && $data['release_date'] !== '') ? (string) $data['release_date'] : null,
            'end_of_life_date'     => (isset($data['end_of_life_date']) && $data['end_of_life_date'] !== '') ? (string) $data['end_of_life_date'] : null,
            'updated_at'           => $now,
        ];
        if ($versionId && Db::first('operating_system_versions', ['id' => (int) $versionId])) {
            Db::update('operating_system_versions', ['id' => (int) $versionId], $row);
            $id = (int) $versionId;
        } else {
            $row['operating_system_id'] = $osId;
            $row['created_at'] = $now;
            $id = Db::insert('operating_system_versions', $row);
        }
        $this->enforceSingleDefault($osId, $id, !empty($data['is_default']));
        return $id;
    }

    /** Retire a version: it leaves the new-deployment catalog (EOL). */
    public function retireVersion($versionId)
    {
        return $this->setVersionStatus($versionId, 'EOL');
    }

    /** Archive a version: terminal lifecycle state. */
    public function archiveVersion($versionId)
    {
        return $this->setVersionStatus($versionId, 'ARCHIVED');
    }

    public function setVersionStatus($versionId, $status)
    {
        $status = strtoupper((string) $status);
        if (!in_array($status, self::VERSION_STATUSES, true)) {
            throw new ValidationException(['status' => 'Unknown version status.']);
        }
        $row = $this->versionRow($versionId);
        if (!$row) {
            throw new NotFoundException('Version not found.');
        }
        Db::update('operating_system_versions', ['id' => (int) $versionId], [
            'status'     => $status,
            'updated_at' => Clock::now(),
        ]);
        return true;
    }

    /** Refused while provider image mappings reference the version. */
    public function deleteVersion($versionId)
    {
        $versionId = (int) $versionId;
        if (!$this->versionRow($versionId)) {
            throw new NotFoundException('Version not found.');
        }
        if (Db::count('server_os_images', ['operating_system_version_id' => $versionId]) > 0) {
            throw new DuplicateOperationException('This version still has provider image mappings — delete them first.');
        }
        return Db::delete('operating_system_versions', ['id' => $versionId]) > 0;
    }

    /* ------------------------------------------- provider image mappings -- */

    /**
     * @return array[] image mappings joined with provider + OS/version names
     */
    public function images(array $filters = [])
    {
        $sql = 'SELECT i.*, p.name AS provider_name, p.slug AS provider_slug, p.is_enabled AS provider_enabled,
                       v.display_name AS version_name, v.version, o.name AS os_name, o.slug AS os_slug,
                       r.code AS region_code, r.name AS region_name
                FROM ' . Db::t('server_os_images') . ' i
                JOIN ' . Db::t('infrastructure_providers') . ' p ON p.id = i.provider_id
                JOIN ' . Db::t('operating_system_versions') . ' v ON v.id = i.operating_system_version_id
                JOIN ' . Db::t('operating_systems') . ' o ON o.id = v.operating_system_id
                LEFT JOIN ' . Db::t('infrastructure_regions') . ' r ON r.id = i.region_id';
        $where = [];
        $bind = [];
        if (!empty($filters['provider_id'])) {
            $where[] = 'i.provider_id = ?';
            $bind[] = (int) $filters['provider_id'];
        }
        if (!empty($filters['os_id'])) {
            $where[] = 'o.id = ?';
            $bind[] = (int) $filters['os_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'i.status = ?';
            $bind[] = (string) $filters['status'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.name, o.sort_order, v.id, i.architecture';
        $rows = Db::query($sql, $bind) ?: [];
        foreach ($rows as &$row) {
            $row['metadata'] = json_decode((string) $row['metadata'], true) ?: [];
        }
        unset($row);
        return $rows;
    }

    /** @return array|null */
    public function imageRow($imageId)
    {
        $row = Db::first('server_os_images', ['id' => (int) $imageId]);
        if ($row) {
            $row['metadata'] = json_decode((string) $row['metadata'], true) ?: [];
        }
        return $row ?: null;
    }

    /**
     * Create or update a provider image mapping.
     *
     * Admin validation (§48): provider exists and is enabled, OS version
     * exists, architecture is in the version's support list, region belongs
     * to the provider, and an image or template identifier is present.
     * New mappings start disabled — enabling requires a passed provider
     * test (see testImage / setImageStatus).
     *
     * @return int image id
     */
    public function saveImage($imageId, array $data)
    {
        $errors = [];
        $providerId = (int) (isset($data['provider_id']) ? $data['provider_id'] : 0);
        $provider = $this->registry->find($providerId);
        if (!$provider) {
            $errors['provider_id'] = 'Provider not found.';
        } elseif (empty($provider['is_enabled'])) {
            $errors['provider_id'] = 'Provider is disabled.';
        }
        $versionId = (int) (isset($data['operating_system_version_id']) ? $data['operating_system_version_id'] : 0);
        $version = $this->versionRow($versionId);
        if (!$version) {
            $errors['operating_system_version_id'] = 'OS version not found.';
        }
        $architecture = strtolower(trim((string) (isset($data['architecture']) ? $data['architecture'] : 'x86_64')));
        if (!in_array($architecture, self::ARCHITECTURES, true)) {
            $errors['architecture'] = 'Architecture must be one of: ' . implode(', ', self::ARCHITECTURES);
        } elseif ($version && !in_array($architecture, $version['architectures'], true)) {
            $errors['architecture'] = 'Architecture is not in this version\'s support list.';
        }
        $regionId = (int) (isset($data['region_id']) ? $data['region_id'] : 0);
        if ($regionId) {
            $region = Db::first('infrastructure_regions', ['id' => $regionId]);
            if (!$region || (int) $region['provider_id'] !== $providerId) {
                $errors['region_id'] = 'Region does not belong to the selected provider.';
            }
        }
        $imageRef = trim((string) (isset($data['provider_image_id']) ? $data['provider_image_id'] : ''));
        $templateRef = trim((string) (isset($data['provider_template_id']) ? $data['provider_template_id'] : ''));
        if ($imageRef === '' && $templateRef === '') {
            $errors['provider_image_id'] = 'A provider image id or template id is required.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $now = Clock::now();
        $row = [
            'provider_id'                  => $providerId,
            'operating_system_version_id'  => $versionId,
            'provider_image_id'            => $imageRef,
            'provider_template_id'         => $templateRef,
            'architecture'                 => $architecture,
            'region_id'                    => $regionId ?: null,
            'metadata'                     => json_encode(isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : []),
            'updated_at'                   => $now,
        ];
        if ($imageId && Db::first('server_os_images', ['id' => (int) $imageId])) {
            // An edited mapping must be re-tested before it can serve traffic.
            $row['status'] = 'disabled';
            $row['test_result'] = '';
            $row['last_tested_at'] = null;
            Db::update('server_os_images', ['id' => (int) $imageId], $row);
            return (int) $imageId;
        }
        $row['status'] = 'disabled';
        $row['created_at'] = $now;
        return Db::insert('server_os_images', $row);
    }

    /**
     * Test Image: ask the provider whether the mapped image actually exists
     * before it may be enabled for production provisioning. The outcome is
     * persisted on the mapping.
     *
     * @return array{status:string,detail:string}
     */
    public function testImage($imageId)
    {
        $image = $this->imageRow($imageId);
        if (!$image) {
            throw new NotFoundException('Image mapping not found.');
        }
        $provider = $this->registry->instance((int) $image['provider_id']);
        try {
            if (!$provider->isConfigured()) {
                throw ProviderFailure::notConfigured('provider credentials are not configured (set CHS_CREDENTIALS_KEY and save credentials)');
            }
            $ref = $image['provider_image_id'] !== '' ? $image['provider_image_id'] : $image['provider_template_id'];
            $found = $provider->getImage($ref);
            $result = $found
                ? ['status' => 'ok', 'detail' => 'Image "' . $ref . '" exists at the provider.']
                : ['status' => 'failed', 'detail' => 'Provider does not list image "' . $ref . '" — it cannot be deployed.'];
        } catch (ProviderFailure $e) {
            $result = ['status' => 'failed', 'detail' => $e->machineCode() . ': ' . $e->getMessage()];
        } catch (\Throwable $e) {
            $result = ['status' => 'failed', 'detail' => 'PROVIDER_ERROR: ' . $e->getMessage()];
        }
        Db::update('server_os_images', ['id' => (int) $imageId], [
            'test_result'    => $result['status'],
            'last_tested_at' => Clock::now(),
            'updated_at'     => Clock::now(),
        ]);
        return $result;
    }

    /**
     * Enable/disable a mapping. Enabling is refused (§48) unless the provider
     * is configured and the last provider test passed — an untested or
     * failing image is never deployable.
     */
    public function setImageStatus($imageId, $status)
    {
        $status = strtolower((string) $status);
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new ValidationException(['status' => 'Status must be active or disabled.']);
        }
        $image = $this->imageRow($imageId);
        if (!$image) {
            throw new NotFoundException('Image mapping not found.');
        }
        if ($status === 'active') {
            $provider = $this->registry->instance((int) $image['provider_id']);
            if (!$provider->isConfigured()) {
                throw new ValidationException(['status' => 'CONFIGURATION_REQUIRED: the provider needs a base URL and sealed credentials (CHS_CREDENTIALS_KEY) before images can be enabled.']);
            }
            if ((string) $image['test_result'] !== 'ok') {
                throw new ValidationException(['status' => 'Run "Test image" first — only a mapping whose provider test passed can be enabled.']);
            }
        }
        Db::update('server_os_images', ['id' => (int) $imageId], [
            'status'     => $status,
            'updated_at' => Clock::now(),
        ]);
        return true;
    }

    /** Refused while provisioning jobs reference the mapping. */
    public function deleteImage($imageId)
    {
        $imageId = (int) $imageId;
        if (!$this->imageRow($imageId)) {
            throw new NotFoundException('Image mapping not found.');
        }
        if (Db::count('provisioning_jobs', ['os_image_id' => $imageId]) > 0) {
            throw new DuplicateOperationException('Provisioning jobs reference this mapping — it cannot be deleted.');
        }
        return Db::delete('server_os_images', ['id' => $imageId]) > 0;
    }

    /* ------------------------------------------------------ product rules -- */

    /** @return array[] rules joined with product names */
    public function productRules()
    {
        $rows = Db::all('server_product_rules', [], 'product_id ASC');
        $products = [];
        foreach (Platform::gateway()->serverProducts() as $p) {
            $products[(int) $p['id']] = $p;
        }
        foreach ($rows as &$row) {
            $pid = (int) $row['product_id'];
            $row['product_name'] = isset($products[$pid]) ? $products[$pid]['name'] : ('#' . $pid);
            $row['provider_name'] = '';
            if (!empty($row['provider_id'])) {
                $provider = $this->registry->find((int) $row['provider_id']);
                $row['provider_name'] = $provider ? $provider['name'] : '';
            }
            $row['region_name'] = '';
            if (!empty($row['region_id'])) {
                $region = Db::first('infrastructure_regions', ['id' => (int) $row['region_id']]);
                $row['region_name'] = $region ? $region['name'] : '';
            }
        }
        unset($row);
        return $rows;
    }

    /** @return array|null */
    public function ruleForProduct($productId)
    {
        $row = Db::first('server_product_rules', ['product_id' => (int) $productId]);
        return $row ?: null;
    }

    /**
     * Save the per-product rule: server type classification + optional
     * provider/region restriction. No row = every enabled provider/region.
     *
     * @return int rule id
     */
    public function saveProductRule($productId, array $data)
    {
        $productId = (int) $productId;
        $errors = [];
        $product = Platform::gateway()->productDetail($productId);
        if (!$product || (string) $product['type'] !== 'server') {
            $errors['product_id'] = 'Product not found or not a server product.';
        }
        $serverType = strtolower((string) (isset($data['server_type']) ? $data['server_type'] : 'vps'));
        if (!in_array($serverType, self::SERVER_TYPES, true)) {
            $errors['server_type'] = 'Server type must be one of: ' . implode(', ', self::SERVER_TYPES);
        }
        $providerId = (int) (isset($data['provider_id']) ? $data['provider_id'] : 0);
        if ($providerId && !$this->registry->find($providerId)) {
            $errors['provider_id'] = 'Provider not found.';
        }
        $regionId = (int) (isset($data['region_id']) ? $data['region_id'] : 0);
        if ($regionId && !Db::first('infrastructure_regions', ['id' => $regionId])) {
            $errors['region_id'] = 'Region not found.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $now = Clock::now();
        $row = [
            'server_type' => $serverType,
            'provider_id' => $providerId ?: null,
            'region_id'   => $regionId ?: null,
            'updated_at'  => $now,
        ];
        $existing = $this->ruleForProduct($productId);
        if ($existing) {
            Db::update('server_product_rules', ['id' => (int) $existing['id']], $row);
            return (int) $existing['id'];
        }
        $row['product_id'] = $productId;
        $row['created_at'] = $now;
        return Db::insert('server_product_rules', $row);
    }

    public function deleteProductRule($productId)
    {
        return Db::delete('server_product_rules', ['product_id' => (int) $productId]) > 0;
    }

    /* ------------------------------------------- customer-facing catalog -- */

    /** Active regions across enabled providers (order form region picker). */
    public function regions()
    {
        return $this->registry->activeRegions();
    }

    /**
     * The availability matrix for the order form.
     *
     * @param string $serverType vps | dedicated | cloud
     * @param int    $productId  0 = no product restriction
     * @param int    $regionId   0 = any region
     * @param string $architecture '' = any
     * @return array<int,array> OSes: id, name, slug, vendor, logo_url,
     *         versions: [id, display_name, is_default, is_lts, eol, architectures: [arch => providers]]
     */
    public function catalogFor($serverType, $productId = 0, $regionId = 0, $architecture = '')
    {
        $serverType = strtolower((string) $serverType);
        if (!in_array($serverType, self::SERVER_TYPES, true)) {
            $serverType = 'vps';
        }
        $typeFlag = 'is_' . $serverType . '_supported';
        $regionId = (int) $regionId;
        $architecture = strtolower(trim((string) $architecture));

        // Product rule restrictions (provider/region pinning).
        $rule = $productId ? $this->ruleForProduct($productId) : null;
        $allowedProvider = $rule && !empty($rule['provider_id']) ? (int) $rule['provider_id'] : 0;
        $ruleRegion = $rule && !empty($rule['region_id']) ? (int) $rule['region_id'] : 0;
        if ($ruleRegion) {
            $regionId = $ruleRegion; // a pinned region wins over the request
        }

        $oses = Db::all('operating_systems', ['status' => 'ACTIVE', $typeFlag => 1], 'sort_order ASC, id ASC');
        $out = [];
        foreach ($oses as $os) {
            $versions = [];
            foreach ($this->versions((int) $os['id']) as $version) {
                if (!in_array($version['status'], self::SELECTABLE_VERSION_STATUSES, true)) {
                    continue; // EOL / retired versions never appear for new deployments
                }
                $archs = json_decode((string) $version['architecture_support'], true) ?: ['x86_64'];
                if ($architecture !== '' && !in_array($architecture, $archs, true)) {
                    continue;
                }
                $archProviders = [];
                foreach ($archs as $arch) {
                    if ($architecture !== '' && $arch !== $architecture) {
                        continue;
                    }
                    $providers = $this->providersForVersion((int) $version['id'], $arch, $regionId, $allowedProvider);
                    if ($providers) {
                        $archProviders[$arch] = $providers;
                    }
                }
                if (!$archProviders) {
                    continue; // no active provider image → not orderable
                }
                $versions[] = [
                    'id'           => (int) $version['id'],
                    'display_name' => (string) $version['display_name'],
                    'is_default'   => (int) $version['is_default'] === 1,
                    'is_lts'       => (int) $version['is_lts'] === 1,
                    'end_of_life'  => $version['end_of_life_date'],
                    'architectures' => $archProviders,
                ];
            }
            if (!$versions) {
                continue;
            }
            $out[] = [
                'id'          => (int) $os['id'],
                'name'        => (string) $os['name'],
                'slug'        => (string) $os['slug'],
                'vendor'      => (string) $os['vendor'],
                'description' => (string) $os['description'],
                'logo_url'    => (string) $os['logo_url'],
                'versions'    => $versions,
            ];
        }
        return $out;
    }

    /**
     * Full order-form configuration for one product: the availability matrix
     * plus the regions and architectures the product can actually use.
     *
     * @return array{product:array, regions:array[], architectures:array<string>, operatingSystems:array[]}
     */
    public function configurationFor($productId, $regionId = 0, $architecture = '')
    {
        $productId = (int) $productId;
        $product = Platform::gateway()->productDetail($productId);
        if (!$product || (string) $product['type'] !== 'server') {
            throw new NotFoundException('Server product not found.');
        }
        $visible = false;
        foreach (Platform::gateway()->serverProducts() as $p) {
            if ((int) $p['id'] === $productId) {
                $visible = true;
                break;
            }
        }
        if (!$visible) {
            throw new NotFoundException('Server product not found.');
        }

        $rule = $this->ruleForProduct($productId);
        $serverType = $rule ? (string) $rule['server_type'] : 'vps';
        $catalog = $this->catalogFor($serverType, $productId, (int) $regionId, (string) $architecture);

        $regions = [];
        $architectures = [];
        foreach ($this->regions() as $region) {
            $regions[] = [
                'id'            => (int) $region['id'],
                'code'          => (string) $region['code'],
                'name'          => (string) $region['name'],
                'datacenter'    => (string) $region['datacenter'],
                'provider_id'   => (int) $region['provider_id'],
                'provider_name' => (string) $region['provider_name'],
            ];
        }
        foreach ($catalog as $os) {
            foreach ($os['versions'] as $version) {
                foreach ($version['architectures'] as $arch => $providers) {
                    $architectures[$arch] = true;
                }
            }
        }

        return [
            'product' => [
                'id'          => $productId,
                'name'        => (string) $product['name'],
                'description' => (string) $product['description'],
                'server_type' => $serverType,
                'paytype'     => (string) $product['paytype'],
            ],
            'regions'          => $regions,
            'architectures'    => array_keys($architectures),
            'operatingSystems' => $catalog,
        ];
    }

    /**
     * Providers (enabled, with an active image) covering one version +
     * architecture + region — the availability join behind the matrix.
     *
     * @return array<int,array{id:int,name:string,slug:string,is_default:bool}>
     */
    public function providersForVersion($versionId, $architecture, $regionId = 0, $onlyProviderId = 0)
    {
        $sql = 'SELECT DISTINCT p.id, p.name, p.slug, p.is_default
                FROM ' . Db::t('server_os_images') . ' i
                JOIN ' . Db::t('infrastructure_providers') . ' p ON p.id = i.provider_id
                WHERE i.operating_system_version_id = ? AND i.status = \'active\'
                  AND i.architecture = ? AND p.is_enabled = 1';
        $bind = [(int) $versionId, (string) $architecture];
        if ((int) $regionId) {
            // A selected region admits region-scoped and region-agnostic images.
            $sql .= ' AND (i.region_id IS NULL OR i.region_id = ?)';
            $bind[] = (int) $regionId;
        }
        if ($onlyProviderId) {
            $sql .= ' AND p.id = ?';
            $bind[] = (int) $onlyProviderId;
        }
        $sql .= ' ORDER BY p.is_default DESC, p.id ASC';
        $rows = Db::query($sql, $bind) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'name'       => (string) $row['name'],
                'slug'       => (string) $row['slug'],
                'is_default' => (int) $row['is_default'] === 1,
            ];
        }
        return $out;
    }

    /** Architectures actually orderable for a version (provider-backed). */
    public function architecturesFor($versionId, $providerId = 0, $regionId = 0)
    {
        $version = $this->versionRow($versionId);
        if (!$version) {
            throw new NotFoundException('Version not found.');
        }
        $archs = json_decode((string) $version['architecture_support'], true) ?: ['x86_64'];
        $out = [];
        foreach ($archs as $arch) {
            $providers = $this->providersForVersion($versionId, $arch, (int) $regionId, (int) $providerId);
            if ($providers) {
                $out[$arch] = $providers;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------ internals -- */

    private function enforceSingleDefault($osId, $versionId, $isDefault)
    {
        if (!$isDefault) {
            return;
        }
        Db::exec(
            'UPDATE ' . Db::t('operating_system_versions') . ' SET is_default = 0 WHERE operating_system_id = ? AND id != ?',
            [(int) $osId, (int) $versionId]
        );
    }
}
