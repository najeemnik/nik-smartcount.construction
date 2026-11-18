# راه‌اندازی روی لپ‌تاپ — بدون اینترنت، با روتر

**هدف:** یک لپ‌تاپ «سرور» می‌شود. هر گوشی/کمپیوتر دیگری که به همان روتر وصل شود، می‌تواند با مرورگر کار کند — **حتی وقتی روتر اینترنت ندارد.**

---

## ۰. تفکیک مهم (اول این را بخوانید)

| | چیزی که شما می‌خواهید | تحقق |
|---|---|---|
| **اینترنت نباشد ولی گوشی‌ها کار کنند** | ✅ **بله** | سرور روی لپ‌تاپ است، نه روی اینترنت |
| **اپ روی گوشی نصب شود** | ⚠️ بله، ولی با شرط | نیاز به HTTPS محلی دارد |
| **گوشی بدون هیچ اتصالی (فرض: وای‌فای هم قطع) کار کند** | ❌ نه | این «آفلاین واقعی» است و پروژهٔ جدا |

اگر روتر برق دارد و وای‌فای پخش می‌کند → **همه‌چیز کار می‌کند.** اینترنت فقط برای نصب اولیه و آپدیت‌ها لازم است.

---

## ۱. نرم‌افزار لازم (همه رایگان)

| # | برنامه | چرا | لینک |
|---|---|---|---|
| ۱ | **XAMPP برای ویندوز** | Apache + PHP + MariaDB در یک بسته | `apachefriends.org/download.html` |
| ۲ | **mkcert** | ساخت گواهی HTTPS محلی (برای دوربین و نصب PWA) | `github.com/FiloSottile/mkcert/releases` |
| ۳ | NSSM (اختیاری) | XAMPP را به سرویس ویندوز تبدیل کند تا خودکار بالا بیاید | `nssm.cc/download` |

> ⚠️ **در XAMPP موقع نصب، فقط Apache و MySQL را لازم دارید.** FileZilla و Mercury را تیک نزنید.

---

## ۲. نصب XAMPP — گام‌به‌گام

1. XAMPP را نصب کنید در **`C:\xampp`** (مسیر پیش‌فرض — عوض نکنید).
2. **XAMPP Control Panel** را **به‌عنوان Administrator** باز کنید.
3. دکمهٔ **Config** کنار Apache → **`php.ini`** → این مقدارها را تغییر دهید:

```ini
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
max_input_time = 300
memory_limit = 512M
```

4. **Apache** → **Start**. **MySQL** → **Start**.
5. تست: مرورگر روی همان لپ‌تاپ → `http://localhost/` → صفحهٔ XAMPP باید بیاید.

---

## ۳. آماده کردن کد

1. کد NiK Smart Count را کپی کنید در:
   **`C:\xampp\htdocs\nik\`**
   (طوری که `C:\xampp\htdocs\nik\workspace.php` وجود داشته باشد)

2. پوشه‌های دسترسی:
   - `C:\xampp\htdocs\nik\api\.secrets\` → **قابل نوشتن** برای Apache
   - `C:\xampp\htdocs\nik\uploads\` → **قابل نوشتن**
   - `C:\xampp\htdocs\nik\api\.secrets\pre-restore\` → **قابل نوشتن** (برای بکاپ قبل از بازیابی)

3. **در `apache\conf\httpd.conf`** این خط را از کامنت خارج کنید:
```apache
LoadModule rewrite_module modules/mod_rewrite.so
```
و `<Directory "C:/xampp/htdocs">` را ببیند که `AllowOverride All` باشد (نه `None`).

---

## ۴. ساخت دیتابیس

1. `http://localhost/phpmyadmin`
2. **Databases** → نام: `nik_smart` → **Create**
3. Collation: `utf8mb4_unicode_ci`

---

## ۵. نام و آدرس ثابت در شبکه

**چرا لازم است:** اگر IP لپ‌تاپ عوض شود (`192.168.1.50` → `192.168.1.73`)، گوشی‌ها قطع می‌شوند و گواهی HTTPS هم بی‌اعتبار می‌شود.

### ۵-۱) IP ثابت در روتر

در تنظیمات روتر (`192.168.1.1` یا `192.168.0.1`) → بخش **DHCP Reservation** / **Static Lease**:
- دستگاه: نام لپ‌تاپ
- MAC: از `cmd` → `ipconfig /all` → `Physical Address`
- IP: مثلاً `192.168.1.50`

### ۵-۲) نام محلی

روی **لپ‌تاپ**، فایل زیر را با Notepad «Run as Administrator» باز کنید:
`C:\Windows\System32\drivers\etc\hosts`

این خط را اضافه کنید:
```
192.168.1.50    nik.local
```

> ⚠️ این فقط روی لپ‌تاپ کار می‌کند. برای گوشی‌ها دو راه هست:
> - **راه ساده‌تر:** از IP استفاده کنید (`https://192.168.1.50`) — ولی گواهی باید برای IP هم ساخته شود.
> - **راه بهتر:** DNS روتر را تغییر دهید تا `nik.local` را به `192.168.1.50` بفرستد (اگر روتر پشتیبانی کند).

---

## ۶. HTTPS محلی با mkcert — **مهم‌ترین گام برای دوربین و PWA**

**بدون این،** روی گوشی: دوربین اسکن کار نمی‌کند، رمز عبور هش نمی‌شود، و اپ نصب نمی‌شود.

```
mkcert -install
cd C:\xampp\apache\conf
mkcert nik.local 192.168.1.50 localhost 127.0.0.1
```

خروجی: دو فایل `nik.local+3.pem` و `nik.local+3-key.pem` در `C:\xampp\apache\conf\`.

### ۶-۱) فعال کردن SSL در Apache

در `C:\xampp\apache\conf\httpd.conf`:
```apache
LoadModule ssl_module modules/mod_ssl.so
Include conf/extra/httpd-ssl.conf
```

در `C:\xampp\apache\conf\extra\httpd-ssl.conf`، داخل `<VirtualHost _default_:443>`:
```apache
DocumentRoot "C:/xampp/htdocs/nik"
ServerName nik.local:443
SSLCertificateFile "C:/xampp/apache/conf/nik.local+3.pem"
SSLCertificateKeyFile "C:/xampp/apache/conf/nik.local+3-key.pem"
<Directory "C:/xampp/htdocs/nik">
    AllowOverride All
    Require all granted
</Directory>
```

Apache را Restart کنید.

### ۶-۲) نصب گواهی روی هر گوشی/کمپیوتر

فایل `C:\Users\<user>\AppData\Local\mkcert\rootCA.pem` را روی گوشی منتقل کنید:

- **اندروید:** Settings → Security → Encryption & credentials → Install a certificate → **CA certificate** → فایل را انتخاب کنید.
- **ویندوز:** دوبار کلیک → Install Certificate → Local Machine → **Trusted Root Certification Authorities**.

> این گام یک‌بار برای هر دستگاه است. بعد از آن، `https://nik.local` بدون هشدار باز می‌شود.

---

## ۷. فایروال ویندوز

`Windows Defender Firewall` → **Advanced settings** → **Inbound Rules** → **New Rule**:

- Type: **Port**
- Protocol: **TCP**
- Port: **443** (و 80 برای ریدایرکت)
- Action: **Allow the connection**
- Profile: **Private** فقط (نه Public — امنیت)

---

## ۸. نصب NiK Smart Count

1. روی لپ‌تاپ: `https://nik.local/installer.php`
2. دیتابیس: `nik_smart` · کاربر: `root` · رمز: **خالی** (در XAMPP پیش‌فرض)
3. حساب مدیر اصلی را بسازید
4. **بعد از نصب، پوشهٔ `installer.php` خودش 404 می‌دهد** — این درست است

---

## ۹. تست — ۱۰ مورد که باید پاس شوند

| # | تست | انتظار |
|---|---|---|
| ۱ | روی لپ‌تاپ: `https://nik.local` | ورود می‌شود |
| ۲ | روی گوشی: `https://nik.local` | ورود می‌شود، بدون هشدار گواهی |
| ۳ | روی گوشی: دوربین/اسکن | باز می‌شود |
| ۴ | روی گوشی: «Add to Home Screen» | آیکون ساخته می‌شود |
| ۵ | **اینترنت روتر را قطع کنید** — گوشی دوباره باز کند | **باید کار کند** |
| ۶ | قطع اینترنت → ثبت یک مصرف | باید ذخیره شود |
| ۷ | دو دستگاه هم‌زمان یک پروژه را باز کنند | هر دو داده می‌بینند |
| ۸ | `https://nik.local/backup.php` → «اکنون بگیر» | فایل دانلود می‌شود |
| ۹ | همان فایل را دوباره وارد کنید | ردیف‌ها دو برابر نمی‌شوند |
| ۱۰ | `https://nik.local/api/.secrets/` | **403** (نه لیست فایل) |

> تست ۵ همان چیزی است که شما می‌خواستید. **بدون اینترنت، با روتر، همه‌چیز کار می‌کند.**

---

## ۱۰. پشتیبان‌گیری خودکار (خیلی مهم)

### ۱۰-۱) اسکریپت ساده

فایل `C:\nik-backup\backup.cmd` بسازید:

```bat
@echo off
set STAMP=%DATE:~-4%%DATE:~3,2%%DATE:~0,2%
set DIR=C:\nik-backup
"C:\xampp\mysql\bin\mysqldump.exe" -u root nik_smart > "%DIR%\db-%STAMP%.sql"
"C:\Program Files\7-Zip\7z.exe" a -tzip "%DIR%\uploads-%STAMP%.zip" "C:\xampp\htdocs\nik\uploads\*"
forfiles /p "%DIR%" /m *.sql /d -14 /c "cmd /c del @path" 2>nul
forfiles /p "%DIR%" /m *.zip /d -14 /c "cmd /c del @path" 2>nul
```

### ۱۰-۲) زمان‌بندی

`Task Scheduler` → **Create Basic Task**:
- Name: `NiK Backup`
- Trigger: **Daily**, 02:00
- Action: Start program → `C:\nik-backup\backup.cmd`
- ✅ Run whether user is logged on or not

---

## ۱۱. شروع خودکار بعد از ری‌استارت

اگر لپ‌تاپ خاموش/ری‌استارت شود و کسی XAMPP را دستی روشن نکند، کل سیستم قطع می‌شود.

**راه ساده:** `C:\xampp\xampp-control.exe` → Config → ✅ **Autostart Apache** و **Autostart MySQL**

**راه بهتر با NSSM:**
```
nssm install nikApache "C:\xampp\apache\bin\httpd.exe"
nssm install nikMySQL "C:\xampp\mysql\bin\mysqld.exe" --defaults-file=C:\xampp\mysql\bin\my.ini
```
هر دو را روی **Automatic** بگذارید.

---

## ۱۲. آنچه **نمی‌تواند** کار کند (صادقانه)

| خواسته | وضعیت | چرا |
|---|---|---|
| مرورگر روی گوشی، **بدون هیچ اتصال شبکه‌ای** | ❌ | اپ باید از جایی لود شود. باید یا شبکه باشد یا نصب کامل آفلاین (فاز جدا) |
| دوربین روی `http://` | ❌ | مرورگرها فقط روی HTTPS یا `localhost` اجازه می‌دهند |
| نصب PWA روی `http://192.168.x.x` | ❌ | همان دلیل |
| همگام‌سازی بین لپ‌تاپ و سایت اینترنتی | ❌ | نیاز به فاز Outbox/Sync دارد (بخش و گزارش Audit) |

---

## ۱۳. اگر جایی گیر کردید

| نشانه | علت | راه‌حل |
|---|---|---|
| `403 Forbidden` روی همه صفحات | `AllowOverride None` | `httpd.conf` را اصلاح کنید |
| صفحه سفید | خطای PHP | `C:\xampp\apache\logs\error.log` را ببینید |
| `Access denied for user root` | رمز MySQL | در phpMyAdmin → User accounts → رمز را خالی کنید |
| گوشی وصل نمی‌شود | فایروال | Inbound rule برای 443 |
| «گواهی نامعتبر» | CA نصب نشده | `rootCA.pem` را روی گوشی نصب کنید |
| دوربین خطا می‌دهد | HTTP به‌جای HTTPS | باید `https://` باشد |
| بکاپ ZIP خطا می‌دهد | افزونهٔ zip | `php.ini` → `extension=zip` را از کامنت خارج کنید |
