<?php
/**
 * NSC Health & Preflight Check — بدون احراز هویت، بدون افشای اطلاعات حساس.
 * برای تشخیص سریع «چرا صفحه سفید است؟» روی هاست اشتراکی.
 * هیچ رمز، نام دیتابیس یا نام کاربری در پاسخ نیست.
 */
require_once __DIR__.'/db.php';
require_once __DIR__.'/connection.php';
sendSecurityHeaders();

$configured = DB_NAME !== '' && DB_USER !== '';
$connected = false;
$initialized = is_file(__DIR__.'/.secrets/installed.lock');
$diagnostic = null;

$requiredTables = [
    'users', 'projects', 'payment_vouchers', 'revenue_entries', 'treasury_accounts',
    'suppliers', 'material_procurements', 'labor_payroll', 'journal_entries', 'journal_lines',
    'chart_of_accounts', 'petty_cash_expenses', 'purchase_orders', 'steel_records',
    'project_files', 'project_folders', 'app_workspaces', 'workspace_sync_states',
    'office7_tenant_configs', 'login_attempts', 'system_audit_logs',
];
$missingTables = [];
$schemaVersion = '';

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

        // فقط نام جدول‌ها؛ هیچ ردیف یا اطلاعاتی از کاربران بازگردانده نمی‌شود.
        $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $existing = array_map('strtolower', $existing);
        foreach ($requiredTables as $table) {
            if (!in_array(strtolower($table), $existing, true)) $missingTables[] = $table;
        }
    } catch (Throwable $e) {
        $diagnostic = nscConnectionError($e);
    }
}

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

$ok = $configured && $connected && empty($missingTables);

jsonResponse(true, [
    'ok' => $ok,
    'configured' => $configured,
    'initialized' => $initialized,
    'connected' => $connected,
    'schemaVersion' => $schemaVersion,
    'expectedSchemaVersion' => NSC_SCHEMA_VERSION,
    'schemaUpToDate' => $schemaVersion === NSC_SCHEMA_VERSION,
    'missingTables' => $missingTables,
    'checks' => $checks,
    'diagnostic' => $diagnostic,
    'hint' => $missingTables
        ? 'جدول‌های غایب در اولین درخواست API خودکار ساخته می‌شوند؛ اگر باقی ماندند، دسترسی CREATE به یوزر دیتابیس را بررسی کنید.'
        : null,
]);
