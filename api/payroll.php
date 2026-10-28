<?php
/**
 * NSC Labor Wages, Employee Profiles & Flexible Payroll Management API (Phase 5)
 * Supports:
 * - Employee Profiles (full name, contact info, assigned project/projects, free-text job position,
 *   start date, optional end date, optional daily start/end times, notes)
 * - Flexible Payroll (monthly, weekly, daily, biweekly, hourly, custom period)
 * - Strict separation between Earned Wages (Accrual) and Cash Payments (Cash Outflow)
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
    $sql = "SELECT lp.*, p.name as project_name 
            FROM labor_payroll lp 
            LEFT JOIN projects p ON lp.project_id = p.id";
    $params = ['tid' => $tenantId];
    $sql .= " WHERE lp.tenant_id = :tid";
    if ($projectId) {
        $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
        $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
        $sql .= " AND lp.project_id = :pid";
        $params['pid'] = $projectId;
    }
    $sql .= " ORDER BY lp.payroll_date DESC LIMIT 200";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $employees = [];
    try {
        $empStmt = $db->prepare("SELECT * FROM employees WHERE tenant_id = :tid ORDER BY created_at DESC");
        $empStmt->execute(['tid' => $tenantId]);
        $employees = $empStmt->fetchAll();
    } catch (Exception $e) {
        // Backward compatibility if migration not yet applied
    }

    jsonResponse(true, ['payroll' => $stmt->fetchAll(), 'employees' => $employees]);
}

if ($method === 'POST') {
    $input = getJsonInput();
    $action = $input['action'] ?? 'create_payroll';
    $reqProjectId = !empty($input['projectId']) ? (string)$input['projectId'] : null;
    if ($reqProjectId) {
        assertTenantProjectAccess($db, $reqProjectId, $tenantId, false);
    }
    assertModulePermission($user, 'employees', 'create', $reqProjectId);
    enforceIdempotencyGuard($db, $user, 'payroll:' . $action, $input);

    // Action 1: Create or update Employee Profile (Phase 5 Task 1 & 2)
    if ($action === 'save_employee') {
        $empId = !empty($input['id']) ? trim($input['id']) : generateUuid();
        $projectId = trim($input['projectId'] ?? '');
        $assignedIds = is_array($input['assignedProjectIds'] ?? null) ? $input['assignedProjectIds'] : [$projectId];
        $fullName = trim($input['fullName'] ?? '');
        $jobPosition = trim($input['jobPosition'] ?? '');
        $startDate = !empty($input['employmentStartDate']) ? $input['employmentStartDate'] : date('Y-m-d');
        $endDate = !empty($input['employmentEndDate']) ? $input['employmentEndDate'] : null;
        $dailyStart = !empty($input['dailyStartTime']) ? $input['dailyStartTime'] : null;
        $dailyEnd = !empty($input['dailyEndTime']) ? $input['dailyEndTime'] : null;
        $payType = trim($input['payType'] ?? 'monthly');
        $agreedAmount = (float)($input['agreedAmount'] ?? 0);
        $currency = strtoupper(trim($input['currency'] ?? 'AFN'));
        $effectiveDate = !empty($input['effectiveDate']) ? $input['effectiveDate'] : $startDate;

        if ($fullName === '' || $jobPosition === '' || $agreedAmount <= 0) {
            jsonResponse(false, null, 'نام کامل، موقف کاری و مبلغ توافق‌شده الزامی است.', 422);
        }

        try {
            $chkStmt = $db->prepare("SELECT tenant_id FROM employees WHERE id = :id LIMIT 1");
            $chkStmt->execute([':id' => $empId]);
            $existingEmp = $chkStmt->fetch();
            if ($existingEmp && !empty($existingEmp['tenant_id']) && $existingEmp['tenant_id'] !== $tenantId) {
                jsonResponse(false, null, 'عدم دسترسی به پروفایل کارمند شرکت دیگر.', 403);
            }

            $stmt = $db->prepare("
                INSERT INTO employees (
                    id, tenant_id, project_id, assigned_project_ids_json, full_name,
                    phone, secondary_phone, contact_info, job_position,
                    employment_start_date, employment_end_date, daily_start_time, daily_end_time,
                    pay_type, custom_period_label, custom_period_days, agreed_amount,
                    currency, effective_date, notes
                ) VALUES (
                    :id, :tid, :pid, :aids, :fname,
                    :ph1, :ph2, :cinfo, :jpos,
                    :sdate, :edate, :dstart, :dend,
                    :ptype, :clabel, :cdays, :agreed,
                    :curr, :efdate, :notes
                )
                ON DUPLICATE KEY UPDATE
                    project_id = VALUES(project_id),
                    assigned_project_ids_json = VALUES(assigned_project_ids_json),
                    full_name = VALUES(full_name),
                    phone = VALUES(phone),
                    secondary_phone = VALUES(secondary_phone),
                    contact_info = VALUES(contact_info),
                    job_position = VALUES(job_position),
                    employment_start_date = VALUES(employment_start_date),
                    employment_end_date = VALUES(employment_end_date),
                    daily_start_time = VALUES(daily_start_time),
                    daily_end_time = VALUES(daily_end_time),
                    pay_type = VALUES(pay_type),
                    custom_period_label = VALUES(custom_period_label),
                    custom_period_days = VALUES(custom_period_days),
                    agreed_amount = VALUES(agreed_amount),
                    currency = VALUES(currency),
                    effective_date = VALUES(effective_date),
                    notes = VALUES(notes)
            ");
            $stmt->execute([
                ':id' => $empId,
                ':tid' => $tenantId,
                ':pid' => $projectId,
                ':aids' => json_encode(array_values(array_unique($assignedIds)), JSON_UNESCAPED_UNICODE),
                ':fname' => $fullName,
                ':ph1' => $input['phone'] ?? null,
                ':ph2' => $input['secondaryPhone'] ?? null,
                ':cinfo' => $input['contactInfo'] ?? null,
                ':jpos' => $jobPosition,
                ':sdate' => $startDate,
                ':edate' => $endDate,
                ':dstart' => $dailyStart,
                ':dend' => $dailyEnd,
                ':ptype' => $payType,
                ':clabel' => $input['customPeriodLabel'] ?? null,
                ':cdays' => isset($input['customPeriodDays']) ? (int)$input['customPeriodDays'] : null,
                ':agreed' => $agreedAmount,
                ':curr' => $currency,
                ':efdate' => $effectiveDate,
                ':notes' => $input['notes'] ?? null,
            ]);
            logAudit($db, $user['id'], $user['name'], 'create', 'labor', $empId, "ذخیره پروفایل کارمند: $fullName ($jobPosition)");
            jsonResponse(true, ['id' => $empId], 'پروفایل کارمند با موفقیت ذخیره شد.');
        } catch (Exception $e) {
            error_log("Payroll save_employee error: " . $e->getMessage());
            jsonResponse(false, null, 'خطا در ذخیره پروفایل کارمند در پایگاه داده.', 500);
        }
    }

    // Default Action: Record Payroll Earned Wages (and optional Cash Payment)
    $id = generateUuid();
    $payrollNo = 'PAY-' . date('ymd') . '-' . rand(100, 999);
    $projectId = $input['projectId'] ?? '';
    $workerName = trim($input['workerOrTeamLeader'] ?? $input['workerName'] ?? '');
    $workType = trim($input['workTypeDescription'] ?? $input['workType'] ?? 'کارمند ساحه');
    $payType = trim($input['payType'] ?? 'daily');
    $daysOrUnits = (float)($input['daysOrWorkUnits'] ?? $input['days'] ?? 0);
    $rate = (float)($input['ratePerUnit'] ?? $input['rate'] ?? 0);
    $currency = strtoupper(trim($input['currency'] ?? 'AFN'));
    $advance = (float)($input['advanceDeduction'] ?? $input['advance'] ?? 0);

    // Earned wages (No automatic overtime or deductions without configured rules)
    $earnedAmount = isset($input['earnedAmount']) ? round((float)$input['earnedAmount'], 2) : round($daysOrUnits * $rate, 2);
    // Cash paid (only what was actually paid; do NOT confuse earned wages with cash payments)
    $amountPaid = isset($input['amountPaid']) ? round((float)$input['amountPaid'], 2) : max(0, $earnedAmount - $advance);
    $remainingPayable = max(0, round($earnedAmount - $amountPaid, 2));
    $isSettled = ($earnedAmount > 0 && $remainingPayable <= 0.009) ? 1 : 0;

    $date = !empty($input['payrollDate']) ? $input['payrollDate'] : date('Y-m-d');
    $approvedBy = $input['approvedBy'] ?? $user['name'];

    if (empty($projectId) || empty($workerName) || $daysOrUnits <= 0 || $rate <= 0) {
        jsonResponse(false, null, 'پروژه، نام کارمند/کارگر، مقدار کارکرد و نرخ توافق‌شده الزامی هستند.', 400);
    }

    $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
    $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
    if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }

    $db->beginTransaction();
    try {
        $paymentVoucherId = null;

        // Only create a cash payment voucher if actual cash was paid (amountPaid > 0)
        if ($amountPaid > 0) {
            $paymentVoucherId = generateUuid();
            $pvNo = 'PV-LAB-' . date('ymd') . '-' . rand(100, 999);

            $pvStmt = $db->prepare("
                INSERT INTO payment_vouchers (
                    id, tenant_id, voucher_no, project_id, payee_type, payee_name,
                    expense_category, amount, currency, exchange_rate, amount_in_base_afn,
                    payment_method, payment_date, payment_status, approved_by,
                    recorded_by_user_id, description
                ) VALUES (
                    :id, :tid, :vno, :pid, 'labor_wage', :wname,
                    'labor', :amt, :curr, 1.0, :amt2,
                    'cash', :dt, 'paid', :appr,
                    :uid, :desc
                )
            ");
            $pvStmt->execute([
                'id' => $paymentVoucherId,
                'tid' => $tenantId,
                'vno' => $pvNo,
                'pid' => $projectId,
                'wname' => $workerName,
                'amt' => $amountPaid,
                'curr' => $currency,
                'amt2' => $amountPaid,
                'dt' => $date,
                'appr' => $approvedBy,
                'uid' => $user['id'],
                'desc' => "پرداخت معاش $workerName ($workType) — استحقاق: $earnedAmount $currency | پرداختی: $amountPaid $currency"
            ]);
        }

        $stmt = $db->prepare("
            INSERT INTO labor_payroll (
                id, tenant_id, payroll_number, project_id, worker_or_team_leader,
                work_type_description, days_or_work_units, rate_per_unit,
                gross_amount, advance_deduction, net_amount_paid, currency,
                payment_voucher_id, payroll_date, approved_by, is_fully_settled, notes
            ) VALUES (
                :id, :tid, :pno, :pid, :wname,
                :wtype, :units, :rate,
                :gross, :adv, :net, :curr,
                :pvid, :dt, :appr, :settled, :notes
            )
        ");
        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'pno' => $payrollNo,
            'pid' => $projectId,
            'wname' => $workerName,
            'wtype' => $workType,
            'units' => $daysOrUnits,
            'rate' => $rate,
            'gross' => $earnedAmount,
            'adv' => $advance,
            'net' => $remainingPayable,
            'curr' => $currency,
            'pvid' => $paymentVoucherId,
            'dt' => $date,
            'appr' => $approvedBy,
            'settled' => $isSettled,
            'notes' => $input['notes'] ?? null
        ]);

        $db->commit();
        logAudit($db, $user['id'], $user['name'], 'create', 'labor', $id, "ثبت معاش $workerName: استحقاق $earnedAmount $currency | پرداختی $amountPaid $currency | باقی‌مانده $remainingPayable $currency");
        jsonResponse(true, [
            'id' => $id,
            'payrollNo' => $payrollNo,
            'earnedAmount' => $earnedAmount,
            'amountPaid' => $amountPaid,
            'remainingPayable' => $remainingPayable,
            'grossAmount' => $earnedAmount,
            'netAmount' => $remainingPayable
        ], 'استحقاق معاش کارمند با موفقیت ثبت گردید.');
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Payroll create error: " . $e->getMessage());
        jsonResponse(false, null, 'خطا در ثبت معاش کارمند در پایگاه داده.', 500);
    }
}
