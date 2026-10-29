<?php
require_once __DIR__.'/db.php';require_once __DIR__.'/mail-configuration.php';sendSecurityHeaders();
$user=authenticateUser(true);
if(($_SERVER['REQUEST_METHOD']??'')==='GET')jsonResponse(true,['from'=>emailSecuritySettings()['from'],'email'=>$user['email']]);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')jsonResponse(false,null,'Method not allowed',405);
$input=getJsonInput();
// Always prove delivery to the current master's mailbox, not a recipient chosen by the browser.
$input['email']=(string)$user['email'];
$step=mailConfigurationStep($input,'master:'.$user['id'].':'.($user['token_version']??0));
if(($input['mailAction']??'')==='save'){
    saveEmailConfiguration($step['from']);
    logAudit(Database::getConnection(),$user['id'],$user['name'],'update','email_security',null,'Security sender changed after email verification.');
    jsonResponse(true,['saved'=>true]);
}
jsonResponse(true,$step);
