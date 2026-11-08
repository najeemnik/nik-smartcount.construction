<?php
require '/var/www/html/api/db.php';
if(DB_NAME!=='nsc_audit')throw new RuntimeException('Refusing non-audit database');
$db=Database::getConnection();$accounts=json_decode(file_get_contents('/run/nsc-accounts.json'),true);$password=$accounts[0]['password'];
$view=[];foreach(array_keys(permissionCatalog())as $module)$view[$module]=['view'=>true,'create'=>false,'edit'=>false,'delete'=>false,'approve'=>false,'print'=>false,'export'=>false];
$first=$view;$first['expenses']['create']=true;$first['documents']['create']=true;
$ownerProfile=['staffLimit'=>5];$staffProfile=['projectGrants'=>[['projectId'=>'workflow-a','sections'=>$first]],'assignedProjectIds'=>['workflow-a']];
$q=$db->prepare('INSERT INTO users(id,owner_admin_id,name,username,email,password_hash,role,is_master,is_active,is_locked,permissions_json,app_profile_json) VALUES(?,?,?,?,?,?,?,0,1,0,?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),is_active=1,is_locked=0,permissions_json=VALUES(permissions_json),app_profile_json=VALUES(app_profile_json),token_version=token_version+1');
$q->execute(['workflow-owner',null,'شرکت آموزشی نیک','workflow-demo','workflow-demo@nik-smartcount.com',password_hash($password,PASSWORD_DEFAULT),'admin','{}',json_encode($ownerProfile)]);
$q->execute(['workflow-staff','workflow-owner','حسابدار آموزشی','workflow-staff','workflow-staff@nik-smartcount.com',password_hash($password,PASSWORD_DEFAULT),'accountant',json_encode($first),json_encode($staffProfile)]);
$projects=[];foreach([['workflow-a','برج آموزشی نیک','NSC-DEMO-01'],['workflow-b','پروژهٔ دوم آموزشی','NSC-DEMO-02']]as[$id,$name,$code]){
 $db->prepare('INSERT IGNORE INTO projects(id,tenant_id,project_code,name) VALUES(?,?,?,?)')->execute([$id,'workflow-owner',$code,$name]);
 $projects[]=['id'=>$id,'name'=>$name,'code'=>$code,'city'=>'کابل','address'=>'ساحهٔ آموزشی','floors'=>5,'units'=>10,'status'=>'active','currency'=>'USD','baseCurrency'=>'USD','defaultExchangeRate'=>70];
}
$state=['projects'=>$projects,'expenses'=>[],'payments'=>[],'labor'=>[],'employees'=>[],'payrollPayments'=>[],'projectFolders'=>[['id'=>'workflow-root','name'=>'اسناد آموزشی','projectId'=>'workflow-a','parentFolderId'=>null,'category'=>'other','createdAt'=>date('c')]],'projectFiles'=>[],'appSettings'=>['companyName'=>'شرکت آموزشی نیک','exchangeRateUSDToAFN'=>70]];
$db->prepare('INSERT INTO app_workspaces(tenant_id,state_version,state_json) VALUES(?,1,?) ON DUPLICATE KEY UPDATE state_version=state_version+1,state_json=VALUES(state_json)')->execute(['workflow-owner',json_encode($state,JSON_UNESCAPED_UNICODE)]);
echo "Isolated workflow fixtures ready\n";
