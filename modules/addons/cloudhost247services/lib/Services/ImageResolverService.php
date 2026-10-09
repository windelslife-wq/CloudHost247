<?php
/**
 * Provider image resolver.
 *
 * Answers the one question provisioning asks: "which provider image deploys
 * this OS version on this architecture in this region?" — translating the
 * CloudHost247-level selection (Ubuntu 24.04 LTS, x86_64, Frankfurt) into the
 * provider-specific identifier (OVH image id, Proxmox template, Virtualizor
 * template id…). The provider-specific identifier never reaches the customer.
 *
 * Resolution order:
 *   1. an explicitly requested provider (must be enabled and hold an active
 *      mapping for the combination)
 *   2. region-specific mappings before provider-wide (region-agnostic) ones
 *   3. the provider flagged default, then the lowest id
 *
 * When nothing covers the combination the resolver fails honestly with
 * IMAGE_UNAVAILABLE — provisioning never falls back to a guessed image.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Db;
use Chs\Providers\Infrastructure\ProviderFailure;

class ImageResolverService
{
    /**
     * @param int    $versionId
     * @param string $architecture x86_64 | arm64
     * @param int    $providerId   0 = any enabled provider
     * @param int    $regionId     0 = any region
     * @return array image row (server_os_images) + provider_id/name
     */
    public function resolve($versionId, $architecture, $providerId = 0, $regionId = 0)
    {
        $sql = 'SELECT i.*, p.name AS provider_name, p.slug AS provider_slug, p.is_default
                FROM ' . Db::t('server_os_images') . ' i
                JOIN ' . Db::t('infrastructure_providers') . ' p ON p.id = i.provider_id
                WHERE i.operating_system_version_id = ? AND i.status = \'active\'
                  AND i.architecture = ? AND p.is_enabled = 1';
        $bind = [(int) $versionId, strtolower((string) $architecture)];
        if ((int) $regionId) {
            // A selected region admits region-scoped and region-agnostic images.
            $sql .= ' AND (i.region_id IS NULL OR i.region_id = ?)';
            $bind[] = (int) $regionId;
        }
        if ((int) $providerId) {
            $sql .= ' AND p.id = ?';
            $bind[] = (int) $providerId;
        }
        // Region-specific mappings first, then provider-wide, then default.
        $sql .= ' ORDER BY (i.region_id IS NOT NULL) DESC, p.is_default DESC, p.id ASC, i.id ASC LIMIT 1';
        $rows = Db::query($sql, $bind);
        if (!$rows) {
            throw ProviderFailure::imageUnavailable(
                'no active provider image for version #' . (int) $versionId
                . ' on ' . $architecture
                . ((int) $regionId ? ' in region #' . (int) $regionId : '')
            );
        }
        return $rows[0];
    }

    /** The provider-side identifier to hand to createServer/reinstall. */
    public function providerImageRef(array $imageRow)
    {
        if (trim((string) $imageRow['provider_image_id']) !== '') {
            return (string) $imageRow['provider_image_id'];
        }
        return (string) $imageRow['provider_template_id'];
    }

    /** Whether the combination is orderable right now (no exception path). */
    public function isAvailable($versionId, $architecture, $providerId = 0, $regionId = 0)
    {
        try {
            $this->resolve($versionId, $architecture, $providerId, $regionId);
            return true;
        } catch (ProviderFailure $e) {
            return false;
        }
    }
}
