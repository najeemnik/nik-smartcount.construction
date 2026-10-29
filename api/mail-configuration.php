<?php
/** Shared setup proof, stored privately and bound to sender, recipient and authenticated actor. */
require_once __DIR__.'/email-security.php';
function persistMailConfigurationProof(string $file,array $state): void {
    $raw=json_encode($state,JSON_THROW_ON_ERROR);$tmp=tempnam(dirname($file),'proof-');
    if($tmp===false)throw new RuntimeException('Cannot persist email verification');
    try{
        if(!chmod($tmp,0600)||file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw)||!rename($tmp,$file))throw new RuntimeException('Cannot persist email verification');
    }finally{if(is_file($tmp))unlink($tmp);}
}
function mailConfigurationStep(array $input,string $scope): array {
    $from=trim((string)($input['from']??''));$to=strtolower(trim((string)($input['email']??'')));
    if(!usableLoginEmail($from)||!usableLoginEmail($to))jsonResponse(false,null,'ایمیل فرستنده و ایمیل دریافت‌کننده باید واقعی و معتبر باشد.',400);
    $file=__DIR__.'/.secrets/mail-setup-'.hash('sha256',$scope).'.json';
    $guard=fopen($file.'.guard','c');if(!$guard||!flock($guard,LOCK_EX))jsonResponse(false,null,'تنظیم ایمیل فعلاً در دسترس نیست.',503);
    $state=is_file($file)?json_decode(file_get_contents($file),true):[];if(!is_array($state))$state=[];
    $action=$input['mailAction']??'';
    if($action==='send'){
        $times=array_values(array_filter($state['times']??[],fn($t)=>is_int($t)&&$t>time()-900));
        if(count($times)>=5 || ($times&&end($times)>time()-60))jsonResponse(false,null,'برای ارسال دوباره کمی صبر کنید؛ حداکثر پنج ارسال در ۱۵ دقیقه.',429);
        $times[]=time();$code=(string)random_int(100000,999999);
        $state=['from'=>$from,'email'=>$to,'code'=>hash_hmac('sha256',$scope.':'.$from.':'.$to.':'.$code,JWT_SECRET_KEY),'expires'=>time()+300,'attempts'=>0,'times'=>$times];
        persistMailConfigurationProof($file,$state);
        if(!sendLoginCode($to,$code,$from))jsonResponse(false,null,'هاست ایمیل را ارسال نکرد. سرویس mail و Email Deliverability در cPanel را بررسی کنید.',503);
        return ['sent'=>true];
    }
    if(($state['expires']??0)<=time()||($state['from']??'')!==$from||($state['email']??'')!==$to)jsonResponse(false,null,'تنظیمات تغییر کرده یا کُد منقضی شده است؛ کُد تازه بگیرید.',400);
    if($action==='verify'){
        if(($state['attempts']??0)>=5 || isset($state['proof']))jsonResponse(false,null,'کُد دیگر قابل استفاده نیست؛ کُد تازه بگیرید.',403);
        $state['attempts']++;persistMailConfigurationProof($file,$state);
        $code=$input['code']??'';
        if(!is_string($code)||!preg_match('/^[0-9]{6}$/D',$code)||!hash_equals($state['code'],hash_hmac('sha256',$scope.':'.$from.':'.$to.':'.$code,JWT_SECRET_KEY)))jsonResponse(false,null,'کُد ایمیل اشتباه است.',403);
        $proof=bin2hex(random_bytes(32));$state['proof']=hash('sha256',$proof);unset($state['code']);$state['expires']=time()+900;
        persistMailConfigurationProof($file,$state);return ['proof'=>$proof,'verified'=>true];
    }
    $proof=$input['proof']??'';
    if($action!=='save'||!is_string($proof)||!preg_match('/^[a-f0-9]{64}$/D',$proof)||empty($state['proof'])||!hash_equals($state['proof'],hash('sha256',$proof)))jsonResponse(false,null,'ابتدا دریافت کُد ایمیل را تأیید کنید.',403);
    // Do not consume until persistence succeeds; installation may safely retry after a DB error.
    return ['from'=>$from,'email'=>$to];
}
function saveEmailConfiguration(string $from): void {
    if(!usableLoginEmail($from))throw new InvalidArgumentException('Invalid sender');
    $dir=__DIR__.'/.secrets';$tmp=tempnam($dir,'mail-');
    if($tmp===false)throw new RuntimeException('Cannot save mail settings');
    try{
        if(!chmod($tmp,0600)||file_put_contents($tmp,"<?php\nreturn ".var_export(['enabled'=>true,'from'=>$from],true).";\n",LOCK_EX)===false||!rename($tmp,$dir.'/email.php'))throw new RuntimeException('Cannot save mail settings');
    }finally{if(is_file($tmp))unlink($tmp);}
}
