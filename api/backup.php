<?php
/**
 * NiK Smart Count — API پشتیبان‌گیری و بازیابی
 *
 *   GET  ?action=info        → آمار سبک برای نمایش در صفحه (بدون ساخت فایل)
 *   GET  ?action=download    → فایل .nscbackup را می‌سازد و می‌فرستد
 *   POST ?action=inspect     → فایل آپلودشده را بررسی می‌کند، چیزی نمی‌نویسد
 *   POST ?action=restore     → همان فایل را بازیابی می‌کند (امضا اجباری)
 *
 * فقط مالک شرکت (admin غیر-master) دسترسی دارد.
 * master (اپراتور پلتفرم) عمداً محروم است: دادهٔ شرکت مال صاحبش است.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backup-lib.php';
require_once __DIR__ . '/permissions.php';
sendSecurityHeaders();

$user = authenticateUser();
if (!empty($user['isMasterSuperAdmin']) || ($user['role'] ?? '') === 'master') {
    jsonResponse(false, null, 'پشتیبان‌گیری فقط با حساب مالک همان شرکت ممکن است.', 403);
}

$tenantId = currentTenantId($user);
if ($tenantId === '' || $tenantId === null) {
    jsonResponse(false, null, 'شرکت فعال مشخص نیست؛ دوباره وارد شوید.', 409);
}

// فقط مالک شرکت — کارمندان اجازه ندارند
// currentTenantId برای کارمند، شناسهٔ مالک را برمی‌گرداند؛ پس تساوی زیر
// یعنی «این کاربر خودش مالک شرکت است».
if ($tenantId !== (string) $user['id']) {
    jsonResponse(false, null, 'این بخش فقط برای مالک شرکت است؛ با حساب مالک وارد شوید.', 403);
}
$db = Database::getConnection();

$action = $_GET['action'] ?? '';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ─────────────────────────────────────────────────────────────
// GET ?action=info — خلاصهٔ سبک برای کارت صفحه
// ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'info') {
    $counts = [];
    foreach (nscBackupTables() as $t) {
        if (!nscTableExists($db, $t)) {
            continue;
        }
        try {
            if ($t === 'users') {
                $s = $db->prepare('SELECT COUNT(*) FROM users WHERE id = :tid OR owner_admin_id = :tid');
            } elseif ($t === 'office7_tenant_configs') {
                $s = $db->prepare('SELECT COUNT(*) FROM office7_tenant_configs WHERE tenant_user_id = :tid');
            } elseif (in_array('tenant_id', nscTableColumns($db, $t), true)) {
                $s = $db->prepare("SELECT COUNT(*) FROM `{$t}` WHERE tenant_id = :tid");
            } elseif ($t === 'journal_lines') {
                $s = $db->prepare('SELECT COUNT(*) FROM journal_lines jl INNER JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.tenant_id = :tid');
            } else {
                continue;
            }
            $s->execute(['tid' => $tenantId]);
            $n = (int) $s->fetchColumn();
            if ($n > 0) {
                $counts[$t] = $n;
            }
        } catch (Throwable $e) {
            // جدول با شکل دیگر — نمایش داده نمی‌شود
        }
    }

    // حجم تقریبی آپلودهای همین شرکت
    $uploadsBytes = 0;
    $uploadsCount = 0;
    if (nscTableExists($db, 'project_files')) {
        $cols = nscTableColumns($db, 'project_files');
        $sizeCol = array_values(array_intersect(['file_size', 'size', 'bytes'], $cols))[0] ?? null;
        if ($sizeCol) {
            try {
                $s = $db->prepare("SELECT COALESCE(SUM(`{$sizeCol}`),0), COUNT(*) FROM project_files WHERE tenant_id = :tid");
                $s->execute(['tid' => $tenantId]);
                [$uploadsBytes, $uploadsCount] = $s->fetch(PDO::FETCH_NUM);
            } catch (Throwable $e) {
            }
        }
    }

    jsonResponse(true, [
        'tenantId' => $tenantId,
        'companyName' => $user['companyName'] ?? $user['name'] ?? '',
        'counts' => $counts,
        'uploads' => ['files' => (int) $uploadsCount, 'bytes' => (int) $uploadsBytes],
        'appVersion' => nscBackupAppVersion(),
        'zipAvailable' => class_exists('ZipArchive'),
        'phpVersion' => PHP_VERSION,
        'maxUploadMb' => (int) floor(nscUploadLimitBytes() / 1048576),
    ]);
}

// ─────────────────────────────────────────────────────────────
// GET ?action=download — ساخت و ارسال فایل
// ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'download') {
    if (!class_exists('ZipArchive')) {
        jsonResponse(false, null, 'افزونهٔ ZIP روی سرور فعال نیست. با میزبان تماس بگیرید.', 500);
    }
    try {
        $info = nscCreateBackup($db, $tenantId);
    } catch (Throwable $e) {
        error_log('NSC backup failed: ' . $e->getMessage());
        jsonResponse(false, null, 'ساخت پشتیبان کامل نشد. گزارش خطای سرور را بررسی کنید.', 500);
    }

    logAudit($db, $user['id'], $user['name'], 'backup', 'data', null,
        sprintf('Backup created: %s (%d tables, %d files, %d bytes)',
            $info['name'], count($info['tables']), $info['files'], $info['size']));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $info['name'] . '"');
    header('Content-Length: ' . $info['size']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    readfile($info['path']);
    @unlink($info['path']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// POST — آپلود فایل
// ─────────────────────────────────────────────────────────────
if ($method !== 'POST') {
    jsonResponse(false, null, 'Method not allowed', 405);
}
if ($action !== 'inspect' && $action !== 'restore') {
    jsonResponse(false, null, 'عملیات ناشناخته است.', 400);
}

$file = $_FILES['backup'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $map = [
        UPLOAD_ERR_INI_SIZE => 'حجم فایل از سقف php.ini بیشتر است.',
        UPLOAD_ERR_FORM_SIZE => 'فایل خیلی بزرگ است.',
        UPLOAD_ERR_PARTIAL => 'فایل کامل آپلود نشد؛ دوباره تلاش کنید.',
        UPLOAD_ERR_NO_FILE => 'فایلی انتخاب نشد.',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشهٔ موقت روی سرور وجود ندارد.',
        UPLOAD_ERR_CANT_WRITE => 'نوشتن روی دیسک ممکن نشد.',
        UPLOAD_ERR_EXTENSION => 'آپلود توسط افزونهٔ سرور متوقف شد.',
    ];
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    jsonResponse(false, null, $map[$code] ?? ('خطای آپلود: ' . $code), 400);
}
$limit = nscUploadLimitBytes();
if ((int) $file['size'] > $limit) {
    jsonResponse(false, null, 'فایل از سقف مجاز (' . floor($limit / 1048576) . ' مگابایت) بزرگ‌تر است.', 413);
}
if (!is_uploaded_file($file['tmp_name'])) {
    jsonResponse(false, null, 'آپلود معتبر نیست.', 400);
}

$inspect = nscInspectBackup($file['tmp_name']);
if (!$inspect['ok']) {
    jsonResponse(false, ['code' => 'BAD_BACKUP'], $inspect['error'], 422);
}

$manifest = $inspect['manifest'];
// مالک فایل باید همان شرکت باشد — جلوگیری از ریختن دادهٔ شرکت دیگر
if (($manifest['tenant_id'] ?? '') !== $tenantId) {
    jsonResponse(false, [
        'code' => 'TENANT_MISMATCH',
        'fileTenant' => (string) ($manifest['tenant_id'] ?? ''),
    ], 'این پشتیبان مال شرکت دیگری است و در این حساب بازگردانده نمی‌شود.', 409);
}

$summary = [
    'fileName' => (string) ($file['name'] ?? 'backup.nscbackup'),
    'createdAt' => (string) ($manifest['created_at'] ?? ''),
    'appVersion' => (string) ($manifest['app_version'] ?? ''),
    'tables' => $inspect['tables'],
    'uploadCount' => (int) ($manifest['upload_count'] ?? 0),
    'totalRows' => array_sum(array_filter($inspect['tables'], fn($n) => $n > 0)),
];

// ─────────────────────────────────────────────────────────────
// POST ?action=inspect — فقط گزارش، بدون نوشتن
// ─────────────────────────────────────────────────────────────
if ($action === 'inspect') {
    jsonResponse(true, $summary);
}

// ─────────────────────────────────────────────────────────────
// POST ?action=restore — بازیابی واقعی
// ─────────────────────────────────────────────────────────────
// تأییدیه از فیلد payload می‌آید چون این درخواست multipart است (فایل + متن).
$raw = (string) ($_POST['payload'] ?? '');
$input = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if (!$input) {
    $input = getJsonInput();
}
$confirm = strtoupper(trim((string) ($input['confirm'] ?? '')));
if ($confirm !== 'RESTORE') {
    jsonResponse(false, ['code' => 'CONFIRM_REQUIRED'],
        'برای جلوگیری از بازگردانی ناخواسته، کلمهٔ RESTORE را در کادر تأیید بنویسید.', 400);
}

try {
    $prePath = null;
    $result = nscRestoreBackup($db, $tenantId, $file['tmp_name'], $prePath);

    logAudit($db, $user['id'], $user['name'], 'restore', 'data', null,
        sprintf('Restore from %s: %d tables, %d uploads. Pre-backup kept.',
            $summary['fileName'], count($result['restored']), $result['uploads']));

    // فایل پشتیبانِ «قبل از بازیابی» را برای دانلود نگه می‌داریم
    $preName = 'pre-restore-' . gmdate('Ymd-His') . '.nscbackup';
    $preStore = nscPreRestoreDir() . '/' . $preName;
    $kept = $prePath && @rename($prePath, $preStore);

    jsonResponse(true, [
        'restored' => $result['restored'],
        'uploads' => $result['uploads'],
        'preBackup' => $kept ? $preName : null,
        'totalRows' => array_sum($result['restored']),
    ], 'بازیابی انجام شد. برای دیدن داده‌های بازگردانده‌شده صفحه را دوباره باز کنید.');
} catch (Throwable $e) {
    error_log('NSC restore failed: ' . $e->getMessage());
    jsonResponse(false, null,
        'بازیابی کامل نشد و هیچ تغییری ثبت نشد (تراکنش برگشت خورد). گزارش خطای سرور را بررسی کنید.', 500);
}

// ─────────────────────────────────────────────────────────────

function nscUploadLimitBytes(): int
{
    $parse = static function (string $v): int {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $unit = strtolower($v[strlen($v) - 1]);
        $n = (float) $v;
        return (int) match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    };
    $limits = array_filter([
        $parse((string) ini_get('upload_max_filesize')),
        $parse((string) ini_get('post_max_size')),
    ]);
    return $limits ? min($limits) : 8 * 1024 * 1024;
}

function nscPreRestoreDir(): string
{
    $dir = __DIR__ . '/.secrets/pre-restore';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}
