<?php
/**
 * =====================================================================
 * NSC ERP - SUBCONTRACTORS & WORK STATEMENTS API
 * مدیریت مقاطعه‌کاران، قراردادهای فی واحد (Phase 2) و صورت‌وضعیت کارکرد
 * =====================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$user = requireAuth();
$tenantId = currentTenantId($user);
$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Ensure Phase 2 & tenant_id columns exist on subcontractors table (backward-compatible auto-heal)
 */
function ensureSubcontractorPhase2Columns(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `subcontractors`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tenant_id', $cols, true)) {
            $pdo->exec("ALTER TABLE `subcontractors` ADD COLUMN `tenant_id` VARCHAR(64) NULL AFTER `id`");
        }
        if (!in_array('agreed_unit_rate', $cols, true)) {
            $pdo->exec("
                ALTER TABLE `subcontractors`
                  ADD COLUMN `work_category` VARCHAR(150) NULL AFTER `trade_specialty`,
                  ADD COLUMN `contract_date` DATE NULL AFTER `project_id`,
                  ADD COLUMN `unit` VARCHAR(60) NULL DEFAULT 'm²' AFTER `contract_date`,
                  ADD COLUMN `custom_unit` VARCHAR(80) NULL AFTER `unit`,
                  ADD COLUMN `agreed_unit_rate` DECIMAL(18, 2) NOT NULL DEFAULT 0.00 AFTER `custom_unit`,
                  ADD COLUMN `original_agreed_unit_rate` DECIMAL(18, 2) NOT NULL DEFAULT 0.00 AFTER `agreed_unit_rate`,
                  ADD COLUMN `contracted_quantity` DECIMAL(18, 3) NOT NULL DEFAULT 0.000 AFTER `original_agreed_unit_rate`,
                  ADD COLUMN `contract_total_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0.00 AFTER `contracted_quantity`,
                  ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN' AFTER `contract_total_amount`,
                  ADD COLUMN `exchange_rate` DECIMAL(12, 4) NOT NULL DEFAULT 70.0000 AFTER `currency`,
                  ADD COLUMN `payment_terms` TEXT NULL AFTER `remaining_balance`,
                  ADD COLUMN `rate_history_json` LONGTEXT NULL AFTER `payment_terms`
            ");
        }
    } catch (Throwable $e) {
        // Non-fatal if DB permissions restrict DDL
    }
}

ensureSubcontractorPhase2Columns($pdo);

// =====================================================================
// 1. دریافت لیست مقاطعه‌کاران (GET)
// =====================================================================
if ($method === 'GET') {
    requireScopedProject($user, 'contractors', $_GET['projectId'] ?? $_GET['project_id'] ?? null);

    $projectId = $_GET['project_id'] ?? $_GET['projectId'] ?? null;
    if ($projectId) {
        assertTenantProjectAccess($pdo, $projectId, $tenantId, false);
    }
    assertModulePermission($user, 'contractors', 'view', $projectId ? (string)$projectId : null);

    $sql = "SELECT s.*, p.name AS project_name
            FROM subcontractors s
            LEFT JOIN projects p ON s.project_id = p.id
            WHERE s.tenant_id = :tid AND (s.project_id IS NULL OR p.tenant_id = :tid2)";
    $params = [':tid' => $tenantId, ':tid2' => $tenantId];

    if ($projectId) {
        $sql .= " AND s.project_id = :proj";
        $params[':proj'] = $projectId;
    }
    $sql .= " ORDER BY s.remaining_balance DESC, s.contractor_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['rate_history'] = !empty($row['rate_history_json'])
            ? (json_decode($row['rate_history_json'], true) ?: [])
            : [];
    }
    unset($row);

    jsonResponse(['success' => true, 'subcontractors' => $rows]);
}

// =====================================================================
// 2. ثبت مقاطعه‌کار جدید با محاسبه خودکار مبلغ قرارداد بر اساس فی واحد (POST)
// =====================================================================
if ($method === 'POST') {
    requireRole($user, ['master', 'admin', 'project_manager', 'accountant']);
    $body = getJsonBody();
    $projectId = $body['project_id'] ?? $body['projectId'] ?? null;
    if ($projectId) {
        assertTenantProjectAccess($pdo, $projectId, $tenantId, false);
    }
    assertModulePermission($user, 'contractors', 'create', $projectId ? (string)$projectId : null);
    enforceIdempotencyGuard($pdo, $user, 'subcontractors:create', $body);

    $name = trim($body['contractor_name'] ?? $body['name'] ?? '');
    $trade = trim($body['trade_specialty'] ?? $body['work_category'] ?? $body['specialty'] ?? 'عمومی');
    $workCategory = trim($body['work_category'] ?? $trade);
    if ($name === '') {
        jsonResponse(['error' => 'نام مقاطعه‌کار الزامی است.'], 422);
    }

    $id = $body['id'] ?? generateUuid();
    $contractDate = $body['contract_date'] ?? date('Y-m-d');
    $unit = trim($body['unit'] ?? 'm²');
    $customUnit = !empty($body['custom_unit']) ? trim($body['custom_unit']) : null;
    $agreedUnitRate = round((float)($body['agreed_unit_rate'] ?? $body['agreedUnitRate'] ?? 0), 2);
    $contractedQuantity = round((float)($body['contracted_quantity'] ?? $body['contractedQuantity'] ?? 0), 3);
    $currency = strtoupper(trim($body['currency'] ?? 'AFN'));
    $exchangeRate = (float)($body['exchange_rate'] ?? $body['exchangeRate'] ?? 70);
    $paymentTerms = !empty($body['payment_terms'] ?? $body['paymentTerms']) ? trim($body['payment_terms'] ?? $body['paymentTerms']) : null;

    // Formula: Contract Total = Agreed Unit Rate × Contracted Quantity
    $calculatedTotal = ($agreedUnitRate > 0 && $contractedQuantity > 0)
        ? round($agreedUnitRate * $contractedQuantity, 2)
        : round((float)($body['contract_total_amount'] ?? $body['contract_value'] ?? 0), 2);

    $contractValue = (float)($body['contract_value'] ?? $calculatedTotal);
    $certifiedWork = (float)($body['certified_work_amount'] ?? 0);
    $paidAmount = (float)($body['paid_amount'] ?? 0);
    $retentionAmount = (float)($body['retention_amount'] ?? 0);
    $remaining = round($certifiedWork - $paidAmount - $retentionAmount, 2);

    $initialHistory = $agreedUnitRate > 0 ? [[
        'rate' => $agreedUnitRate,
        'currency' => $currency,
        'unit' => $unit,
        'effectiveDate' => $contractDate,
        'changedBy' => $user['name'] ?? 'Admin',
        'note' => 'Initial agreed contract rate',
    ]] : [];

    $stmt = $pdo->prepare("
        INSERT INTO subcontractors
        (id, tenant_id, contractor_name, trade_specialty, work_category, phone, project_id, contract_date,
         unit, custom_unit, agreed_unit_rate, original_agreed_unit_rate, contracted_quantity, contract_total_amount,
         currency, exchange_rate, contract_value, certified_work_amount, paid_amount, retention_amount,
         remaining_balance, payment_terms, rate_history_json, status)
        VALUES (:id, :tid, :name, :trade, :work_cat, :phone, :proj, :cdate,
                :unit, :cunit, :rate, :orig_rate, :qty, :ctotal,
                :curr, :exrate, :cval, :cert, :paid, :ret,
                :rem, :pterms, :rhist, 'active')
    ");
    $stmt->execute([
        ':id'        => $id,
        ':tid'       => $tenantId,
        ':name'      => $name,
        ':trade'     => $trade,
        ':work_cat'  => $workCategory,
        ':phone'     => $body['phone'] ?? null,
        ':proj'      => $projectId,
        ':cdate'     => $contractDate,
        ':unit'      => $unit,
        ':cunit'     => $customUnit,
        ':rate'      => $agreedUnitRate,
        ':orig_rate' => $agreedUnitRate,
        ':qty'       => $contractedQuantity,
        ':ctotal'    => $calculatedTotal,
        ':curr'      => $currency,
        ':exrate'    => $exchangeRate,
        ':cval'      => $contractValue,
        ':cert'      => $certifiedWork,
        ':paid'      => $paidAmount,
        ':ret'       => $retentionAmount,
        ':rem'       => $remaining,
        ':pterms'    => $paymentTerms,
        ':rhist'     => json_encode($initialHistory, JSON_UNESCAPED_UNICODE),
    ]);

    writeAuditLog($pdo, $user['id'], $user['name'], 'CREATE', 'SUBCONTRACTOR', $id, null, [
        'contractor_name' => $name,
        'unit' => $unit,
        'agreed_unit_rate' => $agreedUnitRate,
        'contracted_quantity' => $contractedQuantity,
        'contract_total_amount' => $calculatedTotal,
        'currency' => $currency,
    ]);
    jsonResponse([
        'success' => true,
        'id' => $id,
        'contract_total_amount' => $calculatedTotal,
        'original_agreed_unit_rate' => $agreedUnitRate,
    ], 201);
}

// =====================================================================
// 3. بروزرسانی صورت‌وضعیت و ویرایش مجاز نرخ با حفظ نرخ اولیه (PUT)
// =====================================================================
if ($method === 'PUT') {
    requireRole($user, ['master', 'admin', 'project_manager', 'accountant']);
    $body = getJsonBody();
    $id = $body['id'] ?? '';

    $oldStmt = $pdo->prepare("SELECT * FROM subcontractors WHERE id = :id AND tenant_id = :tid");
    $oldStmt->execute([':id' => $id, ':tid' => $tenantId]);
    $old = $oldStmt->fetch();
    if (!$old) {
        jsonResponse(['error' => 'مقاطعه‌کار یافت نشد یا دسترسی ندارید.'], 404);
    }
    if (!empty($old['project_id'])) {
        assertTenantProjectAccess($pdo, $old['project_id'], $tenantId, false);
    }
    assertModulePermission($user, 'contractors', 'edit', !empty($old['project_id']) ? (string)$old['project_id'] : null);
    enforceIdempotencyGuard($pdo, $user, 'subcontractors:update', $body);

    $agreedUnitRate = isset($body['agreed_unit_rate']) || isset($body['agreedUnitRate'])
        ? round((float)($body['agreed_unit_rate'] ?? $body['agreedUnitRate']), 2)
        : (float)($old['agreed_unit_rate'] ?? 0);

    // Preserve original_agreed_unit_rate permanently
    $oldOrigRate = (float)($old['original_agreed_unit_rate'] ?? 0);
    $originalAgreedUnitRate = $oldOrigRate > 0
        ? $oldOrigRate
        : ((float)($old['agreed_unit_rate'] ?? 0) > 0 ? (float)$old['agreed_unit_rate'] : $agreedUnitRate);

    $contractedQuantity = isset($body['contracted_quantity']) || isset($body['contractedQuantity'])
        ? round((float)($body['contracted_quantity'] ?? $body['contractedQuantity']), 3)
        : (float)($old['contracted_quantity'] ?? 0);

    $unit = $body['unit'] ?? $old['unit'] ?? 'm²';
    $currency = strtoupper(trim($body['currency'] ?? $old['currency'] ?? 'AFN'));

    $contractTotalAmount = ($agreedUnitRate > 0 && $contractedQuantity > 0)
        ? round($agreedUnitRate * $contractedQuantity, 2)
        : (float)($body['contract_total_amount'] ?? $old['contract_total_amount'] ?? $old['contract_value']);

    // Append to rate_history_json if rate changed
    $history = !empty($old['rate_history_json']) ? (json_decode($old['rate_history_json'], true) ?: []) : [];
    if ($agreedUnitRate > 0 && $agreedUnitRate !== (float)($old['agreed_unit_rate'] ?? 0)) {
        if (empty($history) && $originalAgreedUnitRate > 0) {
            $history[] = [
                'rate' => $originalAgreedUnitRate,
                'currency' => $old['currency'] ?? $currency,
                'unit' => $old['unit'] ?? $unit,
                'effectiveDate' => $old['contract_date'] ?? substr((string)$old['created_at'], 0, 10),
                'changedBy' => 'System',
                'note' => 'Original agreed contract rate',
            ];
        }
        $history[] = [
            'rate' => $agreedUnitRate,
            'currency' => $currency,
            'unit' => $unit,
            'effectiveDate' => date('Y-m-d'),
            'changedBy' => $user['name'] ?? 'Admin',
            'note' => $body['rate_change_note'] ?? 'Authorized rate update',
        ];
    }

    $cert = (float)($body['certified_work_amount'] ?? $old['certified_work_amount']);
    $paid = (float)($body['paid_amount'] ?? $old['paid_amount']);
    $ret  = (float)($body['retention_amount'] ?? $old['retention_amount']);
    $rem  = round($cert - $paid - $ret, 2);

    $stmt = $pdo->prepare("
        UPDATE subcontractors SET
            contractor_name = :name,
            trade_specialty = :trade,
            work_category = :work_cat,
            phone = :phone,
            contract_date = :cdate,
            unit = :unit,
            custom_unit = :cunit,
            agreed_unit_rate = :rate,
            original_agreed_unit_rate = :orig_rate,
            contracted_quantity = :qty,
            contract_total_amount = :ctotal,
            currency = :curr,
            exchange_rate = :exrate,
            contract_value = :cval,
            certified_work_amount = :cert,
            paid_amount = :paid,
            retention_amount = :ret,
            remaining_balance = :rem,
            payment_terms = :pterms,
            rate_history_json = :rhist,
            status = :st,
            tenant_id = :tid
        WHERE id = :id AND tenant_id = :tid2
    ");
    $stmt->execute([
        ':id'        => $id,
        ':tid'       => $tenantId,
        ':tid2'      => $tenantId,
        ':name'      => $body['contractor_name'] ?? $old['contractor_name'],
        ':trade'     => $body['trade_specialty'] ?? $old['trade_specialty'],
        ':work_cat'  => $body['work_category'] ?? $old['work_category'] ?? $old['trade_specialty'],
        ':phone'     => $body['phone'] ?? $old['phone'],
        ':cdate'     => $body['contract_date'] ?? $old['contract_date'],
        ':unit'      => $unit,
        ':cunit'     => $body['custom_unit'] ?? $old['custom_unit'],
        ':rate'      => $agreedUnitRate,
        ':orig_rate' => $originalAgreedUnitRate,
        ':qty'       => $contractedQuantity,
        ':ctotal'    => $contractTotalAmount,
        ':curr'      => $currency,
        ':exrate'    => $body['exchange_rate'] ?? $old['exchange_rate'] ?? 70,
        ':cval'      => $body['contract_value'] ?? $contractTotalAmount,
        ':cert'      => $cert,
        ':paid'      => $paid,
        ':ret'       => $ret,
        ':rem'       => $rem,
        ':pterms'    => $body['payment_terms'] ?? $old['payment_terms'],
        ':rhist'     => json_encode($history, JSON_UNESCAPED_UNICODE),
        ':st'        => $body['status'] ?? $old['status'],
    ]);

    writeAuditLog($pdo, $user['id'], $user['name'], 'UPDATE', 'SUBCONTRACTOR', $id, $old, $body);
    jsonResponse([
        'success' => true,
        'remaining_balance' => $rem,
        'agreed_unit_rate' => $agreedUnitRate,
        'original_agreed_unit_rate' => $originalAgreedUnitRate,
        'contract_total_amount' => $contractTotalAmount,
        'rate_history' => $history,
    ]);
}

// =====================================================================
// 4. حذف مقاطعه‌کار (DELETE)
// =====================================================================
if ($method === 'DELETE') {
    requireRole($user, ['master', 'admin']);
    $id = $_GET['id'] ?? '';
    $oldStmt = $pdo->prepare("SELECT * FROM subcontractors WHERE id = :id AND tenant_id = :tid");
    $oldStmt->execute([':id' => $id, ':tid' => $tenantId]);
    $old = $oldStmt->fetch();
    if (!$old) {
        jsonResponse(['error' => 'مقاطعه‌کار یافت نشد یا دسترسی ندارید.'], 404);
    }
    if (!empty($old['project_id'])) {
        assertTenantProjectAccess($pdo, $old['project_id'], $tenantId, false);
    }
    assertModulePermission($user, 'contractors', 'del', !empty($old['project_id']) ? (string)$old['project_id'] : null);

    $stmt = $pdo->prepare("DELETE FROM subcontractors WHERE id = :id AND tenant_id = :tid");
    $stmt->execute([':id' => $id, ':tid' => $tenantId]);
    jsonResponse(['success' => true]);
}

jsonResponse(false,null,'روش یا عملیات پشتیبانی نمی‌شود.', in_array($method,['GET','POST','PUT','DELETE'],true)?404:405);
