<?php
/** Plain, authenticated account reasons; no account existence/status leaks before password verification. */
function supportContacts(): array {
    $path=__DIR__.'/.secrets/support.php';
    if(is_file($path)&&function_exists('opcache_invalidate'))opcache_invalidate($path,true);
    $settings=is_file($path)?require $path:[];if(!is_array($settings))$settings=[];
    return ['phone'=>(string)($settings['phone']??'+93783788278'),'whatsapp'=>(string)($settings['whatsapp']??'+93783788278'),'email'=>(string)($settings['email']??'info@niksmartcount.com')];
}
function accountRestriction(PDO $db,array $user): ?array {
    $code=null;$expires=null;$notice='';
    if(!empty($user['deleted_at']))$code='ACCOUNT_REMOVED';
    elseif((int)($user['is_locked']??0)===1)$code='ACCOUNT_LOCKED';
    elseif((int)($user['is_active']??1)!==1)$code='ACCOUNT_INACTIVE';
    elseif((int)($user['is_master']??0)!==1&&!empty($user['subscription_expires_at'])&&strtotime($user['subscription_expires_at'])<=time()){$code='SUBSCRIPTION_EXPIRED';$expires=$user['subscription_expires_at'];}
    if($code){$profile=permissionData($user['app_profile_json']??[]);$notice=(string)($profile['accountNotice']??'');}
    if(!$code&&(int)($user['is_master']??0)!==1&&!empty($user['owner_admin_id'])){
        $q=$db->prepare('SELECT * FROM users WHERE id=:id AND role="admin" AND is_master=0');$q->execute(['id'=>$user['owner_admin_id']]);$owner=$q->fetch();
        if(!$owner||!empty($owner['deleted_at']))$code='OWNER_UNAVAILABLE';
        elseif((int)($owner['is_locked']??0)===1)$code='COMPANY_LOCKED';
        elseif((int)($owner['is_active']??1)!==1)$code='COMPANY_INACTIVE';
        elseif(!empty($owner['subscription_expires_at'])&&strtotime($owner['subscription_expires_at'])<=time()){$code='COMPANY_EXPIRED';$expires=$owner['subscription_expires_at'];}
        if($code&&$owner){$profile=permissionData($owner['app_profile_json']??[]);$notice=(string)($profile['accountNotice']??'');}
    }
    if(!$code)return null;
    $messages=['ACCOUNT_REMOVED'=>'دسترسی این حساب حذف شده است.','ACCOUNT_LOCKED'=>'این حساب توسط مدیریت قفل شده است.','ACCOUNT_INACTIVE'=>'این حساب توسط مدیریت غیرفعال شده است.','SUBSCRIPTION_EXPIRED'=>'مدت پکیج این حساب تمام شده است.','OWNER_UNAVAILABLE'=>'حساب مالک شرکت در دسترس نیست.','COMPANY_LOCKED'=>'دسترسی شرکت توسط مدیریت قفل شده است.','COMPANY_INACTIVE'=>'دسترسی شرکت غیرفعال شده است.','COMPANY_EXPIRED'=>'مدت پکیج شرکت تمام شده است.'];
    return ['code'=>$code,'message'=>$messages[$code],'notice'=>mbAccountNotice($notice),'expiresAt'=>$expires];
}
function mbAccountNotice(string $value): string {return strlen($value)<=1200?$value:'';}
function denyAccountRestriction(array $restriction): void {
    jsonResponse(false,['code'=>$restriction['code'],'accountStatus'=>$restriction,'support'=>supportContacts()],$restriction['message'],403);
}
function validateAccountNotice($value): string {
    if(!is_string($value)||strlen($value)>1200||preg_match_all('/./us',$value)>300||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))jsonResponse(false,null,'پیام حساب باید متن ساده و حداکثر ۳۰۰ حرف باشد.',400);
    return trim($value);
}
