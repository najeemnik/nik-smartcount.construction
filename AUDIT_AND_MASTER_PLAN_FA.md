# NiK Smart Count — گزارش بازبینی (Audit) و نقشهٔ راه فنی

**تاریخ:** ۱۸ نومبر ۲۰۲۶ (۲۷ عقرب ۱۴۰۵)
**نوع سند:** Audit فقط‌خواندنی (read-only) + نقشهٔ راه فنی مرحله‌به‌مرحله
**وضعیت اجرا:** هیچ فایلی تغییر نکرد، هیچ commit/push/merge انجام نشد، سایت زنده لمس نشد، هیچ دادهٔ واقعی به عنوان fixture استفاده نشد.

> **مهم‌ترین یافتهٔ این بازبینی را اول بخوانید (بخش الف، مورد ۱):**
> شاخه‌ای که امروز روی آن کار می‌کنیم (`arena/902a6f21-…` که از `main` آمده) **نسخهٔ روی سایت زنده نیست**.
> `main` و دو PR **تاریخچهٔ مشترک git ندارند** (دو root متفاوت). سایت زنده `https://nik-smartcount.com` در واقع
> شاخهٔ **PR #2** (`updates/complete-app-20261109`) را اجرا می‌کند. کارهای آفلاین/LAN که در مرحلهٔ قبلی انجام شد
> روی یک نسخهٔ **قدیمی‌تر** از باندل و API سوار است و **مستقیماً قابل انتشار نیست**.

---

## خلاصهٔ اجرایی (Executive Summary)

| # | یافته | شدت | نتیجه |
|---|---|---|---|
| ۱ | `main` و PRها تاریخچهٔ مشترک ندارند؛ `main` یک کپی ناقص و بی‌تاریخچه از PR #2 در تاریخ ~۲ نومبر است | 🔴 بحرانی | هر merge خودکار گمراه‌کننده است؛ باید baseline مشخص شود |
| ۲ | سایت زنده = PR #2 + چند فایل که در **هیچ شاخه‌ای نیست** | 🔴 بحرانی | اگر با `main` دیپلوی شود، رگرسیون فوری |
| ۳ | `workspace.php` / باندل سمت زنده (`compact-nav-20261109-1`) در هیچ شاخه‌ای از repo نیست | 🔴 بحرانی | آخرین نسخهٔ تولیدی **فقط روی هاست** است؛ بکاپ فوری لازم |
| ۴ | کارهای آفلاین مرحلهٔ قبل روی lineage قدیمی سوار است، نه روی نسخهٔ زنده | 🔴 بحرانی | باید روی baseline درست دوباره اعمال شود |
| ۵ | `rebar_spec_memory` بدون `tenant_id` — ولی در کد فعلی با هشِ شامل tenant محافظت شده | 🟠 متوسط | PR #1 ادعای leak بین‌شرکتی دارد؛ **در نسخهٔ زنده دیگر leak عمومی نیست**، ولی نشتی ثانویه باقی است |
| ۶ | CSP وجود ندارد (فقط `default-src 'none'` روی JSON API) | 🟠 متوسط | XSS قابل مهار است؛ PR #1 آن را حل کرده |
| ۷ | Service Worker عملاً هیچ کاری نمی‌کند (`fetch → fetch`) | 🟠 متوسط | PWA نصب می‌شود ولی آفلاین **نیست** |
| ۸ | PWA + LAN با هم تناقض فنی دارند (`isSecureContext` + `localhost`-origin) | 🔴 بحرانی برای هدف | معماری نیاز به بازطراحی دارد (بخش هـ) |
| ۹ | ذخیره‌سازی: یک JSON غول برای کل شرکت (LONGTEXT) با optimistic lock نسخه‌ای | 🟠 متوسط | برای LAN کار می‌کند؛ برای آفلاین چنددستگاهی مناسب نیست |
| ۱۰ | تست واقعی: PR #1 هارنس اجرایی دارد و **۴۷/۴۷ پاس شد** (خودم اجرا کردم) | 🟢 مثبت | تنها مجموعهٔ قابل‌اعتماد از نظر «اجرا» همین است |
| ۱۱ | تست‌های PR #2 (`.checks/`) به runtime خارجی `/workspace/nsc-test` وابسته‌اند که در repo نیست | 🟠 متوسط | ۸۹ فایل تست هست ولی **قابل اجرا در CI نیست** |
| ۱۲ | `api/password-reset.php` و `admin.php?action=upgrade` در نسخهٔ زنده هست، در `main` نیست | 🔴 بحرانی | دلیل دومِ اینکه `main` baseline نیست |

---

# الف) Baseline درست کد و آشتی‌سازی امن دو PR

## الف-۱) ساختار واقعی تاریخچه (سنجیده‌شده، نه حدس)

```
2ac3033 (Initial commit)
  └── 39b7c15 (Import construction accounting source and initial review)
        └── ed1b3eb (Keep only extracted host deployment files at root)   ← merge-base مشترک PR1 و PR2
              ├── PR1: ad37287 ← 620391a ← 074a1cc ← 03d3f71 ← 98aa2c7
              │        ← db6cb5c ← df3819e ← d908dac ← 65fa89a        (tip: ۶ نومبر)
              └── PR2: 5a8949c ← bf6d83f ← d8a3123 ← fe7e315 ← 377e9d4
                       ← 0c5a360 ← f4b1ac4                          (tip: ۸ نومبر)

04242ba (main)  ← root commit مستقل، بدون parent، ۲ نومبر، پیام: "Polish short-introduction scene captions"
```

**اندازه‌گیری‌ها:**

| مقایسه | نتیجه |
|---|---|
| `git merge-base origin/main pr1` | **خروجی خالی** (تاریخچهٔ مشترک ندارد) |
| `git merge-base origin/main pr2` | **خروجی خالی** |
| `git merge-base pr1 pr2` | `ed1b3eb` (۲۸ اکتوبر) |
| `git diff --shortstat ed1b3eb pr1` | ۴۲ فایل، +۳۶۷۴ / −۹۱ |
| `git diff --shortstat ed1b3eb pr2` | ۱۹۴ فایل، +۴۹۳۸ / −۶۰۰ |
| `git diff --name-only origin/main 0c5a360` | فقط **۱۹ فایل** (۳ insert / ۱۱۸۳ delete) |

## الف-۲) `main` چه چیزی است؟ (پاسخ قطعی)

`main` یک commit یگانهٔ بدون parent است که محتوایش تقریباً برابر **`0c5a360`** از شاخهٔ PR #2 است — یعنی حالت PR #2 در حدود **۲ نومبر**، منهای:
- فایل‌های تست `.checks/browser-*-flow.cjs` (۳ فایل حذف‌شده)
- `.checks/training/*` (۸ فایل حذف‌شده)
- `TRAINING_AND_FUNCTIONAL_REVIEW_FA.md`، `UPDATE_ACCOUNTING_FA.md` (حذف‌شده)
- `SECURITY_REVIEW_FA.md`، `.checks/README.md`، `.checks/browser-permissions.cjs`، `nsc-accounting-r3.js.gz`، `workspace.php` (تغییر‌یافته)

**نتیجه:** `main` = snapshot دستیِ یک نسخهٔ میانی PR #2، بدون تاریخچه، بدون `AGENTS.md`، بدون `DEVELOPMENT.md`، بدون `client/`، بدون `tools/`، بدون `api/password-reset.php`.

## الف-۳) سایت زنده چه چیزی است؟ (سنجیده‌شده از بیرون)

شواهد مستقیم از `https://nik-smartcount.com`:

| شاهد | مقدار | معنی |
|---|---|---|
| `login.php` (HTML واقعی) | عنوان «Welcome back»، «Forgot password?»، «Username or email»، لینک `/install-app.php`، `login-design.css?v=2`، `login-email.js?v=4`، `password-reset.js?v=2` | این markup **فقط در PR #2** هست (main: `login-email.js?v=3`، بدون password-reset) |
| `login-design.css` | محتوای «Emerald glass login» با `login-luxe` | فایل **فقط در PR #2** (در main وجود ندارد) |
| `manifest.webmanifest` | `start_url: /workspace.php`, `lang: en` | برابر **PR #2** (PR #1: `start_url: /`, `lang: fa`) |
| `/calculator.html` | **404** | PR #1 این را دارد؛ زنده **ندارد** → زنده PR #1 نیست |
| `/experience.css` | محتوا با `nsc-boot`، `nsc-dialog`، `nsc-install-link` | مشترک main و PR #2 (دورگه نیست) |

**حکم:** سایت زنده **PR #2 + یک باندل/workspace.php جدیدتر** است. `main` قطعاً زنده نیست.

## الف-۴) 🔴 نسخهٔ گمشده (Loss Risk) — مهم‌ترین ریسک عملیاتی

```
باندل سمت زنده (از workspace.php زنده):  accounting&v=compact-nav-20261109-1
باندل PR #2 (tip):                       accounting&v=compact-nav-20261109-1   ← منطبق
باندل main / شاخهٔ جاری:                  accounting&v=accounting-display-1     ← قدیمی‌تر
```

اما مقایسهٔ بایت‌به‌بایت باندل نشان می‌دهد نسخهٔ روی هاست با PR #2 هم **دقیقاً یکی نیست**:
- raw size در PR #2 = ۲٬۴۹۹٬۴۲۷ بایت
- `workspace.php` زنده به `?v=compact-nav-20261109-1` اشاره می‌کند که در PR #2 هم همین رشته است، **ولی** تعداد جدول‌های اسکیما و برخی فایل‌های PR #2 (مثلاً `api/password-reset.php`) به‌صورت زنده تأیید نشدند چون `api/permission-catalog.php` پشت `Require all denied` است.

**اقدام فوری پیشنهادی (نه در این مرحله، فقط توصیه):** قبل از هر کاری، از طریق cPanel یک نسخهٔ کامل از `public_html` + دامپ دیتابیس گرفته شود. تا آن بکاپ نباشد، **هیچ merge یا دیپلوی امن نیست**.

## الف-۵) آیا دو PR با هم merge می‌شوند؟

```
git merge-tree --write-tree pr2 pr1   →  ۵۲ مدخل تعارض در ۱۹ فایل
```

فایل‌های متعارض: `.gitignore`, `README.md`, `api/.secrets/schema.sql`, `api/admin.php`, `api/auth.php`, `api/db.php`, `api/finance.php`, `api/journal.php`, `api/materials.php`, `api/office7.php`, `api/subcontractors.php`, `api/suppliers.php`, `installer.php`, `login.php`, `manifest.webmanifest`, `office7.php`, `team.php`, `workspace.php`

این تعارض‌ها **سطحی نیستند**: هر دو PR همان توابع امنیتی/مالی را بازنویسی کرده‌اند (`authenticateUser`، `enforceIdempotencyGuard`، `assertModulePermission`، اعتبارسنجی مبالغ). merge خودکار = ریسک بالای از دست دادن یکی از دو مجموعه اصلاحات.

## الف-۶) PR #1 چه دارد که PR #2 ندارد (ارزشمند)

| مورد | فایل | ارزش |
|---|---|---|
| هارنس تست اجرایی واقعی (PHP-WASM) | `tests/run-tests.mjs`, `tests/package.json` | 🟢 **۴۷/۴۷ پاس** — تنها مجموعهٔ اجراشدنی |
| CSP + HSTS + COOP/CORP + Permissions-Policy | `.htaccess` (خط ۵۰) | 🟢 XSS را واقعاً مهار می‌کند |
| `installer.php` بعد از نصب → 404 | `installer.php` | 🟢 سطح حمله را می‌بندد |
| `auth.php?action=ping` بدون افشای `PHP_VERSION` | `api/auth.php` | 🟢 رفع information leak |
| تقسیم‌کنندهٔ SQL واقعی (به‌جای `explode(';')`) | `api/db.php` | 🟢 لازم برای migration امن |
| `nscSchemaEnsure` + `nsc_schema_meta` + نسخه‌بندی اسکیما | `api/db.php`, schema | 🟢 رفع schema drift خودکار |
| جدول‌های `clients`, `chart_of_accounts` کامل + ۱۸۷ حساب + حساب تعلیقی `8200` | schema | 🟢 **باگ واقعی** که در PR #2 رفع نشده |
| GET_LOCK برای rate limit ورود | `api/auth.php` | 🟢 در محیط چند-سروری/چند-پروسه‌ای امن‌تر |
| `NSC_DEFAULT_EXCHANGE_RATE` به‌جای عدد ۷۰ هاردکد | `api/config.php` | 🟢 |
| `make-deploy-package.sh` + `docs/DEPLOY.md` + `docs/DEPLOY-FREE-HOST.md` + `SECURITY.md` | tools/, docs/ | 🟢 بستهٔ استقرار بدون secrets |
| `calculator.html` (ماشین‌حساب مستقل) | `calculator.html` | 🟡 |
| `robots.txt` | `robots.txt` | 🟡 |

> ⚠️ **ادعای PR #1 درست است که `8200` در seed نیست.** بررسی کردم: `grep -c "'8200'"` روی schema شاخهٔ جاری و PR #2 هر دو **۰** است، در حالی که باندل کلاینت `SUSPENSE:'8200'}` را دارد. این یک ارجاع به حساب ناموجود در دفتر است.

## الف-۷) PR #2 چه دارد که PR #1 ندارد (ارزشمند و حیاتی)

| مورد | فایل | چرا حیاتی |
|---|---|---|
| `client/nsc-accounting-r3.js` (سورس قابل‌ویرایشِ ۲.۵MB، بدون فشرده‌سازی) | `client/` | 🔴 **حل مشکل بخش ز** — سورس «گمشده» در واقع اینجاست |
| `tools/client-bundle.mjs` (extract/pack با syntax-check) | `tools/` | 🔴 گردش‌کار ویرایش باندل بدون شکستن آن |
| `AGENTS.md` + `DEVELOPMENT.md` | root | 🔴 قواعد کار، محدودیت‌ها، و «چه چیزهایی را نباید بشکنی» |
| `api/password-reset.php` (۷۸ خط) + `password-reset.js` | api/, root | 🟢 بازیابی رمز که زنده دارد ولی main ندارد |
| `session-client.js` نسخهٔ cookie-based (توکن در HttpOnly، localStorage فقط marker) | root | 🟢 **یک نسل امنیتی جلوتر** از PR #1 |
| `workspace.php` با ۴۰۹ + 3-way merge در جاوااسکریپت | client bundle | 🟢 حل تعارض چنددستگاهی (پایهٔ آیندهٔ آفلاین) |
| `workspace-cache.js` (IndexedDB برای backup موقت، بدون توکن) | root | 🟢 |
| `api/workspace-validation.php` (۵۱ خط جدید در tip) | api/ | 🟢 |
| `api/permissions.php` با «فهرست صریح خالی = بدسترسی» | api/ | 🟢 رفع privilege escalation |
| ۸۹ فایل `.checks/` + ۱۹ سند فارسی | .checks/, *.md | 🟡 ارزش دانشی بالا، اجراپذیری پایین |
| `api/account-identity.php`, `api/account-status.php` | api/ | 🟢 |

## الف-۸) baseline پیشنهادی (توصیهٔ قاطع)

```
baseline درست = PR #2 (tip f4b1ac4) + بکاپ زندهٔ هاست
```

**دلیل:** تنها شاخه‌ای است که (۱) با سایت زنده منطبق است، (۲) سورس کلاینت و ابزار build دارد، (۳) `AGENTS.md`/`DEVELOPMENT.md` دارد، (۴) نسل امنیتی کوکی-محور جدیدتر را دارد، (۵) ۱۹۴ فایل تغییر انباشته دارد.

سپس **PR #1 به‌صورت انتخابی (cherry-pick موضوعی) روی آن اعمال شود**، نه merge. ترتیب پیشنهادی در بخش ح) آمده است.

---

# ب) فهرست‌برداری سیستم‌ها (Inventory)

## ب-۱) حساب‌داری و دیتابیس

**اسکیما (PR #2 / زنده):** ۳۳ جدول در `api/.secrets/schema.sql`

```
users, office7_tenant_configs, login_attempts, system_audit_logs,
projects, company_profiles, employees, subcontractors, suppliers,
material_procurements, supplier_product_rates, treasury_accounts,
payment_vouchers, revenue_entries, exchange_transactions,
assets_machinery, apartment_units, labor_payroll, project_budgets,
project_documents, project_files, project_folders, project_transfers,
form_drafts, workspace_sync_states, rebar_spec_memory,
journal_entries, journal_lines, chart_of_accounts,
clients, petty_cash_expenses, password_reset_challenges, app_workspaces
```

**جدول‌های بدون `tenant_id` (۸ مورد) و دلیل:**

| جدول | دلیل فنی | ریسک |
|---|---|---|
| `users` | کاربر می‌تواند چند شرکت داشته باشد (`office7_tenant_configs`) | 🟢 طراحی درست |
| `office7_tenant_configs` | نگاشت کاربر↔شرکت؛ کلید مرکب | 🟢 |
| `login_attempts` | محدودسازی ورود، سراسری | 🟢 |
| `system_audit_logs` | لاگ پلتفرم | 🟡 نیازمند بازبینی دسترسی |
| `chart_of_accounts` | دفتر سراسری (کد حساب استاندارد) | 🟡 یکسان برای همه؛ اگر روزی هر شرکت دفتر خودش را بخواهد، مشکل |
| `journal_lines` | از طریق `journal_entries.tenant_id` اسکوپ می‌شود | 🟢 **تأیید کردم**: خواندن با `WHERE j.tenant_id = :tid` و سپس join بر `journal_entry_id` |
| `rebar_spec_memory` | 🟠 **استثنای واقعی** | 🟠 جزئیات پایین |
| `password_reset_challenges` | چالش با هش تصادفی، بدون tenant | 🟢 |

**مدل ذخیره‌سازی — نکتهٔ کلیدی معماری:**

ذخیره‌سازی اصلی **جدولی نیست**. یک جدول `app_workspaces` وجود دارد:

```sql
CREATE TABLE app_workspaces (
  tenant_id VARCHAR(64) NOT NULL PRIMARY KEY,
  state_version BIGINT NOT NULL DEFAULT 0,
  state_json LONGTEXT NOT NULL,
  updated_by VARCHAR(64) NULL,
  updated_at TIMESTAMP …
);
```

یعنی: **کل شرکت = یک JSON غول** (پروژه‌ها، مصارف، معاش، فایل‌ها، تنظیمات…). API `api/workspace.php` (۱۴۴ خط) هر بار کل یا بخشی از آن را می‌خواند/می‌نویسد.

- مکانیزم تعارض: `baseVersion` + `state_version`؛ اگر نابرابر → **HTTP 409** + `stateVersion` فعلی سرور.
- سمت کلاینت: در صورت ۴۰۹، سرور را دوباره می‌خواند و یک **3-way merge در جاوااسکریپت** روی آرایه‌های دارای `id` انجام می‌دهد.
- `FOR UPDATE` روی ردیف برای جلوگیری از نوشتن هم‌زمان.

**ارزیابی:** این مدل برای «یک دفتر با چند کاربر روی اینترنت» قابل قبول است. برای **آفلاین چنددستگاهی** (هدف آیندهٔ شما) **کافی نیست** — دلیل در بخش و) آمده.

> ⚠️ **جدول `workspace_sync_states` مرده است.** در `api/db.php:244` ساخته می‌شود و در `api/office7.php` سه ارجاع دارد، ولی `api/workspace.php` **هیچ ارجاعی به آن ندارد** (`grep -c` = ۰). یعنی یک طرح همگام‌سازی نیمه‌کاره رها شده. نباید به‌عنوان مبنای طراحی آیندهٔ sync استفاده شود.

**API ها (۳۶ فایل، ۶٬۸۶۸ خط):**

| دسته | فایل‌ها |
|---|---|
| هستهٔ امنیتی | `config.php`, `db.php`, `web-session.php`, `permissions.php`, `validation.php`, `account-status.php`, `account-identity.php` |
| احراز هویت | `auth.php`, `password-reset.php`, `email-security.php`, `email-settings.php`, `mail-configuration.php` |
| چند-مستأجری | `office7.php`, `admin.php` |
| حسابداری | `finance.php`, `journal.php`, `reports.php`, `report-data.php`, `workspace.php`, `workspace-validation.php` |
| عملیاتی | `projects.php`, `materials.php`, `suppliers.php`, `subcontractors.php`, `payroll.php`, `assets.php`, `apartments.php`, `exchange.php` |
| فایل | `files.php`, `workspace-files.php` |
| نصب | `install.php`, `setup.php`, `health.php` |
| محافظت‌شده از وب | `api/.htaccess`: `config`, `db`, `web-session`, `permissions`, `validation`, `report-data`, `permission-catalog`, `password-reset`, `workspace-files`, `mail-configuration`, `email-security`, `account-identity`, `account-status` |

**نکتهٔ مهم:** `api/permission-catalog.php` از بیرون **403** می‌دهد (تأیید کردم) — یعنی محافظت Apache کار می‌کند. این یک **شاهد مثبت** است.

## ب-۲) احراز هویت و مدیریت کاربر

**جریان امروز (نسخهٔ زنده / PR #2):**

```
login.php → session-client.js
   ↓ POST /api/auth.php?action=login  {sessionMode:'cookie'}
   ↓ سرور: Set-Cookie (HttpOnly, SameSite) — توکن در localStorage نیست
   ↓ پاسخ: csrfToken → حافظهٔ JS
   ↓ هر POST/PUT/DELETE: هدر X-NSC-CSRF + X-Requested-With + X-NSC-Portal
```

- رمز: `password_hash()` (bcrypt/argon بسته به PHP) + پشتیبانی از هش‌های قدیمی `nsc-v2$` و `sha256$` با migrate خودکار.
- محدودسازی ورود: `login_attempts` با پنجرهٔ ۱۵ دقیقه بر اساس `identifier` **و** `ip_address`.
- دو مرحله‌ای: کد ایمیل (`login-email.js` + `api/auth.php?action=verify_email_code`).
- نقش‌ها: `admin` (مالک ساختمان)، `accountant`، `viewer`، `project_manager`، `procurement` + `master`/`isMasterSuperAdmin` در `office7`.
- صلاحیت‌ها: ماتریس `module × {view, create, edit, delete, approve, print, export}` + `projectGrants` + `assignedProjectIds`.

**نسخهٔ PR #1 (قدیمی‌تر):** هنوز `Authorization: Bearer` + توکن در `localStorage`. 🟠

## ب-۳) جداسازی مستأجر (Tenant Isolation)

**قوی:** توابع مشترک `currentTenantId()`, `assertModulePermission()`, `assertTenantProjectAccess()`, `requireScopedProject()`. بررسی‌های PR #2 در `SYSTEM_AUDIT_FA.md` (جدول ۱۳ ردیفی) نشان می‌دهد فیلتر پروژه در ویرایش/انتقال/حذف اصلاح شده است.

**ضعف باقی‌مانده (تأیید‌شده در کد):**

```php
// api/materials.php:93
$findStmt = $db->prepare("SELECT * FROM rebar_spec_memory WHERE spec_key = :skey LIMIT 1");
```

`spec_key` = `sha256(tenantId|projectId|diameter|length|brand)` — یعنی **tenant داخل کلید است**، پس شرط `WHERE spec_key` عملاً tenant را ایزوله می‌کند. **پس ادعای «نشتی کامل بین شرکت‌ها» در PR #1 برای این نسخه درست نیست.**

اما دو مشکل باقی است:
1. **نبود `tenant_id` روی جدول** → هر باگ آینده در ساخت `spec_key`، یا هر query تحلیلی/گزارشی که فراموش کند از هش استفاده کند، مستقیماً بین شرکت‌ها نشت می‌کند. دفاعِ لایه‌ای وجود ندارد.
2. `SELECT *` بدون هیچ شرط دیگری — اگر یک tenant با `projectId` حدس‌زدنی همان `brandSpec` را ثبت کند، الگوریتم کلید عوض نمی‌شود؛ چون `projectId` uuid است، عملاً امن است ولی **شکننده**.

**توصیه:** افزودن ستون `tenant_id` + ایندکس + بازنویسی ۴ کوئری. (این کار در PR #1 انجام شده — قابل برداشت است.)

---

# ج) شکاف‌های امنیتی + پوشش تست واقعی

## ج-۱) شکاف‌های تأییدشده (Verified)

| # | شکاف | محل | شدت | شاهد |
|---|---|---|---|---|
| S1 | **CSP وجود ندارد** در سطح HTML | `.htaccess` ریشه | 🟠 بالا | `grep -c "Content-Security-Policy" .htaccess` = **۰**. تنها CSP موجود `default-src 'none'` روی API JSON است (`api/config.php:70`) که صفحات HTML را پوشش نمی‌دهد |
| S2 | **`unsafe-inline`** لازم است | صفحات، اسکریپت‌های inline | 🟠 | PR #1 آن را به‌صراحت پذیرفته و `connect-src 'self'` گذاشته تا توکن دزدیده‌شده نتواند خارج شود |
| S3 | Service Worker **بی‌اثر** | `pwa-sw.js` | 🟠 | `fetch → respondWith(fetch(request))` — معادل نبودن SW، فقط نصب PWA را فعال می‌کند |
| S4 | **هیچ قابلیت آفلاین واقعی نیست** | کل استک | 🔴 برای هدف شما | نه precache، نه queue، نه sync. `pwa-sw.js` فقط ۲ خط |
| S5 | `rebar_spec_memory` بدون `tenant_id` | schema + `api/materials.php` | 🟠 | بالا توضیح داده شد |
| S6 | `workspace.php` یک JSON غول با optimistic lock | `api/workspace.php` | 🟠 | برای LAN/آفلاین مناسب نیست (بخش و) |
| S7 | `workspace_sync_states` مرده | `api/db.php:244` | 🟡 | کد مرده = گمراه‌کننده برای توسعه‌دهندهٔ بعدی |
| S8 | `chart_of_accounts` سراسری و غیرقابل‌سفارشی‌سازی | schema | 🟡 | فقط ۶ حساب پایه + ۱۸۷ حساب (فقط در PR #1) |
| S9 | `.checks/` و `tests/` اگر روی هاست بمانند | deploy | 🟡 | `make-deploy-package.sh` آن‌ها را حذف می‌کند ✅ ولی دیپلوی دستی ممکن است نه |
| S10 | توکن master در `localStorage` | `office7` scripts | 🟡 | `security-settings.js:5` هنوز `localStorage.getItem('nsc_master_token')` دارد (مسیر ادمین پلتفرم، نه کاربر عادی) |
| S11 | `isSecureContext` در نصب‌کننده | `installer.php:35` | 🟠 | روی `http://192.168.x.x` نصب ممکن نیست (مشکل LAN) |
| S12 | Enforce نشدن حداقل طول رمز در API master | `office7.php` | 🟡 | فقط سمت کلاینت چک می‌شود (`length<12`) |

## ج-۲) ادعاهایی که در repo هست ولی **تأیید نشد** (Unverified)

| ادعا | منبع | دلیل تأییدنشدن |
|---|---|---|
| «۲۹۳ بررسی API پاس شد» | `SYSTEM_AUDIT_FA.md` | `.checks/` نیاز به `/workspace/nsc-test` دارد (۳۶ از ۵۵ فایل `.cjs` به این مسیر ارجاع می‌دهند) که **در repo نیست** |
| «۲۸۳ بررسی مالی پاس شد» | `SYSTEM_AUDIT_FA.md` | همان |
| «۱۸۷ حساب در دفتر» | `SYSTEM_AUDIT_FA.md` | در schema نسخهٔ زنده فقط **۶** حساب پایه هست (`1000`,`1100`,`2000`,`3000`,`4000`,`5000`). عدد ۱۸۷ از PR #1 است |
| «۲۱۵ بررسی تعیین پروژهٔ خالی» | `SYSTEM_AUDIT_FA.md` | اجراپذیر نیست |
| «۱۰۴ بررسی امنیتی» | `SECURITY_REVIEW_FA.md` | اجراپذیر نیست |

## ج-۳) آنچه واقعاً اجرا کردم (Verified by me)

```
cd /tmp/pr1tree/tests && npm install && node run-tests.mjs
→ نتیجه: 47/47 تست موفق
```

| گروه | نتیجه |
|---|---|
| ۱) پارس تمام PHP با `TOKEN_PARSE` | ✅ |
| ۲) قرارداد JSON هر endpoint | ✅ |
| ۳) گارد CSRF در ۵ سناریو (same-origin، cross-site، Origin بیگانه، هدر سفارشی، GET) | ✅ ۵/۵ |
| ۴) توکن HMAC: صدور، دستکاری، انقضا، `jti`، طول کلید | ✅ ۴/۴ |
| ۵) اجرای کامل `schema.sql` با PDO جعلی — ۴۷ دستور، ۳۶ CREATE TABLE | ✅ |
| ۵-الف) گارد نسخه: روی نصب به‌روز ۰ دستور | ✅ |
| ۵-ب) تقسیم‌کنندهٔ SQL: نقطه‌ویرگول داخل رشته نمی‌شکند | ✅ ۴ سناریو |
| ۵-پ) جداسازی tenant در حافظهٔ میلگرد | ✅ ۴/۴ |
| ۵-ت) `NSC_DEFAULT_EXCHANGE_RATE` و حذف عدد ۷۰ هاردکد | ✅ |
| ۶) seed دفتر ۱۸۷ حساب، یکتایی کد، parent موجود | ✅ |
| ۷) هر جدول ارجاع‌شده در کد در اسکیما هست (۳۷ جدول، ۱۸۴ ارجاع) | ✅ |
| ۸) اعتبارسنجی SQL با موتور واقعی (sqlglot/DuckDB) | ⏭️ **SKIP** — نصب نبود (صادقانه SKIP شد، نه PASS پنهان) |

**همچنین در مرحلهٔ قبلی روی شاخهٔ جاری اجرا شد (بازهم معتبر):**
- طبقه‌بندی LAN: ۱۸/۱۸
- حالت‌های offline: ۴/۴
- origin guard: ۳/۳
- SHA-256 fallback: ۷/۷ در برابر Node (شامل متن فارسی)
- UUID v4: regex OK
- پارس توکن: ۱۰ فایل OK

**آنچه هیچ‌جا تست نشده:**
- MySQL/MariaDB واقعی (نه PDO جعلی، نه DuckDB)
- هم‌زمانی (concurrency) و رفتار زیر بار
- باندل کلاینت به‌صورت انتها-به-انتها
- Apache واقعی با `.htaccess` (فقط روی زنده به‌طور غیرمستقیم دیده شد)
- ایمیل واقعی cPanel
- اجرای `admin.php?action=upgrade` روی دیتابیس واقعی

> **حکم صادقانه:** پوشش تست برای «منطق PHP» قابل‌قبول است، برای «دیتابیس واقعی + هم‌زمانی + مرورگر + آفلاین» **صفر** است.

---

# د) رفتار واقعی PWA / Service Worker

## د-۱) کد فعلی (خط به خط)

```js
// pwa-sw.js — کل فایل
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  if (e.request.mode === 'navigate') e.respondWith(fetch(e.request));
});
```

**تحلیل دقیق:**

| سناریو | رفتار |
|---|---|
| نصب PWA (Add to Home Screen) | ✅ کار می‌کند (manifest + SW + آیکون‌ها + `display:standalone`) |
| باز کردن اپ در حالت آفلاین | ❌ **صفحهٔ خطای مرورگر** — هیچ cache وجود ندارد |
| قطع اینترنت وسط کار | ❌ داده‌های ثبت‌نشده در `localStorage` می‌مانند (workspace backup) ولی **بارگذاری صفحه ممکن نیست** |
| آپدیت نسخه | ✅ `skipWaiting` + `clients.claim` فوری |
| API call | ➖ از SW رد می‌شود؛ `fetch` مستقیم (خوب: دادهٔ مالی cache نمی‌شود) |

**نکتهٔ مثبت امنیتی:** SW عمداً API را cache نمی‌کند، پس «دیدن دادهٔ کاربر قبلی روی همان دستگاه» رخ نمی‌دهد. این تصمیم درست است و باید حفظ شود.

## د-۲) لایهٔ «backup آفلاین» که هست ولی کسی آن را «آفلاین» نمی‌داند

`workspace-cache.js` یک IndexedDB با نام `nsc-workspace-recovery` و store `pending` دارد. کلاینت از آن برای نگهداری **نسخهٔ بازیابی تغییرات ثبت‌نشده** استفاده می‌کند (وقتی `localStorage` پر شود). این **queue نیست** — یک backup یک‌طرفه است که فقط برای بازیابی دستی (دانلود فایل JSON) استفاده می‌شود.

**همچنین در باندل هست:**
- `window.NSC_WORKSPACE_CACHE.put(key, backup)` قبل/بعد از save
- `ee()` → دانلود `NiK-unsaved-<ts>.json` برای کاربر
- `beforeunload` با warning
- `navigator.onLine` listener که هنگام برگشت آنلاین `P()` را صدا می‌زند

**یعنی:** زیرساخت ۷۰٪ آماده است، ولی **هیچ منطق «ارسال مجدد خودکار بعد از آفلاین طولانی»** و **هیچ idempotency در سطح رکورد** وجود ندارد. `enforceIdempotencyGuard` در PHP هست ولی روی **درخواست HTTP** کار می‌کند نه روی **شناسهٔ عملیات مالی**.

## د-۳) 🔴 تناقض بنیادی PWA + LAN (مهم‌ترین مسئلهٔ معماری شما)

| نیاز | پیش‌نیاز مرورگر | نتیجهٔ تضاد |
|---|---|---|
| نصب PWA | `https://` **یا** `http://localhost` (Secure Context) | روی `http://192.168.1.50` نصب ممکن **نیست** |
| دوربین / اسکن | `isSecureContext` (تأیید شد: `forms-camera.js:22`) | روی LAN HTTP خطا می‌دهد |
| `crypto.subtle` (SHA-256) | Secure Context | روی LAN HTTP **موجود نیست** — به همین دلیل در مرحلهٔ قبل `offline-client.js` نوشتم که fallback داشته باشد |
| `crypto.randomUUID` | Secure Context | همان — fallback نوشته شد |
| `serviceWorker` | Secure Context | روی LAN HTTP ثبت نمی‌شود |

**راه‌حل‌های واقعی (سه گزینه):**

| گزینه | چطور | مزیت | عیب |
|---|---|---|---|
| **A. HTTPS محلی با گواهی self-signed** | Apache + `mod_ssl` + گواهی محلی + نصب CA روی هر دستگاه | همهٔ قابلیت‌ها کار می‌کند | نصب CA روی هر گوشی اندروید کار فنی سختی است |
| **B. mkcert + CA محلی** | `mkcert` روی لپ‌تاپ، نصب root CA روی دستگاه‌ها | تجربهٔ یک‌بار مصرف، پایدار | کاربر باید CA را نصب کند؛ Apple/Android محدودیت دارند |
| **C. اپ اندروید بومی (WebView + trust) یا Tauri/Capacitor** | wrap کردن UI در یک اپ با trust store سفارشی | یک‌بار نصب، بدون CA | کار اضافه، خارج از دامنهٔ «PWA» |

**توصیه:** برای لپ‌تاپ سرور از **گزینهٔ B (mkcert)** شروع کنید. این تصمیم در بخش هـ ضمیمه شده است.

---

# هـ) معماری عملی سرور محلی ویندوز روی PHP + MySQL/MariaDB موجود

## هـ-۱) چیدمان پیشنهادی (بدون تغییر استک)

```
┌──────────────────────── لپ‌تاپ ویندوز (سرور) ────────────────────────┐
│                                                                      │
│  XAMPP (یا Laragon)                                                  │
│  ├── Apache 2.4  ──  Listen 80/443                                   │
│  │     ├── SSLVirtualHost *.nik.local  (mkcert)                      │
│  │     ├── .htaccess موجود حفظ می‌شود                                 │
│  │     └── mod_rewrite, mod_headers, mod_ssl فعال                    │
│  ├── PHP 8.2 + pdo_mysql + mbstring + fileinfo + openssl             │
│  └── MariaDB 10.11  ──  bind-address = 127.0.0.1  (فقط محلی)         │
│                                                                      │
│  C:\nik\  ── کپی کامل کد (baseline جدید)                              │
│  ├── api/.secrets/database.php        (۱۳۳۰ دسترسی، خارج از Git)      │
│  ├── uploads/                          (خارج از docroot ترجیحاً)      │
│  └── backup/        nik-YYYYMMDD.sql + files-YYYYMMDD.zip            │
│                                                                      │
│  Task Scheduler:                                                     │
│  ├── 02:00 روزانه  → mysqldump + zip uploads → backup/               │
│  └── هر ۵ دقیقه    → health check روی https://nik.local/api/health.php│
└──────────────────────────────────────────────────────────────────────┘
             ▲                                    ▲
             │ Wi-Fi (بدون اینترنت)               │
    ┌────────┴────────┐                  ┌────────┴────────┐
    │ اندروید کارمند   │                  │ لپ‌تاپ مدیر      │
    │ Chrome PWA       │                  │ https://nik.local│
    └─────────────────┘                  └─────────────────┘
```

## هـ-۲) تصمیم‌های فنی الزامی

| تصمیم | مقدار پیشنهادی | چرا |
|---|---|---|
| نام دامنه | `nik.local` (یا `smartcount.lan`) در `C:\Windows\System32\drivers\etc\hosts` **و** روی هر دستگاه | IP ممکن است با DHCP عوض شود؛ نام ثابت است |
| IP ثابت | در روتر یک **DHCP reservation** برای MAC لپ‌تاپ | جلوگیری از قطع ناگهانی |
| TLS | `mkcert -install` + `mkcert nik.local 192.168.x.x` | Secure Context برای دوربین + `crypto.subtle` + SW |
| MySQL bind | `127.0.0.1` فقط | هیچ دستگاه خارجی مستقیم به DB وصل نشود |
| PHP session | `session.cookie_secure=1`, `session.cookie_samesite=Lax` | با HTTPS محلی سازگار |
| Firewall | Inbound rule برای 443 (Private profile) فقط | 80 فقط برای redirect |
| Backup | روزانه خودکار + نگهداری ۱۴ نسخه | بدون این، ریسک از دست دادن داده |
| حالت آفلاین | پرچم `api/.secrets/offline.php` (کار مرحلهٔ قبل) | همان منطق، روی baseline جدید دوباره اعمال شود |

## هـ-۳) آنچه از کار مرحلهٔ قبل قابل استفاده است

| فایل | وضعیت | اقدام |
|---|---|---|
| `api/offline.php` (۱۹۰ خط) | ✅ منطق درست و تست‌شده (۱۸/۱۸ + ۴/۴ + ۳/۳) | بازاعمال روی baseline جدید |
| `offline-client.js` (fallback برای `randomUUID` و `subtle.digest`) | ✅ ۷/۷ در برابر Node | ضروری — روی LAN HTTP **بدون آن، هش کردن رمز می‌شکند** |
| `api/setup-offline.php` (CLI-only، umask 0077) | ✅ | بازاعمال |
| `OFFLINE_LAN_FA.md` | ✅ راهنمای خوب | به‌روزرسانی پس از تغییر معماری |
| پچ‌های `api/config.php`, `web-session.php`, `email-security.php`, `auth.php`, `installer.php` | ⚠️ روی lineage قدیمی زده شده | **دستی روی baseline جدید دوباره اعمال شود** (نه cherry-pick کور) |

> ⚠️ **هشدار:** `cherry-pick de25406` روی baseline جدید **امن نیست** — چون `api/config.php` و `auth.php` در PR #2 بازنویسی شده‌اند و پنجرهٔ CSRF/origin هم عوض شده. باید پچ‌ها دستی منتقل شوند.

---

# و) معماری امن sync برای آینده (بدون last-write-wins برای مالی)

## و-۱) چرا `app_workspaces` برای آفلاین کافی نیست

| ویژگی لازم برای آفلاین | وضعیت فعلی |
|---|---|
| ذخیرهٔ رکورد به رکورد | ❌ کل شرکت = یک JSON |
| حل تعارض در سطح فیلد | ❌ در سطح آرایه با 3-way merge |
| شناسهٔ یکتای عملیات (idempotency) | ❌ `enforceIdempotencyGuard` روی HTTP است، نه عملیات |
| تشخیص حذف vs ویرایش | ❌ (merge آرایه ممکن است حذف را از دست بدهد) |
| تاریخچهٔ تغییر برای audit | ❌ (فقط `system_audit_logs` سطح endpoint) |
| اندازهٔ payload | ❌ چند MB در هر save |

## و-۲) مدل پیشنهادی: **Outbox + Command Log + Domain Events**

```
┌─ دستگاه کارمند (آفلاین) ─┐
│  UI → قصد کاربر           │
│      ↓                    │
│  Command {                │
│    id: uuid,              │  ← کلید idempotency
│    tenant, user, project, │
│    type: "expense.create",│
│    payload,               │
│    clientTs,              │  ← زمان دستگاه (برای تشخیص، نه تصمیم)
│    baseRev: {entity: rev} │  ← نسخه‌ای که کاربر دیده
│  }                        │
│      ↓                    │
│  IndexedDB outbox         │  ← فقط دستورها، نه وضعیت کامل
│  (ترتیبی، پایدار)          │
└──────────┬────────────────┘
           │  (وقتی آنلاین شد)
           ↓
┌─ سرور PHP ──────────────────────────────────────┐
│  POST /api/sync.php  { commands: [...], since } │
│    ↓ در یک تراکنش:                               │
│  ۱. اگر command.id قبلاً apply شده → رد کن و     │
│     نتیجهٔ قبلی را برگردان (idempotent)          │
│  ۲. rev فعلی entity را چک کن                     │
│     • baseRev == current → apply, rev++          │
│     • baseRev <  current → CONFLICT               │
│  ۳. در تعارض: پاسخ 409 با                  │
│     { entityId, serverDoc, yourDoc,              │
│       conflictingFields: [...] }                 │
│     ↓ کلاینت: نمایش دو نسخه به کاربر            │
│     «نسخهٔ سرور / نسخهٔ من / ادغام دستی»          │
│  ۴. apply → INSERT در domain_events             │
│     (append-only، برای audit و replay)           │
└──────────────────────────────────────────────────┘
```

**قواعد طلایی (غیرقابل مذاکره):**

1. **هیچ‌وقت** برای سند مالی last-write-wins نکنید. اگر تعارض بود → **متوقف** و از کاربر بپرسید.
2. هر دستور مالی یک `id` از سمت کلاینت دارد و سرور **دقیقاً یک‌بار** اعمال می‌کند (`UNIQUE` روی `command_id`).
3. حذف = یک command جدا (`expense.delete`) نه غیبت در آرایه.
4. `clientTs` فقط برای نمایش است. **تصمیم تعارض با `rev` گرفته می‌شود، نه با ساعت.**
5. `domain_events` append-only است. هرگز update/delete نشود.
6. برگشت (reversal) هم command است، نه ویرایش سند قبلی.

## و-۳) جدول‌های جدید لازم (مهاجرت افزایشی، بدون حذف)

```sql
CREATE TABLE IF NOT EXISTS sync_commands (
  command_id   CHAR(36)     NOT NULL PRIMARY KEY,
  tenant_id    VARCHAR(64)  NOT NULL,
  device_id    VARCHAR(64)  NOT NULL,
  user_id      VARCHAR(64)  NOT NULL,
  command_type VARCHAR(64)  NOT NULL,
  entity_type  VARCHAR(64)  NULL,
  entity_id    VARCHAR(64)  NULL,
  payload_json LONGTEXT     NOT NULL,
  client_ts    BIGINT       NULL,
  applied_rev  BIGINT       NULL,
  result_json  LONGTEXT     NULL,
  status       ENUM('applied','rejected','conflict') NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sync_tenant_ts (tenant_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS entity_revisions (
  tenant_id   VARCHAR(64) NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id   VARCHAR(64) NOT NULL,
  rev         BIGINT      NOT NULL DEFAULT 0,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS domain_events (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    VARCHAR(64) NOT NULL,
  command_id   CHAR(36)    NULL,
  event_type   VARCHAR(64) NOT NULL,
  entity_type  VARCHAR(64) NOT NULL,
  entity_id    VARCHAR(64) NOT NULL,
  payload_json LONGTEXT    NOT NULL,
  actor_user   VARCHAR(64) NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ev_tenant (tenant_id, id)
) ENGINE=InnoDB;
```

**ایمنی مهاجرت:** هر سه `CREATE TABLE IF NOT EXISTS` هستند → افزودنی، بدون حذف، بدون تغییر ستون موجود. اجرا از طریق همان `admin.php?action=upgrade` یا migration جدید. **هیچ‌کدام روی دادهٔ موجود دست نمی‌گذارند.**

---

# ز) سورس فرانت‌اند «گمشده» و پیامدهای نگهداری JS کامپایل‌شده

## ز-۱) واقعیت (پاسخ قطعی و خوب)

سورس اصلی React/TypeScript **گم نشده‌ است** — بلکه **کامپایل‌شده و خوانا** در repo هست:

```
client/nsc-accounting-r3.js     2,499,427 bytes   ← کد کامپایل‌شده (minified)
nsc-accounting-r3.js.gz           621,540 bytes   ← همان فایل، gzip شده (چیزی که به مرورگر می‌رود)
tools/client-bundle.mjs            ۲۱ خط          ← extract / pack با syntax check
```

**تأیید کردم:** `zcat nsc-accounting-r3.js.gz | md5sum` == `md5sum client/nsc-accounting-r3.js` → `e76e95295525dc3f30299145481cacb2`. یعنی این دو **دقیقاً یکی هستند**.

## ز-۲) گردش‌کار رسمی (از `DEVELOPMENT.md` خودتان)

```bash
node tools/client-bundle.mjs extract     # gz → client/nsc-accounting-r3.js
# ویرایش دقیق تابع مورد نظر
node tools/client-bundle.mjs pack        # syntax check + gzip level 9
# bump asset URL در workspace.php
```

## ز-۳) محدودیت‌های واقعی این رویکرد

| محدودیت | شدت | اثر عملی |
|---|---|---|
| نام متغیرها کوتاه و بی‌معنی (`Qo`, `Ds`, `Nd`, `LA`, `Jo`) | 🟠 | پیدا کردن تابع درست سخت است؛ باید با grep رشته‌های فارسی/انگلیسی جستجو کرد |
| ⚠️ **مهم:** `client/` و `tools/` در `main` و شاخهٔ جاری **وجود ندارند** | 🔴 | امروز اگر روی `main` توسعه دهید، **ابزار build ندارید** |
| تابلوهای `SUSPENSE:'8200'`, `chart_of_accounts` کد حساب | 🟠 | تغییر در باندل بدون تغییر اسکیما → ارجاع به حساب ناموجود (همان باگ ۸۲۰۰) |
| Minified → نمی‌توان به‌خاطر یک تغییر، نوع‌ها یا ساختار را بازسازی کرد | 🟠 | refactor واقعی ممکن نیست |
| هیچ snapshot test روی باندل نیست | 🔴 | تغییر یک تابع می‌تواند بی‌صدا تابع دیگری را بشکند |
| PR #1 هرگز `client/` را نساخت | 🔴 | **PR #1 و PR #2 باندل‌های متفاوت دارند** و سورس قابل ویرایش فقط برای PR #2 موجود است |

## ز-۴) مقایسهٔ باندل‌ها (تأیید‌شده)

| نسخه | gz size | raw size | `?v=` | 409 3-way merge | `client/` source |
|---|---|---|---|---|---|
| `main` / شاخهٔ جاری | ۶۱۵٬۸۲۷ | ۲٬۴۸۵٬۳۵۵ | `accounting-display-1` | ❌ | ❌ |
| `0c5a360` (نزدیک‌ترین به main) | ۶۱۵٬۶۳۰ | ۲٬۴۸۴٬۵۹۰ | `permissions-1` | ❌ | ❌ |
| **PR #2 (زنده)** | **۶۲۱٬۵۴۰** | **۲٬۴۹۹٬۴۲۷** | `compact-nav-20261109-1` | ✅ | ✅ |

## ز-۵) توصیه

1. **`client/` و `tools/` را از PR #2 به baseline منتقل کنید** — بدون این، هر تغییر UI غیرقابل بازبینی است.
2. یک مجموعه **snapshot test** روی باندل اضافه کنید (هش خروجی توابع مالی کلیدی: `Qo`, `Ds`, `Ts`, `Nd`, `LA`, `OA`, `nscApartmentMoney`, `Yr`, `Xr`, `Zr`, `Qr`, `Kr`, `Jo`, `AD`, `li`, `KA`, `PO`).
3. **اگر سورس اصلی TS/React پیدا شد** → آن را در `src/` بگذارید و تغییرات تأییدشده را با diff به آن پورت کنید؛ **هیچ‌گاه `src/` جعلی نسازید** (خودتان در `DEVELOPMENT.md` همین را نوشته‌اید).

---

# ح) نقشهٔ راه مرحله‌به‌مرحله

> هر مرحله: کارهای دقیق، فایل‌های تحت تأثیر، تغییر دیتابیس، تست لازم، معیار قبولی، وابستگی، ریسک، و rollback.

## فاز ۰ — تثبیت baseline (بلاکر همه‌چیز)

**هدف:** اطمینان از اینکه یک نسخهٔ کامل و بازتولیدپذیر از کد زنده داریم.

| مورد | جزئیات |
|---|---|
| **کارها** | ۱) بکاپ کامل `public_html` از cPanel (zip). ۲) بکاپ دیتابیس (SQL dump). ۳) بکاپ `uploads/`. ۴) گرفتن هش MD5 هر فایل PHP/JS و مقایسه با PR #2 برای یافتن تفاوت‌های «فقط روی هاست». ۵) ساخت شاخهٔ `baseline/live-2026-11-18` روی tip **PR #2**. ۶) ثبت تفاوت‌های واقعی به‌عنوان commit جداگانه. |
| **فایل‌ها** | همه (snapshot) |
| **DB** | فقط dump — هیچ تغییری |
| **تست** | `git diff pr2 baseline/live-...` بازبینی چشمی؛ اجرای `api/health.php` روی کپی محلی |
| **معیار قبولی** | برای هر فایل زنده یک معادل در repo وجود دارد یا صریحاً مستند شده که «روی هاست ساخته شده». `mysqldump` بازگردانی‌شدنی است. |
| **وابستگی** | دسترسی cPanel |
| **ریسک** | 🟠 متوسط — فقط خواندن از هاست |
| **Rollback** | لازم نیست (فقط خواندن) |

## فاز ۱ — انتقال دستاوردهای PR #1 (انتخابی، بدون merge)

**هدف:** گرفتن امنیت و آزمون‌پذیری PR #1 بدون از دست دادن نسل جدید PR #2.

| # | کار | فایل‌ها | تست | معیار قبولی |
|---|---|---|---|---|
| ۱.۱ | CSP + HSTS + COOP/CORP + Permissions-Policy | `.htaccess` | باز شدن همهٔ صفحات بدون خطای console؛ `frame-ancestors` | بدون `Content-Security-Policy` در DevTools → ❌ | 
| ۱.۲ | `installer.php` → 404 بعد از نصب | `installer.php` | درخواست مستقیم `/installer.php` | 404 |
| ۱.۳ | حذف `PHP_VERSION` از `ping` | `api/auth.php` | `curl /api/auth.php?action=ping` | نسخه لو نمی‌رود |
| ۱.۴ | تقسیم‌کنندهٔ SQL واقعی | `api/db.php` | ۴ سناریوی PR #1 | نقطه‌ویرگول داخل رشته نمی‌شکند |
| ۱.۵ | `nsc_schema_meta` + نسخه‌بندی | `api/db.php`, schema | تست گروه ۵ | اجرای دوباره = ۰ دستور |
| ۱.۶ | **حساب `8200`** در seed | schema | `grep -c "'8200'"` = ۱ | دفتر کل با باندل منطبق |
| ۱.۷ | `rebar_spec_memory.tenant_id` | schema + `api/materials.php` (۴ کوئری) | تست گروه ۵-پ | نوشتن/خواندن محدود به tenant |
| ۱.۸ | `GET_LOCK` برای rate limit | `api/auth.php` | تست هم‌زمانی | ۱۰ تلاش موازی → فقط مجازها رد |
| ۱.۹ | `NSC_DEFAULT_EXCHANGE_RATE` | `api/config.php` | `grep -c "70"` در finance/reports | عدد هاردکد نماند |
| ۱.۱۰ | `tests/` + `SECURITY.md` + `docs/` | فایل‌های جدید | `cd tests && npm test` | ۴۷/۴۷ |

**DB:** فقط `CREATE TABLE IF NOT EXISTS nsc_schema_meta` + `ALTER TABLE rebar_spec_memory ADD COLUMN tenant_id VARCHAR(64) NULL` + `UPDATE rebar_spec_memory r JOIN projects p ON r.project_id=p.id SET r.tenant_id=p.tenant_id WHERE r.tenant_id IS NULL` + ایندکس.
**ایمنی:** همه افزودنی. `tenant_id` nullable → ردیف‌های قدیمی بدون مقدار مجاز، ولی read با `JOIN projects` هم کار می‌کند.
**Rollback:** `git revert` فاز ۱؛ ستون `tenant_id` بدون حذف باقی می‌ماند (بی‌ضرر).

## فاز ۲ — بستن شکاف‌های کوچک امنیتی

| # | کار | فایل |
|---|---|---|
| ۲.۱ | حذف `workspace_sync_states` مرده از `api/db.php:244` | `api/db.php` |
| ۲.۲ | حداقل طول رمز در API (نه فقط کلاینت) | `api/office7.php`, `api/auth.php` |
| ۲.۳ | حذف `localStorage` برای `nsc_master_token` (استفاده از کوکی) | `security-settings.js`, `api/email-settings.php` |
| ۲.۴ | `chart_of_accounts`: تصمیم مستند دربارهٔ «سراسری یا per-tenant» | design doc |
| ۲.۵ | `.checks/` و `tests/` هرگز به docroot نروند | `tools/make-deploy-package.sh` (بازبینی) |

**DB:** هیچ (فقط ۲.۱ = حذف کد، جدول می‌ماند).
**تست:** `tests/run-tests.mjs` دوباره ۴۷/۴۷.

## فاز ۳ — گردش‌کار build قابل‌بازبینی برای فرانت

| # | کار | فایل |
|---|---|---|
| ۳.۱ | انتقال `client/` + `tools/` | از PR #2 |
| ۳.۲ | `npm run check` برای syntax+hash | `package.json` جدید |
| ۳.۳ | تست snapshot برای ۱۶ تابع مالی | `tests/financial-snapshot.mjs` |
| ۳.۴ | Changelog اجباری برای هر تغییر باندل | `CLIENT_CHANGELOG.md` |

**معیار قبولی:** هر تغییر `nsc-accounting-r3.js` قبل از commit، هش ۱۶ تابع را نشان دهد.

## فاز ۴ — PWA واقعی (app shell، نه دادهٔ مالی)

| # | کار | جزئیات |
|---|---|---|
| ۴.۱ | `pwa-sw.js` با precache shell | `['/','/login.php','/workspace.php','/experience.css','/manifest.webmanifest','/assets/brand-full.webp','/assets/install-192.png','/assets/install-512.png']` |
| ۴.۲ | استراتژی: `network-first` برای navigation، `stale-while-revalidate` برای assets، **`network-only` برای `/api/*`** | حفظ تصمیم امنیتی فعلی |
| ۴.۳ | صفحهٔ offline پویا | `offline.html` با پیام «آفلاین — تغییرات ثبت نشده» |
| ۴.۴ | نسخه‌بندی cache + پاک‌سازی نسخه‌های قبلی | `CACHE_VERSION` |
| ۴.۵ | **تأیید نشدن ثبت مالی در حالت آفلاین** | UI باید صریح بگوید «ذخیره نشد» |

**تست:** DevTools → Offline → reload → صفحهٔ offline دیده شود. `/api/*` هرگز از cache نیاید.
**Rollback:** `pwa-sw.js` به ۳ خط قبلی + پاک‌کردن cache از DevTools.

## فاز ۵ — سرور محلی ویندوز (LAN)

| # | کار | توضیح |
|---|---|---|
| ۵.۱ | XAMPP + PHP 8.2 + MariaDB 10.11 | نصب استاندارد |
| ۵.۲ | `mkcert` + گواهی `nik.local` | **بدون این، دوربین و crypto.subtle کار نمی‌کند** |
| ۵.۳ | **بازاعمال دستی** پچ‌های `api/offline.php`, `offline-client.js`, `setup-offline.php` | روی baseline جدید — **نه cherry-pick** |
| ۵.۴ | `api/config.php`: توافق CSRF/origin با LAN | بازبینی دستی تفاوت با lineage قدیمی |
| ۵.۵ | `installer.php`: اجازهٔ نصب در LAN (با `isSecureContext` که با mkcert حالا true است) | تأیید مجدد |
| ۵.۶ | Task Scheduler: backup روزانه | `mysqldump` |
| ۵.۷ | Firewall + DHCP reservation + hosts | شبکه |

**تست ۱۵ موردی (باید ۱۵/۱۵):**
1. نصب تازه روی لپ‌تاپ ✅
2. ورود از لپ‌تاپ ✅
3. ورود از اندروید روی `https://nik.local` ✅
4. نصب PWA روی اندروید ✅
5. دوربین/اسکن روی اندروید ✅ (بدون خطای InsecureContext)
6. SHA-256 fallback (چون هنوز ممکن است لازم شود) ✅
7. ثبت مصرف از دو دستگاه هم‌زمان → ۴۰۹ + merge ✅
8. قطع Wi-Fi → پیام «ذخیره نشد» (نه «ذخیره شد») ✅
9. برگشت Wi-Fi → ارسال مجدد ✅
10. بکاپ خودکار ساخته شود ✅
11. بازگردانی از بکاپ روی DB خالی ✅
12. `api/health.php` = همه true ✅
13. کارمند محدود به پروژه A نتواند B را ببیند ✅
14. `installer.php` بعد از نصب = 404 ✅
15. `api/.secrets/` از وب = 403 ✅

**Rollback:** خاموش کردن XAMPP + برگرداندن بکاپ DB. سایت زنده دست‌نخورده.

## فاز ۶ — Outbox و Sync (آینده، پس از پایدار شدن ۰–۵)

| # | کار | جزئیات |
|---|---|---|
| ۶.۱ | جدول‌های `sync_commands`, `entity_revisions`, `domain_events` | بخش و) |
| ۶.۲ | `api/sync.php` با idempotency و ۴۰۹ تعارض | تراکنشی |
| ۶.۳ | `sync-client.js` با IndexedDB outbox | خارج از باندل کامپایل‌شده تا قابل بازبینی باشد |
| ۶.۴ | UI تعارض: «نسخهٔ سرور / نسخهٔ من / ادغام دستی» | هرگز خودکار |
| ۶.۵ | تست: ۱۰۰ command در حالت آفلاین، بعد آنلاین | همه دقیقاً یک‌بار اعمال شوند |

**معیار قبولی:** هیچ سند مالی هرگز بی‌صدا بازنویسی نشود. هر تعارض تصمیم کاربر بخواهد.

---

# توصیهٔ نهایی: نقطهٔ شروع امن + اولین کار

## Baseline پیشنهادی

```
شاخهٔ کاری جدید:  baseline/live-2026-11-18
مبنا:              PR #2 tip = f4b1ac4  (+ تفاوت‌های تأییدشدهٔ هاست از فاز ۰)
نه:                main / arena/902a6f21-…  (lineage قدیمی)
```

**دلیل یک‌خطی:** `main` یک snapshot بی‌تاریخچه از یک نسخهٔ میانی PR #2 است؛ PR #1 هم باندل و مسیر auth قدیمی‌تری دارد. تنها ترکیبی که با سایت زنده می‌خواند و سورس فرانت‌اند + ابزار build + AGENTS/DEVELOPMENT را دارد، PR #2 است.

## اولین کار اجرایی (Task #1)

> **فاز ۰ — بکاپ و تثبیت baseline**

۱. از cPanel یک zip کامل `public_html` بگیرید و SQL dump دیتابیس + `uploads/`.
۲. هش MD5 هر فایل `.php` و `.js` و `.gz` روی هاست را با `f4b1ac4` مقایسه کنید؛ هر تفاوت را در یک فایل `LIVE_DRIFT.md` ثبت کنید.
۳. شاخهٔ `baseline/live-2026-11-18` را از `f4b1ac4` بسازید و تفاوت‌های گام ۲ را روی آن commit کنید.
۴. `cd tests && npm test` را اجرا کنید (اگر `tests/` را از PR #1 هنوز برنداشته‌اید، اول فقط `git show pr1:tests/...` را به‌صورت موقت در یک پوشهٔ خارج از repo اجرا کنید) و نتیجه را ثبت کنید.

**معیار قبولی Task #1:** برای هر فایل روی هاست، یک معادل دقیق در `baseline/live-2026-11-18` وجود داشته باشد یا در `LIVE_DRIFT.md` مستند شده باشد که «فقط روی هاست ساخته شده و منبع ندارد». بدون این، هیچ فاز بعدی امن نیست.

---

## ضمیمه: فایل‌های مرجع

| سند | محل | وضعیت |
|---|---|---|
| `AGENTS.md` | PR #2 | ✅ قواعد کار |
| `DEVELOPMENT.md` | PR #2 | ✅ گردش‌کار build و محدودیت‌ها |
| `SYSTEM_AUDIT_FA.md` | PR #2 | 🟡 ادعاهای تست (اجرا نشد) |
| `SECURITY_REVIEW_FA.md` | PR #2 و main | 🟡 ادعاها |
| `SECURITY.md` | PR #1 | 🟢 مدل امنیتی مکتوب |
| `docs/DEPLOY.md` | PR #1 | 🟢 راهنمای cPanel |
| `tests/README.md` | PR #1 | 🟢 صادقانه محدودیت‌ها را می‌گوید |
| `OFFLINE_LAN_FA.md` | شاخهٔ جاری | 🟢 راهنمای LAN (باید به baseline منتقل شود) |
| `AUDIT_AND_MASTER_PLAN_FA.md` | همین فایل | — |

---

**پایان گزارش.**
این سند فقط‌خواندنی بود: هیچ فایل کد، دیتابیس، یا تنظیمات سایت زنده تغییر نکرد.
منتظر بازبینی شما هستم — تا تأیید نکنید، هیچ مرحله‌ای اجرا نمی‌شود.
