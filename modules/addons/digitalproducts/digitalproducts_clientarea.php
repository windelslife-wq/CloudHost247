<?php
/** WHMCS client-area entry point for My Downloads. */
if (!defined('WHMCS')) die('This file cannot be accessed directly');
require_once __DIR__ . '/autoload.php';

function digitalproducts_clientarea($vars)
{
    $client = new DigitalProducts\Client($vars);
    $error = null;
    try { $client->handleRequest(); } catch (\Throwable $e) { $error = 'This download request could not be completed. Please try again.'; }
    $data = $client->viewData();
    $data['error'] = $error;
    if (($_GET['action'] ?? '') === 'product') {
        $detail = $client->productData((int) ($_GET['entitlement_id'] ?? 0));
        if (!$detail) return ['pagetitle' => 'My Downloads', 'breadcrumb' => ['index.php?m=digitalproducts' => 'My Downloads'], 'templatefile' => 'client/downloads', 'requirelogin' => true, 'forcessl' => true, 'vars' => $data];
        return ['pagetitle' => $detail['product']['name'], 'breadcrumb' => ['index.php?m=digitalproducts' => 'My Downloads', 'index.php?m=digitalproducts&action=product&entitlement_id=' . (int) $_GET['entitlement_id'] => $detail['product']['name']], 'templatefile' => 'client/product', 'requirelogin' => true, 'forcessl' => true, 'vars' => array_merge($data, $detail)];
    }
    return ['pagetitle' => 'My Downloads', 'breadcrumb' => ['index.php?m=digitalproducts' => 'My Downloads'], 'templatefile' => 'client/downloads', 'requirelogin' => true, 'forcessl' => true, 'vars' => $data];
}
