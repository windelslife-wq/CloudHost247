<?php
namespace WHMCS\Module\Server\Soyoustart;

/** Admin-only, service-bound context for the legacy standalone WGS AJAX script. */
final class AjaxAuth
{
    public static function context($adminId, $method, $serviceId, $service, $product, $serverName)
    {
        if (!is_numeric($adminId) || (int) $adminId < 1 || $method !== 'POST'
            || !is_numeric($serviceId) || (int) $serviceId < 1
            || !$service || !$product || ($product->servertype ?? '') !== 'soyoustart'
            || (int) ($service->packageid ?? 0) !== (int) ($product->id ?? 0)) {
            return null;
        }
        $parts = explode('-', (string) ($product->configoption3 ?? ''));
        $accountId = trim($parts[0] ?? '');
        $location = strtolower(trim($parts[1] ?? ''));
        // Neither the API path nor the account selector may be supplied by the request.
        if (!is_string($serverName) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,199}$/D', $serverName)
            || !preg_match('/^[0-9]{1,12}$/D', $accountId)
            || !preg_match('/^[a-z]{2,30}$/D', $location)) {
            return null;
        }
        return ['server' => $serverName, 'account' => $accountId, 'location' => $location];
    }

    public static function verifyCsrf($submitted = null, $session = null)
    {
        $sess = is_array($session) ? $session : (isset($_SESSION) && is_array($_SESSION) ? $_SESSION : []);
        if ($submitted === null) {
            if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) && $_POST['csrf_token'] !== '') {
                $submitted = $_POST['csrf_token'];
            } elseif (isset($_POST['token']) && is_string($_POST['token']) && $_POST['token'] !== '') {
                $submitted = $_POST['token'];
            } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN']) && $_SERVER['HTTP_X_CSRF_TOKEN'] !== '') {
                $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'];
            }
        }
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }
        foreach (['soyoustart_csrf_token', 'tkval'] as $key) {
            if (!empty($sess[$key]) && is_string($sess[$key]) && hash_equals($sess[$key], $submitted)) {
                return true;
            }
        }
        return false;
    }
}
