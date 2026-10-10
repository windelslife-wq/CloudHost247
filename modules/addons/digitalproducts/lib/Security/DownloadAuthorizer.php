<?php
namespace DigitalProducts\Security;

use WHMCS\Database\Capsule;

class DownloadAuthorizer
{
    public function resolve($tokenId, $clientId = 0)
    {
        $query = Capsule::table('mod_digitalproducts_download_tokens as t')
            ->join('mod_digitalproducts_entitlements as e', 'e.id', '=', 't.entitlement_id')
            ->join('mod_digitalproducts_products as p', 'p.id', '=', 'e.product_id')
            ->join('mod_digitalproducts_versions as v', 'v.id', '=', 't.version_id')
            ->where('t.id', (int) $tokenId)
            ->select('t.*', 'e.product_id', 'e.service_id', 'e.order_id', 'e.client_id as entitlement_client_id', 'e.status as entitlement_status', 'e.download_limit as entitlement_limit', 'e.downloads_used', 'e.access_mode', 'e.purchase_version_id', 'p.whmcs_product_id', 'p.status as product_status', 'p.download_limit as product_limit', 'p.download_expiry_hours', 'p.current_version_id', 'p.product_name', 'v.version', 'v.storage_key', 'v.original_filename', 'v.file_size', 'v.checksum_sha256', 'v.status as version_status', 'v.product_id as version_product_id', 'v.release_notes', 'v.changelog');
        $record = $query->first();
        if (!$record) return ['ok' => false, 'reason' => 'invalid_token'];
        if ($clientId && (int) $record->client_id !== (int) $clientId) return ['ok' => false, 'reason' => 'not_entitled', 'record' => $record];
        if ($record->expires_at && strtotime($record->expires_at) <= time()) return ['ok' => false, 'reason' => 'expired', 'record' => $record];
        if ((int) $record->single_use && $record->used_at) return ['ok' => false, 'reason' => 'invalid_token', 'record' => $record];
        if ($record->entitlement_status !== 'active') return ['ok' => false, 'reason' => 'not_entitled', 'record' => $record];
        if ($record->product_status !== 'active' || $record->version_status !== 'active' || (int) $record->version_product_id !== (int) $record->product_id) return ['ok' => false, 'reason' => 'not_entitled', 'record' => $record];

        $service = Capsule::table('tblhosting')->where('id', (int) $record->service_id)->where('userid', (int) $record->client_id)->where('packageid', (int) $record->whmcs_product_id)->first();
        if (!$service || !in_array(strtolower((string) $service->domainstatus), ['active', 'completed'], true)) return ['ok' => false, 'reason' => 'not_entitled', 'record' => $record];
        if (!empty($service->orderid)) {
            $order = Capsule::table('tblorders')->where('id', (int) $service->orderid)->first();
            if ($order && !in_array(strtolower((string) $order->status), ['active', 'completed'], true)) return ['ok' => false, 'reason' => 'not_entitled', 'record' => $record];
        }
        if ($this->versionNotAllowed($record)) return ['ok' => false, 'reason' => 'not_entitled', 'record' => $record];
        return ['ok' => true, 'record' => $record, 'service' => $service];
    }

    public function claim($record)
    {
        $limit = $record->entitlement_limit === null ? (int) $record->product_limit : (int) $record->entitlement_limit;
        $query = Capsule::table('mod_digitalproducts_entitlements')->where('id', (int) $record->entitlement_id);
        if ($limit > 0) $query->where('downloads_used', '<', $limit);
        return $query->update(['downloads_used' => Capsule::raw('downloads_used + 1'), 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }

    /**
     * The single rule that decides which release an entitlement may download.
     *
     * purchase_version entitlements are locked to the release bought; every
     * other entitlement follows the product's current release. Returns null
     * when the entitlement is not yet bound to a release, which callers must
     * treat as "refuse" — a missing id must never widen access.
     *
     * The client area and the API both call this, so a download link can never
     * be issued for a release the download endpoint would then reject.
     *
     * @param object      $entitlement      Row carrying access_mode and purchase_version_id.
     * @param string|int|null $currentVersionId The product's current release, when not on the row.
     * @return int|null
     */
    public function allowedVersionId($entitlement, $currentVersionId = null)
    {
        if (!is_object($entitlement)) return null;
        if ($currentVersionId === null) {
            $currentVersionId = $entitlement->current_version_id ?? null;
        }
        if (($entitlement->access_mode ?? 'current_version') === 'purchase_version') {
            $locked = $entitlement->purchase_version_id ?? null;
            return $locked === null ? null : (int) $locked;
        }
        return $currentVersionId === null ? null : (int) $currentVersionId;
    }

    /**
     * True when the token's version is NOT the one this entitlement may download.
     * Fails closed when the expected id is missing (null never equals a real id).
     */
    protected function versionNotAllowed($record)
    {
        $allowed = $this->allowedVersionId($record, $record->current_version_id ?? null);
        return $allowed === null || (int) $record->version_id !== $allowed;
    }
}
