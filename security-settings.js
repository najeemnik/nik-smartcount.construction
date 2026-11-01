(() => {
 document.getElementById('status').removeAttribute('data-en');
 const el=id=>document.getElementById(id);let proof='',busy=false;
 const show=(message,error=false)=>{el('status').textContent=message;el('status').classList.toggle('error',error)};
 async function api(data){const token=localStorage.getItem('nsc_master_token');if(!token)throw Error(NSC_UI.t("Sign in to Office Seven first.","ابتدا در دفتر هفت وارد شوید."));const response=await fetch('/api/email-settings.php',{method:data?'POST':'GET',headers:{'Content-Type':'application/json',Authorization:'Bearer '+token},body:data?JSON.stringify(data):undefined,cache:'no-store',signal:AbortSignal.timeout(20000)});const body=await response.json();if(!response.ok||!body.success)throw Error(body.error||NSC_UI.t("Settings could not be saved.","تنظیم ذخیره نشد."));return body.data}
 async function run(action){if(busy)return;busy=true;el('fields').disabled=true;try{await action()}catch(error){show(error.message,true)}finally{busy=false;el('fields').disabled=false;el('save').disabled=!proof}}
 const data=mailAction=>({mailAction,from:el('from').value.trim(),code:el('code').value.trim(),proof});
 el('from').oninput=()=>{proof='';el('save').disabled=true};
 el('send').onclick=()=>run(async()=>{if(!el('from').reportValidity())return;proof='';await api(data('send'));show(NSC_UI.t("Test code sent. Check your inbox and spam folder.","کُد آزمایشی فرستاده شد. صندوق ایمیل و Spam را ببینید."))});
 el('verify').onclick=()=>run(async()=>{const result=await api(data('verify'));proof=result.proof;show(NSC_UI.t("Email delivery verified. You can now save.","دریافت ایمیل تأیید شد؛ حالا ذخیره کنید."))});
 el('mail-form').onsubmit=event=>{event.preventDefault();run(async()=>{await api(data('save'));proof='';show(NSC_UI.t("Email sender updated.","فرستندهٔ ایمیل با موفقیت تغییر کرد."))})};
 window.NSC_SESSION_READY.then(()=>api()).then(settings=>{el('from').value=settings.from;el('recipient').textContent=NSC_UI.t("A test code will be sent to the administrator: ","کُد آزمایشی به ایمیل مدیر فرستاده می‌شود: ")+settings.email;el('fields').disabled=false;show(NSC_UI.t("Enter a new sender email or test the current sender.","ایمیل جدید را وارد کنید یا فرستندهٔ فعلی را آزمایش کنید."))}).catch(error=>show(error.message,true));
})();
