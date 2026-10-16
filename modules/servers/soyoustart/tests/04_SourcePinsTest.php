<?php
require __DIR__ . '/bootstrap.php';
$pass=0;$fail=0;
function check($s,$b){global $pass,$fail;if($b){$pass++;}else{$fail++;echo "FAIL: $s\n";}}
$root=dirname(__DIR__);
$entry=file_get_contents($root.'/soyoustart.php');$ajax=file_get_contents($root.'/ajax/ajax.php');$lib=file_get_contents($root.'/lib/SoyoustartServer.php');$hooks=file_get_contents($root.'/hooks.php');$tpl=file_get_contents($root.'/templates/clientareanew.tpl');
$p=strpos($ajax, "empty(\$_SESSION['adminid'])");
check('auth gate before ApiCall',$p!==false && strpos($ajax,'$apiCall = new ApiCall()')>$p);
check('POST gate',strpos($ajax,"REQUEST_METHOD'] ?? '') !== 'POST'")!==false);
check('numeric service ID',strpos($ajax,"ctype_digit((string) \$_POST['serviceId'])")!==false);
check('service ownership joined',strpos($ajax,"where('tblcustomfieldsvalues.relid', \$serviceId)")!==false && strpos($ajax,"where('tblcustomfields.relid', \$product->id)")!==false);
foreach(['accountId','ovhServerName','location'] as $key) check('cannot supply '.$key,strpos($ajax,'$'.$key.' = $whmcs->get_req_var("'.$key.'")')===false);
check('no default cancel task 12345',strpos($ajax,'12345')===false && strpos($ajax,'if ($taskId < 1)')!==false);
check('standalone OS options escape',strpos($ajax,'htmlspecialchars((string) $value, ENT_QUOTES')!==false);
check('four service-bound admin requests',substr_count($lib,'data: { serviceId: ')===4 && substr_count($lib,'/soyoustart/ajax/ajax.php')===4);
check('no account inserted into JS',strpos($lib,'accountId:"')===false);
check('hook datatable POST and serviceId',strpos($hooks,'"type": "POST"')!==false && strpos($hooks,"serviceId: ' . (int) \$serviceId")!==false);
check('all client IP actions scoped',strpos($entry,'soyoustart_ipBelongsToServer($ownedIps, $requestedIp)')!==false && strpos($entry,"\$ipAction !== 'addAdditionalIp'")!==false);
check('dead createIPsHtml gone',strpos($entry,'createIPsHtml')===false && strpos($entry,'createIpDetailsHtml($ipdetails, $_ADDONLANG)')!==false);
check('customfield map',strpos($entry,'"ovh_server_location" => $location')!==false);
check('license status typo gone',strpos($entry,'ststus')===false);
$p=strpos($entry,'$additionalIpPrice = $helper->get_data(');
check('price defined',$p!==false && $p<strpos($entry,'"addAdditionalIp"'));
$p=strpos($entry,'!SoyoustartServer::validAdditionalIpPrice($additionalIpPrice)');
check('price/quantity fail before cart',$p!==false && $p<strpos($entry,'$apiCall->createCart($location, $planCode . "add Additional IPs"'));
check('same checked quantity forwarded',strpos($entry,"'quantity' => \$quantity")!==false);
check('unknown monitoring fails',strpos($entry,'Unknown monitoring action')!==false);
check('reboot float gone',strpos($entry,"(float)\$whmcs->get_req_var(\"bootid\")")===false);
check('all template Smarty outputs escaped',!preg_match('/\{\$[^{}]+\}/',preg_replace('/\{\$[^{}]*\|escape\}/','',$tpl)));
check('HTML interpolation no bare dollar',!preg_match('/<[^\r\n]*\.\s*\$[A-Za-z_]/',$lib));
check('lib static escape',strpos($lib,"htmlspecialchars((string) \$value, ENT_QUOTES, 'UTF-8')")!==false);
check('DB failure returns []',strpos($lib,"catch (\\Throwable \$th) {\r\n            return [];")!==false);
check('shared payment uses product type',strpos(file_get_contents($root.'/../soyoustart_vps/hooks.php'), '($params["configoption1"] ?? "")')!==false);
check('no external credential changes',strpos($entry,"require_once __DIR__ . '/../../addons/soyoustart/classes/ApiCall.php'")!==false);
foreach ([$entry,$ajax,$lib,$hooks,file_get_contents($root.'/lib/AjaxAuth.php')] as $source) {
    try { token_get_all($source, TOKEN_PARSE); check('PHP source parses',true); }
    catch (ParseError $e) { check('PHP source parses: '.$e->getMessage(),false); }
}
$growl=file_get_contents($root.'/assets/js/jquerygrowl.js');
$script=file_get_contents($root.'/assets/js/script.js');
$addonHooks=file_get_contents(dirname(dirname($root)).'/addons/soyoustart/hooks.php');
check('SD-O1 jquerygrowl escapes title and message',strpos($growl,'this.escapeHtml(this.settings.title)')!==false && strpos($growl,'this.escapeHtml(this.settings.message)')!==false);
check('SD-O1 script.js uses .text() for viewIpDetailsIpBlock and editFtpIpBlock',strpos($script,'$("#viewIpDetailsIpBlock").text(ipblock)')!==false && strpos($script,'$("#editFTP #editFtpIpBlock").text(editFtpIpBlock)')!==false);
check('SD-O2 entry enforces soyoustart_verifyCsrf and soyoustart_isFeatureDisabled',substr_count($entry,'soyoustart_verifyCsrf()')>=6 && substr_count($entry,'soyoustart_isFeatureDisabled(')>=10);
check('SD-O3 addon UpdateConfigOption guards null and uses packageid',strpos($addonHooks,'if (!is_object($additionipDatas) || empty($additionipDatas->service_id))')!==false && strpos($addonHooks,'$hostingdata->packageid')!==false);
check('SD-O6 ipinfo.io beacon removed from script.js',strpos($script,'ipinfo.io')===false);
check('SD-O6 sweetalert2 and datatables CDN URLs pinned over HTTPS',strpos($lib,'sweetalert2@11.10.5')!==false && strpos($tpl,'sweetalert2@11.10.5')!==false && strpos($tpl,'href="//cdn.datatables.net')===false && strpos($tpl,'src="//cdn.datatables.net')===false);
check('SD-12 client cancel reinstall rejects missing taskId',strpos($entry,'12345')===false);
echo "source: PASS=$pass FAIL=$fail\n";exit($fail?1:0);
