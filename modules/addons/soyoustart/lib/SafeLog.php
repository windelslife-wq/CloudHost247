<?php
/** Metadata-only WHMCS module logging for legacy OVH operations. */
namespace WHMCS\Module\Addon\Soyoustart;

class SafeLog
{
    public static function failure($module, $action, array $params, \Throwable $error)
    {
        logModuleCall($module, $action, self::scope($params),
            ['status' => 'failed', 'exception_type' => get_class($error)]);
    }

    public static function orderResult($module, array $params, array $result)
    {
        $status = isset($result['result']) && $result['result'] === 'success' ? 'success' : 'failed';
        logModuleCall($module, 'AddOrder', self::scope($params), ['status' => $status]);
    }

    private static function scope(array $params)
    {
        $id = isset($params['serviceid']) ? $params['serviceid'] : null;
        return ['serviceid' => (is_int($id) || (is_string($id) && ctype_digit($id)))
            ? (int) $id : 0];
    }
}
