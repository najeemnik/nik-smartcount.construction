<?php
require_once __DIR__.'/db.php';sendSecurityHeaders();$user=authenticateUser(true);$db=Database::getConnection();
if(($_GET['action']??'')==='upgrade' && ($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 try{
  $schema=file_get_contents(__DIR__.'/.secrets/schema.sql');if(!$schema)throw new RuntimeException('Schema missing.');
  foreach(explode(';',preg_replace('/^--.*$/m','',$schema)) as $sql)if(trim($sql)!=='')$db->exec($sql);
  $columns=$db->query('SHOW COLUMNS FROM form_drafts')->fetchAll(PDO::FETCH_COLUMN);
  if(!in_array('client_draft_key',$columns,true))$db->exec('ALTER TABLE form_drafts ADD COLUMN client_draft_key VARCHAR(255) NULL AFTER draft_key');
  foreach(['subcontractors','suppliers'] as $table){$columns=$db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);if(!in_array('tenant_id',$columns,true))$db->exec('ALTER TABLE '.$table.' ADD COLUMN tenant_id VARCHAR(64) NULL AFTER id');}
  logAudit($db,$user['id'],$user['name'],'upgrade','system',null,'Additive schema upgrade; no rows deleted.');
  jsonResponse(true,['upgraded'=>true],'ساختار بدون حذف معلومات به‌روز شد.');
 }catch(Throwable $e){error_log('NSC upgrade: '.$e->getMessage());jsonResponse(false,null,'ارتقا تکمیل نشد. گزارش هاست را بررسی کنید؛ هیچ دستور حذف اجرا نشده است.',500);}
}
if(($_GET['action']??'')==='status' && ($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
 $exists=$db->query("SHOW TABLES LIKE 'app_workspaces'")->fetchColumn();
 jsonResponse(true,['schemaVersion'=>nscSchemaVersionOf($db),'expectedSchemaVersion'=>NSC_SCHEMA_VERSION,'php'=>PHP_VERSION,'database'=>$db->query('SELECT VERSION()')->fetchColumn(),'initialized'=>(int)$db->query('SELECT COUNT(*) FROM users WHERE is_master=1')->fetchColumn()>0,'users'=>(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),'workspaces'=>$exists?(int)$db->query('SELECT COUNT(*) FROM app_workspaces')->fetchColumn():0]);
}
jsonResponse(false,null,'Unknown action.',404);
