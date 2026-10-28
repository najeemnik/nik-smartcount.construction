import {build} from 'esbuild';
import {readFile,writeFile} from 'node:fs/promises';
import {randomBytes} from 'node:crypto';
import {gzipSync} from 'node:zlib';
const w='web';
await writeFile(w+'/api/.secrets/install.key','NSC-'+randomBytes(24).toString('hex')+'\n',{mode:0o600});
const cleanup=`<script>if('serviceWorker'in navigator){navigator.serviceWorker.getRegistrations().then(function(rs){return Promise.all(rs.filter(function(r){return !(r.active&&r.active.scriptURL.endsWith('/pwa-sw.js'))}).map(function(r){return r.unregister()}))}).then(function(){return navigator.serviceWorker.register('/pwa-sw.js')}).catch(function(){})}</script>`;
const originalCss=await readFile('portal/style.css','utf8');
const css=originalCss.replace(/@font-face\{[^}]*\}/g,'').replace(/Vazir,/g,'Tahoma,');
const fail=`<script>setTimeout(function(){var e=document.getElementById('boot-message');if(e)e.innerHTML='دریافت پاسخ سرور طول کشید. <button onclick="location.reload()">تلاش دوباره</button> <a href="/login.php">ورود به حساب</a>'},20000);</script>`;
const header=`<?php if(function_exists('ob_gzhandler')&&!ini_get('zlib.output_compression'))ob_start('ob_gzhandler');header('Cache-Control: no-store, max-age=0');header('Content-Type: text/html; charset=UTF-8');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN'); ?>`;
const staticLogin=master=>`<main class="auth-page"><section class="auth-art"><div class="brand-title">NiK Smart Count <span>Construction</span></div><footer class="creator">Ahmad Najeem Nik</footer></section><section class="auth-content"><div class="auth-card"><h1>${master?'ورود دفتر هفت':'ورود به حساب'}</h1><form><div class="field"><label for="username">نام کاربری</label><input id="username" autocomplete="username" required></div><div class="field"><label for="password">رمز عبور</label><input id="password" type="password" autocomplete="current-password" required></div><p>در حال آماده‌سازی ورود…</p></form></div></section></main>`;
for(const[name,file,title]of [['login','login.php','ورود کاربران'],['login','office7-login.php','ورود مدیر'],['office7','office7.php','دفتر هفت'],['team','team.php','کارمندان شرکت'],['app','workspace.php','حسابداری ساختمانی']]){
 const result=await build({entryPoints:[`portal/${name}.js`],bundle:true,format:'esm',minify:true,target:'es2022',external:['/nsc-runtime.php?asset=accounting'],write:false});
 const js=result.outputFiles[0].text.replaceAll('</script','<\\/script');
 let pageCss=css;
 if(name==='app')pageCss=(await readFile('accounting.css','utf8')).replace(/@font-face\{[^}]*\}/g,'')+(await readFile('portal/bridge.css','utf8')).replace(/@font-face\{[^}]*\}/g,'');
 const content=name==='login'?staticLogin(file==='office7-login.php'):'<main id="boot-message" dir="rtl" style="padding:40px;font-family:system-ui">در حال دریافت معلومات حساب… <button onclick="location.reload()">تلاش دوباره</button></main>';
 const html=`${header}<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title} · NiK Smart Count</title><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png"><link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16.png"><link rel="apple-touch-icon" href="/assets/apple-touch-icon.png"><link rel="manifest" href="/manifest.webmanifest"><meta name="theme-color" content="#102a33"><meta name="apple-mobile-web-app-capable" content="yes"><style>${pageCss}[hidden]{display:none!important}</style></head><body><div id="${name==='app'?'root':'app'}">${content}</div><div id="message" hidden></div>${cleanup}<script type="module">${js}</script>${fail}</body></html>`;
 await writeFile(`${w}/${file}`,html);
}
let accounting=await readFile('accounting-client.js','utf8');
accounting=accounting.replaceAll('/team.html','/team.php').replaceAll('/assets/creator-logo.png','/assets/brand-full.webp').replaceAll('/assets/logo.png','/assets/brand-full.webp').replaceAll('/assets/icon-192.png','/assets/brand-full.webp');
const gz=gzipSync(accounting,{level:9});
await writeFile(`${w}/nsc-accounting-r3.js.gz`,gz);
await writeFile(`${w}/nsc-runtime.php`,`<?php
ini_set('zlib.output_compression','0');
header('X-Content-Type-Options: nosniff');
if(($_GET['asset']??'')!=='accounting'){http_response_code(404);exit;}
header('Content-Type: text/javascript; charset=UTF-8');header('Cache-Control: public, max-age=3600');header('Vary: Accept-Encoding');
$file=__DIR__.'/nsc-accounting-r3.js.gz';
if(!is_file($file)){http_response_code(503);echo 'throw new Error("Accounting asset missing; extract every file from the update.");';exit;}
if(strpos($_SERVER['HTTP_ACCEPT_ENCODING']??'','gzip')!==false){header('Content-Encoding: gzip');header('Content-Length: '.filesize($file));readfile($file);}else{echo gzdecode(file_get_contents($file));}
`);
