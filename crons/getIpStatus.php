<?php
require_once __DIR__ . '/lib/CronRuntime.php';
\CloudHost247\SoYouStart\Cron\CronGuard::requireCli();
$__cronLock = \CloudHost247\SoYouStart\Cron\CronLock::acquire('getIpStatus');
if ($__cronLock === null) {
    exit(0);
}


    $whmcspath = "";
    if (file_exists(dirname(__FILE__) . "/config.php"))
        require_once dirname(__FILE__) . "/config.php";
    if (!empty($whmcspath)) {
        require_once $whmcspath . "/init.php";
        if (file_exists($whmcspath . '/modules/addons/soyoustart/classes/ApiCall.php')) {
            require_once($whmcspath . '/modules/addons/soyoustart/classes/ApiCall.php');
        } else {
            logActivity('OVH Cron error, File (/modules/addons/soyoustart/classes/ApiCall.php) not found');
        }
    } else {
        require(__DIR__ . "/../init.php");
        if (file_exists(__DIR__ . '/../modules/addons/soyoustart/classes/ApiCall.php'))
            require_once(__DIR__ . '/../modules/addons/soyoustart/classes/ApiCall.php');
        else
            logActivity('OVH Cron error, File (/modules/addons/soyoustart/classes/ApiCall.php) not found');
    }

    use \WHMCS\Module\Addon\Soyoustart\Helper;
    use \WGSModule\Soyoustart\classes\ApiCall;
    use WHMCS\Database\Capsule;



    try {
        $helper = new Helper();
        $apiCall = new ApiCall();

        $allips = $apiCall->get_data("mod_soyoustart_ips_orders", ["status" => 2], "all");

        $helper->insert_update("tblconfiguration", ['setting' => 'getIpCronstatus'], ['value' => 'Configured', 'setting' => 'getIpCronstatus', "updated_at" => date('Y-m-d H:i:s')]);


        if (isset($allips[0])) {
            foreach ($allips as $key => $ip) {
                $serviceMatches = getAllServerPackages($ip->service_id);
                if (!isset($serviceMatches[0])) {
                    logActivity("GetIpStatus cron skipped order with no active hosting service (order row " . (int) $ip->id . ").");
                    continue;
                }
                $service = $serviceMatches[0];
                $accountInfo = $service->accountInfo ?? null;
                if (!is_object($accountInfo) || empty($accountInfo->id) || empty($accountInfo->location)) {
                    logActivity("GetIpStatus cron skipped service with missing OVH account configuration (service ID " . (int) $ip->service_id . ").");
                    continue;
                }
                $accountId = $accountInfo->id;
                $location = $accountInfo->location;
                $ovhCustomHostname = $service->ovh_custom_hostname;
                $ovh_server_name = $service->ovh_server_name;
                $ovhOrderId = $ip->iporderid;
                if (!empty($ovh_server_name) && !empty($ovhOrderId) && !empty($accountId) && !empty($location)) {
                    $orderstatus = $apiCall->get('/me/order/' . $ovhOrderId . '/status', $accountId, $location, "Getting order status", true);
                    if ($orderstatus["httpcode"] != 200) {
                        logActivity('Cron Error: getting server datails after create command for serviceId(' . $service->id . ') error message(' . $orderstatus["result"]->message . ') ');
                        continue;
                    }

                    if ($orderstatus["result"] == "delivered") {
                        /* getting billing details of specific order id */
                        $billDetail = $apiCall->get("/me/bill?orderId={$ovhOrderId}", $accountId, $location, "Getting billing details", true);
                        if ($billDetail["httpcode"] != 200) {
                            logActivity('Cron Error: getting bill details after create command for serviceId(' . $service->id . ') error message(' . $billDetail["result"]->message . ') ');
                            continue;
                        }
                        /* getting order details */
                        $orderDetail = $apiCall->get("/me/bill/{$billDetail['result'][0]}", $accountId, $location, "Getting order details", true);


                        if ($orderDetail["httpcode"] != 200) {
                            logActivity('Cron Error: getting order details after create command for serviceId(' . $service->id . ') error message(' . $orderDetail["result"]->message . ') ');
                            continue;
                        }
                        if ($orderDetail["result"]->orderId == $ovhOrderId) {
                            /* Getting bill details ids */
                            $billDetailIds = $apiCall->get("/me/bill/{$billDetail['result'][0]}/details", $accountId, $location, "Getting bill details ids", true);
                            if ($billDetailIds["httpcode"] != 200) {
                                logActivity('Cron Error: getting bill details ids after create command for serviceId(' . $service->id . ') error message(' . $billDetailIds["result"]->message . ') ');
                                continue;
                            }
                            $billDetailIds = end($billDetailIds["result"]);
                            $billDetails = $apiCall->get("/me/bill/{$billDetail['result'][0]}/details/{$billDetailIds}", $accountId, $location, "Getting bill details ids", true);
                            if ($billDetails["httpcode"] != 200) {
                                logActivity('Cron Error: getting bill details after create command for serviceId(' . $service->id . ') error message(' . $billDetails["result"]->message . ') ');
                                continue;
                            }
                            $domainName = $billDetails["result"]->domain;
                            if (stripos($domainName, 'ip-') !== false) {
                                $domainName = str_replace("ip-", "", $domainName);
                                if (!str_contains($domainName, '/')) {
                                    $domainName .= "/32";
                                }
                                $destinations = $apiCall->getIpMoveDestinations(
                                    $location,
                                    $accountId,
                                    $domainName
                                );
                                if ($destinations["httpcode"] != 200) {
                                    logActivity('Cron Error: getting ip move destinations after create command for serviceId(' . $service->id . ') error message(' . $destinations["result"]->message . ') ');
                                    continue;
                                }

                                $matchedDestination = \CloudHost247\SoYouStart\Cron\IpDestinationValidator::findEligibleService(
                                    $destinations,
                                    $ovh_server_name
                                );
                                if ($matchedDestination === null) {
                                    logActivity('Cron Error: target service(' . $ovh_server_name . ') is not in eligible move destinations for IP(' . $domainName . ') on serviceId(' . $service->id . ')');
                                    continue;
                                }

                                $assignResponse = $apiCall->moveIpToService(
                                    $location,
                                    $accountId,
                                    $domainName,
                                    $matchedDestination
                                );

                                if ($assignResponse["httpcode"] != 200) {
                                    logActivity('Cron Error: moving ip to service after create command for serviceId(' . $service->id . ') error message(' . $assignResponse["result"]->message . ') ');
                                    continue;
                                }

                                Capsule::table("mod_soyoustart_ips_orders")->where("id", $ip->id)->update(["status" => 1]);
                            }
                            logActivity("Cron: get server name run successfully for the service id #$service->id");
                        }
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        logActivity('Cron Error: getting server datails after create command(' . $e->getMessage() . ')');
    } finally {
        \CloudHost247\SoYouStart\Cron\CronLock::release($__cronLock);
    }



    function getAllServerPackages($serviceID)
    {
        $result = Capsule::table("tblhosting")
            ->join("tblproducts", "tblhosting.packageid", "=", "tblproducts.id")
            ->join("tblclients", "tblclients.id", "=", "tblhosting.userid")
            ->select("tblhosting.id", "tblhosting.regdate", "tblhosting.nextinvoicedate", "tblhosting.packageid", "tblhosting.domainstatus", "tblproducts.servertype", "tblclients.firstname", "tblclients.id as clientid", "tblclients.lastname", "tblclients.email")
            ->whereIn("tblproducts.servertype", ['soyoustart_vps', 'soyoustart'])
            ->where("tblhosting.domainstatus", "Active")
            ->where("tblhosting.id", $serviceID)
            ->get();
        foreach ($result as $key => $value) {
            $customVieldsValues = Capsule::table('tblcustomfields')
                ->leftJoin('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
                ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')->where('tblcustomfields.type', 'product')
                ->where('tblcustomfields.type', 'product')->where('tblcustomfieldsvalues.relid', $value->id)
                ->get();
            foreach ($customVieldsValues as $customVieldsValue) {
                $name = explode("|", $customVieldsValue->fieldname)[0];
                $result[$key]->$name = $customVieldsValue->value;
            }
            $accountInfo = get_data("mod_soyoustart", ["account_number" => $value->ovh_account,  "location" => $value->ovh_server_location], "first");

            $result[$key]->accountInfo = $accountInfo;
        }
        return $result;
    }

    function get_data($table_name = '', $where = [], $limit = null)
    {
        try {
            $query = Capsule::table($table_name);
            if (!empty($where)) {
                $query->where($where);
            }
            if ($limit == "first") {
                return $query->first();
            }
            return $query->get();
        } catch (\Throwable $e) {
            throw new \Exception('Error retrieving data: ' . $e->getMessage());
        }
    }
