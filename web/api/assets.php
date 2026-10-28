<?php
/**
 * NSC Assets, Machinery & Equipment Register API
 * Manages Heavy Machinery, Tools, Book Value & Depreciation Calculations
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
$user = authenticateUser(false);
$tenantId = currentTenantId($user);

if ($method === 'GET') {
    $projectId = $_GET['projectId'] ?? null;
    assertModulePermission($user, 'assets', 'view', $projectId ? (string)$projectId : null);
    $sql = "SELECT a.*, p.name as project_name 
            FROM assets_machinery a 
            LEFT JOIN projects p ON a.assigned_project_id = p.id";
    $params = ['tid' => $tenantId];
    $sql .= " WHERE a.tenant_id = :tid";
    if ($projectId) {
        $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
        $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
        $sql .= " AND a.assigned_project_id = :pid";
        $params['pid'] = $projectId;
    }
    $sql .= " ORDER BY a.purchase_date DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(true, ['assets' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $input = getJsonInput();
    assertModulePermission($user, 'assets', 'create', $input['assignedProjectId'] ?? null);
    enforceIdempotencyGuard($db, $user, 'assets:post', $input);

    $id = $input['id'] ?? generateUuid();
    $tag = trim($input['assetTag'] ?? 'AST-' . rand(100, 999));
    $name = trim($input['assetName'] ?? $input['name'] ?? '');
    $category = trim($input['category'] ?? 'تجهیزات عمومی');
    $projectId = $input['assignedProjectId'] ?? null;
    $purchaseDate = !empty($input['purchaseDate']) ? $input['purchaseDate'] : date('Y-m-d');
    $cost = (float)($input['purchaseCost'] ?? 0);
    $salvage = (float)($input['salvageValue'] ?? 0);
    $lifeYears = max(1, (int)($input['usefulLifeYears'] ?? 5));
    $status = $input['status'] ?? 'active';

    // محاسبه استهلاک خط مستقیم (Straight-line Depreciation)
    $yearsElapsed = max(0, (time() - strtotime($purchaseDate)) / (365 * 86400));
    $annualDepreciation = ($cost - $salvage) / $lifeYears;
    $totalDepreciation = min($cost - $salvage, $annualDepreciation * $yearsElapsed);
    $bookValue = max($salvage, $cost - $totalDepreciation);

    if (empty($name) || $cost <= 0) {
        jsonResponse(false, null, 'نام دارایی و بهای تمام‌شده الزامی است.', 400);
    }

    // امنیت: پروژه (در صورت انتخاب) و دارایی موجود باید متعلق به همین شرکت باشند
    if (!empty($projectId)) {
        $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
        $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
    }
    $chkStmt = $db->prepare("SELECT tenant_id FROM assets_machinery WHERE id = :id LIMIT 1");
    $chkStmt->execute(['id' => $id]);
    $existing = $chkStmt->fetch();
    if ($existing && (string)$existing['tenant_id'] !== (string)$tenantId) {
        jsonResponse(false, null, 'دارایی یافت نشد یا دسترسی ندارید.', 404);
    }

    $stmt = $db->prepare("
        INSERT INTO assets_machinery (
            id, tenant_id, asset_tag_code, asset_name, category, assigned_project_id,
            purchase_date, purchase_cost, current_book_value, salvage_value,
            useful_life_years, serial_or_engine_no, location_site, status, notes
        ) VALUES (
            :id, :tid, :tag, :name, :cat, :pid,
            :pdate, :cost, :bval, :salvage,
            :life, :serial, :loc, :status, :notes
        ) ON DUPLICATE KEY UPDATE 
            asset_name = VALUES(asset_name),
            category = VALUES(category),
            assigned_project_id = VALUES(assigned_project_id),
            current_book_value = VALUES(current_book_value),
            status = VALUES(status),
            notes = VALUES(notes)
    ");

    $stmt->execute([
        'id' => $id,
        'tid' => $tenantId,
        'tag' => $tag,
        'name' => $name,
        'cat' => $category,
        'pid' => $projectId,
        'pdate' => $purchaseDate,
        'cost' => $cost,
        'bval' => $bookValue,
        'salvage' => $salvage,
        'life' => $lifeYears,
        'serial' => $input['serialNumber'] ?? null,
        'loc' => $input['location'] ?? null,
        'status' => $status,
        'notes' => $input['notes'] ?? null
    ]);

    logAudit($db, $user['id'], $user['name'], 'update', 'assets', $id, "ثبت/ویرایش دارایی: $name");
    jsonResponse(true, ['id' => $id, 'currentBookValue' => $bookValue], 'اطلاعات دارایی و ارزش دفتری با موفقیت ثبت شد.');
}
