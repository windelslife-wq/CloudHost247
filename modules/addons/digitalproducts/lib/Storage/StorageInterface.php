<?php
namespace DigitalProducts\Storage;

interface StorageInterface
{
    public function put($sourcePath, $productId, $versionId, $extension);
    public function exists($storageKey);
    public function path($storageKey);
    public function size($storageKey);
    public function checksum($storageKey);
    public function stream($storageKey);
    public function delete($storageKey);
    public function root();
}
