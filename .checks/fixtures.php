<?php
require '/var/www/html/api/db.php';
if(DB_NAME!=='nsc_audit')throw new RuntimeException('Not an audit database');
$db=Database::getConnection();$password=json_decode(file_get_contents('/run/nsc-accounts.json'),true)[0]['password'];
$modules=['projects','expenses','steel','concrete','contractors','suppliers','apartments','assets','labor','payments','exchange','partners','budget','accounting','reports','documents','settings','users'];
$all=[];$view=[];foreach($modules as $m){$all[$m]=['view'=>true,'create'=>true,'edit'=>true,'delete'=>true];$view[$m]=['view'=>true,'create'=>false,'edit'=>false,'delete'=>false];}
$grants=[['projectId'=>'audit-a','sections'=>$all],['projectId'=>'audit-b','sections'=>$view]];
$s=$db->prepare('INSERT INTO users (id,owner_admin_id,name,username,email,password_hash,role,permissions_json,app_profile_json) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role=VALUES(role), permissions_json=VALUES(permissions_json),app_profile_json=VALUES(app_profile_json),token_version=0');
foreach([['audit-staff','preview-owner','accountant',['settings'=>['view'=>false]],['projectGrants'=>$grants]],['audit-viewer','preview-owner','viewer',[],[]],['audit-other',null,'admin',[],[]],['audit-create','preview-owner','accountant',['projects'=>['edit'=>false]],[]]] as [$id,$owner,$role,$perms,$profile])$s->execute([$id,$owner,$id,$id,$id.'@audit.invalid',password_hash($password,PASSWORD_DEFAULT),$role,json_encode($perms),json_encode($profile)]);
$s=$db->prepare('INSERT IGNORE INTO projects (id,tenant_id,project_code,name) VALUES (?,?,?,?)');
foreach([['audit-a','preview-owner'],['audit-b','preview-owner'],['audit-c','preview-owner'],['audit-foreign','audit-other']] as [$id,$tenant])$s->execute([$id,$tenant,$id,$id]);
$state=['projects'=>[['id'=>'audit-a'],['id'=>'audit-b'],['id'=>'audit-c']],'expenses'=>[['id'=>'expense-a','projectId'=>'audit-a','amount'=>10],['id'=>'expense-b','projectId'=>'audit-b','amount'=>20],['id'=>'no-project','amount'=>30]],'appSettings'=>[]];
$s=$db->prepare('INSERT INTO app_workspaces (tenant_id,state_version,state_json) VALUES (?,1,?) ON DUPLICATE KEY UPDATE state_version=state_version+1,state_json=VALUES(state_json)');$s->execute(['preview-owner',json_encode($state)]);
$db->exec("INSERT IGNORE INTO suppliers(id,tenant_id,supplier_name) VALUES ('audit-null',NULL,'Unclaimed legacy supplier'),('audit-supplier','preview-owner','Owner supplier')");
$db->exec("INSERT IGNORE INTO treasury_accounts(id,tenant_id,account_name,account_type,currency,current_balance) VALUES ('audit-afn','preview-owner','AFN test','bank','AFN',10000),('audit-usd','preview-owner','USD test','bank','USD',1000)");
echo "Audit fixtures ready\n";
