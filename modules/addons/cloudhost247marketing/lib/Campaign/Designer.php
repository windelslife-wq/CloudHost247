<?php
/**
 * Normalises and validates design JSON coming from the builder.
 *
 * Nothing from the browser is trusted: unknown block types are dropped,
 * unknown props are discarded, column counts are clamped, and the whole
 * structure is rebuilt from scratch rather than merged. Whatever leaves this
 * class is guaranteed to be renderable.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Campaign;

use Ch247Mkt\Core\ValidationException;

class Designer
{
    public const MAX_ROWS = 100;
    public const MAX_COLUMNS = 4;
    public const MAX_BLOCKS_PER_CELL = 50;

    /** An empty but valid design. */
    public static function blank()
    {
        return [
            'settings' => BlockLibrary::DESIGN_DEFAULTS,
            'rows'     => [],
        ];
    }

    /** Decode a stored design column; never throws, falls back to blank. */
    public static function decode($json)
    {
        if (is_array($json)) {
            return self::normalize($json);
        }
        $json = trim((string) $json);
        if ($json === '') {
            return self::blank();
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return self::blank();
        }
        return self::normalize($data);
    }

    public static function encode(array $design)
    {
        $json = json_encode(self::normalize($design), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{"settings":{},"rows":[]}' : $json;
    }

    /** @throws ValidationException when the payload is structurally impossible */
    public static function normalize(array $design)
    {
        $settings = [];
        $given = isset($design['settings']) && is_array($design['settings']) ? $design['settings'] : [];
        foreach (BlockLibrary::DESIGN_DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $given)) {
                $settings[$key] = $default;
                continue;
            }
            $value = $given[$key];
            if (is_int($default)) {
                $settings[$key] = (int) $value;
            } elseif (is_float($default)) {
                $settings[$key] = (float) $value;
            } else {
                $settings[$key] = is_scalar($value) ? (string) $value : $default;
            }
        }
        $settings['contentWidth'] = max(320, min(900, (int) $settings['contentWidth']));
        $settings['fontSize'] = max(10, min(32, (int) $settings['fontSize']));

        $rowsIn = isset($design['rows']) && is_array($design['rows']) ? array_values($design['rows']) : [];
        if (count($rowsIn) > self::MAX_ROWS) {
            throw new ValidationException('This design has too many sections (limit ' . self::MAX_ROWS . ').');
        }

        $rows = [];
        foreach ($rowsIn as $rowIn) {
            if (!is_array($rowIn)) {
                continue;
            }
            $cellsIn = isset($rowIn['cells']) && is_array($rowIn['cells']) ? array_values($rowIn['cells']) : [[]];
            $columns = max(1, min(self::MAX_COLUMNS, count($cellsIn)));
            $cellsIn = array_slice($cellsIn, 0, $columns);

            $cells = [];
            foreach ($cellsIn as $cellIn) {
                $blocks = [];
                foreach ((array) $cellIn as $blockIn) {
                    if (!is_array($blockIn)) {
                        continue;
                    }
                    $block = BlockLibrary::normalizeBlock($blockIn);
                    if ($block !== null) {
                        $blocks[] = $block;
                    }
                    if (count($blocks) >= self::MAX_BLOCKS_PER_CELL) {
                        break;
                    }
                }
                $cells[] = $blocks;
            }

            $props = [];
            $givenProps = isset($rowIn['props']) && is_array($rowIn['props']) ? $rowIn['props'] : [];
            foreach (BlockLibrary::ROW_DEFAULTS as $key => $default) {
                if (!array_key_exists($key, $givenProps)) {
                    $props[$key] = $default;
                    continue;
                }
                $value = $givenProps[$key];
                if (is_bool($default)) {
                    $props[$key] = (bool) $value;
                } elseif (is_int($default)) {
                    $props[$key] = (int) $value;
                } else {
                    $props[$key] = is_scalar($value) ? (string) $value : $default;
                }
            }

            $widths = isset($rowIn['widths']) && is_array($rowIn['widths']) ? array_values($rowIn['widths']) : [];
            $widths = array_map(function ($w) {
                $w = (float) $w;
                return $w > 0 ? $w : 1.0;
            }, array_slice($widths, 0, $columns));
            while (count($widths) < $columns) {
                $widths[] = 1.0;
            }

            $rows[] = [
                'id'      => isset($rowIn['id']) && is_string($rowIn['id']) ? substr($rowIn['id'], 0, 32) : uniqid('r', false),
                'layout'  => isset($rowIn['layout']) && is_string($rowIn['layout']) ? substr($rowIn['layout'], 0, 32) : 'col-' . $columns,
                'widths'  => $widths,
                'props'   => $props,
                'cells'   => $cells,
            ];
        }

        return ['settings' => $settings, 'rows' => $rows];
    }

    /** Build a design from a layout preset + blocks, used by the seeded templates. */
    public static function row($layout, array $cells, array $props = [])
    {
        $layouts = BlockLibrary::layouts();
        $preset = isset($layouts[$layout]) ? $layouts[$layout] : $layouts['col-1'];
        $presetProps = isset($preset['props']) ? $preset['props'] : [];
        return [
            'id'     => uniqid('r', false),
            'layout' => $layout,
            'widths' => $preset['widths'],
            'props'  => array_merge(BlockLibrary::ROW_DEFAULTS, $presetProps, $props),
            'cells'  => $cells,
        ];
    }

    public static function block($type, array $props = [])
    {
        return BlockLibrary::normalizeBlock(['type' => $type, 'props' => $props]);
    }

    /** Every distinct http(s) link in a design — the click-tracking source list. */
    public static function collectLinks(array $design)
    {
        $urls = [];
        $walk = function ($value) use (&$walk, &$urls) {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    if (is_string($item) && in_array($key, ['href', 'src', 'thumbnail', 'image'], true)) {
                        if (preg_match('#^https?://#i', trim($item)) === 1) {
                            $urls[] = trim($item);
                        }
                        continue;
                    }
                    $walk($item);
                }
                return;
            }
            if (is_string($value) && strpos($value, 'href') !== false) {
                if (preg_match_all('/href\s*=\s*["\'](https?:\/\/[^"\']+)["\']/i', $value, $m) > 0) {
                    foreach ($m[1] as $url) {
                        $urls[] = $url;
                    }
                }
            }
        };
        $walk($design);
        return array_values(array_unique($urls));
    }
}
