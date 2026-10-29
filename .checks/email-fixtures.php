<?php
require '/var/www/html/api/db.php';require '/var/www/html/api/email-security.php';
if(DB_NAME!=='nsc_audit')throw new RuntimeException('Refusing non-audit database');
$db=Database::getConnection();ensureEmailSecuritySchema($db);
$mode=$argv[1]??'';
if($mode==='enable'||$mode==='disable'){
 file_put_contents('/var/www/html/api/.secrets/email.php','<?php return ["enabled"=>'.($mode==='enable'?'true':'false').',"from"=>"security@nik-smartcount.com"];');
}else if($mode==='reset'){
 $db->exec('DELETE FROM email_login_challenges');$db->exec('DELETE FROM email_trusted_devices');$db->exec('DELETE FROM login_attempts');
 $db->exec('UPDATE users SET email=CONCAT(username,"@nik-smartcount.com") WHERE id IN ("preview-owner","preview-master","audit-other","audit-viewer")');
}else if($mode==='clear'){$db->exec('DELETE FROM email_login_challenges');$db->exec('DELETE FROM login_attempts');}
else if($mode==='expire'){$q=$db->prepare('UPDATE email_login_challenges SET expires_at=NOW()-INTERVAL 1 SECOND WHERE challenge_hash=:hash');$q->execute(['hash'=>hash('sha256',$argv[2])]);}
else if($mode==='version'){$db->exec('UPDATE users SET token_version=token_version+1 WHERE id="preview-owner"');}
else if($mode==='lock'){$db->exec('UPDATE users SET is_locked=1 WHERE id="preview-owner"');}
else if($mode==='unlock'){$db->exec('UPDATE users SET is_locked=0 WHERE id="preview-owner"');}
else if($mode==='placeholder'){$db->exec('UPDATE users SET email="preview@sync.local" WHERE id="preview-owner"');}
else if($mode==='email'){$db->exec('UPDATE users SET email="preview@nik-smartcount.com" WHERE id="preview-owner"');}
else throw new RuntimeException('Unknown fixture mode');
echo "Audit email fixture updated\n";
