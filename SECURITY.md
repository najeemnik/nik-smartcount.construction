# مدل امنیتی NiK Smart Count

این سند مرجع رسمی امنیت پروژه است: چه چیزی محافظت شده، چه چیزی آگاهانه پذیرفته شده، و
چه گام‌هایی برای بستن شکاف‌های باقی‌مانده لازم است.

آخرین به‌روزرسانی: ۲۰۲۶-۱۰-۲۸ · نسخهٔ ساختار: `2026-10-28.1`

---

## ۱. لایه‌های اعتماد

| لایه | مکانیزم | محل |
|---|---|---|
| هویت | توکن HMAC-SHA256 (`HS256`) با `jti`, `exp`, `ver` | `api/db.php` → `generateToken` / `verifyToken` |
| ابطال نشست | `users.token_version`؛ افزایش آن همهٔ توکن‌های قبلی را باطل می‌کند | `auth.php?action=logout_all` |
| نقش و صلاحیت | `authenticateUser($requireMaster)`, `requireRole`, `assertModulePermission` | `api/db.php` |
| شرکت (tenant) | `currentTenantId()`؛ هر کوئری با `tenant_id` | همهٔ اندپوینت‌ها |
| پروژه | `assertTenantProjectAccess()` | `db.php` |
| CSRF | تطبیق `Origin` با `Host` + الزام JSON یا هدر سفارشی | `config.php` → `enforceCsrfAndOriginGuard` |
| تکرار درخواست | `X-Idempotency-Key` + پنجرهٔ اثر انگشت | `db.php` → `enforceIdempotencyGuard` |
| نرخ درخواست | ۲۰ تلاش/IP و ۱۰ تلاش/نام‌کاربری در ۱۵ دقیقه | `auth.php` |
| رمز | Bcrypt (cost 12) روی دایجست کلاینت (`nsc-v2$`) | `db.php` → `protectClientPasswordHash` |
| آپلود | لیست سفید پسوند + لیست سیاه دوبار-پسوند + `php_flag engine off` | `api/files.php`, `uploads/.htaccess` |
| نصب | `install.key` + `installed.lock` + `flock` + ۵ تلاش/۱۵ دقیقه | `install.php`, `setup.php` |

---

## ۲. هدرهای امنیتی (اعمال‌شده در `.htaccess` و `api/config.php`)

```
Strict-Transport-Security: max-age=31536000; includeSubDomains      (env=HTTPS)
Content-Security-Policy: default-src 'self'; base-uri 'none'; object-src 'none';
    frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline';
    style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self';
    manifest-src 'self'; worker-src 'self' blob:; upgrade-insecure-requests
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
X-Robots-Tag: noindex, nofollow, noarchive
```

همچنین HTTP به HTTPS ریدایرکت می‌شود (با احترام به `X-Forwarded-Proto` برای هاست‌های پشت پروکسی).

---

## ۳. آنچه آگاهانه پذیرفته شده (و چرا)

### ۳.۱ توکن در `localStorage`
کلاینت کامپایل‌شده (`nsc-accounting-r3.js.gz`) توکن را از `localStorage` می‌خواند و این
مسیر در باندل هارد‌کد است؛ کد منبع آن در این مخزن نیست. تغییر به کوکی `HttpOnly` نیازمند
بازسازی باندل است، وگرنه برنامه در بارگذاری اول از کار می‌افتد.

**کاهش ریسک اعمال‌شده:**
- CSP با `connect-src 'self'` و `img-src 'self'` → حتی با XSS، توکن دزدیده‌شده نمی‌تواند
  به دامنهٔ مهاجم ارسال شود (هم `fetch` و هم `img` و هم `form-action` بسته است).
- `X-Content-Type-Options: nosniff` + `X-Frame-Options` + `frame-ancestors`
- انقضای توکن و ابطال گروهی با `token_version`

**گام بعدی (نیازمند بازسازی کلاینت):** صدور کوکی `HttpOnly; Secure; SameSite=Strict`
در `auth.php` و حذف توکن از `localStorage` + CSP با nonce و حذف `'unsafe-inline'`.

### ۳.۲ `'unsafe-inline'` در CSP
هر دو صفحه و باندل از اسکریپت/استایل درون‌خطی و هندلرهای `on*` استفاده می‌کنند. حذف
`'unsafe-inline'` بدون بازسازی کلاینت، تمام رابط را از کار می‌اندازد. به‌جای آن، بردارهای
خروج داده بسته شده است.

### ۳.۳ دایجست رمز در مرورگر
رمز در مرورگر به `sha256(salt::nsc::password)` تبدیل و همان دایجست ارسال می‌شود. یعنی
«رمز مؤثر» همان دایجست است؛ اگر کسی آن را روی سیم ببیند می‌تواند بازپخش کند. سرور آن را با
Bcrypt ذخیره می‌کند تا نشت دیتابیس مستقیماً قابل استفاده نباشد. رفع کامل نیازمند
challenge-response در کلاینت است.

### ۳.۴ `rebar_spec_memory` فاقد `tenant_id`
این جدول وزن استاندارد میلگرد (kg/m بر اساس قطر و طول) را نگه می‌دارد — دادهٔ فیزیکی مشترک.
اما `spec_key` سراسری یکتاست، بنابراین یک شرکت می‌تواند مقدار «برند» مشترک را بازنویسی کند.
**اثر:** یکپارچگی داده بین شرکت‌ها، نه نشت اطلاعات.
**برنامهٔ اصلاح:** افزودن `tenant_id` + یکتایی ترکیبی `(tenant_id, spec_key)` + مهاجرت
داده‌های موجود به‌صورت tenant-scoped؛ نیازمند مهاجرت کنترل‌شده است و در یک تغییر کورکورانه
انجام نمی‌شود.

---

## ۴. تاریخچهٔ اصلاحات امنیتی

| تاریخ | مورد | اقدام |
|---|---|---|
| 2026-10-28 | نبود CSP و HSTS | افزودن CSP کامل، HSTS، ریدایرکت HTTPS، COOP/CORP/Permissions-Policy |
| 2026-10-28 | `installer.php` پس از نصب قابل مشاهده بود | پاسخ ۴۰۴ بعد از وجود `installed.lock` |
| 2026-10-28 | `auth.php?action=ping` نسخهٔ PHP را افشا می‌کرد | حذف `php`/`mbstring` از پاسخ |
| 2026-10-28 | `office7.php?action=tenants` فهرست همکاران را به هر کاربر می‌داد | کاربران عادی فقط ردیف خود را می‌بینند |
| 2026-10-28 | `api/health.php` نام جدول‌ها را عمومی افشا می‌کرد | پاسخ عمومی خلاصه؛ جزئیات فقط برای مدیر ارشد |
| 2026-10-28 | قفل ۵ تلاشی حساب = DoS هدفمند | آستانه به ۱۰ افزایش یافت + پاک‌سازی دوره‌ای |
| 2026-10-28 | کش باندل ۱ ساعته، آپدیت امنیتی را عقب می‌انداخت | ETag + `must-revalidate` |
| 2026-10-28 | Service Worker بدون کش و بدون محافظت در برابر کش دادهٔ حساس | کش فقط برای `/assets/`، `/api/*` هرگز کش نمی‌شود، صفحهٔ آفلاین |

---

## ۵. گزارش آسیب‌پذیری

اگر آسیب‌پذیری پیدا کردید، آن را عمومی منتشر نکنید؛ به مالک پروژه اطلاع دهید و امکان
اصلاح پیش از افشا بدهید.

## ۶. چک‌لیست پیش از انتشار هر نسخه

- [ ] `curl -sI https://دامنه/` → `Strict-Transport-Security` و `Content-Security-Policy`
- [ ] `curl -s https://دامنه/api/health.php` → `ok: true`
- [ ] `curl -sI https://دامنه/installer.php` → `404`
- [ ] `curl -s https://دامنه/api/.secrets/database.php` → `403`
- [ ] `curl -s https://دامنه/tools/nsc-init.php` → `403`
- [ ] `php tools/nsc-init.php --check` → بدون FAIL
