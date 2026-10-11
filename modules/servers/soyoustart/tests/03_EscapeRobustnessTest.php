<?php
require __DIR__ . '/bootstrap.php';
use WHMCS\Module\Server\Soyoustart\SoyoustartServer;
use WHMCS\Database\Capsule;
$pass=0;$fail=0;
function check($s,$b){global $pass,$fail;if($b){$pass++;}else{$fail++;echo "FAIL: $s\n";}}
$X='"><script>alert(1)</script>';
check('escape quote and tags',SoyoustartServer::escapeHtml($X)==='&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;');
check('escape apostrophe',SoyoustartServer::escapeHtml("a'b")==='a&#039;b');
$o=new SoyoustartServer();
$L=[];foreach (['serviceInfo','state','name','ip','serverId','os','reverse','disk','cpu','datacenter','prefessionalUse','supportLevel','commercialRange','bootId','monitoring','rack','linkSpeed','installOsHeading','installOslabel','ipVersion','ipMask','reverseDns','btnCreateFirewall','ipDetailModalIp','ipDetailModalVersion','ipDetailModalDesc','ipDetailModalCampus','ipDetailModalResion','ipDetailModalType','ipDetailModalMacaddress','ipDetailModalFirewall','bkuphead','nameftp','server_name','diskUsage','enableFTPMessage','bkupstorage','firewall','action','create_aclbtn','deletebkup','changeftpbtn'] as $key) $L[$key]=$key;
$err=['httpcode'=>500,'result'=>(object)['message'=>$X]];
$h=$o->serviceInfoHtml($err,$L);check('service error escaped',strpos($h,$X)===false && strpos($h,'&lt;script&gt;')!==false);
$info=(object)['state'=>'bad'.$X,'name'=>'name'.$X,'iam'=>(object)['displayName'=>'disp'.$X],
 'ip'=>'1.2.3.4','serverId'=>123,'os'=>'os'.$X,'reverse'=>'rev'.$X,'datacenter'=>'bhs',
 'monitoring'=>true,'supportLevel'=>'normal'.$X,'commercialRange'=>'c'.$X,'bootId'=>1,'rack'=>'r'.$X,'linkSpeed'=>'1'];
$disk=(object)['diskGroups'=>[(object)['description'=>'disk'.$X]]];
$h=$o->serviceInfoHtml(['httpcode'=>200,'result'=>$info],$L,$disk,'CPU'.$X,null,null,null,'canada','soyoustart','ns12',1,true,42);
check('service values escaped',strpos($h,$X)===false && strpos($h,'name&quot;')!==false && strpos($h,'disk&quot;')!==false);
$scriptHtml=$o->serviceInfoHtml([], $L, null, null, null, null, null, 'canada', 'soyoustart', 'ns12', 1, false, 42);
check('script carries service id, not OVH account',strpos($scriptHtml,'serviceId: 42')!==false && strpos($scriptHtml,'accountId:"')===false);
$h=$o->ipsInfoHtml([], $X, $L);check('IP error escaped',strpos($h,$X)===false);
$ip=(object)['ip'=>'1.2.3.0/24','reverseIp'=>(object)['reverse'=>'rev'.$X], 'version'=>4,
 'description'=>'desc'.$X,'mitigationIp'=>'9.9.9.9','firewallDetails'=>(object)['enabled'=>true]];
$h=$o->ipsInfoHtml([$ip],'',$L,true);
check('admin IP row escaped',strpos($h,$X)===false && strpos($h,'desc&quot;')!==false);
check('admin firewall toggle',strpos($h,'firewallEnableDisable')!==false);
$api=new SoyoustartTest\FakeApiCall();$api->detailsResult=['httpcode'=>200,'result'=>$ip];
$h=$o->createIpListHtml(['1.2.3.0/24'],$api,'canada','ns12',1,$L);
check('client IP row escaped',strpos($h,$X)===false && strpos($h,'rev&quot;')!==false);
$h=$o->createIpDetailsHtml(['httpcode'=>200,'result'=>(object)['ip'=>'1.2.3.4','version'=>4,
 'description'=>'d'.$X,'campus'=>'c','regions'=>['r'],'type'=>'failover','macaddress'=>[],
 'firewallDetails'=>(object)['enabled'=>false]]],$L);
check('IP details escaped',strpos($h,$X)===false && strpos($h,'d&quot;')!==false);
$h=$o->cretaeServerInfoHtml(['httpcode'=>500,'result'=>(object)['message'=>$X]],$L);
check('server info error escaped',strpos($h,$X)===false);
$h=$o->cretaeHardwareInfoHtml(['httpcode'=>500,'result'=>(object)['message'=>$X]],$L);
check('hardware error escaped',strpos($h,$X)===false);
$h=$o->createFtpBackupHtml((object)['ftpBackupName'=>'ftp'.$X,'message'=>'not found'],(object)['message'=>'bad'],$L,'ns12','', '', 'canada',1,$api);
check('ftp name escaped',strpos($h,$X)===false && strpos($h,'ftp&quot;')!==false);
check('ftp zero quota renders',strpos($h,'0 GB')!==false);
Capsule::$handler=function($table){throw new Exception('db down');};
check('config lookup error []',$o->getServiceConfigs(1)===[]);
Capsule::$handler=function($table){return new SoyoustartTest\FakeQuery(['getResult'=>[]]);};
$h=$o->featureHtml(['A','B'],12);check('feature no settings not crash',strpos($h,'type="checkbox"')!==false);
echo "escape/robust: PASS=$pass FAIL=$fail\n";exit($fail?1:0);
