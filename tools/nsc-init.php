<?php
/**
 * NSC Init — راه‌اندازی و بازبینی از خط فرمان (فقط CLI)
 *
 * چرا: نصب به فایل api/.secrets/install.key نیاز دارد و قبلاً هیچ راهی برای ساختن آن
 * وجود نداشت جز ساخت دستی فایل. این اسکریپت فقط از خط فرمان اجرا می‌شود، بنابراین
 * هرگز از طریق وب قابل سوءاستفاده نیست.
 *
 * استفاده:
 *   php tools/nsc-init.php            # بازبینی محیط و ساخت کلید نصب در صورت نبود
 *   php tools/nsc-init.php --check    # فقط بازبینی، بدون نوشتن چیزی
 *   php tools/nsc-init.php --rotate   # ساخت کلید نصب تازه (نصب‌های قبلی را نامعتبر می‌کند)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

$root = dirname(__DIR__);
require_once $root . '/api/config.php';

$secrets = $root . '/api/.secrets';
$keyFile = $secrets . '/install.key';
$checkOnly = in_array('--check', $argv, true);
$rotate = in_array('--rotate', $argv, true);

$ok = true;
$line = static function (string $status, string $label) use (&$ok): void {
    if ($status !== 'OK') $ok = $ok && $status === 'INFO';
    printf("  [%-4s] %s\n", $status, $label);
};

echo "NiK Smart Count — بازبینی محیط\n";
echo str_repeat('-', 58) . "\n";

echo "PHP\n";
$line(version_compare(PHP_VERSION, '7.4.0', '>=') ? 'OK' : 'FAIL', 'نسخه: ' . PHP_VERSION . ' (حداقل 7.4)');
foreach (['pdo_mysql', 'mbstring', 'json', 'openssl', 'zlib'] as $ext) {
    $line(extension_loaded($ext) ? 'OK' : 'FAIL', 'افزونه: ' . $ext);
}
$line(function_exists('gzdecode') ? 'OK' : 'FAIL', 'پشتیبانی gzip برای باندل کلاینت');

echo "\nفایل‌ها و دسترسی نوشتن\n";
$line(is_dir($secrets) ? 'OK' : 'FAIL', 'پوشه: api/.secrets');
$line(is_writable($secrets) ? 'OK' : 'FAIL', 'اجازهٔ نوشتن در api/.secrets (لازم برای کلید و قفل نصب)');
$line(is_file($secrets . '/schema.sql') ? 'OK' : 'FAIL', 'فایل ساختار: api/.secrets/schema.sql');
$line(is_dir($root . '/uploads') ? 'OK' : 'FAIL', 'پوشهٔ آپلود: uploads/');
$line(is_writable($root . '/uploads') ? 'OK' : 'FAIL', 'اجازهٔ نوشتن در uploads/');
$line(is_file($secrets . '/installed.lock') ? 'INFO' : 'OK',
    is_file($secrets . '/installed.lock') ? 'نصب قبلاً قفل شده است (طبیعی برای نصب فعال)' : 'نصب قفل نشده؛ آمادهٔ نصب اولیه');

echo "\nپیکربندی دیتابیس\n";
$configured = DB_NAME !== '' && DB_USER !== '';
$line($configured ? 'OK' : 'INFO', $configured
    ? 'اتصال از api/.secrets/database.php خوانده شد (نام دیتابیس تنظیم است)'
    : 'اتصال هنوز تنظیم نشده؛ از /installer.php استفاده کنید');

if ($configured) {
    try {
        require_once $root . '/api/connection.php';
        $cfg = ['HOST' => DB_HOST, 'PORT' => DB_PORT, 'NAME' => DB_NAME, 'USER' => DB_USER, 'PASS' => DB_PASS];
        $pdo = nscConnect($cfg);
        $line('OK', 'اتصال به MySQL برقرار شد');
        $version = nscSchemaVersionOf($pdo);
        $line($version === NSC_SCHEMA_VERSION ? 'OK' : 'WARN',
            'نسخهٔ ساختار: ' . ($version !== '' ? $version : 'ثبت نشده') . ' (انتظار: ' . NSC_SCHEMA_VERSION . ')');
        if ($version !== NSC_SCHEMA_VERSION) {
            echo "         ارتقا در اولین درخواست API خودکار اجرا می‌شود (api/db.php → nscSchemaEnsure).\n";
        }
    } catch (Throwable $e) {
        $diagnostic = nscConnectionError($e);
        $line('FAIL', 'اتصال به MySQL: [' . $diagnostic['code'] . '] ' . $diagnostic['message']);
    }
}

echo "\nکلید نصب\n";
if ($checkOnly) {
    $line(is_file($keyFile) ? 'OK' : 'WARN', is_file($keyFile) ? 'کلید نصب موجود است' : 'کلید نصب وجود ندارد (با اجرای بدون --check ساخته می‌شود)');
} elseif (is_file($keyFile) && !$rotate) {
    $line('OK', 'کلید نصب از قبل موجود است و تغییر داده نشد');
} else {
    if (!is_dir($secrets) || !is_writable($secrets)) {
        $line('FAIL', 'نوشتن کلید نصب ممکن نیست: api/.secrets قابل نوشتن نیست');
    } else {
        try {
            $key = bin2hex(random_bytes(24));
            $tmp = $keyFile . '.tmp';
            if (file_put_contents($tmp, $key . "\n", LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $keyFile)) {
                throw new RuntimeException('نوشتن فایل کلید ناموفق بود');
            }
            $line('OK', 'کلید نصب ساخته شد (۰۶۰۰). همین لحظه آن را در فورم نصب وارد کنید:');
            echo "\n      " . $key . "\n\n";
            echo "      پس از تکمیل نصب، این کلید بی‌اثر می‌شود (installed.lock).\n";
        } catch (Throwable $e) {
            $line('FAIL', 'ساخت کلید نصب: ' . $e->getMessage());
        }
    }
}

echo str_repeat('-', 58) . "\n";
echo $ok ? "نتیجه: محیط برای اجرا آماده است.\n" : "نتیجه: موارد FAIL را پیش از نصب برطرف کنید.\n";
exit($ok ? 0 : 1);
