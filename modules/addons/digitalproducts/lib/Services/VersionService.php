<?php
namespace DigitalProducts\Services;

use DigitalProducts\Core\Audit;
use DigitalProducts\Core\Clock;
use DigitalProducts\Core\ValidationException;
use DigitalProducts\Security\UploadValidator;
use DigitalProducts\Storage\StorageFactory;
use WHMCS\Database\Capsule;

class VersionService
{
    public function upload($productId, $version, array $file, array $meta = [])
    {
        $product = Capsule::table('mod_digitalproducts_products')->where('id', (int) $productId)->first();
        if (!$product) throw new ValidationException('Digital product not found.');
        $version = trim((string) $version);
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,49}$/', $version)) throw new ValidationException('Invalid version.');
        if (Capsule::table('mod_digitalproducts_versions')->where('product_id', (int) $productId)->where('version', $version)->exists()) throw new ValidationException('That version already exists.');
        $validated = (new UploadValidator())->validate($file);
        $storage = StorageFactory::make();
        // Put into a temporary product/version directory using a harmless
        // placeholder id, then rename only through the storage abstraction.
        $key = $storage->put($validated['tmp_name'], (int) $productId, 0, $validated['extension']);
        try {
            $actualHash = $storage->checksum($key);
            if (!$actualHash || !hash_equals($validated['sha256'], $actualHash) || $storage->size($key) !== $validated['size']) throw new ValidationException('Stored file verification failed.');
            $id = Capsule::table('mod_digitalproducts_versions')->insertGetId(['product_id' => (int) $productId, 'version' => $version, 'storage_key' => $key, 'original_filename' => $validated['original_name'], 'file_size' => $validated['size'], 'checksum_sha256' => $actualHash, 'release_notes' => (string) ($meta['release_notes'] ?? ''), 'changelog' => (string) ($meta['changelog'] ?? ''), 'min_php' => $meta['min_php'] ?? null, 'max_php' => $meta['max_php'] ?? null, 'min_whmcs' => $meta['min_whmcs'] ?? null, 'max_whmcs' => $meta['max_whmcs'] ?? null, 'required_extensions' => $meta['required_extensions'] ?? null, 'release_date' => $meta['release_date'] ?? Clock::now(), 'status' => !empty($meta['publish']) ? 'active' : 'draft', 'download_count' => 0, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
            // Re-home the blob into product-{id}/version-{id}. The temporary
            // object is never exposed and is removed if metadata fails.
            $finalKey = $storage->put($validated['tmp_name'], (int) $productId, (int) $id, $validated['extension']);
            if (!$storage->exists($finalKey) || $storage->size($finalKey) !== $validated['size'] || $storage->checksum($finalKey) !== $actualHash) throw new ValidationException('Stored file verification failed.');
            $storage->delete($key);
            $key = $finalKey;
            Capsule::table('mod_digitalproducts_versions')->where('id', $id)->update(['storage_key' => $finalKey]);
            if (!empty($meta['publish']) && (empty($product->current_version_id) || !empty($meta['set_current']))) Capsule::table('mod_digitalproducts_products')->where('id', (int) $productId)->update(['current_version_id' => $id, 'current_file_id' => null, 'updated_at' => Clock::now()]);
            Audit::record('version.uploaded', 'version:' . $id, ['product_id' => $productId, 'version' => $version]);
            if (!empty($meta['set_current'])) $this->setCurrent($productId, $id);
            return Capsule::table('mod_digitalproducts_versions')->where('id', $id)->first();
        } catch (\Throwable $e) {
            $storage->delete($key);
            if (!empty($id)) Capsule::table('mod_digitalproducts_versions')->where('id', $id)->delete();
            throw $e;
        }
    }

    public function setCurrent($productId, $versionId)
    {
        $version = Capsule::table('mod_digitalproducts_versions')->where('id', (int) $versionId)->where('product_id', (int) $productId)->where('status', 'active')->first();
        if (!$version) throw new ValidationException('Version is not active or does not belong to this product.');
        Capsule::table('mod_digitalproducts_products')->where('id', (int) $productId)->update(['current_version_id' => $version->id, 'updated_at' => Clock::now()]);
        Audit::record('version.activated', 'version:' . $version->id, ['product_id' => $productId]);
        return $version;
    }

    public function publish($versionId)
    {
        $version = Capsule::table('mod_digitalproducts_versions')->where('id', (int) $versionId)->first();
        if (!$version) throw new ValidationException('Version not found.');
        Capsule::table('mod_digitalproducts_versions')->where('id', $version->id)->update(['status' => 'active', 'updated_at' => Clock::now()]);
        Audit::record('version.activated', 'version:' . $version->id, ['product_id' => $version->product_id]);
        return true;
    }

    public function retire($versionId)
    {
        $version = Capsule::table('mod_digitalproducts_versions')->where('id', (int) $versionId)->first();
        if (!$version) return false;
        Capsule::table('mod_digitalproducts_versions')->where('id', $version->id)->update(['status' => 'retired', 'updated_at' => Clock::now()]);
        Audit::record('version.retired', 'version:' . $version->id, ['product_id' => $version->product_id]);
        return true;
    }
}
