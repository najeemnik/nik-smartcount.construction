<?php
function clientNetworkHash(): string {
    $address=(string)($_SERVER['REMOTE_ADDR']??'');$packed=@inet_pton($address);
    return hash_hmac('sha256','nsc-network:'.($packed===false?$address:bin2hex($packed)),JWT_SECRET_KEY);
}
function clientDeviceHash(): string {return hash('sha256',(string)($_SERVER['HTTP_USER_AGENT']??''));}
function webSessionKind(bool $master=false): string {return $master||($_SERVER['HTTP_X_NSC_PORTAL']??'')==='master'?'master':'user';}
function webCookieName(string $kind): string {return $kind==='master'?'__Host-nsc_master_session':'__Host-nsc_session';}
function clearWebCookie(string $kind): void {setcookie(webCookieName($kind),'',['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);}
function ensureWebSessionSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS web_sessions (session_hash CHAR(64) PRIMARY KEY,user_id VARCHAR(64) NOT NULL,token_version INT NOT NULL,email_verified TINYINT NOT NULL DEFAULT 0,network_hash CHAR(64) NOT NULL DEFAULT '',expires_at DATETIME NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(user_id),INDEX(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function migrateCookieUser(PDO $db,array $user): array {
    if(!empty($user['cookie_session_enabled']))return $user;
    $q=$db->prepare('UPDATE users SET cookie_session_enabled=1,token_version=token_version+1 WHERE id=:id AND cookie_session_enabled=0');$q->execute(['id'=>$user['id']]);
    $q=$db->prepare('SELECT * FROM users WHERE id=:id');$q->execute(['id'=>$user['id']]);return $q->fetch();
}
function webCsrf(string $hash): string {return hash_hmac('sha256','nsc-csrf:'.$hash,JWT_SECRET_KEY);}
function issueWebSession(PDO $db,array $user,bool $emailVerified): array {
    ensureWebSessionSchema($db);$db->exec('DELETE FROM web_sessions WHERE expires_at<=NOW() LIMIT 1000');
    $columns=$db->query('SHOW COLUMNS FROM web_sessions')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('network_hash',$columns,true))$db->exec("ALTER TABLE web_sessions ADD COLUMN network_hash CHAR(64) NOT NULL DEFAULT ''");
    $secret=bin2hex(random_bytes(32));$hash=hash('sha256',$secret);$kind=(int)$user['is_master']===1?'master':'user';
    $q=$db->prepare('INSERT INTO web_sessions(session_hash,user_id,token_version,email_verified,network_hash,expires_at) VALUES(:hash,:id,:version,:verified,:network,NOW()+INTERVAL 30 DAY)');$q->execute(['hash'=>$hash,'id'=>$user['id'],'version'=>(int)($user['token_version']??0),'verified'=>$emailVerified?1:0,'network'=>clientNetworkHash()]);
    setcookie(webCookieName($kind),$secret,['expires'=>time()+86400*30,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    return ['csrfToken'=>webCsrf($hash),'sessionKind'=>$kind];
}
function readWebSession(PDO $db,string $kind): ?array {
    $value=$_COOKIE[webCookieName($kind)]??'';if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/D',$value))return null;
    try{$q=$db->prepare('SELECT *,expires_at>NOW() AS valid FROM web_sessions WHERE session_hash=:hash');$q->execute(['hash'=>hash('sha256',$value)]);$row=$q->fetch();return $row&&(int)$row['valid']===1&&hash_equals((string)($row['network_hash']??''),clientNetworkHash())?$row:null;}
    catch(PDOException $e){if($e->getCode()==='42S02')return null;throw $e;}
}
function enforceWebCsrf(array $session): void {
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['POST','PUT','PATCH','DELETE'],true))return;
    $provided=$_SERVER['HTTP_X_NSC_CSRF']??'';
    if(!is_string($provided)||!hash_equals(webCsrf($session['session_hash']),$provided))jsonResponse(false,['code'=>'CSRF_REQUIRED'],'درخواست معتبر نیست؛ صفحه را تازه و دوباره تلاش کنید.',403);
}
