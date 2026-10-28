<?php
/**
 * نقطهٔ ورود برنامه — مسیر‌یابی بر اساس وضعیت واقعی نصب.
 *
 * پیش از این فقط وجود DB_NAME بررسی می‌شد، بنابراین دو حالت بن‌بست داشت:
 *   • دیتابیس تنظیم شده ولی مدیر اصلی ساخته نشده → هدایت به workspace.php که با 401 می‌افتد.
 *   • مدیر ارشد → هدایت به workspace.php که API آن برای مدیر ارشد 403 می‌دهد.
 */
require_once __DIR__ . '/api/config.php';
header('Cache-Control: no-store');

$sealed = is_file(__DIR__ . '/api/.secrets/installed.lock');
$configured = DB_NAME !== '' && DB_USER !== '';

$hasMaster = false;
if ($configured) {
    try {
        require_once __DIR__ . '/api/connection.php';
        $pdo = nscConnect(['HOST' => DB_HOST, 'PORT' => DB_PORT, 'NAME' => DB_NAME, 'USER' => DB_USER, 'PASS' => DB_PASS]);
        $hasMaster = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_master = 1')->fetchColumn() > 0;
    } catch (Throwable $e) {
        // اتصال برقرار نشد: صفحهٔ نصب پیام تشخیصی می‌دهد.
        $hasMaster = false;
        $configured = false;
    }
}

$target = '/login.php';
if (!$configured || (!$sealed && !$hasMaster)) {
    $target = '/installer.php';
} elseif (!$sealed) {
    // دیتابیس آماده است ولی فایل قفل نصب نوشته نشده (مثلاً restored از بکاپ).
    $target = '/login.php';
}

header('Location: ' . $target, true, 302);
exit;
