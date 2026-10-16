<?php



require_once dirname(dirname(dirname(dirname(__DIR__)))) . "/init.php";

require_once dirname(dirname(dirname(__DIR__))) . "/addons/soyoustart/classes/ApiCall.php";



use \WGSModule\Soyoustart\classes\ApiCall;
use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Soyoustart\AjaxAuth;



global $whmcs;

// The legacy endpoint must not trust client-supplied account/server identifiers.
if (empty($_SESSION['adminid']) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !isset($_POST['serviceId']) || !ctype_digit((string) $_POST['serviceId'])
    || (int) $_POST['serviceId'] < 1) {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../lib/AjaxAuth.php';
$serviceId = (int) $_POST['serviceId'];
$service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
$product = $service ? Capsule::table('tblproducts')->where('id', $service->packageid)->first() : null;
$serverName = ($service && $product)
    ? Capsule::table('tblcustomfieldsvalues')
        ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
        ->where('tblcustomfieldsvalues.relid', $serviceId)
        ->where('tblcustomfields.relid', $product->id)
        ->where('tblcustomfields.fieldname', 'like', 'ovh_server_name%')
        ->value('tblcustomfieldsvalues.value') : null;
$context = AjaxAuth::context($_SESSION['adminid'], $_SERVER['REQUEST_METHOD'],
    $serviceId, $service, $product, $serverName);
if ($context === null) {
    http_response_code(403);
    exit;
}
if (($whmcs->get_req_var("installOs") == "installOs" || $whmcs->get_req_var("cancleReinstallation") == "cancleReinstallation")
    && !AjaxAuth::verifyCsrf()) {
    http_response_code(403);
    exit;
}
$ovhServerName = $context['server'];
$accountId = $context['account'];
$location = $context['location'];
$apiCall = new ApiCall();



if ($whmcs->get_req_var("getCompatibleOS") == "getCompatibleOS") {





    // ovhServerName derived from the service record.

    // accountId derived from the service record.

    // location derived from the service record.



    $compatibleOs = $apiCall->get("/dedicated/server/{$ovhServerName}/install/compatibleTemplates", $accountId, $location, "Getting compatible OS", true);

    $installationStatus = $apiCall->get("/dedicated/server/{$ovhServerName}/install/status", $accountId, $location, "Getting installation status", true);

    $html = '';

    if (($compatibleOs['httpcode'] ?? 0) != 200) {
        echo json_encode($compatibleOs);
        exit;
    }
    foreach ($compatibleOs["result"]->ovh ?? [] as $value) {
        $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars(ucfirst(str_replace('-', ' ', str_replace('_', ' ', (string) $value))), ENT_QUOTES, 'UTF-8');
        $html .= '<option value="' . $safeValue . '">' . $safeLabel . '</option>';
    }

    echo json_encode(["html" => $html, "installationStatus" => $installationStatus]);

    exit;

} elseif ($whmcs->get_req_var("installOs") == "installOs") {



    // location derived from the service record.

    // accountId derived from the service record.

    // ovhServerName derived from the service record.



    $response = $apiCall->post("/dedicated/server/{$ovhServerName}/install/start", $accountId, $location, ["templateName" => $whmcs->get_req_var("templateName")], "reinstalling os", true);

    if($response["httpcode"] == 200){

        $_SESSION["installationTaskId"] = $response["result"]->taskId;

    }

    echo json_encode($response);

    exit;

} 

elseif ($whmcs->get_req_var("cancleReinstallation") == "cancleReinstallation") {



    // location derived from the service record.

    // accountId derived from the service record.

    // ovhServerName derived from the service record.



    $taskId = (int) ($_SESSION["installationTaskId"] ?? 0);

    if ($taskId < 1) {
        http_response_code(400);
        exit;
    }
    $response = $apiCall->post("/dedicated/server/{$ovhServerName}/task/{$taskId}/cancel", $accountId, $location, [], "Cancel Re-intallation", true);



    echo json_encode($response);

    exit;

} 





elseif ($whmcs->get_req_var("getInstallationStatus") == "getInstallationStatus") {



    // location derived from the service record.

    // accountId derived from the service record.

    // ovhServerName derived from the service record.



    $installationStatus = $apiCall->get("/dedicated/server/{$ovhServerName}/install/status", $accountId, $location, "Getting installation status", true);

    if($installationStatus["httpcode"] != 200){

        unset($_SESSION["installationTaskId"]);

    }

    echo json_encode($installationStatus);

    exit;

} 

elseif ($whmcs->get_req_var("iPaction") == "getFirewallRules") {





    // accountId derived from the service record.

    // location derived from the service record.

    $ipblock = urlencode($whmcs->get_req_var("ipblock"));

                  

    $ipReverse = str_replace("::", "", explode("/", $whmcs->get_req_var("ipblock")));

    $endPoint = "/ip/{$ipblock}/firewall/{$ipReverse["0"]}/rule";

    $response = $apiCall->get($endPoint, $accountId, $location, "getting firewall rules", true);





    if ($response["httpcode"] != 200) {

        echo json_encode($response);

        exit;

    }

    $html = [];

    foreach ($response["result"] as $value) {

        $endPoint = "/ip/{$ipblock}/firewall/{$ipReverse["0"]}/rule/{$value}";

        $response = $apiCall->get($endPoint, $accountId, $location, "getting firewall rules", true);

        $state = '-';

        $action = "";

        if ($response["result"]->state == "ok") {

            $state = "<span class=\"badge bg-success\">Active</span>";

            $action = "<i class=\"fas fa-trash-alt deleteFirewallRule\"></i>";

        } elseif ($response["result"]->state == "creationPending") {

            $state = "<span class=\"badge bg-secondary\">Creating</span>";

        } else {

            $state = "<span class=\"badge bg-danger\">Deleting</span>";

        }



        $html[] = [

            "sequence" => (int) $response["result"]->sequence,

            "action" => htmlspecialchars(strtoupper((string) $response["result"]->action), ENT_QUOTES, 'UTF-8'),

            "protocol" => htmlspecialchars(strtoupper((string) $response["result"]->protocol), ENT_QUOTES, 'UTF-8'),

            "destination" => htmlspecialchars((string) $response["result"]->destination, ENT_QUOTES, 'UTF-8'),

            "sourcePort" => (is_null($response["result"]->sourcePort) ? "-" : htmlspecialchars((string) $response["result"]->sourcePort, ENT_QUOTES, 'UTF-8')),

            "destinationPort" => is_null($response["result"]->destinationPort) ? "-" : htmlspecialchars((string) $response["result"]->destinationPort, ENT_QUOTES, 'UTF-8'),

            "tcpOption" => ($response["result"]->fragments) ? "Fragments" : "-",

            "state" => $state,

            "delete" => $action,

        ];

    }



    echo json_encode(["data" => $html]);

    exit;

} 

