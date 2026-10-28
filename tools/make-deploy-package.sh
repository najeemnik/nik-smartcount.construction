#!/usr/bin/env bash
# =====================================================================
# ساخت بستهٔ استقرار NiK Smart Count برای هاست اشتراکی / فری‌هاست
# =====================================================================
# فقط فایل‌هایی را برمی‌دارد که در زمان اجرا لازم‌اند:
#   - بدون tests/ (وابستگی npm)
#   - بدون review/ (گزارش‌های محلی)
#   - بدون tools/ و docs/
#   - بدون api/.secrets/database.php (رمز دیتابیس هرگز بسته‌بندی نمی‌شود)
# خروجی: deploy-out/nik-smartcount-<تاریخ>.zip
# =====================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M)"
OUT_DIR="$ROOT/deploy-out"
STAGE="$OUT_DIR/stage"
ZIP="$OUT_DIR/nik-smartcount-$STAMP.zip"

rm -rf "$STAGE"
mkdir -p "$STAGE"

cd "$ROOT"

# --- فایل‌های ریشه ---
for f in index.php installer.php login.php login.html office7.php office7-login.php \
         office7-login.html team.php workspace.php nsc-runtime.php \
         .htaccess robots.txt manifest.webmanifest pwa-sw.js nsc-accounting-r3.js.gz; do
    [ -f "$f" ] && cp -p "$f" "$STAGE/"
done

# --- api/ ---
mkdir -p "$STAGE/api"
find api -maxdepth 1 -name '*.php' -exec cp -p {} "$STAGE/api/" \;
[ -f api/.htaccess ] && cp -p api/.htaccess "$STAGE/api/"

# .secrets: فقط اسکیما و نمونهٔ پیکربندی؛ هرگز database.php واقعی
mkdir -p "$STAGE/api/.secrets"
cp -p api/.secrets/schema.sql "$STAGE/api/.secrets/"
[ -f api/.secrets/database.example.php ] && cp -p api/.secrets/database.example.php "$STAGE/api/.secrets/"
chmod 755 "$STAGE/api/.secrets"

# --- دارایی‌های ثابت ---
[ -d assets ] && cp -rp assets "$STAGE/"
mkdir -p "$STAGE/uploads"
[ -f uploads/.htaccess ] && cp -p uploads/.htaccess "$STAGE/uploads/"

# --- ایمنی: هیچ رمز/کلیدی داخل بسته نباشد ---
LEAK="$(cd "$STAGE" && find . -name 'database.php' -o -name '*.key' -o -name '*.lock' -o -name '.env' | head)"
if [ -n "$LEAK" ]; then
    echo "✗ فایل حساس داخل بسته پیدا شد: $LEAK" >&2
    exit 1
fi

# --- zip ---
command -v zip >/dev/null || { echo "zip نصب نیست" >&2; exit 1; }
# نکته: .htaccess نباید حذف شود — الگوی «همهٔ فایل‌های مخفی» آن را هم می‌انداخت
(cd "$STAGE" && zip -qr "$ZIP" . -x '*/.git/*' -x '.git/*' -x '*.DS_Store')

SIZE="$(du -h "$ZIP" | cut -f1)"
COUNT="$(cd "$STAGE" && find . -type f | wc -l)"
rm -rf "$STAGE"

echo "✓ بسته ساخته شد: ${ZIP#$ROOT/}"
echo "  حجم: $SIZE · تعداد فایل: $COUNT"
echo "  مرحلهٔ بعد: محتویات را در public_html هاست آپلود (یا Extract) کن،"
echo "  بعد /installer.php را باز کن."
