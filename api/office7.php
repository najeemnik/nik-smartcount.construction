<?php
/**
 * NSC Office 7 (دفتر هفت) Master Control, Cross-Device Sync, Form Drafts & AI Gate API (Phase 11)
 * Authoritative MySQL Persistence for Users, Permissions, Subscriptions, Feature Toggles & Sync
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
ensurePhase11SyncTables($db);

// Ensure users schema has all Phase 11 columns
$userCols = [];
try {
    $userCols = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $alterStatements = [
        'owner_admin_id'   => "ALTER TABLE users ADD COLUMN owner_admin_id VARCHAR(64) NULL AFTER id",
        'hash_salt'        => "ALTER TABLE users ADD COLUMN hash_salt VARCHAR(80) NULL AFTER password_hash",
        'is_active'        => "ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER subscription_expires_at",
        'ai_enabled'       => "ALTER TABLE users ADD COLUMN ai_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_master",
        'token_version'    => "ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 0 AFTER ai_enabled",
        'app_profile_json' => "ALTER TABLE users ADD COLUMN app_profile_json LONGTEXT NULL AFTER allowed_tabs_json",
    ];
    foreach ($alterStatements as $colName => $sql) {
        if (!in_array($colName, $userCols, true)) {
            try {
                $db->exec($sql);
                $userCols[] = $colName;
            } catch (Throwable $ignore) {}
        }
    }
} catch (Throwable $e) {
    error_log('office7 schema check notice: ' . $e->getMessage());
}
$hasAppProfile = in_array('app_profile_json', $userCols, true);
$hasHashSalt   = in_array('hash_salt', $userCols, true);
$hasIsActive   = in_array('is_active', $userCols, true);
$hasAiEnabled  = in_array('ai_enabled', $userCols, true);

// بررسی احراز هویت - مدیر ارشد، کلید دروازه دفتر هفت، یا کاربر احراز هویت‌شده برای عملیات‌های مجاز
$headers = function_exists('getallheaders') ? getallheaders() : [];
$masterGateKey = $headers['X-Office7-Gate-Key'] ?? $headers['x-office7-gate-key'] ?? ($_SERVER['HTTP_X_OFFICE7_GATE_KEY'] ?? '');

$currentUser = null;
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!empty($authHeader)) {
    $currentUser = authenticateUser(false);
}

$isMasterAuthorized = false;
if ($currentUser && (int)($currentUser['is_master'] ?? 0) === 1) {
    $isMasterAuthorized = true;
}

// A gate key alone must never grant master authority; a signed master session is required.

$action = $_GET['action'] ?? '';
$authenticatedTenantActions = ['tenants', 'drafts', 'save_draft', 'delete_draft', 'workspace_sync', 'ai_query'];
$adminAllowedActions = ['update_tenant', 'update_permissions', 'toggle_lock', 'toggle_active', 'toggle_ai', 'reset_password'];

$isCompanyAdmin = $currentUser && strtolower((string)($currentUser['role'] ?? '')) === 'admin';
$canProceed = $isMasterAuthorized
    || ($currentUser && in_array($action, $authenticatedTenantActions, true))
    || ($isCompanyAdmin && in_array($action, $adminAllowedActions, true));

if (!$canProceed) {
    jsonResponse(false, null, 'خطای امنیتی: عدم دسترسی به پنل ماستر دفتر هفت.', 403);
}

/**
 * Format user row for Office 7 & Cross-Device roster sync
 */
function formatOffice7UserRow(array $r, bool $hasAppProfile, bool $hasIsActive, bool $hasAiEnabled): array {
    $appProfile = ($hasAppProfile && !empty($r['app_profile_json']))
        ? (is_string($r['app_profile_json']) ? json_decode($r['app_profile_json'], true) : $r['app_profile_json'])
        : [];
    if (!is_array($appProfile)) $appProfile = [];

    $isLocked = (int)($r['is_locked'] ?? 0) === 1;
    $isActive = ($hasIsActive && array_key_exists('is_active', $r))
        ? ((int)$r['is_active'] === 1)
        : (array_key_exists('active', $appProfile) ? (bool)$appProfile['active'] : !$isLocked);
    $aiEnabled = ($hasAiEnabled && array_key_exists('ai_enabled', $r))
        ? ((int)$r['ai_enabled'] === 1)
        : (array_key_exists('aiEnabled', $appProfile) ? (bool)$appProfile['aiEnabled'] : true);

    return [
        'id' => (string)$r['id'],
        'name' => (string)($r['name'] ?? $r['username']),
        'username' => (string)$r['username'],
        'email' => (string)($r['email'] ?? ''),
        'role' => (string)($r['role'] ?? 'viewer'),
        'phone' => (string)($r['phone'] ?? ''),
        'companyName' => (string)($r['company_name'] ?? ''),
        'companyAddress' => (string)($r['company_address'] ?? ''),
        'isMasterSuperAdmin' => (int)($r['is_master'] ?? 0) === 1,
        'isLocked' => $isLocked,
        'isLockedBySuperAdmin' => $isLocked,
        'active' => $isActive,
        'aiEnabled' => $aiEnabled,
        'ownerAdminId' => array_key_exists('owner_admin_id', $r) ? $r['owner_admin_id'] : ($appProfile['ownerAdminId'] ?? null),
        'subscriptionPlan' => (string)($r['subscription_plan'] ?? 'pro'),
        'subscriptionExpiresAt' => $r['subscription_expires_at'] ?? null,
        'permissions' => json_decode($r['permissions_json'] ?? '{}', true),
        'customEnabledModules' => json_decode($r['allowed_modules_json'] ?? '{}', true),
        'allowedTabs' => json_decode($r['allowed_tabs_json'] ?? '[]', true),
        'projectGrants' => $appProfile['projectGrants'] ?? null,
        'assignedProjectIds' => $appProfile['assignedProjectIds'] ?? [],
        'appProfile' => $appProfile,
        'staffLimit' => max(0, (int)($appProfile['staffLimit'] ?? 0)),
        'lastLoginAt' => $r['last_login_at'] ?? null,
        'updatedAt' => $r['updated_at'] ?? null,
    ];
}

// -------------------------------------------------------------
// عملیات GET: دریافت لیست کاربران، پیش‌نویس‌ها، وضعیت همگام‌سازی و لاگ‌ها
// -------------------------------------------------------------
if ($method === 'GET') {
    $action = $action !== '' ? $action : 'tenants';

    if ($action === 'tenants') {
        $meId = ($currentUser && isset($currentUser['id'])) ? (string)$currentUser['id'] : '';
        $meMaster = $isMasterAuthorized || ($currentUser && (int)($currentUser['is_master'] ?? 0) === 1);
        $meUser = ($currentUser && isset($currentUser['username'])) ? strtolower(trim((string)$currentUser['username'])) : '';
        $meOwnerId = ($currentUser && !empty($currentUser['owner_admin_id'])) ? (string)$currentUser['owner_admin_id'] : '';
        $meServerId = '';
        if ($meUser !== '') {
            try {
                $idStmt = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:un) LIMIT 1');
                $idStmt->execute(['un' => $meUser]);
                $meServerId = (string)($idStmt->fetchColumn() ?: '');
            } catch (Exception $e) { /* ignore */ }
        }

        $sql = "SELECT id, owner_admin_id, name, username, email, role, phone,
                       company_name, company_address, subscription_plan,
                       subscription_expires_at, is_locked, is_master,
                       permissions_json, allowed_modules_json, allowed_tabs_json"
            . ($hasIsActive ? ", is_active" : "")
            . ($hasAiEnabled ? ", ai_enabled" : "")
            . ($hasAppProfile ? ", app_profile_json" : "") . ",
                       last_login_at, created_at, updated_at
                FROM users";
        $tparams = [];
        if ($meId !== '' && !$meMaster) {
            $tenantRootId = $meOwnerId !== '' ? $meOwnerId : ($meServerId !== '' ? $meServerId : $meId);
            $sql .= " WHERE id = :me OR owner_admin_id = :me2 OR LOWER(username) = LOWER(:mu) OR owner_admin_id = :troot OR id = :troot2";
            $tparams = [
                'me' => $meId,
                'me2' => $meId,
                'mu' => $meUser,
                'troot' => $tenantRootId,
                'troot2' => $tenantRootId,
            ];
        }
        if(!$meMaster && !canAccessModule($currentUser,'users','view',null,true)){
            $sql='SELECT * FROM users WHERE id=:only_me';
            $tparams=['only_me'=>$meId];
        }
        $sql .= " ORDER BY is_master DESC, created_at ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($tparams);
        $users = [];
        foreach ($stmt->fetchAll() as $r) {
            $users[] = formatOffice7UserRow($r, $hasAppProfile, $hasIsActive, $hasAiEnabled);
        }
        jsonResponse(true, ['users' => $users]);
    }

    if ($action === 'drafts') {
        if (!$currentUser) {
            jsonResponse(false, null, 'احراز هویت الزامی است.', 401);
        }
        $tenantId = currentTenantId($currentUser);
        $projectId = trim((string)($_GET['project_id'] ?? ''));
        if($projectId!==''&&!canAccessProject($currentUser,$projectId))jsonResponse(false,null,'صلاحیت پروژهٔ پیش‌نویس را ندارید.',403);
        $sql = "SELECT * FROM form_drafts WHERE user_id = :uid AND tenant_id = :tid";
        $params = ['uid' => $currentUser['id'], 'tid' => $tenantId];
        if ($projectId !== '') {
            $sql .= " AND (project_id = :pid OR project_id IS NULL OR project_id = '')";
            $params['pid'] = $projectId;
        }
        $sql .= " ORDER BY updated_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $drafts = [];
        foreach ($stmt->fetchAll() as $d) {
            if(!canAccessProject($currentUser,$d['project_id']?:null))continue;
            $drafts[] = [
                'id' => $d['id'],
                'draftKey' => $d['client_draft_key'] ?? preg_replace('/^scoped:[a-f0-9]{64}:/', '', $d['draft_key']),
                'formType' => $d['form_type'],
                'projectId' => $d['project_id'],
                'data' => json_decode($d['draft_json'] ?? '{}', true),
                'clientUpdatedAt' => (int)($d['client_updated_at'] ?? 0),
                'updatedAt' => $d['updated_at'],
            ];
        }
        jsonResponse(true, ['drafts' => $drafts]);
    }

    if ($action === 'workspace_sync') {
        if (!$currentUser || !in_array($currentUser['role']??'', ['admin','master'],true)) jsonResponse(false,null,'همگام‌سازی کل شرکت برای این نقش مجاز نیست.',403);
        if (!$currentUser) {
            jsonResponse(false, null, 'احراز هویت الزامی است.', 401);
        }
        if ((int)($currentUser['is_master'] ?? 0) !== 1 && $currentUser['role'] !== 'admin') { jsonResponse(false, null, 'همگام‌سازی کل شرکت برای این نقش مجاز نیست.', 403); }
        assertLegacyWorkspacePermission($currentUser,'view');
        $tenantId = currentTenantId($currentUser);
        $stmt = $db->prepare("SELECT * FROM workspace_sync_states WHERE tenant_id = :tid LIMIT 1");
        $stmt->execute(['tid' => $tenantId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(true, [
                'tenantId' => $tenantId,
                'stateVersion' => 0,
                'clientUpdatedAt' => 0,
                'projects' => null,
                'records' => null,
            ]);
        }
        jsonResponse(true, [
            'tenantId' => $tenantId,
            'stateVersion' => (int)$row['state_version'],
            'clientUpdatedAt' => (int)$row['client_updated_at'],
            'projects' => !empty($row['projects_json']) ? json_decode($row['projects_json'], true) : null,
            'records' => !empty($row['records_json']) ? json_decode($row['records_json'], true) : null,
            'updatedBy' => $row['updated_by'],
            'updatedAt' => $row['updated_at'],
        ]);
    }

    if ($action === 'audit_logs') {
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        $stmt = $db->prepare("
            SELECT * FROM system_audit_logs
            ORDER BY created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();
        jsonResponse(true, ['logs' => $logs]);
    }
}

// -------------------------------------------------------------
// عملیات POST: ذخیره تغییرات، قفل/بازگشایی، فعال/غیرفعال، هوش مصنوعی، پیش‌نویس و همگام‌سازی
// -------------------------------------------------------------
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
    $action = $action !== '' ? $action : ($input['action'] ?? 'update_tenant');

    // ۱. به‌روزرسانی مشخصات، نقش، رمز عبور، اشتراک، ماژول‌ها و هوش مصنوعی کاربر (پشتیبانی از update_tenant و update_permissions)
    if (in_array($action, ['update_tenant', 'update_permissions', 'reset_password', 'toggle_active', 'toggle_ai'], true)) {
        $userId = trim((string)($input['id'] ?? ($input['userId'] ?? '')));
        $usernameLookup = strtolower(trim((string)($input['username'] ?? '')));

        if ($userId === '' && $usernameLookup === '') {
            jsonResponse(false, null, 'شناسه کاربر الزامی است.', 400);
        }

        // پیدا کردن کاربر هدف از دیتابیس
        $target = null;
        if ($userId !== '') {
            $s = $db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $s->execute(['id' => $userId]);
            $target = $s->fetch() ?: null;
        }
        if (!$target && $usernameLookup !== '') {
            $s = $db->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
            $s->execute(['u' => $usernameLookup]);
            $target = $s->fetch() ?: null;
            if ($target) {
                $userId = (string)$target['id'];
            }
        }

        if (!$target) {
            jsonResponse(false, null, 'کاربر مورد نظر در دیتابیس یافت نشد.', 404);
        }

        // اگر فراخواننده‌ مدیر شرکت (غیر ماستر) است، فقط کارمندان زیرمجموعه خودش را می‌تواند ویرایش کند
        if (!$isMasterAuthorized) {
            if ((int)($target['is_master'] ?? 0) === 1) {
                jsonResponse(false, null, 'حساب مدیر ارشد قابل تغییر نیست.', 403);
            }
            $targetOwner = (string)($target['owner_admin_id'] ?? '');
            if ((string)$target['id'] !== (string)$currentUser['id'] && $targetOwner !== (string)$currentUser['id']) {
                jsonResponse(false, null, 'این کاربر متعلق به شرکت شما نیست.', 403);
            }
        }

        if (!$isMasterAuthorized) {
            assertModulePermission($currentUser, 'users', 'edit');
            if ((string)$target['id'] === (string)$currentUser['id']) {
                jsonResponse(false, null, 'تغییر تنظیمات مدیریتی مالک فقط از دفتر هفت ممکن است.', 403);
            }
            foreach (['subscriptionPlan', 'subscriptionExpiresAt', 'customEnabledModules', 'allowedTabs', 'appProfile', 'staffLimit'] as $restricted) {
                if (array_key_exists($restricted, $input)) {
                    jsonResponse(false, null, 'این تنظیم فقط توسط دفتر هفت قابل تغییر است.', 403);
                }
            }
        }

        if(isset($input['email'])){
            require_once __DIR__.'/email-security.php';
            if(!is_string($input['email']) || !usableLoginEmail(trim($input['email'])))jsonResponse(false,null,'ایمیل واقعی و معتبر وارد کنید.',400);
            $input['email']=trim($input['email']);
        }
        if (isset($input['ownerAdminId']) && trim((string)$input['ownerAdminId']) !== '') {
            $ownerId=trim((string)$input['ownerAdminId']);
            $ownerCheck=$db->prepare('SELECT id FROM users WHERE id=:id AND role=:role AND is_master=0');
            $ownerCheck->execute(['id'=>$ownerId,'role'=>'admin']);
            if($ownerId===$userId||!$ownerCheck->fetch())jsonResponse(false,null,'مالک شرکت معتبر نیست.',400);
        }
        $passwordInputs=[];
        foreach(['newPassword','password','passwordHash'] as $key){
            if(!array_key_exists($key,$input))continue;
            if(!is_string($input[$key]))jsonResponse(false,null,'رمز معتبر نیست.',400);
            if($input[$key]!=='')$passwordInputs[]=$input[$key];
        }
        if(count(array_unique($passwordInputs))>1)jsonResponse(false,null,'مقادیر متفاوت برای رمز ارسال شده است.',400);
        $rawOrHashedPwd=$passwordInputs[0]??'';
        if($rawOrHashedPwd!=='' && substr($rawOrHashedPwd,0,7)!=='sha256$' && strlen($rawOrHashedPwd)<12)jsonResponse(false,null,'رمز حداقل ۱۲ حرف باشد.',400);

        $fields = [];
        $params = ['id' => $userId];
        $nextUsername = strtolower(trim((string)($input['username'] ?? $target['username'])));

        if (isset($input['name']) && trim((string)$input['name']) !== '') {
            $fields[] = 'name = :name';
            $params['name'] = trim((string)$input['name']);
        }
        if (isset($input['username']) && $nextUsername !== '' && preg_match('/^[a-z0-9._@-]{3,80}$/i', $nextUsername)) {
            // بررسی یکتا بودن نام کاربری جدید
            $uCheck = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:u) AND id != :id LIMIT 1");
            $uCheck->execute(['u' => $nextUsername, 'id' => $userId]);
            if (!$uCheck->fetch()) {
                $fields[] = 'username = :uname';
                $params['uname'] = $nextUsername;
            }
        }
        if (isset($input['email']) && trim((string)$input['email']) !== '') {
            $nextEmail = strtolower(trim((string)$input['email']));
            $eCheck = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:e) AND id != :id LIMIT 1");
            $eCheck->execute(['e' => $nextEmail, 'id' => $userId]);
            if (!$eCheck->fetch()) {
                $fields[] = 'email = :email';
                $params['email'] = $nextEmail;
            }
        }
        if (isset($input['phone'])) {
            $fields[] = 'phone = :phone';
            $params['phone'] = trim((string)$input['phone']);
        }
        if (isset($input['companyName'])) {
            $fields[] = 'company_name = :cname';
            $params['cname'] = trim((string)$input['companyName']);
        }
        if (isset($input['companyAddress'])) {
            $fields[] = 'company_address = :caddr';
            $params['caddr'] = trim((string)$input['companyAddress']);
        }
        if (isset($input['role']) && (int)($target['is_master'] ?? 0) !== 1) {
            $roleIn = strtolower(trim((string)$input['role']));
            if (in_array($roleIn, ['admin', 'project_manager', 'accountant', 'procurement', 'viewer'], true)) {
                if ($roleIn !== 'admin' || $isMasterAuthorized || $target['role'] === 'admin') {
                    $fields[] = 'role = :role';
                    $params['role'] = $roleIn;
                }
            }
        }
        if (isset($input['ownerAdminId']) && $isMasterAuthorized) {
            $fields[] = 'owner_admin_id = :owner';
            $params['owner'] = trim((string)$input['ownerAdminId']) !== '' ? trim((string)$input['ownerAdminId']) : null;
        }

        // پشتیبانی امن از تغییر یا بازنشانی رمز عبور (هم sha256$ و هم رمز خام)
        if ($rawOrHashedPwd !== '') {
            $saltForPwd = $nextUsername !== '' ? $nextUsername : strtolower(trim((string)$target['username']));
            if (substr($rawOrHashedPwd, 0, 7) === 'sha256$') {
                $finalHash = $rawOrHashedPwd;
            } else {
                $finalHash = 'sha256$' . hash('sha256', $saltForPwd . '::nsc::' . $rawOrHashedPwd);
            }
            $fields[] = 'password_hash = :pwd';
            $fields[] = 'token_version = token_version + 1';
            $params['pwd'] = protectClientPasswordHash($finalHash);
            if ($hasHashSalt) {
                $fields[] = 'hash_salt = :hsalt';
                $params['hsalt'] = $saltForPwd;
            }
        }

        if (isset($input['subscriptionPlan'])) {
            $fields[] = 'subscription_plan = :splan';
            $params['splan'] = trim((string)$input['subscriptionPlan']);
        }
        if (array_key_exists('subscriptionExpiresAt', $input)) {
            $fields[] = 'subscription_expires_at = :sexp';
            $expVal = trim((string)($input['subscriptionExpiresAt'] ?? ''));
            $params['sexp'] = $expVal !== '' ? str_replace('T', ' ', substr($expVal, 0, 19)) : null;
        }

        // وضعیت قفل (Lock / Unlock)
        if (array_key_exists('isLockedBySuperAdmin', $input) || array_key_exists('isLocked', $input)) {
            $lockedBool = array_key_exists('isLockedBySuperAdmin', $input)
                ? !empty($input['isLockedBySuperAdmin'])
                : !empty($input['isLocked']);
            if ((int)($target['is_master'] ?? 0) !== 1) {
                $fields[] = 'is_locked = :lck';
                $params['lck'] = $lockedBool ? 1 : 0;
            }
        }

        // وضعیت فعال/غیرفعال (Activate / Deactivate)
        if ($hasIsActive && (array_key_exists('active', $input) || array_key_exists('isActive', $input))) {
            $activeBool = array_key_exists('active', $input)
                ? (bool)$input['active']
                : (bool)$input['isActive'];
            if ((int)($target['is_master'] ?? 0) !== 1) {
                $fields[] = 'is_active = :act';
                $params['act'] = $activeBool ? 1 : 0;
            }
        }

        // وضعیت دستیار هوش مصنوعی (Per-User AI Enable / Disable)
        if ($hasAiEnabled && (array_key_exists('aiEnabled', $input) || array_key_exists('ai_enabled', $input))) {
            $aiBool = array_key_exists('aiEnabled', $input)
                ? (bool)$input['aiEnabled']
                : (bool)$input['ai_enabled'];
            $fields[] = 'ai_enabled = :aie';
            $params['aie'] = $aiBool ? 1 : 0;
        }

        if (isset($input['customEnabledModules'])) {
            $fields[] = 'allowed_modules_json = :mods';
            $params['mods'] = json_encode($input['customEnabledModules'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($input['allowedTabs'])) {
            $fields[] = 'allowed_tabs_json = :tabs';
            $params['tabs'] = json_encode($input['allowedTabs'], JSON_UNESCAPED_UNICODE);
        } elseif (isset($input['permissions']['allowedTabs']) && is_array($input['permissions']['allowedTabs'])) {
            $fields[] = 'allowed_tabs_json = :tabs';
            $params['tabs'] = json_encode($input['permissions']['allowedTabs'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($input['permissions'])) {
            $fields[] = 'permissions_json = :perms';
            $params['perms'] = json_encode($input['permissions'], JSON_UNESCAPED_UNICODE);
        }

        // همگام‌سازی کامل app_profile_json برای حفظ تمام تنظیمات کاربر در تمام دستگاه‌ها
        if ($hasAppProfile) {
            $existingProfile = !empty($target['app_profile_json'])
                ? (json_decode($target['app_profile_json'], true) ?: [])
                : [];
            $incomingProfile = isset($input['appProfile']) && is_array($input['appProfile']) ? $input['appProfile'] : [];
            $mergedProfile = array_merge($existingProfile, $incomingProfile);

            foreach (['name', 'username', 'email', 'phone', 'companyName', 'companyAddress', 'role', 'active', 'isLockedBySuperAdmin', 'aiEnabled', 'subscriptionPlan', 'subscriptionExpiresAt', 'customEnabledModules', 'permissions', 'projectGrants', 'assignedProjectIds', 'ownerAdminId'] as $k) {
                if (array_key_exists($k, $input)) {
                    $mergedProfile[$k] = $input[$k];
                }
            }
            if ($isMasterAuthorized && array_key_exists('staffLimit', $input)) $mergedProfile['staffLimit'] = min(500, max(0, (int)$input['staffLimit']));
            $fields[] = 'app_profile_json = :aprof';
            $params['aprof'] = json_encode($mergedProfile, JSON_UNESCAPED_UNICODE);
        }

        $contextChanged=(array_key_exists('owner',$params) && (string)$params['owner']!==(string)($target['owner_admin_id']??''))
            || (isset($params['role']) && $params['role']!==$target['role']);
        if(isset($input['email']) && (string)$input['email']!==(string)($target['email']??''))$contextChanged=true;
        if($contextChanged && $rawOrHashedPwd==='')$fields[]='token_version = token_version + 1';

        if (empty($fields)) {
            jsonResponse(false, null, 'داده‌ای برای به‌روزرسانی ارسال نشده است.', 400);
        }

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $freshStmt = $db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $freshStmt->execute(['id' => $userId]);
        $freshRow = $freshStmt->fetch();

        logAudit($db, $currentUser['id'] ?? 'master', $currentUser['name'] ?? 'دفتر هفت', 'update', 'users', $userId, 'به‌روزرسانی تنظیمات و دسترسی‌های کاربر در دفتر هفت');

        jsonResponse(true, [
            'user' => $freshRow ? formatOffice7UserRow($freshRow, $hasAppProfile, $hasIsActive, $hasAiEnabled) : null
        ], 'تنظیمات و دسترسی‌های کاربر در دیتابیس MySQL با موفقیت ذخیره گردید.');
    }

    // ۲. تغییر وضعیت قفل (Lock/Unlock)
    if ($action === 'toggle_lock') {
        if(!$isMasterAuthorized)assertModulePermission($currentUser,'users','edit');
        $userId = trim((string)($input['userId'] ?? ($input['id'] ?? '')));
        $lockStatus = !empty($input['isLocked']) || !empty($input['isLockedBySuperAdmin']) ? 1 : 0;

        $stmt = $db->prepare("UPDATE users SET is_locked = :l WHERE id = :id AND is_master = 0" . ($isMasterAuthorized ? '' : ' AND owner_admin_id = :owner'));
        $lockParams = ['l' => $lockStatus, 'id' => $userId];
        if (!$isMasterAuthorized) $lockParams['owner'] = $currentUser['id'];
        $stmt->execute($lockParams);

        logAudit($db, $currentUser['id'] ?? 'master', 'دفتر هفت', $lockStatus ? 'lock' : 'unlock', 'users', $userId, $lockStatus ? 'مسدودسازی دسترسی' : 'رفع مسدودیت دسترسی');

        jsonResponse(true, ['isLocked' => (bool)$lockStatus, 'isLockedBySuperAdmin' => (bool)$lockStatus], 'وضعیت دسترسی کاربر تغییر کرد.');
    }

    // ۳. تغییر کلید امنیتی دروازه دفتر هفت
    if ($action === 'change_gate_key') {
        if (!$isMasterAuthorized) {
            jsonResponse(false, null, 'فقط مدیر ارشد دفتر هفت مجاز به تغییر کلید امنیتی است.', 403);
        }
        $newKey = trim((string)($input['newKey'] ?? ''));
        if (strlen($newKey) < 10) {
            jsonResponse(false, null, 'کلید دروازه امنیتی باید حداقل ۱۰ کاراکتر باشد.', 400);
        }

        $newHash = password_hash($newKey, PASSWORD_BCRYPT);
        $stmt = $db->prepare("
            INSERT INTO office7_tenant_configs (id, tenant_user_id, gate_key_hash, active_modules_json, accessible_tabs_json)
            VALUES ('cfg-master', 'usr-master-001', :hash, '{}', '[]')
            ON DUPLICATE KEY UPDATE gate_key_hash = :hash2
        ");
        $stmt->execute(['hash' => $newHash, 'hash2' => $newHash]);

        logAudit($db, $currentUser['id'] ?? 'master', 'دفتر هفت', 'update', 'security', 'gate_key', 'تغییر کلید امنیتی دفتر هفت');
        jsonResponse(true, null, 'کلید امنیتی دروازه دفتر هفت با موفقیت ارتقا یافت.');
    }

    // ۴. ذخیره خودکار پیش‌نویس‌های فرم‌های ناتمام هر ۶۰ ثانیه (جدا از اسناد مالی قطعی)
    if ($action === 'save_draft') {
        if (!$currentUser) {
            jsonResponse(false, null, 'احراز هویت الزامی است.', 401);
        }
        $tenantId = currentTenantId($currentUser);
        $formType = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($input['formType'] ?? 'general'));
        $projectId = trim((string)($input['projectId'] ?? ''));
        $draftKey = trim((string)($input['draftKey'] ?? ($currentUser['id'] . '::' . $projectId . '::' . $formType)));
        if ($projectId !== '') {
            assertTenantProjectAccess($db,$projectId,$tenantId);
            if(!canAccessProject($currentUser,$projectId))jsonResponse(false,null,'صلاحیت پروژهٔ پیش‌نویس را ندارید.',403);
        } elseif(hasProjectRestrictions($currentUser))jsonResponse(false,null,'پروژهٔ پیش‌نویس الزامی است.',403);
        if (strlen($draftKey) > 255) { jsonResponse(false, null, 'کلید پیش‌نویس بیش از حد طولانی است.', 400); }
        $visibleDraftKey = $draftKey;
        $draftKey = 'scoped:' . hash('sha256', $tenantId . ':' . $currentUser['id'] . ':' . $draftKey);
        $draftData = $input['data'] ?? [];
        $clientUpdatedAt = (int)($input['clientUpdatedAt'] ?? (round(microtime(true) * 1000)));
        $draftId = 'drf-' . substr(hash('sha256', $draftKey), 0, 32);

        // بررسی عدم بازنویسی پیش‌نویس جدیدتر با داده قدیمی‌تر
        $chk = $db->prepare("SELECT client_updated_at FROM form_drafts WHERE draft_key = :dk LIMIT 1");
        $chk->execute(['dk' => $draftKey]);
        $existingDraft = $chk->fetch();
        if ($existingDraft && (int)$existingDraft['client_updated_at'] > $clientUpdatedAt) {
            jsonResponse(true, ['skipped' => true, 'reason' => 'newer-draft-exists'], 'نسخه جدیدتری از این پیش‌نویس در سرور موجود است.');
        }

        $stmt = $db->prepare("
            INSERT INTO form_drafts (id, tenant_id, user_id, project_id, form_type, draft_key, client_draft_key, draft_json, client_updated_at)
            VALUES (:id, :tid, :uid, :pid, :ft, :dk, :ck, :dj, :cu)
            ON DUPLICATE KEY UPDATE
                draft_json = IF(VALUES(client_updated_at) >= client_updated_at, VALUES(draft_json), draft_json),
                client_updated_at = GREATEST(client_updated_at, VALUES(client_updated_at)),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'id' => $draftId,
            'tid' => $tenantId,
            'uid' => $currentUser['id'],
            'pid' => $projectId !== '' ? $projectId : null,
            'ft' => $formType,
            'dk' => $draftKey,
            'ck' => $visibleDraftKey,
            'dj' => json_encode($draftData, JSON_UNESCAPED_UNICODE),
            'cu' => $clientUpdatedAt,
        ]);

        jsonResponse(true, [
            'id' => $draftId,
            'draftKey' => $visibleDraftKey,
            'savedAt' => date('c'),
            'clientUpdatedAt' => $clientUpdatedAt,
        ], 'پیش‌نویس فرم به صورت خودکار ذخیره شد.');
    }

    // ۵. حذف پیش‌نویس پس از ثبت قطعی یا انصراف کاربر
    if ($action === 'delete_draft') {
        if (!$currentUser) {
            jsonResponse(false, null, 'احراز هویت الزامی است.', 401);
        }
        $draftKey = trim((string)($input['draftKey'] ?? ''));
        $formType = trim((string)($input['formType'] ?? ''));
        $projectId = trim((string)($input['projectId'] ?? ''));

        if($projectId!==''&&!canAccessProject($currentUser,$projectId))jsonResponse(false,null,'صلاحیت پروژهٔ پیش‌نویس را ندارید.',403);
        if($draftKey===''&&$projectId===''&&hasProjectRestrictions($currentUser))jsonResponse(false,null,'پروژهٔ پیش‌نویس الزامی است.',403);

        if ($draftKey !== '') {
            $draftKey = 'scoped:' . hash('sha256', currentTenantId($currentUser) . ':' . $currentUser['id'] . ':' . $draftKey);
            $scope=$db->prepare('SELECT project_id FROM form_drafts WHERE user_id=:uid AND draft_key=:dk');
            $scope->execute(['uid'=>$currentUser['id'],'dk'=>$draftKey]);$draft=$scope->fetch();
            if($draft&&!canAccessProject($currentUser,$draft['project_id']?:null))jsonResponse(false,null,'صلاحیت پروژهٔ پیش‌نویس را ندارید.',403);
            $stmt = $db->prepare("DELETE FROM form_drafts WHERE user_id = :uid AND tenant_id=:tid AND draft_key = :dk");
            $stmt->execute(['uid' => $currentUser['id'], 'tid'=>currentTenantId($currentUser), 'dk' => $draftKey]);
        } elseif ($formType !== '') {
            $stmt = $db->prepare("DELETE FROM form_drafts WHERE user_id = :uid AND tenant_id=:tid AND form_type = :ft AND (project_id = :pid OR :pid2 = '')");
            $stmt->execute(['uid' => $currentUser['id'], 'tid'=>currentTenantId($currentUser), 'ft' => $formType, 'pid' => $projectId, 'pid2' => $projectId]);
        }
        jsonResponse(true, ['deleted' => true], 'پیش‌نویس پاک‌سازی شد.');
    }

    // ۶. همگام‌سازی خودکار پروژه‌ها و رکوردهای کاری بین دستگاه‌ها با جلوگیری از بازنویسی داده جدیدتر
    if ($action === 'workspace_sync') {
        if (!$currentUser) {
            jsonResponse(false, null, 'احراز هویت الزامی است.', 401);
        }
        // حساب‌های مشاهده‌گر اجازه بازنویسی وضعیت کل شرکت را ندارند
        if (strtolower((string)($currentUser['role'] ?? 'viewer')) === 'viewer' && (int)($currentUser['is_master'] ?? 0) !== 1) {
            jsonResponse(false, null, 'حساب مشاهده‌گر مجاز به تغییر داده‌های سرور نیست.', 403);
        }

        if ((int)($currentUser['is_master'] ?? 0) !== 1 && $currentUser['role'] !== 'admin') { jsonResponse(false, null, 'همگام‌سازی کل شرکت برای این نقش مجاز نیست.', 403); }
        assertLegacyWorkspacePermission($currentUser,'edit');
        $tenantId = currentTenantId($currentUser);
        $clientUpdatedAt = (int)($input['clientUpdatedAt'] ?? round(microtime(true) * 1000));
        $projectsJson = isset($input['projects']) ? json_encode($input['projects'], JSON_UNESCAPED_UNICODE) : null;
        $recordsJson  = isset($input['records']) ? json_encode($input['records'], JSON_UNESCAPED_UNICODE) : null;

        $chk = $db->prepare("SELECT state_version, client_updated_at, projects_json, records_json FROM workspace_sync_states WHERE tenant_id = :tid LIMIT 1");
        $chk->execute(['tid' => $tenantId]);
        $existingState = $chk->fetch();

        if ($existingState && (int)$existingState['client_updated_at'] > $clientUpdatedAt && empty($input['force'])) {
            jsonResponse(true, [
                'conflict' => true,
                'stateVersion' => (int)$existingState['state_version'],
                'clientUpdatedAt' => (int)$existingState['client_updated_at'],
                'projects' => !empty($existingState['projects_json']) ? json_decode($existingState['projects_json'], true) : null,
                'records' => !empty($existingState['records_json']) ? json_decode($existingState['records_json'], true) : null,
            ], 'نسخه جدیدتری در دیتابیس سرور وجود دارد؛ اطلاعات سرور بازگردانده شد.');
        }

        $nextVersion = $existingState ? ((int)$existingState['state_version'] + 1) : 1;
        $stmt = $db->prepare("
            INSERT INTO workspace_sync_states (tenant_id, state_version, client_updated_at, projects_json, records_json, updated_by)
            VALUES (:tid, :ver, :cu, :pj, :rj, :ub)
            ON DUPLICATE KEY UPDATE
                state_version = VALUES(state_version),
                client_updated_at = VALUES(client_updated_at),
                projects_json = COALESCE(VALUES(projects_json), projects_json),
                records_json = COALESCE(VALUES(records_json), records_json),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'tid' => $tenantId,
            'ver' => $nextVersion,
            'cu'  => $clientUpdatedAt,
            'pj'  => $projectsJson,
            'rj'  => $recordsJson,
            'ub'  => $currentUser['id'],
        ]);

        jsonResponse(true, [
            'conflict' => false,
            'tenantId' => $tenantId,
            'stateVersion' => $nextVersion,
            'clientUpdatedAt' => $clientUpdatedAt,
            'savedAt' => date('c'),
        ], 'اطلاعات با موفقیت در دیتابیس مرکزی MySQL همگام‌سازی شد.');
    }

    // ۷. دروازه امنیتی هوش مصنوعی در سمت سرور (محافظت از کلیدهای سرور و مسدودسازی حساب‌های غیرمجاز)
    if ($action === 'ai_query') {
        if (!$currentUser) {
            jsonResponse(false, null, 'برای استفاده از هوش مصنوعی، ابتدا وارد حساب خود شوید.', 401);
        }
        assertAiPermission($currentUser);
        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '') {
            jsonResponse(false, null, 'متن پرسش ارسال نشده است.', 400);
        }
        logAudit($db, $currentUser['id'], $currentUser['name'], 'ai_query', 'ai_assistant', $currentUser['id'], mb_substr($prompt, 0, 120));
        jsonResponse(true, [
            'authorized' => true,
            'userId' => $currentUser['id'],
            'aiEnabled' => true,
            'serverTimestamp' => date('c'),
        ], 'دسترسی به هوش مصنوعی تأیید شد.');
    }
}

jsonResponse(false,null,'روش یا عملیات پشتیبانی نمی‌شود.', in_array($method,['GET','POST','PUT','DELETE'],true)?404:405);
