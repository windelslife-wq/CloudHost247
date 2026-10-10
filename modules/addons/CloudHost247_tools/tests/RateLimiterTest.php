<?php
define('CLOUDHOST247_TOOLS', true);
$d=__DIR__ . '/../includes/';
require $d.'Security.php'; require $d.'RateLimiter.php'; require $d.'Catalog.php';
$pass=0;$fail=0;
function ok($l,$c){global $pass,$fail; if($c){$pass++;}else{$fail++;echo "  FAIL $l\n";}}

$client = ['slug'=>'json-tool','name'=>'JSON','exec'=>'client'];
$server = ['slug'=>'dns-lookup','name'=>'DNS Lookup','exec'=>'server'];
$heavy  = ['slug'=>'traceroute','name'=>'Traceroute','exec'=>'server'];

ok('client unlimited', CloudHost247ToolsRateLimiter::limitFor($client)===0);
ok('server 30', CloudHost247ToolsRateLimiter::limitFor($server)===30);
ok('heavy 10', CloudHost247ToolsRateLimiter::limitFor($heavy)===10);
ok('override', CloudHost247ToolsRateLimiter::limitFor($server+['rate_limit'=>5])===5);

// client tool never limited
for($i=0;$i<200;$i++){ $r=CloudHost247ToolsRateLimiter::check($client,'203.0.113.1'); }
ok('client never throttled', $r['unlimited']===true);

// heavy tool: 10 allowed then throttle
$allowed=0;$threw=false;
for($i=0;$i<25;$i++){
  try { CloudHost247ToolsRateLimiter::check($heavy,'198.51.100.77'); $allowed++; }
  catch (CloudHost247ToolsRateLimitException $e){ $threw=true; break; }
}
ok("heavy allowed exactly 10 (got $allowed)", $allowed===10);
ok('heavy threw', $threw);

// separate IP has its own bucket
$a=0; try{ for($i=0;$i<10;$i++){CloudHost247ToolsRateLimiter::check($heavy,'198.51.100.88');$a++;} }catch(Exception $e){}
ok("distinct IP independent (got $a)", $a===10);

// remaining counts down
$r1=CloudHost247ToolsRateLimiter::check($server,'203.0.113.50');
$r2=CloudHost247ToolsRateLimiter::check($server,'203.0.113.50');
ok('remaining decrements', $r1['remaining']===29 && $r2['remaining']===28);

// global ceiling
$g=0; try{ for($i=0;$i<400;$i++){ CloudHost247ToolsRateLimiter::check($server,'203.0.113.200'); $g++; } }
catch(CloudHost247ToolsRateLimitException $e){}
ok("global ceiling stops runaway (got $g)", $g>0 && $g<=120);

// CSRF
$t = CloudHost247ToolsRateLimiter::token();
ok('token len', strlen($t)===64);
ok('token stable', $t === CloudHost247ToolsRateLimiter::token());
ok('valid passes', CloudHost247ToolsRateLimiter::validateToken($t)===true);
ok('wrong fails', CloudHost247ToolsRateLimiter::validateToken(str_repeat('a',64))===false);
ok('empty fails', CloudHost247ToolsRateLimiter::validateToken('')===false);
ok('null fails', CloudHost247ToolsRateLimiter::validateToken(null)===false);

// catalog integration: every tool gets a sane limit
$bad=[];
foreach(CloudHost247ToolsCatalog::tools() as $s=>$t){
  $l=CloudHost247ToolsRateLimiter::limitFor($t);
  if($t['exec']==='client' && $l!==0) $bad[]="$s client limited";
  if($t['exec']!=='client' && $l<=0) $bad[]="$s server unlimited";
}
ok('all 91 limits sane: '.implode(', ',array_slice($bad,0,5)), empty($bad));
echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
