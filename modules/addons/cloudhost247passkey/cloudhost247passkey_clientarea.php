<?php
/**
 * WHMCS client-area entry point for CloudHost247 Passkey.
 *
 * JSON ceremony/management actions exit through the HTTP kernel (public
 * ceremony routes stay reachable without a login); page views render the
 * Passkeys management template for authenticated clients.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

function cloudhost247passkey_clientarea($vars)
{
    $action = '';
    if (isset($_POST['passkey_action']) && is_string($_POST['passkey_action'])) {
        $action = $_POST['passkey_action'];
    } elseif (isset($_GET['passkey_action']) && is_string($_GET['passkey_action'])) {
        $action = $_GET['passkey_action'];
    }
    if ($action !== '' || isset($_GET['ajax']) || isset($_POST['ajax'])) {
        \CloudHost247\Passkey\Http\PasskeyHttpKernel::dispatchAjax();
    }

    $client = new \CloudHost247\Passkey\Client(is_array($vars) ? $vars : []);
    try {
        $client->handleRequest();
    } catch (\Throwable $error) {
        // viewData() surfaces a generic notice; page rendering continues.
    }
    return [
        'pagetitle' => 'Passkeys',
        'breadcrumb' => ['index.php?m=cloudhost247passkey' => 'Passkeys'],
        'templatefile' => 'client/passkeys',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => $client->viewData(),
    ];
}
