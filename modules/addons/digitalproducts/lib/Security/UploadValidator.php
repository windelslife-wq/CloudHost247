<?php
namespace DigitalProducts\Security;

use DigitalProducts\Core\Settings;
use DigitalProducts\Core\ValidationException;

class UploadValidator
{
    public function validate(array $file)
    {
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) throw new ValidationException('File upload failed.');
        $name = (string) ($file['name'] ?? '');
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        // Both separators count: a backslash is a directory separator on the
        // Windows servers WHMCS is often deployed on, and the original name is
        // later used as a download filename.
        if ($name === '' || strpos($name, "\0") !== false || preg_match('#(^|[/\\\\])\.\.?([/\\\\]|$)#', $name)) throw new ValidationException('Invalid filename.');
        if (pathinfo($name, PATHINFO_FILENAME) === '') throw new ValidationException('Invalid filename.');
        if (!is_uploaded_file($tmp) && !defined('DIGITALPRODUCTS_TESTING')) throw new ValidationException('Invalid upload source.');
        if (!is_file($tmp) || $size < 1 || $size > Settings::int('max_upload_size', 524288000)) throw new ValidationException('File is empty or exceeds the configured size limit.');
        $lower = strtolower($name);
        $extension = $this->extension($lower);
        if (!in_array($extension, Settings::extensions(), true)) throw new ValidationException('This file type is not allowed.');
        $this->validateMime($tmp, $extension);
        if (in_array($extension, ['zip', 'tar.gz'], true)) $this->validateArchive($tmp, $extension, $size);
        return ['original_name' => basename($name), 'extension' => $extension, 'size' => $size, 'sha256' => hash_file('sha256', $tmp), 'tmp_name' => $tmp];
    }

    protected function extension($name)
    {
        if (substr($name, -7) === '.tar.gz') return 'tar.gz';
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    protected function validateMime($path, $extension)
    {
        if (!function_exists('finfo_open')) return;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = [
            'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
            'tar.gz' => ['application/gzip', 'application/x-gzip', 'application/octet-stream'],
            'pdf' => ['application/pdf'], 'json' => ['application/json', 'text/plain'],
            'js' => ['application/javascript', 'text/javascript', 'text/plain'], 'css' => ['text/css', 'text/plain'],
            'xml' => ['application/xml', 'text/xml', 'text/plain'], 'txt' => ['text/plain', 'application/octet-stream'],
            'md' => ['text/plain', 'text/markdown', 'application/octet-stream'],
            'php' => ['text/plain', 'text/x-php', 'application/x-php', 'application/octet-stream'],
        ];
        if (isset($allowed[$extension]) && !in_array($mime, $allowed[$extension], true)) throw new ValidationException('File content does not match its extension.');
    }

    protected function validateArchive($path, $extension, $size)
    {
        if ($extension === 'tar.gz') {
            if (!class_exists('PharData')) return;
            try {
                $archive = new \PharData($path);
                $count = 0;
                foreach (new \RecursiveIteratorIterator($archive) as $entry) {
                    if (++$count > 10000 || strpos(str_replace('\\', '/', $entry->getPathName()), '../') !== false) throw new ValidationException('Archive contains unsafe entries.');
                }
            } catch (ValidationException $e) { throw $e; } catch (\Throwable $e) { throw new ValidationException('Unable to inspect archive.'); }
            return;
        }
        if (!class_exists('ZipArchive')) return;
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new ValidationException('Unable to inspect ZIP archive.');
        if ($zip->numFiles > 10000) { $zip->close(); throw new ValidationException('Archive contains too many entries.'); }
        $uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entry = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($entry === '' || $entry[0] === '/' || preg_match('#(^|/)\.\.?(/|$)#', $entry) || preg_match('/^[A-Za-z]:\//', $entry)) { $zip->close(); throw new ValidationException('Archive contains an unsafe path.'); }
            // Unix mode 0120000 denotes a symlink.
            if (isset($stat['external_attributes']) && (($stat['external_attributes'] >> 16) & 0170000) === 0120000) { $zip->close(); throw new ValidationException('Archive symlinks are not allowed.'); }
            $uncompressed += (int) ($stat['size'] ?? 0);
            if ($uncompressed > max($size * 100, 1048576000)) { $zip->close(); throw new ValidationException('Archive compression ratio is unsafe.'); }
        }
        $zip->close();
    }
}
