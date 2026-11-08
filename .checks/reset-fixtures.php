<?php
require '/var/www/html/api/db.php';require '/var/www/html/api/email-security.php';require '/var/www/html/api/password-reset.php';
if(DB_NAME!=='nsc_audit')throw new RuntimeException('Refusing non-audit database');
$db=Database::getConnection();ensurePasswordResetSchema($db);ensureEmailSecuritySchema($db);ensureWebSessionSchema($db);$mode=$argv[1]??'';
if($mode==='seed'){
 $accounts=json_decode(file_get_contents('/run/nsc-accounts.json'),true);$q=$db->prepare('INSERT INTO users(id,name,username,email,password_hash,role,is_master) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE email=VALUES(email),password_hash=VALUES(password_hash),role=VALUES(role),is_master=VALUES(is_master),is_active=1,is_locked=0,deleted_at=NULL,token_version=token_version+1');
 foreach([['reset-test-user',0,'admin'],['reset-test-master',1,'master']]as[$id,$master,$role])$q->execute([$id,$id,$id,$id.'@nik-smartcount.com',password_hash($accounts[0]['password'],PASSWORD_DEFAULT),$role,$master]);
 foreach(['web_sessions','email_trusted_devices','email_login_challenges']as$table)$db->exec("DELETE FROM $table WHERE user_id IN ('reset-test-user','reset-test-master')");
 $db->exec('DELETE FROM password_reset_challenges');$db->exec('DELETE FROM login_attempts');
}elseif($mode==='artifacts'){
 $db->exec("INSERT INTO email_trusted_devices(token_hash,user_id,token_version,remaining_uses,ip_hash,device_hash,expires_at) SELECT REPEAT('a',64),id,token_version,0,REPEAT('b',64),REPEAT('c',64),NOW()+INTERVAL 1 DAY FROM users WHERE id='reset-test-user'");
 $db->exec("INSERT INTO email_login_challenges(challenge_hash,user_id,token_version,code_hash,browser_mode,ip_address,expires_at) SELECT REPEAT('d',64),id,token_version,REPEAT('e',64),0,'127.0.0.1',NOW()+INTERVAL 5 MINUTE FROM users WHERE id='reset-test-user'");
}elseif($mode==='clear'){$db->exec('DELETE FROM password_reset_challenges');$db->exec('DELETE FROM login_attempts');}
elseif($mode==='age')$db->exec('UPDATE password_reset_challenges SET created_at=NOW()-INTERVAL 61 SECOND');
elseif($mode==='expire')$db->exec('UPDATE password_reset_challenges SET expires_at=NOW()-INTERVAL 1 SECOND');
elseif($mode==='email')$db->exec("UPDATE users SET email='changed-reset@nik-smartcount.com' WHERE id='reset-test-user'");
elseif($mode==='version')$db->exec("UPDATE users SET token_version=token_version+1 WHERE id='reset-test-user'");
elseif($mode==='role')$db->exec("UPDATE users SET is_master=1 WHERE id='reset-test-user'");
elseif($mode==='lock')$db->exec("UPDATE users SET is_locked=1 WHERE id='reset-test-user'");
elseif($mode==='deleted')$db->exec("UPDATE users SET deleted_at=NOW() WHERE id='reset-test-user'");
elseif($mode==='placeholder')$db->exec("UPDATE users SET email='reset-user@sync.local' WHERE id='reset-test-user'");
elseif($mode==='inspect'){
 $r=$db->query("SELECT * FROM password_reset_challenges WHERE user_id='reset-test-user' ORDER BY created_at DESC LIMIT 1")->fetch();$u=$db->query("SELECT * FROM users WHERE id='reset-test-user'")->fetch();
 $result=['storedHashedCode'=>$r&&strlen($r['code_hash'])===64,'passwordHashed'=>str_starts_with($u['password_hash'],'$2y$'),'locked'=>(int)$u['is_locked']];
 foreach(['web_sessions','email_trusted_devices','email_login_challenges']as$table)$result[$table]=(int)$db->query("SELECT COUNT(*) FROM $table WHERE user_id='reset-test-user'")->fetchColumn();echo json_encode($result);exit;
}else throw new RuntimeException('Unknown fixture');
echo "Reset fixture updated\n";
