# تست‌های اجرایی (Runtime tests)

این هارنس کد را **واقعاً اجرا می‌کند** — نه تحلیل ایستا. کل مخزن داخل یک فایل‌سیستم مجازی
mount می‌شود و PHP 8.3 (ساختهٔ WebAssembly) همان فایل‌های `api/*.php` را با ورودی واقعی
فراخوانی می‌کند.

## اجرا

```bash
cd tests
npm install                          # @php-wasm/node + @php-wasm/universal
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt   # برای گروه ۸
npm test                             # یا: node run-tests.mjs
```

اگر `.venv` ساخته نشود، گروه ۸ به‌صورت صریح **SKIP** می‌شود (نه PASS پنهان).

خروجی: خلاصهٔ PASS/FAIL در ترمینال و گزارش فارسی در `review/tests.html`
(مسیر `review/` در `.gitignore` است و فقط محلی می‌ماند). کد خروج ۱ اگر تستی شکست بخورد.

## چه چیزی پوشش داده می‌شود

| گروه | محتوا |
|---|---|
| ۱ | پارس همهٔ فایل‌های PHP با `token_get_all(..., TOKEN_PARSE)` — جایگزین `php -l` |
| ۲ | فراخوانی هر اندپوینت و بررسی قرارداد JSON (کد خطا، کلیدها، بدون خروجی ناخواسته) |
| ۳ | گارد CSRF در پنج سناریو (same-origin، cross-site، Origin بیگانه، هدر سفارشی، GET) |
| ۴ | توکن HMAC: صدور، دستکاری، انقضا، `jti`، طول کلید |
| ۵ | اجرای کامل `api/.secrets/schema.sql` با `PDO` جعلی + گارد نسخه (`nscSchemaEnsure`) |
| ۶ | یکپارچگی seed دفتر حساب‌ها (کد یکتا، والد موجود، نوع/تراز معتبر) |
| ۷ | هر جدولی که کد صدا می‌زند در اسکیما تعریف شده باشد |
| ۸ | اعتبارسنجی SQL با موتور واقعی: گرامر MySQL (`sqlglot`) + ساخت جدول و درج seed + `EXPLAIN` روی ۲۰۰+ کوئری استخراج‌شده از PHP (`DuckDB`) |

## چه چیزی پوشش داده **نمی‌شود**

- **سرور واقعی MySQL/MariaDB**: در این محیط قابل تهیه نیست (downloads.mariadb.org، cdn.mysql.com
  و حتی GitHub Releases مسدودند). گروه ۸ جایگزین صادقانهٔ آن است: گرامر واقعی MySQL + موتور
  واقعی SQL. تفاوت‌های مخصوص MySQL (ENUM، InnoDB، DECIMAL، قفل‌گذاری، تراکنش) اجرا نمی‌شوند.
- باندل مرورگری (`nsc-accounting-r3.js.gz`) و رابط کاربری.
- همزمانی (concurrency) و رفتار زیر بار.

برای پوشش این سه مورد به یک محیط با MySQL واقعی و مرورگر نیاز است.
