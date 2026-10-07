<?php
/**
 * The drag-and-drop component library.
 *
 * This is the single source of truth for which blocks exist, what props they
 * carry and what their defaults are. The PHP renderer and the JavaScript
 * builder both read it (the builder gets it as JSON from the admin portal), so
 * a block can never exist in the UI but not in the compiler, or vice versa.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Campaign;

class BlockLibrary
{
    public const CONTENT = 'content';
    public const LAYOUT  = 'layout';

    /** Canvas-level defaults applied when a design omits them. */
    public const DESIGN_DEFAULTS = [
        'backgroundColor' => '#f4f5f7',
        'contentWidth'    => 600,
        'fontFamily'      => "Arial, Helvetica, sans-serif",
        'textColor'       => '#333333',
        'linkColor'       => '#1a73e8',
        'fontSize'        => 15,
        'lineHeight'      => 1.6,
    ];

    public const ROW_DEFAULTS = [
        'backgroundColor' => '#ffffff',
        'paddingTop'      => 24,
        'paddingBottom'   => 24,
        'paddingLeft'     => 24,
        'paddingRight'    => 24,
        'columnGap'       => 16,
        'fullWidth'       => false,
        'stackOnMobile'   => true,
    ];

    /**
     * Layout presets offered in the builder's "Layout" tab.
     *
     * `widths` are relative weights; the renderer converts them to percentages.
     */
    public static function layouts()
    {
        return [
            'col-1'      => ['label' => '1 column',      'widths' => [1]],
            'col-2'      => ['label' => '2 columns',     'widths' => [1, 1]],
            'col-3'      => ['label' => '3 columns',     'widths' => [1, 1, 1]],
            'col-4'      => ['label' => '4 columns',     'widths' => [1, 1, 1, 1]],
            'full-width' => ['label' => 'Full-width section', 'widths' => [1], 'props' => ['fullWidth' => true, 'paddingLeft' => 0, 'paddingRight' => 0]],
            'image-text' => ['label' => 'Image + text',  'widths' => [1, 2]],
            'text-image' => ['label' => 'Text + image',  'widths' => [2, 1]],
        ];
    }

    /**
     * Content blocks.
     *
     * icon  — Font Awesome 4 class used by the builder palette.
     * props — default prop values; anything not listed is rejected on save.
     */
    public static function blocks()
    {
        return [
            'heading' => [
                'label' => 'Heading', 'icon' => 'fa-header', 'group' => self::CONTENT,
                'props' => ['text' => 'Your headline here', 'level' => 'h2', 'align' => 'left', 'color' => '', 'fontSize' => 26, 'paddingTop' => 0, 'paddingBottom' => 12],
            ],
            'text' => [
                'label' => 'Text', 'icon' => 'fa-paragraph', 'group' => self::CONTENT,
                'props' => ['html' => '<p>Write your message here. You can use merge tags such as {{first_name}}.</p>', 'align' => 'left', 'color' => '', 'fontSize' => 0, 'paddingTop' => 0, 'paddingBottom' => 12],
            ],
            'image' => [
                'label' => 'Image', 'icon' => 'fa-image', 'group' => self::CONTENT,
                'props' => ['src' => '', 'alt' => '', 'href' => '', 'width' => 0, 'align' => 'center', 'paddingTop' => 0, 'paddingBottom' => 12],
            ],
            'logo' => [
                'label' => 'Logo', 'icon' => 'fa-copyright', 'group' => self::CONTENT,
                'props' => ['src' => '', 'alt' => 'Logo', 'href' => '', 'width' => 160, 'align' => 'center', 'paddingTop' => 8, 'paddingBottom' => 16],
            ],
            'button' => [
                'label' => 'Button', 'icon' => 'fa-hand-pointer-o', 'group' => self::CONTENT,
                'props' => ['text' => 'Click here', 'href' => '', 'align' => 'center', 'backgroundColor' => '#1a73e8', 'textColor' => '#ffffff', 'radius' => 4, 'paddingX' => 28, 'paddingY' => 13, 'fontSize' => 15, 'paddingTop' => 8, 'paddingBottom' => 16, 'fullWidth' => false],
            ],
            'divider' => [
                'label' => 'Divider', 'icon' => 'fa-minus', 'group' => self::CONTENT,
                'props' => ['color' => '#e3e6ea', 'thickness' => 1, 'style' => 'solid', 'width' => 100, 'paddingTop' => 12, 'paddingBottom' => 12],
            ],
            'spacer' => [
                'label' => 'Spacer', 'icon' => 'fa-arrows-v', 'group' => self::CONTENT,
                'props' => ['height' => 24],
            ],
            'social' => [
                'label' => 'Social links', 'icon' => 'fa-share-alt', 'group' => self::CONTENT,
                'props' => ['align' => 'center', 'iconSize' => 28, 'paddingTop' => 8, 'paddingBottom' => 8, 'links' => [
                    ['network' => 'facebook', 'href' => ''],
                    ['network' => 'x', 'href' => ''],
                    ['network' => 'linkedin', 'href' => ''],
                    ['network' => 'instagram', 'href' => ''],
                ]],
            ],
            'video' => [
                'label' => 'Video', 'icon' => 'fa-play-circle', 'group' => self::CONTENT,
                'props' => ['thumbnail' => '', 'href' => '', 'alt' => 'Watch the video', 'width' => 0, 'align' => 'center', 'paddingTop' => 0, 'paddingBottom' => 12],
            ],
            'html' => [
                'label' => 'Custom HTML', 'icon' => 'fa-code', 'group' => self::CONTENT,
                'props' => ['html' => '<!-- your HTML -->'],
            ],
            'product' => [
                'label' => 'Product / service card', 'icon' => 'fa-cube', 'group' => self::CONTENT,
                'props' => ['image' => '', 'title' => 'Cloud Starter Hosting', 'body' => 'NVMe storage, free SSL and daily backups.', 'price' => '', 'buttonText' => 'Order now', 'href' => '', 'borderColor' => '#e3e6ea', 'paddingTop' => 0, 'paddingBottom' => 16],
            ],
            'coupon' => [
                'label' => 'Coupon', 'icon' => 'fa-ticket', 'group' => self::CONTENT,
                'props' => ['code' => 'SAVE20', 'headline' => 'Use this code at checkout', 'subtext' => 'Expires soon', 'backgroundColor' => '#fff8e1', 'borderColor' => '#f0b429', 'textColor' => '#8a5a00', 'paddingTop' => 8, 'paddingBottom' => 16],
            ],
            'pricing' => [
                'label' => 'Pricing block', 'icon' => 'fa-tags', 'group' => self::CONTENT,
                'props' => ['plan' => 'Business VPS', 'price' => '$24', 'period' => '/month', 'features' => "4 vCPU\n8 GB RAM\n200 GB NVMe", 'buttonText' => 'Choose plan', 'href' => '', 'accent' => '#1a73e8', 'paddingTop' => 0, 'paddingBottom' => 16],
            ],
            'footer' => [
                'label' => 'Footer', 'icon' => 'fa-align-center', 'group' => self::CONTENT,
                'props' => ['companyName' => '', 'address' => '', 'showUnsubscribe' => true, 'extraHtml' => '', 'fontSize' => 12, 'color' => '#8a9099', 'align' => 'center', 'paddingTop' => 16, 'paddingBottom' => 16],
            ],
        ];
    }

    public static function has($type)
    {
        return array_key_exists((string) $type, self::blocks());
    }

    public static function defaults($type)
    {
        $blocks = self::blocks();
        return isset($blocks[$type]) ? $blocks[$type]['props'] : [];
    }

    /**
     * Normalise a block coming from the builder: unknown types are dropped by
     * the caller, unknown props are discarded, missing props are defaulted.
     */
    public static function normalizeBlock(array $block)
    {
        $type = isset($block['type']) ? (string) $block['type'] : '';
        if (!self::has($type)) {
            return null;
        }
        $defaults = self::defaults($type);
        $given = isset($block['props']) && is_array($block['props']) ? $block['props'] : [];
        $props = [];
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $given)) {
                $props[$key] = $default;
                continue;
            }
            $value = $given[$key];
            if (is_bool($default)) {
                $props[$key] = (bool) $value;
            } elseif (is_int($default)) {
                $props[$key] = (int) $value;
            } elseif (is_float($default)) {
                $props[$key] = (float) $value;
            } elseif (is_array($default)) {
                $props[$key] = is_array($value) ? $value : $default;
            } else {
                $props[$key] = is_scalar($value) ? (string) $value : $default;
            }
        }
        return [
            'id'    => isset($block['id']) && is_string($block['id']) ? substr($block['id'], 0, 32) : uniqid('b', false),
            'type'  => $type,
            'props' => $props,
        ];
    }

    /** Payload handed to the JS builder. */
    public static function manifest()
    {
        return [
            'blocks'         => self::blocks(),
            'layouts'        => self::layouts(),
            'designDefaults' => self::DESIGN_DEFAULTS,
            'rowDefaults'    => self::ROW_DEFAULTS,
        ];
    }
}
