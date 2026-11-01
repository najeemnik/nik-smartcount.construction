/* Only non-secret presence markers remain in Storage. The session credential is HttpOnly. */
(() => {
 const originalFetch=window.fetch.bind(window),nativeSet=Storage.prototype.setItem,nativeRemove=Storage.prototype.removeItem;
 const master=/^\/(office7(?:-login)?|security|support-settings)\.php$/.test(location.pathname),kind=master?'master':'user',key=master?'nsc_master_token':'nsc_auth_token';
 const entry=/^\/(?:office7-login|login)\.php$/.test(location.pathname);let csrf='',suppress=false,redirecting=false;const logoutKey='nsc_logout_requested_'+kind;
 const hadMarker=!!localStorage.getItem(key);
 for(const name of ['nsc_auth_token','nsc_master_token'])if(localStorage.getItem(name)&&localStorage.getItem(name)!=='cookie-session')nativeRemove.call(localStorage,name);
 nativeRemove.call(localStorage,'nsc_office7_key');
 function marker(value,name=key){suppress=true;value?nativeSet.call(localStorage,name,'cookie-session'):nativeRemove.call(localStorage,name);suppress=false}
 function authFailure(error){
  if(entry)return false;const code=error?.data?.code;
  if(error?.status!==401&&!/^(?:ACCOUNT_|COMPANY_|OWNER_UNAVAILABLE|SERVICES_DISABLED)/.test(code||''))return false;
  if(redirecting)return true;redirecting=true;window.NSC_AUTH_REDIRECTING=true;
  marker(false);sessionStorage.setItem('nsc_auth_notice',code||'SESSION_EXPIRED');
  location.replace(master?'/office7-login.php':'/login.php');return true;
 }
 window.NSC_HANDLE_AUTH_ERROR=authFailure;
 const activeHeaders=()=>({'X-Requested-With':'XMLHttpRequest','X-NSC-Portal':kind});
 window.NSC_SESSION_READY=(async()=>{
  try{const response=await originalFetch('/api/auth.php',{headers:activeHeaders(),credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(10000)});const body=await response.json();
   if(response.ok&&body.success){
    csrf=body.data.csrfToken||'';
    if(localStorage.getItem(logoutKey)){
     const logout=await originalFetch('/api/auth.php?action=logout',{method:'POST',headers:{...activeHeaders(),'Content-Type':'application/json','X-NSC-CSRF':csrf},body:'{}',credentials:'same-origin',signal:AbortSignal.timeout(5000)});
     if(logout.ok||logout.status===401)nativeRemove.call(localStorage,logoutKey);marker(false);return null;
    }
    marker(true);return body.data
   }
   marker(false);if(hadMarker||!entry)sessionStorage.setItem('nsc_auth_notice',body.data?.code||'SESSION_EXPIRED');return null;
  }catch{return null}
 })();
 window.fetch=async(input,init={})=>{
  const url=new URL(input instanceof Request?input.url:String(input),location.href);
  if(url.origin!==location.origin||!url.pathname.startsWith('/api/'))return originalFetch(input,init);
  const headers=new Headers(input instanceof Request?input.headers:undefined);new Headers(init.headers).forEach((v,k)=>headers.set(k,v));
  const auth=headers.get('Authorization');if(auth==='Bearer cookie-session')headers.delete('Authorization');
  const method=(init.method||(input instanceof Request?input.method:'GET')).toUpperCase(),action=url.searchParams.get('action');
  const publicAuth=url.pathname==='/api/auth.php'&&['login','master_login','verify_email_code'].includes(action);
  let body=init.body;
  if(publicAuth)await window.NSC_SESSION_READY;
  if(publicAuth&&typeof body==='string'){try{body=JSON.stringify({...JSON.parse(body),sessionMode:'cookie'})}catch{}}
  headers.set('X-NSC-Portal',kind);headers.set('X-Requested-With','XMLHttpRequest');
  if(!publicAuth&&['POST','PUT','PATCH','DELETE'].includes(method)&&!headers.has('Authorization')){await window.NSC_SESSION_READY;if(csrf)headers.set('X-NSC-CSRF',csrf)}
  const response=await originalFetch(input,{...init,headers,body,credentials:'same-origin'});
  try{const result=await response.clone().json();if(publicAuth&&result.success&&result.data?.authenticated)nativeRemove.call(localStorage,logoutKey);if(result.success&&result.data?.csrfToken)csrf=result.data.csrfToken;
   if(!publicAuth&&!response.ok)authFailure({status:response.status,data:result.data});
  }catch{}return response;
 };
 async function logoutRequest(){
  nativeSet.call(localStorage,logoutKey,'1');
  await window.NSC_SESSION_READY;
  try{const response=await originalFetch('/api/auth.php?action=logout',{method:'POST',headers:{...activeHeaders(),'Content-Type':'application/json','X-NSC-CSRF':csrf},body:'{}',credentials:'same-origin',keepalive:true,signal:AbortSignal.timeout(5000)});if(response.ok||response.status===401)nativeRemove.call(localStorage,logoutKey)}catch{}finally{marker(false)}
 }
 window.NSC_LOGOUT=()=>window.NSC_LOGOUT_PENDING=logoutRequest();
 Storage.prototype.removeItem=function(name){
  nativeRemove.call(this,name);
  if(this!==localStorage||suppress||!['nsc_auth_token','nsc_master_token'].includes(name))return;
  if((name==='nsc_master_token'?'master':'user')!==kind)return;
  window.NSC_LOGOUT();
 };
 window.NSC_SHOW_ACCOUNT_STATUS=(data)=>{
  marker(false);sessionStorage.setItem('nsc_account_status',JSON.stringify(data.accountStatus));location.replace('/account-status.php');
 };
 window.NSC_SHOW_BOOT_ERROR=(target,error)=>{
  if(redirecting||authFailure(error))return;
  target.replaceChildren();const card=document.createElement('main');card.className='nsc-status-card';card.dir=document.documentElement.dir;
  const title=document.createElement('h1');title.textContent=NSC_UI.t('Connection interrupted','ارتباط کامل نشد');const message=document.createElement('p');message.textContent=error.message||'اتصال اینترنت را بررسی کنید.';
  const retry=document.createElement('button');retry.textContent=NSC_UI.t('Try again','تلاش دوباره');retry.onclick=()=>location.reload();
  const login=document.createElement('a');login.textContent=NSC_UI.t('Sign in','ورود به حساب');login.href=master?'/office7-login.php':'/login.php';card.append(title,message,retry,login);target.append(card);
 };
})();
