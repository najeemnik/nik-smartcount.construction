<?php header('Cache-Control: no-store');header('X-Frame-Options: SAMEORIGIN');header('X-Content-Type-Options: nosniff'); ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title data-en="Backup and restore">پشتیبان‌گیری و بازیابی</title>
<link rel="icon" href="/assets/favicon-32.png">
<link rel="stylesheet" href="/experience.css?v=2">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f8f5;color:#214b3c;font:15px/1.9 Tahoma,system-ui,sans-serif}
main{max-width:760px;margin:40px auto 60px;padding:0 16px}
.card{padding:26px;background:#fff;border:1px solid #dfebe4;border-radius:22px;margin-bottom:18px}
.card h2{margin:0 0 6px;font-size:18px;color:#14493a}
.card p.lead{margin:0 0 18px;color:#5d7a6d;font-size:13px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.brand img{width:104px;height:auto}
.brand h1{font-size:21px;margin:0}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin:14px 0 4px}
@media(max-width:520px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
.stat{padding:12px 13px;background:#f5faf7;border:1px solid #e2efe8;border-radius:13px;min-height:68px}
.stat b{display:block;font-size:19px;color:#0f6b52;font-variant-numeric:tabular-nums}
.stat span{font-size:11px;color:#6f8a7e;display:block;margin-top:2px}
button{padding:12px 21px;border:0;border-radius:13px;background:#137c60;color:#fff;font:inherit;font-weight:600;cursor:pointer;min-height:44px}
button:hover:not(:disabled){background:#0f6b52}
button:disabled{opacity:.45;cursor:not-allowed}
button.ghost{background:#eef6f0;color:#137c60;border:1px solid #cfe3d8}
button.danger{background:#a8323d}
.drop{margin:14px 0;padding:26px;border:2px dashed #c2dbcd;border-radius:17px;text-align:center;background:#fafdfb;transition:.16s}
.drop.over{border-color:#137c60;background:#eef8f2}
.drop input{display:none}
.drop label{display:block;cursor:pointer;color:#2d6a55}
.drop b{display:block;margin-bottom:4px;color:#136b51}
.drop small{color:#79948a}
#status{margin:14px 0 0;padding:13px 15px;border-radius:13px;background:#edf6f0;font-size:13.5px;overflow-wrap:anywhere;min-height:44px}
#status.err{background:#fff0f1;color:#93262f}
#status.warn{background:#fff8e8;color:#825c14}
#status.ok{background:#e9f7ef;color:#0d6647}
#status.busy{background:#eef2fb;color:#2a4a86}
table{width:100%;border-collapse:collapse;font-size:13px;margin:10px 0}
th,td{text-align:right;padding:7px 9px;border-bottom:1px solid #eef4f0}
th{color:#5d7a6d;font-weight:600;font-size:12px}
.sub{color:#7b968b;font-size:12px;margin-top:4px}
.warnbox{padding:13px 15px;border-radius:13px;background:#fff8e8;color:#7a5612;font-size:13px;margin:14px 0;border:1px solid #f0e0b8}
.warnbox strong{color:#63431a}
input[type=text]{width:100%;max-width:260px;padding:11px 12px;border:1px solid #c7dbd0;border-radius:11px;font:inherit;direction:ltr;text-align:center;letter-spacing:2px;font-weight:700}
.hide{display:none!important}
a.back{display:inline-block;margin-top:16px;color:#137c60}
@media(max-width:620px){main{margin:16px auto}.card{padding:19px}.brand img{width:84px}}
</style>
</head>
<body>
<script src="/ui-language.js?v=1"></script>
<script src="/session-client.js?v=2"></script>
<main>
  <div class="brand">
    <img src="/assets/brand-full.webp" alt="NiK Smart Count">
    <h1>پشتیبان‌گیری و بازیابی</h1>
  </div>

  <!-- ۱) اکنون بگیر -->
  <section class="card">
    <h2>۱. ساختن فایل پشتیبان</h2>
    <p class="lead">یک فایل می‌گیرید که همهٔ پروژه‌ها، اسناد، حساب‌ها، کارمندان و فایل‌های پیوست همین شرکت در آن است. فقط داده داخل آن است، نه رمز عبور.</p>
    <div class="stats" id="stats"><div class="stat"><b>…</b><span>در حال خواندن</span></div></div>
    <p class="sub" id="counts"></p>
    <button id="make">اکنون از بکاپ بگیر (دانلود)</button>
    <p class="sub">هر بار که بزنید یک فایل تازه با تاریخ ساخته می‌شود. فایل‌های قبلی پاک نمی‌شوند.</p>
  </section>

  <!-- ۲) بازیابی -->
  <section class="card">
    <h2>۲. بازگرداندن از فایل</h2>
    <p class="lead">یک فایل <code>.nscbackup</code> را انتخاب کنید. اول محتوایش را نشان می‌دهیم؛ هیچ چیزی تا تأیید نهایی نوشته نمی‌شود.</p>

    <div class="drop" id="drop">
      <input id="file" type="file" accept=".nscbackup,.zip">
      <label for="file">
        <b>فایل را اینجا بکشید یا کلیک کنید</b>
        <small>پسوند: .nscbackup (یا .zip)</small>
      </label>
    </div>

    <div id="preview" class="hide">
      <table>
        <thead><tr><th>جدول</th><th style="width:90px">ردیف</th></tr></thead>
        <tbody id="preview-rows"></tbody>
      </table>
      <p class="sub" id="preview-meta"></p>

      <div class="warnbox">
        <strong>این کار داده‌ها را جایگزین نمی‌کند، اضافه می‌کند.</strong>
        ردیفی که در فایل هست با همان شناسه به‌روزرسانی می‌شود؛ ردیف‌های موجود که در فایل نیستند دست نمی‌خورند.
        پیش از هر نوشتنی، سرور خودش از وضع فعلی بکاپ می‌گیرد.
      </div>

      <label for="confirm" style="font-size:13px;color:#5d7a6d">برای تأیید، کلمهٔ <b>RESTORE</b> را بنویسید:</label><br>
      <input id="confirm" type="text" autocomplete="off" spellcheck="false" placeholder="RESTORE"><br>
      <button id="restore" class="danger" disabled style="margin-top:12px">بازگرداندن</button>
      <button id="other" class="ghost" type="button" style="margin-top:12px">انتخاب فایل دیگر</button>
    </div>

    <p id="status" role="status" aria-live="polite">در حال بررسی دسترسی…</p>
  </section>

  <a class="back" href="/workspace.php">← بازگشت به حسابداری</a>
</main>

<script>
(() => {
'use strict';
const $ = s => document.querySelector(s);
const statusEl = $('#status');
const setStatus = (msg, kind) => {
  statusEl.textContent = msg;
  statusEl.className = kind || '';
};
const fmt = n => new Intl.NumberFormat('fa-AF', { numberingSystem: 'latn' }).format(n || 0);
const mb = b => (b / 1048576).toFixed(1);

async function api(url, options) {
  const res = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options || {}));
  let body = null;
  try { body = await res.json(); } catch (_) {}
  if (!res.ok || !body || body.success === false) {
    const err = new Error((body && (body.error || body.message)) || ('HTTP ' + res.status));
    err.status = res.status;
    err.data = body && body.data;
    throw err;
  }
  return body.data || {};
}

// ── ۱) آمار
async function loadInfo() {
  try {
    const d = await api('/api/backup.php?action=info');
    const box = $('#stats');
    box.innerHTML = '';
    const items = [
      ['پروژه‌ها', d.counts.projects || 0],
      ['مصارف', d.counts.expenses || d.counts.revenue_entries || 0],
      ['کارمندان', d.counts.employees || 0],
      ['تأمین‌کنندگان', d.counts.suppliers || 0],
      ['فایل پیوست', d.uploads.files || 0],
      ['حجم پیوست', mb(d.uploads.bytes) + ' MB'],
    ];
    for (const [label, value] of items) {
      const el = document.createElement('div');
      el.className = 'stat';
      const b = document.createElement('b');
      b.textContent = typeof value === 'number' ? fmt(value) : value;
      const sp = document.createElement('span');
      sp.textContent = label;
      el.append(b, sp);
      box.append(el);
    }
    const tableCount = Object.keys(d.counts || {}).length;
    $('#counts').textContent =
      tableCount + ' جدول داده دارد · نسخهٔ ' + (d.appVersion || '?') +
      ' · سقف آپلود ' + d.maxUploadMb + ' مگابایت';
    if (!d.zipAvailable) {
      setStatus('افزونهٔ ZIP روی سرور فعال نیست؛ گرفتن بکاپ ممکن نیست. با میزبان تماس بگیرید.', 'err');
      $('#make').disabled = true;
    } else {
      setStatus('آماده است.', 'ok');
    }
  } catch (e) {
    setStatus(e.message, 'err');
    if (e.status === 401 || e.status === 403) setTimeout(() => location.replace('/login.php'), 1500);
  }
}

// ── ۲) ساخت و دانلود
$('#make').addEventListener('click', async () => {
  const btn = $('#make');
  btn.disabled = true;
  setStatus('در حال ساختن فایل پشتیبان… ممکن است چند ثانیه طول بکشد.', 'busy');
  try {
    const res = await fetch('/api/backup.php?action=download', { credentials: 'same-origin', cache: 'no-store' });
    if (!res.ok) {
      const body = await res.json().catch(() => null);
      throw new Error((body && body.error) || ('HTTP ' + res.status));
    }
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') || '';
    const match = /filename="([^"]+)"/.exec(cd);
    const name = match ? match[1] : ('nik-backup-' + Date.now() + '.nscbackup');
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 4000);
    setStatus('فایل ساخته و دانلود شد: ' + name + ' — ' + mb(blob.size) + ' مگابایت. آن را جای مطمئن نگه دارید.', 'ok');
  } catch (e) {
    setStatus('ساخت پشتیبان ناموفق بود: ' + e.message, 'err');
  } finally {
    btn.disabled = false;
  }
});

// ── ۳) انتخاب فایل → inspect
let chosen = null;
const drop = $('#drop');
const fileInput = $('#file');

function resetPreview() {
  chosen = null;
  $('#drop').classList.remove('hide');
  $('#preview').classList.add('hide');
  $('#confirm').value = '';
  $('#restore').disabled = true;
}
['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => {
  e.preventDefault(); drop.classList.add('over');
}));
['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => {
  e.preventDefault(); drop.classList.remove('over');
}));
drop.addEventListener('drop', e => {
  const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
  if (f) inspectFile(f);
});
fileInput.addEventListener('change', () => {
  const f = fileInput.files && fileInput.files[0];
  if (f) inspectFile(f);
});

async function inspectFile(file) {
  resetPreview();
  if (file.size > 200 * 1024 * 1024) {
    setStatus('فایل بیش از ۲۰۰ مگابایت است.', 'err');
    return;
  }
  setStatus('در حال خواندن فایل «' + file.name + '»…', 'busy');
  const fd = new FormData();
  fd.append('backup', file, file.name);
  try {
    const d = await api('/api/backup.php?action=inspect', { method: 'POST', body: fd });
    chosen = file;
    const labels = {
      users: 'کاربران', projects: 'پروژه‌ها', expenses: 'مصارف',
      revenue_entries: 'عواید', payment_vouchers: 'اسناد پرداخت',
      employees: 'کارمندان', suppliers: 'تأمین‌کنندگان',
      clients: 'مشتریان', journal_entries: 'روزنامچه', journal_lines: 'ردیف‌های روزنامچه',
      app_workspaces: 'وضعیت برنامه', project_files: 'فایل‌های پروژه',
      apartment_units: 'آپارتمان‌ها', material_procurements: 'خرید مصالح',
      labor_payroll: 'معاش', assets_machinery: 'دارایی‌ها',
    };
    const tbody = $('#preview-rows');
    tbody.innerHTML = '';
    const entries = Object.entries(d.tables || {}).filter(([, n]) => n > 0)
      .sort((a, b) => b[1] - a[1]);
    for (const [t, n] of entries) {
      const tr = document.createElement('tr');
      const td1 = document.createElement('td');
      td1.textContent = labels[t] || t;
      const td2 = document.createElement('td');
      td2.textContent = fmt(n);
      tr.append(td1, td2);
      tbody.append(tr);
    }
    $('#preview-meta').textContent =
      'ساخته‌شده: ' + (d.createdAt || '?') + ' · نسخهٔ ' + (d.appVersion || '?') +
      ' · ' + fmt(d.totalRows) + ' ردیف · ' + fmt(d.uploadCount) + ' فایل پیوست';
    $('#drop').classList.add('hide');
    $('#preview').classList.remove('hide');
    setStatus('فایل خوانده شد. اگر با آنچه انتظار دارید می‌خواند، کلمهٔ RESTORE را بنویسید.', 'ok');
  } catch (e) {
    setStatus(e.message, 'err');
  }
}

$('#confirm').addEventListener('input', e => {
  $('#restore').disabled = !(chosen && e.target.value.trim().toUpperCase() === 'RESTORE');
});

document.getElementById('other').addEventListener('click', () => {
  resetPreview();
  fileInput.value = '';
  setStatus('فایل دیگری انتخاب کنید.', 'ok');
});

// ── ۴) بازیابی
$('#restore').addEventListener('click', async () => {
  if (!chosen) return;
  if (!confirm('مطمئن هستید؟ سرور اول از وضع فعلی بکاپ می‌گیرد، بعد داده‌های این فایل را وارد می‌کند.')) return;
  const btn = $('#restore');
  btn.disabled = true;
  setStatus('در حال بازیابی… صفحه را نبندید.', 'busy');
  const fd = new FormData();
  fd.append('backup', chosen, chosen.name);
  fd.append('payload', JSON.stringify({ confirm: 'RESTORE' }));
  try {
    const res = await fetch('/api/backup.php?action=restore', {
      method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store',
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || !body || body.success === false) {
      throw new Error((body && body.error) || ('HTTP ' + res.status));
    }
    const d = body.data || {};
    setStatus('بازیابی شد: ' + fmt(d.totalRows) + ' ردیف و ' + fmt(d.uploads) + ' فایل. صفحه را دوباره باز کنید.', 'ok');
    $('#preview').classList.add('hide');
    $('#confirm').value = '';
    setTimeout(() => location.reload(), 2200);
  } catch (e) {
    setStatus('بازیابی ناموفق: ' + e.message, 'err');
    btn.disabled = false;
  }
});

loadInfo();
})();
</script>
</body>
</html>
