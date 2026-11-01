<?php
require_once __DIR__.'/db.php';sendSecurityHeaders();
if(($_SERVER['REQUEST_METHOD']??'')==='GET')jsonResponse(true,supportContacts());
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')jsonResponse(false,null,'Method not allowed',405);
$user=authenticateUser(true);$input=getJsonInput();$settings=[];
foreach(['phone','whatsapp']as $key){
    if(!is_string($input[$key]??null))jsonResponse(false,null,'شمارهٔ معتبر وارد کنید.',400);
    $value=preg_replace('/[\s()\-]/','',trim($input[$key]));
    if(!preg_match('/^\+[1-9][0-9]{6,14}$/D',$value))jsonResponse(false,null,'شماره را همراه کود کشور مانند +93 وارد کنید.',400);
    $settings[$key]=$value;
}
$email=$input['email']??'';if(!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))jsonResponse(false,null,'ایمیل معتبر وارد کنید.',400);$settings['email']=$email;
$tmp=tempnam(__DIR__.'/.secrets','support-');if($tmp===false)throw new RuntimeException('Support settings not writable');
try{if(!chmod($tmp,0600)||file_put_contents($tmp,"<?php\nreturn ".var_export($settings,true).";\n",LOCK_EX)===false||!rename($tmp,__DIR__.'/.secrets/support.php'))throw new RuntimeException('Cannot save support settings');}finally{if(is_file($tmp))unlink($tmp);}
logAudit(Database::getConnection(),$user['id'],$user['name'],'update','support',null,'Support contacts updated.');jsonResponse(true,['saved'=>true]);
