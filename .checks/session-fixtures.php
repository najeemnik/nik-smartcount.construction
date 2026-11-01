<?php
require '/var/www/html/api/db.php';require '/var/www/html/api/email-security.php';
if(DB_NAME!=='nsc_audit')throw new RuntimeException('Refusing non-audit DB');
$db=Database::getConnection();ensureEmailSecuritySchema($db);$mode=$argv[1]??'';
if($mode==='clear'){$db->exec('DELETE FROM login_attempts');$db->exec('DELETE FROM email_login_challenges');}
elseif($mode==='trust-ip')$db->exec("UPDATE email_trusted_devices SET ip_hash=REPEAT('0',64) WHERE user_id='preview-owner'");
elseif($mode==='trust-expire')$db->exec("UPDATE email_trusted_devices SET expires_at=NOW()-INTERVAL 1 SECOND WHERE user_id='preview-owner'");
elseif($mode==='session-ip')$db->exec("UPDATE web_sessions SET network_hash=REPEAT('0',64) WHERE user_id='preview-owner'");
elseif($mode==='fresh-migration')$db->exec("UPDATE users SET cookie_session_enabled=0 WHERE id='preview-owner'");
elseif(in_array($mode,['locked','inactive','expired','deleted','normal'],true)){
 $q=$db->prepare('UPDATE users SET is_locked=:locked,is_active=:active,subscription_expires_at=:expiry,deleted_at=:deleted,app_profile_json=:profile WHERE id="preview-owner"');
 $q->execute(['locked'=>$mode==='locked'?1:0,'active'=>$mode==='inactive'?0:1,'expiry'=>$mode==='expired'?'2020-01-01 00:00:00':null,'deleted'=>$mode==='deleted'?'2020-01-01 00:00:00':null,'profile'=>json_encode(['staffLimit'=>5,'accountNotice'=>'<img src=x onerror=alert(1)> پیام مدیریت'])]);
}else throw new RuntimeException('Unknown fixture');
echo "Audit session fixture updated\n";
