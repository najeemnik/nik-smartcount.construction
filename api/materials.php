<?php
/**
 * NSC Material Procurement, Steel Rebar Specifications & Tonnage API (Phase 3)
 * Solves Excel Flaw #2 + Phase 3 Rebar Weight Memory & Multi-Size Tonnage Calculation
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
$user = authenticateUser(false);
$tenantId = currentTenantId($user);

/**
 * Ensure Phase 3 tables exist (backward-compatible auto-heal)
 */
function ensurePhase3RebarTables(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `rebar_spec_memory` (
              `id` VARCHAR(64) NOT NULL PRIMARY KEY,
              `project_id` VARCHAR(36) NULL,
              `tenant_id` VARCHAR(64) NULL,
              `diameter_mm` DECIMAL(8, 2) NOT NULL,
              `length_m` DECIMAL(8, 2) NOT NULL DEFAULT 12.00,
              `brand_spec` VARCHAR(180) NOT NULL DEFAULT 'عمومی',
              `weight_per_bar_kg` DECIMAL(12, 3) NOT NULL,
              `original_weight_per_bar_kg` DECIMAL(12, 3) NOT NULL,
              `spec_key` VARCHAR(300) NOT NULL UNIQUE,
              `weight_history_json` LONGTEXT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_rsm_diameter` (`diameter_mm`, `length_m`),
              INDEX `idx_rsm_project` (`project_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS `steel_purchase_lines` (
              `id` VARCHAR(64) NOT NULL PRIMARY KEY,
              `procurement_id` VARCHAR(36) NOT NULL,
              `project_id` VARCHAR(36) NOT NULL,
              `diameter_mm` DECIMAL(8, 2) NOT NULL,
              `length_m` DECIMAL(8, 2) NOT NULL DEFAULT 12.00,
              `brand_spec` VARCHAR(180) NOT NULL DEFAULT 'عمومی',
              `use_bundles` TINYINT(1) NOT NULL DEFAULT 0,
              `bundle_count` INT NULL,
              `bars_per_bundle` INT NULL,
              `bar_count` INT NOT NULL DEFAULT 0,
              `weight_per_bar_kg` DECIMAL(12, 3) NOT NULL,
              `estimated_weight_kg` DECIMAL(16, 3) NOT NULL DEFAULT 0.000,
              `estimated_weight_ton` DECIMAL(16, 4) NOT NULL DEFAULT 0.0000,
              `actual_weight_ton` DECIMAL(16, 4) NULL,
              `confirmed_tonnage` DECIMAL(16, 4) NOT NULL DEFAULT 0.0000,
              `discrepancy_kg` DECIMAL(16, 3) NOT NULL DEFAULT 0.000,
              `discrepancy_ton` DECIMAL(16, 4) NOT NULL DEFAULT 0.0000,
              `discrepancy_pct` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
              `price_per_ton` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
              `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
              `total_cost` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_spl_proc` (`procurement_id`),
              INDEX `idx_spl_proj_size` (`project_id`, `diameter_mm`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // نصب‌های موجود: ستون tenant_id را افزودنی اضافه کن.
        // CREATE TABLE IF NOT EXISTS روی جدول موجود کاری نمی‌کند، بنابراین بدون این
        // ALTER نصب‌های فعلی هرگز ستون شرکت را نمی‌گرفتند.
        // MySQL ندارد ADD COLUMN IF NOT EXISTS، پس خطای «ستون تکراری» (1060) را نادیده می‌گیریم.
        try {
            $cols = $db->query("SHOW COLUMNS FROM `rebar_spec_memory` LIKE 'tenant_id'")->fetchAll();
            if (!$cols) {
                $db->exec("ALTER TABLE `rebar_spec_memory` ADD COLUMN `tenant_id` VARCHAR(64) NULL AFTER `project_id`");
                $db->exec("ALTER TABLE `rebar_spec_memory` ADD INDEX `idx_rsm_tenant` (`tenant_id`)");
            }
        } catch (Throwable $e) {
            // اگر هاست اجازهٔ ALTER ندهد، خواندن/نوشتن با spec_key پیشوند‌دار باز هم شرکت‌ها را جدا می‌کند
            error_log('NSC rebar tenant_id migration skipped: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        // Non-fatal if DB permissions restrict DDL
    }
}

/**
 * Upsert a rebar specification weight per bar in rebar_spec_memory
 * Key: diameter_mm||length_m||LOWER(brand_spec) (never assumes diameter alone determines weight)
 *
 * Tenant scoping: پیش از این spec_key سراسری یکتا بود و جست‌وجو هم بدون فیلتر شرکت انجام
 * می‌شد؛ هر شرکتی که وزن مرجع را عوض می‌کرد، ردیف شرکت‌های دیگر را بازنویسی می‌کرد و
 * محاسبهٔ وزن پروژه‌های آن‌ها بی‌خبر تغییر می‌کرد. اکنون کلید با شناسهٔ شرکت پیشوند
 * می‌گیرد (بدون نیاز به حذف قید UNIQUE موجود) و هر نوشتن به شرکت خودش محدود است.
 * ردیف‌های قدیمیِ بدون شرکت (tenant_id IS NULL) به‌عنوان «پیش‌فرض مشترک» فقط خواندنی می‌مانند.
 */
function upsertRebarSpecInDb(PDO $db, array $spec, string $changedBy = 'Admin', string $tenantId = ''): array {
    $diameterMm = round((float)($spec['diameterMm'] ?? $spec['diameter_mm'] ?? 0), 2);
    $lengthM = round((float)($spec['lengthM'] ?? $spec['length_m'] ?? 12), 2);
    $brandSpec = trim((string)($spec['brandSpec'] ?? $spec['brand_spec'] ?? 'عمومی')) ?: 'عمومی';
    $weightPerBarKg = round((float)($spec['weightPerBarKg'] ?? $spec['weight_per_bar_kg'] ?? 0), 3);
    $projectId = $spec['projectId'] ?? $spec['project_id'] ?? null;

    if ($diameterMm <= 0 || $lengthM <= 0 || $weightPerBarKg <= 0) {
        return ['saved' => false];
    }

    $specKey = ($tenantId !== '' ? $tenantId . '||' : '') . $diameterMm . '||' . $lengthM . '||' . mb_strtolower($brandSpec, 'UTF-8');
    $findStmt = $db->prepare("SELECT * FROM rebar_spec_memory WHERE spec_key = :skey LIMIT 1");
    $findStmt->execute([':skey' => $specKey]);
    $existing = $findStmt->fetch();

    if ($existing) {
        $origWeight = (float)$existing['original_weight_per_bar_kg'] > 0
            ? (float)$existing['original_weight_per_bar_kg']
            : (float)$existing['weight_per_bar_kg'];
        $history = !empty($existing['weight_history_json'])
            ? (json_decode($existing['weight_history_json'], true) ?: [])
            : [];
        if (round((float)$existing['weight_per_bar_kg'], 3) !== $weightPerBarKg) {
            $history[] = [
                'weightPerBarKg' => $weightPerBarKg,
                'effectiveDate' => date('Y-m-d'),
                'changedBy' => $changedBy,
                'note' => $spec['note'] ?? 'Authorized reference weight update',
            ];
        }
        $updStmt = $db->prepare("
            UPDATE rebar_spec_memory
            SET weight_per_bar_kg = :w,
                original_weight_per_bar_kg = :orig,
                weight_history_json = :hist,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND (tenant_id = :tid OR tenant_id IS NULL)
        ");
        $updStmt->execute([
            ':w' => $weightPerBarKg,
            ':orig' => $origWeight,
            ':hist' => json_encode($history, JSON_UNESCAPED_UNICODE),
            ':id' => $existing['id'],
            ':tid' => $tenantId,
        ]);
        return [
            'saved' => true,
            'id' => $existing['id'],
            'spec_key' => $specKey,
            'weight_per_bar_kg' => $weightPerBarKg,
            'original_weight_per_bar_kg' => $origWeight,
        ];
    }

    $id = $spec['id'] ?? ('rbs-' . substr(md5($specKey), 0, 16));
    $initialHistory = [[
        'weightPerBarKg' => $weightPerBarKg,
        'effectiveDate' => date('Y-m-d'),
        'changedBy' => $changedBy,
        'note' => $spec['note'] ?? 'Initial reference bar weight saved',
    ]];
    $insStmt = $db->prepare("
        INSERT INTO rebar_spec_memory
        (id, project_id, tenant_id, diameter_mm, length_m, brand_spec, weight_per_bar_kg, original_weight_per_bar_kg, spec_key, weight_history_json)
        VALUES (:id, :proj, :tid, :d, :l, :b, :w, :orig, :skey, :hist)
    ");
    $insStmt->execute([
        ':id' => $id,
        ':proj' => $projectId,
        ':tid' => $tenantId !== '' ? $tenantId : null,
        ':d' => $diameterMm,
        ':l' => $lengthM,
        ':b' => $brandSpec,
        ':w' => $weightPerBarKg,
        ':orig' => $weightPerBarKg,
        ':skey' => $specKey,
        ':hist' => json_encode($initialHistory, JSON_UNESCAPED_UNICODE),
    ]);
    return [
        'saved' => true,
        'id' => $id,
        'spec_key' => $specKey,
        'weight_per_bar_kg' => $weightPerBarKg,
        'original_weight_per_bar_kg' => $weightPerBarKg,
    ];
}

ensurePhase3RebarTables($db);

if ($method === 'GET') {
    // Phase 3 — TASK 1: GET saved rebar specifications and weights per bar
    if (isset($_GET['action']) && $_GET['action'] === 'rebar_specs') {
        $projectId = $_GET['projectId'] ?? null;
        if ($projectId) {
            assertTenantProjectAccess($db, $projectId, $tenantId, false);
        }
        assertModulePermission($user, 'steel', 'view', $projectId ? (string)$projectId : null);

        $diameter = isset($_GET['diameterMm']) ? (float)$_GET['diameterMm'] : null;
        $length = isset($_GET['lengthM']) ? (float)$_GET['lengthM'] : null;
        $brand = $_GET['brandSpec'] ?? null;

        // ردیف‌های خودِ این شرکت + پیش‌فرض‌های مشترک قدیمی (tenant_id IS NULL و بدون پروژه)
        $sql = "SELECT r.* FROM rebar_spec_memory r
                LEFT JOIN projects p ON r.project_id = p.id
                WHERE (r.tenant_id = :tid OR (r.tenant_id IS NULL AND (r.project_id IS NULL OR p.tenant_id = :tid)))";
        $params = [':tid' => $tenantId];
        if ($projectId) {
            $sql .= " AND (r.project_id = :pid OR r.project_id IS NULL)";
            $params[':pid'] = $projectId;
        }
        if ($diameter !== null && $diameter > 0) {
            $sql .= " AND r.diameter_mm = :d";
            $params[':d'] = $diameter;
        }
        if ($length !== null && $length > 0) {
            $sql .= " AND r.length_m = :l";
            $params[':l'] = $length;
        }
        if ($brand !== null && trim($brand) !== '') {
            $sql .= " AND LOWER(TRIM(r.brand_spec)) = LOWER(TRIM(:b))";
            $params[':b'] = $brand;
        }
        $sql .= " ORDER BY r.diameter_mm ASC, r.length_m ASC, r.updated_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse(true, ['specs' => $stmt->fetchAll()]);
    }

    // Phase 8 — TASK 2: Steel Rebar Report endpoint (Weight Kg, Weight Ton, Cost USD, preserved original currency)
    if (isset($_GET['action']) && $_GET['action'] === 'steel_report') {
        $projectId = $_GET['projectId'] ?? null;
        if ($projectId) {
            assertTenantProjectAccess($db, $projectId, $tenantId, false);
        }
        assertModulePermission($user, 'steel', 'view', $projectId ? (string)$projectId : null);

        $sql = "SELECT s.* FROM steel_records s
                INNER JOIN projects p ON s.project_id = p.id
                WHERE p.tenant_id = :tid";
        $params = [':tid' => $tenantId];
        if ($projectId) {
            $sql .= " AND s.project_id = :pid";
            $params[':pid'] = $projectId;
        }
        $sql .= " ORDER BY s.date DESC, s.created_at DESC";
        $rows = [];
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }

        $totalWeightKg = 0.0;
        $totalWeightTon = 0.0;
        $totalCostUSD = 0.0;
        $totalCostByCurrency = [];
        $reportRows = [];

        foreach ($rows as $r) {
            $status = strtolower((string)($r['status'] ?? $r['doc_status'] ?? 'active'));
            if ($status === 'cancelled' || $status === 'void' || $status === 'deleted') {
                continue;
            }
            $ton = (float)($r['confirmed_total_ton'] ?? $r['actual_total_ton'] ?? $r['tons'] ?? 0);
            $kg = (float)($r['kg'] ?? ($ton * 1000));
            if ($ton <= 0 && $kg > 0) {
                $ton = round($kg / 1000, 4);
            }
            if ($kg <= 0 && $ton > 0) {
                $kg = round($ton * 1000, 3);
            }
            $currency = strtoupper(trim((string)($r['currency'] ?? 'USD'))) ?: 'USD';
            $exRate = isset($r['exchange_rate']) && (float)$r['exchange_rate'] > 0 ? (float)$r['exchange_rate'] : null;
            $nativeCost = (float)($r['total_native_cost'] ?? ($currency === 'AFN' ? ($r['amount_in_afn'] ?? 0) : ($r['total_amount'] ?? 0)));
            $costUSD = null;
            if ($currency === 'USD') {
                $costUSD = round((float)($r['amount_in_usd'] ?? $r['total_amount'] ?? $nativeCost), 2);
            } elseif ($exRate !== null && $exRate > 0) {
                $costUSD = round($nativeCost / $exRate, 2);
            }

            $totalWeightKg += $kg;
            $totalWeightTon += $ton;
            if ($costUSD !== null) {
                $totalCostUSD += $costUSD;
            }
            $totalCostByCurrency[$currency] = round(($totalCostByCurrency[$currency] ?? 0) + $nativeCost, 2);

            $reportRows[] = [
                'id' => $r['id'],
                'projectId' => $r['project_id'],
                'date' => $r['date'],
                'size' => $r['size_mm'] ?: '16mm',
                'brand' => $r['origin_country'] ?: '—',
                'supplier' => $r['supplier_name'] ?: '—',
                'bundles' => $r['bundles'] ?: (!empty($r['branches']) ? ($r['branches'] . ' شاخه') : '—'),
                'weightKg' => round($kg, 3),
                'weightTon' => round($ton, 4),
                'originalCurrency' => $currency,
                'nativeCost' => round($nativeCost, 2),
                'exchangeRate' => $exRate,
                'hasValidExchangeRate' => ($currency === 'USD' || ($exRate !== null && $exRate > 0)),
                'costUSD' => $costUSD,
            ];
        }

        jsonResponse(true, [
            'totalWeightKg' => round($totalWeightKg, 3),
            'totalWeightTon' => round($totalWeightTon, 4),
            'totalCostUSD' => round($totalCostUSD, 2),
            'totalCostByCurrency' => $totalCostByCurrency,
            'columns' => ['Date', 'Size', 'Brand', 'Supplier', 'Bundles', 'Weight Kg', 'Weight Ton', 'Cost USD', 'Actions'],
            'rows' => $reportRows,
        ]);
    }

    $projectId = $_GET['projectId'] ?? null;
    $sql = "SELECT m.*, p.name as project_name, s.supplier_name as supplier_official_name 
            FROM material_procurements m 
            LEFT JOIN projects p ON m.project_id = p.id 
            LEFT JOIN suppliers s ON m.supplier_id = s.id";
    $params = ['tid' => $tenantId];
    $sql .= " WHERE m.tenant_id = :tid";
    if ($projectId) {
        $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
        $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
        $sql .= " AND m.project_id = :pid";
        $params['pid'] = $projectId;
    }
    $sql .= " ORDER BY m.purchase_date DESC LIMIT 200";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(true, ['materials' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $input = getJsonInput();
    $reqProjectId = !empty($input['projectId']) ? (string)$input['projectId'] : null;
    if ($reqProjectId) {
        assertTenantProjectAccess($db, $reqProjectId, $tenantId, false);
    }
    assertModulePermission($user, 'inventory', 'create', $reqProjectId);
    enforceIdempotencyGuard($db, $user, 'materials:post', $input);

    // Phase 3 — TASK 1: Save or update rebar specification weight per bar
    if ((isset($_GET['action']) && $_GET['action'] === 'rebar_specs') || (($input['action'] ?? '') === 'save_rebar_spec')) {
        $res = upsertRebarSpecInDb($db, $input, $user['name'] ?? 'Admin', (string)$tenantId);
        if (empty($res['saved'])) {
            jsonResponse(false, null, 'قطر، طول و وزن یک شاخه (kg) معتبر الزامی است.', 422);
        }
        jsonResponse(true, $res, 'مشخصات و وزن مرجع شاخه با موفقیت ذخیره شد.');
    }

    $id = generateUuid();
    $procCode = 'MAT-' . date('ymd') . '-' . rand(100, 999);
    $projectId = $input['projectId'] ?? '';
    $materialName = trim($input['materialName'] ?? $input['itemDescription'] ?? '');
    $category = $input['materialCategory'] ?? 'مواد عمومی';
    $qty = (float)($input['quantity'] ?? 0);
    $unit = $input['unitOfMeasure'] ?? 'کیسه';
    $unitPrice = (float)($input['unitPrice'] ?? 0);
    $totalPrice = $qty * $unitPrice;
    $supplierName = trim($input['supplierName'] ?? 'تأمین‌کننده محلی');
    $supplierId = $input['supplierId'] ?? null;
    $date = !empty($input['purchaseDate']) ? $input['purchaseDate'] : date('Y-m-d');
    $isPaid = !empty($input['isPaid']) ? 1 : 0;
    $lineItems = is_array($input['lineItems'] ?? null) ? $input['lineItems'] : [];

    if (empty($projectId) || empty($materialName) || $qty <= 0 || $unitPrice <= 0) {
        jsonResponse(false, null, 'پروژه، نام جنس، تعداد و قیمت واحد الزامی هستند.', 400);
    }

    // امنیت: پروژه و تأمین‌کننده باید متعلق به همین شرکت باشند
    $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
    $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
    if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
    if (!empty($supplierId)) {
        $supStmt = $db->prepare("SELECT id FROM suppliers WHERE id = :sid AND tenant_id = :tid LIMIT 1");
        $supStmt->execute(['sid' => $supplierId, 'tid' => $tenantId]);
        if (!$supStmt->fetch()) { jsonResponse(false, null, 'تأمین‌کننده یافت نشد یا دسترسی ندارید.', 404); }
    }

    $db->beginTransaction();
    try {
        $paymentVoucherId = null;

        if ($isPaid) {
            $paymentVoucherId = generateUuid();
            $pvNo = 'PV-MAT-' . date('ymd') . '-' . rand(100, 999);
            $pvStmt = $db->prepare("
                INSERT INTO payment_vouchers (
                    id, tenant_id, voucher_no, project_id, payee_type, payee_id, payee_name,
                    expense_category, amount, currency, exchange_rate, amount_in_base_afn,
                    payment_method, payment_date, payment_status, approved_by,
                    recorded_by_user_id, description
                ) VALUES (
                    :id, :tid, :vno, :pid, 'supplier', :sid, :sname,
                    'materials', :amt, 'AFN', 1.0, :amt2,
                    'cash', :dt, 'paid', 'مدیر تدارکات',
                    :uid, :desc
                )
            ");
            $pvStmt->execute([
                'id' => $paymentVoucherId,
                'tid' => $tenantId,
                'vno' => $pvNo,
                'pid' => $projectId,
                'sid' => $supplierId,
                'sname' => $supplierName,
                'amt' => $totalPrice, 'amt2' => $totalPrice,
                'dt' => $date,
                'uid' => $user['id'],
                'desc' => "خرید $qty $unit $materialName با قیمت واحد $unitPrice AFN"
            ]);
        }

        // درج در جدول تدارکات مواد
        $stmt = $db->prepare("
            INSERT INTO material_procurements (
                id, tenant_id, procurement_code, project_id, supplier_id, supplier_name,
                material_category, item_description, quantity, unit_of_measure,
                unit_price, total_cost, currency, is_paid, payment_voucher_id,
                purchase_date, received_by, notes
            ) VALUES (
                :id, :tid, :code, :pid, :sid, :sname,
                :cat, :item, :qty, :unit,
                :uprice, :total, 'AFN', :paid, :pvid,
                :pdate, :rec, :notes
            )
        ");
        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'code' => $procCode,
            'pid' => $projectId,
            'sid' => $supplierId,
            'sname' => $supplierName,
            'cat' => $category,
            'item' => $materialName,
            'qty' => $qty,
            'unit' => $unit,
            'uprice' => $unitPrice,
            'total' => $totalPrice,
            'paid' => $isPaid,
            'pvid' => $paymentVoucherId,
            'pdate' => $date,
            'rec' => $input['receivedBy'] ?? $user['name'],
            'notes' => $input['notes'] ?? null
        ]);

        // Phase 3: Save multi-size steel line items & update rebar_spec_memory
        if (!empty($lineItems)) {
            $lineStmt = $db->prepare("
                INSERT INTO steel_purchase_lines (
                    id, procurement_id, project_id, diameter_mm, length_m, brand_spec,
                    use_bundles, bundle_count, bars_per_bundle, bar_count, weight_per_bar_kg,
                    estimated_weight_kg, estimated_weight_ton, actual_weight_ton, confirmed_tonnage,
                    discrepancy_kg, discrepancy_ton, discrepancy_pct, price_per_ton, currency, total_cost
                ) VALUES (
                    :id, :proc, :proj, :d, :l, :b,
                    :ub, :bcnt, :bpb, :bars, :wpb,
                    :ekg, :eton, :aton, :cton,
                    :dkg, :dton, :dpct, :ppt, :curr, :tcost
                )
            ");
            foreach ($lineItems as $li) {
                $d = (float)($li['diameterMm'] ?? 0);
                $l = (float)($li['lengthM'] ?? 12);
                $b = trim((string)($li['brandSpec'] ?? 'عمومی')) ?: 'عمومی';
                $useBundles = !empty($li['useBundles']) ? 1 : 0;
                $bundleCount = isset($li['bundleCount']) ? (int)$li['bundleCount'] : null;
                $barsPerBundle = isset($li['barsPerBundle']) ? (int)$li['barsPerBundle'] : null;
                // Avoid double-counting: if bundles are used, barCount = bundleCount * barsPerBundle
                $barCount = ($useBundles && $bundleCount > 0 && $barsPerBundle > 0)
                    ? ($bundleCount * $barsPerBundle)
                    : (int)($li['barCount'] ?? 0);
                $weightPerBarKg = (float)($li['weightPerBarKg'] ?? 0);
                $estKg = round($barCount * $weightPerBarKg, 3);
                $estTon = round($estKg / 1000, 4);
                $hasActual = isset($li['actualWeightTon']) && $li['actualWeightTon'] !== null && (float)$li['actualWeightTon'] > 0;
                $actualTon = $hasActual ? round((float)$li['actualWeightTon'], 4) : null;
                $confirmedTon = $hasActual ? $actualTon : $estTon;
                $discTon = $hasActual ? round($actualTon - $estTon, 4) : 0;
                $discKg = $hasActual ? round($actualTon * 1000 - $estKg, 3) : 0;
                $discPct = ($hasActual && $estTon > 0) ? round((($actualTon - $estTon) / $estTon) * 100, 2) : 0;
                $ppt = round((float)($li['pricePerTon'] ?? 0), 2);
                $curr = strtoupper(trim((string)($li['currency'] ?? 'USD')));
                $tcost = round($confirmedTon * $ppt, 2);

                $lineStmt->execute([
                    ':id' => $li['id'] ?? generateUuid(),
                    ':proc' => $id,
                    ':proj' => $projectId,
                    ':d' => $d,
                    ':l' => $l,
                    ':b' => $b,
                    ':ub' => $useBundles,
                    ':bcnt' => $bundleCount,
                    ':bpb' => $barsPerBundle,
                    ':bars' => $barCount,
                    ':wpb' => $weightPerBarKg,
                    ':ekg' => $estKg,
                    ':eton' => $estTon,
                    ':aton' => $actualTon,
                    ':cton' => $confirmedTon,
                    ':dkg' => $discKg,
                    ':dton' => $discTon,
                    ':dpct' => $discPct,
                    ':ppt' => $ppt,
                    ':curr' => $curr,
                    ':tcost' => $tcost,
                ]);

                upsertRebarSpecInDb($db, [
                    'projectId' => $projectId,
                    'diameterMm' => $d,
                    'lengthM' => $l,
                    'brandSpec' => $b,
                    'weightPerBarKg' => $weightPerBarKg,
                ], $user['name'] ?? 'Admin', (string)$tenantId);
            }
        }

        if (!empty($supplierId)) {
            $db->prepare("
                UPDATE suppliers 
                SET total_purchases = total_purchases + :tot,
                    total_paid = total_paid + :paid_amt,
                    current_payable_balance = current_payable_balance + :remain
                WHERE id = :sid AND tenant_id = :tid
            ")->execute([
                'tot' => $totalPrice,
                'paid_amt' => $isPaid ? $totalPrice : 0,
                'remain' => $isPaid ? 0 : $totalPrice,
                'sid' => $supplierId,
                'tid' => $tenantId
            ]);
        }

        $db->commit();
        logAudit($db, $user['id'], $user['name'], 'create', 'materials', $id, "ثبت خرید مواد: $materialName ($totalPrice AFN)");
        jsonResponse(true, ['id' => $id, 'procCode' => $procCode, 'totalCost' => $totalPrice], 'خرید مصالح با موفقیت ثبت و مستقیماً به هزینه پروژه متصل شد.');
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Materials POST error: " . $e->getMessage());
        jsonResponse(false, null, 'خطا در ثبت تدارکات مواد در پایگاه داده.', 500);
    }
}

// =====================================================================
// Phase 4 — TASK 1 & TASK 2: ویرایش خرید سیخ‌گول بدون ایجاد رکورد تکراری (PUT)
// =====================================================================
if ($method === 'PUT') {
    $input = getJsonInput();
    $id = trim($input['id'] ?? '');
    if ($id === '') {
        jsonResponse(false, null, 'شناسه رکورد خرید برای ویرایش الزامی است.', 422);
    }

    $oldStmt = $db->prepare("SELECT * FROM material_procurements WHERE id = :id AND tenant_id = :tid LIMIT 1");
    $oldStmt->execute([':id' => $id, ':tid' => $tenantId]);
    $old = $oldStmt->fetch();
    if (!$old) {
        jsonResponse(false, null, 'رکورد خرید یافت نشد یا دسترسی ندارید.', 404);
    }
    assertModulePermission($user, 'steel', 'edit', !empty($old['project_id']) ? (string)$old['project_id'] : null);
    enforceIdempotencyGuard($db, $user, 'materials:put', $input);

    $supplierName = trim($input['supplierName'] ?? $old['supplier_name']);
    $supplierId = $input['supplierId'] ?? $old['supplier_id'];
    $date = !empty($input['purchaseDate'] ?? $input['date']) ? ($input['purchaseDate'] ?? $input['date']) : $old['purchase_date'];
    $billNo = trim($input['billNumber'] ?? $input['invoiceNumber'] ?? $old['bill_invoice_no'] ?? $old['procurement_code']);
    $currency = strtoupper(trim($input['currency'] ?? $old['currency'] ?? 'USD'));
    $notes = $input['notes'] ?? $old['notes'];
    $lineItems = is_array($input['lineItems'] ?? null) ? $input['lineItems'] : [];

    // Recalculate confirmed tonnage and total cost from lineItems (or top-level inputs)
    $confirmedTotalTon = 0.0;
    $totalNativeCost = 0.0;
    if (!empty($lineItems)) {
        foreach ($lineItems as $li) {
            $useBundles = !empty($li['useBundles']) ? 1 : 0;
            $bundleCount = isset($li['bundleCount']) ? (int)$li['bundleCount'] : 0;
            $barsPerBundle = isset($li['barsPerBundle']) ? (int)$li['barsPerBundle'] : 0;
            $barCount = ($useBundles && $bundleCount > 0 && $barsPerBundle > 0)
                ? ($bundleCount * $barsPerBundle)
                : (int)($li['barCount'] ?? 0);
            $weightPerBarKg = (float)($li['weightPerBarKg'] ?? 0);
            $estKg = round($barCount * $weightPerBarKg, 3);
            $estTon = round($estKg / 1000, 4);
            $hasActual = isset($li['actualWeightTon']) && $li['actualWeightTon'] !== null && (float)$li['actualWeightTon'] > 0;
            $actualTon = $hasActual ? round((float)$li['actualWeightTon'], 4) : null;
            $confirmedTon = $hasActual ? $actualTon : $estTon;
            $ppt = round((float)($li['pricePerTon'] ?? 0), 2);
            $confirmedTotalTon += $confirmedTon;
            $totalNativeCost += round($confirmedTon * $ppt, 2);
        }
    } else {
        $confirmedTotalTon = (float)($input['confirmedTotalTon'] ?? $input['tons'] ?? $input['quantity'] ?? $old['quantity']);
        $unitPrice = (float)($input['unitPrice'] ?? $input['pricePerTon'] ?? $old['unit_price']);
        $totalNativeCost = round($confirmedTotalTon * $unitPrice, 2);
    }

    $confirmedTotalTon = round($confirmedTotalTon, 4);
    $totalNativeCost = round($totalNativeCost, 2);
    $effectiveUnitPrice = $confirmedTotalTon > 0 ? round($totalNativeCost / $confirmedTotalTon, 2) : (float)$old['unit_price'];

    $db->beginTransaction();
    try {
        // Update original material_procurements record in-place (preserving id)
        $updStmt = $db->prepare("
            UPDATE material_procurements
            SET supplier_id = :sid,
                supplier_name = :sname,
                item_description = :item,
                quantity = :qty,
                unit_price = :uprice,
                total_cost = :total,
                currency = :curr,
                bill_invoice_no = :bill,
                purchase_date = :pdate,
                notes = :notes
            WHERE id = :id AND tenant_id = :tid
        ");
        $updStmt->execute([
            ':sid' => $supplierId,
            ':sname' => $supplierName,
            ':item' => trim($input['materialName'] ?? $input['itemDescription'] ?? $old['item_description']),
            ':qty' => $confirmedTotalTon,
            ':uprice' => $effectiveUnitPrice,
            ':total' => $totalNativeCost,
            ':curr' => $currency,
            ':bill' => $billNo,
            ':pdate' => $date,
            ':notes' => $notes,
            ':id' => $id,
            ':tid' => $tenantId,
        ]);

        // Replace steel_purchase_lines for this procurement id & update rebar_spec_memory + supplier_product_rates
        if (!empty($lineItems)) {
            $db->prepare("DELETE FROM steel_purchase_lines WHERE procurement_id = :proc")->execute([':proc' => $id]);
            $lineStmt = $db->prepare("
                INSERT INTO steel_purchase_lines (
                    id, procurement_id, project_id, diameter_mm, length_m, brand_spec,
                    use_bundles, bundle_count, bars_per_bundle, bar_count, weight_per_bar_kg,
                    estimated_weight_kg, estimated_weight_ton, actual_weight_ton, confirmed_tonnage,
                    discrepancy_kg, discrepancy_ton, discrepancy_pct, price_per_ton, currency, total_cost
                ) VALUES (
                    :id, :proc, :proj, :d, :l, :b,
                    :ub, :bcnt, :bpb, :bars, :wpb,
                    :ekg, :eton, :aton, :cton,
                    :dkg, :dton, :dpct, :ppt, :curr, :tcost
                )
            ");
            foreach ($lineItems as $li) {
                $d = (float)($li['diameterMm'] ?? 0);
                $l = (float)($li['lengthM'] ?? 12);
                $b = trim((string)($li['brandSpec'] ?? 'عمومی')) ?: 'عمومی';
                $useBundles = !empty($li['useBundles']) ? 1 : 0;
                $bundleCount = isset($li['bundleCount']) ? (int)$li['bundleCount'] : null;
                $barsPerBundle = isset($li['barsPerBundle']) ? (int)$li['barsPerBundle'] : null;
                $barCount = ($useBundles && $bundleCount > 0 && $barsPerBundle > 0)
                    ? ($bundleCount * $barsPerBundle)
                    : (int)($li['barCount'] ?? 0);
                $weightPerBarKg = (float)($li['weightPerBarKg'] ?? 0);
                $estKg = round($barCount * $weightPerBarKg, 3);
                $estTon = round($estKg / 1000, 4);
                $hasActual = isset($li['actualWeightTon']) && $li['actualWeightTon'] !== null && (float)$li['actualWeightTon'] > 0;
                $actualTon = $hasActual ? round((float)$li['actualWeightTon'], 4) : null;
                $confirmedTon = $hasActual ? $actualTon : $estTon;
                $discTon = $hasActual ? round($actualTon - $estTon, 4) : 0;
                $discKg = $hasActual ? round($actualTon * 1000 - $estKg, 3) : 0;
                $discPct = ($hasActual && $estTon > 0) ? round((($actualTon - $estTon) / $estTon) * 100, 2) : 0;
                $ppt = round((float)($li['pricePerTon'] ?? 0), 2);
                $curr = strtoupper(trim((string)($li['currency'] ?? $currency)));
                $tcost = round($confirmedTon * $ppt, 2);

                $lineStmt->execute([
                    ':id' => $li['id'] ?? generateUuid(),
                    ':proc' => $id,
                    ':proj' => $old['project_id'],
                    ':d' => $d,
                    ':l' => $l,
                    ':b' => $b,
                    ':ub' => $useBundles,
                    ':bcnt' => $bundleCount,
                    ':bpb' => $barsPerBundle,
                    ':bars' => $barCount,
                    ':wpb' => $weightPerBarKg,
                    ':ekg' => $estKg,
                    ':eton' => $estTon,
                    ':aton' => $actualTon,
                    ':cton' => $confirmedTon,
                    ':dkg' => $discKg,
                    ':dton' => $discTon,
                    ':dpct' => $discPct,
                    ':ppt' => $ppt,
                    ':curr' => $curr,
                    ':tcost' => $tcost,
                ]);

                upsertRebarSpecInDb($db, [
                    'projectId' => $old['project_id'],
                    'diameterMm' => $d,
                    'lengthM' => $l,
                    'brandSpec' => $b,
                    'weightPerBarKg' => $weightPerBarKg,
                ], $user['name'] ?? 'Admin', (string)$tenantId);
            }
        }

        // Update linked payment voucher if present
        if (!empty($old['payment_voucher_id'])) {
            $db->prepare("
                UPDATE payment_vouchers
                SET payee_id = :sid,
                    payee_name = :sname,
                    amount = :amt,
                    amount_in_base_afn = :amt2,
                    payment_date = :dt
                WHERE id = :pvid AND tenant_id = :tid
            ")->execute([
                ':sid' => $supplierId,
                ':sname' => $supplierName,
                ':amt' => $totalNativeCost,
                ':amt2' => $totalNativeCost,
                ':dt' => $date,
                ':pvid' => $old['payment_voucher_id'],
                ':tid' => $tenantId,
            ]);
        }

        // Adjust supplier running balance delta if supplier_id is present
        $deltaCost = $totalNativeCost - (float)$old['total_cost'];
        if (!empty($supplierId) && abs($deltaCost) > 0.001) {
            $isPaid = (int)$old['is_paid'] === 1;
            $db->prepare("
                UPDATE suppliers
                SET total_purchases = GREATEST(0, total_purchases + :dtot),
                    total_paid = GREATEST(0, total_paid + :dpaid),
                    current_payable_balance = GREATEST(0, current_payable_balance + :drem)
                WHERE id = :sid AND tenant_id = :tid
            ")->execute([
                ':dtot' => $deltaCost,
                ':dpaid' => $isPaid ? $deltaCost : 0,
                ':drem' => $isPaid ? 0 : $deltaCost,
                ':sid' => $supplierId,
                ':tid' => $tenantId,
            ]);
        }

        $db->commit();
        logAudit($db, $user['id'], $user['name'], 'update', 'materials', $id, "ویرایش خرید سیخ/مصالح #$billNo: تناژ قطعی $confirmedTotalTon تن — مبلغ $totalNativeCost $currency");
        jsonResponse(true, [
            'id' => $id,
            'confirmedTotalTon' => $confirmedTotalTon,
            'totalCost' => $totalNativeCost,
            'currency' => $currency,
        ], 'تغییرات خرید سیخ‌گول با موفقیت ذخیره و سرجمع‌ها بازمحاسبه شد.');
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Materials PUT error: " . $e->getMessage());
        jsonResponse(false, null, 'خطا در ویرایش خرید در پایگاه داده.', 500);
    }
}
