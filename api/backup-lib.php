<?php
/**
 * NiK Smart Count — پشتیبان‌گیری و بازیابی (Backup / Restore)
 *
 * اصل طراحی:
 *   • فقط «داده» پشتیبان می‌شود، نه ساختار — چون ساختار از روی api/.secrets/schema.sql
 *     بازسازی می‌شود و همین موضوع نسخه‌های قدیمی را هم قابل‌بازیابی می‌کند.
 *   • هر پشتیبان یک فایل .nscbackup (ZIP) است: دادهٔ یک شرکت + فایل‌های آپلودش.
 *   • ترتیب import از وابستگی‌های خارجی پیروی می‌کند (users → projects → رکوردها).
 *   • شناسه‌ها (UUID) همه در فایل هستند، پس روابط حفظ می‌شود.
 *
 * چرا ZIP و نه SQL؟
 *   افزونهٔ zip در هاست‌های cPanel معمولاً فعال است و ZIP قابل‌بازکردن با
 *   Windows Explorer است — کاربر می‌تواند ببیند داخلش چیست.
 */

/** جدول‌هایی که در پشتیبان یک شرکت نوشته می‌شوند، به ترتیب ایمنِ درج. */
function nscBackupTables(): array
{
    return [
        // ۱) هویت — کاربران و پیکربندی شرکت
        'users',
        'office7_tenant_configs',
        'company_profiles',
        // ۲) پروژه‌ها و بودجه
        'projects',
        'project_budgets',
        'project_transfers',
        'project_folders',
        'project_documents',
        'project_files',
        // ۳) طرف‌های حساب
        'clients',
        'suppliers',
        'supplier_product_rates',
        'subcontractors',
        'employees',
        // ۴) عملیات
        'material_procurements',
        'rebar_spec_memory',
        'steel_purchase_lines',
        'labor_payroll',
        'apartment_units',
        'assets_machinery',
        'petty_cash_expenses',
        'exchange_transactions',
        'revenue_entries',
        'payment_vouchers',
        'treasury_accounts',
        // ۵) دفتر
        'journal_entries',
        'journal_lines',
        // ۶) وضعیت برنامه
        'app_workspaces',
        'form_drafts',
        'workspace_sync_states',
    ];
}

/** جدول‌هایی که NO WAY پشتیبان‌شان را برنمی‌گردانیم. */
function nscBackupSkipTables(): array
{
    return [
        'login_attempts',   // موقتی
        'system_audit_logs', // لاگ
        'web_sessions',     // نشست فعال
        'password_reset_challenges',
        'email_login_challenges',
        'email_trusted_devices',
    ];
}

/** ستون‌هایی که در import هرگز از فایل نوشته نمی‌شوند. */
function nscBackupSkipColumns(): array
{
    return ['password_hash', 'hash_salt'];
}

// ─────────────────────────────────────────────────────────────
// ساخت پشتیبان
// ─────────────────────────────────────────────────────────────

/**
 * یک شرکت را پشتیبان می‌گیرد و مسیر فایل ZIP را برمی‌گرداند.
 *
 * @param string $tenantId شناسهٔ مالک شرکت
 * @return array{path:string,name:string,size:int,tables:array<string,int>,files:int}
 */
function nscCreateBackup(PDO $db, string $tenantId): array
{
    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'nsc-bk-');
    if ($tmp === false) {
        throw new RuntimeException('ساخت فایل موقت ممکن نشد.');
    }
    $zipPath = $tmp . '.nscbackup';
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ساخت فایل ZIP ممکن نشد.');
    }

    $manifest = [
        'format' => 'nsc-backup',
        'version' => 1,
        'created_at' => gmdate('c'),
        'tenant_id' => $tenantId,
        'schema_tables' => [],
        'row_counts' => [],
        'upload_count' => 0,
        'app_version' => nscBackupAppVersion(),
        'notes' => '',
    ];

    // ۱) دادهٔ جدول‌ها
    foreach (nscBackupTables() as $table) {
        if (!nscTableExists($db, $table)) {
            continue;
        }
        $rows = nscFetchTenantRows($db, $table, $tenantId);
        $manifest['schema_tables'][] = $table;
        $manifest['row_counts'][$table] = count($rows);
        $zip->addFromString("data/{$table}.json", nscJsonEncode($rows));
    }

    // ۲) فایل‌های آپلود این شرکت
    $manifest['upload_count'] = nscAddTenantUploads($zip, $db, $tenantId);

    // ۳) مانیفست — «شناسنامهٔ» فایل
    $zip->addFromString('manifest.json', nscJsonEncode($manifest));

    // ۴) راهنمای خوانا برای انسان
    $zip->addFromString('README.txt', nscBackupReadme($manifest));

    $zip->close();
    @chmod($zipPath, 0600);

    $name = 'nik-backup-' . preg_replace('/[^0-9a-zA-Z_-]/', '', $tenantId)
          . '-' . gmdate('Ymd-His') . '.nscbackup';

    return [
        'path' => $zipPath,
        'name' => $name,
        'size' => (int) filesize($zipPath),
        'tables' => $manifest['row_counts'],
        'files' => $manifest['upload_count'],
    ];
}

function nscBackupAppVersion(): string
{
    $f = __DIR__ . '/../nsc-runtime.php';
    if (is_file($f)) {
        $s = @file_get_contents($f);
        if ($s && preg_match('/v=([a-z0-9._-]+)/i', $s, $m)) {
            return $m[1];
        }
    }
    return 'unknown';
}

function nscTableExists(PDO $db, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $safe = str_replace(['`', "'"], '', $table);
    $candidates = [
        "SELECT 1 FROM `{$safe}` LIMIT 1",
        "SELECT 1 FROM {$safe} WHERE 1=0",
    ];
    foreach ($candidates as $sql) {
        try {
            $db->query($sql);
            return $cache[$table] = true;
        } catch (Throwable $e) {
            // ادامه — یا جدول نیست یا نحو پشتیبانی نمی‌شود
        }
    }
    try {
        $s = $db->prepare('SHOW TABLES LIKE :t');
        $s->execute(['t' => $safe]);
        return $cache[$table] = (bool) $s->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function nscTableColumns(PDO $db, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $safe = str_replace('`', '', $table);
    // ۱) راه قابل‌حمل: یک ردیف بخوان و کلیدها را بگیر
    foreach (["SELECT * FROM `{$safe}` LIMIT 1", "SELECT * FROM {$safe} LIMIT 1"] as $sql) {
        try {
            $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $cache[$table] = array_keys($row);
            }
        } catch (Throwable $e) {
            // ادامه
        }
    }
    // ۲) MySQL: حتی جدول خالی هم ستون‌ها را می‌دهد
    try {
        $cols = $db->query('SHOW COLUMNS FROM `' . $safe . '`')->fetchAll(PDO::FETCH_COLUMN);
        return $cache[$table] = $cols;
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

/**
 * ردیف‌های یک شرکت را می‌خواند. جدول‌های بدون tenant_id با JOIN به والد محدود می‌شوند.
 */
function nscFetchTenantRows(PDO $db, string $table, string $tenantId): array
{
    $cols = nscTableColumns($db, $table);
    if (!$cols) {
        return [];
    }

    $safe = '`' . str_replace('`', '', $table) . '`';

    // جدول‌هایی که مستقیماً tenant_id دارند
    if (in_array('tenant_id', $cols, true)) {
        $s = $db->prepare("SELECT * FROM {$safe} WHERE tenant_id = :tid");
        $s->execute(['tid' => $tenantId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // journal_lines → از طریق journal_entries
    if ($table === 'journal_lines' && nscTableExists($db, 'journal_entries')) {
        $s = $db->prepare(
            "SELECT jl.* FROM journal_lines jl
             INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE je.tenant_id = :tid"
        );
        $s->execute(['tid' => $tenantId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // users → خود شرکت + کارمندانش
    if ($table === 'users') {
        $s = $db->prepare(
            'SELECT * FROM users WHERE id = :tid OR owner_admin_id = :tid ORDER BY id'
        );
        $s->execute(['tid' => $tenantId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        // رمز عبور در فایل نوشته نمی‌شود (نشت‌پذیری در فایل بکاپ آسان است)
        foreach ($rows as &$r) {
            unset($r['password_hash'], $r['hash_salt']);
        }
        return $rows;
    }

    // office7_tenant_configs → tenant_user_id
    if ($table === 'office7_tenant_configs') {
        $s = $db->prepare('SELECT * FROM office7_tenant_configs WHERE tenant_user_id = :tid');
        $s->execute(['tid' => $tenantId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // company_profiles → اگر ستون tenant_id نبود، همه (کم‌خطر)
    return [];
}

/** فایل‌های آپلود شرکت را به ZIP اضافه می‌کند؛ تعداد را برمی‌گرداند. */
function nscAddTenantUploads(ZipArchive $zip, PDO $db, string $tenantId): int
{
    $root = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/\\') : __DIR__ . '/../uploads';
    if (!is_dir($root)) {
        return 0;
    }

    // فقط فایل‌هایی که در دیتابیس به این شرکت تعلق دارند
    $paths = [];
    if (nscTableExists($db, 'project_files')) {
        $cols = nscTableColumns($db, 'project_files');
        $candidates = array_values(array_intersect(['file_path', 'path', 'stored_path', 'storage_path'], $cols));
        if ($candidates) {
            $col = $candidates[0];
            $s = $db->prepare("SELECT `{$col}` FROM project_files WHERE tenant_id = :tid");
            $s->execute(['tid' => $tenantId]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $p) {
                if (is_string($p) && $p !== '') {
                    $paths[] = $p;
                }
            }
        }
    }

    $count = 0;
    foreach (array_unique($paths) as $relative) {
        $abs = nscResolveUploadPath($root, $relative);
        if ($abs !== null && is_file($abs)) {
            $zip->addFile($abs, 'uploads/' . ltrim(str_replace('\\', '/', $relative), '/'));
            $count++;
        }
    }
    return $count;
}

/** مسیر نسبی را امن به مسیر مطلق داخل uploads تبدیل می‌کند (بدون path traversal). */
function nscResolveUploadPath(string $root, string $relative): ?string
{
    $clean = str_replace('\\', '/', $relative);
    $clean = preg_replace('#/+#', '/', $clean);
    // هر «..» یا مسیر مطلق یا null byte → رد
    if (str_contains($clean, '..') || str_contains($clean, "\0") || str_starts_with($clean, '/')) {
        return null;
    }
    // نام فایل باید بعد از پاک‌سازی هم داخل ریشه بماند
    $abs = rtrim($root, '/') . '/' . ltrim($clean, '/');
    $realRoot = realpath($root);
    if ($realRoot === false) {
        return null;
    }
    $parent = realpath(dirname($abs));
    if ($parent === false) {
        // فایل هنوز ساخته نشده؛ ولی والد اگر هست باید داخل ریشه باشد
        return $abs;
    }
    return str_starts_with($parent . '/', $realRoot . '/') ? $abs : null;
}

function nscJsonEncode($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? '[]' : $json;
}

function nscBackupReadme(array $m): string
{
    $rows = '';
    foreach ($m['row_counts'] as $t => $n) {
        $rows .= sprintf("  %-28s %6d\n", $t, $n);
    }
    return "NiK Smart Count — فایل پشتیبان\n"
         . "================================\n\n"
         . "تاریخ ساخت : {$m['created_at']}\n"
         . "شناسهٔ شرکت : {$m['tenant_id']}\n"
         . "نسخهٔ برنامه: {$m['app_version']}\n\n"
         . "محتویات:\n"
         . "  manifest.json   — شناسنامهٔ همین فایل\n"
         . "  data/*.json     — دادهٔ جدول‌ها\n"
         . "  uploads/*       — فایل‌های پیوست\n\n"
         . "تعداد ردیف‌ها:\n{$rows}\n"
         . "فایل‌های پیوست: {$m['upload_count']}\n\n"
         . "این فایل رمز عبور کاربران را ندارد.\n"
         . "برای بازیابی: پنجرهٔ «اکنون از بکاپ بازگردان» → فایل را انتخاب کنید\n"
         . "→ سرور ابتدا اکنون از دادهٔ موجود بکاپ می‌گیرد → سپس ردیف‌ها اضافه می‌شوند.\n"
         . "هیچ ردیفی حذف نمی‌شود؛ ردیف هم‌شناسه به‌روزرسانی می‌شود.\n";
}

// ─────────────────────────────────────────────────────────────
// بازگرداندن از پشتیبان
// ─────────────────────────────────────────────────────────────

/**
 * محتوای یک فایل .nscbackup را بررسی می‌کند بدون اینکه چیزی بنویسد.
 *
 * @return array{ok:bool,error?:string,manifest?:array,tables?:array<string,int>}
 */
function nscInspectBackup(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'error' => 'فایل باز نشد؛ ممکن است ZIP معیوب یا پسوندش عوض شده باشد.'];
    }
    $raw = $zip->getFromName('manifest.json');
    if ($raw === false) {
        $zip->close();
        return ['ok' => false, 'error' => 'این فایل پشتیبان NiK نیست (manifest.json ندارد).'];
    }
    $manifest = json_decode($raw, true);
    if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'nsc-backup') {
        $zip->close();
        return ['ok' => false, 'error' => 'شناسنامهٔ فایل معتبر نیست یا از نسخهٔ دیگری است.'];
    }

    $counts = [];
    foreach ((array) ($manifest['schema_tables'] ?? []) as $t) {
        $safe = preg_replace('/[^a-z0-9_]/i', '', (string) $t);
        $json = $zip->getFromName("data/{$safe}.json");
        $counts[$safe] = $json === false ? -1 : count((array) json_decode($json, true));
    }
    $zip->close();

    return ['ok' => true, 'manifest' => $manifest, 'tables' => $counts];
}

/**
 * پشتیبان را در دیتابیس می‌ریزد. پیش از هر چیز از وضع موجود بکاپ می‌گیرد.
 *
 * @return array{restored:array<string,int>,uploads:int,pre_backup:string}
 */
function nscRestoreBackup(PDO $db, string $tenantId, string $zipPath, ?string &$preBackupPath = null): array
{
    // ۰) شبکهٔ ایمنی: قبل از هر نوشتنی، از وضع فعلی بکاپ بگیر
    $pre = nscCreateBackup($db, $tenantId);
    $preBackupPath = $pre['path'];

    $inspect = nscInspectBackup($zipPath);
    if (!$inspect['ok']) {
        throw new RuntimeException($inspect['error']);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('فایل پشتیبان باز نشد.');
    }

    $restored = [];
    $db->beginTransaction();
    try {
        // ۱) کاربران — با احتیاط: کاربر موجود با همان شناسه به‌روزرسانی می‌شود،
        //    ولی password_hash هرگز از فایل نوشته نمی‌شود (رمز کاربر عوض نمی‌شود).
        // ۲) بقیهٔ جدول‌ها به ترتیب وابستگی
        foreach (nscBackupTables() as $table) {
            $safe = preg_replace('/[^a-z0-9_]/i', '', $table);
            $json = $zip->getFromName("data/{$safe}.json");
            if ($json === false) {
                continue;
            }
            $rows = json_decode($json, true);
            if (!is_array($rows)) {
                continue;
            }
            $n = 0;
            foreach ($rows as $row) {
                if (!is_array($row) || !$row) {
                    continue;
                }
                $row = nscForceTenant($table, $row, $tenantId);
                if (nscUpsertRow($db, $table, $row)) {
                    $n++;
                }
            }
            $restored[$safe] = $n;
        }

        // ۳) فایل‌های پیوست
        $uploads = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || !str_starts_with($entry, 'uploads/')) {
                continue;
            }
            $bytes = $zip->getFromIndex($i);
            if ($bytes === false) {
                continue;
            }
            $uploads += nscWriteUpload($entry, $bytes);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $zip->close();
        throw $e;
    }
    $zip->close();

    return ['restored' => $restored, 'uploads' => $uploads, 'pre_backup' => $pre['name']];
}

/** اطمینان از اینکه ردیف به این شرکت تعلق می‌گیرد (جلوگیری از ردیف «بی‌صاحب»). */
function nscForceTenant(string $table, array $row, string $tenantId): array
{
    if ($table === 'users') {
        // ردیف مالک باید همان tenant باشد؛ کارمند باید به آن مالک وصل شود
        if (($row['id'] ?? '') === $tenantId) {
            $row['id'] = $tenantId;
        } else {
            $row['owner_admin_id'] = $tenantId;
        }
        unset($row['password_hash'], $row['hash_salt']); // هرگز از فایل
        return $row;
    }
    if ($table === 'office7_tenant_configs') {
        $row['tenant_user_id'] = $tenantId;
        return $row;
    }
    if (nscHasTenantColumnCached($table)) {
        $row['tenant_id'] = $tenantId;
    }
    return $row;
}

function nscHasTenantColumnCached(string $table): bool
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (nscBackupTables() as $t) {
            $map[$t] = true; // نهایتاً upsert ستون غایب را نادیده می‌گیرد
        }
    }
    return isset($map[$table]);
}

/** درج یا به‌روزرسانی یک ردیف با کلید اصلی id؛ ستون‌های غایب در جدول نادیده گرفته می‌شوند. */
function nscUpsertRow(PDO $db, string $table, array $row): bool
{
    $cols = nscTableColumns($db, $table);
    if (!$cols) {
        return false;
    }
    // فقط ستون‌هایی که در جدول واقعی وجود دارند
    $row = array_intersect_key($row, array_flip($cols));
    if (!$row) {
        return false;
    }
    $pk = in_array('id', $cols, true) ? 'id' : (in_array('tenant_id', $cols, true) ? 'tenant_id' : null);
    if ($pk === null || !isset($row[$pk])) {
        return false;
    }

    $safeTable = str_replace('`', '', $table);
    $names = array_keys($row);
    $quoted = array_map(fn($c) => '`' . str_replace('`', '', $c) . '`', $names);
    $params = [];
    foreach ($names as $c) {
        $params[':' . $c] = $row[$c];
    }

    // ۱) اگر ردیف هست → UPDATE. اگر نه → INSERT.
    //    این کار به ON DUPLICATE KEY (فقط MySQL) وابسته نیست و در هر دو موتور کار می‌کند.
    try {
        $exists = $db->prepare(
            "SELECT 1 FROM `{$safeTable}` WHERE `{$pk}` = :pk LIMIT 1"
        );
        $exists->execute(['pk' => $row[$pk]]);
        $found = (bool) $exists->fetchColumn();

        if ($found) {
            $updates = array_values(array_diff($names, [$pk]));
            if (!$updates) {
                return true; // چیزی برای تغییر نیست
            }
            $set = implode(',', array_map(fn($c) => "`{$c}`=:{$c}", $updates));
            $sql = "UPDATE `{$safeTable}` SET {$set} WHERE `{$pk}` = :pk";
            $db->prepare($sql)->execute($params);
            return true;
        }

        $sql = "INSERT INTO `{$safeTable}` (" . implode(',', $quoted) . ') VALUES ('
             . implode(',', array_map(fn($c) => ':' . $c, $names)) . ')';
        $db->prepare($sql)->execute($params);
        return true;
    } catch (Throwable $e) {
        error_log('NSC restore row skipped: ' . $table . ' — ' . $e->getMessage());
        return false;
    }
}

function nscWriteUpload(string $entry, string $bytes): int
{
    $root = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/\\') : __DIR__ . '/../uploads';
    $rel = substr($entry, strlen('uploads/'));
    $abs = nscResolveUploadPath($root, $rel);
    if ($abs === null) {
        return 0;
    }
    $dir = dirname($abs);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return 0;
    }
    return @file_put_contents($abs, $bytes) !== false ? 1 : 0;
}
