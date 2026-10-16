<?php

namespace WHMCS\Module\Server\Soyoustart;

require_once __DIR__ . '/../../../addons/soyoustart/classes/Configuration.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Module\Addon\Soyoustart\Helper;
use WGSModule\Soyoustart\classes\Configuration;
use WHMCS\Database\Capsule;

class SoyoustartServer extends Configuration
{
    public $addonHelper;
    public function __construct()
    {
        $this->addonHelper = new Helper();
    }
    public static function escapeHtml($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /** Fail closed on absent/non-numeric/non-positive pricing before the OVH purchase. */
    public static function validAdditionalIpPrice($price)
    {
        return is_object($price) && isset($price->additionalIPprice)
            && is_numeric($price->additionalIPprice)
            && is_finite((float) $price->additionalIPprice)
            && (float) $price->additionalIPprice > 0
            && (float) $price->additionalIPprice <= PHP_FLOAT_MAX / 256;
    }

    public function createAdditionalIpInvoice($userId, $quantity, $price)
    {
        if ((int) $userId < 1 || (int) $quantity < 1 || (int) $quantity > 256 || !self::validAdditionalIpPrice($price)) {
            return ['result' => 'error', 'message' => 'Invalid IP invoice request'];
        }
        $total = round((float) $price->additionalIPprice * (int) $quantity, 2);
        if (!is_finite($total) || $total <= 0) {
            return ['result' => 'error', 'message' => 'Invalid IP invoice total'];
        }
        return localAPI('CreateInvoice', [
            'userid' => (int) $userId,
            'status' => 'Unpaid',
            'paymentmethod' => 'mailin',
            'itemdescription1' => 'Additional IPs (' . (int) $quantity . ')',
            'itemamount1' => $total,
        ]);
    }

    public function InsertIpData($table, $data)
    {
        if ($table !== 'mod_soyoustart_ips_orders') {
            throw new \InvalidArgumentException('Unsupported IP order table');
        }
        return Capsule::table($table)->insert($data);
    }

    public function formateBillingCycle($billingCycle)
    {

        $data = [];

        if ($billingCycle == "Monthly") {
            $data['durationVal'] = "P1M";
            $data['pricingModeVal'] = "default";
        } elseif ($billingCycle == "Quarterly") {
            $data['durationVal'] = "P3M";
            $data['pricingModeVal'] = "default";
        } elseif ($billingCycle == "Semi-Annually") {
            $data['durationVal'] = "P6M";
            $data['pricingModeVal'] = "default";
        } elseif ($billingCycle == "Annually") {
            $data['durationVal'] = "P1Y";
            $data['pricingModeVal'] = "degressivity12";
        } elseif ($billingCycle == "Biennially") {
            $data['durationVal'] = "P2Y";
            $data['pricingModeVal'] = "degressivity24";
        } else {
            $data['durationVal'] = "P1M";
            $data['pricingModeVal'] = "default";
        }
        return $data;
    }

    public function getServiceConfigs($serviceID)
    {
        try {
            $serviceConfigs = Capsule::table('tblhostingconfigoptions')
                ->join('tblproductconfigoptionssub', 'tblhostingconfigoptions.optionid', '=', 'tblproductconfigoptionssub.id')
                ->join('tblproductconfigoptions', 'tblhostingconfigoptions.configid', '=', 'tblproductconfigoptions.id')
                ->select('tblhostingconfigoptions.optionid', 'tblhostingconfigoptions.qty', 'tblproductconfigoptions.optionname as opname', 'tblproductconfigoptions.optiontype', 'tblproductconfigoptionssub.optionname')
                ->where('tblhostingconfigoptions.relid', $serviceID)->get();

            return $serviceConfigs;
        } catch (\Throwable $th) {
            return [];
        }
    }

    public function serviceInfoHtml($serverInfo, $LANG, $hardwareInfo = null, $processor= null, $disk =null, $dataCenter =null, $apiCall =null, $location = '', $serverType ='', $ovhServerName ='', $accountId ='', $cardBody = true, $serviceId = 0)
    {
        $html = '
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.5/dist/sweetalert2.all.min.js" crossorigin="anonymous"></script>
        <script>

        $(document).ready(function(){
            $(document).on("click", ".getCompatibaleOS", function () {
                try {
                    $.ajax({
                        url: "/modules/servers/soyoustart/ajax/ajax.php",
                        method: "POST",
                        global:false,
                        data: { serviceId: ' . (int) $serviceId . ', getCompatibleOS: "getCompatibleOS" },
                        beforeSend:function(){
                            $("#installOs .reInstall").prop("disabled", true);
                            $("#installOs").find("select").html(`<option value="" disabled selected> loading...</option>`);
                        },
                        success: function (response) {
                            let result = JSON.parse(response);
                            $("#installOs").find("select").html(`${result.html}`);
                            if (result?.installationStatus) {
                                if (result.installationStatus.httpcode == 200) {
                                    $(".progress.reinstall").css("display", "block")
                                    $(".cancleReinstall").css("display", "inline")
                                    $("#inputState").attr("disabled", "disabled");
                                   
                                    $(".cancleReinstall").attr("disabled", false);
        
                                    let data = result.installationStatus.result.progress;
                                    /* calulating percentage  */
                                    calculateReinstallPercentage(data)
        
                                    /* getting re-installation status */
                                    setTimeout(() => getInstallationStatus(), 5000);
        
                                } else {
                                    $("#installOs .reInstall").prop("disabled", false);
                                    $("#inputState").attr("disabled", false);
                                }
                            } else {
                                $("#installOs .reInstall").prop("disabled", false);
                            }
                        },
                        error: function (error) {
                            $("#installOs .reInstall").prop("disabled", false);
                        }
                    });
                } catch (error) {
                    console.error(error)
                } 
            })
        });

        function reInstall(){
            Swal.fire({
                title: "Are you sure?",
                text: "You want to re-install the os!",
                icon: "question",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Yes",
    
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        let templateName = $("#installOs #inputState").val();
                        $.ajax({
                            url: "/modules/servers/soyoustart/ajax/ajax.php",
                            method: "POST",
                            data: { serviceId: ' . (int) $serviceId . ', installOs: "installOs", templateName },
                            beforeSend:function(){
                                $("#installOs .reInstall").prop("disabled", true);
                                $("#installOs .reInstall").append(`<i class="fa fa-spinner fa-spin"></i>`);
                            },
                            success: function (result) {
                                var response = JSON.parse(result)
                                if (response.httpcode != 200) {
                                    jQuery.growl.error({ title: "Error", message: response.result.message, duration: 5000 });
                                    $("#installOs .reInstall").prop("disabled", false);
                                    $("#installOs .reInstall").find("i").remove();
                                } else {

                                jQuery.growl.notice({ title: "Success", message: "OS installation has started successfully! This may take about 5-10 minutes", duration: 5000 });
                                $("#installOs .cancleReinstall").css("display", "inline");
                                $(".progress.reinstall").css("display", "block")

                                setTimeout(() => getInstallationStatus(), 5000);
                            }

                            },
                            error: function (error) {
                                jQuery.growl.error({ title: "Error", message: error, duration: 8000 });
                                $("#installOs .reInstall").prop("disabled", false);
                                $("#installOs .reInstall").find("i").remove();
                            }
                        });
                    } catch (error) {
                        console.error(error)
                    }
                }
            })
        }

        function cancleReInstall(){

            Swal.fire({
                title: "Are you sure?",
                text: "You want to cancle re-install.",
                icon: "question",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Yes",
    
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        
                        $.ajax({
                            url: "/modules/servers/soyoustart/ajax/ajax.php",
                            method: "POST",
                            data: { serviceId: ' . (int) $serviceId . ', cancleReinstallation:"cancleReinstallation"  },
                            beforeSend:function(){
                                $("#installOs .cancleReinstall").append(`<i class="fa fa-spinner fa-spin"></i>`);
                                $("#installOs .cancleReinstall").prop("disabled", true);
                                $("#installOs .reInstall").find("i").remove();
                            },
                            success: function (result) {
                                var response = JSON.parse(result)
                                console.log(response)
                                if (response.httpcode != 200) {
                                    jQuery.growl.error({ title: "Error", message: response.result.message, duration: 5000 });
                                    $("#installOs .cancleReinstall").prop("disabled", false);
                                    $("#installOs .cancleReinstall").find("i").remove();
                                } else {
                                    jQuery.growl.notice({ title: "Success", message: "OS Re-installation cancel in progress..!", duration: 5000 });
                                    /* getting re-installation status */
                                    setTimeout(() => getInstallationStatus(calculatePercentage = false), 5000);
                                }

                            },
                            error: function (error) {
                                jQuery.growl.error({ title: "Error", message: error, duration: 8000 });
                                $("#installOs .cancleReinstall").prop("disabled", false);
                                $("#installOs .cancleReinstall").find("i").remove();
                            }
                        });
    
                    } catch (error) {
                        console.error(error)
                    }
                }
            })
        }

        const calculateReinstallPercentage = (data = {}) => {
            let completedTask = 0;
            let pendingTask = 0;
            const totalProgressItems = data.length;
            let html ="";
            data.forEach((step, index) => {
                html+=`<tr><td>${step.comment}</td><td>${step.status}</td></tr>`;
                if (step.status == "done") {
                    completedTask += 1;
                } else {
                    pendingTask += 1;
                }
            });
        
            $("span.installatio_progress").remove()
            $("button.seeMore").remove()
            $(".progress.reinstall").after(`<span class="installatio_progress">Installation progress is running (${completedTask}/${completedTask+pendingTask})</span> <button type="button" class="btn btn-primary seeMore" onclick="jQuery(\'#installationDetails\').fadeToggle(1000); jQuery(\'#installationDetail\').fadeToggle(1000)"> See more details</button>`)
        
            $("#installationDetails tbody").html(`${html}`);
            $("#installationDetail tbody").html(`${html}`);
            const percentageDone = ((completedTask / totalProgressItems) * 100).toFixed(2);
            $(".progress-done").css("width", `${percentageDone}%`);
            $(".progress.reinstall").find("span").html(`${percentageDone}%`);
        }
        
        const getInstallationStatus = async (calculatePercentage = true) => {

            try {
                $.ajax({
                    url: "/modules/servers/soyoustart/ajax/ajax.php",
                    method: "POST",
                    data: { serviceId: ' . (int) $serviceId . ', getInstallationStatus: "getInstallationStatus" },

                    success: function (result) {
                        var response = JSON.parse(result)
                        console.log(response)
                        if (response.httpcode == 200) {
                            if(calculatePercentage){
                                calculateReinstallPercentage(response.result.progress);
                            }else{
                                jQuery.growl.notice({ title: "Success", message: "OS Re-installation cancel in progress...!", duration: 5000 });
                            }
                            /* getting re-installation status */
                            setTimeout(() => getInstallationStatus(calculatePercentage), 5000);
                        } else {
                            $(".progress.reinstall").css("display", "none")
                            $("button.reInstall ").prop("disabled", false);
                            $("button.reInstall ").find("i").remove();
                            if(calculatePercentage){
                                jQuery.growl.notice({ title: "Success", message: "OS Re-installation has been completed!", duration: 5000 });
                            }else{
                                jQuery.growl.notice({ title: "Success", message: "OS Re-installation has been cancelled successfully!", duration: 5000 });
                            }
                            setTimeout(() => location.reload(), 3000);
                        }
                     
                    
                    }
                });
        
            } catch (error) {
                console.error(error)
            }
        }
        

    </script>';

        $html .= '
        <div class="modal fade" id="installOs" tabindex="-1" role="dialog" aria-labelledby="installOsTitle" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form id="installOsForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="installOsLongTitle">' . self::escapeHtml($LANG["installOsHeading"]) . '</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">
                        <div class="form-group">
                            <label for="inputState">' . self::escapeHtml($LANG["installOslabel"]) . '</label>
                            <select class="form-control" id="inputState" name="inputState">
                                <option value="" disabled selected>loading...</option>
                            </select>

                        </div>
                        <div class="progress reinstall mb-4" style="display: none;">
                            <div class="progress-done" data-done="0" style="width: 0%; opacity: 1;"></div>
                            <span style="display: block;text-align: center;width: 100%;position: absolute;color: #fff;top: 0;">0.00%</span>
                        </div>
                        
                        <div id="installationDetails" style="display: none;" >
                            <table class="table table-hover">
                                <thead>
                                <tr>
                                    <th scope="col">Action</th>
                                    <th scope="col">Status</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td scope="col">Action</td>
                                    <td scope="col">Status</td>
                                </tr>
                                <tr>
                                    <td scope="col">Action</td>
                                    <td scope="col">Status</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="modal-footer">
                        <button type="button" class="btn btn-success reInstall" onclick="reInstall()">Re-install</button>
                        <button type="button" class="btn btn-primary cancleReinstall" onclick="cancleReInstall()" style="display: none;">Cancle Re-install</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>


        <div class="card serviceinfo">
            <h5 class="card-header">' . self::escapeHtml($LANG["serviceInfo"]) . '</h5>
            <div class="card-body">';
        if (isset($serverInfo["httpcode"]) && $serverInfo["httpcode"] != 200) {
            $html = '<div class="alert alert-danger" role="alert">Error: ' . self::escapeHtml($serverInfo["result"]->message) . '</div>';
            return $html;
        } elseif ($cardBody) {

            $serverInfo = $serverInfo["result"];
            $html = '<div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["state"]) . '</div>';
            if ($serverInfo->state == "ok" || $serverInfo->state == "running") {
                $html .= '<div><span class="badge badge-success">Running</span></div>';
            } else {
                $html .= '<div><span class="badge badge-danger">' . self::escapeHtml($serverInfo->state) . '</span></div>';
            }
            $html .=
                '</div> 
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["name"]) . '</div>
                    <div class="">' . self::escapeHtml($serverInfo->name) . ' ('. self::escapeHtml($serverInfo->iam->displayName) .')'.'</div>
                </div>  
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["ip"]) . '</div>
                    <div class="">' . self::escapeHtml($serverInfo->ip) . '</div>
                </div> 
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["serverId"]) . '</div>
                    <div class="">' . self::escapeHtml($serverInfo->serverId) . '</div>
                </div>   
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["os"]) . '</div>';
            if ($serverInfo->os == "none_64") {
                $html .= '<div>' . self::escapeHtml($LANG["installOsMessage"]) . '</div>';
            } else {
                $html .= '<div class="">' .  self::escapeHtml(ucfirst(str_replace("-"," ",str_replace("_"," ", $serverInfo->os)))). ' &nbsp;&nbsp; <span> <button type="button" class="btn btn-primary getCompatibaleOS" data-toggle="modal" data-target="#installOs">Re-install</button> </span></div>

            ';
            }
            $html .= '</div>  
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["reverse"]) . '</div>
                    <div class="">' . self::escapeHtml($serverInfo->reverse) . '</div>
                </div> 
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["disk"]) . '</div>
                    <div class="">' . self::escapeHtml($hardwareInfo->diskGroups[0]->description) . '</div>
                </div> 
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["cpu"]) . '</div>
                    <div class="">' . self::escapeHtml($processor) . '</div>
                </div> 

                <div class="adminServerInfo">
                        <div class="">' . self::escapeHtml($LANG["datacenter"]) . '</div>
                        <div class="">' . self::escapeHtml(strtoupper($serverInfo->datacenter)) . '</div>
                </div>  
                <div class="adminServerInfo">
                        <div class="">' . self::escapeHtml($LANG["prefessionalUse"]) . '</div>';
            if ($serverInfo->monitoring) {
                $html .= '<div><span class="badge badge-success">Enabled</span></div>';
            } else {
                $html .= '<div><span class="badge badge-danger">Disabled</span></div>';
            }
            $html .= '</div>  
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["supportLevel"]) . '</div>
                    <div class="">' . self::escapeHtml(ucfirst($serverInfo->supportLevel)) . '</div>
                </div>  
                 
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["commercialRange"]) . '</div>
                    <div class="">' . self::escapeHtml($serverInfo->commercialRange) . '</div>
                </div>  

        
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["bootId"]) . '</div>
                    <div class="">' . self::escapeHtml($serverInfo->bootId) . '</div>
                </div>  
              
                <div class="adminServerInfo">
                        <div class="">' . self::escapeHtml($LANG["monitoring"]) . '</div>';

            if ($serverInfo->monitoring) {
                $html .= '<div><span class="badge badge-success">Enabled</span></div>';
            } else {
                $html .= '<div><span class="badge badge-danger">Disabled</span></div>';
            }

            $html .= '</div>  
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["rack"]) . '</div>
                    <div class="">' . self::escapeHtml($serverInfo->rack) . '</div>
                </div>   
                <div class="adminServerInfo">
                    <div class="">' . self::escapeHtml($LANG["linkSpeed"]) . '</div>
                    <div class="">' . self::escapeHtml($serverInfo->linkSpeed) . '</div>
                </div> ';
        }

        $html .= '</div>
      </div>';

        return $html;
    }


    public function ipsInfoHtml($allIpDetails, $ipsErrors, $LANG, $cardBody = false)
    {
        $html = '
        <div class="card ipsInfo">
            <h5 class="card-header">' . self::escapeHtml($LANG["ip"]) . '</h5>
            <div class="card-body">';
        if ($ipsErrors != "") {
            $html = '<div class="alert alert-danger" role="alert">Error: ' . self::escapeHtml($ipsErrors) . '</div>';
            return $html;
        } else {

            $html.= '<table id="ipsDetails" class="display" style="width:100%">
                    <thead>
                        <tr>
                            <th>' . self::escapeHtml($LANG["ip"]) . '</th>
                            <th>' . self::escapeHtml($LANG["ipVersion"]) . '</th>
                            <th>' . self::escapeHtml($LANG["ipMask"]) . '</th>
                            <th>' . self::escapeHtml($LANG["reverseDns"]) . '</th>
                            <th>Firewall</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>';
            if ($cardBody) {
                $html='';
                foreach ($allIpDetails as $allIpDetail) {
                    $ip = str_replace("::", "", explode("/", $allIpDetail->ip));
                    $reverseDNS = (isset($allIpDetail->reverseIp->message) ? "Not Configured" : $allIpDetail->reverseIp->reverse);
                    $html.= '              
                        <tr data-ip="'. self::escapeHtml($allIpDetail->ip) .'">
                            <td>' . self::escapeHtml($ip[0]) . '</td>
                            <td>v' . self::escapeHtml($allIpDetail->version) . '</td>
                            <td>' . self::escapeHtml($ip[1]) . '</td>
                            <td class="reverseDns">' . self::escapeHtml($reverseDNS) . '</td>';

                            if ($allIpDetail->version != 6) {
                                if (isset($allIpDetail->firewallDetails->message)) {
                                    $html .= '<td><button type="button" class="btn btn-primary createFirewall">' . self::escapeHtml($LANG["btnCreateFirewall"]) . '</button></td>';
                                } else if ($allIpDetail->firewallDetails->enabled) {
                                    $html .= '<td class="disable_firewall">
                                        <label class="switch">
                                            <input type="checkbox" class="firewallEnableDisable" data-action="enable" checked>
                                            <span class="slider round"></span>
                                        </label></td>';
                                } else {
                                    $html .= '<td class="disable_firewall">
                                                        <label class="switch">
                                                        <input type="checkbox" class="firewallEnableDisable" data-action="disable">
                                                        <span class="slider round"></span>
                                                        </label>
                                                    </td>';
                                }
                            } else {
                                $html .= '<td> </td>';
                            }

                            
                            $html .= '
                            <td>';
                            if ($allIpDetail->version == 6) {
                                $html .= ' 
                                    <button type="button"><i class="fa fa-eye viewIpDetails" data-toggle="modal" data-target="#viewIpDetails"></i></button>
                                    <button type="button" class="ipAction"><i class="fas fa-ellipsis-h"></i></button>
                            
                                    <div class="ipActionLists" data-ipBlock="' . self::escapeHtml($allIpDetail->ip) . '">
                                        <ul>
                                            <li data-toggle="modal" data-target="#addIpDescriptions" data-desc="' . self::escapeHtml($allIpDetail->description) . '">Add a description</li>
                                            <li>View/Edit IP block information</li>
                                            <li>Select an IPv6</li>
                                            
                                        </ul>
                                    </div>';
                            } else {
                                $html .= ' 
                                    <button type="button"><i class="fa fa-eye viewIpDetails" data-toggle="modal" data-target="#viewIpDetails"></i></button>
                                    <button type="button" class="ipAction"><i class="fas fa-ellipsis-h"></i></button>
                                    <div class="ipActionLists" data-ipBlock="' . self::escapeHtml($allIpDetail->ip) . '">
                                        <ul>';
                                if ($allIpDetail->description) {
                                    $html .= '<li data-toggle="modal" data-target="#addIpDescriptions" data-desc="' . self::escapeHtml($allIpDetail->description) . '">Edit a description</li>';
                                } else {
                                    $html .= '<li data-toggle="modal" data-target="#addIpDescriptions" data-desc="' . self::escapeHtml($allIpDetail->description) . '">Add a description</li>';
                                }
                                $html .= '<li data-toggle="modal" data-target="#addReverseIp">Modify the reverse path</li>
                                            <li class="mitigration" data-mitigration="mitigration" data-mitigrationIp="' . self::escapeHtml($allIpDetail->mitigationIp) . '">Scrubbling Center: permanent</li>';
                                            if(!isset($allIpDetail->firewallDetails->message)){
                                                $html.='<li class="deleteFirewall">Delete Network Firewall</li>';
                                            }
                                            $html.='<li data-toggle="modal" data-target="#getFirewallRules" role="button">Edge Network Firewall configuration</li>
                                        </ul>
                                    </div>';
                            }
                            $html .= '</td>
                            </tr>';
                }
                return $html;
            }
            $html .= '</tbody>
                </table>';
        }
        $html .= '</div>
        </div>    
            ';

        return $html;
    }


    public function renewHtml()
    {
        $html = '
        <table width=100%>
            <tr>
                <td style="text-align:left;">automatic</td>
                <td>&nbsp;</td>
                <td style="text-align:left;"><input type="checkbox" name="automatic" value="1"></td>
            </tr>

            <tr>
                <td style="text-align:left;">deleteAtExpiration</td>
                <td>&nbsp;</td>
                <td style="text-align:left;"><input type="checkbox" name="deleteAtExpiration" value="1"></td>
            </tr>
            <tr>
                <td style="text-align:left;">forced</td>
                <td>&nbsp;</td>
                <td style="text-align:left;"><input type="checkbox" name="forced" value="1"></td>
            </tr>
            <tr>
                <td style="text-align:left;">period</td>
                <td>&nbsp;</td>
                <td style="text-align:left;" >
                    <select name="period1" class="form-control select-inline">
                        <option value="">Choose</option>
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="6">6</option>
                        <option value="12">12</option>
                    </select>
                </td>
            </tr>
        </table>';
        return $html;
    }

    public function getSPLALicense()
    {
        $html = '
        <table width=100%>
            <tr>
                <td style="text-align:left;">SerialNumber</td>
                <td>&nbsp;</td>
                <td style="text-align:left;"><input type="text" name="serialNumber" value="" class="form-control input-200"></td>
            </tr>

            <tr>
                <td style="text-align:left;">Type</td>
                <td>&nbsp;</td>
                <td style="text-align:left;">
                    <select name="ostype" class="form-control select-inline">
                        <option>Choose</option>
                        <option value="os">os</option>
                        <option value="sqlstd">sqlstd</option>
                        <option value="sqlweb">sqlweb</option>
                    </select>
                </td>
            </tr>
        </table>';

        return $html;
    }


    public function cretaeHardwareInfoHtml($params, $LANG)
    {
        $html = '';
        if ($params["httpcode"] != 200) {
            $html .= '<div class="alert alert-danger" role="alert">' . self::escapeHtml($params["result"]->message) . '</div>';
            return $html;
        }
        $data = $params["result"];

        $html .= '
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["memorySize"]) . '</strong>
            </div>
            <div class="col">
                <span>' . self::escapeHtml($data->memorySize->value) . ' ' . self::escapeHtml($data->memorySize->unit) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["processorArchitecture"]) . '</strong>
            </div>
            <div class="col">
                <span>' . self::escapeHtml($data->processorArchitecture) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["diskGroups"]) . '</strong>
            </div>
            <div class="col">
                <span>No of Disk : ' . self::escapeHtml($data->diskGroups[0]->numberOfDisks) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
               
            </div>
            <div class="col">
                <span>Disk Type : ' . self::escapeHtml($data->diskGroups[0]->diskType) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
               
            </div>
            <div class="col">
                <span>Disk Size : ' . self::escapeHtml($data->diskGroups[0]->diskSize->value) . ' ' . self::escapeHtml($data->diskGroups[0]->diskSize->unit) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["raidController"]) . '</strong>
            </div>
            <div class="col">
                <span>' . self::escapeHtml($data->diskGroups[0]->raidController) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["processorName"]) . '</strong>
            </div>
            <div class="col">
                <span>' . self::escapeHtml($data->processorName) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["numberOfProcessors"]) . '</strong>
            </div>
            <div class="col">
                <span>' . self::escapeHtml($data->numberOfProcessors) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["coresPerProcessor"]) . '</strong>
            </div>
            <div class="col">
                <span>' . self::escapeHtml($data->coresPerProcessor) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["usbKeys"]) . '</strong>
            </div>
            <div class="col">
                <span>' . self::escapeHtml($data->usbKeys) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["defaultHardwareRaidSize"]) . '</strong>
            </div>
            <div class="col">
                <span>' . self::escapeHtml($data->diskGroups[0]->defaultHardwareRaidSize) . '</span>
            </div> 
        </div>
        <div class="row">
            <div class="col-3">
                <strong>' . self::escapeHtml($LANG["motherboard"]) . '</strong>
            </div>
            <div class="col">
                <span>' . self::escapeHtml($data->motherboard) . '</span>
            </div> 
        </div>
        ';
        return $html;
    }
    public function cretaeServerInfoHtml($params, $LANG)
    {
        $html = '';
        if ($params["httpcode"] != 200) {
            $html .= '<div class="alert alert-danger" role="alert">' . self::escapeHtml($params["result"]->message) . '</div>';
            return $html;
        }
        $data = $params["result"];

        $html .= '
        <div class="row">
            <div class="col-2">
                <strong>' . self::escapeHtml($LANG["datacenter"]) . '</strong>
            </div>
            <div class="col-4">
                <span>' . self::escapeHtml($data->datacenter) . '</span>
            </div>
            <div class="col"></div>
        </div>
        <div class="row">
            <div class="col-2">
                <strong>' . self::escapeHtml($LANG["ip"]) . '</strong>
            </div>
            <div class="col-4">
                <span>' . self::escapeHtml($data->ip) . '</span>
            </div>
            <div class="col"></div>
        </div>
        <div class="row">
            <div class="col-2">
                <strong>' . self::escapeHtml($LANG["name"]) . '</strong>
            </div>
            <div class="col-4">
                <span>' . self::escapeHtml($data->name) . '</span>
            </div>
            <div class="col"></div>
        </div>
        <div class="row">
            <div class="col-2">
                <strong>' . self::escapeHtml($LANG["os"]) . '</strong>
            </div>
            <div class="col-4">
                <span>' . self::escapeHtml($data->os) . '</span>
            </div>
            <div class="col"></div>
        </div>
        <div class="row">
            <div class="col-2">
                <strong>' . self::escapeHtml($LANG["state"]) . '</strong>
            </div>
            <div class="col-4">';
        if ($data->state == "ok" || $data->state == "running") {
            $html .= '<span class="label label-success">' . self::escapeHtml($data->state) . '</span>';
        } else {
            $html .= '<span class="label label-danger">' . self::escapeHtml($data->state) . '</span>';
        }

        $html .= '</div>
            <div class="col"></div>
        </div>
        <div class="row">
            <div class="col-2">
                <strong>' . self::escapeHtml($LANG["reverse"]) . '</strong>
            </div>
            <div class="col-4">
                <span>' . self::escapeHtml($data->reverse) . '</span>
            </div>
            <div class="col"></div>
        </div>
        <div class="row">
            <div class="col-2">
                <strong>' . self::escapeHtml($LANG["monitoring"]) . '</strong>
            </div>
            <div class="col-4">';
        if ($data->monitoring == "") {
            $html .= '<span>Disabled</span>';
        } else {
            $html .= '<span>' . self::escapeHtml($data->monitoring) . '</span>';
        }
        $html .= '</div>
            <div class="col"></div>
        </div>
        <div class="row">
            <div class="col-2">
                <strong>' . self::escapeHtml($LANG["linkSpeed"]) . '</strong>
            </div>
            <div class="col-4">
                <span>' . self::escapeHtml($data->linkSpeed) . '</span>
            </div>
            <div class="col"></div>
        </div>
        ';
        return $html;
    }


    public function cretaeInterventionHtml($params, $LANG)
    {

        $html = '
        <div class="row">
            <div class="col-12" style="padding: 0;">
                <h2 class="interventionheading">Tech Intervention History</h2>
            </div>
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
           ';

        foreach ($params as $value) {
            $dateTime = new \DateTime("$value->date");
            $readableFormat = $dateTime->format('D, Y-m-d H:i:s');
            $html .= '
                    <tr>
                        <td scope="row">' . self::escapeHtml($readableFormat) . '</td>
                        <td>' . self::escapeHtml($value->type) . '</td>
                    </tr>
                    
               ';
        }
        $html .= ' </tbody>
        </table>
        <div>';

        return $html;
    }


    public function createFtpBackupHtml($ftps, $FTPblockips, $LANG, $ovhServerName, $customHostName, $ovhCustomFTPHostname, $location, $accountId, $apiCall)
    {
        $ftpBackupName = ($ovhCustomFTPHostname == "" ? $ftps->ftpBackupName : $ovhCustomFTPHostname);
        $hostName = ($customHostName == "" ? $ovhServerName : $customHostName);

        $html = '
        <div class="ftpMainDiv">
        <div class="ftp-bkp-main margin">
            <div class="col-12">
                <h2 class="ftpBackupheading">' . self::escapeHtml($LANG["bkuphead"]) . '</h2>
            </div>
            <div class="row">
                <div class="col-2">
                    <span class="">' . self::escapeHtml($LANG["nameftp"]) . '</span>
                </div>
                <div class="col text-left">
                    <span class="">' . self::escapeHtml($ftpBackupName) . '</span>
                </div>
            </div>
            <div class="row">
                <div class="col-2">
                    <span class="">' . self::escapeHtml($LANG["server_name"]) . '</span>
                </div>
                <div class="col text-left">
                    <span class="server_name"> ' . self::escapeHtml($hostName) . '';

        $quota = "0 GB";
        $percent = 0;
        $usage = 0;
        $totalUsg = 0;
        if (isset($ftps->quota)) {
            $quota = $ftps->quota->value . " " . $ftps->quota->unit;
            $usage = ($ftps->usage == "" ? 0 : $ftps->usage);
            $totalUsg =  ($usage / 100);
            $totalUsg = round($totalUsg, 2);
            $percent = $usage;
        }


        $html .= '</span>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-2">
                    <span class="">' . self::escapeHtml($LANG["diskUsage"]) . '</span>
                </div>
                <div class="col text-left">
                    <div class="progress">
                        <div class="progress-done" data-done="' . self::escapeHtml($percent) . '">' . self::escapeHtml($percent) . '</div>
                    </div>
                    <small>' . self::escapeHtml($totalUsg) . '  of ' . self::escapeHtml($quota) . '</small>
                </div>
            </div>

            <div class="row mt-3">
            <div class="col-2"></div>
                <div class="col-10 text-left">
                    <div class="ftpBackupactionBtn">
                   
                    ';
        if ($ftps->message != 'The requested object (backupFTP) does not exist') {
            $html .= '
            <button type="button" style="font-size:12px;" class="btn btn-primary" id="ftpbkup" data-toggle="modal" data-target="#enableACLFtp">' . self::escapeHtml($LANG['create_aclbtn']) . '</button>
            <button id="deleteBkup" class="btn btn-danger" style="font-size:12px;" onclick="deleteFTPBackup();">' . self::escapeHtml($LANG['deletebkup']) . '</button>
            <button id="changeFtppass" class="btn btn-success" style="font-size:12px;" onclick="changepass();">' . self::escapeHtml($LANG['changeftpbtn']) . '</button>

            ';
        } else {
            $html .= '
            <button id="enableFTPBackup" class="btn btn-info"  onclick="enableFTPBackup();" style="font-size:12px;">' . self::escapeHtml($LANG['enablestoragebtn']) . '</button>';
        }
        $html .= '
                    </div>
                    ';

        $html .= '</div>
            </div>
        </div>

        <div class="backupList">

            <div class="col-12">
                <h2 class="interventionheading">' . self::escapeHtml($LANG["bkupstorage"]) . '</h2>
            </div>';
        if (isset($FTPblockips->message)) {
            $html .= '<div class="alert alert-warning" role="alert" style="width: 100%;">
                ' . $LANG["enableFTPMessage"] . '
                </div>';
        } else {
            $html .= '
            <div class="table-responsive">
            <table id="clientAreaFTPmang" class="display table-hover" style="width:100%">
            <thead>
                <tr>
                    <th>' . self::escapeHtml($LANG["ftpDetailsIpBlock"]) . '</th>
                    <th>' . self::escapeHtml($LANG["ftpDetailFTP"]) . '</th>
                    <th>' . self::escapeHtml($LANG["ftpDetailNFS"]) . '</th>
                    <th>' . self::escapeHtml($LANG["ftpDetailCIFS"]) . '</th>
                    <th>' . self::escapeHtml($LANG["ftpDetailStaus"]) . '</th>
                    <th>' . self::escapeHtml($LANG["ftpDetailActions"]) . '</th>
                </tr>
            </thead>
                <tbody>';
            foreach ($FTPblockips as $key => $ipBlock) {
                $ipBlock = urlencode($ipBlock);

                
                $response = $apiCall->get("/dedicated/server/{$ovhServerName}/features/backupFTP/access/{$ipBlock}", $accountId, $location, "Getting FTPs Blocke IP details", true);
                // $response = $apiCall->getFTPblockipsDetails($location, $ovhServerName, $accountId, $ip);


                $ftpBlockIPDetail = $this->createFtpBlockIPDetailHtml($response["result"], $LANG);
                $html .= $ftpBlockIPDetail;
            }
            $html .= '</tbody>
            </table>
            </div>';
            $html .= '</div>';
        }
        return $html;
    }

    public function createPowerHtml($LANG, $type = null, $ovhCustomHostname ='', $data =[])
    {

        $html = '';
        if (!$type) {
            $html .= '<div class="powerTab parrentTab">
            <div class="sub_box" data-type="reboot" onclick="getPowerDetails(this)">
                <a href="javascript:void(0);" class="active">' . self::escapeHtml($LANG["reboot"]) . ' </a>
            </div>
            <div class="sub_box" data-type="reInstall" onclick="getPowerDetails(this)">
                <a href="javascript:void(0);" class="">' . self::escapeHtml($LANG["reInstall"]) . ' </a>
            </div>
            <div class="sub_box" data-type="netBoot" onclick="getPowerDetails(this)">
                <a href="javascript:void(0);" class=""> ' . self::escapeHtml($LANG["netBoot"]) . ' </a>
            </div>
        </div>
        <div id="powerResponce">
                <p class="reboot">' . self::escapeHtml($LANG["reboot_msg"]) . ' </p>
                <button class="btn btn-success rebootBtn">' . self::escapeHtml($LANG["reboot"]) . ' </button>
            </div>
        ';
        }
        if ($type == "reboot")
            $html .= '            
                <p>' . self::escapeHtml($LANG["reboot_msg"]) . ' </p>
                <button class="btn btn-success rebootBtn">' . self::escapeHtml($LANG["reboot"]) . ' </button>
          
        ';
        if ($type == "netBoot")
            $html .= '            
                <div class="powerTab mt-3 ml-4">
                    <div class="sub_box" data-type="hardDisk" onclick="getPowerDetails(this)">
                        <a href="javascript:void(0);" class="active">' . self::escapeHtml($LANG["hardDisk"]) . '</a>
                    </div>
                    <div class="sub_box" data-type="network" onclick="getPowerDetails(this)">
                        <a href="javascript:void(0);" class="">' . self::escapeHtml($LANG["network"]) . '</a>
                    </div>
                    <div class="sub_box" data-type="rescue" onclick="getPowerDetails(this)">
                        <a href="javascript:void(0);" class="">' . self::escapeHtml($LANG["rescue"]) . '</a>
                    </div>
                </div>
                <div id="subPowerResponce">
                    <p class="netboot p-5">' . self::escapeHtml($LANG["netbootmsg"]) . ' </p>
                    <button class="btn btn-success netbootBtn">' . self::escapeHtml($LANG["bootnow_btn"]) . ' </button>
                </div>';

        if ($type == "hardDisk")
            $html .= '            
            <p class="netboot p-5">' . self::escapeHtml($LANG["netbootmsg"]) . ' </p>
            <button class="btn btn-success netbootBtn">' . self::escapeHtml($LANG["bootnow_btn"]) . ' </button>
        ';
        return $html;
    }


    public function createIpListHtml($ips = [], $apiCall= null, $location ='', $ovhServerName ='', $accountId ='', $LANG =[])
    {
        if (empty($ips)) {
            return '<div class="alert alert-warning" role="alert">
                No IPs assing with the server!
          </div>';
        }

        $html = '
        <button class="btn btn-primary mb-4" data-toggle="modal" data-target="#additinalIpModalCenter" style="float: right;"> Additional IP <i class="fa fa-plus"></i></button>';
        $html .= '
        <div class="table-responsive">
        <table id="clientAreaIpmang" class="display table-hover" style="width:100%; text-align: center;">
        <thead>
            <tr>
                <th>' . self::escapeHtml($LANG["ip"]) . '</th>
                <th>' . self::escapeHtml($LANG["reverseDns"]) . '</th>
                <th>' . self::escapeHtml($LANG["firewall"]) . '</th>
                <th>' . self::escapeHtml($LANG["action"]) . '</th>
            </tr>
        </thead>
        <tbody>';
        foreach ($ips as $key => $ip) {
            $response = $apiCall->getIpDetails($location, $ovhServerName, $accountId, $ip);

            if ($response["httpcode"] != 200) {
                $html .= '<tr><td colspan="4">' . self::escapeHtml($response["result"]->message) . '</td></tr>';
                continue;
            }

            $ipDetail = $response["result"];
            $reverseDNS = (isset($ipDetail->reverseIp->message) ? "Not Configured" : $ipDetail->reverseIp->reverse);
            $html .= '<tr data-ip="' . self::escapeHtml($ipDetail->ip) . '">
            <td>' . self::escapeHtml($ipDetail->ip) . '</td>
            <td class="reverseDns">' . self::escapeHtml($reverseDNS) . '</td>';
            if ($ipDetail->version != 6) {
                if (isset($ipDetail->firewallDetails->message)) {
                    $html .= '<td><button class="btn btn-primary createFirewall">' . self::escapeHtml($LANG["btnCreateFirewall"]) . '</button></td>';
                } else if ($ipDetail->firewallDetails->enabled) {
                    $html .= '<td class="disable_firewall">
                        <label class="switch">
                            <input type="checkbox" id="firewallEnableDisable" class="firewallEnableDisable" data-action="enable" checked>
                            <span class="slider round"></span>
                        </label></td>';
                } else {
                    $html .= '<td class="disable_firewall">
                                        <label class="switch">
                                        <input type="checkbox" id="firewallEnableDisable" class="firewallEnableDisable" data-action="disable">
                                        <span class="slider round"></span>
                                        </label>
                                    </td>';
                }
            } else {
                $html .= '<td> </td>';
            }
            $html .= '
            <td>';
            if ($ipDetail->version == 6) {
                $html .= ' 
                    <button><i class="fa fa-eye viewIpDetails" data-toggle="modal" data-target="#viewIpDetails"></i></button>
                    <button class="ipAction" type="button"><i class="fas fa-ellipsis-h"></i></button>
            
                    <div class="ipActionLists" data-ipBlock="' . self::escapeHtml($ipDetail->ip) . '">
                        <ul>
                            <li data-toggle="modal" data-target="#addIpDescriptions" data-desc="' . self::escapeHtml($ipDetail->description) . '">Add a description</li>
                             <li data-toggle="modal" data-target="#addReverseIp6">Select an IPv6</li>
                            
                        </ul>
                    </div>';
            } else {
                $html .= ' 
                    <button><i class="fa fa-eye viewIpDetails" data-toggle="modal" data-target="#viewIpDetails"></i></button>
                    <button class="ipAction" type="button"><i class="fas fa-ellipsis-h"></i></button>
                    <div class="ipActionLists" data-ipBlock="' . self::escapeHtml($ipDetail->ip) . '">
                        <ul>';
                if ($ipDetail->description) {
                    $html .= '<li data-toggle="modal" data-target="#addIpDescriptions" data-desc="' . self::escapeHtml($ipDetail->description) . '">Edit a description</li>';
                } else {
                    $html .= '<li data-toggle="modal" data-target="#addIpDescriptions" data-desc="' . self::escapeHtml($ipDetail->description) . '">Add a description</li>';
                }
                $html .= '<li data-toggle="modal" data-target="#addReverseIp">Modify the reverse path</li>
                            <li class="mitigration" data-mitigration="mitigration" data-mitigrationIp="' . self::escapeHtml($ipDetail->mitigationIp) . '">Scrubbling Center: permanent</li>
                            <li class="deleteFirewall">Delete Network Firewall</li>
                            <li data-toggle="modal" data-target="#getFirewallRules" role="button">Edge Network Firewall configuration</li>
                        </ul>
                    </div>';
            }
            $html .= '</td>
            </tr>';
        }
        $html .= '</tbody>
        </table>
        </div>';
        return $html;
    }


    public function createIpDetailsHtml($ipDetail, $LANG)
    {
        if ($ipDetail["httpcode"] != 200) {
            return '<div class="alert alert-warning" role="alert">' . self::escapeHtml($ipDetail["result"]->message) . '</div>';
        }

        $ipDetail = $ipDetail["result"];
        $html = '<div class="row">
            <div class="col-3"><strong>' . self::escapeHtml($LANG["ipDetailModalIp"]) . '</strong></div>
            <div class="col"><span>' . self::escapeHtml($ipDetail->ip) . '</span></div>
        </div>
        <div class="row">
            <div class="col-3"><strong>' . self::escapeHtml($LANG["ipDetailModalVersion"]) . '</strong></div>
            <div class="col"><span>IPv' . self::escapeHtml($ipDetail->version) . '</span></div>
        </div>
        <div class="row">
            <div class="col-3"><strong>' . self::escapeHtml($LANG["ipDetailModalDesc"]) . '</strong></div>
            <div class="col"><span>' . self::escapeHtml($ipDetail->description) . '</span></div>
        </div>
        <div class="row">
            <div class="col-3"><strong>' . self::escapeHtml($LANG["ipDetailModalCampus"]) . '</strong></div>
            <div class="col"><span>' . self::escapeHtml($ipDetail->campus) . '</span></div>
        </div>
        <div class="row">
            <div class="col-3"><strong>' . self::escapeHtml($LANG["ipDetailModalResion"]) . '</strong></div>
            <div class="col"><span>' . self::escapeHtml($ipDetail->regions[0]) . '</span></div>
        </div>
        <div class="row">
            <div class="col-3"><strong>' . self::escapeHtml($LANG["ipDetailModalType"]) . '</strong></div>
            <div class="col"><span>' . self::escapeHtml(ucfirst($ipDetail->type)) . '</span></div>
        </div>
        <div class="row">
            <div class="col-3"><strong>' . self::escapeHtml($LANG["ipDetailModalMacaddress"]) . '</strong></div>
            <div class="col-9">
            <textarea class="form-control" id="exampleFormControlTextarea3" rows="3">'.self::escapeHtml(empty($ipDetail->macaddress) ? "No Mac Address" : (is_array($ipDetail->macaddress) ? implode(",", $ipDetail->macaddress) : $ipDetail->macaddress)).'</textarea>
            </div>
          
        </div>
        <div class="row">
            <div class="col-3"><strong>' . self::escapeHtml($LANG["ipDetailModalFirewall"]) . '</strong></div>';

        if (isset($ipDetail->firewallDetails->enabled) && $ipDetail->firewallDetails->enabled) {
            $html .= '<div class="col"><span class="label label-success">Enable</span></div>';
        }else{
            $html .= '<div class="col"><span class="label label-danger">Disable</span></div>';
        }

        $html .= '</div>
        
        
        ';

        return $html;
    }
    public function createFtpBlockIPDetailHtml($ipDetail, $LANG)
    {
        $html = '';
        $html .= '
            <tr>
                <td class="ipBlock" data-ipBlock="' . self::escapeHtml($ipDetail->ipBlock) . '">' . self::escapeHtml($ipDetail->ipBlock) . '</td>';
        if ($ipDetail->ftp) {
            $html .= '<td class="ftp" data-ftp="enabled"><i class="fa fa-check"></i></td>';
        } else {
            $html .= '<td class="ftp" data-ftp="disabled"><i class="fa fa-ban"></i></td>';
        }
        if ($ipDetail->nfs) {
            $html .= '<td class="nfs" data-nfs="enabled"><i class="fa fa-check"></i></td>';
        } else {
            $html .= '<td class="nfs" data-nfs="disabled"><i class="fa fa-ban"></i></td>';
        }
        if ($ipDetail->cifs) {
            $html .= '<td class="cifs" data-cifs="enabled"><i class="fa fa-check"></i></td>';
        } else {
            $html .= '<td  class="cifs" data-cifs="disabled"><i class="fa fa-ban"></i></td>';
        }
        if ($ipDetail->isApplied) {
            $html .= '<td class="status"><span class="label label-success">Enabled</span></td>';
        } else {
            $html .= '<td class="status"><span class="label label-danger">Disabled</span></td>';
        }
        $html .= '<td>
                    <button><i class="fas fa-edit editFTP" data-toggle="modal" data-target="#editFTP"></i></button>
                    <button> <i class="fas fa-trash-alt deleteFTP"></i></button>
                    </td>
            </tr>';
        return $html;
    }

    public function createACLBlockIPs($FTPblockips, $authorizableBlocks)
    {

        $html = '';

        foreach ($authorizableBlocks as $key => $value) {
            $disable = '';
            if (in_array($value, $FTPblockips)) {
                $disable = "disabled";
            }
            $html .= '<option value="' . self::escapeHtml($value) . '" ' . self::escapeHtml($disable) . '>' . self::escapeHtml($value) . '</option>';
        }

        return ["httpcode" => 200, "response" => $html];
    }


    public function createFirewallRulesTable($data)
    {

        $html = '
        <tr>
            <td>' . self::escapeHtml($data->sequence) . '</td>
            <td>' . self::escapeHtml($data->action) . '</td>
            <td>' . self::escapeHtml($data->protocol) . '</td>
            <td>' . self::escapeHtml($data->destination) . '</td>
            <td>' . self::escapeHtml($data->sourcePort) . '</td>
            <td>' . self::escapeHtml($data->tcpOption) . '</td>
            <td>' . self::escapeHtml($data->state) . '</td>
        </tr>';


        return $html;
    }


    function seachData($string, $searchString)
    {
        $searchStringPosition = strpos($string, $searchString);

        $text = false;
        if ($searchStringPosition !== false) {
            $searchStrStart = $searchStringPosition + strlen($searchString);
            $searchStrEnd = strpos($string, "</li>", $searchStrStart);

            if ($searchStrEnd == false) {
                $text = trim(substr($string, $searchStrStart));
                return $text;
            }
            $text = trim(substr($string, $searchStrStart, $searchStrEnd - $searchStrStart));
            return $text;
        } else {
            return $text;
        }
    }



    public function featureHtml($features, $serviceId)
    {
        $enableSettings = $this->addonHelper->get_data("mod_acl_settings", ["key" => "servicePageAclSettings_$serviceId"]);

        $enableSettingsResult = [];
        foreach ($enableSettings as $key => $value) {
            $enableSettingsResult[$value->key] = !empty($value) ? json_decode($value->value, true) : [];
        }
        $enableSettingsResult = $enableSettingsResult["servicePageAclSettings_$serviceId"] ?? [];
        $html = '
        <div class="card">
            <h5 class="card-header">Hide clientarea features for this service</h5>
            <div class="card-body">
                <div class="row">';
        foreach ($features as $key => $value) {
            $checked = ((($enableSettingsResult[str_replace(' ', '_', $value)] ?? '') == "on") ? "checked" : "");
            $html .= '
                            <div class="col-md-4">
                                <div class="form-group"><input type="checkbox" name="' . self::escapeHtml($value) . '" ' . self::escapeHtml($checked) . '> ' . self::escapeHtml($value) . '
                                </div>
                            </div>';
        }
        $html .= '
                </div>
            </div>
        </div>';

        return $html;
    }
}
