<?php
require '/var/www/html/api/db.php';
if(DB_NAME!=='nsc_audit')throw new RuntimeException('Refusing non-audit database');
$id=$argv[1]??'';if(!preg_match('/^reuse-[a-z0-9-]{3,55}$/D',$id))throw new RuntimeException('Not a reuse fixture');
$db=Database::getConnection();$q=$db->prepare('SELECT * FROM users WHERE id=:id');$q->execute(['id'=>$id]);$u=$q->fetch();$profile=json_decode($u['app_profile_json']??'{}',true)?:[];
$q=$db->prepare('SELECT state_json FROM app_workspaces WHERE tenant_id=:id');$q->execute(['id'=>$id]);$state=json_decode($q->fetchColumn()?:'{}',true);
echo json_encode(['id'=>$u['id'],'username'=>$u['username'],'email'=>$u['email'],'deleted'=>!empty($u['deleted_at']),'locked'=>(int)$u['is_locked'],'active'=>(int)$u['is_active'],'original'=>$profile['removedIdentity']??null,'state'=>$state]);
