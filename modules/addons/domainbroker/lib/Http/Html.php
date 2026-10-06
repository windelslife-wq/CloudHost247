<?php
/**
 * Domain Broker — markup builders for the WHMCS admin area.
 *
 * The admin area is rendered as HTML strings (that is the addon module
 * contract), so every value passes through an escaping helper here. There is
 * deliberately no "raw" variant: if a caller needs markup it composes it from
 * these builders.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Http;

use DomainBroker\Core\Csrf;
use DomainBroker\Core\Str;

class Html
{
    public static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function tag($name, $content = '', array $attributes = [])
    {
        return '<' . $name . self::attributes($attributes) . '>' . $content . '</' . $name . '>';
    }

    public static function attributes(array $attributes)
    {
        $out = '';
        foreach ($attributes as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $out .= ' ' . $key;
                continue;
            }
            $out .= ' ' . $key . '="' . self::e($value) . '"';
        }
        return $out;
    }

    public static function link($label, $url, $class = '', array $attributes = [])
    {
        return self::tag('a', self::e($label), array_merge(['href' => $url, 'class' => $class], $attributes));
    }

    public static function badge($label, $tone = 'default')
    {
        return '<span class="label label-' . self::e($tone) . '">' . self::e($label) . '</span>';
    }

    public static function panel($title, $body, $class = 'default', $footer = '')
    {
        return '<div class="panel panel-' . self::e($class) . '">'
            . ($title === '' ? '' : '<div class="panel-heading"><h3 class="panel-title">' . self::e($title) . '</h3></div>')
            . '<div class="panel-body">' . $body . '</div>'
            . ($footer === '' ? '' : '<div class="panel-footer">' . $footer . '</div>')
            . '</div>';
    }

    public static function alert($message, $type = 'info')
    {
        return '<div class="alert alert-' . self::e($type) . '">' . self::e($message) . '</div>';
    }

    /** A statistic tile for the dashboard. */
    public static function stat($label, $value, $tone = 'primary', $url = null)
    {
        $inner = '<div class="db-stat-value">' . self::e($value) . '</div>'
            . '<div class="db-stat-label">' . self::e($label) . '</div>';
        $body = '<div class="panel panel-' . self::e($tone) . ' db-stat"><div class="panel-body">' . $inner . '</div></div>';
        return $url ? '<a href="' . self::e($url) . '" class="db-stat-link">' . $body . '</a>' : $body;
    }

    /**
     * A data table.
     *
     * @param array<int,string> $headers
     * @param array<int,array<int,string>> $rows already-escaped cell markup
     */
    public static function table(array $headers, array $rows, $emptyMessage = 'Nothing to show.')
    {
        if (!$rows) {
            return self::alert($emptyMessage, 'info');
        }
        $out = '<div class="table-responsive"><table class="table table-striped table-hover db-table"><thead><tr>';
        foreach ($headers as $header) {
            $out .= '<th>' . self::e($header) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $out .= '<tr>';
            foreach ($row as $cell) {
                $out .= '<td>' . $cell . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table></div>';
    }

    /** A definition list of label => already-escaped value. */
    public static function details(array $pairs)
    {
        $out = '<dl class="dl-horizontal db-details">';
        foreach ($pairs as $label => $value) {
            $out .= '<dt>' . self::e($label) . '</dt><dd>' . $value . '</dd>';
        }
        return $out . '</dl>';
    }

    /* -------------------------------------------------------------- forms */

    public static function formOpen($url, array $attributes = [])
    {
        $attributes = array_merge([
            'method' => 'post',
            'action' => $url,
            'class' => 'form-horizontal db-form',
        ], $attributes);
        return '<form' . self::attributes($attributes) . '>' . Csrf::field();
    }

    public static function formClose()
    {
        return '</form>';
    }

    public static function hidden($name, $value)
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    public static function input($name, $value = '', array $attributes = [])
    {
        $attributes = array_merge([
            'type' => 'text',
            'name' => $name,
            'id' => 'db-' . $name,
            'value' => $value,
            'class' => 'form-control',
        ], $attributes);
        return '<input' . self::attributes($attributes) . '>';
    }

    public static function textarea($name, $value = '', array $attributes = [])
    {
        $attributes = array_merge([
            'name' => $name,
            'id' => 'db-' . $name,
            'rows' => 4,
            'class' => 'form-control',
        ], $attributes);
        return '<textarea' . self::attributes($attributes) . '>' . self::e($value) . '</textarea>';
    }

    public static function select($name, array $options, $selected = '', array $attributes = [])
    {
        $attributes = array_merge([
            'name' => $name,
            'id' => 'db-' . $name,
            'class' => 'form-control',
        ], $attributes);
        $out = '<select' . self::attributes($attributes) . '>';
        foreach ($options as $value => $label) {
            $out .= '<option value="' . self::e($value) . '"'
                . ((string) $value === (string) $selected ? ' selected' : '') . '>'
                . self::e($label) . '</option>';
        }
        return $out . '</select>';
    }

    public static function checkbox($name, $checked = false, $label = '')
    {
        return '<div class="checkbox"><label><input type="checkbox" name="' . self::e($name) . '" value="1"'
            . ($checked ? ' checked' : '') . '> ' . self::e($label) . '</label></div>';
    }

    /** A labelled control in a Bootstrap horizontal form. */
    public static function field($label, $control, $help = '')
    {
        return '<div class="form-group"><label class="col-sm-3 control-label">' . self::e($label) . '</label>'
            . '<div class="col-sm-9">' . $control
            . ($help === '' ? '' : '<p class="help-block">' . self::e($help) . '</p>')
            . '</div></div>';
    }

    public static function submit($label, $class = 'btn btn-primary', array $attributes = [])
    {
        return '<button type="submit"' . self::attributes(array_merge(['class' => $class], $attributes)) . '>'
            . self::e($label) . '</button>';
    }

    /** A compact inline form — used for the one-click row actions. */
    public static function inlineForm($url, array $fields, $buttonLabel, $buttonClass = 'btn btn-xs btn-default', $confirm = '')
    {
        $out = '<form method="post" action="' . self::e($url) . '" class="db-inline-form"'
            . ($confirm !== '' ? ' onsubmit="return confirm(\'' . self::e($confirm) . '\');"' : '') . '>'
            . Csrf::field();
        foreach ($fields as $name => $value) {
            $out .= self::hidden($name, $value);
        }
        return $out . self::submit($buttonLabel, $buttonClass) . '</form>';
    }

    /* --------------------------------------------------------- navigation */

    public static function tabs(array $items)
    {
        $out = '<ul class="nav nav-tabs db-tabs">';
        foreach ($items as $item) {
            $out .= '<li' . (!empty($item['active']) ? ' class="active"' : '') . '>'
                . '<a href="' . self::e($item['url']) . '">' . self::e($item['label']) . '</a></li>';
        }
        return $out . '</ul>';
    }

    public static function pager(array $pagination)
    {
        if ($pagination['pages'] <= 1) {
            return '';
        }
        $out = '<nav><ul class="pagination pagination-sm">';
        $out .= '<li' . ($pagination['has_previous'] ? '' : ' class="disabled"') . '>'
            . '<a href="' . self::e($pagination['previous_url'] ?: '#') . '">&laquo;</a></li>';
        foreach ($pagination['window'] as $page) {
            $out .= '<li' . ($page['active'] ? ' class="active"' : '') . '>'
                . '<a href="' . self::e($page['url']) . '">' . (int) $page['number'] . '</a></li>';
        }
        $out .= '<li' . ($pagination['has_next'] ? '' : ' class="disabled"') . '>'
            . '<a href="' . self::e($pagination['next_url'] ?: '#') . '">&raquo;</a></li>';
        return $out . '</ul></nav>';
    }

    public static function heading($title, $subtitle = '', $actions = '')
    {
        return '<div class="db-page-head"><div class="db-page-head-text">'
            . '<h2>' . self::e($title) . '</h2>'
            . ($subtitle === '' ? '' : '<p class="text-muted">' . self::e($subtitle) . '</p>')
            . '</div><div class="db-page-head-actions">' . $actions . '</div></div>';
    }

    public static function label($value)
    {
        return self::e(Str::label($value));
    }
}
