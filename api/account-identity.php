<?php
/** Reuse login identifiers only for an explicitly new, authorized account. Never reuse its ID or data. */
function canReuseRemovedIdentity(array $actor,array $removed): bool {
    if(empty($removed['deleted_at'])||(int)($removed['is_master']??0)===1)return false;
    return (int)($actor['is_master']??0)===1 || (($actor['role']??'')==='admin'&&(string)($removed['owner_admin_id']??'')===(string)$actor['id']);
}
/** Called inside the new-account INSERT transaction, after all normal creation checks. */
function retireRemovedIdentities(PDO $db,array $actor,string $username,string $email): bool {
    $q=$db->prepare('SELECT * FROM users WHERE LOWER(username)=LOWER(:username) OR LOWER(email)=LOWER(:email) ORDER BY id FOR UPDATE');
    $q->execute(['username'=>$username,'email'=>$email]);$matches=$q->fetchAll();
    foreach($matches as $removed)if(!canReuseRemovedIdentity($actor,$removed))return false;
    foreach($matches as $removed){
        $rawProfile=$removed['app_profile_json']??'{}';if(trim($rawProfile)==='')$rawProfile='{}';
        $profile=json_decode($rawProfile,true,512,JSON_THROW_ON_ERROR);if(!is_array($profile))$profile=['removedLegacyProfile'=>$profile];
        $profile['removedIdentity']??=['username'=>$removed['username'],'email'=>$removed['email'],'retiredAt'=>date('c')];
        $archive='removed-'.bin2hex(random_bytes(16));
        $q=$db->prepare('UPDATE users SET username=:username,email=:email,app_profile_json=:profile,is_active=0,is_locked=1,token_version=token_version+1 WHERE id=:id AND deleted_at IS NOT NULL AND is_master=0');
        $q->execute(['username'=>$archive,'email'=>$archive.'@deleted.invalid','profile'=>json_encode($profile,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'id'=>$removed['id']]);
        logAudit($db,$actor['id'],$actor['name'],'update','user_sync',$removed['id'],'Removed account identifiers archived for a fresh account; original identity retained privately; financial records preserved.');
    }
    return true;
}
