<?php
/**
 * NSC Apartment Units & Commercial Pre-sales API
 * Manages Units, Prices (USD & AFN), Client Information & Outstanding Receivables
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_once __DIR__.'/validation.php';
sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
$user = authenticateUser(false);
$tenantId = currentTenantId($user);

if ($method === 'GET') {
    requireScopedProject($user, 'apartments', $_GET['projectId'] ?? null);

    $projectId = $_GET['projectId'] ?? null;
    assertModulePermission($user, 'sales', 'view', $projectId ? (string)$projectId : null);
    $sql = "SELECT u.*, p.name as project_name, c.client_name, c.phone as client_phone
            FROM apartment_units u
            LEFT JOIN projects p ON u.project_id = p.id
            LEFT JOIN clients c ON u.client_id = c.id AND c.tenant_id = u.tenant_id";
    $params = ['tid' => $tenantId];
    $sql .= " WHERE u.tenant_id = :tid";
    if ($projectId) {
        $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
        $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
        $sql .= " AND u.project_id = :pid";
        $params['pid'] = $projectId;
    }
    $sql .= " ORDER BY u.floor_number ASC, u.unit_number ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(true, ['units' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $input = getJsonInput();
    assertModulePermission($user, 'sales', 'view', $input['projectId'] ?? null);
    enforceIdempotencyGuard($db, $user, 'apartments:post', $input);

    $id = $input['id'] ?? generateUuid();
    $projectId = $input['projectId'] ?? '';
    $unitNumber = trim($input['unitNumber'] ?? '');
    $floor = (int)($input['floorNumber'] ?? 1);
    $area = (float)($input['areaSqm'] ?? 0);
    $unitType = $input['unitType'] ?? 'apartment';
    $priceUsd = moneyMinor($input['priceUsd'] ?? 0)/100;
    $priceAfn = moneyMinor($input['priceAfn'] ?? 0)/100;
    $totalPaid = moneyMinor($input['totalPaidAfn'] ?? 0)/100;
    if($totalPaid>$priceAfn)jsonResponse(false,null,'پرداخت بیشتر از قیمت افغانی واحد است.',400);
    $balance = round($priceAfn - $totalPaid,2);
    $status = $input['status'] ?? 'available';

    if (empty($projectId) || empty($unitNumber)) {
        jsonResponse(false, null, 'شناسه پروژه و شماره پلاک الزامی هستند.', 400);
    }

    // امنیت: پروژه و پلاک موجود باید متعلق به همین شرکت باشند
    $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
    $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
    if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
    $chkStmt = $db->prepare("SELECT tenant_id, project_id FROM apartment_units WHERE id = :id LIMIT 1");
    $chkStmt->execute(['id' => $id]);
    $existing = $chkStmt->fetch();
    if ($existing && (string)$existing['tenant_id'] !== (string)$tenantId) {
        jsonResponse(false, null, 'پلاک یافت نشد یا دسترسی ندارید.', 404);
    }

    assertModulePermission($user,'sales',$existing?'edit':'create',$projectId ?: null);
    if ($existing) assertModulePermission($user, 'apartments', 'edit', $existing['project_id'] ?: null);
    $stmt = $db->prepare("
        INSERT INTO apartment_units (
            id, tenant_id, project_id, unit_number, floor_number, area_sqm,
            unit_type, price_usd, price_afn, total_paid_afn,
            balance_receivable_afn, status, notes
        ) VALUES (
            :id, :tid, :pid, :uno, :floor, :area,
            :type, :pusd, :pafn, :paid,
            :bal, :status, :notes
        ) ON DUPLICATE KEY UPDATE
            floor_number = VALUES(floor_number),
            area_sqm = VALUES(area_sqm),
            unit_type = VALUES(unit_type),
            price_usd = VALUES(price_usd),
            price_afn = VALUES(price_afn),
            total_paid_afn = VALUES(total_paid_afn),
            balance_receivable_afn = VALUES(balance_receivable_afn),
            status = VALUES(status),
            notes = VALUES(notes)
    ");

    $stmt->execute([
        'id' => $id,
        'tid' => $tenantId,
        'pid' => $projectId,
        'uno' => $unitNumber,
        'floor' => $floor,
        'area' => $area,
        'type' => $unitType,
        'pusd' => $priceUsd,
        'pafn' => $priceAfn,
        'paid' => $totalPaid,
        'bal' => $balance,
        'status' => $status,
        'notes' => $input['notes'] ?? null
    ]);

    logAudit($db, $user['id'], $user['name'], 'update', 'apartments', $id, "ثبت/ویرایش پلاک: $unitNumber");
    jsonResponse(true, ['id' => $id, 'balanceReceivable' => $balance], 'اطلاعات واحد با موفقیت ثبت شد.');
}

jsonResponse(false,null,'روش یا عملیات پشتیبانی نمی‌شود.', in_array($method,['GET','POST','PUT','DELETE'],true)?404:405);
