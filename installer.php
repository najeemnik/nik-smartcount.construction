<?php
require_once __DIR__.'/api/offline.php';
/**
 * Local/LAN convenience: a laptop user has no SSH to run create-install-key.php.
 * In offline mode only, and only while the installation is not sealed, generate
 * the private installer key automatically and show it once on this page.
 * On a public host nscOfflineMode() is false, so this block never runs.
 */
function nscBootstrapInstallKey(): ?string {
    $dir = __DIR__ . '/api/.secrets';
    $keyFile = $dir . '/install.key';
    $lockFile = $dir . '/installed.lock';
    if (!nscOfflineMode()) return null;          // public host: unchanged behaviour
    if (is_file($lockFile)) return null;         // already installed
    if (is_file($keyFile)) return trim((string)@file_get_contents($keyFile)) ?: null;
    if (!is_dir($dir) || !is_writable($dir)) return null;
    $previous = umask(0077);
    $handle = @fopen($keyFile, 'x');
    umask($previous);
    if (!$handle) return null;                   // someone else created it first
    $key = 'NSC-' . bin2hex(random_bytes(24));
    if (fwrite($handle, $key . "\n") !== strlen($key) + 1) { fclose($handle); @unlink($keyFile); return null; }
    fclose($handle);
    @chmod($keyFile, 0600);
    return $key;
}
/**
 * First-run on the laptop: if the private profile is still the shipped example,
 * activate it from the browser. Without this file nothing works (cookies would
 * keep the Secure flag over plain http:// and install would never complete).
 * Public hosts are untouched: the function only runs on loopback/private LAN.
 */
function nscBootstrapOfflineProfile(): bool {
    $dir = __DIR__ . '/api/.secrets';
    $target = $dir . '/offline.php';
    $example = $dir . '/offline.example.php';
    if (!nscIsLanAddress($_SERVER['REMOTE_ADDR'] ?? null) && PHP_SAPI !== 'cli') return false;
    if (is_file($target)) return true;
    if (!is_file($example) || !is_dir($dir) || !is_writable($dir)) return false;
    return @copy($example, $target);
}
$nscOfflineProfileReady = nscBootstrapOfflineProfile();
$nscLocalInstallKey = nscBootstrapInstallKey();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
?>
<!doctype html><html lang="en" dir="ltr"><head><link rel="icon" href="/assets/favicon-32.png"><link rel="apple-touch-icon" href="/assets/apple-touch-icon.png"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title data-en="Install NiK Smart Count">نصب مستقل حسابداری نیک</title><link rel="stylesheet" href="/experience.css?v=2"><script>window.NSC_OFFLINE_MODE=<?php echo nscOfflineMode()?'true':'false'; ?>;</script><style>
.nsc-offline-note{margin:14px 0;padding:13px 15px;border:1px solid #cfe3d8;border-radius:12px;background:#eef8f2;color:#14584a;font:13px/1.9 Tahoma,system-ui,sans-serif}

*{box-sizing:border-box}body{margin:0;background:#edf4f2;color:#16382e;font:16px/1.9 Tahoma,Arial,sans-serif}main{max-width:780px;margin:35px auto;padding:24px;background:white;border-radius:16px}label{display:block;margin-top:12px}input{display:block;width:100%;padding:12px;border:1px solid #b4cfc4;border-radius:12px;font:inherit;direction:ltr}button{padding:12px 18px;margin:16px 0 0 8px;border:0;border-radius:12px;background:#08775b;color:white;font:inherit;cursor:pointer}button:disabled{opacity:.5}#result{padding:15px;background:#e5f4ec;white-space:pre-wrap;overflow-wrap:anywhere;margin-top:18px}#result.bad{background:#fee9e9;color:#862323}h1{font-size:23px}h2{font-size:19px;border-top:1px solid #ddd;padding-top:20px}small{color:#537065}fieldset{border:0;padding:0}a{color:#076246}code{direction:ltr;display:inline-block}
</style></head><body><script src="/ui-language.js?v=1"></script><main><div style="text-align:end"><button type="button" onclick="NSC_UI.set('en')">English</button><button type="button" onclick="NSC_UI.set('fa')">دری</button></div><img src="/assets/brand-full.webp" alt="NiK Smart Count" style="width:160px;height:auto"><h1 data-en="1. Set up NiK Smart Count">۱. نصب آسان NiK Smart Count</h1><p data-en="Three steps: connect the database, test email delivery, and create an administrator. No code edits needed.">سه قدم ساده: اتصال دیتابیس، آزمایش ایمیل، ساخت مدیر. هیچ تنظیمی در کُد لازم نیست.</p>
<?php if(nscOfflineMode()): ?><p><small><b>حالت محلی/شبکه:</b> دو قدم — اتصال دیتابیس، ساخت مدیر اصلی. گام ایمیل خودکار رد می‌شود.</small></p><?php endif; ?>
<noscript><p data-en="Enable JavaScript to test the connection and complete setup.">JavaScript مرورگر غیرفعال است. برای اجرای آزمایش اتصال آن را فعال کنید.</p></noscript>
<form id="connection"><label><span data-en="Installation key">کلید نصب</span><input id="key" type="password" required autocomplete="off" value="<?php echo htmlspecialchars((string)$nscLocalInstallKey, ENT_QUOTES, 'UTF-8'); ?>"></label><label><span data-en="Database host">سرور دیتابیس</span><input id="host" value="<?php echo nscOfflineMode()?'localhost':''; ?>" placeholder="سرور MySQL از پنل هاست" required></label><label><span data-en="Port">پورت</span><input id="port" value="3306" required inputmode="numeric"></label><label><span data-en="Database name">نام دیتابیس</span><input id="db-name" value="<?php echo nscOfflineMode()?'nik_smart':''; ?>" required></label><label><span data-en="Database user">یوزر دیتابیس</span><input id="db-user" value="<?php echo nscOfflineMode()?'root':''; ?>" required></label><label><span data-en="Database password">رمز یوزر دیتابیس</span><input id="db-pass" type="password" autocomplete="off"></label><button id="test" type="submit" data-en="Test connection">آزمایش اتصال</button><button id="save" type="button" data-en="Save connection">ذخیرهٔ اتصال</button></form>
<div id="result" role="status" data-en="Enter your installation key and database details, then test the connection.">صفحه باز شد. کلید نصب و رمز دیتابیس را وارد کنید، سپس آزمایش اتصال را بزنید.</div>
<?php if(nscOfflineMode()): ?>
<div class="nsc-offline-note" role="note">گام ایمیل امنیتی روی این کمپیوتر لازم نیست: این دستگاه سرور محلی است و ایمیل نمی‌فرستد. این گام خودکار رد می‌شود؛ مستقیم به ساخت مدیر اصلی بروید.</div>
<?php else: ?>
<h2 data-en="2. Security email">۲. ایمیل امنیتی</h2><fieldset id="mail-fields" disabled><label><span data-en="Sender email in cPanel">ایمیل فرستنده در cPanel</span><input type="email" id="mail-from" value="security@nik-smartcount.com" required></label><?php if(!nscOfflineMode()): ?><label><span data-en="Administrator email for verification codes">ایمیل دریافت کُد مدیر اصلی</span><input type="email" id="master-email" required placeholder="ایمیلی که به صندوق آن دسترسی داری"></label><?php endif; ?><button type="button" id="mail-send" data-en="Send test code">ارسال کُد آزمایشی</button><label><span data-en="Six-digit email code">کُد شش‌رقمی ایمیل</span><input id="mail-code" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></label><button type="button" id="mail-verify" data-en="Verify email delivery">تأیید دریافت ایمیل</button></fieldset><?php endif; ?>
<h2 data-en="3. Create administrator">۳. ساخت مدیر اصلی</h2><p><small data-en="Complete the connection and email checks first. If an administrator already exists, use the sign-in page and keep the installation lock.">این بخش پس از ذخیرهٔ موفق اتصال فعال می‌شود. اگر مدیر قبلاً ساخته شده، از صفحهٔ ورود استفاده کنید و قفل نصب را حذف نکنید.</small></p>
<form id="master"><fieldset id="master-fields" disabled><?php if(nscOfflineMode()): ?><label><span data-en="Administrator email">ایمیل مدیر اصلی</span><input type="email" id="master-email" required value="admin@nik.local" placeholder="admin@nik.local"><small style="color:#537065">این ایمیل فقط برای شناسهٔ حساب و بازیابی است؛ روی این دستگاه کُد ارسال نمی‌شود.</small></label><?php endif; ?><label><span data-en="Full name">نام مکمل</span><input id="name" required value="Ahmad Najeem Nik"></label><label><span data-en="Username">نام کاربری</span><input id="username" required value="nikadmin" pattern="[a-zA-Z0-9._-]{3,80}"></label><label><span data-en="Company name">نام شرکت</span><input id="company" value="NiK Smart Count - Construction"></label><label><span data-en="New administrator password; at least 12 characters">رمز جدید مدیر؛ حداقل ۱۲ حرف</span><input id="password" type="password" minlength="12" required autocomplete="new-password"></label><button type="submit" data-en="Complete setup and create administrator">تکمیل نصب و ساخت مدیر</button></fieldset></form><p><a href="/office7-login.php" data-en="Go to sign in">رفتن به صفحهٔ ورود</a></p></main>
<script>
(function(){
 var el=function(id){return document.getElementById(id);},busy=false,emailProof='';
 function show(s,bad){el('result').removeAttribute('data-en');el('result').textContent=s;el('result').className=bad?'bad':'';}
 function request(path,data){
  var timer,controller=typeof AbortController!=='undefined'?new AbortController():null;
  if(controller)timer=setTimeout(function(){controller.abort();},25000);
  return fetch('/api/'+path,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(data),cache:'no-store',signal:controller?controller.signal:undefined})
  .then(function(r){return r.text().then(function(text){var parsed;try{parsed=JSON.parse(text);}catch(e){throw new Error('پاسخ API معتبر نیست. HTTP '+r.status+'؛ فایل‌های PHP نصب و تنظیمات باید بررسی شوند.');}if(!r.ok||!parsed.success)throw new Error(parsed.error||('HTTP '+r.status));return parsed.data||{};});})
  .then(function(v){clearTimeout(timer);return v;},function(e){clearTimeout(timer);throw e;});
 }
 function run(job){if(busy)return;busy=true;document.querySelectorAll('button').forEach(function(b){b.disabled=true;});show(NSC_UI.t("Connecting to server\u2026","در حال ارتباط با سرور…"));Promise.resolve().then(job).catch(function(e){show(e.name==='AbortError'?NSC_UI.t("The server did not respond within 25 seconds.","سرور در ۲۵ ثانیه پاسخ نداد."):e.message,true);}).then(function(){busy=false;document.querySelectorAll('button').forEach(function(b){b.disabled=false;});});}
 function config(action){return {action:action,key:el('key').value.trim(),host:el('host').value.trim(),port:el('port').value.trim(),name:el('db-name').value.trim(),user:el('db-user').value.trim(),password:el('db-pass').value};}
 el('connection').onsubmit=function(e){e.preventDefault();run(function(){return request('setup.php',config('test')).then(function(){show(NSC_UI.t("Connection successful. Click Save connection.","اتصال موفق است. اکنون «ذخیرهٔ اتصال» را بزنید."));});});};
 el('save').onclick=function(){if(!el('connection').reportValidity())return;run(function(){return request('setup.php',config('configure')).then(function(){el('db-pass').value='';el('mail-fields').disabled=false;show(NSC_UI.t("Connection saved. Enter the sender and administrator email addresses, then request a test code.","اتصال ذخیره شد. اکنون ایمیل فرستنده و ایمیل مدیر را وارد کنید و کُد آزمایشی بگیرید."));});});};
 function mailData(){var g=function(id){return el(id)?el(id).value.trim():'';};return {key:g('key'),from:g('mail-from'),email:g('master-email'),code:g('mail-code')};}
 if(el('mail-send'))el('mail-send').onclick=function(){run(function(){return request('install.php?action=email_test',mailData()).then(function(){show(NSC_UI.t("Test code sent. Check the inbox and spam folder.","کُد آزمایشی فرستاده شد. ایمیل و پوشه Spam را ببینید."));});});};
 if(el('mail-verify'))el('mail-verify').onclick=function(){run(function(){return request('install.php?action=email_verify',mailData()).then(function(data){emailProof=data.proof;el('master-fields').disabled=false;show(NSC_UI.t("Email delivery verified. Create the administrator now.","دریافت ایمیل تأیید شد. اکنون مدیر اصلی را بسازید."));});});};
 for(var id of ['mail-from','master-email'])if(el(id))el(id).addEventListener('input',function(){if(window.NSC_OFFLINE_MODE)return;emailProof='';el('master-fields').disabled=true;});
 if(window.NSC_OFFLINE_MODE){var mf=document.getElementById('master-fields');if(mf)mf.disabled=false;}
 el('master').onsubmit=function(e){e.preventDefault();run(function(){
 if(!window.isSecureContext&&!window.NSC_OFFLINE_MODE)throw new Error(NSC_UI.t("Open the site with HTTPS to create an administrator.","برای ساخت مدیر، سایت را با HTTPS باز کنید."));
 var username=el('username').value.trim().toLowerCase(),password=el('password').value;
 return request('install.php?action=complete',{key:el('key').value.trim(),from:el('mail-from').value.trim(),emailProof:emailProof,master:{username:username,name:el('name').value.trim(),email:el('master-email').value.trim(),companyName:el('company').value.trim(),password:password}}).then(function(){el('password').value='';el('master-fields').disabled=true;show(NSC_UI.t("Setup complete. Use the link below to sign in.","نصب موفق شد. از لینک پایین صفحه وارد حساب خود شوید."));});
 });};
 window.addEventListener('error',function(){show(NSC_UI.t("A browser error occurred. Share a screenshot of this page with passwords hidden.","خطای مرورگر رخ داد. از همین صفحه عکس بفرستید؛ رمزها دیده نشوند."),true);});
})();
</script></body></html>
