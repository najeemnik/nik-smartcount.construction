<?php
/**
 * DEPRECATED — مسیر ذخیره‌سازی جایگزین (جدول app_workspaces)
 *
 * کلاینت فعلی این فایل را صدا نمی‌زند؛ مسیر رسمی همگام‌سازی:
 *   office7.php?action=workspace_sync  (جدول workspace_sync_states)
 *
 * این فایل فقط برای سازگاری و مهاجرت نگه داشته شده است. پیش از هر تغییر در منطق
 * همگام‌سازی، ابتدا تصمیم بگیرید کدام مسیر مرجع است و بعد دیگری را بازنشسته کنید؛
 * نگه‌داشتن دو مسیر نوشتن مستقل باعث واگرایی داده می‌شود.
 */
require_once __DIR__ . '/db.php';
sendSecurityHeaders();
$user = authenticateUser();
$db = Database::getConnection();
if ((int)($user['is_master'] ?? 0) === 1) jsonResponse(false,null,'این بخش برای حساب شرکت است.',403);
$tenant = currentTenantId($user);
$map = [
 'fileManagerPolicy'=>'documents','appSettings'=>'settings','projects'=>'projects','expenses'=>'expenses','steel'=>'steel','concrete'=>'concrete',
 'contractors'=>'contractors','suppliers'=>'suppliers','apartments'=>'apartments','assets'=>'assets',
 'labor'=>'labor','employees'=>'labor','payrollPayments'=>'labor','payments'=>'payments',
 'exchangeTransactions'=>'exchange','supplierProductRates'=>'suppliers','rebarSpecMemory'=>'steel',
 'documents'=>'documents','projectFolders'=>'documents','projectFiles'=>'documents',
 'projectPartners'=>'partners','projectInvestments'=>'partners','partnerEquityTransfers'=>'partners',
 'projectBudgets'=>'budget','customAccounts'=>'accounting','interProjectTransfers'=>'payments',
 'activityEvents'=>'reports','auditLogs'=>'reports'
];
function workspaceAllowed(array $u, string $module, string $action, ?string $pid = null): bool {
 if ((int)($u['is_master'] ?? 0) === 1) return true;
 if (!empty($u['_tenant_owner']) && !workspaceAllowed($u['_tenant_owner'], $module, $action, $pid)) return false;
 $aliases = resolveModuleAliases($module);
 $mods = json_decode($u['allowed_modules_json'] ?? '{}', true) ?: [];
 foreach ($aliases as $alias) if (array_key_exists($alias, $mods) && !$mods[$alias]) return false;
 $tabs = json_decode($u['allowed_tabs_json'] ?? '[]', true) ?: [];
 if ($tabs && !array_intersect($aliases, $tabs)) return false;
 if (($u['role'] ?? '') === 'admin') return true;
 if (($u['role'] ?? 'viewer') === 'viewer' && $action !== 'view') return false;
 $profile = json_decode($u['app_profile_json'] ?? '{}', true) ?: [];
 $grants = $profile['projectGrants'] ?? [];
 if ($grants && $pid !== null) {
  $granted = false;
  foreach ($grants as $g) {
   if (!in_array((string)($g['projectId'] ?? ''), ['*', $pid], true)) continue;
   foreach ($aliases as $a) if (!empty($g['sections'][$a]['view']) && ($action === 'view' || !empty($g['sections'][$a][$action]))) $granted = true;
  }
  if (!$granted) return false;
 }
 $perms = json_decode($u['permissions_json'] ?? '{}', true) ?: [];
 foreach ($aliases as $a) {
  if (isset($perms[$a]['view']) && !$perms[$a]['view']) return false;
  if (isset($perms[$a][$action]) && !$perms[$a][$action]) return false;
 }
 return in_array($u['role'] ?? '', ['viewer','accountant','project_manager','procurement'], true);
}
function workspaceRows($value, string $key, string $module, array $u): array {
 if (!is_array($value)) return [];
 if (in_array($key,['fileManagerPolicy','appSettings','rebarSpecMemory'],true)) return $value;
 return array_values(array_filter($value, function ($row) use ($key, $module, $u) {
  if (!is_array($row)) return false;
  $pid = (string)($key === 'projects' ? ($row['id'] ?? '') : ($row['projectId'] ?? $row['project_id'] ?? ''));
  return workspaceAllowed($u, $module, 'view', $pid !== '' ? $pid : null);
 }));
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) jsonResponse(false,null,'Method not allowed.',405);
if ($method === 'POST') {
 $input = getJsonInput();
 if(isset($input['tenantId']) && !hash_equals($tenant,(string)$input['tenantId']))jsonResponse(false,null,'حساب فعال تغییر کرده است. صفحه را دوباره باز کنید.',403);
 if (!isset($input['baseVersion']) || !is_int($input['baseVersion']) || !isset($input['state']) || !is_array($input['state'])) jsonResponse(false,null,'نسخهٔ پایه و معلومات معتبر الزامی است.',400);
 foreach ($input['state'] as $key=>$value) {
  if (!isset($map[$key]) || !is_array($value)) jsonResponse(false,null,'بخش نامعتبر در ذخیره‌سازی.',400);
  if (!workspaceAllowed($user,$map[$key],'edit') && !workspaceAllowed($user,$map[$key],'create') && !workspaceAllowed($user,$map[$key],'delete')) jsonResponse(false,null,'اجازهٔ تغییر این بخش را ندارید.',403);
  foreach ($value as $row) {
   if (!is_array($row)) continue;
   $pid=(string)($key==='projects'?($row['id']??''):($row['projectId']??$row['project_id']??''));
   if ($pid!=='' && !workspaceAllowed($user,$map[$key],'view',$pid)) jsonResponse(false,null,'اجازهٔ تغییر پروژه را ندارید.',403);
  }
 }
 $db->beginTransaction();
 try {
  $s=$db->prepare('INSERT IGNORE INTO app_workspaces (tenant_id, state_version, state_json) VALUES (:tid,0,:state)');$s->execute(['tid'=>$tenant,'state'=>'{}']);
  $s=$db->prepare('SELECT * FROM app_workspaces WHERE tenant_id=:tid FOR UPDATE');$s->execute(['tid'=>$tenant]);$row=$s->fetch();
  $current=json_decode($row['state_json'],true)?:[];
  foreach ($input['state'] as $key=>$value) {
   $module=$map[$key];$old=$current[$key]??[];
   if (in_array($key,['appSettings','fileManagerPolicy','rebarSpecMemory'],true)) {
    if ($old!=$value && !workspaceAllowed($user,$module,'edit')) {$db->rollBack();jsonResponse(false,null,'صلاحیت ویرایش تنظیمات را ندارید.',403);}
    continue;
   }
   $before=[];foreach($old as $i=>$r)if(is_array($r))$before[(string)($r['id']??$r['code']??('row-'.$i))]=$r;
   $after=[];foreach($value as $i=>$r)if(is_array($r))$after[(string)($r['id']??$r['code']??('row-'.$i))]=$r;
   foreach($after as $id=>$r){
    $action=isset($before[$id])?'edit':'create';
    if(isset($before[$id]) && $before[$id]==$r)continue;
    $pid=(string)($key==='projects'?($r['id']??''):($r['projectId']??$r['project_id']??''));
    if(!workspaceAllowed($user,$module,$action,$pid!==''?$pid:null)){$db->rollBack();jsonResponse(false,null,'صلاحیت این عملیات را ندارید: '.$action,403);}
   }
   foreach($before as $id=>$r){
    if(isset($after[$id]))continue;
    $pid=(string)($key==='projects'?($r['id']??''):($r['projectId']??$r['project_id']??''));
    if(!workspaceAllowed($user,$module,'view',$pid!==''?$pid:null))continue;
    if(!workspaceAllowed($user,$module,'delete',$pid!==''?$pid:null)){$db->rollBack();jsonResponse(false,null,'صلاحیت حذف ندارید.',403);}
   }
  }
  // Idempotent retry: identical data acknowledges the current version even after a lost response.
  $same=true; foreach ($input['state'] as $key=>$value) if (workspaceRows($current[$key]??[],$key,$map[$key],$user)!=$value) {$same=false;break;}
  if ($same) {$db->commit();jsonResponse(true,['stateVersion'=>(int)$row['state_version'],'unchanged'=>true]);}
  if ((int)$row['state_version']!==$input['baseVersion']) {$db->rollBack();jsonResponse(false,['stateVersion'=>(int)$row['state_version']],'نسخهٔ سرور تغییر کرده است. قبل از ذخیره، تعارض را حل کنید.',409);}
  foreach ($input['state'] as $key=>$value) {
   if (!in_array($key,['appSettings','fileManagerPolicy','rebarSpecMemory'],true)) {
    $hidden=array_values(array_filter($current[$key]??[],function($r)use($key,$map,$user){if(!is_array($r))return false;$pid=(string)($key==='projects'?($r['id']??''):($r['projectId']??$r['project_id']??''));return !workspaceAllowed($user,$map[$key],'view',$pid!==''?$pid:null);}));
    $hiddenIds=[];foreach($hidden as $r)if(isset($r['id']))$hiddenIds[(string)$r['id']]=true;
    foreach($value as $r)if(is_array($r)&&isset($hiddenIds[(string)($r['id']??'')])){$db->rollBack();jsonResponse(false,null,'شناسهٔ رکورد خارج از صلاحیت است.',403);}
    $value=array_merge($hidden,$value);
   }
   $current[$key]=$value;
  }
  $json=json_encode($current,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  $s=$db->prepare('UPDATE app_workspaces SET state_json=:state,state_version=state_version+1,updated_by=:uid WHERE tenant_id=:tid');
  $s->execute(['state'=>$json,'uid'=>$user['id'],'tid'=>$tenant]);$version=(int)$row['state_version']+1;
  $db->commit();logAudit($db,$user['id'],$user['name'],'save','workspace',$tenant,'Workspace version '.$version.'; sections: '.implode(',',array_keys($input['state'])));
  jsonResponse(true,['stateVersion'=>$version,'savedAt'=>gmdate('c')]);
 } catch (Throwable $e) {if($db->inTransaction())$db->rollBack();error_log('Workspace save failed: '.$e->getMessage());jsonResponse(false,null,'ذخیرهٔ سرور ناکام شد؛ تغییرات محلی نگهداری شده‌اند.',500);}
}
$s=$db->prepare('SELECT * FROM app_workspaces WHERE tenant_id=:tid');$s->execute(['tid'=>$tenant]);$row=$s->fetch();$raw=$row?(json_decode($row['state_json'],true)?:[]):[];
$state=[];$writable=[];
foreach($map as $key=>$module){
 if(workspaceAllowed($user,$module,'view'))$state[$key]=workspaceRows($raw[$key]??[],$key,$module,$user);
 if(workspaceAllowed($user,$module,'edit')||workspaceAllowed($user,$module,'create')||workspaceAllowed($user,$module,'delete'))$writable[]=$key;
}
jsonResponse(true,['tenantId'=>$tenant,'stateVersion'=>(int)($row['state_version']??0),'state'=>$state,'writableKeys'=>$writable,'updatedAt'=>$row['updated_at']??null]);
