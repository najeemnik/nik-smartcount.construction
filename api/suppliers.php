<?php
/**
 * =====================================================================
 * NSC ERP - SUPPLIERS & VENDOR SUB-LEDGER API + RATE MEMORY (Phase 2 & 10 Hardened)
 * مدیریت تأمین‌کنندگان، حافظه نرخ اجناس و صورت‌حساب معین (تدارکات و خرید)
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
 * Ensure supplier_product_rates table & tenant_id columns exist (backward-compatible auto-heal)
 */
function ensureSupplierRatesTable(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `supplier_product_rates` (
              `id` VARCHAR(64) NOT NULL PRIMARY KEY,
              `tenant_id` VARCHAR(64) NULL,
              `project_id` VARCHAR(36) NULL,
              `supplier_id` VARCHAR(36) NULL,
              `supplier_name` VARCHAR(200) NOT NULL,
              `product` VARCHAR(200) NOT NULL,
              `unit` VARCHAR(60) NOT NULL,
              `last_agreed_rate` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
              `currency` VARCHAR(10) NOT NULL DEFAULT 'AFN',
              `effective_date` DATE NOT NULL,
              `rate_key` VARCHAR(512) NOT NULL UNIQUE,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_spr_supplier` (`supplier_id`, `supplier_name`),
              INDEX `idx_spr_product` (`product`, `unit`, `currency`),
              INDEX `idx_spr_project` (`project_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $sCols = $pdo->query("SHOW COLUMNS FROM `suppliers`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tenant_id', $sCols, true)) {
            $pdo->exec("ALTER TABLE `suppliers` ADD COLUMN `tenant_id` VARCHAR(64) NULL AFTER `id`");
        }
        $rCols = $pdo->query("SHOW COLUMNS FROM `supplier_product_rates`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tenant_id', $rCols, true)) {
            $pdo->exec("ALTER TABLE `supplier_product_rates` ADD COLUMN `tenant_id` VARCHAR(64) NULL AFTER `id`");
        }
    } catch (Throwable $e) {
        // Non-fatal if DB permissions restrict DDL
    }
}

ensureSupplierRatesTable($pdo);

// =====================================================================
// 1. دریافت لیست تأمین‌کنندگان، حافظه نرخ اجناس یا صورت‌حساب معین (GET)
// =====================================================================
if ($method === 'GET') {
    requireScopedProject($user, 'suppliers', $_GET['projectId'] ?? $_GET['project_id'] ?? null);

    $projectId = $_GET['project_id'] ?? $_GET['projectId'] ?? null;
    if ($projectId) {
        assertTenantProjectAccess($pdo, $projectId, $tenantId, false);
    }
    assertModulePermission($user, 'suppliers', 'view', $projectId ? (string)$projectId : null);

    if(hasProjectRestrictions($user) && ($_GET['action']??'')!=='rates')jsonResponse(false,null,'صورت‌حساب سراسری تأمین‌کنندگان برای این حساب مجاز نیست.',403);

    // Phase 2 — TASK 2: GET supplier product rate memory
    if (isset($_GET['action']) && $_GET['action'] === 'rates') {
        $supplierId = $_GET['supplier_id'] ?? null;
        $supplierName = $_GET['supplier_name'] ?? null;
        $product = $_GET['product'] ?? null;
        $unit = $_GET['unit'] ?? null;
        $currency = $_GET['currency'] ?? null;

        $sql = "SELECT * FROM supplier_product_rates WHERE tenant_id = :tid";
        $params = [':tid' => $tenantId];
        if($projectId){$sql.=' AND project_id=:scope_pid';$params[':scope_pid']=$projectId;}
        if ($supplierId) {
            $sql .= " AND supplier_id = :sid";
            $params[':sid'] = $supplierId;
        } elseif ($supplierName) {
            $sql .= " AND LOWER(TRIM(supplier_name)) = LOWER(TRIM(:sname))";
            $params[':sname'] = $supplierName;
        }
        if ($product) {
            $sql .= " AND LOWER(TRIM(product)) = LOWER(TRIM(:prod))";
            $params[':prod'] = $product;
        }
        if ($unit) {
            $sql .= " AND LOWER(TRIM(unit)) = LOWER(TRIM(:unit))";
            $params[':unit'] = $unit;
        }
        if ($currency) {
            $sql .= " AND UPPER(TRIM(currency)) = UPPER(TRIM(:curr))";
            $params[':curr'] = $currency;
        }
        $sql .= " ORDER BY effective_date DESC, updated_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse([
            'success' => true,
            'rates' => $stmt->fetchAll(),
        ]);
    }

    // اگر درخواست کاردکس یا معین یک فروشنده خاص باشد
    if (isset($_GET['action']) && $_GET['action'] === 'ledger' && !empty($_GET['supplier_id'])) {
        $sid = $_GET['supplier_id'];

        $supStmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = :id AND tenant_id = :tid");
        $supStmt->execute([':id' => $sid, ':tid' => $tenantId]);
        $supplier = $supStmt->fetch();

        if (!$supplier) {
            jsonResponse(['error' => 'تأمین‌کننده یافت نشد یا دسترسی ندارید.'], 404);
        }

        // فاکتورهای خرید از این فروشنده (محدود به پروژه‌های همین شرکت)
        $poStmt = $pdo->prepare("
            SELECT po.*, p.name AS project_name
            FROM material_procurements po
            INNER JOIN projects p ON po.project_id = p.id
            WHERE po.supplier_id = :sid AND p.tenant_id = :tid
            ORDER BY po.purchase_date DESC
        ");
        $poStmt->execute([':sid' => $sid, ':tid' => $tenantId]);

        // پرداخت‌های انجام شده به این فروشنده (محدود به پروژه‌های همین شرکت)
        $payStmt = $pdo->prepare("
            SELECT pv.*, p.name AS project_name
            FROM payment_vouchers pv
            INNER JOIN projects p ON pv.project_id = p.id
            WHERE pv.payee_type = 'supplier' AND pv.payee_id = :sid AND p.tenant_id = :tid
            ORDER BY pv.payment_date DESC
        ");
        $payStmt->execute([':sid' => $sid, ':tid' => $tenantId]);

        // حافظه نرخ اجناس این فروشنده
        $rateStmt = $pdo->prepare("
            SELECT * FROM supplier_product_rates
            WHERE tenant_id = :tid
              AND (supplier_id = :sid OR LOWER(TRIM(supplier_name)) = LOWER(TRIM(:sname)))
            ORDER BY effective_date DESC, updated_at DESC
        ");
        $rateStmt->execute([':tid' => $tenantId, ':sid' => $sid, ':sname' => $supplier['supplier_name']]);

        jsonResponse([
            'success'          => true,
            'supplier'         => $supplier,
            'purchase_orders'  => $poStmt->fetchAll(),
            'payment_vouchers' => $payStmt->fetchAll(),
            'product_rates'    => $rateStmt->fetchAll(),
        ]);
    }

    // دریافت لیست کامل همه تأمین‌کنندگان شرکت
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE tenant_id = :tid ORDER BY current_payable_balance DESC, supplier_name ASC");
    $stmt->execute([':tid' => $tenantId]);
    jsonResponse([
        'success'   => true,
        'suppliers' => $stmt->fetchAll(),
    ]);
}

// =====================================================================
// 2. ثبت تأمین‌کننده جدید یا ثبت/بروزرسانی نرخ جنس در حافظه (POST)
// =====================================================================
if ($method === 'POST') {
    requireRole($user, ['master', 'admin', 'project_manager', 'accountant', 'procurement']);
    $body = getJsonBody();
    $projectId = !empty($body['projectId'] ?? $body['project_id']) ? trim($body['projectId'] ?? $body['project_id']) : null;
    if ($projectId) {
        assertTenantProjectAccess($pdo, $projectId, $tenantId, false);
    }
    $savingRate=(isset($_GET['action'])&&$_GET['action']==='rates')||(($body['action']??'')==='save_rate');
    assertModulePermission($user,'suppliers',$savingRate?'view':'create',$projectId);
    enforceIdempotencyGuard($pdo, $user, 'suppliers:post', $body);

    // Phase 2 — TASK 2: Save or update supplier product rate memory
    if ($savingRate) {
        $supplierId = !empty($body['supplierId'] ?? $body['supplier_id']) ? trim($body['supplierId'] ?? $body['supplier_id']) : null;
        $supplierName = trim($body['supplierName'] ?? $body['supplier_name'] ?? '');
        $product = trim($body['product'] ?? '');
        $unit = trim($body['unit'] ?? '');
        $rate = round((float)($body['lastAgreedRate'] ?? $body['last_agreed_rate'] ?? $body['rate'] ?? 0), 2);
        $currency = strtoupper(trim($body['currency'] ?? 'AFN'));
        $effectiveDate = trim($body['effectiveDate'] ?? $body['effective_date'] ?? date('Y-m-d'));

        if ($supplierName === '' || $product === '' || $unit === '' || $rate <= 0) {
            jsonResponse(['error' => 'نام تأمین‌کننده، نام جنس، واحد و نرخ معتبر الزامی است.'], 422);
        }

        // Scope BOTH unique keys to the authenticated tenant and project. Never trust a supplied ID.
        if ($supplierId !== null) {
            $supplierCheck=$pdo->prepare('SELECT id FROM suppliers WHERE id=:id AND tenant_id=:tid');
            $supplierCheck->execute(['id'=>$supplierId,'tid'=>$tenantId]);
            if(!$supplierCheck->fetch())jsonResponse(false,null,'تأمین‌کننده متعلق به این شرکت نیست.',403);
        }
        $rateKey='v2:'.hash('sha256',json_encode([$tenantId,$projectId,(function_exists('mb_strtolower')?mb_strtolower($supplierName,'UTF-8'):strtolower($supplierName)),(function_exists('mb_strtolower')?mb_strtolower($product,'UTF-8'):strtolower($product)),(function_exists('mb_strtolower')?mb_strtolower($unit,'UTF-8'):strtolower($unit)),$currency],JSON_UNESCAPED_UNICODE));
        $id='spr-'.substr(hash('sha256',$rateKey),0,60);
        $rateLock='nsc_rate_'.substr(hash('sha256',$rateKey),0,48);
        $lock=$pdo->prepare('SELECT GET_LOCK(:name,5)');$lock->execute(['name'=>$rateLock]);
        if((int)$lock->fetchColumn()!==1)jsonResponse(false,null,'این نرخ در حال ثبت است؛ دوباره تلاش کنید.',503);
        register_shutdown_function(function()use($pdo,$rateLock){try{$unlock=$pdo->prepare('SELECT RELEASE_LOCK(:name)');$unlock->execute(['name'=>$rateLock]);}catch(Throwable $e){error_log('Rate lock cleanup failed.');}});
        $existingRate=$pdo->prepare('SELECT id FROM supplier_product_rates WHERE rate_key=:key AND tenant_id=:tid');
        $existingRate->execute(['key'=>$rateKey,'tid'=>$tenantId]);
        $foundRate=$existingRate->fetch();
        assertModulePermission($user,'suppliers',$foundRate?'edit':'create',$projectId);
        if($foundRate)$id=(string)$foundRate['id'];

        $stmt = $pdo->prepare("
            INSERT INTO supplier_product_rates
            (id, tenant_id, project_id, supplier_id, supplier_name, product, unit, last_agreed_rate, currency, effective_date, rate_key)
            VALUES (:id, :tid, :proj, :sid, :sname, :prod, :unit, :rate, :curr, :edate, :rkey)
            ON DUPLICATE KEY UPDATE
                supplier_id = IF(tenant_id=VALUES(tenant_id) AND rate_key=VALUES(rate_key),COALESCE(VALUES(supplier_id),supplier_id),supplier_id),
                last_agreed_rate = IF(tenant_id=VALUES(tenant_id) AND rate_key=VALUES(rate_key),VALUES(last_agreed_rate),last_agreed_rate),
                effective_date = IF(tenant_id=VALUES(tenant_id) AND rate_key=VALUES(rate_key),VALUES(effective_date),effective_date),
                updated_at = IF(tenant_id=VALUES(tenant_id) AND rate_key=VALUES(rate_key),CURRENT_TIMESTAMP,updated_at)
        ");
        $stmt->execute([
            ':id'    => $id,
            ':tid'   => $tenantId,
            ':proj'  => $projectId,
            ':sid'   => $supplierId,
            ':sname' => $supplierName,
            ':prod'  => $product,
            ':unit'  => $unit,
            ':rate'  => $rate,
            ':curr'  => $currency,
            ':edate' => $effectiveDate,
            ':rkey'  => $rateKey,
        ]);

        $confirmed=$pdo->prepare('SELECT id FROM supplier_product_rates WHERE tenant_id=:tid AND rate_key=:key');
        $confirmed->execute(['tid'=>$tenantId,'key'=>$rateKey]);$savedId=$confirmed->fetchColumn();
        if(!$savedId)jsonResponse(false,null,'شناسهٔ نرخ با رکورد دیگری تعارض دارد؛ هیچ رکورد بیگانه تغییر نکرد.',409);
        $id=(string)$savedId;

        jsonResponse([
            'success' => true,
            'id' => $id,
            'rate_key' => $rateKey,
            'last_agreed_rate' => $rate,
            'currency' => $currency,
            'effective_date' => $effectiveDate,
        ], 201);
    }

    $name = trim($body['supplier_name'] ?? '');
    if ($name === '') {
        jsonResponse(['error' => 'نام تأمین‌کننده الزامی است.'], 422);
    }

    $id = $body['id'] ?? generateUuid();
    $openingBalance = (float)($body['opening_balance'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO suppliers
        (id, tenant_id, supplier_name, contact_person, phone, address, material_category, tax_id, opening_balance, current_payable_balance, status)
        VALUES (:id, :tid, :name, :contact, :phone, :addr, :cat, :tax, :open_bal, :curr_bal, 'active')
    ");
    $stmt->execute([
        ':id'       => $id,
        ':tid'      => $tenantId,
        ':name'     => $name,
        ':contact'  => $body['contact_person'] ?? null,
        ':phone'    => $body['phone'] ?? null,
        ':addr'     => $body['address'] ?? null,
        ':cat'      => $body['material_category'] ?? 'عمومی',
        ':tax'      => $body['tax_id'] ?? null,
        ':open_bal' => $openingBalance,
        ':curr_bal' => $openingBalance,
    ]);

    writeAuditLog($pdo, $user['id'], $user['name'], 'CREATE', 'SUPPLIER', $id, null, ['supplier_name' => $name]);
    jsonResponse(['success' => true, 'id' => $id], 201);
}

// =====================================================================
// 3. ویرایش مشخصات تأمین‌کننده (PUT)
// =====================================================================
if ($method === 'PUT') {
    requireRole($user, ['master', 'admin', 'project_manager', 'accountant']);
    $body = getJsonBody();
    $id = $body['id'] ?? '';
    assertModulePermission($user, 'suppliers', 'edit');
    enforceIdempotencyGuard($pdo, $user, 'suppliers:put', $body);

    $stmt = $pdo->prepare("
        UPDATE suppliers SET
            supplier_name = :name,
            contact_person = :contact,
            phone = :phone,
            address = :addr,
            material_category = :cat,
            tax_id = :tax,
            status = :st,
            tenant_id = :tid
        WHERE id = :id AND tenant_id = :tid2
    ");
    $stmt->execute([
        ':id'      => $id,
        ':tid'     => $tenantId,
        ':tid2'    => $tenantId,
        ':name'    => $body['supplier_name'],
        ':contact' => $body['contact_person'] ?? null,
        ':phone'   => $body['phone'] ?? null,
        ':addr'    => $body['address'] ?? null,
        ':cat'     => $body['material_category'] ?? 'عمومی',
        ':tax'     => $body['tax_id'] ?? null,
        ':st'      => $body['status'] ?? 'active',
    ]);

    writeAuditLog($pdo, $user['id'], $user['name'], 'UPDATE', 'SUPPLIER', $id, null, $body);
    jsonResponse(['success' => true]);
}

// =====================================================================
// 4. حذف تأمین‌کننده (DELETE)
// =====================================================================
if ($method === 'DELETE') {
    requireRole($user, ['master', 'admin']);
    assertModulePermission($user, 'suppliers', 'del');
    $id = $_GET['id'] ?? '';

    $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = :id AND tenant_id = :tid");
    $stmt->execute([':id' => $id, ':tid' => $tenantId]);

    writeAuditLog($pdo, $user['id'], $user['name'], 'DELETE', 'SUPPLIER', $id, null, null);
    jsonResponse(['success' => true]);
}

jsonResponse(false,null,'روش یا عملیات پشتیبانی نمی‌شود.', in_array($method,['GET','POST','PUT','DELETE'],true)?404:405);
