<?php
namespace DigitalProducts\Storage;

use DigitalProducts\Core\Settings;
use DigitalProducts\Core\StorageException;

class LocalPrivateStorage implements StorageInterface
{
    protected $root;

    public function __construct($root = null)
    {
        $this->root = $root ?: self::configuredRoot();
        $this->root = rtrim($this->root, DIRECTORY_SEPARATOR);
        $this->assertSafeRoot($this->root);
        if (!is_dir($this->root) && !@mkdir($this->root, 0700, true)) throw new StorageException('Private storage is not writable.');
        if (!is_writable($this->root)) throw new StorageException('Private storage is not writable.');
        $this->protectRoot();
    }

    public static function configuredRoot()
    {
        $configured = trim((string) Settings::get('storage_path'));
        if ($configured !== '') return $configured;
        $env = getenv('DIGITALPRODUCTS_STORAGE');
        if ($env !== false && trim($env) !== '') return trim($env);
        if (defined('ROOTDIR')) return dirname(ROOTDIR) . '/cloudhost247-private/digitalproducts';
        return sys_get_temp_dir() . '/cloudhost247-digitalproducts';
    }

    public function root() { return $this->root; }

    public function put($sourcePath, $productId, $versionId, $extension)
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) throw new StorageException('Uploaded file is unavailable.');
        $extension = preg_replace('/[^a-z0-9.]/', '', strtolower((string) $extension));
        $relative = 'product-' . (int) $productId . '/version-' . (int) $versionId . '/' . bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
        $destination = $this->path($relative);
        $dir = dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new StorageException('Unable to create private storage directory.');
        if (!@copy($sourcePath, $destination)) throw new StorageException('Unable to store uploaded file.');
        @chmod($destination, 0600);
        return $relative;
    }

    public function exists($storageKey) { return is_file($this->path($storageKey)); }
    public function path($storageKey)
    {
        $storageKey = str_replace('\\', '/', (string) $storageKey);
        if ($storageKey === '' || strpos($storageKey, '..') !== false || $storageKey[0] === '/' || strpos($storageKey, "\0") !== false) throw new StorageException('Invalid storage key.');
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storageKey);
        $root = realpath($this->root);
        $parent = realpath(dirname($path));
        if ($root !== false && $parent !== false && strpos($parent, $root . DIRECTORY_SEPARATOR) !== 0 && $parent !== $root) throw new StorageException('Storage path escaped private root.');
        return $path;
    }
    public function size($storageKey) { return $this->exists($storageKey) ? filesize($this->path($storageKey)) : 0; }
    public function checksum($storageKey) { return $this->exists($storageKey) ? hash_file('sha256', $this->path($storageKey)) : null; }
    public function stream($storageKey)
    {
        $handle = @fopen($this->path($storageKey), 'rb');
        if (!$handle) throw new StorageException('Unable to open private file.');
        return $handle;
    }
    public function delete($storageKey) { $path = $this->path($storageKey); return !is_file($path) || @unlink($path); }

    protected function assertSafeRoot($root)
    {
        if ($root === '' || strpos($root, "\0") !== false) throw new StorageException('Storage path is invalid.');
        $rootReal = realpath($root);
        $doc = defined('ROOTDIR') ? realpath(ROOTDIR) : false;
        if ($rootReal && $doc && ($rootReal === $doc || strpos($rootReal, $doc . DIRECTORY_SEPARATOR) === 0)) {
            throw new StorageException('Private storage must be outside the document root.');
        }
        if ($doc && (!$rootReal && strpos($root, rtrim(ROOTDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0)) {
            throw new StorageException('Private storage must be outside the document root.');
        }
    }

    protected function protectRoot()
    {
        @file_put_contents($this->root . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents($this->root . '/index.html', '');
    }
}
