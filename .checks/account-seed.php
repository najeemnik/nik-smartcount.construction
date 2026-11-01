<?php
require '/var/www/html/api/db.php';
if(DB_NAME!=='nsc_audit')throw new RuntimeException('Refusing non-audit database');
$db=Database::getConnection();
$schema=preg_replace('/^--.*$/m','',file_get_contents('/var/www/html/api/.secrets/schema.sql'));
foreach(explode(';',$schema) as $sql)if(trim($sql)!=='')$db->exec($sql);
$accounts=json_decode(file_get_contents('/run/nsc-accounts.json'),true);
$q=$db->prepare('INSERT INTO users(id,name,username,email,password_hash,role,is_master,app_profile_json) VALUES(?,?,?,?,?,?,?,?)');
foreach([['preview-owner','admin',0,0],['preview-master','master',1,1]] as [$id,$role,$master,$index]){
 $a=$accounts[$index];$q->execute([$id,$a['username'],$a['username'],$a['username'].'@nik-smartcount.com',password_hash($a['password'],PASSWORD_DEFAULT),$role,$master,json_encode(['staffLimit'=>5])]);
}
echo "Synthetic accounts seeded\n";
