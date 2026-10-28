<?php
/**
 * NSC Database Connection & Security Helpers (Phase 10 Security Hardened)
 * Strict PDO Implementation with Zero-Vulnerability Architecture
 */

require_once __DIR__ . '/config.php';
set_exception_handler(function (Throwable $e): void {
    error_log('NSC request failed: ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    jsonResponse(false, null, 'عملیات تکمیل نشد؛ گزارش خطای سرور را بررسی کنید.', 500);
});

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            require_once __DIR__.'/connection.php';
            $cfg=['HOST'=>DB_HOST,'PORT'=>DB_PORT,'NAME'=>DB_NAME,'USER'=>DB_USER,'PASS'=>DB_PASS];
            try { self::$instance=nscConnect($cfg); }
            catch(Throwable $e){
                $diagnostic=nscConnectionError($e);
                jsonResponse(false,['code'=>$diagnostic['code']],'['.$diagnostic['code'].'] '.$diagnostic['message'],503);
            }
        }

        return self::$instance;
    }
}

/**
 * ارسال پاسخ استاندارد JSON
 * Supports both jsonResponse(bool $success, $data, ?string $error, int $statusCode)
 * and legacy jsonResponse(array $payload, int $statusCode = 200).
 */
function jsonResponse($successOrPayload, $dataOrStatus = null, ?string $error = null, int $statusCode = 200) {
    if (is_array($successOrPayload)) {
        $code = is_int($dataOrStatus) ? $dataOrStatus : 200;
        http_response_code($code);
        $payload = $successOrPayload;
        if (!array_key_exists('success', $payload)) {
            $payload['success'] = !isset($payload['error']);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $success = (bool)$successOrPayload;
    http_response_code($statusCode);
    $response = ['success' => $success];
    if ($dataOrStatus !== null) {
        $response['data'] = $dataOrStatus;
    }
    if ($error !== null) {
        $response['error'] = $error;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * خواندن امن ورودی JSON همراه با محدودیت حجم بدنه (حداکثر ۲۰ مگابایت)
 */
function getJsonInput(): array {
    $stream = fopen('php://input', 'rb');
    $raw = $stream ? stream_get_contents($stream, 21 * 1024 * 1024 + 1) : false;
    if ($stream) fclose($stream);
    if ($raw === false) jsonResponse(false, null, 'خواندن درخواست ناکام شد.', 400);
    if ($raw === '') return [];
    if (strlen($raw) > 21 * 1024 * 1024) jsonResponse(false, null, 'حجم درخواست بیش از حد مجاز است.', 413);
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || substr(ltrim($raw), 0, 1) !== '{') {
        jsonResponse(false, null, 'بدنهٔ درخواست باید یک شیء JSON معتبر باشد.', 400);
    }
    return $decoded;
}

function getJsonBody(): array {
    return getJsonInput();
}

/**
 * تولید شناسه یکتا UUID v4 امن
 */
function generateUuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // set version to 0100
    $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    $b64 = strtr($data, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    return (string)base64_decode($b64, false);
}

/**
 * دریافت توکن و احراز هویت کاربر
 */
function authenticateUser(bool $requireMaster = false): array {
    $db = Database::getConnection();
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization']
        ?? $headers['authorization']
        ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
        jsonResponse(false, null, 'عدم دسترسی: توکن احراز هویت ارسال نشده است.', 401);
    }

    $token = $matches[1];
    $payload = verifyToken($token);
    if (!$payload) {
        logSyncReject($db, [], '', 'auth:invalid-or-expired-token');
        jsonResponse(false, null, 'توکن امنیتی نامعتبر یا منقضی شده است. لطفاً دوباره وارد شوید.', 401);
    }
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $payload['user_id']]);
    $user = $stmt->fetch();

    if (!$user || (int)($user['is_locked'] ?? 0) === 1 || (array_key_exists('is_active', $user) && (int)$user['is_active'] === 0)) {
        logSyncReject($db, ['id' => (string)($payload['user_id'] ?? ''), 'name' => 'unknown'], '', 'auth:user-missing-locked-or-inactive');
        jsonResponse(false, null, 'حساب کاربری شما غیرفعال یا مسدود شده است.', 403);
    }

    // ابطال نشست‌ها: توکن‌های صادرشده قبل از logout_all نامعتبرند
    $tokenVersion = (int)($payload['ver'] ?? 0);
    if ($tokenVersion !== (int)($user['token_version'] ?? 0)) {
        logSyncReject($db, $user, '', 'auth:token-version-mismatch');
        jsonResponse(false, null, 'نشست امنیتی شما باطل شده است. لطفاً دوباره وارد شوید.', 401);
    }

    // بررسی انقضای اشتراک یا دسترسی کاربر (به جز مدیر ارشد دفتر هفت)
    if ((int)($user['is_master'] ?? 0) !== 1 && !empty($user['subscription_expires_at'])) {
        $expTs = strtotime((string)$user['subscription_expires_at']);
        if ($expTs !== false && $expTs <= time()) {
            logSyncReject($db, $user, (string)($user['username'] ?? ''), 'auth:subscription-expired');
            jsonResponse(false, null, 'مدت اعتبار اشتراک یا دسترسی حساب شما به پایان رسیده است.', 403);
        }
    }

    if ($requireMaster && (int)$user['is_master'] !== 1) {
        logSyncReject($db, $user, '', 'auth:master-required');
        jsonResponse(false, null, 'دسترسی غیرمجاز: این عملیات مختص مدیریت ارشد (دفتر هفت) می‌باشد.', 403);
    }

    if ((int)($user['is_master'] ?? 0) !== 1 && !empty($user['owner_admin_id'])) {
        $ownerStmt = $db->prepare('SELECT * FROM users WHERE id = :id AND role = :role AND is_master = 0 LIMIT 1');
        $ownerStmt->execute(['id' => $user['owner_admin_id'], 'role' => 'admin']);
        $owner = $ownerStmt->fetch();
        if (!$owner || (int)($owner['is_locked'] ?? 0) === 1 || (int)($owner['is_active'] ?? 1) === 0
            || (!empty($owner['subscription_expires_at']) && strtotime($owner['subscription_expires_at']) <= time())) {
            jsonResponse(false, null, 'دسترسی شرکت غیرفعال یا منقضی شده است.', 403);
        }
        $user['_tenant_owner'] = $owner;
    }

    return $user;
}

function requireAuth(bool $requireMaster = false): array {
    return authenticateUser($requireMaster);
}

function requireRole(array $user, array $allowedRoles): void {
    if ((int)($user['is_master'] ?? 0) === 1) {
        return;
    }
    $role = strtolower(trim((string)($user['role'] ?? 'viewer')));
    $normalizedAllowed = array_map(fn($r) => strtolower(trim((string)$r)), $allowedRoles);
    if (!in_array($role, $normalizedAllowed, true)) {
        jsonResponse(false, null, 'شما صلاحیت انجام این عملیات را ندارید (Role Permission Denied).', 403);
    }
}

/**
 * Phase 10 & 11 Security + Office 7 Feature Control: Backend Module & Action Permission Evaluator
 * Enforces Office 7 per-user disabled modules/tabs (even for company owners), role defaults,
 * customEnabledModules, permissions_json, and projectGrants across all backend API endpoints.
 */
function resolveModuleAliases(string $module): array {
    $m = trim($module);
    $map = [
        'documents'      => ['documents', 'files'],
        'files'          => ['files', 'documents'],
        'steel'          => ['steel', 'materials', 'inventory'],
        'materials'      => ['materials', 'steel', 'concrete', 'inventory'],
        'concrete'       => ['concrete', 'materials'],
        'inventory'      => ['inventory', 'steel', 'materials'],
        'labor'          => ['labor', 'employees', 'payroll'],
        'employees'      => ['employees', 'labor', 'payroll'],
        'payroll'        => ['payroll', 'labor', 'employees'],
        'contractors'    => ['contractors', 'subcontractors'],
        'subcontractors' => ['subcontractors', 'contractors'],
        'apartments'     => ['apartments', 'sales'],
        'sales'          => ['sales', 'apartments'],
        'accounting'     => ['accounting', 'journal'],
        'journal'        => ['journal', 'accounting'],
        'payments'       => ['payments', 'finance'],
        'finance'        => ['finance', 'payments', 'expenses'],
        'auditLogs'      => ['auditLogs', 'audit_logs'],
        'audit_logs'     => ['audit_logs', 'auditLogs'],
    ];
    return $map[$m] ?? [$m];
}

require_once __DIR__ . '/permissions.php';
function assertModulePermission(array $user, string $module, string $action = 'view', ?string $projectId = null): void {
    if (!canAccessModule($user, $module, $action, $projectId, $projectId === null && $action === 'view')) {
        jsonResponse(false, null, 'صلاحیت این عملیات یا پروژه را ندارید.', 403);
    }
}

/**
 * Phase 11: Enforce per-user AI Assistant access in PHP APIs
 */
function assertAiPermission(array $user): void {
    if (!empty($user['_tenant_owner'])) { assertAiPermission($user['_tenant_owner']); }
    if ((int)($user['is_master'] ?? 0) === 1) {
        return;
    }
    if ((int)($user['is_locked'] ?? 0) === 1 || (array_key_exists('is_active', $user) && (int)$user['is_active'] === 0)) {
        jsonResponse(false, null, 'حساب کاربری شما غیرفعال یا مسدود شده است.', 403);
    }
    if (array_key_exists('ai_enabled', $user) && (int)$user['ai_enabled'] === 0) {
        jsonResponse(false, null, 'دسترسی به دستیار هوش مصنوعی برای حساب کاربری شما توسط مدیریت غیرفعال شده است.', 403);
    }
    if (!empty($user['app_profile_json'])) {
        $profile = is_string($user['app_profile_json']) ? json_decode($user['app_profile_json'], true) : $user['app_profile_json'];
        if (is_array($profile) && array_key_exists('aiEnabled', $profile) && $profile['aiEnabled'] === false) {
            jsonResponse(false, null, 'دسترسی به دستیار هوش مصنوعی برای حساب کاربری شما غیرفعال است.', 403);
        }
    }
}

/**
 * Phase 11: Auto-ensure form_drafts & workspace_sync_states tables exist
 */
function ensurePhase11SyncTables(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `form_drafts` (
              `id` VARCHAR(80) NOT NULL PRIMARY KEY,
              `tenant_id` VARCHAR(64) NOT NULL,
              `user_id` VARCHAR(64) NOT NULL,
              `project_id` VARCHAR(64) NULL,
              `form_type` VARCHAR(60) NOT NULL,
              `draft_key` VARCHAR(160) NOT NULL UNIQUE,
              `client_draft_key` VARCHAR(255) NULL,
              `draft_json` LONGTEXT NOT NULL,
              `client_updated_at` BIGINT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_fd_user_proj` (`user_id`, `project_id`, `form_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS `workspace_sync_states` (
              `tenant_id` VARCHAR(64) NOT NULL PRIMARY KEY,
              `state_version` BIGINT NOT NULL DEFAULT 1,
              `client_updated_at` BIGINT NOT NULL DEFAULT 0,
              `projects_json` LONGTEXT NULL,
              `records_json` LONGTEXT NULL,
              `updated_by` VARCHAR(64) NULL,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log("ensurePhase11SyncTables notice: " . $e->getMessage());
    }
}

/**
 * Phase 10 Security Audit: Strict Tenant & Project Isolation Check
 */
function assertTenantProjectAccess(PDO $db, ?string $projectId, string $tenantId, bool $required = true): ?array {
    $pid = trim((string)($projectId ?? ''));
    if ($pid === '') {
        if ($required) {
            jsonResponse(false, null, 'شناسه پروژه الزامی است.', 400);
        }
        return null;
    }
    $stmt = $db->prepare("SELECT id, name, tenant_id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
    $stmt->execute(['pid' => $pid, 'tid' => $tenantId]);
    $proj = $stmt->fetch();
    if (!$proj) {
        jsonResponse(false, null, 'پروژه یافت نشد یا شما اجازه دسترسی به این پروژه را ندارید.', 403);
    }
    return $proj;
}

/**
 * Phase 10 Security Audit: Backend Duplicate Request / Idempotency Guard
 * Prevents duplicate financial or operational records when a request is sent twice
 * due to double-click or network retry.
 */
function enforceIdempotencyGuard(PDO $db, array $user, string $module, array $payload = []): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        return;
    }
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $idemKey = trim((string)(
        $headers['X-Idempotency-Key']
        ?? $headers['x-idempotency-key']
        ?? ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '')
    ));

    $userId = (string)($user['id'] ?? 'anon');
    if ($idemKey === '') {
        // Compute a deterministic short-window fingerprint from user + method + module + payload
        $idemKey = 'fp-' . substr(hash('sha256', $userId . '|' . $method . '|' . $module . '|' . json_encode($payload)), 0, 40);
        $windowSeconds = 2;
    } else {
        $idemKey = 'ik-' . hash('sha256', $idemKey);
        $windowSeconds = 60;
    }

    $lockDir = sys_get_temp_dir() . '/nsc_idempotency_' . substr(hash('sha256', DB_HOST . ':' . DB_NAME), 0, 16);
    if (!is_dir($lockDir) && !mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
        jsonResponse(false, null, 'محافظ ثبت تکراری در دسترس نیست.', 503);
    }
    $lockFile = $lockDir . '/' . hash('sha256', $userId . '::' . $module . '::' . $idemKey) . '.lock';
    $handle = fopen($lockFile, 'c+');
    if (!$handle) jsonResponse(false, null, 'محافظ ثبت تکراری در دسترس نیست.', 503);
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        jsonResponse(false, null, 'این درخواست در حال پردازش است.', 409);
    }
    $lastSuccess = (int)stream_get_contents($handle);
    if ($lastSuccess > 0 && time() - $lastSuccess < $windowSeconds) {
        flock($handle, LOCK_UN); fclose($handle);
        jsonResponse(false, null, 'این درخواست قبلاً ثبت شده است.', 409);
    }
    // Hold the lock until the response completes. Failed validation must remain retryable.
    register_shutdown_function(function () use ($handle) {
        $lastError = error_get_last();
        $fatal = $lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
        $status = http_response_code() ?: 200;
        rewind($handle); ftruncate($handle, 0);
        fwrite($handle, (!$fatal && $status < 400) ? (string)time() : '0');
        fflush($handle); flock($handle, LOCK_UN); fclose($handle);
    });
}

/**
 * Wave 56: rejection logger — sync/auth failures are recorded with their
 * reason so the roster page can show WHY pushes never land. Never throws.
 */
function logSyncReject(PDO $db, array $me, string $username, string $reason): void {
    try {
        $module = (strpos($reason, 'auth:') === 0) ? 'auth' : 'user_sync';
        $action = ($module === 'auth') ? 'auth_rejected' : 'sync_rejected';
        logAudit($db, isset($me['id']) && $me['id'] !== '' ? (string)$me['id'] : null, (string)($me['name'] ?? 'unknown'), $action, $module, $username !== '' ? $username : null, $reason);
    } catch (Throwable $e) { /* never break the API response */ }
}

/**
 * شناسه مستأجر (شرکت) کاربر — کارمند: شناسه مالکش؛ مالک: شناسه خودش.
 * تنها مرجع تفکیک معلومات شرکت‌ها در تمام اندپوینت‌ها (deny by default).
 */
function currentTenantId(array $user): string {
    $tid = $user['owner_admin_id'] ?? '';
    if ($tid === '' || $tid === null) { $tid = $user['id']; }
    return (string)$tid;
}

/**
 * ساخت توکن امضاشده HMAC امن (با پشتیبانی از Base64URL و شناسه یکتای توکن jti)
 */
function generateToken(string $userId, string $role, int $isMaster, int $tokenVersion = 0): string {
    $header = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64UrlEncode(json_encode([
        'user_id' => $userId,
        'role' => $role,
        'is_master' => $isMaster,
        'ver' => $tokenVersion,
        'jti' => bin2hex(random_bytes(8)),
        'iat' => time(),
        'exp' => time() + (86400 * 30) // اعتبار ۳۰ روزه
    ]));
    $signature = hash_hmac('sha256', "$header.$payload", JWT_SECRET_KEY, true);
    $encodedSignature = base64UrlEncode($signature);
    return "$header.$payload.$encodedSignature";
}

/**
 * بررسی صحت توکن HMAC (پشتیبانی از هر دو فرمت Base64URL و Base64 استاندارد برای سازگاری عقب‌رو)
 */
function verifyToken(string $token): ?array {
    if (strlen($token) > 4096) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;
    $rawHmac = hash_hmac('sha256', "$header.$payload", JWT_SECRET_KEY, true);
    $expectedUrlSafe = base64UrlEncode($rawHmac);
    $expectedLegacy = base64_encode($rawHmac);

    if (!hash_equals($expectedUrlSafe, $signature) && !hash_equals($expectedLegacy, $signature)) {
        return null; // امضا مخدوش است
    }

    $decodedHeader = json_decode(base64UrlDecode($header), true);
    if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? '') !== 'HS256' || ($decodedHeader['typ'] ?? '') !== 'JWT') return null;
    $decodedPayload = base64UrlDecode($payload);
    $data = json_decode($decodedPayload, true);
    if (!is_array($data) || !isset($data['exp'], $data['user_id']) || !is_int($data['exp']) || $data['exp'] <= time() || !is_string($data['user_id']) || $data['user_id'] === '' || strlen($data['user_id']) > 64 || (isset($data['ver']) && !is_int($data['ver']))) {
        return null; // توکن منقضی شده
    }

    return $data;
}

/**
 * ثبت وقایع و ردپای سیستم (System Audit Trail)
 */
function logAudit(PDO $db, ?string $userId, string $userName, string $action, string $module, ?string $recordId, ?string $details = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt = $db->prepare("
            INSERT INTO system_audit_logs (user_id, user_name, action, module, record_id, ip_address, user_agent, details)
            VALUES (:uid, :uname, :action, :module, :rid, :ip, :ua, :details)
        ");
        $stmt->execute([
            'uid' => $userId,
            'uname' => $userName,
            'action' => $action,
            'module' => $module,
            'rid' => $recordId,
            'ip' => $ip,
            'ua' => substr($ua, 0, 255),
            'details' => $details
        ]);
    } catch (Exception $e) {
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}

function logSystemAudit(PDO $db, ?string $userId, string $userName, string $action, string $module, ?string $recordId, ?string $details = null): void {
    logAudit($db, $userId, $userName, $action, $module, $recordId, $details);
}

function writeAuditLog(PDO $db, ?string $userId, string $userName, string $action, string $module, ?string $recordId, $oldData = null, $newData = null): void {
    $details = is_string($newData) ? $newData : json_encode(['old' => $oldData, 'new' => $newData], JSON_UNESCAPED_UNICODE);
    logAudit($db, $userId, $userName, strtolower($action), strtolower($module), $recordId, $details);
}

/** Adaptive storage of the legacy client digest. Never accept a digest as a login password. */
function protectClientPasswordHash(string $digest): string {
    if (!preg_match('/^sha256\$[a-f0-9]{64}$/i', $digest)) {
        jsonResponse(false, null, 'ساختار رمز ارسالی معتبر نیست.', 400);
    }
    return 'nsc-v2$' . password_hash($digest, PASSWORD_BCRYPT, ['cost' => 12]);
}
