<?php
require '/var/www/html/api/db.php';
$tests=[];
$tests['valid token']=verifyToken(generateToken('audit-test','admin',0))!==null;
function signedClaims($payload,$header=['alg'=>'HS256','typ'=>'JWT']){ $h=base64UrlEncode(json_encode($header));$p=base64UrlEncode(json_encode($payload));return $h.'.'.$p.'.'.base64UrlEncode(hash_hmac('sha256',$h.'.'.$p,JWT_SECRET_KEY,true)); }
foreach([
 ['expired',['user_id'=>'x','exp'=>time()-1]],
 ['expiry boundary',['user_id'=>'x','exp'=>time()]],
 ['invalid expiry type',['user_id'=>'x','exp'=>'9999999999']],
 ['missing user',['exp'=>time()+60]],
 ['invalid user type',['user_id'=>['x'],'exp'=>time()+60]],
 ['invalid token version',['user_id'=>'x','exp'=>time()+60,'ver'=>'0']]
] as [$name,$payload])$tests[$name]=verifyToken(signedClaims($payload))===null;
$tests['unsupported algorithm']=verifyToken(signedClaims(['user_id'=>'x','exp'=>time()+60],['alg'=>'none','typ'=>'JWT']))===null;
$tests['tampered signature']=verifyToken(generateToken('x','admin',0).'bad')===null;
$tests['oversized token']=verifyToken(str_repeat('a',5000))===null;
foreach($tests as $name=>$ok)echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
if(in_array(false,$tests,true))exit(1);
echo count($tests)." token checks passed\n";
