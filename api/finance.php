<?php
/**
 * NSC Core Financial Operations API
 * Income, Payments, Bank, Cash, Petty Cash & Transfers
 * Zero Vulnerability - ACID Transactions on All Balance Updates
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
$user = authenticateUser(false);
$tenantId = currentTenantId($user);

// -------------------------------------------------------------
// GET: دریافت لیست دریافت‌ها، پرداخت‌ها، حساب‌ها و انتقالات
// -------------------------------------------------------------
if ($method === 'GET') {
    $section = $_GET['section'] ?? 'summary';
    $projectId = $_GET['projectId'] ?? null;

    if ($section === 'treasury_accounts') {
        $stmt = $db->prepare("SELECT * FROM treasury_accounts WHERE tenant_id = :tid ORDER BY account_type, account_name");
        $stmt->execute(['tid' => $tenantId]);
        jsonResponse(true, ['accounts' => $stmt->fetchAll()]);
    }

    if ($section === 'incomes') {
        $sql = "SELECT r.*, p.name as project_name, a.account_name as deposit_account_name 
                FROM revenue_entries r 
                LEFT JOIN projects p ON r.project_id = p.id 
                LEFT JOIN treasury_accounts a ON r.deposit_account_id = a.id";
        $params = ['tid' => $tenantId];
        $sql .= " WHERE r.tenant_id = :tid";
        if ($projectId) {
            $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
            $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
            if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
            $sql .= " AND r.project_id = :pid";
            $params['pid'] = $projectId;
        }
        $sql .= " ORDER BY r.received_date DESC LIMIT 200";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse(true, ['incomes' => $stmt->fetchAll()]);
    }

    if ($section === 'payments') {
        $sql = "SELECT v.*, p.name as project_name, a.account_name as source_account_name 
                FROM payment_vouchers v 
                LEFT JOIN projects p ON v.project_id = p.id 
                LEFT JOIN treasury_accounts a ON v.source_account_id = a.id";
        $params = ['tid' => $tenantId];
        $sql .= " WHERE v.tenant_id = :tid";
        if ($projectId) {
            $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
            $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
            if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
            $sql .= " AND v.project_id = :pid";
            $params['pid'] = $projectId;
        }
        $sql .= " ORDER BY v.payment_date DESC LIMIT 200";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse(true, ['payments' => $stmt->fetchAll()]);
    }

    if ($section === 'transfers') {
        $stmt = $db->prepare("
            SELECT t.*, 
                   fp.name as from_project_name, 
                   tp.name as to_project_name,
                   fa.account_name as from_account_name,
                   ta.account_name as to_account_name
            FROM project_transfers t
            LEFT JOIN projects fp ON t.from_project_id = fp.id
            LEFT JOIN projects tp ON t.to_project_id = tp.id
            LEFT JOIN treasury_accounts fa ON t.from_treasury_account_id = fa.id
            LEFT JOIN treasury_accounts ta ON t.to_treasury_account_id = ta.id
            WHERE t.tenant_id = :tid
            ORDER BY t.transfer_date DESC LIMIT 100
        ");
        $stmt->execute(['tid' => $tenantId]);
        jsonResponse(true, ['transfers' => $stmt->fetchAll()]);
    }

    if ($section === 'petty_cash') {
        $sql = "SELECT pc.*, p.name as project_name, a.account_name 
                FROM petty_cash_expenses pc 
                LEFT JOIN projects p ON pc.project_id = p.id 
                LEFT JOIN treasury_accounts a ON pc.petty_cash_account_id = a.id";
        $params = ['tid' => $tenantId];
        $sql .= " WHERE pc.tenant_id = :tid";
        if ($projectId) {
            $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
            $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
            if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
            $sql .= " AND pc.project_id = :pid";
            $params['pid'] = $projectId;
        }
        $sql .= " ORDER BY pc.expense_date DESC LIMIT 200";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse(true, ['pettyCashExpenses' => $stmt->fetchAll()]);
    }
}

// -------------------------------------------------------------
// POST: ثبت و پردازش عواید، مصارف و انتقالات در تراکنش‌های امن
// -------------------------------------------------------------
if ($method === 'POST') {
    $input = getJsonInput();
    $action = $_GET['action'] ?? '';
    $reqProjectId = !empty($input['projectId']) ? (string)$input['projectId'] : (!empty($input['fromProjectId']) ? (string)$input['fromProjectId'] : null);
    assertModulePermission($user, 'payments', 'create', $reqProjectId);
    enforceIdempotencyGuard($db, $user, 'finance:' . $action, $input);

    // ۱. ثبت عواید / دریافت جدید (Income Register)
    if ($action === 'create_income') {
        $id = generateUuid();
        $receiptNo = 'REC-' . date('ymd') . '-' . rand(100, 999);
        $projectId = $input['projectId'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        $accountId = $input['targetAccountId'] ?? '';
        $date = !empty($input['date']) ? $input['date'] : date('Y-m-d');
        $currency = $input['currency'] ?? 'AFN';
        $rate = (float)($input['exchangeRate'] ?? 1.0);
        $baseAmount = $currency === 'USD' ? ($amount * ($rate > 0 ? $rate : NSC_DEFAULT_EXCHANGE_RATE)) : $amount;

        if (empty($projectId) || $amount <= 0 || empty($accountId)) {
            jsonResponse(false, null, 'پروژه، مبلغ و حساب مقصد الزامی هستند.', 400);
        }

        // امنیت: پروژه و حساب مقصد باید متعلق به همین شرکت باشند
        $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
        $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
        $accStmt = $db->prepare("SELECT id FROM treasury_accounts WHERE id = :aid AND tenant_id = :tid LIMIT 1");
        $accStmt->execute(['aid' => $accountId, 'tid' => $tenantId]);
        if (!$accStmt->fetch()) { jsonResponse(false, null, 'حساب مقصد یافت نشد یا دسترسی ندارید.', 404); }

        $db->beginTransaction();
        try {
            // ثبت سند عواید
            $stmt = $db->prepare("
                INSERT INTO revenue_entries (
                    id, tenant_id, receipt_number, project_id, client_id, received_from,
                    income_type, amount, currency, exchange_rate, amount_in_base_afn,
                    payment_method, deposit_account_id, reference_document_no, received_date,
                    recorded_by_user_id, notes
                ) VALUES (
                    :id, :tid, :rec, :pid, :cid, :from,
                    :type, :amt, :curr, :rate, :base,
                    :method, :acc, :ref, :dt,
                    :user, :notes
                )
            ");
            $stmt->execute([
                'id' => $id,
                'tid' => $tenantId,
                'rec' => $receiptNo,
                'pid' => $projectId,
                'cid' => $input['clientId'] ?? null,
                'from' => $input['receivedFrom'] ?? 'مشتری / سرمایه‌گذار',
                'type' => $input['incomeType'] ?? 'client_installment',
                'amt' => $amount,
                'curr' => $currency,
                'rate' => $rate,
                'base' => $baseAmount,
                'method' => $input['paymentMethod'] ?? 'bank_transfer',
                'acc' => $accountId,
                'ref' => $input['referenceDoc'] ?? null,
                'dt' => $date,
                'user' => $user['id'],
                'notes' => $input['notes'] ?? null
            ]);

            // افزایش موجودی حساب بانک/صندوق
            $upAcc = $db->prepare("
                UPDATE treasury_accounts 
                SET current_balance = current_balance + :amt 
                WHERE id = :id AND tenant_id = :tid
            ");
            $upAcc->execute(['amt' => $amount, 'id' => $accountId, 'tid' => $tenantId]);

            $db->commit();
            logAudit($db, $user['id'], $user['name'], 'create', 'income', $id, "ثبت عواید به مبلغ $amount $currency");
            jsonResponse(true, ['id' => $id, 'receiptNo' => $receiptNo], 'دریافتی با موفقیت ثبت و موجودی حساب ارتقا یافت.');
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error saving income: " . $e->getMessage());
            jsonResponse(false, null, 'خطا در ثبت عواید در پایگاه داده.', 500);
        }
    }

    // ۲. ثبت پرداخت / هزینه جدید (Payment Register)
    if ($action === 'create_payment') {
        $id = generateUuid();
        $voucherNo = 'PV-' . date('ymd') . '-' . rand(100, 999);
        $projectId = $input['projectId'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        $accountId = $input['sourceAccountId'] ?? null;
        $date = !empty($input['date']) ? $input['date'] : date('Y-m-d');
        $currency = $input['currency'] ?? 'AFN';
        $rate = (float)($input['exchangeRate'] ?? 1.0);
        $baseAmount = $currency === 'USD' ? ($amount * ($rate > 0 ? $rate : NSC_DEFAULT_EXCHANGE_RATE)) : $amount;

        if (empty($projectId) || $amount <= 0) {
            jsonResponse(false, null, 'مشخصات پروژه و مبلغ پرداختی الزامی است.', 400);
        }

        // امنیت: پروژه و حساب مبدأ باید متعلق به همین شرکت باشند
        $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
        $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
        if (!empty($accountId)) {
            $accStmt = $db->prepare("SELECT id FROM treasury_accounts WHERE id = :aid AND tenant_id = :tid LIMIT 1");
            $accStmt->execute(['aid' => $accountId, 'tid' => $tenantId]);
            if (!$accStmt->fetch()) { jsonResponse(false, null, 'حساب مبدأ یافت نشد یا دسترسی ندارید.', 404); }
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO payment_vouchers (
                    id, tenant_id, voucher_no, project_id, payee_type, payee_id, payee_name,
                    expense_category, budget_category_id, amount, currency,
                    exchange_rate, amount_in_base_afn, payment_method, source_account_id,
                    invoice_or_bill_no, payment_date, payment_status, approved_by,
                    recorded_by_user_id, description, notes
                ) VALUES (
                    :id, :tid, :vno, :pid, :ptype, :pid_ref, :pname,
                    :cat, :bcat, :amt, :curr,
                    :rate, :base, :pmeth, :acc,
                    :inv, :pdate, 'paid', :appr,
                    :user, :desc, :notes
                )
            ");
            $stmt->execute([
                'id' => $id,
                'tid' => $tenantId,
                'vno' => $voucherNo,
                'pid' => $projectId,
                'ptype' => $input['payeeType'] ?? 'supplier',
                'pid_ref' => $input['payeeId'] ?? null,
                'pname' => $input['payeeName'] ?? 'شخص یا شرکت',
                'cat' => $input['expenseCategory'] ?? 'general',
                'bcat' => $input['budgetCategoryId'] ?? null,
                'amt' => $amount,
                'curr' => $currency,
                'rate' => $rate,
                'base' => $baseAmount,
                'pmeth' => $input['paymentMethod'] ?? 'cash',
                'acc' => $accountId,
                'inv' => $input['invoiceNo'] ?? null,
                'pdate' => $date,
                'appr' => $input['approvedBy'] ?? 'مدیریت پروژه',
                'user' => $user['id'],
                'desc' => $input['description'] ?? null,
                'notes' => $input['notes'] ?? null
            ]);

            // در صورتی که پرداخت از بانک یا صندوق باشد، موجودی کسر گردد
            if (!empty($accountId)) {
                $upAcc = $db->prepare("
                    UPDATE treasury_accounts 
                    SET current_balance = current_balance - :amt 
                    WHERE id = :id AND tenant_id = :tid
                ");
                $upAcc->execute(['amt' => $amount, 'id' => $accountId, 'tid' => $tenantId]);
            }

            $db->commit();
            logAudit($db, $user['id'], $user['name'], 'create', 'payments', $id, "ثبت سند پرداخت $voucherNo به مبلغ $amount");
            jsonResponse(true, ['id' => $id, 'voucherNo' => $voucherNo], 'پرداخت با موفقیت در دفاتر ثبت شد.');
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error saving payment: " . $e->getMessage());
            jsonResponse(false, null, 'خطا در ثبت پرداخت در پایگاه داده.', 500);
        }
    }

    // ۳. حواله و انتقال بین دو پروژه (حل اساسی مشکل شماره ۶ اکسل)
    if ($action === 'inter_project_transfer') {
        $id = generateUuid();
        $transferNo = 'TRF-' . date('ymd') . '-' . rand(100, 999);
        $fromProj = $input['fromProjectId'] ?? '';
        $toProj = $input['toProjectId'] ?? '';
        $amount = (float)($input['amount'] ?? 0);
        $fromAcc = $input['fromAccountId'] ?? null;
        $toAcc = $input['toAccountId'] ?? null;
        $date = !empty($input['transferDate']) ? $input['transferDate'] : date('Y-m-d');

        if (empty($fromProj) || empty($toProj) || $amount <= 0 || $fromProj === $toProj) {
            jsonResponse(false, null, 'اطلاعات پروژه‌های مبدأ و مقصد نامعتبر است.', 400);
        }

        // امنیت: هر دو پروژه و هر دو حساب باید متعلق به همین شرکت باشند
        foreach (['from_p' => $fromProj, 'to_p' => $toProj] as $lbl => $prj) {
            $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
            $ownStmt->execute(['pid' => $prj, 'tid' => $tenantId]);
            if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه مبدأ/مقصد یافت نشد یا دسترسی ندارید.', 404); }
        }
        foreach (['from_a' => $fromAcc, 'to_a' => $toAcc] as $lbl => $acc) {
            if (!empty($acc)) {
                $accStmt = $db->prepare("SELECT id FROM treasury_accounts WHERE id = :aid AND tenant_id = :tid LIMIT 1");
                $accStmt->execute(['aid' => $acc, 'tid' => $tenantId]);
                if (!$accStmt->fetch()) { jsonResponse(false, null, 'حساب مبدأ/مقصد یافت نشد یا دسترسی ندارید.', 404); }
            }
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO project_transfers (
                    id, tenant_id, transfer_number, from_project_id, to_project_id,
                    transfer_nature, amount_or_value, currency,
                    from_treasury_account_id, to_treasury_account_id,
                    transfer_date, approved_by, status, description
                ) VALUES (
                    :id, :tid, :tno, :from_p, :to_p,
                    :nature, :amt, 'AFN',
                    :from_a, :to_a,
                    :dt, :appr, 'approved', :desc
                )
            ");
            $stmt->execute([
                'id' => $id,
                'tid' => $tenantId,
                'tno' => $transferNo,
                'from_p' => $fromProj,
                'to_p' => $toProj,
                'nature' => $input['transferNature'] ?? 'funds',
                'amt' => $amount,
                'from_a' => $fromAcc,
                'to_a' => $toAcc,
                'dt' => $date,
                'appr' => $input['approvedBy'] ?? $user['name'],
                'desc' => $input['description'] ?? 'انتقال وجوه بین‌کارگاهی'
            ]);

            // کسر از حساب مبدأ و واریز به حساب مقصد در صورت تعریف حساب‌ها
            if (!empty($fromAcc)) {
                $db->prepare("UPDATE treasury_accounts SET current_balance = current_balance - :amt WHERE id = :id AND tenant_id = :tid")
                   ->execute(['amt' => $amount, 'id' => $fromAcc, 'tid' => $tenantId]);
            }
            if (!empty($toAcc)) {
                $db->prepare("UPDATE treasury_accounts SET current_balance = current_balance + :amt WHERE id = :id AND tenant_id = :tid")
                   ->execute(['amt' => $amount, 'id' => $toAcc, 'tid' => $tenantId]);
            }

            $db->commit();
            logAudit($db, $user['id'], $user['name'], 'transfer', 'transfers', $id, "انتقال بین پروژه‌ای $amount AFN از $fromProj به $toProj");
            jsonResponse(true, ['id' => $id, 'transferNo' => $transferNo], 'انتقال با موفقیت ثبت و اثر مالی آن اعمال شد.');
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error saving transfer: " . $e->getMessage());
            jsonResponse(false, null, 'خطا در ثبت انتقال در پایگاه داده.', 500);
        }
    }
}
