/* Public recovery UI for both portals. Codes/passwords never enter browser storage. */
(() => {
 'use strict';
 const master=location.pathname.startsWith('/office7'),portal=master?'master':'user';
 const text=(en,fa,ps=fa)=>document.documentElement.lang==='en'?en:document.documentElement.lang==='ps'?ps:fa;
 const digits=s=>s.replace(/[۰-۹٠-٩]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.includes(c)?String('۰۱۲۳۴۵۶۷۸۹'.indexOf(c)):String('٠١٢٣٤٥٦٧٨٩'.indexOf(c)));
 async function request(action,data){
  const response=await fetch('/api/auth.php?action='+action,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({...data,portal}),signal:AbortSignal.timeout(15000)});
  let body;try{body=await response.json()}catch{throw Error(text('The server response could not be read. Try again.','پاسخ سرور خوانده نشد؛ دوباره تلاش کنید.'))}
  if(!response.ok||!body.success)throw Error(body.error||text('Please try again.','دوباره تلاش کنید.'));
  return body.data;
 }
 function open(){
  if(document.querySelector('#password-recovery'))return;
  const dialog=document.createElement('dialog');dialog.id='password-recovery';dialog.dir=document.documentElement.dir;
  const title=text('Reset your password','بازیابی رمز عبور','د پټنوم بیا ټاکل');
  let identifier=document.querySelector('#username')?.value.trim()||'',challenge='',resendAt=0,timer=null,busy=false;
  const close=()=>{clearInterval(timer);dialog.close();dialog.remove();document.querySelector('#forgot-password')?.focus()};
  const shell=()=>{
   dialog.innerHTML='<form class="dialog-body"><h2></h2><p class="reset-info"></p><div class="reset-fields"></div><p class="form-error" role="alert" hidden></p><div class="actions"><button type="button" class="cancel"></button><button type="submit" class="primary"></button></div></form>';
   dialog.querySelector('h2').textContent=title;
   dialog.querySelector('.cancel').textContent=text('Back to sign in','بازگشت به ورود');dialog.querySelector('.cancel').onclick=close;window.NSC_CODE_UI?.decorate(dialog);
  };
  const field=(id,label,type,autocomplete)=>{
   const wrap=document.createElement('div');wrap.className='field';const l=document.createElement('label');l.htmlFor=id;l.textContent=label;
   const input=document.createElement('input');input.id=id;input.name=id;input.type=type;input.required=true;input.autocomplete=autocomplete;wrap.append(l,input);return {wrap,input};
  };
  const error=e=>{const box=dialog.querySelector('.form-error');box.textContent=e.name==='TimeoutError'?text('Connection timed out. Try again.','مهلت اتصال تمام شد؛ دوباره تلاش کنید.'):e.message;box.hidden=false;window.NSC_CODE_UI?.error(dialog)};
  const loading=on=>{busy=on;window.NSC_CODE_UI?.busy(dialog,on);dialog.querySelector('button[type=submit]').disabled=on;const resend=dialog.querySelector('#reset-resend');if(resend)resend.disabled=on||Date.now()<resendAt};
  function start(){
   clearInterval(timer);shell();dialog.querySelector('.reset-info').textContent=text('Enter your account username or registered email. A recovery code will be sent to the registered email if the account is eligible.','نام کاربری یا ایمیل حساب را وارد کنید؛ کُد بازیابی به ایمیل ثبت‌شدهٔ حساب واجد شرایط فرستاده می‌شود.');
   const f=field('reset-identifier',text('Username or registered email','نام کاربری یا ایمیل ثبت‌شده'),'text','username');f.input.maxLength=150;f.input.value=identifier;dialog.querySelector('.reset-fields').append(f.wrap);
   dialog.querySelector('button[type=submit]').textContent=text('Send recovery code','ارسال کُد بازیابی');
   dialog.querySelector('form').onsubmit=async e=>{e.preventDefault();if(busy)return;identifier=f.input.value.trim();loading(true);dialog.querySelector('.form-error').hidden=true;
    try{const result=await request('request_password_reset',{identifier});if(!dialog.isConnected)return;challenge=result.challengeId;resendAt=Date.now()+result.retryAfter*1000;codeStep()}catch(e){if(dialog.isConnected)error(e)}finally{if(dialog.isConnected)loading(false)}
   };f.input.focus();
  }
  function codeStep(){
   clearInterval(timer);shell();dialog.querySelector('.reset-info').textContent=text('If the account is eligible, a six-digit code has been sent from security@nik-smartcount.com to its registered email. Valid for 5 minutes. Check Spam too.','اگر حساب واجد شرایط باشد، کُد شش‌رقمی از security@nik-smartcount.com به ایمیل ثبت‌شده فرستاده شده است. کُد ۵ دقیقه اعتبار دارد؛ Spam را هم ببینید.');
   const fields=dialog.querySelector('.reset-fields');
   const code=field('reset-code',text('Six-digit recovery code','کُد شش‌رقمی بازیابی'),'text','one-time-code');code.input.inputMode='numeric';code.input.maxLength=6;code.input.pattern='[0-9]{6}';code.input.dir='ltr';code.input.oninput=()=>{code.input.value=digits(code.input.value)};
   const password=field('reset-new-password',text('New password (at least 12 characters)','رمز تازه (حداقل ۱۲ حرف)'),'password','new-password');password.input.minLength=12;password.input.maxLength=72;
   const confirm=field('reset-confirm-password',text('Repeat new password','تکرار رمز تازه'),'password','new-password');confirm.input.minLength=12;confirm.input.maxLength=72;
   fields.append(code.wrap,password.wrap,confirm.wrap);window.NSC_CODE_UI?.attach(code.input);
   const note=document.createElement('p');note.className='muted';note.textContent=text('Maximum password length: 72 bytes. Previous sessions will be signed out. Sign in again after resetting.','حداکثر طول رمز ۷۲ بایت است. نشست‌های قبلی باطل می‌شوند؛ بعد از بازیابی دوباره وارد شوید.');fields.append(note);
   const resend=document.createElement('button');resend.type='button';resend.id='reset-resend';fields.append(resend);
   const tick=()=>{const seconds=Math.max(0,Math.ceil((resendAt-Date.now())/1000));resend.disabled=busy||seconds>0;resend.textContent=seconds?text('Resend in '+seconds+' seconds','ارسال دوباره تا '+seconds+' ثانیه'):text('Send a new code','ارسال کُد تازه')};timer=setInterval(tick,1000);tick();
   resend.onclick=async()=>{if(busy||Date.now()<resendAt)return;loading(true);dialog.querySelector('.form-error').hidden=true;try{const result=await request('request_password_reset',{identifier});if(!dialog.isConnected)return;challenge=result.challengeId;resendAt=Date.now()+result.retryAfter*1000;codeStep()}catch(e){if(dialog.isConnected)error(e)}finally{if(dialog.isConnected)loading(false)}};
   dialog.querySelector('button[type=submit]').textContent=text('Save new password','ثبت رمز تازه');
   dialog.querySelector('form').onsubmit=async e=>{e.preventDefault();if(busy)return;dialog.querySelector('.form-error').hidden=true;
    if(password.input.value!==confirm.input.value){error(Error(text('The passwords do not match.','رمز تازه و تکرارش یکسان نیست.')));return}
    loading(true);try{await request('reset_password',{challengeId:challenge,code:digits(code.input.value),newPassword:password.input.value,confirmPassword:confirm.input.value});if(!dialog.isConnected)return;clearInterval(timer);shell();dialog.querySelector('.reset-info').setAttribute('role','status');dialog.querySelector('.reset-info').textContent=text('Your new password has been saved. Sign in with it. Email sign-in verification still applies.','رمز تازه ثبت شد. با رمز تازه وارد شوید؛ تأیید ایمیل ورود همچنان برقرار است.');dialog.querySelector('button[type=submit]').hidden=true;dialog.querySelector('.cancel').focus();const loginPassword=document.querySelector('#password');if(loginPassword)loginPassword.value='';}catch(e){if(dialog.isConnected)error(e)}finally{if(dialog.isConnected)loading(false)}
   };code.input.focus();
  }
  dialog.addEventListener('cancel',e=>{e.preventDefault();close()});document.body.append(dialog);start();dialog.showModal();dialog.querySelector('input')?.focus();
 }
 function attach(){const form=document.querySelector('#login');if(!form||document.querySelector('#forgot-password'))return;const button=document.createElement('button');button.type='button';button.id='forgot-password';button.className='subtle';button.style.cssText='margin-top:14px;padding:10px 0;background:none;border:0;cursor:pointer';button.textContent=text('Forgot password?','رمز عبور را فراموش کرده‌اید؟','پټنوم مو هېر شوی؟');button.onclick=open;form.after(button)}
 new MutationObserver(attach).observe(document.querySelector('#app')||document.body,{childList:true,subtree:true});attach();
})();
