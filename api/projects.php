<?php
/**
 * NSC Project Master & Budget API
 * Manages Projects, Budgets, and Cost Allocation
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
$user = authenticateUser(false);
$tenantId = currentTenantId($user);

if ($method === 'GET') {
    $projectId = $_GET['id'] ?? null;

    if ($projectId) {
        assertModulePermission($user, 'projects', 'view', $projectId);
        // دریافت مشخصات یک پروژه همراه با بودجه‌ها و مصارف واقعی
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = :id AND tenant_id = :tid LIMIT 1");
        $stmt->execute(['id' => $projectId, 'tid' => $tenantId]);
        $project = $stmt->fetch();

        if (!$project) {
            jsonResponse(false, null, 'پروژه مورد نظر یافت نشد.', 404);
        }

        // بودجه‌ها
        $bStmt = $db->prepare("SELECT * FROM project_budgets WHERE project_id = :pid AND tenant_id = :tid");
        $bStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        $budgets = $bStmt->fetchAll();

        // محاسبه مجموع مصارف واقعی پرداخت‌شده برای این پروژه
        $expStmt = $db->prepare("
            SELECT expense_category, SUM(amount_in_base_afn) as total_spent
            FROM payment_vouchers
            WHERE project_id = :pid AND tenant_id = :tid AND payment_status != 'cancelled'
            GROUP BY expense_category
        ");
        $expStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        $actualCosts = $expStmt->fetchAll();

        // مجموع عواید دریافتی
        $revStmt = $db->prepare("
            SELECT SUM(amount_in_base_afn) as total_revenue
            FROM revenue_entries
            WHERE project_id = :pid AND tenant_id = :tid
        ");
        $revStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        $revRow = $revStmt->fetch();
        $totalRevenue = (float)($revRow['total_revenue'] ?? 0);

        jsonResponse(true, [
            'project' => $project,
            'budgets' => $budgets,
            'actualCosts' => $actualCosts,
            'totalRevenue' => $totalRevenue
        ]);
    } else {
        // لیست تمام پروژه‌ها
        assertModulePermission($user, 'projects', 'view');
        $stmt = $db->prepare("
            SELECT p.*,
                   COALESCE((SELECT SUM(amount_in_base_afn) FROM payment_vouchers WHERE project_id = p.id AND tenant_id = :tid1 AND payment_status != 'cancelled'), 0) as total_spent,
                   COALESCE((SELECT SUM(amount_in_base_afn) FROM revenue_entries WHERE project_id = p.id AND tenant_id = :tid2), 0) as total_income
            FROM projects p
            WHERE p.tenant_id = :tid3
            ORDER BY p.created_at DESC
        ");
        $stmt->execute(['tid1' => $tenantId, 'tid2' => $tenantId, 'tid3' => $tenantId]);
        $projects = array_values(array_filter($stmt->fetchAll(), fn($p) => canAccessModule($user, 'projects', 'view', (string)$p['id'])));
        jsonResponse(true, ['projects' => $projects]);
    }
}

if ($method === 'POST') {
    $input = getJsonInput();
    $action = $_GET['action'] ?? 'save_project';
    $permAction = $action === 'save_budget' ? 'edit' : 'view';
    assertModulePermission($user, $action === 'save_budget' ? 'budget' : 'projects', $permAction, $input['projectId'] ?? $input['id'] ?? null);
    enforceIdempotencyGuard($db, $user, 'projects:' . $action, $input);

    if ($action === 'save_project') {
        $id = $input['id'] ?? generateUuid();
        $projectCode = trim($input['projectCode'] ?? $input['code'] ?? 'P-' . rand(100, 999));
        $name = trim($input['name'] ?? '');

        if (empty($name)) {
            jsonResponse(false, null, 'نام پروژه الزامی است.', 400);
        }

        // امنیت: رکورد موجود باید متعلق به همین شرکت باشد (جلوگیری از بازنویسی معلومات دیگران)
        $chkStmt = $db->prepare("SELECT tenant_id FROM projects WHERE id = :id LIMIT 1");
        $chkStmt->execute(['id' => $id]);
        $existing = $chkStmt->fetch();
        if ($existing && (string)$existing['tenant_id'] !== (string)$tenantId) {
            jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404);
        }

        assertModulePermission($user, 'projects', $existing ? 'edit' : 'create', (string)$id);

        $stmt = $db->prepare("
            INSERT INTO projects (
                id, tenant_id, project_code, name, client_name, contract_value,
                approved_budget, currency, location, start_date, expected_finish,
                status, project_manager, total_area_sqm, floors_count, notes, created_by
            ) VALUES (
                :id, :tid, :code, :name, :client, :contract,
                :budget, :curr, :loc, :sdate, :edate,
                :status, :pm, :area, :floors, :notes, :created_by
            ) ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                client_name = VALUES(client_name),
                contract_value = VALUES(contract_value),
                approved_budget = VALUES(approved_budget),
                currency = VALUES(currency),
                location = VALUES(location),
                start_date = VALUES(start_date),
                expected_finish = VALUES(expected_finish),
                total_area_sqm = VALUES(total_area_sqm),
                floors_count = VALUES(floors_count),
                status = VALUES(status),
                project_manager = VALUES(project_manager),
                notes = VALUES(notes)
        ");

        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'code' => $projectCode,
            'name' => $name,
            'client' => $input['clientName'] ?? null,
            'contract' => (float)($input['contractValue'] ?? 0),
            'budget' => (float)($input['approvedBudget'] ?? $input['budget'] ?? 0),
            'curr' => $input['currency'] ?? 'AFN',
            'loc' => $input['location'] ?? null,
            'sdate' => !empty($input['startDate']) ? $input['startDate'] : null,
            'edate' => !empty($input['expectedFinish']) ? $input['expectedFinish'] : null,
            'status' => $input['status'] ?? 'active',
            'pm' => $input['projectManager'] ?? null,
            'area' => (float)($input['totalAreaSqm'] ?? 0),
            'floors' => (int)($input['floorsCount'] ?? 0),
            'notes' => $input['notes'] ?? null,
            'created_by' => $user['id']
        ]);

        logAudit($db, $user['id'], $user['name'], 'update', 'projects', $id, "ثبت/ویرایش پروژه: $name");
        jsonResponse(true, ['id' => $id, 'projectCode' => $projectCode], 'اطلاعات پروژه با موفقیت ثبت شد.');
    }

    if ($action === 'save_budget') {
        $id = $input['id'] ?? generateUuid();
        $projectId = $input['projectId'] ?? '';
        $categoryCode = $input['categoryCode'] ?? 'materials';
        $categoryName = $input['categoryName'] ?? 'مصالح ساختمانی';
        $amount = (float)($input['budgetedAmount'] ?? 0);

        if (empty($projectId)) {
            jsonResponse(false, null, 'شناسه پروژه مشخص نیست.', 400);
        }

        // امنیت: پروژه و سرفصل موجود باید متعلق به همین شرکت باشند
        $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
        $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
        $chkStmt = $db->prepare("SELECT tenant_id, project_id FROM project_budgets WHERE id = :id LIMIT 1");
        $chkStmt->execute(['id' => $id]);
        $existing = $chkStmt->fetch();
        if ($existing && (string)$existing['tenant_id'] !== (string)$tenantId) {
            jsonResponse(false, null, 'سرفصل بودجه یافت نشد یا دسترسی ندارید.', 404);
        }

    if ($existing) assertModulePermission($user, 'budget', 'edit', $existing['project_id'] ?: null);
        $stmt = $db->prepare("
            INSERT INTO project_budgets (id, tenant_id, project_id, category_code, category_name, budgeted_amount, currency)
            VALUES (:id, :tid, :pid, :cat, :cname, :amt, 'AFN')
            ON DUPLICATE KEY UPDATE
                budgeted_amount = VALUES(budgeted_amount),
                category_name = VALUES(category_name)
        ");
        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'pid' => $projectId,
            'cat' => $categoryCode,
            'cname' => $categoryName,
            'amt' => $amount
        ]);

        jsonResponse(true, ['id' => $id], 'سرفصل بودجه با موفقیت بروزرسانی شد.');
    }
}

jsonResponse(false,null,'روش یا عملیات پشتیبانی نمی‌شود.', in_array($method,['GET','POST','PUT','DELETE'],true)?404:405);
