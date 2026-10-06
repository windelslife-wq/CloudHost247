<?php
/**
 * Global display helpers (chs_ prefix — never collides with WHMCS or any
 * other module). All of them escape their input; templates may echo freely.
 */

if (!function_exists('chs_h')) {
    /** HTML-escape (UTF-8, quotes double-encoded safely). */
    function chs_h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('chs_u')) {
    /** URL path/query escape. */
    function chs_u($value)
    {
        return rawurlencode((string) $value);
    }
}

if (!function_exists('chs_urlencode')) {
    /** Alias kept for template ergonomics. */
    function chs_urlencode($value)
    {
        return rawurlencode((string) $value);
    }
}

if (!function_exists('chs_json')) {
    /** Compact JSON for data attributes / script islands. */
    function chs_json($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('chs_money')) {
    /** Minor-cents -> formatted currency string, escaped for HTML. */
    function chs_money($minor, $currency = 'USD')
    {
        return chs_h(\Chs\Core\Money::format((int) $minor, (string) $currency));
    }
}

if (!function_exists('chs_dt')) {
    /** Datetime display ('Y-m-d H:i'), em-dash for blanks. */
    function chs_dt($datetime)
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        return chs_h(substr((string) $datetime, 0, 16));
    }
}

if (!function_exists('chs_status_pill')) {
    /** Colourized status badge; unknown statuses fall back to neutral grey. */
    function chs_status_pill($status)
    {
        $status = (string) $status;
        $map = [
            'success' => ['active', 'paid', 'completed', 'accepted', 'sold', 'live', 'met',
                'invoiced', 'closed_sold', 'answer', 'ok'],
            'info'    => ['open', 'new', 'requested', 'answered', 'browsing', 'watching'],
            'warning' => ['pending', 'quoted', 'reviewing', 'in_progress', 'pending_payment',
                'on hold', 'on-hold', 'customer-reply', 'draft', 'reserve_pending'],
            'danger'  => ['lapsed', 'cancelled', 'canceled', 'declined', 'failed', 'expired',
                'unsold', 'closed_unsold', 'error', 'lost'],
        ];
        $class = 'default';
        foreach ($map as $candidate => $needles) {
            if (in_array(strtolower($status), $needles, true)) {
                $class = $candidate;
                break;
            }
        }
        return '<span class="label label-' . $class . ' chs-pill">' . chs_h(ucwords(str_replace(['_', '-'], ' ', $status))) . '</span>';
    }
}
