<?php
/**
 * Global display helpers (ch247ai_ prefix — collides with nothing).
 * All of them escape their input; templates may echo freely.
 */

if (!function_exists('ch247ai_h')) {
    function ch247ai_h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('ch247ai_dt')) {
    function ch247ai_dt($datetime)
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        return ch247ai_h(substr((string) $datetime, 0, 16));
    }
}
if (!function_exists('ch247ai_json')) {
    function ch247ai_json($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
if (!function_exists('ch247ai_pill')) {
    function ch247ai_pill($status)
    {
        $status = (string) $status;
        $map = [
            'success' => ['active', 'completed', 'approved', 'executed', 'paid', 'done', 'indexed', 'valid', 'narrated', 'ok'],
            'info' => ['pending', 'running', 'awaiting_approval', 'metrics_only', 'processed'],
            'warning' => ['draft', 'deferred', 'stale', 'skipped', 'modified'],
            'danger' => ['failed', 'rejected', 'expired', 'error', 'refused', 'dead', 'killed'],
        ];
        $class = 'default';
        foreach ($map as $candidate => $needles) {
            if (in_array(strtolower($status), $needles, true)) {
                $class = $candidate;
                break;
            }
        }
        return '<span class="label label-' . $class . '">' . ch247ai_h(ucwords(str_replace('_', ' ', $status))) . '</span>';
    }
}
if (!function_exists('ch247ai_pre')) {
    /** Escaped <pre> for JSON payloads. */
    function ch247ai_pre($value)
    {
        $json = is_string($value) ? $value : ch247ai_json($value);
        return '<pre class="ch247ai-pre">' . ch247ai_h($json) . '</pre>';
    }
}
