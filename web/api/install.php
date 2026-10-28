<?php
/** First-run bootstrap. Existing master accounts are never overwritten here. */
require_once __DIR__ . '/db.php';
sendSecurityHeaders();
$secretDir = __DIR__ . '/.secrets';
$keyFile = $secretDir . '/install.key';
$lockFile = $secretDir . '/installed.lock';
$action = (string)($_GET['action'] ?? '');

if ($action === 'status' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (is_file($lockFile)) { jsonResponse(true, ['initialized' => true]); }
    $db = Database::getConnection();
    try {
        $initialized = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_master = 1')->fetchColumn() > 0;
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') { throw $e; }
        $initialized = false;
    }
    jsonResponse(true, ['initialized' => $initialized]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(false, null, 'Method not allowed.', 405);
}
if (!in_array($action, ['verify', 'complete'], true)) {
    jsonResponse(false, null, 'Unknown action.', 404);
}
if (is_file($lockFile)) {
    jsonResponse(false, null, 'نصب قبلاً تکمیل شده است؛ حساب مدیر بازنویسی نمی‌شود.', 409);
}
$input = getJsonInput();
$attempt = trim((string)($input['key'] ?? ''));
$expected = is_file($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
// Serialize verification and completion across requests on this installation.
$guard = fopen($secretDir . '/bootstrap.guard', 'c');
if (!$guard || !flock($guard, LOCK_EX)) {
    jsonResponse(false, null, 'قفل نصب قابل ایجاد نیست. دسترسی پوشه تنظیمات را بررسی کنید.', 503);
}
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateFile = $secretDir . '/install-rate-' . hash('sha256', $ip) . '.json';
$attempts = is_file($rateFile) ? (json_decode((string)file_get_contents($rateFile), true) ?: []) : [];
$attempts = array_values(array_filter($attempts, fn($ts) => is_int($ts) && $ts > time() - 900));
if (count($attempts) >= 5) { jsonResponse(false, null, 'پس از ۱۵ دقیقه دوباره تلاش کنید.', 429); }
if ($expected === '' || $attempt === '' || !hash_equals($expected, $attempt)) {
    $attempts[] = time();
    file_put_contents($rateFile, json_encode($attempts), LOCK_EX);
    jsonResponse(false, null, 'کلید نصب نامعتبر است.', 403);
}
@unlink($rateFile);
if (is_file($lockFile)) { jsonResponse(false, null, 'نصب قبلاً تکمیل شده است.', 409); }
$db = Database::getConnection();
$databaseLock = 'nsc-install-' . substr(hash('sha256', DB_NAME), 0, 40);
$l = $db->prepare('SELECT GET_LOCK(:name, 5)');
$l->execute(['name' => $databaseLock]);
if ((int)$l->fetchColumn() !== 1) { jsonResponse(false, null, 'نصب دیگری در حال اجرا است.', 409); }
register_shutdown_function(function () use ($db, $databaseLock) {
    try {
        if ($db->inTransaction()) $db->rollBack();
        $s = $db->prepare('SELECT RELEASE_LOCK(:name)');
        $s->execute(['name' => $databaseLock]);
    } catch (Throwable $e) { error_log('Installer cleanup failed.'); }
});
try {
    try {
        $masterExists = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_master = 1')->fetchColumn() > 0;
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') { throw $e; }
        $masterExists = false;
    }
    if ($masterExists) {
        jsonResponse(false, null, 'مدیر اصلی در دیتابیس موجود است. نصب دوباره اجازه ندارد.', 409);
    }
    if ($action === 'verify') { jsonResponse(true, ['verified' => true]); }
    $m = $input['master'] ?? null;
    if (!is_array($m)) { jsonResponse(false, null, 'معلومات مدیر اصلی الزامی است.', 400); }
    $username = strtolower(trim((string)($m['username'] ?? '')));
    $hash = trim((string)($m['passwordHash'] ?? ''));
    $salt = strtolower(trim((string)($m['hashSalt'] ?? $username)));
    if (!preg_match('/^[a-z0-9._-]{3,80}$/', $username) || !preg_match('/^sha256\$[a-f0-9]{64}$/i', $hash)
        || $salt === '' || strlen($salt) > 80) {
        jsonResponse(false, null, 'نام کاربری یا ساختار رمز مدیر معتبر نیست.', 400);
    }
    $email = strtolower(trim((string)($m['email'] ?? ''))) ?: $username . '@sync.local';
    $name = trim((string)($m['name'] ?? '')) ?: $username;
    $company = trim((string)($m['companyName'] ?? ''));
    if (strlen($email) > 150 || strlen($name) > 150 || strlen($company) > 200) {
        jsonResponse(false, null, 'طول معلومات مدیر بیش از حد مجاز است.', 400);
    }
    // DDL precedes the account transaction; a failed attempt can safely retry CREATE IF NOT EXISTS.
    $schema = file_get_contents($secretDir . '/schema.sql');
    if ($schema === false || $schema === '') { throw new RuntimeException('Missing installation schema.'); }
    $schema = preg_replace('/^--.*$/m', '', $schema);
    foreach (explode(';', $schema) as $statement) {
        if (trim($statement) !== '') $db->exec($statement);
    }
    $db->beginTransaction();
    $id = generateUuid();
    $stmt = $db->prepare('INSERT INTO users (id, name, username, email, password_hash, hash_salt, role, company_name, subscription_plan, is_locked, is_active, is_master) VALUES (:id, :name, :username, :email, :hash, :salt, :role, :company, :plan, 0, 1, 1)');
    $stmt->execute(['id' => $id, 'name' => $name, 'username' => $username, 'email' => $email,
        'hash' => protectClientPasswordHash($hash), 'salt' => $salt, 'role' => 'master', 'company' => $company, 'plan' => 'lifetime']);
    $db->commit();
    // The database master row also seals setup if the filesystem write fails.
    $sealed = file_put_contents($lockFile, json_encode(['locked_at' => gmdate('c'), 'master_id' => $id]), LOCK_EX);
    if ($sealed !== false) chmod($lockFile, 0600);
    logAudit($db, $id, $name, 'install', 'system', $id, 'Initial master created; setup sealed.');
    jsonResponse(true, ['initialized' => true, 'masterSynced' => true]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('NSC bootstrap failed: ' . $e->getMessage());
    jsonResponse(false, null, 'نصب تکمیل نشد. مشخصات دیتابیس، نسخه ساختار و گزارش خطای هاست را بررسی کنید؛ معلومات قبلی حذف نشده است.', 500);
}
