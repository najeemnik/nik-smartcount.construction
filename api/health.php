<?php
/**
 * NSC Health & Preflight Check
 *
 * دو سطح پاسخ:
 *   • عمومی (بدون توکن): فقط چهار بولین — آیا سایت تنظیم، نصب و متصل است.
 *     نام جدول، نسخهٔ PHP و مسیرها افشا نمی‌شوند (جلوگیری از reconnaissance).
 *   • مدیر ارشد (Bearer توکن معتبر is_master=1): گزارش کامل برای عیب‌یابی.
 */
require_once __DIR__.'/db.php';
require_once __DIR__.'/connection.php';
sendSecurityHeaders();

/** آیا درخواست از یک نشست مدیر ارشد معتبر آمده است؟ هرگز خطا پرتاب نمی‌کند. */
function nscHealthIsMaster(): bool {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(\S+)/', (string)$header, $m)) return false;
    $payload = verifyToken($m[1]);
    if (!$payload || (int)($payload['is_master'] ?? 0) !== 1) return false;
    try {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT is_master, is_locked, token_version FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $payload['user_id'] ?? '']);
        $user = $stmt->fetch();
        return $user
            && (int)$user['is_master'] === 1
            && (int)$user['is_locked'] === 0
            && (int)$user['token_version'] === (int)($payload['ver'] ?? 0);
    } catch (Throwable $e) {
        return false;
    }
}

$configured = DB_NAME !== '' && DB_USER !== '';
$connected = false;
$initialized = is_file(__DIR__.'/.secrets/installed.lock');
$diagnostic = null;
$missingTables = [];
$schemaVersion = '';

$requiredTables = [
    'users', 'projects', 'payment_vouchers', 'revenue_entries', 'treasury_accounts',
    'suppliers', 'material_procurements', 'labor_payroll', 'journal_entries', 'journal_lines',
    'chart_of_accounts', 'petty_cash_expenses', 'purchase_orders', 'steel_records',
    'project_files', 'project_folders', 'app_workspaces', 'workspace_sync_states',
    'office7_tenant_configs', 'login_attempts', 'system_audit_logs',
];

if ($configured) {
    try {
        $cfg = ['HOST' => DB_HOST, 'PORT' => DB_PORT, 'NAME' => DB_NAME, 'USER' => DB_USER, 'PASS' => DB_PASS];
        $pdo = nscConnect($cfg);
        $connected = true;
        $schemaVersion = nscSchemaVersionOf($pdo);

        try {
            $initialized = $initialized
                || (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_master=1')->fetchColumn() > 0;
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;
        }

        $existing = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach ($requiredTables as $table) {
            if (!in_array(strtolower($table), $existing, true)) $missingTables[] = $table;
        }
    } catch (Throwable $e) {
        $diagnostic = nscConnectionError($e);
    }
}

$ok = $configured && $connected && empty($missingTables) && $schemaVersion === NSC_SCHEMA_VERSION;

// ---------- پاسخ عمومی ----------
if (!nscHealthIsMaster()) {
    jsonResponse(true, [
        'ok' => $ok,
        'configured' => $configured,
        'initialized' => $initialized,
        'connected' => $connected,
    ]);
}

// ---------- پاسخ کامل برای مدیر ارشد ----------
$checks = [
    'phpOk' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'pdoMysql' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
    'json' => extension_loaded('json'),
    'openssl' => extension_loaded('openssl'),
    'secretsWritable' => is_writable(__DIR__.'/.secrets'),
    'uploadsWritable' => is_writable(UPLOAD_DIR),
    'schemaFile' => is_file(__DIR__.'/.secrets/schema.sql'),
    'installKey' => is_file(__DIR__.'/.secrets/install.key'),
    'https' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
];

jsonResponse(true, [
    'ok' => $ok,
    'configured' => $configured,
    'initialized' => $initialized,
    'connected' => $connected,
    'phpVersion' => PHP_VERSION,
    'schemaVersion' => $schemaVersion,
    'expectedSchemaVersion' => NSC_SCHEMA_VERSION,
    'schemaUpToDate' => $schemaVersion === NSC_SCHEMA_VERSION,
    'missingTables' => $missingTables,
    'checks' => $checks,
    'diagnostic' => $diagnostic,
    'hint' => $missingTables
        ? 'جدول‌های غایب در اولین درخواست API خودکار ساخته می‌شوند؛ اگر باقی ماندند، دسترسی CREATE یوزر دیتابیس را بررسی کنید.'
        : null,
]);
