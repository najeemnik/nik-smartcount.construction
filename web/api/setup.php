<?php
require_once __DIR__.'/db.php';require_once __DIR__.'/connection.php';sendSecurityHeaders();
$dir=__DIR__.'/.secrets';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')jsonResponse(false,null,'Method not allowed.',405);
if(is_file($dir.'/installed.lock'))jsonResponse(false,null,'نصب قبلاً قفل شده است. فایل قفل را حذف نکنید.',409);
$input=getJsonInput();$expected=is_file($dir.'/install.key')?trim((string)file_get_contents($dir.'/install.key')):'';
$guard=fopen($dir.'/bootstrap.guard','c');
if(!$guard||!flock($guard,LOCK_EX))jsonResponse(false,null,'پوشه api/.secrets برای PHP قابل نوشتن نیست.',503);
$rate=$dir.'/setup-rate-'.hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown').'.json';
$attempts=is_file($rate)?(json_decode((string)file_get_contents($rate),true)?:[]):[];
$attempts=array_values(array_filter($attempts,fn($t)=>is_int($t)&&$t>time()-900));
if(count($attempts)>=5)jsonResponse(false,null,'پس از ۱۵ دقیقه دوباره تلاش کنید.',429);
if($expected===''||!hash_equals($expected,trim((string)($input['key']??'')))){
 $attempts[]=time();file_put_contents($rate,json_encode($attempts),LOCK_EX);jsonResponse(false,null,'کلید نصب نامعتبر است.',403);
}
if(is_file($dir.'/installed.lock'))jsonResponse(false,null,'نصب قفل شده است.',409);
$cfg=['HOST'=>trim((string)($input['host']??'localhost')),'PORT'=>(string)($input['port']??'3306'),'NAME'=>trim((string)($input['name']??'')),'USER'=>trim((string)($input['user']??'')),'PASS'=>(string)($input['password']??'')];
if(!preg_match('/^[a-zA-Z0-9.:-]{1,255}$/',$cfg['HOST'])||!preg_match('/^[0-9]{1,5}$/',$cfg['PORT'])||(int)$cfg['PORT']<1||(int)$cfg['PORT']>65535||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$cfg['NAME'])||$cfg['USER']===''||strlen($cfg['USER'])>128)jsonResponse(false,null,'مشخصات معتبر نیست. آدرس سرور معمولاً localhost است.',400);
try{
 // Protect an existing master in the current database as well as the filesystem lock.
 $old=null;
 if(DB_NAME!==''){
  try{$oldCfg=['HOST'=>DB_HOST,'PORT'=>DB_PORT,'NAME'=>DB_NAME,'USER'=>DB_USER,'PASS'=>DB_PASS];$old=nscConnect($oldCfg);}catch(Throwable $e){/* Initial connection repair: preserve the old settings below. */}
  if($old){try{if((int)$old->query('SELECT COUNT(*) FROM users WHERE is_master=1')->fetchColumn()>0)jsonResponse(false,null,'مدیر اصلی موجود است؛ نصب یا تغییر اتصال اولیه اجازه ندارد.',409);}catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;}}
 }
 $connection=nscConnect($cfg);
 if($connection->query('SHOW TABLES')->fetchColumn()!==false)jsonResponse(false,null,'برای نصب تازه دیتابیس خالی لازم است. هیچ جدول موجودی حذف نشد.',409);
 if(($input['action']??'test')==='test')jsonResponse(true,['connected'=>true],'اتصال موفق است.');
 if(($input['action']??'')!=='configure')jsonResponse(false,null,'Unknown action.',400);
 $target=$dir.'/database.php';
 if(is_file($target)){
  $backup=$dir.'/database.previous-'.bin2hex(random_bytes(6)).'.php';
  if(!copy($target,$backup)||!chmod($backup,0600))throw new RuntimeException('Backup failed.');
 }
 $tmp=tempnam($dir,'db-');if($tmp===false)throw new RuntimeException('Write failed.');
 if(file_put_contents($tmp,"<?php\nreturn ".var_export($cfg,true).";\n",LOCK_EX)===false||!chmod($tmp,0600)||!rename($tmp,$target))throw new RuntimeException('Write failed.');
 @unlink($rate);jsonResponse(true,['configured'=>true],'اتصال ذخیره شد؛ مدیر اصلی را بسازید.');
}catch(Throwable $e){$error=nscConnectionError($e);jsonResponse(false,['code'=>$error['code']],'['.$error['code'].'] '.$error['message'],400);}
