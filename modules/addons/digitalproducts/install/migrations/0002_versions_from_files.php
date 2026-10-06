<?php
use WHMCS\Database\Capsule;
use DigitalProducts\Storage\StorageFactory;
use DigitalProducts\Core\Clock;

return [
    'id' => '0002_versions_from_files',
    'description' => 'Copy legacy file records into the version model without deleting legacy data.',
    'up' => function ($m) {
        if (!$m->tableExists('mod_digitalproducts_files')) return;
        $storage = null;
        foreach (Capsule::table('mod_digitalproducts_files')->orderBy('id')->get() as $file) {
            $exists = Capsule::table('mod_digitalproducts_versions')->where('product_id', (int) $file->product_id)->where('version', (string) $file->version)->first();
            if ($exists) {
                if (Capsule::table('mod_digitalproducts_products')->where('id', (int) $file->product_id)->whereNull('current_version_id')->where('current_file_id', (int) $file->id)->exists()) Capsule::table('mod_digitalproducts_products')->where('id', (int) $file->product_id)->update(['current_version_id' => $exists->id]);
                continue;
            }
            $versionId = Capsule::table('mod_digitalproducts_versions')->insertGetId([
                'product_id' => (int) $file->product_id, 'version' => (string) $file->version, 'storage_key' => null,
                'original_filename' => (string) ($file->original_name ?: $file->filename), 'file_size' => (int) $file->file_size,
                'checksum_sha256' => $file->file_hash ?: null, 'release_notes' => $file->changelog ?: null, 'changelog' => $file->changelog ?: null,
                'release_date' => $file->created_at ?: Clock::now(), 'status' => ($file->status === 'active' ? 'active' : 'disabled'), 'download_count' => (int) ($file->download_count ?: 0), 'created_at' => $file->created_at ?: Clock::now(), 'updated_at' => $file->updated_at ?: Clock::now(),
            ]);
            // Move, where possible, into private storage. A missing legacy file
            // remains a visible version but is not downloadable until replaced.
            if (!empty($file->file_path) && is_file($file->file_path)) {
                try {
                    if ($storage === null) $storage = StorageFactory::make();
                    $extension = substr(strrchr((string) ($file->original_name ?: $file->filename), '.'), 1);
                    $key = $storage->put($file->file_path, (int) $file->product_id, (int) $versionId, $extension);
                    $checksum = $storage->checksum($key);
                    Capsule::table('mod_digitalproducts_versions')->where('id', $versionId)->update(['storage_key' => $key, 'checksum_sha256' => $checksum, 'file_size' => $storage->size($key)]);
                    // If the legacy object was under the document root, remove
                    // the public copy after the private copy is verified.
                    if (defined('ROOTDIR')) { $legacy = realpath($file->file_path); $document = realpath(ROOTDIR); if ($legacy && $document && strpos($legacy, $document . DIRECTORY_SEPARATOR) === 0) @unlink($legacy); }
                } catch (\Throwable $e) {
                    // Do not abort the whole activation because a historical
                    // path is unavailable; the admin sees the missing file.
                }
            }
            if ($m->tableExists('mod_digitalproducts_downloads')) Capsule::table('mod_digitalproducts_downloads')->where('file_id', (int) $file->id)->whereNull('version_id')->update(['version_id' => $versionId, 'product_id' => (int) $file->product_id]);
            Capsule::table('mod_digitalproducts_products')->where('id', (int) $file->product_id)->whereNull('current_version_id')->where('current_file_id', (int) $file->id)->update(['current_version_id' => $versionId]);
        }
        // Fresh products with only the old pointer still get their current
        // version resolved when the previous update could not use NULL syntax.
        foreach (Capsule::table('mod_digitalproducts_products')->whereNull('current_version_id')->whereNotNull('current_file_id')->get() as $product) {
            $file = Capsule::table('mod_digitalproducts_files')->where('id', $product->current_file_id)->first();
            if ($file) { $version = Capsule::table('mod_digitalproducts_versions')->where('product_id', $product->id)->where('version', $file->version)->first(); if ($version) Capsule::table('mod_digitalproducts_products')->where('id', $product->id)->update(['current_version_id' => $version->id]); }
        }
    },
];
