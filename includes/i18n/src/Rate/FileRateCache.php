<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * File-backed rate cache. Shares the table across requests without depending
 * on a WHMCS bootstrap, which keeps the same caching policy working in the
 * crons that run from the WHMCS root.
 *
 * Storage is a single JSON file written atomically (temp file + rename) so a
 * concurrent request never reads a half-written table.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Rate;

use CloudHost247\I18n\Contracts\RateCacheInterface;
use CloudHost247\I18n\RateTable;

final class FileRateCache implements RateCacheInterface
{
    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function read()
    {
        if (!is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['base'], $data['rates']) || !is_array($data['rates'])) {
            return null;
        }

        return new RateTable(
            (string) $data['base'],
            $data['rates'],
            isset($data['source']) ? (string) $data['source'] : 'unknown',
            isset($data['as_of']) ? $data['as_of'] : null,
            isset($data['retrieved_at']) ? (int) $data['retrieved_at'] : null
        );
    }

    public function write(RateTable $table)
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode($table->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        $tmp = $this->path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload) === false) {
            return;
        }
        @rename($tmp, $this->path);
        @chmod($this->path, 0664);
    }

    public function clear()
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
