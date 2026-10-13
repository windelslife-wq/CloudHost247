<?php
/**
 * SMM Panel Integration - Helper Class
 */

namespace SmmAddon;

use Illuminate\Database\Capsule\Manager as Capsule;
use Exception;

class Helper
{
    const ENCRYPT_PREFIX = 'enc:v1:';

    /**
     * Encrypt a secret for storage (finding M-7). Outside a WHMCS context
     * (offline tests) the value is returned unchanged because encrypt() is
     * unavailable.
     */
    public static function encryptSecret($value): string
    {
        $value = (string) $value;
        if ($value === '' || !function_exists('encrypt')) {
            return $value;
        }
        return self::ENCRYPT_PREFIX . base64_encode((string) encrypt($value));
    }

    /**
     * Decrypt a stored secret if it carries the enc:v1: prefix. Legacy
     * plaintext values (or failed decodes) are returned as stored.
     */
    public static function decryptValue($stored): string
    {
        $stored = (string) $stored;
        if (strpos($stored, self::ENCRYPT_PREFIX) === 0 && function_exists('decrypt')) {
            $plain = decrypt(base64_decode(substr($stored, strlen(self::ENCRYPT_PREFIX))));
            if (is_string($plain) && $plain !== '') {
                return $plain;
            }
        }
        return $stored;
    }

    /**
     * Get module configuration (provider keys are decrypted transparently)
     */
    public static function getConfig($key = null, $default = null)
    {
        try {
            $settings = Capsule::table('mod_smm_config')
                ->pluck('value', 'setting')
                ->toArray();

            if ($key) {
                if (!isset($settings[$key])) {
                    return $default;
                }
                if ($key === 'api_key') {
                    return self::decryptValue($settings[$key]);
                }
                return $settings[$key];
            }
            return $settings;
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * Set module configuration (provider keys are encrypted at rest)
     */
    public static function setConfig($key, $value)
    {
        try {
            $stored = $key === 'api_key' ? self::encryptSecret($value) : $value;
            Capsule::table('mod_smm_config')
                ->updateOrInsert(
                    ['setting' => $key],
                    ['value' => $stored, 'updated_at' => date('Y-m-d H:i:s')]
                );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Log API request/response
     */
    public static function logApi($action, $endpoint, $request, $response, $httpCode = null, $error = null)
    {
        try {
            Capsule::table('mod_smm_logs')->insert([
                'action'    => substr($action, 0, 255),
                'endpoint'  => substr($endpoint, 0, 500),
                'request'   => is_string($request) ? $request : json_encode($request),
                'response'  => is_string($response) ? $response : json_encode($response),
                'http_code' => $httpCode,
                'error'     => $error,
                'created_at'=> date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            // Silently fail logging to prevent crashes
        }
    }

    /**
     * Sanitize input
     */
    public static function sanitize($input, $type = 'string')
    {
        if (is_array($input)) {
            $clean = [];
            foreach ($input as $key => $val) {
                $clean[$key] = self::sanitize($val, $type);
            }
            return $clean;
        }

        switch ($type) {
            case 'int':
                return (int) $input;
            case 'float':
                return (float) $input;
            case 'url':
                return filter_var($input, FILTER_SANITIZE_URL);
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
            case 'html':
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            case 'string':
            default:
                return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * Get all SMM services
     */
    public static function getServices($activeOnly = false, $productId = null)
    {
        try {
            $query = Capsule::table('mod_smm_services');
            if ($activeOnly) {
                $query->where('is_active', 1);
            }
            if ($productId) {
                $query->where('whmcs_product_id', $productId);
            }
            return $query->get()->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get SMM order by WHMCS service ID
     */
    public static function getOrderByServiceId($serviceId)
    {
        try {
            return Capsule::table('mod_smm_orders')
                ->where('whmcs_service_id', $serviceId)
                ->first();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get SMM order by WHMCS order ID
     */
    public static function getOrderByWHMCSOrderId($orderId)
    {
        try {
            return Capsule::table('mod_smm_orders')
                ->where('whmcs_order_id', $orderId)
                ->first();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Update order status
     */
    public static function updateOrderStatus($id, $status, $extra = [])
    {
        try {
            $data = array_merge(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $extra);
            Capsule::table('mod_smm_orders')
                ->where('id', $id)
                ->update($data);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Format price with markup
     */
    public static function calculatePrice($baseRate, $markupPercent, $markupFixed)
    {
        $price = $baseRate;
        if ($markupPercent > 0) {
            $price += ($price * ($markupPercent / 100));
        }
        if ($markupFixed > 0) {
            $price += $markupFixed;
        }
        return round($price, 4);
    }

    /**
     * Resolve the WHMCS server ID for an SMM order row (fixes passing
     * whmcs_service_id directly to getServerConfig).
     */
    public static function resolveServerIdForOrder($order): int
    {
        if (!is_object($order)) {
            return 0;
        }
        try {
            if (!empty($order->service_map_id)) {
                $serverId = Capsule::table('mod_smm_services')
                    ->where('id', (int) $order->service_map_id)
                    ->value('whmcs_server_id');
                if ((int) $serverId > 0) {
                    return (int) $serverId;
                }
            }
            if (!empty($order->whmcs_service_id)) {
                $serverId = Capsule::table('tblhosting')
                    ->where('id', (int) $order->whmcs_service_id)
                    ->value('server');
                if ((int) $serverId > 0) {
                    return (int) $serverId;
                }
                $productId = Capsule::table('tblhosting')
                    ->where('id', (int) $order->whmcs_service_id)
                    ->value('packageid');
                if ((int) $productId > 0) {
                    $serverId = Capsule::table('mod_smm_services')
                        ->where('whmcs_product_id', (int) $productId)
                        ->where('is_active', 1)
                        ->value('whmcs_server_id');
                    if ((int) $serverId > 0) {
                        return (int) $serverId;
                    }
                }
            }
            if (!empty($order->smm_service_id)) {
                $serverId = Capsule::table('mod_smm_services')
                    ->where('smm_service_id', (string) $order->smm_service_id)
                    ->where('is_active', 1)
                    ->value('whmcs_server_id');
                if ((int) $serverId > 0) {
                    return (int) $serverId;
                }
            }
        } catch (Exception $e) {
            return 0;
        }
        return 0;
    }

    /**
     * Get server config for an SMM order row (resolves whmcs_server_id first).
     */
    public static function getServerConfigForOrder($order): array
    {
        return self::getServerConfig(self::resolveServerIdForOrder($order));
    }

    /**
     * Get server config from provisioning module
     */
    public static function getServerConfig($serverId)
    {
        try {
            $row = Capsule::table('mod_smm_server_map')
                ->where('server_id', $serverId)
                ->where('is_active', 1)
                ->first();

            if ($row) {
                return [
                    'api_url' => $row->api_url,
                    'api_key' => self::decryptValue($row->api_key),
                ];
            }

            // Fallback to addon config
            return [
                'api_url' => self::getConfig('api_url'),
                'api_key' => self::getConfig('api_key'),
            ];
        } catch (Exception $e) {
            return [
                'api_url' => self::getConfig('api_url'),
                'api_key' => self::getConfig('api_key'),
            ];
        }
    }

    /**
     * Save server config
     */
    public static function setServerConfig($serverId, $apiUrl, $apiKey)
    {
        try {
            Capsule::table('mod_smm_server_map')
                ->updateOrInsert(
                    ['server_id' => $serverId],
                    [
                        'api_url'   => $apiUrl,
                        'api_key'   => self::encryptSecret($apiKey),
                        'is_active' => 1,
                        'updated_at'=> date('Y-m-d H:i:s')
                    ]
                );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Safe JSON decode
     */
    public static function jsonDecode($string, $assoc = true)
    {
        if (empty($string)) {
            return null;
        }
        $result = json_decode($string, $assoc);
        return (json_last_error() === JSON_ERROR_NONE) ? $result : null;
    }

    /**
     * Get recent logs
     */
    public static function getLogs($limit = 100, $action = null)
    {
        try {
            $query = Capsule::table('mod_smm_logs')->orderBy('id', 'desc');
            if ($action) {
                $query->where('action', 'like', '%' . $action . '%');
            }
            return $query->limit($limit)->get()->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Delete logs older than X days
     */
    public static function purgeLogs($days = 30)
    {
        try {
            Capsule::table('mod_smm_logs')
                ->where('created_at', '<', date('Y-m-d H:i:s', strtotime("-{$days} days")))
                ->delete();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
