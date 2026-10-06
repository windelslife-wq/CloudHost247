<?php
namespace DigitalProducts\Core;

/**
 * Capability gate for addon actions. WHMCS addon access control remains the
 * primary role mapping; this guard makes every handler fail closed outside an
 * authenticated admin request and gives deployments a single extension point.
 */
class Rbac
{
    const PRODUCTS_VIEW = 'products.view';
    const PRODUCTS_MANAGE = 'products.manage';
    const FILES_UPLOAD = 'files.upload';
    const FILES_DELETE = 'files.delete';
    const VERSIONS_MANAGE = 'versions.manage';
    const LICENSES_MANAGE = 'licenses.manage';
    const ENTITLEMENTS_MANAGE = 'entitlements.manage';
    const DOWNLOADS_VIEW = 'downloads.view';
    const SETTINGS_MANAGE = 'settings.manage';
    const AUDIT_VIEW = 'audit.view';

    public static function isAdmin()
    {
        return (int) ($_SESSION['adminid'] ?? 0) > 0 || (defined('ADMINAREA') && ADMINAREA);
    }

    public static function authorize($capability)
    {
        if (!self::isAdmin()) throw new AuthorizationException('Administrator authorization required.');
        // WHMCS enforces addon access roles before rendering the module. A
        // deployment can provide a stricter capability callback without forking.
        if (function_exists('digitalproducts_capability_check') && !digitalproducts_capability_check($capability)) {
            throw new AuthorizationException('You are not authorized for this action.');
        }
        return true;
    }
}
