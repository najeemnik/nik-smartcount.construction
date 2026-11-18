# نصب و استفادهٔ آفلاین (لپ‌تاپ به‌عنوان سرور دفتر)

این راهنما برای حالتی است که برنامه روی **یک لپ‌تاپ در دفتر** نصب می‌شود و دستگاه‌های همان
وای‌فای/شبکه از طریق نام یا IP همان لپ‌تاپ وارد می‌شوند. اینترنت لازم نیست و هیچ داده‌ای به بیرون نمی‌رود.

> نسخهٔ آنلاین روی [nik-smartcount.com](https://nik-smartcount.com) با همین کُد کار می‌کند و **هیچ تغییری در آن لازم نیست**.
> حالت آفلاین فقط وقتی روشن می‌شود که فایل خصوصی `api/.secrets/offline.php` روی همان لپ‌تاپ ساخته شود
> و درخواست از یک آدرس شبکهٔ داخلی (LAN) بیاید.

---

## ۱. چرا در حالت قبل (XAMPP + phpMyAdmin) کار می‌کرد و این‌جا نه؟

قبلاً لپ‌تاپ فقط PHP می‌اجرا کرد و دیتابیس با phpMyAdmin دستی مدیریت می‌شد؛ «ورود» معنای
امنیتی جداگانه نداشت. این برنامه سه چیز را سخت‌گیرانه فرض کرده بود که روی LAN ممکن نیست:

| فرض نسخهٔ آنلاین | روی لپ‌تاپ با `http://192.168.x.x` | راه‌حل در این نسخه |
|---|---|---|
| کوکی نشست با پرچم `Secure` | مرورگر کوکی `Secure` را روی `http://` **ذخیره نمی‌کند** → ورود همیشه ناموفق | در حالت LAN، پرچم `Secure` برداشته می‌شود (نام کوکی همان می‌ماند) |
| کُد شش‌رقمی ایمیل پس از رمز | لپ‌تاپ SMTP/Exim ندارد → کُد نمی‌رسد | در حالت LAN این مرحله رد می‌شود و در لاگ رویدادها ثبت می‌گردد |
| نصب فقط روی `https` (Secure Context) | `http://laptop` اجازهٔ ساخت مدیر نمی‌داد | نصب‌کننده در حالت LAN اجازه می‌دهد |
| `crypto.randomUUID` و `crypto.subtle` | روی `http://` تعریف نشده‌اند → خطای مرورگر | `offline-client.js` جایگزین سازگار می‌دهد (SHA-256 تأییدشده) |

**امنیت حفظ می‌شود:** حساب‌ها، نقش‌ها، صلاحیت هر بخش، محدودیت پروژه، قفل حساب،
ورود ناموفق (۵ تلاش / ۱۵ دقیقه)، CSRF، `HttpOnly`, `SameSite=Strict` و جداسازی نشست مالک/کارمند
همه سر جای خود هستند. تفاوت فقط در «حمل» کوکی و «مرحلهٔ ایمیل» است.

---

## ۲. پیش‌نیاز لپ‌تاپ

- ویندوز ۱۰/۱۱ (یا لینوکس/مک) — **XAMPP** با PHP **8.1 یا بالاتر** و MySQL/MariaDB
- افزونه‌های PHP: `pdo_mysql`, `mbstring`, `fileinfo`, `zip`
  (در XAMPP به‌صورت پیش‌فرض فعال‌اند؛ در `php.ini` فقط مطمئن شوید `;extension=pdo_mysql` کامنت نشده باشد)
- همان لپ‌تاپ همیشه روشن و به همان وای‌فای وصل باشد (IP ثابت بهتر است)

## ۳. کپی فایل‌ها

کل پروژه را در پوشهٔ وب‌سرور بگذارید، مثلاً:

```
C:\xampp\htdocs\nsc\        (ویندوز)
/opt/lampp/htdocs/nsc/      (لینوکس)
```

سپس در مرورگر همان لپ‌تاپ: `http://localhost/nsc/installer.php`

## ۴. آماده‌سازی یک‌مرحله‌ای (ترمینال)

در `C:\xampp\php\php.exe` (ویندوز) یا `php` (لینوکس):

```
cd C:\xampp\htdocs\nsc
C:\xampp\php\php.exe api\setup-offline.php
```

از شما می‌پرسد: نام لپ‌تاپ روی شبکه (`nikbox.local`)، مشخصات MySQL، و رمز دیتابیس.
سپس این فایل‌های خصوصی ساخته می‌شوند (همه در `.gitignore` هستند و با HTTP سرو نمی‌شوند):

| فایل | کار |
|---|---|
| `api/.secrets/offline.php` | حالت LAN + نام‌های مجاز |
| `api/.secrets/database.php` | اتصال MySQL |
| `api/.secrets/email.php` | خاموش‌کردن مرحلهٔ ایمیل در حالت آفلاین |
| `api/.secrets/install.key` | کلید یک‌بارمصرف نصب (چاپ می‌شود) |

اگر فایلی از قبل باشد، **بازنویسی نمی‌شود**.

## ۵. ساخت دیتابیس (همان روش XAMPP)

در `http://localhost/phpmyadmin`:

1. **Databases** → نام `nsc_offline` → **Create** (Collation: `utf8mb4_unicode_ci`)
2. **User accounts** → یک یوزر، مثلاً `nsc` با رمز قوی → **Create user**
3. در **Database → Privileges** همان یوزر را به `nsc_offline` وصل کنید با
   `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX`

> جدول‌ها را دستی import نکنید؛ نصب‌کننده خودش `api/.secrets/schema.sql` را می‌سازد.
> اگر خواستید دستی بسازید، همان فایل را در phpMyAdmin → Import بار کنید.

## ۶. نصب از مرورگر

1. `http://nikbox.local/installer.php` (یا `http://<IP-لپ‌تاپ>/installer.php`)
2. کلید نصب را بچسبانید، مشخصات دیتابیس را همان‌طور که در مرحلهٔ ۴ دادید وارد کنید → **آزمایش اتصال**
3. **ذخیرهٔ اتصال**
4. نام کاربری، ایمیل و رمز **مالک** را بسازید (رمز حداقل ۱۲ حرف)
5. کلید نصب و فایل `install.key` را پاک کنید

## ۷. دسترسی دستگاه‌های دیگر

1. در ویندوز: **Windows Defender Firewall → Advanced settings → Inbound Rules → New Rule**
   → Port → TCP → `80` (و اگر XAMPP روی 8080 است، همان) → Allow
2. IP لپ‌تاپ را پیدا کنید: `ipconfig` → مثلاً `192.168.1.20`
3. روی موبایل/لپ‌تاپ دیگر همان وای‌فای:
   `http://192.168.1.20/installer.php` یا بعد از نصب `http://192.168.1.20/login.php`
4. اگر نام `nikbox.local` را می‌خواهید:
   - ویندوز: `C:\Windows\System32\drivers\etc\hosts` → `192.168.1.20  nikbox.local`
   - اندروید/iOS: در تنظیمات وای‌فای، DNS را روی روتر بگذارید و در روتر رکورد محلی بسازید
   - یا ساده‌تر: همان IP را استفاده کنید

> برای «نصب برنامه» (PWA) روی موبایل، مرورگر معمولاً HTTPS می‌خواهد. در حالت آفلاین
> نصب PWA ممکن نیست؛ ولی باز کردن در مرورگر و «افزودن به صفحهٔ اصلی» کار می‌کند.

## ۸. ساخت کارمند با محدودیت (همان روش آنلاین)

مالک وارد می‌شود → **مدیریت کارمندان** → ساخت حساب → برای هر ۲۰ بخش (داشبورد، پروژه‌ها،
مصارف، پرداخت‌ها، صرافی، معاشات، راپورها، تنظیمات، …) مشاهده/ثبت/ویرایش/حذف/تأیید/چاپ/خروجی
را جدا تعیین کنید. هر کارمند فقط همان را می‌بیند و سرور هم همان را اجازه می‌دهد.

## ۹. بکاپ (مهم)

```
C:\xampp\mysql\bin\mysqldump.exe -u root -p nsc_offline > D:\backup\nsc-1404-08-27.sql
```

و کل پوشهٔ `api/.secrets` را جدا کپی کنید (کلیدها و اتصال داخل آن است).

**پیشنهاد:** هر شب یک Scheduled Task همین دستور را اجرا کند و بکاپ را روی یک فلش/درایو دوم بگذارد.

## ۱۰. به‌روزرسانی نسخه

فایل‌های برنامه را جایگزین کنید، **ولی `api/.secrets` و `uploads` را دست نزنید**.
سپس یک‌بار با حساب مدیر ارشد: `POST /api/admin.php?action=upgrade` (از داخل برنامه:
دفتر هفت ← سیستم ← به‌روزرسانی ساختار). داده‌ها پاک نمی‌شوند.

---

## چه چیزی در این نسخه تغییر کرد

| فایل | تغییر |
|---|---|
| `api/offline.php` | **جدید.** تشخیص حالت LAN، اجازهٔ Originهای داخلی، تصمیم کوکی/ایمیل |
| `offline-client.js` | **جدید.** جایگزین `crypto.randomUUID` و SHA-256 برای `http://` |
| `api/setup-offline.php` | **جدید.** ساخت امن فایل‌های خصوصی + کلید نصب |
| `api/.secrets/offline.example.php` | **جدید.** الگوی تنظیمات حالت آفلاین |
| `api/web-session.php` | کوکی نشست با `secure` شرطی (آنلاین: همان `true`) |
| `api/email-security.php` | کوکی دستگاه مورداعتماد با همان قاعده |
| `api/config.php` | نگهبان CSRF/Origin، Originهای LAN را در حالت آفلاین می‌پذیرد |
| `api/auth.php` | رد کردن مرحلهٔ کُد ایمیل فقط در حالت LAN + ثبت در Audit |
| `installer.php` | اجازهٔ ساخت مدیر روی `http://` فقط در حالت LAN |
| `workspace.php` / `login.php` / `office7-login.php` | بارگذاری `offline-client.js` |

## محدودیت‌های آفلاین — صادقانه

- **دستیار هوش مصنوعی** به اینترنت نیاز دارد (در تنظیمات می‌توانید خاموشش کنید).
- **ارسال ایمیل** (کُد، اطلاعیه) بدون SMTP محلی کار نمی‌کند؛ اگر امنیت بیشتر می‌خواهید
  `emailFallback` را در `offline.php` روی `never` بگذارید و یک SMTP محلی تنظیم کنید.
- **نصب PWA** روی `http://` محدود است (بالا توضیح داده شد).
- اگر لپ‌تاپ را به اینترنت وصل کنید و پورت را باز بگذارید، بازدیدکنندهٔ اینترنتی
  **حالت آفلاین نمی‌گیرد** (IP عمومی است) و همان قواعد سخت آنلاین برایش اجرا می‌شود.
  با این حال بهترین کار: پورت ۸۰ را فقط برای شبکهٔ داخلی در فایروال باز بگذارید.

---

## English summary

- **New files:** `api/offline.php` (LAN detection + consent), `offline-client.js` (browser crypto fallbacks),
  `api/setup-offline.php` (one-shot local setup), `api/.secrets/offline.example.php`.
- **Change:** session/trusted-device cookies drop the `Secure` flag *only* in local mode (that flag is why
  `http://` logins never stuck), the six-digit email step is skipped *only* in local mode, and the installer
  may run on plain `http://` *only* in local mode.
- **Guard rails:** local mode requires an explicit `api/.secrets/offline.php` on that machine **and** a
  loopback/RFC-1918 client address. A request from a public IP never gets it, so `nik-smartcount.com`
  keeps the exact HTTPS + email-code behaviour even if the file were copied by mistake.
- **Verified:** 18/18 LAN address cases, 5 mode/environment scenarios, 4 origin-guard scenarios
  (hostname↔IP, foreign origin, public visitor), PHP parse of every edited file,
  client SHA-256/UUID fallbacks against Node's implementation.
