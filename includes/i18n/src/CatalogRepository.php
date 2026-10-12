<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Loads and caches the on-disk translation catalogs (`lang/site/<locale>.php`).
 *
 * Adding a language is one file drop: put a catalog in `lang/site/`, and the
 * registry, switcher, completeness gate and tests pick it up automatically.
 * No page, template or PHP file changes.
 *
 * A catalog file returns:
 *
 *     <?php
 *     return [
 *         'meta'     => ['locale' => 'fr', 'english_name' => 'French', ...],
 *         'messages' => ['nav_home' => 'Accueil', 'cart_item_count' => [...]],
 *     ];
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class CatalogRepository
{
    /** @var string */
    private $directory;

    /** @var array<string,array{meta:array,messages:array}> */
    private $loaded = array();

    /** @var array<string,bool> */
    private $missing = array();

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Locale codes that have a catalog on disk, sorted.
     *
     * @return string[]
     */
    public function availableLocales(): array
    {
        $found = array();
        foreach ($this->candidateFiles() as $file) {
            $locale = Locale::normalise(basename($file, '.php'));
            if ($locale !== '') {
                $found[$locale] = true;
            }
        }
        $codes = array_keys($found);
        sort($codes, SORT_STRING);

        return $codes;
    }

    /**
     * @param string $locale
     *
     * @return bool
     */
    public function has(string $locale): bool
    {
        $locale = Locale::normalise($locale);
        if (!preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{2,8})*$/D', $locale)) {
            return false;
        }
        if ($locale === '') {
            return false;
        }
        if (isset($this->loaded[$locale]) || isset($this->missing[$locale])) {
            return isset($this->loaded[$locale]);
        }

        return is_file($this->pathFor($locale));
    }

    /**
     * Full catalog for a locale. Missing files yield an empty catalog rather
     * than an exception: a half-installed language must not blank the site.
     *
     * @param string $locale
     *
     * @return array{meta:array,messages:array}
     */
    public function load(string $locale): array
    {
        $locale = Locale::normalise($locale);
        if (!preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{2,8})*$/D', $locale)) {
            return array('meta' => array(), 'messages' => array());
        }
        if ($locale === '') {
            return array('meta' => array(), 'messages' => array());
        }
        if (isset($this->loaded[$locale])) {
            return $this->loaded[$locale];
        }

        $path = $this->pathFor($locale);
        if (!is_file($path)) {
            $this->missing[$locale] = true;

            return array('meta' => array(), 'messages' => array());
        }

        /** @noinspection PhpIncludeInspection */
        $data = require $path;
        if (!is_array($data)) {
            $data = array();
        }

        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : array();
        $messages = isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : array();

        // Page-specific plain text belongs beside its locale's catalog, not
        // in a monolithic file or an inline PHP translation switch.
        // Only locales with metadata in <locale>.php are discovered.
        $fragments = glob($this->directory . '/' . $locale . '/*.php') ?: array();
        sort($fragments);
        foreach ($fragments as $fragment) {
            $values = require $fragment;
            if (is_array($values)) {
                $messages = array_merge($messages, $values);
            }
        }


        $this->loaded[$locale] = array('meta' => $meta, 'messages' => $messages);

        return $this->loaded[$locale];
    }

    /**
     * @param string $locale
     *
     * @return array
     */
    public function meta(string $locale): array
    {
        $catalog = $this->load($locale);

        return $catalog['meta'];
    }

    /**
     * @param string $locale
     *
     * @return array
     */
    public function messages(string $locale): array
    {
        $catalog = $this->load($locale);

        return $catalog['messages'];
    }

    /**
     * Forget everything (used by tests that write temporary catalogs).
     *
     * @return void
     */
    public function reset(): void
    {
        $this->loaded = array();
        $this->missing = array();
    }

    /**
     * @param string $locale
     *
     * @return string
     */
    private function pathFor(string $locale): string
    {
        // Catalogs may be flat (`fr.php`) or region-suffixed (`pt-BR.php`);
        // try the canonical code, then a lower-case variant.
        $candidates = array(
            $this->directory . DIRECTORY_SEPARATOR . $locale . '.php',
            $this->directory . DIRECTORY_SEPARATOR . strtolower($locale) . '.php',
        );

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * @return string[]
     */
    private function candidateFiles(): array
    {
        if (!is_dir($this->directory)) {
            return array();
        }
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.php');

        return $files === false ? array() : $files;
    }
}
