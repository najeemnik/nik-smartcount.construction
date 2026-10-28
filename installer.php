<?php
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
?>
<!doctype html><html lang="fa" dir="rtl"><head><link rel="icon" href="/assets/favicon-32.png"><link rel="apple-touch-icon" href="/assets/apple-touch-icon.png"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصب مستقل حسابداری نیک</title><style>
*{box-sizing:border-box}body{margin:0;background:#edf4f2;color:#16382e;font:16px/1.9 Tahoma,Arial,sans-serif}main{max-width:650px;margin:35px auto;padding:24px;background:white;border-radius:16px}label{display:block;margin-top:12px}input{display:block;width:100%;padding:12px;border:1px solid #b4cfc4;border-radius:7px;font:inherit;direction:ltr}button{padding:12px 18px;margin:16px 0 0 8px;border:0;border-radius:7px;background:#08775b;color:white;font:inherit;cursor:pointer}button:disabled{opacity:.5}#result{padding:15px;background:#e5f4ec;white-space:pre-wrap;overflow-wrap:anywhere;margin-top:18px}#result.bad{background:#fee9e9;color:#862323}h1{font-size:23px}h2{font-size:19px;border-top:1px solid #ddd;padding-top:20px}small{color:#537065}fieldset{border:0;padding:0}a{color:#076246}code{direction:ltr;display:inline-block}
</style></head><body><main><img src="/assets/brand-full.webp" alt="NiK Smart Count" style="width:160px;height:auto"><h1>نصب مستقل NiK Smart Count</h1><p>این صفحه بدون فایل‌های جانبی باز می‌شود. رمز را فقط داخل همین فورم وارد کنید.</p>
<noscript><p>JavaScript مرورگر غیرفعال است. برای اجرای آزمایش اتصال آن را فعال کنید.</p></noscript>
<form id="connection"><label>کلید نصب<input id="key" required autocomplete="off"></label><p style="margin:-6px 0 12px;font-size:12px;color:#537065">کلید نصب را پیش از باز کردن این صفحه بسازید: <code style="direction:ltr;display:inline-block">php tools/nsc-init.php</code> — راهنمای کامل در <code style="direction:ltr">docs/DEPLOY.md</code> بخش ۳.</p><label>سرور دیتابیس<input id="host" value="" placeholder="سرور MySQL از پنل هاست" required></label><label>پورت<input id="port" value="3306" required inputmode="numeric"></label><label>نام دیتابیس<input id="db-name" value="" required></label><label>یوزر دیتابیس<input id="db-user" value="" required></label><label>رمز یوزر دیتابیس<input id="db-pass" type="password" autocomplete="off"></label><button id="test" type="submit">آزمایش اتصال</button><button id="save" type="button">ذخیرهٔ اتصال</button></form>
<div id="result" role="status">صفحه باز شد. کلید نصب و رمز دیتابیس را وارد کنید، سپس آزمایش اتصال را بزنید.</div>
<h2>ساخت مدیر اصلی</h2><p><small>این بخش پس از ذخیرهٔ موفق اتصال فعال می‌شود. اگر مدیر قبلاً ساخته شده، از صفحهٔ ورود استفاده کنید و قفل نصب را حذف نکنید.</small></p>
<form id="master"><fieldset id="master-fields" disabled><label>نام مکمل<input id="name" required value="Ahmad Najeem Nik"></label><label>نام کاربری<input id="username" required value="nikadmin" pattern="[a-zA-Z0-9._-]{3,80}"></label><label>نام شرکت<input id="company" value="NiK Smart Count - Construction"></label><label>رمز جدید مدیر؛ حداقل ۱۲ حرف<input id="password" type="password" minlength="12" required autocomplete="new-password"></label><button type="submit">تکمیل نصب و ساخت مدیر</button></fieldset></form><p><a href="/office7-login.php">رفتن به صفحهٔ ورود</a></p></main>
<script>
(function(){
 var el=function(id){return document.getElementById(id);},busy=false;
 function show(s,bad){el('result').textContent=s;el('result').className=bad?'bad':'';}
 function request(path,data){
  var timer,controller=typeof AbortController!=='undefined'?new AbortController():null;
  if(controller)timer=setTimeout(function(){controller.abort();},25000);
  return fetch('/api/'+path,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(data),cache:'no-store',signal:controller?controller.signal:undefined})
  .then(function(r){return r.text().then(function(text){var parsed;try{parsed=JSON.parse(text);}catch(e){throw new Error('پاسخ API معتبر نیست. HTTP '+r.status+'؛ فایل‌های PHP نصب و تنظیمات باید بررسی شوند.');}if(!r.ok||!parsed.success)throw new Error(parsed.error||('HTTP '+r.status));return parsed.data||{};});})
  .then(function(v){clearTimeout(timer);return v;},function(e){clearTimeout(timer);throw e;});
 }
 function run(job){if(busy)return;busy=true;el('test').disabled=true;el('save').disabled=true;show('در حال ارتباط با سرور…');Promise.resolve().then(job).catch(function(e){show(e.name==='AbortError'?'سرور در ۲۵ ثانیه پاسخ نداد.':e.message,true);}).then(function(){busy=false;el('test').disabled=false;el('save').disabled=false;});}
 function config(action){return {action:action,key:el('key').value.trim(),host:el('host').value.trim(),port:el('port').value.trim(),name:el('db-name').value.trim(),user:el('db-user').value.trim(),password:el('db-pass').value};}
 el('connection').onsubmit=function(e){e.preventDefault();run(function(){return request('setup.php',config('test')).then(function(){show('اتصال موفق است. اکنون «ذخیرهٔ اتصال» را بزنید.');});});};
 el('save').onclick=function(){if(!el('connection').reportValidity())return;run(function(){return request('setup.php',config('configure')).then(function(){el('db-pass').value='';el('master-fields').disabled=false;show('اتصال ذخیره شد. اکنون بخش ساخت مدیر اصلی را خانه‌پری کنید.');});});};
 el('master').onsubmit=function(e){e.preventDefault();run(function(){if(!window.crypto||!window.crypto.subtle)throw new Error('برای ساخت مدیر، سایت را با HTTPS و مرورگر به‌روز باز کنید.');var username=el('username').value.trim().toLowerCase(),password=el('password').value;
 return crypto.subtle.digest('SHA-256',new TextEncoder().encode(username+'::nsc::'+password)).then(function(buffer){var hash='sha256$'+Array.from(new Uint8Array(buffer)).map(function(b){return b.toString(16).padStart(2,'0');}).join('');return request('install.php?action=complete',{key:el('key').value.trim(),master:{username:username,name:el('name').value.trim(),companyName:el('company').value.trim(),passwordHash:hash,hashSalt:username}});}).then(function(){el('password').value='';el('master-fields').disabled=true;show('نصب موفق شد. از لینک پایین صفحه وارد حساب خود شوید.');});});};
 window.addEventListener('error',function(){show('خطای مرورگر رخ داد. از همین صفحه عکس بفرستید؛ رمزها دیده نشوند.',true);});
})();
</script></body></html>
