(() => {
 const el=id=>document.getElementById(id);let proof='',busy=false;
 const show=(message,error=false)=>{el('status').textContent=message;el('status').classList.toggle('error',error)};
 async function api(data){const token=localStorage.getItem('nsc_master_token');if(!token)throw Error('ابتدا در دفتر هفت وارد شوید.');const response=await fetch('/api/email-settings.php',{method:data?'POST':'GET',headers:{'Content-Type':'application/json',Authorization:'Bearer '+token},body:data?JSON.stringify(data):undefined,cache:'no-store',signal:AbortSignal.timeout(20000)});const body=await response.json();if(!response.ok||!body.success)throw Error(body.error||'تنظیم ذخیره نشد.');return body.data}
 async function run(action){if(busy)return;busy=true;el('fields').disabled=true;try{await action()}catch(error){show(error.message,true)}finally{busy=false;el('fields').disabled=false;el('save').disabled=!proof}}
 const data=mailAction=>({mailAction,from:el('from').value.trim(),code:el('code').value.trim(),proof});
 el('from').oninput=()=>{proof='';el('save').disabled=true};
 el('send').onclick=()=>run(async()=>{if(!el('from').reportValidity())return;proof='';await api(data('send'));show('کُد آزمایشی فرستاده شد. صندوق ایمیل و Spam را ببینید.')});
 el('verify').onclick=()=>run(async()=>{const result=await api(data('verify'));proof=result.proof;show('دریافت ایمیل تأیید شد؛ حالا ذخیره کنید.')});
 el('mail-form').onsubmit=event=>{event.preventDefault();run(async()=>{await api(data('save'));proof='';show('فرستندهٔ ایمیل با موفقیت تغییر کرد.')})};
 api().then(settings=>{el('from').value=settings.from;el('recipient').textContent='کُد آزمایشی به ایمیل مدیر فرستاده می‌شود: '+settings.email;el('fields').disabled=false;show('ایمیل جدید را وارد کنید یا فرستندهٔ فعلی را آزمایش کنید.')}).catch(error=>show(error.message,true));
})();
