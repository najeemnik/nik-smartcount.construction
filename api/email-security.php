<?php
/** Email login challenge and five-use trusted devices. No code or device token is stored in clear text. */
function emailSecuritySettings(): array {
    $path=__DIR__.'/.secrets/email.php';
    if(is_file($path) && function_exists('opcache_invalidate'))opcache_invalidate($path,true);
    $settings=is_file($path)?require $path:[];
    if(!is_array($settings))$settings=[];
    return ['enabled'=>$settings['enabled']??true,'from'=>$settings['from']??'security@nik-smartcount.com'];
}
function ensureEmailSecuritySchema(PDO $db): void {
    // Additive and idempotent, including for existing hosts before their next login.
    $db->exec("CREATE TABLE IF NOT EXISTS email_login_challenges (
        challenge_hash CHAR(64) PRIMARY KEY,user_id VARCHAR(64) NOT NULL,token_version INT NOT NULL,
        code_hash CHAR(64) NOT NULL,attempts TINYINT NOT NULL DEFAULT 0,ip_address VARCHAR(45) NOT NULL,
        expires_at DATETIME NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        consumed_at DATETIME NULL,INDEX(user_id,created_at),INDEX(ip_address,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS email_trusted_devices (
        token_hash CHAR(64) PRIMARY KEY,user_id VARCHAR(64) NOT NULL,token_version INT NOT NULL,
        remaining_uses TINYINT NOT NULL,expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function assertLoginAccountActive(PDO $db, array $user): void {
    if((int)($user['is_locked']??0)===1||(int)($user['is_active']??1)!==1)jsonResponse(false,null,'حساب غیرفعال یا مسدود شده است.',403);
    if((int)($user['is_master']??0)!==1&&!empty($user['subscription_expires_at'])&&strtotime($user['subscription_expires_at'])<=time())jsonResponse(false,null,'اعتبار حساب پایان یافته است.',403);
    if(!empty($user['owner_admin_id'])){
        $q=$db->prepare('SELECT * FROM users WHERE id=:id AND role="admin" AND is_master=0');$q->execute(['id'=>$user['owner_admin_id']]);
        $owner=$q->fetch();if(!$owner)jsonResponse(false,null,'شرکت معتبر نیست.',403);
        if((int)($owner['is_locked']??0)===1||(int)($owner['is_active']??1)!==1||(!empty($owner['subscription_expires_at'])&&strtotime($owner['subscription_expires_at'])<=time()))jsonResponse(false,null,'دسترسی شرکت غیرفعال یا منقضی شده است.',403);
    }
}
function trustedDeviceCookieName(string $id): string { return '__Host-nsc_trust_'.substr(hash('sha256',$id),0,16); }
function useTrustedDevice(PDO $db, array $user): bool {
    $token=$_COOKIE[trustedDeviceCookieName((string)$user['id'])]??'';
    if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))return false;
    $q=$db->prepare('UPDATE email_trusted_devices SET remaining_uses=remaining_uses-1 WHERE token_hash=:hash AND user_id=:uid AND token_version=:ver AND remaining_uses>0 AND expires_at>NOW()');
    $q->execute(['hash'=>hash('sha256',$token),'uid'=>$user['id'],'ver'=>(int)($user['token_version']??0)]);
    return $q->rowCount()===1;
}
function usableLoginEmail(string $email): bool {
    return filter_var($email,FILTER_VALIDATE_EMAIL)!==false && !preg_match('/[\r\n]/',$email)
        && !preg_match('/@(sync\.local|example\.(com|org|net)|[^@]+\.invalid)$/i',$email);
}
function sendLoginCode(string $to,string $code,?string $from=null): bool {
    if(!function_exists('mail'))return false;
    $from=$from??emailSecuritySettings()['from'];
    if(!is_string($from)||!filter_var($from,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$from))return false;
    $subject='NiK Smart Count - Login verification code';
    $body="Your login verification code is: $code\n\nValid for 5 minutes. Do not share this code.\nIf you did not request a login, change your password and contact your administrator.\n\nNiK Smart Count";
    // cPanel routes PHP mail through its local Exim service. Set SPF/DKIM in Email Deliverability.
    return mail($to,$subject,$body,['From'=>$from,'Content-Type'=>'text/plain; charset=UTF-8','X-Auto-Response-Suppress'=>'All']);
}
function issueEmailChallenge(PDO $db,array $user): void {
    $db->exec('DELETE FROM email_login_challenges WHERE created_at<NOW()-INTERVAL 1 DAY LIMIT 1000');
    $db->exec('DELETE FROM email_trusted_devices WHERE expires_at<=NOW() LIMIT 1000');
    $email=(string)($user['email']??'');
    if(!usableLoginEmail($email))jsonResponse(false,null,'ایمیل واقعی این حساب ثبت نشده است؛ از مالک یا دفتر هفت بخواهید ایمیل حساب را اصلاح کند.',403);
    // Serialize challenge issuance per account, including simultaneous resend requests.
    $lock='mfa:'.hash('sha256',(string)$user['id']);$q=$db->prepare('SELECT GET_LOCK(:key,5)');$q->execute(['key'=>$lock]);
    if((int)$q->fetchColumn()!==1)jsonResponse(false,null,'دوباره تلاش کنید.',429);
    try{
        $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,45);
        $q=$db->prepare('SELECT COUNT(*) FROM email_login_challenges WHERE created_at>NOW()-INTERVAL 15 MINUTE AND (user_id=:uid OR ip_address=:ip)');$q->execute(['uid'=>$user['id'],'ip'=>$ip]);
        if((int)$q->fetchColumn()>=10)jsonResponse(false,null,'ارسال کُد زیاد تکرار شده است؛ ۱۵ دقیقه بعد تلاش کنید.',429);
        $challenge=bin2hex(random_bytes(32));$code=(string)random_int(100000,999999);
        $hash=hash('sha256',$challenge);$codeHash=hash_hmac('sha256',$hash.':'.$code,JWT_SECRET_KEY);
        $q=$db->prepare('INSERT INTO email_login_challenges(challenge_hash,user_id,token_version,code_hash,ip_address,expires_at) VALUES(:hash,:uid,:ver,:code,:ip,NOW()+INTERVAL 5 MINUTE)');
        $q->execute(['hash'=>$hash,'uid'=>$user['id'],'ver'=>(int)($user['token_version']??0),'code'=>$codeHash,'ip'=>$ip]);
        if(!sendLoginCode($email,$code)){
            $q=$db->prepare('UPDATE email_login_challenges SET consumed_at=NOW() WHERE challenge_hash=:hash');$q->execute(['hash'=>$hash]);
            jsonResponse(false,null,'ایمیل ارسال نشد. تنظیم ارسال ایمیل هاست باید بررسی شود.',503);
        }
        // New codes supersede older pending codes for this account, after successful delivery acceptance.
        $q=$db->prepare('UPDATE email_login_challenges SET consumed_at=NOW() WHERE user_id=:uid AND challenge_hash<>:hash AND consumed_at IS NULL');$q->execute(['uid'=>$user['id'],'hash'=>$hash]);
        $parts=explode('@',$email);$masked=substr($parts[0],0,1).'***@'.$parts[1];
        jsonResponse(true,['requiresVerification'=>true,'challengeId'=>$challenge,'emailHint'=>$masked,'expiresIn'=>300],'کُد تأیید به ایمیل حساب فرستاده شد.');
    }finally{$q=$db->prepare('SELECT RELEASE_LOCK(:key)');$q->execute(['key'=>$lock]);}
}
function verifyEmailChallenge(PDO $db,array $input): array {
    $id=$input['challengeId']??'';$code=$input['code']??'';
    if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)||!is_string($code)||!preg_match('/^[0-9]{6}$/D',$code))jsonResponse(false,null,'کُد معتبر شش‌رقمی را وارد کنید.',400);
    $hash=hash('sha256',$id);
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT *, (expires_at>NOW()) AS not_expired FROM email_login_challenges WHERE challenge_hash=:hash FOR UPDATE');$q->execute(['hash'=>$hash]);$row=$q->fetch();
        if(!$row||$row['consumed_at']!==null||(int)$row['not_expired']!==1||(int)$row['attempts']>=5){$db->rollBack();jsonResponse(false,null,'کُد منقضی یا نامعتبر است؛ دوباره وارد شوید.',401);}
        $q=$db->prepare('UPDATE email_login_challenges SET attempts=attempts+1 WHERE challenge_hash=:hash');$q->execute(['hash'=>$hash]);
        if(!hash_equals($row['code_hash'],hash_hmac('sha256',$hash.':'.$code,JWT_SECRET_KEY))){$db->commit();jsonResponse(false,null,'کُد اشتباه است.',401);}
        $q=$db->prepare('SELECT * FROM users WHERE id=:uid FOR UPDATE');$q->execute(['uid'=>$row['user_id']]);$user=$q->fetch();
        if(!$user||(int)($user['token_version']??0)!==(int)$row['token_version']){$db->rollBack();jsonResponse(false,null,'حساب تغییر کرده است؛ دوباره وارد شوید.',401);}
        assertLoginAccountActive($db,$user);
        $q=$db->prepare('UPDATE email_login_challenges SET consumed_at=NOW() WHERE challenge_hash=:hash');$q->execute(['hash'=>$hash]);
        $deviceToken=null;
        if(($input['trustDevice']??false)===true){
            $deviceToken=bin2hex(random_bytes(32));
            $q=$db->prepare('INSERT INTO email_trusted_devices(token_hash,user_id,token_version,remaining_uses,expires_at) VALUES(:hash,:uid,:ver,5,NOW()+INTERVAL 30 DAY)');
            $q->execute(['hash'=>hash('sha256',$deviceToken),'uid'=>$user['id'],'ver'=>(int)($user['token_version']??0)]);
        }
        $db->commit();
        if($deviceToken!==null)setcookie(trustedDeviceCookieName((string)$user['id']),$deviceToken,['expires'=>time()+86400*30,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
        return $user;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
