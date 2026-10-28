<?php
/**
 * NSC User Authentication & Cross-Device Account Synchronization API (Phase 11)
 * Authoritative MySQL Authentication, Password Hash Verification & Session Management
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();

/**
 * Auto-heal users table columns so cross-device login & Office 7 controls
 * work reliably even before manual SQL migration is run.
 */
function ensureAuthUserSchema(PDO $db): array {
    $cols = [];
    try {
        $cols = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $alterStatements = [
            'owner_admin_id'   => "ALTER TABLE users ADD COLUMN owner_admin_id VARCHAR(64) NULL AFTER id",
            'hash_salt'        => "ALTER TABLE users ADD COLUMN hash_salt VARCHAR(80) NULL AFTER password_hash",
            'is_active'        => "ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER subscription_expires_at",
            'ai_enabled'       => "ALTER TABLE users ADD COLUMN ai_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_master",
            'token_version'    => "ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 0 AFTER ai_enabled",
            'app_profile_json' => "ALTER TABLE users ADD COLUMN app_profile_json LONGTEXT NULL AFTER allowed_tabs_json",
        ];
        foreach ($alterStatements as $colName => $sql) {
            if (!in_array($colName, $cols, true)) {
                try {
                    $db->exec($sql);
                    $cols[] = $colName;
                } catch (Throwable $ignore) {}
            }
        }
    } catch (Throwable $e) {
        error_log('ensureAuthUserSchema check failed: ' . $e->getMessage());
    }
    return [
        'hasAppProfile' => in_array('app_profile_json', $cols, true),
        'hasHashSalt'   => in_array('hash_salt', $cols, true),
        'hasIsActive'   => in_array('is_active', $cols, true),
        'hasAiEnabled'  => in_array('ai_enabled', $cols, true),
    ];
}

/**
 * Format a MySQL user row into the canonical frontend User payload
 */
function formatUserPayload(array $user, ?string $lastLoginOverride = null): array {
    $appProfile = !empty($user['app_profile_json'])
        ? (is_string($user['app_profile_json']) ? json_decode($user['app_profile_json'], true) : $user['app_profile_json'])
        : null;
    if (!is_array($appProfile)) {
        $appProfile = [];
    }
    $perms = !empty($user['permissions_json'])
        ? (is_string($user['permissions_json']) ? json_decode($user['permissions_json'], true) : $user['permissions_json'])
        : ($appProfile['permissions'] ?? new stdClass());
    $mods = !empty($user['allowed_modules_json'])
        ? (is_string($user['allowed_modules_json']) ? json_decode($user['allowed_modules_json'], true) : $user['allowed_modules_json'])
        : ($appProfile['customEnabledModules'] ?? new stdClass());
    $tabs = !empty($user['allowed_tabs_json'])
        ? (is_string($user['allowed_tabs_json']) ? json_decode($user['allowed_tabs_json'], true) : $user['allowed_tabs_json'])
        : ($appProfile['permissions']['allowedTabs'] ?? []);

    $isLocked = (int)($user['is_locked'] ?? 0) === 1;
    $isActive = array_key_exists('is_active', $user)
        ? ((int)$user['is_active'] === 1)
        : (array_key_exists('active', $appProfile) ? (bool)$appProfile['active'] : !$isLocked);
    $aiEnabled = array_key_exists('ai_enabled', $user)
        ? ((int)$user['ai_enabled'] === 1)
        : (array_key_exists('aiEnabled', $appProfile) ? (bool)$appProfile['aiEnabled'] : true);

    return [
        'id' => (string)$user['id'],
        'name' => (string)($user['name'] ?? $user['username']),
        'username' => (string)$user['username'],
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? 'viewer'),
        'phone' => (string)($user['phone'] ?? ''),
        'companyName' => (string)($user['company_name'] ?? ''),
        'companyAddress' => (string)($user['company_address'] ?? ''),
        'isMasterSuperAdmin' => (int)($user['is_master'] ?? 0) === 1,
        'ownerAdminId' => array_key_exists('owner_admin_id', $user) ? $user['owner_admin_id'] : ($appProfile['ownerAdminId'] ?? null),
        'active' => $isActive,
        'isLockedBySuperAdmin' => $isLocked,
        'aiEnabled' => $aiEnabled,
        'subscriptionPlan' => (string)($user['subscription_plan'] ?? 'pro'),
        'subscriptionExpiresAt' => $user['subscription_expires_at'] ?? null,
        'permissions' => $perms,
        'customEnabledModules' => $mods,
        'allowedTabs' => is_array($tabs) ? $tabs : [],
        'projectGrants' => $appProfile['projectGrants'] ?? null,
        'assignedProjectIds' => $appProfile['assignedProjectIds'] ?? [],
        'appProfile' => $appProfile,
        'staffLimit' => max(0, (int)($appProfile['staffLimit'] ?? 0)),
        'lastLoginAt' => $lastLoginOverride ?? ($user['last_login_at'] ?? null),
        'updatedAt' => $user['updated_at'] ?? null,
    ];
}

/**
 * Verify a candidate password against stored hash (supports Bcrypt $2y$ and NSC sha256$ with multi-salt fallback)
 */
function verifyUserPassword(array $user, string $password, string $loginInput = ''): bool {
    $storedHash = trim((string)($user['password_hash'] ?? ''));
    if ($storedHash === '' || $password === '') {
        return false;
    }

    // 2. NSC Frontend/Cross-Device sha256$ format: sha256$<hex(sha256(lower(salt)::nsc::password))>
    if (substr($storedHash, 0, 7) === 'sha256$' || substr($storedHash, 0, 7) === 'nsc-v2$') {
        $candidateSalts = array_unique(array_filter([
            trim((string)($user['hash_salt'] ?? '')),
            trim((string)($user['username'] ?? '')),
            trim((string)($user['email'] ?? '')),
            trim($loginInput),
        ], fn($v) => $v !== ''));

        foreach ($candidateSalts as $rawSalt) {
            $lowerSalt = function_exists('mb_strtolower') ? mb_strtolower($rawSalt, 'UTF-8') : strtolower($rawSalt);
            $calcLower = 'sha256$' . hash('sha256', $lowerSalt . '::nsc::' . $password);
            if ((substr($storedHash, 0, 7) === 'nsc-v2$' && password_verify($calcLower, substr($storedHash, 7))) || hash_equals($storedHash, $calcLower)) {
                return true;
            }
            $calcExact = 'sha256$' . hash('sha256', $rawSalt . '::nsc::' . $password);
            if ((substr($storedHash, 0, 7) === 'nsc-v2$' && password_verify($calcExact, substr($storedHash, 7))) || hash_equals($storedHash, $calcExact)) {
                return true;
            }
        }
        return false;
    }

    // 3. Standard PHP Bcrypt / Argon2 hash ($2y$, $2a$, $argon2...)
    return password_verify($password, $storedHash);
}

$schemaFlags = ensureAuthUserSchema($db);

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
    $action = $_GET['action'] ?? ($input['action'] ?? 'login');

    if (in_array($action, ['login', 'master_login'], true)) {
        $username = trim((string)($input['username'] ?? ''));
        $password = trim((string)($input['password'] ?? ''));

        if ($username === '' || $password === '') {
            jsonResponse(false, null, 'لطفاً نام کاربری و رمز عبور را وارد نمایید.', 400);
        }

        // ۱. بررسی محدودیت تلاش ورود پیش از هرگونه پاسخ (حداکثر ۵ تلاش برای هر نام کاربری یا ۲۰ تلاش از یک IP در ۱۵ دقیقه)
        $attemptKey = function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
        $clientIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        try {
            $rlStmt = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = :ident AND attempted_at > (NOW() - INTERVAL 15 MINUTE)");
            $rlStmt->execute(['ident' => $attemptKey]);
            $fails = (int)($rlStmt->fetch()['c'] ?? 0);

            $ipStmt = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE ip_address = :ip AND attempted_at > (NOW() - INTERVAL 15 MINUTE)");
            $ipStmt->execute(['ip' => $clientIp]);
            $ipFails = (int)($ipStmt->fetch()['c'] ?? 0);

            if ($fails >= 5 || $ipFails >= 20) {
                jsonResponse(false, null, 'به دلیل تلاش‌های ناموفق پی‌هم، ورود برای ۱۵ دقیقه قفل شد. لطفاً بعداً تلاش کنید.', 429);
            }
        } catch (Exception $e) { error_log('Rate-limit check failed: ' . $e->getMessage()); }

        // جستجوی کاربر با نام کاربری یا ایمیل (بدون حساسیت به حروف بزرگ و کوچک)
        $stmt = $db->prepare("
            SELECT * FROM users 
            WHERE LOWER(username) = LOWER(:u1) OR LOWER(email) = LOWER(:u2) 
            LIMIT 1
        ");
        $stmt->execute(['u1' => $username, 'u2' => $username]);
        $user = $stmt->fetch();

        if (!$user || (($action === 'master_login') !== ((int)($user['is_master'] ?? 0) === 1))) {
            try {
                $insStmt = $db->prepare("INSERT INTO login_attempts (identifier, ip_address) VALUES (:ident, :ip)");
                $insStmt->execute(['ident' => $attemptKey, 'ip' => $clientIp]);
            } catch (Exception $e) { error_log('Rate-limit record failed: ' . $e->getMessage()); }
            jsonResponse(false, null, 'نام کاربری یا رمز عبور اشتباه است.', 401);
        }

        if ((int)($user['is_locked'] ?? 0) === 1) {
            jsonResponse(false, null, 'حساب کاربری شما توسط مدیر ارشد مسدود گردیده است.', 403);
        }

        if (array_key_exists('is_active', $user) && (int)$user['is_active'] === 0) {
            jsonResponse(false, null, 'حساب کاربری شما غیرفعال گردیده است. با مدیریت تماس بگیرید.', 403);
        }

        if ((int)($user['is_master'] ?? 0) !== 1 && !empty($user['subscription_expires_at'])) {
            $expTs = strtotime((string)$user['subscription_expires_at']);
            if ($expTs !== false && $expTs <= time()) {
                jsonResponse(false, null, 'مدت اعتبار اشتراک یا دسترسی حساب کاربری شما به پایان رسیده است.', 403);
            }
        }

        $passwordMatches = verifyUserPassword($user, $password, $username);

        if (!$passwordMatches) {
            try {
                $insStmt = $db->prepare("INSERT INTO login_attempts (identifier, ip_address) VALUES (:ident, :ip)");
                $insStmt->execute(['ident' => $attemptKey, 'ip' => substr($clientIp, 0, 45)]);
            } catch (Exception $e) { error_log('Rate-limit record failed: ' . $e->getMessage()); }
            logAudit($db, $user['id'], $user['name'], 'login', 'auth', null, 'تلاش ناموفق برای ورود به سیستم');
            jsonResponse(false, null, 'نام کاربری یا رمز عبور اشتباه است.', 401);
        }

        if (substr((string)$user['password_hash'], 0, 7) === 'sha256$') {
            $rehash = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id AND password_hash = :old');
            $rehash->execute(['hash' => protectClientPasswordHash((string)$user['password_hash']), 'id' => $user['id'], 'old' => $user['password_hash']]);
        }

        // ورود موفق: پاک‌سازی تلاش‌های ناموفق قبلی همین کاربر
        try {
            $clrStmt = $db->prepare("DELETE FROM login_attempts WHERE identifier = :ident");
            $clrStmt->execute(['ident' => $attemptKey]);
        } catch (Exception $e) { error_log('Rate-limit clear failed: ' . $e->getMessage()); }

        // به‌روزرسانی تاریخ آخرین ورود و یکسان‌سازی نمک هش برای ورود از سایر دستگاه‌ها
        $now = date('Y-m-d H:i:s');
        try {
            $canonicalSalt = strtolower(trim((string)$user['username']));
            if ($schemaFlags['hasHashSalt'] && empty($user['hash_salt']) && substr((string)$user['password_hash'], 0, 7) === 'sha256$') {
                $upStmt = $db->prepare("UPDATE users SET last_login_at = :now, hash_salt = :salt WHERE id = :id");
                $upStmt->execute(['now' => $now, 'salt' => $canonicalSalt, 'id' => $user['id']]);
            } else {
                $upStmt = $db->prepare("UPDATE users SET last_login_at = :now WHERE id = :id");
                $upStmt->execute(['now' => $now, 'id' => $user['id']]);
            }
        } catch (Exception $e) { error_log('last_login update failed: ' . $e->getMessage()); }

        // تولید توکن امنیتی
        $token = generateToken($user['id'], $user['role'], (int)$user['is_master'], (int)($user['token_version'] ?? 0));

        // ثبت در لاگ امنیتی
        logAudit($db, $user['id'], $user['name'], 'login', 'auth', $user['id'], 'ورود موفقانه به سامانه');

        jsonResponse(true, [
            'token' => $token,
            'expiresAt' => date('c', time() + (86400 * 30)),
            'user' => formatUserPayload($user, $now)
        ], 'ورود با موفقیت انجام شد.');
    }

    /**
     * Multi-device user creation & synchronization.
     * Supports:
     * - New user creation with sha256$ hash or rawPassword (hashed server-side)
     * - Updating existing user profile/permissions/modules/active/lock/AI WITHOUT requiring password re-entry
     */
    if ($action === 'sync_user') {
        $me = authenticateUser(false);
        $meMaster = ((int)($me['is_master'] ?? 0) === 1) || $me['role'] === 'master';
        $mePriv = $meMaster || $me['role'] === 'admin';
        $p = $input['user'] ?? $input;
        if (!is_array($p)) $p = [];

        $username = strtolower(trim((string)($p['username'] ?? '')));
        $email = strtolower(trim((string)($p['email'] ?? '')));
        $hash = trim((string)($p['passwordHash'] ?? ''));
        $rawPassword = trim((string)($p['rawPassword'] ?? ($p['password'] ?? '')));
        $fid = trim((string)($p['id'] ?? ''));

        if ($username === '' || !preg_match('/^[a-z0-9._@-]{3,80}$/i', $username)) {
            logSyncReject($db, $me, $username, 'invalid-username');
            jsonResponse(false, null, 'نام کاربری نامعتبر است (حداقل ۳ کاراکتر لاتین یا عدد).', 400);
        }

        $existing = null;
        if ($fid !== '') {
            $s = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $s->execute(['id' => $fid]);
            $existing = $s->fetch() ?: null;
        }
        if (!$existing) {
            $s = $db->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1');
            $s->execute(['u' => $username]);
            $existing = $s->fetch() ?: null;
        }

        if (!empty($p['createOnly']) && $existing) { jsonResponse(false, null, 'این نام کاربری قبلاً ثبت شده است.', 409); }

        // Determine password hash:
        // 1) Valid sha256$ hash sent from client
        // 2) Raw password sent over HTTPS -> hash on server with sha256$<sha256(username::nsc::password)>
        // 3) Existing user update without password change -> keep existing password_hash in MySQL!
        $saltToStore = strtolower(trim((string)($p['hashSalt'] ?? $username)));
        if ($hash !== '' && substr($hash, 0, 7) === 'sha256$' && strlen($hash) <= 255) {
            // valid client-side sha256$ hash
        } elseif ($rawPassword !== '' && substr($rawPassword, 0, 7) !== 'sha256$') {
            $hash = 'sha256$' . hash('sha256', $saltToStore . '::nsc::' . $rawPassword);
        } elseif ($existing && !empty($existing['password_hash'])) {
            $hash = (string)$existing['password_hash'];
            $saltToStore = !empty($existing['hash_salt']) ? (string)$existing['hash_salt'] : $saltToStore;
        } else {
            logSyncReject($db, $me, $username, 'invalid-hash');
            jsonResponse(false, null, 'رمز عبور برای ایجاد حساب کاربری جدید الزامی است.', 400);
        }

        if (substr($hash, 0, 7) === 'sha256$') { $hash = protectClientPasswordHash($hash); }

        if ($email === '') {
            $email = $existing && !empty($existing['email']) ? (string)$existing['email'] : ($username . '@sync.local');
        }

        $isSelf = $existing && (string)$existing['id'] === (string)$me['id'];
        if ($existing && (int)$existing['is_master'] === 1 && !$isSelf) {
            logSyncReject($db, $me, $username, 'master-touch-denied');
            jsonResponse(false, null, 'حساب‌های مدیر ارشد قابل تغییر نیست.', 403);
        }
        if (!$existing && !empty($p['isMaster'])) {
            logSyncReject($db, $me, $username, 'master-create-denied');
            jsonResponse(false, null, 'Master accounts are created only via installer bootstrap.', 403);
        }
        if (!$mePriv && !$isSelf) {
            logSyncReject($db, $me, $username, 'privilege-denied');
            jsonResponse(false, null, 'عدم دسترسی.', 403);
        }
        if ($mePriv && !$meMaster && $existing && !$isSelf) {
            $ownerOf = (string)($existing['owner_admin_id'] ?? '');
            if ($ownerOf !== (string)$me['id']) {
                logSyncReject($db, $me, $username, 'owner-mismatch');
                jsonResponse(false, null, 'این کاربر متعلق به شرکت شما نیست.', 403);
            }
        }

        if (!$meMaster && !$isSelf) {
            assertModulePermission($me, 'users', $existing ? 'edit' : 'create');
            $ownerProfile = json_decode($me['app_profile_json'] ?? '{}', true) ?: [];
            $staffLimit = max(0, (int)($ownerProfile['staffLimit'] ?? 0));
            if ($staffLimit === 0) jsonResponse(false, null, 'ساخت و مدیریت کارمند برای این حساب فعال نشده است.', 403);
            if (!$existing) {
                // Serialize quota checks; two simultaneous requests cannot exceed the allowance.
                $quotaLock = 'nsc_staff_' . substr(hash('sha256', (string)$me['id']),0,40);
                $lockStmt = $db->prepare('SELECT GET_LOCK(:name, 5)');
                $lockStmt->execute(['name' => $quotaLock]);
                if ((int)$lockStmt->fetchColumn() !== 1) jsonResponse(false, null, 'لطفاً دوباره تلاش کنید.', 503);
                $countStmt = $db->prepare('SELECT COUNT(*) FROM users WHERE owner_admin_id=:owner AND is_master=0');
                $countStmt->execute(['owner' => $me['id']]);
                if ((int)$countStmt->fetchColumn() >= $staffLimit) jsonResponse(false, null, 'تعداد کارمندان به حد مجاز تعیین‌شده رسیده است.', 403);
            }
        }

        // Email uniqueness check across OTHER rows (auto-disambiguate @sync.local placeholder emails)
        $s = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:e) LIMIT 1');
        $s->execute(['e' => $email]);
        $emailRow = $s->fetch();
        if ($emailRow && (!$existing || (string)$emailRow['id'] !== (string)$existing['id'])) {
            if (str_ends_with($email, '@sync.local')) {
                $email = $username . '.' . substr(bin2hex(random_bytes(3)), 0, 6) . '@sync.local';
            } else {
                logSyncReject($db, $me, $username, 'email-conflict');
                jsonResponse(false, null, 'این ایمیل قبلاً ثبت گردیده است.', 409);
            }
        }

        $roleIn = strtolower(trim((string)($p['role'] ?? ($existing['role'] ?? 'viewer'))));
        $allowedRoles = ['admin', 'project_manager', 'accountant', 'procurement', 'viewer'];
        $role = in_array($roleIn, $allowedRoles, true) ? $roleIn : 'viewer';
        if ($role === 'admin' && !$meMaster && !$isSelf && (!$existing || $existing['role'] !== 'admin')) {
            logSyncReject($db, $me, $username, 'owner-create-denied');
            jsonResponse(false, null, 'فقط دفتر هفت می‌تواند حساب مالک شرکت (Admin) بسازد.', 403);
        }

        $planIn = strtolower(trim((string)($p['subscriptionPlan'] ?? ($existing['subscription_plan'] ?? 'pro'))));
        $plan = in_array($planIn, ['trial', 'basic', 'pro', 'enterprise', 'lifetime', '6_months', '1_year'], true) ? $planIn : 'pro';
        $expIn = trim((string)($p['subscriptionExpiresAt'] ?? ($existing['subscription_expires_at'] ?? '')));
        $expires = preg_match('/^\d{4}-\d{2}-\d{2}/', $expIn) ? str_replace('T', ' ', substr($expIn, 0, 19)) : null;
        $ownerId = $meMaster
            ? (trim((string)($p['ownerAdminId'] ?? ($existing['owner_admin_id'] ?? ''))) ?: null)
            : (string)$me['id'];

        if ($ownerId !== null && !$isSelf) {
            $os = $db->prepare('SELECT id FROM users WHERE id=:id AND role=:role AND is_master=0');
            $os->execute(['id'=>$ownerId,'role'=>'admin']);
            if (!$os->fetch() || ($fid !== '' && $ownerId === $fid)) jsonResponse(false,null,'مالک شرکت معتبر نیست.',400);
        }
        if (!$existing && $role !== 'admin' && $ownerId === null) jsonResponse(false,null,'برای کارمند، مالک شرکت را تعیین کنید.',400);
        if ($rawPassword !== '' && strlen($rawPassword)<12) jsonResponse(false,null,'رمز حداقل ۱۲ حرف باشد.',400);

        if ($existing && !$meMaster && $isSelf) {
            // Self-service: password + basic profile only (no role/lock/grants escalation)
            $sqlSelf = 'UPDATE users SET name = :n, phone = :ph, password_hash = :h'
                . ($schemaFlags['hasHashSalt'] ? ', hash_salt = :sa' : '')
                . ' WHERE id = :id';
            $s = $db->prepare($sqlSelf);
            $selfArgs = [
                'n' => trim((string)($p['name'] ?? $existing['name'])),
                'ph' => trim((string)($p['phone'] ?? ($existing['phone'] ?? ''))),
                'h' => $hash,
                'id' => $existing['id'],
            ];
            if ($schemaFlags['hasHashSalt']) $selfArgs['sa'] = $saltToStore;
            $s->execute($selfArgs);
            logAudit($db, $me['id'], $me['name'], 'update', 'user_sync', $existing['id'], 'Self profile sync from device');
            jsonResponse(true, ['id' => $existing['id'], 'self' => true], 'همگام‌سازی موفقانه انجام شد.');
        }

        $isLocked = array_key_exists('isLockedBySuperAdmin', $p)
            ? (!empty($p['isLockedBySuperAdmin']) ? 1 : 0)
            : (int)($existing['is_locked'] ?? 0);
        $isActive = array_key_exists('active', $p)
            ? ($p['active'] ? 1 : 0)
            : (array_key_exists('is_active', $existing ?: []) ? (int)$existing['is_active'] : 1);
        $aiEnabled = array_key_exists('aiEnabled', $p)
            ? ($p['aiEnabled'] ? 1 : 0)
            : (array_key_exists('ai_enabled', $existing ?: []) ? (int)$existing['ai_enabled'] : 1);

        if (!$meMaster && !$isSelf) {
            // Staff inherit the tenant's service ceiling and subscription. Only Office 7 can change these.
            $p['customEnabledModules'] = json_decode($me['allowed_modules_json'] ?? '{}', true) ?: [];
            $p['allowedTabs'] = json_decode($me['allowed_tabs_json'] ?? '[]', true) ?: [];
            $plan = (string)($me['subscription_plan'] ?? 'pro');
            $expires = $me['subscription_expires_at'] ?? null;
            $aiEnabled = (int)($me['ai_enabled'] ?? 1);
        }

        $existingProfile = ($existing && $schemaFlags['hasAppProfile'] && !empty($existing['app_profile_json']))
            ? (json_decode($existing['app_profile_json'], true) ?: [])
            : [];
        $incomingProfile = $meMaster && isset($p['appProfile']) && is_array($p['appProfile']) ? $p['appProfile'] : [];
        $mergedProfile = array_merge($existingProfile, $incomingProfile, [
            'active' => (bool)$isActive,
            'isLockedBySuperAdmin' => (bool)$isLocked,
            'aiEnabled' => (bool)$aiEnabled,
        ]);
        if ($meMaster && array_key_exists('staffLimit', $p)) $mergedProfile['staffLimit'] = min(500, max(0, (int)$p['staffLimit']));

        if (isset($p['projectGrants']) && is_array($p['projectGrants'])) {
            $mergedProfile['projectGrants'] = $p['projectGrants'];
        }
        if (isset($p['assignedProjectIds']) && is_array($p['assignedProjectIds'])) {
            $mergedProfile['assignedProjectIds'] = $p['assignedProjectIds'];
        }
        if (isset($p['customEnabledModules']) && is_array($p['customEnabledModules'])) {
            $mergedProfile['customEnabledModules'] = $p['customEnabledModules'];
        }

        $row = [
            'id' => $existing ? $existing['id'] : ($fid !== '' ? $fid : bin2hex(random_bytes(16))),
            'owner' => $isSelf && $existing ? ($existing['owner_admin_id'] ?? null) : $ownerId,
            'name' => trim((string)($p['name'] ?? ($existing['name'] ?? $username))),
            'username' => $username,
            'email' => $email,
            'hash' => $hash,
            'salt' => $saltToStore,
            'role' => $role,
            'phone' => trim((string)($p['phone'] ?? ($existing['phone'] ?? ''))),
            'company' => trim((string)($p['companyName'] ?? ($existing['company_name'] ?? ''))),
            'address' => (string)($p['companyAddress'] ?? ($existing['company_address'] ?? '')),
            'plan' => $plan,
            'expires' => $expires,
            'locked' => $isLocked,
            'active' => $isActive,
            'ai' => $aiEnabled,
            'perms' => json_encode($p['permissions'] ?? ($existing ? json_decode($existing['permissions_json'] ?? '{}', true) : new stdClass()), JSON_UNESCAPED_UNICODE),
            'modules' => json_encode($p['customEnabledModules'] ?? ($existing ? json_decode($existing['allowed_modules_json'] ?? '{}', true) : new stdClass()), JSON_UNESCAPED_UNICODE),
            'tabs' => json_encode($p['allowedTabs'] ?? ($existing ? json_decode($existing['allowed_tabs_json'] ?? '[]', true) : []), JSON_UNESCAPED_UNICODE),
            'profile' => json_encode($mergedProfile, JSON_UNESCAPED_UNICODE),
        ];

        if (!$meMaster) unset($mergedProfile['staffLimit']);

        if ($existing && (int)$existing['is_master'] === 1) {
            $row['role'] = 'master';
        }

        if ($existing) {
            $sql = 'UPDATE users SET owner_admin_id = :owner, name = :n, username = :u, email = :e,
                    password_hash = :h, role = :r, phone = :ph, company_name = :c, company_address = :a,
                    subscription_plan = :pl, subscription_expires_at = :ex, is_locked = :l,
                    permissions_json = :pm, allowed_modules_json = :mo, allowed_tabs_json = :tb'
                . ($schemaFlags['hasIsActive'] ? ', is_active = :ac' : '')
                . ($schemaFlags['hasAiEnabled'] ? ', ai_enabled = :ai' : '')
                . ($schemaFlags['hasHashSalt'] ? ', hash_salt = :sa' : '')
                . ($schemaFlags['hasAppProfile'] ? ', app_profile_json = :pr' : '')
                . ' WHERE id = :id';
            $s = $db->prepare($sql);
            $args = [
                'owner' => $row['owner'], 'n' => $row['name'], 'u' => $row['username'],
                'e' => $row['email'], 'h' => $row['hash'], 'r' => $row['role'], 'ph' => $row['phone'],
                'c' => $row['company'], 'a' => $row['address'], 'pl' => $row['plan'], 'ex' => $row['expires'],
                'l' => $row['locked'], 'pm' => $row['perms'], 'mo' => $row['modules'], 'tb' => $row['tabs'],
                'id' => $row['id']
            ];
            if ($schemaFlags['hasIsActive']) $args['ac'] = $row['active'];
            if ($schemaFlags['hasAiEnabled']) $args['ai'] = $row['ai'];
            if ($schemaFlags['hasHashSalt']) $args['sa'] = $row['salt'];
            if ($schemaFlags['hasAppProfile']) $args['pr'] = $row['profile'];
            $s->execute($args);
            logAudit($db, $me['id'], $me['name'], 'update', 'user_sync', $row['id'], 'User synced from device: ' . $username);
        } else {
            $cols = 'id, owner_admin_id, name, username, email, password_hash, role, phone, company_name,
                company_address, subscription_plan, subscription_expires_at, is_locked,
                permissions_json, allowed_modules_json, allowed_tabs_json';
            $vals = ':id, :owner, :n, :u, :e, :h, :r, :ph, :c, :a, :pl, :ex, :l, :pm, :mo, :tb';
            $args = [
                'id' => $row['id'], 'owner' => $row['owner'], 'n' => $row['name'], 'u' => $row['username'],
                'e' => $row['email'], 'h' => $row['hash'], 'r' => $row['role'], 'ph' => $row['phone'],
                'c' => $row['company'], 'a' => $row['address'], 'pl' => $row['plan'], 'ex' => $row['expires'],
                'l' => $row['locked'], 'pm' => $row['perms'], 'mo' => $row['modules'], 'tb' => $row['tabs']
            ];
            if ($schemaFlags['hasIsActive']) { $cols .= ', is_active'; $vals .= ', :ac'; $args['ac'] = $row['active']; }
            if ($schemaFlags['hasAiEnabled']) { $cols .= ', ai_enabled'; $vals .= ', :ai'; $args['ai'] = $row['ai']; }
            if ($schemaFlags['hasHashSalt']) { $cols .= ', hash_salt'; $vals .= ', :sa'; $args['sa'] = $row['salt']; }
            if ($schemaFlags['hasAppProfile']) { $cols .= ', app_profile_json'; $vals .= ', :pr'; $args['pr'] = $row['profile']; }
            $s = $db->prepare("INSERT INTO users ($cols) VALUES ($vals)");
            $s->execute($args);
            logAudit($db, $me['id'], $me['name'], 'create', 'user_sync', $row['id'], 'User pushed from device: ' . $username);
        }

        if (isset($quotaLock)) { $unlock=$db->prepare('SELECT RELEASE_LOCK(:name)'); $unlock->execute(['name'=>$quotaLock]); }
        $freshStmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $freshStmt->execute(['id' => $row['id']]);
        $freshRow = $freshStmt->fetch() ?: $row;

        jsonResponse(true, [
            'id' => $row['id'],
            'user' => is_array($freshRow) && isset($freshRow['username']) ? formatUserPayload($freshRow) : null
        ], 'همگام‌سازی کاربر در دیتابیس MySQL با موفقیت انجام شد.');
    }

    if ($action === 'delete_user') {
        $me = authenticateUser(false);
        $meMaster = ((int)$me['is_master'] === 1) || $me['role'] === 'master';
        $mePriv = $meMaster || $me['role'] === 'admin';
        if (!$mePriv) {
            jsonResponse(false, null, 'عدم دسترسی.', 403);
        }
        $fid = trim((string)($input['id'] ?? ''));
        $username = trim((string)($input['username'] ?? ''));
        $target = null;
        if ($fid !== '') {
            $s = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $s->execute(['id' => $fid]);
            $target = $s->fetch() ?: null;
        }
        if (!$target && $username !== '') {
            $s = $db->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1');
            $s->execute(['u' => $username]);
            $target = $s->fetch() ?: null;
        }
        if (!$target) {
            jsonResponse(true, ['deleted' => false], 'کاربر در سرور یافت نشد.');
        }
        if ((int)$target['is_master'] === 1) {
            jsonResponse(false, null, 'حساب‌های مدیر ارشد قابل حذف نیست.', 403);
        }
        if (!$meMaster) {
            $ownerOf = (string)($target['owner_admin_id'] ?? '');
            if ($ownerOf !== (string)$me['id']) {
                jsonResponse(false, null, 'این کاربر متعلق به شرکت شما نیست.', 403);
            }
        }
        if (!$meMaster) { assertModulePermission($me, 'users', 'delete'); }
        $s = $db->prepare('DELETE FROM users WHERE id = :id');
        $s->execute(['id' => $target['id']]);
        logAudit($db, $me['id'], $me['name'], 'delete', 'user_sync', $target['id'], 'User deleted from device: ' . $target['username']);
        jsonResponse(true, ['deleted' => true], 'کاربر از سرور حذف شد.');
    }

    if ($action === 'logout_all') {
        $me = authenticateUser(false);
        $upStmt = $db->prepare("UPDATE users SET token_version = token_version + 1 WHERE id = :id");
        $upStmt->execute(['id' => $me['id']]);
        logAudit($db, $me['id'], $me['name'], 'logout', 'auth', $me['id'], 'ابطال تمام نشست‌های فعال کاربر');
        jsonResponse(true, null, 'تمام نشست‌های فعال شما باطل شد. لطفاً دوباره وارد شوید.');
    }

    jsonResponse(false, null, 'عملیات نامعتبر است.', 400);
}

if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'ping') {
        jsonResponse(true, ['wave' => 110, 'time' => date('c'), 'mbstring' => function_exists('mb_strtolower'), 'php' => PHP_VERSION]);
    }
    // دریافت اطلاعات به‌روز کاربر لاگین‌شده از دیتابیس MySQL
    $user = authenticateUser(false);
    jsonResponse(true, ['user' => formatUserPayload($user)]);
}
