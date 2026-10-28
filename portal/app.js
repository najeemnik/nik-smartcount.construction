import {api,esc} from './common.js';
import {merge,equal} from './merge.js';
const financialKeys=ks=>ks.filter(k=>!['activityEvents','auditLogs','appSettings','fileManagerPolicy'].includes(k));
function meaningful(remote){return financialKeys(Object.keys(remote)).some(k=>!equal(baseline[k],remote[k]));}
const prefix='nik_smart_count_',baseSet=Storage.prototype.setItem,baseRemove=Storage.prototype.removeItem;
let user,tenant,scope,version=0,baseline={},writable=[],keys=[],dirty=new Set(),ready=false,writing=false,blocked=false,timer,retry=1000,recoveryKey,lastInput=Date.now(),sessionToken;const cache={};
const status=document.createElement('aside');status.className='app-sync error';status.hidden=true;status.setAttribute('role','alert');document.body.append(status);
function warning(message){status.hidden=false;status.innerHTML=`<p>${esc(message)}</p><button id="recovery">دانلود نسخهٔ محفوظ</button><button id="retry-save">تلاش دوباره</button>`;status.querySelector('#recovery').onclick=backup;status.querySelector('#retry-save').onclick=()=>blocked?location.reload():save();}
function clearWarning(){status.hidden=true;}
const localKey=k=>k==='appSettings'?prefix+k:prefix+scope+':'+k;
function writeField(k,value){const raw=JSON.stringify(value);baseSet.call(localStorage,localKey(k),raw);cache[k]=raw;}
function read(k){const raw=cache[k]??localStorage.getItem(localKey(k));try{return raw?JSON.parse(raw):k==='appSettings'||k==='fileManagerPolicy'||k==='rebarSpecMemory'?{}:[];}catch{throw Error('معلومات محلی معتبر نیست؛ برای حفظ حساب‌ها عملیات متوقف شد.');}}
function state(){return Object.fromEntries(keys.map(k=>[k,read(k)]));}
function remember(){if(!recoveryKey)return;try{const data=Object.fromEntries([...dirty].map(k=>[k,read(k)]));if(dirty.size)baseSet.call(localStorage,recoveryKey,JSON.stringify({tenantId:tenant,userId:user.id,baseVersion:version,baseline,state:data}));else baseRemove.call(localStorage,recoveryKey);}catch{warning('فضای ذخیرهٔ موقت مرورگر کافی نیست؛ تا تکمیل ثبت در سرور صفحه را نبندید.');}}

function backup(){const blob=new Blob([JSON.stringify({format:'nsc-recovery-v2',tenantId:tenant,userId:user.id,baseVersion:version,baseline,state:state()},null,2)],{type:'application/json'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='NiK-unsaved-'+Date.now()+'.json';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000);}
function schedule(delay=250){clearTimeout(timer);timer=setTimeout(save,delay);}
async function save(){
 if(!ready||writing||blocked||!dirty.size)return;
 writing=true;const pending=[...dirty],sent=Object.fromEntries(pending.map(k=>[k,read(k)]));let reload=false;
 try{
  let result;
  try{result=await api('workspace.php',{tenantId:tenant,baseVersion:version,state:sent});}
  catch(e){
   if(e.status!==409)throw e;
   const remote=await api('workspace.php');
   const refresh=meaningful(remote.state);const combined={};try{for(const k of pending)combined[k]=merge(baseline[k]??[],sent[k],remote.state[k]??[]);}catch{blocked=true;warning('همین رکورد در دستگاه دیگری تغییر کرده است. نسخهٔ این دستگاه محفوظ است؛ قبل از ادامه با مسئول حساب بررسی کنید.');return;}
   result=await api('workspace.php',{tenantId:tenant,baseVersion:remote.stateVersion,state:combined});
   for(const k of pending){if(equal(read(k),sent[k])){writeField(k,combined[k]);sent[k]=combined[k];}else{try{const next=merge(sent[k],read(k),combined[k]);writeField(k,next);sent[k]=combined[k];}catch{blocked=true;warning('تغییر هم‌زمان نیاز به بررسی دارد؛ معلومات محلی محفوظ مانده است.');return;}}}
   baseline=remote.state;reload=refresh;
  }
  version=result.stateVersion;
  for(const k of pending){baseline[k]=sent[k];if(equal(read(k),sent[k]))dirty.delete(k);}
  retry=1000;remember();clearWarning();
  if(reload&&!dirty.size){ready=false;location.reload();return;}
 }catch(e){
  if(e.status===401||e.status===403){blocked=true;warning('ثبت معلومات تأیید نشد: '+e.message+' معلومات این دستگاه محفوظ مانده است.');}
  else{warning((e.status?'ثبت در سرور ناکام شد. HTTP '+e.status+'؛ '+e.message:'ارتباط با سرور قطع است؛ تغییرات این دستگاه محفوظ است و خودکار دوباره ارسال می‌شود.'));retry=Math.min(retry*2,30000);}
 }finally{writing=false;remember();if(dirty.size&&!blocked)schedule(retry);}
}
function canonical(u){return {...u,verified:true,active:u.active!==false,isMasterSuperAdmin:false,createdAt:u.createdAt||new Date().toISOString(),permissions:{...(u.permissions||{}),allowedTabs:u.allowedTabs||[]}};}
async function start(){
 localStorage.removeItem('nsc_test_mode');localStorage.setItem(prefix+'lang',JSON.stringify(localStorage.getItem('nsc_portal_lang')||'fa'));
 sessionToken=localStorage.getItem('nsc_auth_token');if(!sessionToken){location.replace('/login.php');return;}
 user=(await api('auth.php')).user;if(user.isMasterSuperAdmin){localStorage.removeItem('nsc_auth_token');location.replace('/login.php');return;}
 const initial=await api('workspace.php');tenant=initial.tenantId;scope=tenant;version=initial.stateVersion;baseline=structuredClone(initial.state);writable=initial.writableKeys;keys=Object.keys(initial.state);
 let tabId=sessionStorage.getItem('nsc_tab_id');if(!tabId){tabId=crypto.randomUUID();sessionStorage.setItem('nsc_tab_id',tabId);}
 recoveryKey=`nsc_pending_v2_${tenant}_${user.id}_${tabId}`;
 let recovery;try{recovery=JSON.parse(localStorage.getItem(recoveryKey)||'null');}catch{}
 const legacyKey='nsc_pending_workspace_'+tenant;
 if(!recovery){try{const old=JSON.parse(localStorage.getItem(legacyKey)||'null');if(old){recovery={...old,tenantId:tenant,userId:user.id};}}catch{}}
 // Remove data no longer visible to the current account before hydration.
 for(const k of Object.keys(localStorage))if(k.startsWith(prefix+scope+':'))baseRemove.call(localStorage,k);baseRemove.call(localStorage,prefix+'appSettings');
 // Never import stale local snapshots merely because a database is empty.
 for(const [k,v]of Object.entries(initial.state)){const value=k==='appSettings'&&!Object.keys(v).length?{companyName:user.companyName||'NiK Smart Count - Construction',companySubtitle:'حسابداری هوشمند نیک - ساختمانی',logoUrl:'/assets/brand-full.webp',receiptHeader:user.companyName||'NiK Smart Count',receiptContact:user.phone||'',sectionProtection:{passwords:{},enabled:{}}}:v;if(k==='appSettings'&&value.logoUrl==='/assets/logo.png')value.logoUrl='/assets/brand-full.webp';writeField(k,value);}
 if(recovery?.tenantId===tenant&&recovery?.userId===user.id){
  try{for(const [k,v]of Object.entries(recovery.state||{})){if(!writable.includes(k))continue;const value=recovery.baseVersion===version?v:merge(recovery.baseline?.[k]??[],v,initial.state[k]);writeField(k,value);if(!equal(value,initial.state[k]))dirty.add(k);}}
  catch{blocked=true;warning('نسخهٔ ثبت‌نشدهٔ قدیمی با سرور تفاوت دارد؛ هر دو نسخه محفوظ است. برای بررسی، نسخهٔ محفوظ را دانلود کنید.');for(const [k,v]of Object.entries(recovery.state||{}))if(writable.includes(k)){writeField(k,v);dirty.add(k);}baseline=recovery.baseline||{};version=recovery.baseVersion;}
 }
 localStorage.setItem(prefix+'currentUser',JSON.stringify(canonical(user)));sessionStorage.removeItem(prefix+'currentUser');
 const roster=await api('office7.php?action=tenants');localStorage.setItem(prefix+'users',JSON.stringify(roster.users.map(canonical)));
 Storage.prototype.setItem=function(k,v){const before=this.getItem(k);baseSet.call(this,k,v);if(this!==localStorage||!ready||before===String(v))return;const field=keys.find(f=>localKey(f)===k);if(field){cache[field]=String(v);if(writable.includes(field)){dirty.add(field);schedule();remember();}}};
 Storage.prototype.removeItem=function(k){baseRemove.call(this,k);if(this===localStorage&&k==='nsc_auth_token'&&ready){remember();ready=false;location.replace('/login.php');}};
 ready=true;window.NSC_PERSIST=async()=>{const end=Date.now()+20000;while(writing&&Date.now()<end)await new Promise(r=>setTimeout(r,25));await save();return {success:!dirty.size&&!blocked,error:dirty.size?'ثبت در سرور هنوز تأیید نشده است.':undefined};};remember();if(recovery&&!blocked)baseRemove.call(localStorage,legacyKey);
 for(const name of ['input','pointerdown'])document.addEventListener(name,()=>lastInput=Date.now(),true);
 window.addEventListener('beforeunload',e=>{remember();if(dirty.size){e.preventDefault();e.returnValue='';}});
 window.addEventListener('storage',e=>{if(e.key==='nsc_auth_token'&&e.newValue!==sessionToken){remember();ready=false;location.reload();}});
 await import('/nsc-runtime.php?asset=accounting');
 document.getElementById('boot-message')?.remove();
 window.NSC_READY=true;
 if(dirty.size&&!blocked)schedule(0);
 async function poll(){
  if(!ready||writing||blocked)return;if(dirty.size){await save();return;}
  try{const auth=(await api('auth.php')).user;if(!equal([user.role,user.permissions,user.customEnabledModules,user.allowedTabs,user.active,user.staffLimit],[auth.role,auth.permissions,auth.customEnabledModules,auth.allowedTabs,auth.active,auth.staffLimit])){location.reload();return;}
   const remote=await api('workspace.php');if(remote.stateVersion!==version&&meaningful(remote.state)&&!dirty.size&&!writing&&Date.now()-lastInput>30000&&!/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName||'')){ready=false;location.reload();}
  }catch(e){if(e.status===401||e.status===403){blocked=true;warning('دسترسی حساب نیاز به بررسی دارد: '+e.message);}/* No save is pending, so transient polling failures need no alert. */}
 }
 setInterval(poll,60000);window.addEventListener('online',()=>{retry=1000;schedule(0);poll();});document.addEventListener('visibilitychange',()=>{if(document.hidden){remember();save();}else poll();});
}
start().catch(e=>{document.getElementById('root').innerHTML=`<main dir="rtl" style="padding:40px;font-family:system-ui"><h1>NiK Smart Count</h1><p>${esc(e.message)}</p><button onclick="location.reload()">تلاش دوباره</button> <a href="/login.php">ورود به حساب</a></main>`;window.NSC_READY=true;});
