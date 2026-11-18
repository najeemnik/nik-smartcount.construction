/*
 * NiK Smart Count — دکمهٔ «پشتیبان‌گیری» داخل پوستهٔ برنامه
 *
 * چرا یک فایل جداگانه و نه ویرایش باندل؟
 *   nsc-accounting-r3.js.gz یک باندل کامپایل‌شده (minified) ۲.۵ مگابایتی است.
 *   دست‌زدن به آن برای یک دکمهٔ ساده، ریسک شکستن منطق مالی دارد.
 *   این فایل مستقل است، فقط یک <a> واقعی می‌سازد، و اگر خطا بدهد
 *   هیچ‌چیز دیگری را تحت تأثیر قرار نمی‌دهد.
 *
 * امنیت: این فایل فقط «نمایش» می‌دهد. دسترسی واقعی در api/backup.php
 * با authenticateUser() و بررسی مالکیت شرکت کنترل می‌شود.
 */
(() => {
  'use strict';

  // فقط داخل برنامهٔ حسابداری — نه صفحهٔ ورود یا نصب
  const path = location.pathname;
  if (!/^\/(workspace|office7|team)\.php$/.test(path)) return;

  // اگر قبلاً ساخته شده
  if (document.getElementById('nsc-backup-link')) return;

  const style = document.createElement('style');
  style.textContent = `
    #nsc-backup-link {
      position: fixed;
      left: 14px;
      bottom: 14px;
      z-index: 2147483000;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      min-height: 44px;
      padding: 0 16px;
      border-radius: 13px;
      background: #137c60;
      color: #fff;
      font: 600 13px/1 Tahoma, system-ui, sans-serif;
      text-decoration: none;
      box-shadow: 0 4px 14px #0c3d2f4d;
      transition: background .16s, transform .16s;
    }
    #nsc-backup-link:hover { background: #0f6b52; transform: translateY(-1px); }
    #nsc-backup-link:focus-visible { outline: 3px solid #137c6066; outline-offset: 3px; }
    #nsc-backup-link svg { width: 17px; height: 17px; flex: none; }
    html.dark #nsc-backup-link { background: #1a9c78; box-shadow: 0 4px 14px #0009; }
    html.dark #nsc-backup-link:hover { background: #14886a; }
    @media (max-width: 640px) {
      #nsc-backup-link {
        font-size: 12px;
        min-height: 40px;
        padding: 0 13px;
        bottom: 72px;
        left: 10px;
      }
    }
    @media print { #nsc-backup-link { display: none !important; } }
  `;
  document.head.append(style);

  const a = document.createElement('a');
  a.id = 'nsc-backup-link';
  a.href = '/backup.php';
  a.setAttribute('aria-label', 'پشتیبان‌گیری و بازیابی داده‌ها');
  a.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
      stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
      <polyline points="7 10 12 15 17 10"/>
      <line x1="12" y1="15" x2="12" y2="3"/>
    </svg><span>پشتیبان‌گیری</span>`;

  // کمی صبر تا پوسته رندر شود
  const attach = () => {
    if (document.body) document.body.append(a);
    else document.addEventListener('DOMContentLoaded', () => document.body.append(a), { once: true });
  };
  attach();
})();
