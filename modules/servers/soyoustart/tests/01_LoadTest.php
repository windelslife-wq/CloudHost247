<?php
require __DIR__ . '/bootstrap.php';
$pass=0;$fail=0;
function check($s,$b) {global $pass,$fail;if($b){$pass++;}else{$fail++;echo "FAIL: $s\n";}}
foreach (['soyoustart_MetaData','soyoustart_ConfigOptions','soyoustart_CreateAccount','soyoustart_Renew','soyoustart_AdminServicesTabFields','soyoustart_AdminServicesTabFieldsSave','soyoustart_ClientArea','soyoustart_TerminateAccount','soyoustart_rebootServer','soyoustart_cfgPart','soyoustart_firstIp','dataCenter'] as $fn) check('entry '.$fn,function_exists($fn));
check('metadata',soyoustart_MetaData()['DisplayName']==='Soyoustart');
check('buttons',soyoustart_AdminCustomButtonArray()===['Reboot'=>'rebootServer']);
foreach (['escapeHtml','validAdditionalIpPrice','createAdditionalIpInvoice','InsertIpData','createIpDetailsHtml','serviceInfoHtml','ipsInfoHtml','featureHtml','getServiceConfigs','formateBillingCycle'] as $fn) check('lib '.$fn,method_exists('WHMCS\\Module\\Server\\Soyoustart\\SoyoustartServer',$fn));
check('auth class',class_exists('WHMCS\\Module\\Server\\Soyoustart\\AjaxAuth'));
check('admin hook',isset($GLOBALS['__c5_hooks']['AdminAreaHeadOutput']));
foreach (['ApiCall','Configuration'] as $cl) check('addon '.$cl,class_exists('WGSModule\\Soyoustart\\classes\\'.$cl));
check('billing monthly',(new WHMCS\Module\Server\Soyoustart\SoyoustartServer())->formateBillingCycle('Monthly')['durationVal']==='P1M');
check('billing annual',(new WHMCS\Module\Server\Soyoustart\SoyoustartServer())->formateBillingCycle('Annually')['durationVal']==='P1Y');
echo "load: PASS=$pass FAIL=$fail\n";exit($fail?1:0);
