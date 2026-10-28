<?php
/**
 * NSC Database Connection & Security Helpers (Phase 10 Security Hardened)
 * Strict PDO Implementation with Zero-Vulnerability Architecture
 */

require_once __DIR__ . '/config.php';

/**
 * نسخهٔ ساختار دیتابیس. با هر تغییر در api/.secrets/schema.sql این مقدار را بالا ببرید؛
 * ارتقای خودکار در اولین درخواست بعدی اجرا می‌شود (بدون حذف هیچ معلوماتی).
 */
const NSC_SCHEMA_VERSION = '2026-10-29.1';

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
            nscSchemaEnsure(self::$instance);
        }

        return self::$instance;
    }
}

/**
 * ارتقای خودکار ساختار (Schema Drift Guard)
 * چرا لازم است: پیش از این، نصب‌های موجودی که جدول‌های جدید را نداشتند تا اجرای دستی
 * admin.php?action=upgrade با خطای ۵۰۰ روی روزنامچه/تنخواه مواجه می‌شدند.
 * این تابع فقط وقتی نسخهٔ ثبت‌شده با NSC_SCHEMA_VERSION تفاوت داشته باشد کل schema.sql را
 * اجرا می‌کند؛ همهٔ دستورها CREATE TABLE IF NOT EXISTS یا INSERT IGNORE هستند.
 */
function nscSchemaEnsure(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (nscSchemaVersionOf($db) === NSC_SCHEMA_VERSION) return;

    // اگر هاست اجازهٔ DDL ندهد یا schema.sql ناقص باشد، هر ساعت یک‌بار تلاش می‌کنیم نه هر درخواست.
    // اگر api/.secrets قابل نوشتن نباشد، نشانگرها در پوشهٔ موقت سیستم نگهداری می‌شوند.
    $stateDir = is_writable(__DIR__ . '/.secrets') ? __DIR__ . '/.secrets' : rtrim(sys_get_temp_dir(), '/') . '/nsc-schema';
    if (!is_dir($stateDir)) @mkdir($stateDir, 0700, true);
    $errorMarker = $stateDir . '/schema.error';
    if (is_file($errorMarker) && (time() - (int)@filemtime($errorMarker)) < 3600) return;

    $schemaFile = __DIR__ . '/.secrets/schema.sql';
    $sql = is_file($schemaFile) ? (string)@file_get_contents($schemaFile) : '';
    if (trim($sql) === '') {
        error_log('NSC schema ensure skipped: api/.secrets/schema.sql is missing or empty.');
        return;
    }

    $lock = @fopen($stateDir . '/schema.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    try {
        // درخواست‌های هم‌زمان: بعد از گرفتن قفل دوباره بررسی کن
        if (nscSchemaVersionOf($db) === NSC_SCHEMA_VERSION) return;

        $sql = preg_replace('/^--.*$/m', '', $sql);
        foreach (nscSplitSqlStatements($sql) as $statement) {
            if (trim($statement) !== '') $db->exec($statement);
        }
        if (nscSchemaVersionOf($db) !== NSC_SCHEMA_VERSION) {
            error_log('NSC schema ensure: statements ran but version marker was not written.');
            @touch($errorMarker);
            return;
        }
        @unlink($errorMarker);
        error_log('NSC schema ensured at version ' . NSC_SCHEMA_VERSION);
    } catch (Throwable $e) {
        error_log('NSC schema ensure failed: ' . $e->getMessage());
        @touch($errorMarker);
    } finally {
        if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    }
}

/**
 * تقسیم schema.sql به دستورهای جدا.
 * چرا explode(';') کافی نیست: اگر متن داخل یک رشته (مثلاً نام حساب یا توضیح فارسی)
 * شامل «;» باشد، دستور از وسط نصف می‌شود، خطای نحوی می‌دهد و کل ارتقا بی‌صدا شکست
 * می‌خورد (نشانگر schema.error نوشته می‌شود و تا یک ساعت تلاش بعدی انجام نمی‌شود).
 * این نسخه کوتیشن تک/دوتایی، بک‌تیک و کاراکتر escape را در نظر می‌گیرد.
 *
 * @return string[]
 */
function nscSplitSqlStatements(string $sql): array
{
    $out = [];
    $cur = '';
    $len = strlen($sql);
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($quote !== null) {
            $cur .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $cur .= $sql[++$i];      // کاراکتر escape‌شده را همان‌طور نگه دار
                continue;
            }
            if ($ch === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $cur .= $sql[++$i];  // '' یا "" یا `` دوتایی = کوتیشن فرار‌داده‌شده
                    continue;
                }
                $quote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $cur .= $ch;
            continue;
        }
        if ($ch === ';') {
            if (trim($cur) !== '') $out[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    if (trim($cur) !== '') $out[] = $cur;

    return $out;
}

/** نسخهٔ ساختار ثبت‌شده در دیتابیس؛ '' اگر جدول نسخه وجود نداشته باشد. */
function nscSchemaVersionOf(PDO $db): string {
    try {
        $stmt = $db->query("SELECT meta_value FROM nsc_schema_meta WHERE meta_key = 'core' LIMIT 1");
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
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
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > 20 * 1024 * 1024) {
        jsonResponse(false, null, 'حجم درخواست ارسالی بیش از حد مجاز است.', 413);
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
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

function assertModulePermission(array $user, string $module, string $action = 'view', ?string $projectId = null): void {
    // Only Master Super Admin (Office 7) is unconditionally exempt
    if ((int)($user['is_master'] ?? 0) === 1) {
        return;
    }

    if ((int)($user['is_locked'] ?? 0) === 1 || (array_key_exists('is_active', $user) && (int)$user['is_active'] === 0)) {
        jsonResponse(false, null, 'حساب کاربری شما غیرفعال یا مسدود شده است.', 403);
    }

    if (!empty($user['_tenant_owner'])) {
        assertModulePermission($user['_tenant_owner'], $module, $action, $projectId);
    }

    $role = strtolower(trim((string)($user['role'] ?? 'viewer')));
    if ($role === 'viewer' && $action !== 'view') jsonResponse(false, null, 'حساب مشاهده‌کننده اجازهٔ تغییر ندارد.', 403);
    $aliases = resolveModuleAliases($module);

    // 1. Check Office 7 per-user customEnabledModules (allowed_modules_json) — applies to BOTH admin & staff
    if (!empty($user['allowed_modules_json'])) {
        $mods = is_string($user['allowed_modules_json'])
            ? json_decode($user['allowed_modules_json'], true)
            : $user['allowed_modules_json'];
        if (is_array($mods)) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $mods) && $mods[$alias] === false) {
                    jsonResponse(false, null, "دسترسی به بخش «{$module}» توسط مدیریت ارشد (دفتر هفت) برای حساب شما غیرفعال شده است.", 403);
                }
            }
        }
    }

    // 1b. Check Office 7 allowed_tabs_json if configured as an explicit whitelist
    if (!empty($user['allowed_tabs_json'])) {
        $tabs = is_string($user['allowed_tabs_json'])
            ? json_decode($user['allowed_tabs_json'], true)
            : $user['allowed_tabs_json'];
        if (is_array($tabs) && count($tabs) > 0) {
            $tabMatch = false;
            foreach ($aliases as $alias) {
                if (in_array($alias, $tabs, true)) {
                    $tabMatch = true;
                    break;
                }
            }
            if (!$tabMatch && in_array($module, ['dashboard', 'projects', 'documents', 'steel', 'concrete', 'expenses', 'contractors', 'labor', 'suppliers', 'assets', 'apartments', 'payments', 'exchange', 'budget', 'reports', 'accounting', 'users', 'settings', 'audit_logs', 'auditLogs'], true)) {
                jsonResponse(false, null, "دسترسی به تب «{$module}» برای حساب شما غیرفعال است.", 403);
            }
        }
    }

    // Company owners (role=admin) have full access to all modules that were NOT disabled for them by Office 7
    if ($role === 'admin') {
        return;
    }

    // 2. Check explicit projectGrants inside app_profile_json if present
    if (!empty($user['app_profile_json'])) {
        $profile = is_string($user['app_profile_json'])
            ? json_decode($user['app_profile_json'], true)
            : $user['app_profile_json'];
        if (is_array($profile) && !empty($profile['projectGrants']) && is_array($profile['projectGrants'])) {
            $grants = $profile['projectGrants'];
            $relevant = [];
            foreach ($grants as $g) {
                $gPid = (string)($g['projectId'] ?? '');
                if ($gPid === '*' || $projectId === null || $projectId === '' || $gPid === $projectId) {
                    $relevant[] = $g;
                }
            }
            if (empty($relevant)) {
                jsonResponse(false, null, 'شما صلاحیت دسترسی به این پروژه را ندارید.', 403);
            }
            $viewOk = false;
            $actOk = false;
            foreach ($relevant as $g) {
                foreach ($aliases as $alias) {
                    if (!empty($g['sections'][$alias]['view'])) {
                        $viewOk = true;
                    }
                    if (!empty($g['sections'][$alias][$action])) {
                        $actOk = true;
                    }
                }
            }
            if (!$viewOk || ($action !== 'view' && !$actOk)) {
                jsonResponse(false, null, "شما صلاحیت انجام عملیات «{$action}» در بخش «{$module}» را ندارید.", 403);
            }
            return;
        }
    }

    // 3. Check explicit permissions_json
    if (!empty($user['permissions_json'])) {
        $perms = is_string($user['permissions_json'])
            ? json_decode($user['permissions_json'], true)
            : $user['permissions_json'];
        if (is_array($perms)) {
            foreach ($aliases as $alias) {
                if (isset($perms[$alias]) && is_array($perms[$alias])) {
                    if (array_key_exists('view', $perms[$alias]) && $perms[$alias]['view'] === false) {
                        jsonResponse(false, null, "شما صلاحیت مشاهده بخش «{$module}» را ندارید.", 403);
                    }
                    if (array_key_exists($action, $perms[$alias]) && $perms[$alias][$action] === false) {
                        jsonResponse(false, null, "شما صلاحیت عملیات «{$action}» در بخش «{$module}» را ندارید.", 403);
                    }
                    if (!empty($perms[$alias][$action])) {
                        return;
                    }
                }
            }
        }
    }

    if (!in_array($role, ['viewer', 'accountant', 'project_manager', 'procurement'], true)) {
        jsonResponse(false, null, 'نقش کاربری معتبر نیست.', 403);
    }

    // 4. Role fallback: viewer is strictly read-only; accountant can view/create/edit/del/print/export (not approve)
    if ($role === 'viewer' && $action !== 'view') {
        jsonResponse(false, null, 'حساب کاربری مشاهده‌گر (Viewer) اجازه ثبت، ویرایش یا حذف اطلاعات را ندارد.', 403);
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
        $idemKey = 'ik-' . substr(preg_replace('/[^a-zA-Z0-9_\-:.]/', '', $idemKey), 0, 80);
        $windowSeconds = 60;
    }

    $lockDir = sys_get_temp_dir() . '/nsc_idempotency';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0700, true);
    }
    $lockFile = $lockDir . '/' . hash('sha256', $userId . '::' . $idemKey) . '.lock';
    $now = time();
    if (is_file($lockFile)) {
        $mtime = (int)@filemtime($lockFile);
        if ($mtime > 0 && ($now - $mtime) < $windowSeconds) {
            jsonResponse(false, null, 'این درخواست هم‌اکنون ثبت شده یا در حال پردازش است (جلوگیری از ثبت تکراری).', 409);
        }
    }
    @file_put_contents($lockFile, (string)$now, LOCK_EX);

    // پاک‌سازی دوره‌ای: فایل‌های قفل قدیمی جمع می‌شوند تا این پوشه بی‌نهایت رشد نکند.
    if (random_int(1, 50) === 1) {
        foreach ((array)@glob($lockDir . '/*.lock') as $stale) {
            $mtime = (int)@filemtime($stale);
            if ($mtime > 0 && ($now - $mtime) > 600) @unlink($stale);
        }
    }
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
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;
    $rawHmac = hash_hmac('sha256', "$header.$payload", JWT_SECRET_KEY, true);
    $expectedUrlSafe = base64UrlEncode($rawHmac);
    $expectedLegacy = base64_encode($rawHmac);

    if (!hash_equals($expectedUrlSafe, $signature) && !hash_equals($expectedLegacy, $signature)) {
        return null; // امضا مخدوش است
    }

    $decodedPayload = base64UrlDecode($payload);
    $data = json_decode($decodedPayload, true);
    if (!$data || !isset($data['exp']) || $data['exp'] < time()) {
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
