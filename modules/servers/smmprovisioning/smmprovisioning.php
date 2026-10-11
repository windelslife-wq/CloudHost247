<?php
/**
 * SMM Panel Provisioning Module for WHMCS
 * Version: 1.0.0
 * Compatible with WHMCS 8.9.x and PHP 7.4+
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../addons/smmaddon/lib/Helper.php';
require_once __DIR__ . '/../../addons/smmaddon/lib/ApiClient.php';

use SmmAddon\Helper;
use SmmAddon\ApiClient;


/**
 * Normalise the panel endpoint from the server record.
 * Returns array($url, $key, $error); $error is null on success.
 */
function smmprovisioning_apiEndpoint(array $params)
{
    $host = isset($params['serverhostname']) ? trim((string) $params['serverhostname']) : '';
    $ip = isset($params['serverip']) ? trim((string) $params['serverip']) : '';
    $url = $host !== '' ? $host : $ip;
    $key = isset($params['serverpassword']) ? (string) $params['serverpassword'] : '';
    if ($url === '' || $key === '') {
        return array(null, null, 'API URL and API Key are required.');
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return array($url, $key, null);
}

/**
 * Pick a custom-field value: exact configured name (before any "|label") wins,
 * then first substring-needle hit. $pairs is array(array($name, $value), ...).
 */
function smmprovisioning_matchField(array $pairs, $configuredName, array $needles)
{
    $want = strtolower(trim((string) $configuredName));
    foreach ($pairs as $pair) {
        $name = strtolower(trim((string) explode('|', (string) $pair[0])[0]));
        if ($name !== '' && $name === $want) {
            return $pair[1];
        }
    }
    foreach ($pairs as $pair) {
        $name = strtolower((string) $pair[0]);
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($name, $needle) !== false) {
                return $pair[1];
            }
        }
    }
    return null;
}

/**
 * Module Metadata
 */
function smmprovisioning_MetaData()
{
    return array(
        'DisplayName'    => 'SMM Panel Provisioning',
        'APIVersion'     => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '443',
        'DefaultSSLPort'    => '443',
    );
}

/**
 * Module Configuration
 */
function smmprovisioning_ConfigOptions()
{
    return array(
        'smm_service_id' => array(
            'FriendlyName' => 'SMM Service ID',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => '',
            'Description'  => 'The SMM panel service ID to use for this product.',
        ),
        'smm_category' => array(
            'FriendlyName' => 'Service Category',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => '',
            'Description'  => 'Optional category label.',
        ),
        'min_quantity' => array(
            'FriendlyName' => 'Minimum Quantity',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '100',
            'Description'  => 'Minimum order quantity.',
        ),
        'max_quantity' => array(
            'FriendlyName' => 'Maximum Quantity',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '10000',
            'Description'  => 'Maximum order quantity.',
        ),
        'link_field' => array(
            'FriendlyName' => 'Link Custom Field Name',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'Link',
            'Description'  => 'Name of custom field where client enters URL/link.',
        ),
        'quantity_field' => array(
            'FriendlyName' => 'Quantity Custom Field Name',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'Quantity',
            'Description'  => 'Name of custom field where client enters quantity.',
        ),
    );
}

/**
 * Test Connection
 */
function smmprovisioning_TestConnection(array $params)
{
    try {
        list($apiUrl, $apiKey, $endpointError) = smmprovisioning_apiEndpoint($params);
        if ($endpointError !== null) {
            return array(
                'success' => false,
                'error'   => $endpointError,
            );
        }

        $client = new ApiClient($apiUrl, $apiKey, true);
        $result = $client->getBalance();

        if (isset($result['balance'])) {
            // Save server config to mapping table
            Helper::setServerConfig($params['serverid'], $apiUrl, $apiKey);
            return array(
                'success' => true,
                'error'   => '',
            );
        }

        return array(
            'success' => false,
            'error'   => 'Invalid API response: ' . json_encode($result),
        );
    } catch (Exception $e) {
        return array(
            'success' => false,
            'error'   => 'Connection failed: ' . $e->getMessage(),
        );
    }
}

/**
 * Create Account / Place Order
 */
function smmprovisioning_CreateAccount(array $params)
{
    try {
        $serviceId = $params['serviceid'];
        $productId = $params['pid'];
        $orderId   = $params['orderid'];
        $userId    = $params['clientsdetails']['userid'];

        // Idempotency (mirrors M-4): a re-run must not place a second provider order.
        $existingOrder = Helper::getOrderByServiceId($serviceId);
        if ($existingOrder && (!isset($existingOrder->status) || $existingOrder->status !== 'error')) {
            return 'success';
        }

        // Get SMM service mapping
        $smmServiceId = $params['configoption1'] ?? '';
        $minQty = (int) ($params['configoption3'] ?? 100);
        $maxQty = (int) ($params['configoption4'] ?? 10000);
        $linkFieldName = $params['configoption5'] ?? 'Link';
        $qtyFieldName  = $params['configoption6'] ?? 'Quantity';

        if (empty($smmServiceId)) {
            return 'SMM Service ID is not configured for this product.';
        }

        // Find mapped service in our database
        $smmService = \Illuminate\Database\Capsule\Manager::table('mod_smm_services')
            ->where('smm_service_id', $smmServiceId)
            ->where('is_active', 1)
            ->first();

        if (!$smmService) {
            return 'SMM Service ID ' . $smmServiceId . ' not found or not mapped.';
        }

        // Get custom field values from params (exact configured name wins, fuzzy fallback)
        $link = '';
        $quantity = 0;

        if (!empty($params['customfields']) && is_array($params['customfields'])) {
            $pairs = array();
            foreach ($params['customfields'] as $fieldName => $fieldValue) {
                $pairs[] = array($fieldName, $fieldValue);
            }
            $foundLink = smmprovisioning_matchField($pairs, $linkFieldName, array('link', 'url'));
            if ($foundLink !== null && $foundLink !== '') {
                $link = $foundLink;
            }
            $foundQty = smmprovisioning_matchField($pairs, $qtyFieldName, array('quantity', 'count', 'amount'));
            if ($foundQty !== null && $foundQty !== '') {
                $quantity = (int) $foundQty;
            }
        }

        // Fallback: query database for custom fields
        if (empty($link) || $quantity < 1) {
            $customFields = \Illuminate\Database\Capsule\Manager::table('tblcustomfieldsvalues')
                ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
                ->where('tblcustomfieldsvalues.relid', $serviceId)
                ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')
                ->get();

            $pairs = array();
            foreach ($customFields as $cf) {
                $pairs[] = array($cf->fieldname, $cf->value);
            }
            if (empty($link)) {
                $foundLink = smmprovisioning_matchField($pairs, $linkFieldName, array('link', 'url'));
                if ($foundLink !== null && $foundLink !== '') {
                    $link = $foundLink;
                }
            }
            if ($quantity < 1) {
                $foundQty = smmprovisioning_matchField($pairs, $qtyFieldName, array('quantity', 'count', 'amount'));
                if ($foundQty !== null && $foundQty !== '') {
                    $quantity = (int) $foundQty;
                }
            }
        }

        // Fallback to domain field
        if (empty($link) && !empty($params['domain'])) {
            $link = $params['domain'];
        }

        if (empty($link) || $quantity < 1) {
            return 'Missing required fields: Link/URL and Quantity must be provided.';
        }

        // Validate quantity bounds
        if ($quantity < $minQty) {
            $quantity = $minQty;
        }
        if ($maxQty > 0 && $quantity > $maxQty) {
            $quantity = $maxQty;
        }

        // Use server-specific config if available
        list($apiUrl, $apiKey, $endpointError) = smmprovisioning_apiEndpoint($params);
        if ($endpointError !== null) {
            return $endpointError;
        }

        $debug = Helper::getConfig('debug_mode') === 'on';
        $client = new ApiClient($apiUrl, $apiKey, $debug);
        $result = $client->placeOrder($smmServiceId, $link, $quantity);

        if (isset($result['order'])) {
            // Store order
            \Illuminate\Database\Capsule\Manager::table('mod_smm_orders')->insert([
                'whmcs_order_id'   => $orderId,
                'whmcs_service_id' => $serviceId,
                'whmcs_user_id'    => $userId,
                'smm_order_id'     => (string) $result['order'],
                'smm_service_id'   => $smmServiceId,
                'quantity'         => $quantity,
                'link'             => $link,
                'status'           => 'pending',
                'api_response'     => json_encode($result),
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);

            // Append to service notes (never clobber admin notes)
            $priorNotes = \Illuminate\Database\Capsule\Manager::table('tblhosting')
                ->where('id', $serviceId)
                ->value('notes');
            $notes = trim((string) $priorNotes);
            $notes .= ($notes === '' ? '' : "\n") . 'SMM Order ID: ' . $result['order'];
            \Illuminate\Database\Capsule\Manager::table('tblhosting')
                ->where('id', $serviceId)
                ->update([
                    'notes' => $notes,
                ]);

            return 'success';
        }

        // Log failure
        $errorMsg = isset($result['error']) ? $result['error'] : json_encode($result);

        \Illuminate\Database\Capsule\Manager::table('mod_smm_orders')->insert([
            'whmcs_order_id'   => $orderId,
            'whmcs_service_id' => $serviceId,
            'whmcs_user_id'    => $userId,
            'smm_order_id'     => '',
            'smm_service_id'   => $smmServiceId,
            'quantity'         => $quantity,
            'link'             => $link,
            'status'           => 'error',
            'api_response'     => json_encode($result),
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        return 'Order placement failed: ' . $errorMsg;
    } catch (Exception $e) {
        logActivity('SMM Provisioning CreateAccount Error: ' . $e->getMessage());
        return 'An error occurred: ' . $e->getMessage();
    }
}

/**
 * Suspend Account
 */
function smmprovisioning_SuspendAccount(array $params)
{
    try {
        $serviceId = $params['serviceid'];
        $order = Helper::getOrderByServiceId($serviceId);

        if ($order && !empty($order->smm_order_id)) {
            list($apiUrl, $apiKey, $endpointError) = smmprovisioning_apiEndpoint($params);
            if ($endpointError === null) {
                $debug = Helper::getConfig('debug_mode') === 'on';
                $client = new ApiClient($apiUrl, $apiKey, $debug);
                $cancelResult = $client->cancelOrder($order->smm_order_id);
                if (is_array($cancelResult) && !isset($cancelResult['error'])) {
                    \Illuminate\Database\Capsule\Manager::table('mod_smm_orders')
                        ->where('id', $order->id)
                        ->update([
                            'status' => 'canceled',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                } else {
                    logActivity('SMM Provisioning ' . __FUNCTION__ . ': panel cancel rejected for order ' . $order->smm_order_id);
                }
            } else {
                logActivity('SMM Provisioning ' . __FUNCTION__ . ': ' . $endpointError);
            }
        }

        return 'success';
    } catch (Exception $e) {
        logActivity('SMM Provisioning SuspendAccount Error: ' . $e->getMessage());
        return 'success'; // Allow suspend even if API fails
    }
}

/**
 * Unsuspend Account
 */
function smmprovisioning_UnsuspendAccount(array $params)
{
    return 'success';
}

/**
 * Terminate Account
 */
function smmprovisioning_TerminateAccount(array $params)
{
    try {
        $serviceId = $params['serviceid'];
        $order = Helper::getOrderByServiceId($serviceId);

        if ($order && !empty($order->smm_order_id)) {
            list($apiUrl, $apiKey, $endpointError) = smmprovisioning_apiEndpoint($params);
            if ($endpointError === null) {
                $debug = Helper::getConfig('debug_mode') === 'on';
                $client = new ApiClient($apiUrl, $apiKey, $debug);
                $cancelResult = $client->cancelOrder($order->smm_order_id);
                if (is_array($cancelResult) && !isset($cancelResult['error'])) {
                    \Illuminate\Database\Capsule\Manager::table('mod_smm_orders')
                        ->where('id', $order->id)
                        ->update([
                            'status' => 'canceled',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                } else {
                    logActivity('SMM Provisioning ' . __FUNCTION__ . ': panel cancel rejected for order ' . $order->smm_order_id);
                }
            } else {
                logActivity('SMM Provisioning ' . __FUNCTION__ . ': ' . $endpointError);
            }
        }

        return 'success';
    } catch (Exception $e) {
        logActivity('SMM Provisioning TerminateAccount Error: ' . $e->getMessage());
        return 'success';
    }
}

/**
 * Client Area output
 */
function smmprovisioning_ClientArea(array $params)
{
    try {
        $serviceId = $params['serviceid'];
        $order = Helper::getOrderByServiceId($serviceId);

        if (!$order) {
            return array(
                'templatefile' => 'clientarea',
                'vars' => array(
                    'error' => 'No SMM order found for this service.',
                    'serviceid' => $serviceId,
                ),
            );
        }

        $statusColors = array(
            'pending'     => 'warning',
            'processing'  => 'info',
            'inprogress'  => 'info',
            'completed'   => 'success',
            'canceled'    => 'danger',
            'partial'     => 'warning',
            'refunded'    => 'default',
            'error'       => 'danger',
        );

        return array(
            'templatefile' => 'clientarea',
            'vars' => array(
                'order'        => (array) $order,
                'status_color' => $statusColors[$order->status] ?? 'default',
                'serviceid'    => $serviceId,
            ),
        );
    } catch (Exception $e) {
        return array(
            'templatefile' => 'clientarea',
            'vars' => array(
                'error' => 'An error occurred: ' . $e->getMessage(),
                'serviceid' => $params['serviceid'],
            ),
        );
    }
}
