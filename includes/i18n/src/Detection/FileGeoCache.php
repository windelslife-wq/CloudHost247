<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Cross-request geolocation cache, one JSON file, written atomically.
 *
 * Keys are stored hashed: the cache must never become a list of visitor IP
 * addresses on disk, because an IP is personal data under the GDPR and this
 * feature exists to pick a currency, not to build a location history.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class FileGeoCache implements GeoCacheInterface
{
    /** @var string */
    private $path;

    /** @var array<string,array<string,mixed>>|null */
    private $loaded;

    /**
     * @param string $path Writable file path.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @param string $key
     *
     * @return GeoLocation|null
     */
    public function read(string $key)
    {
        $values = $this->load();
        $hash = $this->key($key);

        if (!isset($values[$hash]) || !is_array($values[$hash])) {
            return null;
        }
        $expires = isset($values[$hash]['expires']) ? (int) $values[$hash]['expires'] : 0;
        if ($expires < time()) {
            unset($values[$hash]);
            $this->persist($values);

            return null;
        }

        return GeoLocation::fromValue(
            isset($values[$hash]['country']) ? $values[$hash]['country'] : null,
            isset($values[$hash]['source']) ? (string) $values[$hash]['source'] : 'cache',
            isset($values[$hash]['confidence']) ? (float) $values[$hash]['confidence'] : 0.7
        );
    }

    /**
     * @param string      $key
     * @param GeoLocation $location
     * @param int         $ttl
     *
     * @return void
     */
    public function write(string $key, GeoLocation $location, int $ttl): void
    {
        $values = $this->load();
        $values[$this->key($key)] = array(
            'country'    => $location->getCountry(),
            'source'     => $location->getSource(),
            'confidence' => $location->getConfidence(),
            'expires'    => time() + max(1, $ttl),
        );
        $this->persist($this->prune($values));
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->loaded = array();
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function key(string $key): string
    {
        // Salted per installation so the file cannot be reversed with a
        // rainbow table of IPv4 addresses.
        return hash('sha256', 'ch247-geo|' . $this->path . '|' . $key);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $this->loaded = array();

        if (is_file($this->path)) {
            $raw = @file_get_contents($this->path);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->loaded = $decoded;
                }
            }
        }

        return $this->loaded;
    }

    /**
     * @param array<string,array<string,mixed>> $values
     *
     * @return void
     */
    private function persist(array $values): void
    {
        $this->loaded = $values;

        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $temporary = $this->path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, json_encode($values)) === false) {
            return;
        }
        @rename($temporary, $this->path);
        @chmod($this->path, 0664);
    }

    /**
     * Drop expired entries and cap the file so it cannot grow without bound.
     *
     * @param array<string,array<string,mixed>> $values
     *
     * @return array<string,array<string,mixed>>
     */
    private function prune(array $values): array
    {
        $now = time();
        foreach ($values as $hash => $entry) {
            if (!is_array($entry) || !isset($entry['expires']) || (int) $entry['expires'] < $now) {
                unset($values[$hash]);
            }
        }
        if (count($values) > 5000) {
            $values = array_slice($values, -5000, null, true);
        }

        return $values;
    }
}
