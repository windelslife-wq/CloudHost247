<?php
/**
 * Domain Broker — presentation helpers shared by every HTML surface.
 *
 * Templates (Smarty in the client area, plain PHP strings in the admin area)
 * must never build a URL or escape a value by hand; everything goes through
 * here so escaping, the module link and the status vocabulary stay consistent.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Http;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Money;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Workflow\OfferStatus;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;
use DomainBroker\Workflow\TransferStatus;

class View
{
    const CLIENT_MODULE = 'domainbroker';

    /** Bootstrap 3 contextual class for a workflow tone. */
    const TONE_CLASSES = [
        'neutral' => 'default',
        'info' => 'info',
        'progress' => 'primary',
        'warning' => 'warning',
        'success' => 'success',
        'danger' => 'danger',
    ];

    /** @var string cached admin module link */
    protected static $adminLink = '';

    /* ----------------------------------------------------------- linking */

    public static function setAdminLink($link)
    {
        self::$adminLink = (string) $link;
    }

    public static function adminLink(array $params = [])
    {
        $base = self::$adminLink !== ''
            ? self::$adminLink
            : 'addonmodules.php?module=domainbroker';
        if (!$params) {
            return $base;
        }
        return $base . '&' . http_build_query($params);
    }

    /** A client-area URL for the module. */
    public static function url($action = 'dashboard', array $params = [])
    {
        $query = array_merge(['m' => self::CLIENT_MODULE], $params);
        if ($action !== '' && $action !== null) {
            $query['action'] = $action;
        }
        return 'index.php?' . http_build_query($query);
    }

    /** Absolute client-area URL, for emails and redirects. */
    public static function absoluteUrl($action = 'dashboard', array $params = [])
    {
        $base = rtrim(Settings::string('portal_base_url', ''), '/');
        $relative = self::url($action, $params);
        return $base === '' ? $relative : $base . '/' . $relative;
    }

    /* ----------------------------------------------------------- escaping */

    public static function e($value)
    {
        return Str::e($value);
    }

    public static function attr($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /* ---------------------------------------------------------- vocabulary */

    public static function toneClass($tone)
    {
        $tone = (string) $tone;
        return isset(self::TONE_CLASSES[$tone]) ? self::TONE_CLASSES[$tone] : 'default';
    }

    public static function statusBadge($status)
    {
        return [
            'value' => (string) $status,
            'label' => RequestStatus::label($status),
            'tone' => RequestStatus::tone($status),
            'class' => self::toneClass(RequestStatus::tone($status)),
            'description' => RequestStatus::description($status),
        ];
    }

    /** Every request status, for filter dropdowns. */
    public static function statusOptions()
    {
        $out = [];
        foreach (RequestStatus::all() as $status) {
            $out[$status] = RequestStatus::label($status);
        }
        return $out;
    }

    public static function paymentStatusOptions()
    {
        $out = [];
        foreach (PaymentStatus::all() as $status) {
            $out[$status] = PaymentStatus::label($status);
        }
        return $out;
    }

    public static function transferStatusOptions()
    {
        $out = [];
        foreach (TransferStatus::all() as $status) {
            $out[$status] = TransferStatus::label($status);
        }
        return $out;
    }

    public static function offerStatusOptions()
    {
        $out = [];
        foreach (OfferStatus::all() as $status) {
            $out[$status] = OfferStatus::label($status);
        }
        return $out;
    }

    /**
     * The customer-facing pipeline, marked up with where this request is.
     *
     * @return array<int,array{key:string,label:string,state:string}>
     */
    public static function tracker($status)
    {
        $current = RequestStatus::pipelineIndex($status);
        $terminal = RequestStatus::isTerminal($status);
        $steps = [];
        foreach (RequestStatus::PIPELINE as $index => $step) {
            if ($current < 0) {
                $state = 'pending';
            } elseif ($index < $current) {
                $state = 'done';
            } elseif ($index === $current) {
                $state = $terminal ? 'done' : 'current';
            } else {
                $state = 'pending';
            }
            $steps[] = [
                'key' => $step,
                'label' => RequestStatus::label($step),
                'state' => $state,
            ];
        }
        if ($current < 0 && $terminal) {
            // Cancelled / rejected / expired: show the pipeline greyed with a
            // terminal marker appended so the customer sees where it stopped.
            $steps[] = [
                'key' => $status,
                'label' => RequestStatus::label($status),
                'state' => 'stopped',
            ];
        }
        return $steps;
    }

    /* ------------------------------------------------------------- values */

    public static function money($minor, $currency)
    {
        return Money::format((int) $minor, (string) $currency);
    }

    /** A short, human date in the viewer's configured display format. */
    public static function date($value, $withTime = true)
    {
        if (empty($value)) {
            return '—';
        }
        $format = $withTime
            ? Settings::string('display_datetime_format', 'd M Y H:i')
            : Settings::string('display_date_format', 'd M Y');
        $timestamp = strtotime((string) $value . ' UTC');
        if ($timestamp === false) {
            return (string) $value;
        }
        return gmdate($format, $timestamp) . ($withTime ? ' UTC' : '');
    }

    /** "3 days ago" / "in 2 hours". */
    public static function relative($value)
    {
        if (empty($value)) {
            return '—';
        }
        $timestamp = strtotime((string) $value . ' UTC');
        if ($timestamp === false) {
            return (string) $value;
        }
        $delta = $timestamp - strtotime(Clock::now() . ' UTC');
        $future = $delta > 0;
        $delta = abs($delta);

        $units = [
            ['second', 60, 1],
            ['minute', 3600, 60],
            ['hour', 86400, 3600],
            ['day', 2592000, 86400],
            ['month', 31536000, 2592000],
            ['year', PHP_INT_MAX, 31536000],
        ];
        foreach ($units as $unit) {
            list($name, $ceiling, $divisor) = $unit;
            if ($delta < $ceiling) {
                $count = (int) max(1, floor($delta / $divisor));
                $label = $count . ' ' . $name . ($count === 1 ? '' : 's');
                return $future ? ('in ' . $label) : ($label . ' ago');
            }
        }
        return self::date($value);
    }

    /** Whole days until a timestamp, or null when it has passed / is unset. */
    public static function daysUntil($value)
    {
        if (empty($value)) {
            return null;
        }
        $timestamp = strtotime((string) $value . ' UTC');
        if ($timestamp === false) {
            return null;
        }
        $delta = $timestamp - strtotime(Clock::now() . ' UTC');
        return $delta <= 0 ? 0 : (int) ceil($delta / 86400);
    }

    /* ------------------------------------------------------- page framing */

    /** Client-area navigation, filtered to what this actor may see. */
    public static function clientNav(Actor $actor, $active = '')
    {
        $items = [
            ['key' => 'dashboard', 'label' => 'Overview', 'icon' => 'fa-dashboard'],
            ['key' => 'requests', 'label' => 'My Acquisitions', 'icon' => 'fa-briefcase'],
            ['key' => 'new', 'label' => 'New Request', 'icon' => 'fa-plus-circle'],
            ['key' => 'transactions', 'label' => 'Transactions', 'icon' => 'fa-credit-card'],
            ['key' => 'help', 'label' => 'Help & FAQ', 'icon' => 'fa-question-circle'],
        ];
        $nav = [];
        foreach ($items as $item) {
            $item['url'] = self::url($item['key']);
            $item['active'] = ($item['key'] === $active);
            $nav[] = $item;
        }
        return $nav;
    }

    public static function brokerNav($active = '')
    {
        $items = [
            ['key' => 'broker', 'label' => 'Broker Desk', 'icon' => 'fa-handshake-o'],
            ['key' => 'broker-queue', 'label' => 'Queue', 'icon' => 'fa-inbox'],
            ['key' => 'broker-negotiations', 'label' => 'Negotiations', 'icon' => 'fa-comments'],
            ['key' => 'broker-transfers', 'label' => 'Transfers', 'icon' => 'fa-exchange'],
            ['key' => 'broker-completed', 'label' => 'Completed', 'icon' => 'fa-check-circle'],
        ];
        $nav = [];
        foreach ($items as $item) {
            $item['url'] = self::url($item['key']);
            $item['active'] = ($item['key'] === $active);
            $nav[] = $item;
        }
        return $nav;
    }

    /**
     * Admin-area tabs, filtered by permission so a read-only administrator is
     * never shown a page that will refuse them.
     */
    public static function adminNav(Actor $actor, $active = '')
    {
        $items = [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'permission' => \DomainBroker\Core\Rbac::REQUEST_VIEW_ALL],
            ['key' => 'requests', 'label' => 'Requests', 'permission' => \DomainBroker\Core\Rbac::REQUEST_VIEW_ALL],
            ['key' => 'brokers', 'label' => 'Brokers', 'permission' => \DomainBroker\Core\Rbac::REQUEST_VIEW_ALL],
            ['key' => 'payments', 'label' => 'Payments', 'permission' => \DomainBroker\Core\Rbac::PAYMENT_VIEW],
            ['key' => 'disputes', 'label' => 'Disputes', 'permission' => \DomainBroker\Core\Rbac::REQUEST_VIEW_ALL],
            ['key' => 'risk', 'label' => 'Risk Review', 'permission' => \DomainBroker\Core\Rbac::RISK_REVIEW],
            ['key' => 'fees', 'label' => 'Fees', 'permission' => \DomainBroker\Core\Rbac::FEE_MANAGE],
            ['key' => 'reports', 'label' => 'Reports', 'permission' => \DomainBroker\Core\Rbac::REPORT_VIEW],
            ['key' => 'audit', 'label' => 'Audit Log', 'permission' => \DomainBroker\Core\Rbac::AUDIT_VIEW],
            ['key' => 'settings', 'label' => 'Settings', 'permission' => \DomainBroker\Core\Rbac::SETTINGS_MANAGE],
        ];
        $nav = [];
        foreach ($items as $item) {
            if (!\DomainBroker\Core\Rbac::allows($actor, $item['permission'])) {
                continue;
            }
            $nav[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'url' => self::adminLink(['action' => $item['key']]),
                'active' => ($item['key'] === $active),
            ];
        }
        return $nav;
    }

    /** Build a querystring-preserving pagination model. */
    public static function pagination($total, $page, $perPage, $urlCallback)
    {
        $total = (int) $total;
        $perPage = max(1, (int) $perPage);
        $pages = (int) ceil($total / $perPage);
        $page = max(1, min($page ?: 1, max(1, $pages)));

        $window = [];
        for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
            $window[] = ['number' => $i, 'url' => $urlCallback($i), 'active' => $i === $page];
        }

        return [
            'total' => $total,
            'per_page' => $perPage,
            'page' => $page,
            'pages' => $pages,
            'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'to' => min($total, $page * $perPage),
            'has_previous' => $page > 1,
            'has_next' => $page < $pages,
            'previous_url' => $page > 1 ? $urlCallback($page - 1) : null,
            'next_url' => $page < $pages ? $urlCallback($page + 1) : null,
            'window' => $window,
        ];
    }
}
