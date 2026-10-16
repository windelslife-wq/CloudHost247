<?php
require __DIR__ . '/bootstrap.php';
use WHMCS\Module\Server\Soyoustart\AjaxAuth;
use WHMCS\Module\Server\Soyoustart\SoyoustartServer;
use WHMCS\Database\Capsule;
$pass=0;$fail=0;
function check($s,$b) {global $pass,$fail;if($b){$pass++;}else{$fail++;echo "FAIL: $s\n";}}
$svc=(object)['packageid'=>7];$product=(object)['id'=>7,'servertype'=>'soyoustart','configoption3'=>'1-canada-user@example.com'];
$good=['server'=>'ns1234.ovh.net','account'=>'1','location'=>'canada'];
check('valid context',AjaxAuth::context(3,'POST',4,$svc,$product,'ns1234.ovh.net')===$good);
foreach ([
 ['no admin',0,'POST',4,$svc,$product,'ns1234.ovh.net'],
 ['no service',3,'POST',0,$svc,$product,'ns1234.ovh.net'],
 ['GET',3,'GET',4,$svc,$product,'ns1234.ovh.net'],
 ['no service row',3,'POST',4,null,$product,'ns1234.ovh.net'],
 ['no product',3,'POST',4,$svc,null,'ns1234.ovh.net'],
 ['foreign product',3,'POST',4,(object)['packageid'=>8],$product,'ns1234.ovh.net'],
 ['wrong module',3,'POST',4,$svc,(object)['id'=>7,'servertype'=>'soyoustart_vps','configoption3'=>'1-canada-x'],'ns1234.ovh.net'],
 ['path traversal',3,'POST',4,$svc,$product,'../foreign'],
 ['query injection',3,'POST',4,$svc,$product,'x?y'],
 ['slash',3,'POST',4,$svc,$product,'ns/evil'],
 ['blank',3,'POST',4,$svc,$product,''],
 ['missing account',3,'POST',4,$svc,(object)['id'=>7,'servertype'=>'soyoustart','configoption3'=>'-canada-x'],'ns'],
 ['non-numeric account',3,'POST',4,$svc,(object)['id'=>7,'servertype'=>'soyoustart','configoption3'=>'bad-canada-x'],'ns'],
 ['malformed location',3,'POST',4,$svc,(object)['id'=>7,'servertype'=>'soyoustart','configoption3'=>'1-../evil-x'],'ns'],
] as $v) { $label=array_shift($v); check($label,AjaxAuth::context(...$v)===null); }
foreach ([['1.2.3.4/28','1.2.3.4'], ['2001:db8::1/64','2001:db8::1'], ['../bad',null], ['x?y',null], [null,null], ['',null]] as $case) check('firstIp '.json_encode($case[0]),soyoustart_firstIp($case[0])===$case[1]);
$assigned=['httpcode'=>200,'result'=>['1.2.3.0/24','2001:db8::/64']];
check('own IP allowed',soyoustart_ipBelongsToServer($assigned,'1.2.3.0/24'));
foreach ([['other IP',$assigned,'1.2.4.0/24'],['prefix confusion',$assigned,'1.2.3.0'],
 ['bad response',['httpcode'=>503,'result'=>['1.2.3.0/24']],'1.2.3.0/24'],
 ['bad shape',['httpcode'=>200,'result'=>(object)[]],'1.2.3.0/24'],
 ['bad input',$assigned,['1.2.3.0/24']]] as $case) check($case[0],!soyoustart_ipBelongsToServer($case[1],$case[2]));
check('cfgPart present',soyoustart_cfgPart(['key'=>'1-canada-mail'],'key',1,'-')==='canada');
check('cfgPart absent',soyoustart_cfgPart([],'key',1,'-')==='');
$o=new SoyoustartServer();
check('price numeric',SoyoustartServer::validAdditionalIpPrice((object)['additionalIPprice'=>'1.25']));
foreach ([null,(object)[],(object)['additionalIPprice'=>0],(object)['additionalIPprice'=>'x'],(object)['additionalIPprice'=>-1]] as $price) check('price rejected',!SoyoustartServer::validAdditionalIpPrice($price));
$GLOBALS['__c5_localapi']['handler']=function($cmd,$data){return ['result'=>'success','invoiceid'=>88];};
$price=(object)['additionalIPprice'=>'1.25'];
$r=$o->createAdditionalIpInvoice(44,3,$price);
check('invoice success',($r['invoiceid']??null)===88);
check('one localAPI call',count($GLOBALS['__c5_localapi']['calls'])===1);
check('invoice command',$GLOBALS['__c5_localapi']['calls'][0][0]==='CreateInvoice');
$payload=$GLOBALS['__c5_localapi']['calls'][0][1];
foreach (['userid'=>44,'status'=>'Unpaid','itemamount1'=>3.75,'paymentmethod'=>'mailin'] as $k=>$v) check('invoice field '.$k,$payload[$k]===$v);
foreach ([[0,1,$price],[44,0,$price],[44,257,$price],[44,1,null]] as $args) check('invalid invoice blocked',($o->createAdditionalIpInvoice(...$args)['result']??'')==='error');
check('no invoice on invalid',count($GLOBALS['__c5_localapi']['calls'])===1);
$q=new SoyoustartTest\FakeQuery();Capsule::$handler=function($table)use($q){if($table!=='mod_soyoustart_ips_orders')throw new Exception('wrong table');return $q;};
check('ip order insert',$o->InsertIpData('mod_soyoustart_ips_orders',['invoiceid'=>88])===true);
check('insert data',$q->inserted[0]['invoiceid']===88);
try {$o->InsertIpData('tblhosting',['invoiceid'=>88]);check('table restricted',false);}catch(InvalidArgumentException $e){check('table restricted',true);}
$fields=new SoyoustartTest\FakeQuery(['firstResult'=>(object)['id'=>17]]);
$values=new SoyoustartTest\FakeQuery();
Capsule::$handler=function($table)use($fields,$values){return $table==='tblcustomfields'?$fields:$values;};
$helper=new WHMCS\Module\Addon\Soyoustart\Helper();
check('fresh service persists OVH order id',$helper->insert_custom_fields_value(12,7,['ovh_order_id'=>'ovh-89'])==='success');
check('fresh insert correct',($values->inserted[0]['value']??null)==='ovh-89' && ($values->inserted[0]['fieldid']??null)===17);
check('infinite price rejected',!SoyoustartServer::validAdditionalIpPrice((object)['additionalIPprice'=>'1e309']));
check('ACL enabled by default',!soyoustart_isFeatureDisabled([],[],'Reboot','reboot'));
check('ACL disabled by service setting',soyoustart_isFeatureDisabled(['Reboot'=>'on'],[],'Reboot','reboot'));
check('ACL disabled by addon setting',soyoustart_isFeatureDisabled([],['reboot'],'Reboot','reboot'));
$_SESSION=[];
$tok=soyoustart_csrfToken();
check('CSRF token minted',is_string($tok)&&strlen($tok)===64);
check('CSRF valid accepted',soyoustart_verifyCsrf($tok,$_SESSION)&&AjaxAuth::verifyCsrf($tok,$_SESSION));
check('CSRF invalid rejected',!soyoustart_verifyCsrf('bad',$_SESSION)&&!AjaxAuth::verifyCsrf('bad',$_SESSION));
echo "auth/invoice: PASS=$pass FAIL=$fail\n";exit($fail?1:0);
