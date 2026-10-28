<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/connection.php';
sendSecurityHeaders();
$configured=DB_NAME!==''&&DB_USER!=='';$connected=false;
$initialized=is_file(__DIR__.'/.secrets/installed.lock');$diagnostic=null;
if($configured){
 try{
  $cfg=['HOST'=>DB_HOST,'PORT'=>DB_PORT,'NAME'=>DB_NAME,'USER'=>DB_USER,'PASS'=>DB_PASS];
  $pdo=nscConnect($cfg);$connected=true;
  try{$initialized=$initialized||(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_master=1')->fetchColumn()>0;}
  catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;}
 }catch(Throwable $e){$diagnostic=nscConnectionError($e);}
}
jsonResponse(true,['configured'=>$configured,'initialized'=>$initialized,'connected'=>$connected,'diagnostic'=>$diagnostic]);
