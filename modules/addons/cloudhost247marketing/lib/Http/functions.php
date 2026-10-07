<?php
/**
 * Global display helpers (ch247m_ prefix — collides with nothing).
 * All of them escape their input; views may echo freely.
 */

if (!function_exists('ch247m_h')) {
    function ch247m_h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('ch247m_dt')) {
    function ch247m_dt($datetime)
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        return ch247m_h(substr((string) $datetime, 0, 16));
    }
}
if (!function_exists('ch247m_json')) {
    function ch247m_json($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
if (!function_exists('ch247m_num')) {
    function ch247m_num($value)
    {
        return ch247m_h(number_format((float) $value));
    }
}
if (!function_exists('ch247m_pct')) {
    /** Rate as a percentage string; '—' when the denominator is zero. */
    function ch247m_pct($numerator, $denominator, $decimals = 2)
    {
        $denominator = (float) $denominator;
        if ($denominator <= 0) {
            return '—';
        }
        return number_format(((float) $numerator / $denominator) * 100, (int) $decimals) . '%';
    }
}
if (!function_exists('ch247m_pill')) {
    function ch247m_pill($status)
    {
        $status = (string) $status;
        $map = [
            'success' => ['sent', 'sending', 'delivered', 'subscribed', 'active', 'confirmed', 'opened', 'clicked', 'completed', 'ok'],
            'info'    => ['scheduled', 'queued', 'pending', 'processing', 'draft_saved', 'unconfirmed'],
            'warning' => ['draft', 'paused', 'soft_bounced', 'deferred', 'unsubscribed', 'cancelled', 'skipped'],
            'danger'  => ['failed', 'bounced', 'hard_bounced', 'complained', 'suppressed', 'error', 'dead'],
        ];
        $class = 'default';
        foreach ($map as $candidate => $needles) {
            if (in_array(strtolower($status), $needles, true)) {
                $class = $candidate;
                break;
            }
        }
        return '<span class="label label-' . $class . '">' . ch247m_h(ucwords(str_replace('_', ' ', $status))) . '</span>';
    }
}
if (!function_exists('ch247m_pre')) {
    function ch247m_pre($value)
    {
        $json = is_string($value) ? $value : ch247m_json($value);
        return '<pre class="ch247m-pre">' . ch247m_h($json) . '</pre>';
    }
}
