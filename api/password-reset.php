<?php
/** Email-based recovery, deliberately independent of login MFA challenges. */
function ensurePasswordResetSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS password_reset_challenges (
        challenge_hash CHAR(64) PRIMARY KEY, user_id VARCHAR(64) NULL,
        identifier_hash CHAR(64) NOT NULL, ip_hash CHAR(64) NOT NULL,
        portal VARCHAR(8) NOT NULL, token_version INT NOT NULL DEFAULT 0,
        email_hash CHAR(64) NOT NULL, code_hash CHAR(64) NOT NULL,
        attempts TINYINT NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, consumed_at DATETIME NULL,
        INDEX(identifier_hash,created_at), INDEX(ip_hash,created_at), INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function passwordResetPortal(array $input): string {
    $portal=$input['portal']??'user';
    if(!is_string($portal)||!in_array($portal,['user','master'],true))jsonResponse(false,null,'مسیر بازیابی معتبر نیست.',400);
    return $portal;
}
function requestPasswordReset(PDO $db,array $input): void {
    $portal=passwordResetPortal($input);$identifier=$input['identifier']??'';
    if(!is_string($identifier)||trim($identifier)===''||strlen($identifier)>150)jsonResponse(false,null,'نام کاربری یا ایمیل معتبر وارد کنید.',400);
    $identifier=strtolower(trim($identifier));ensurePasswordResetSchema($db);
    $ih=hash_hmac('sha256','reset-identifier:'.$identifier,JWT_SECRET_KEY);$ip=clientNetworkHash();
    // Serialize rate-limit decisions, including requests for nonexistent accounts.
    $lock='nsc-password-reset-issue';$q=$db->prepare('SELECT GET_LOCK(:key,5)');$q->execute(['key'=>$lock]);
    if((int)$q->fetchColumn()!==1)jsonResponse(false,null,'دوباره تلاش کنید.',429);
    try{
        $db->exec('DELETE FROM password_reset_challenges WHERE created_at<NOW()-INTERVAL 1 DAY LIMIT 1000');
        $q=$db->prepare('SELECT COUNT(*) FROM password_reset_challenges WHERE created_at>NOW()-INTERVAL 15 MINUTE AND (identifier_hash=:identifier OR ip_hash=:ip)');$q->execute(['identifier'=>$ih,'ip'=>$ip]);
        if((int)$q->fetchColumn()>=20)jsonResponse(false,null,'درخواست زیاد تکرار شده است؛ ۱۵ دقیقه بعد تلاش کنید.',429);
        $q=$db->prepare('SELECT COUNT(*) AS count_requests, MAX(created_at)>NOW()-INTERVAL 60 SECOND AS cooling FROM password_reset_challenges WHERE identifier_hash=:identifier AND created_at>NOW()-INTERVAL 15 MINUTE');$q->execute(['identifier'=>$ih]);$limits=$q->fetch();
        if((int)$limits['count_requests']>=3||!empty($limits['cooling']))jsonResponse(false,null,'برای درخواست تازه کمی صبر کنید؛ حداکثر ۳ درخواست در ۱۵ دقیقه مجاز است.',429);
        $q=$db->prepare('SELECT * FROM users WHERE (LOWER(username)=:username OR LOWER(email)=:email) LIMIT 1');$q->execute(['username'=>$identifier,'email'=>$identifier]);$user=$q->fetch();
        $eligible=$user&&!$user['deleted_at']&&(((int)$user['is_master']===1)===($portal==='master'))&&usableLoginEmail((string)$user['email']);
        $challenge=bin2hex(random_bytes(32));$hash=hash('sha256',$challenge);$code=(string)random_int(100000,999999);
        $q=$db->prepare('INSERT INTO password_reset_challenges(challenge_hash,user_id,identifier_hash,ip_hash,portal,token_version,email_hash,code_hash,expires_at) VALUES(:hash,:uid,:identifier,:ip,:portal,:version,:email,:code,NOW()+INTERVAL 5 MINUTE)');
        $q->execute(['hash'=>$hash,'uid'=>$eligible?$user['id']:null,'identifier'=>$ih,'ip'=>$ip,'portal'=>$portal,'version'=>$eligible?(int)$user['token_version']:0,'email'=>$eligible?hash('sha256',(string)$user['email']):str_repeat('0',64),'code'=>hash_hmac('sha256','password-reset:'.$hash.':'.$code,JWT_SECRET_KEY)]);
        if($eligible){
            $body="Your password reset code is: $code\n\nValid for 5 minutes. Do not share this code.\nUse it only on the NiK Smart Count password recovery screen.\nIf you did not request this, ignore this email. Your password has not changed.\n\nNiK Smart Count";
            $sent=function_exists('mail')&&mail((string)$user['email'],'NiK Smart Count - Password reset code',$body,['From'=>'security@nik-smartcount.com','Content-Type'=>'text/plain; charset=UTF-8','X-Auto-Response-Suppress'=>'All']);
            if($sent){
                $q=$db->prepare('UPDATE password_reset_challenges SET consumed_at=NOW() WHERE user_id=:uid AND challenge_hash<>:hash AND consumed_at IS NULL');$q->execute(['uid'=>$user['id'],'hash'=>$hash]);
            }else{
                $q=$db->prepare('UPDATE password_reset_challenges SET consumed_at=NOW() WHERE challenge_hash=:hash');$q->execute(['hash'=>$hash]);
                error_log('NSC password reset: local mail service did not accept recovery email.');
            }
        }
        // Neither account existence, role nor email address is disclosed.
        jsonResponse(true,['challengeId'=>$challenge,'expiresIn'=>300,'retryAfter'=>60],'اگر حساب واجد شرایط با ایمیل معتبر موجود باشد، کُد بازیابی به ایمیل ثبت‌شده فرستاده می‌شود.');
    }finally{$q=$db->prepare('SELECT RELEASE_LOCK(:key)');$q->execute(['key'=>$lock]);}
}
function completePasswordReset(PDO $db,array $input): void {
    $portal=passwordResetPortal($input);$id=$input['challengeId']??'';$code=$input['code']??'';$password=$input['newPassword']??'';$confirm=$input['confirmPassword']??'';
    if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)||!is_string($code)||!preg_match('/^[0-9]{6}$/D',$code))jsonResponse(false,null,'کُد شش‌رقمی و درخواست معتبر لازم است.',400);
    $passwordLength=is_string($password)?preg_match_all('/./us',$password):false;
    if($passwordLength===false||$passwordLength<12||strlen($password)>72||str_contains($password,"\0")||!is_string($confirm)||!hash_equals($password,$confirm))jsonResponse(false,null,'رمز تازه باید حداقل ۱۲ حرف و حداکثر ۷۲ بایت و با تکرار آن یکسان باشد.',400);
    ensurePasswordResetSchema($db);ensureEmailSecuritySchema($db);ensureWebSessionSchema($db);
    $hash=hash('sha256',$id);
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT *,expires_at>NOW() AS valid FROM password_reset_challenges WHERE challenge_hash=:hash FOR UPDATE');$q->execute(['hash'=>$hash]);$row=$q->fetch();
        if(!$row||!$row['valid']||$row['consumed_at']!==null||(int)$row['attempts']>=5||$row['portal']!==$portal){$db->rollBack();jsonResponse(false,null,'کُد منقضی یا نامعتبر است؛ درخواست تازه بدهید.',401);}
        $q=$db->prepare('UPDATE password_reset_challenges SET attempts=attempts+1 WHERE challenge_hash=:hash');$q->execute(['hash'=>$hash]);
        $matches=hash_equals($row['code_hash'],hash_hmac('sha256','password-reset:'.$hash.':'.$code,JWT_SECRET_KEY));
        if(!$matches||!$row['user_id']){$db->commit();jsonResponse(false,null,'کُد اشتباه یا نامعتبر است.',401);}
        $q=$db->prepare('SELECT * FROM users WHERE id=:id FOR UPDATE');$q->execute(['id'=>$row['user_id']]);$user=$q->fetch();
        if(!$user||$user['deleted_at']!==null||(int)$user['token_version']!==(int)$row['token_version']||!hash_equals($row['email_hash'],hash('sha256',(string)$user['email']))||(((int)$user['is_master']===1)!==($portal==='master'))){$db->rollBack();jsonResponse(false,null,'حساب تغییر کرده است؛ درخواست تازه بدهید.',401);}
        $newHash=password_hash($password,PASSWORD_DEFAULT);
        $q=$db->prepare('UPDATE users SET password_hash=:password,hash_salt=NULL,token_version=token_version+1 WHERE id=:id');$q->execute(['password'=>$newHash,'id'=>$user['id']]);
        foreach(['web_sessions','email_trusted_devices','email_login_challenges']as $table){$q=$db->prepare("DELETE FROM $table WHERE user_id=:id");$q->execute(['id'=>$user['id']]);}
        $q=$db->prepare('UPDATE password_reset_challenges SET consumed_at=NOW() WHERE user_id=:id AND consumed_at IS NULL');$q->execute(['id'=>$user['id']]);
        $q=$db->prepare('DELETE FROM login_attempts WHERE identifier IN (:username,:email)');$q->execute(['username'=>strtolower((string)$user['username']),'email'=>strtolower((string)$user['email'])]);
        $db->commit();
        logAudit($db,$user['id'],$user['name'],'update','auth',$user['id'],'Password reset completed by email code; previous sessions revoked.');
        // No session is issued. Normal sign-in (including email MFA) is still required.
        jsonResponse(true,['passwordReset'=>true],'رمز تازه ثبت شد؛ با رمز تازه وارد شوید.');
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
