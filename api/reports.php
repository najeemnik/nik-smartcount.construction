<?php
/**
 * NSC Executive Reporting & P&L Analysis API
 * Solves Excel Flaw #1: Multi-Year Monthly Performance Reports (Year + Month)
 * Generates Real-time Budget vs Actual and Profitability Snapshots
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

require_once __DIR__.'/report-data.php';
sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
$user = authenticateUser(false);
$tenantId = currentTenantId($user);

$reportType = $_GET['type'] ?? 'dashboard';
$projectId = $_GET['projectId'] ?? null;
if(in_array($reportType,['drilldown','transaction_detail'],true)){
    if(hasProjectRestrictions($user)&&!$projectId)jsonResponse(false,null,'یک پروژهٔ مجاز را انتخاب کنید.',403);
}else requireScopedProject($user, $reportType === 'dashboard' ? 'dashboard' : 'reports', $projectId);
$year = (int)($_GET['year'] ?? date('Y'));

if (!empty($projectId)) {
    assertTenantProjectAccess($db, $projectId, $tenantId, false);
}
if ($reportType !== 'drilldown' && $reportType !== 'transaction_detail') {
    assertModulePermission($user, $reportType === 'dashboard' ? 'dashboard' : 'reports', 'view', $projectId ? (string)$projectId : null);
}

// -------------------------------------------------------------
// ۱. گزارش داشبورد کلان و شاخص‌های کلیدی (Executive Dashboard)
// -------------------------------------------------------------
if ($reportType === 'dashboard') {
    if(hasProjectRestrictions($user))jsonResponse(false,null,'داشبورد کل شرکت فقط برای حساب دارای دسترسی سراسری است.',403);
    if($projectId)jsonResponse(false,null,'برای گزارش یک پروژه از project_pnl یا monthly_timeline استفاده کنید؛ داشبورد سراسری است.',400);
    // مجموع درآمد
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount_in_base_afn), 0) as total_rev FROM revenue_entries WHERE tenant_id = :tid");
    $stmt->execute(['tid' => $tenantId]);
    $totalRevenue = (float)$stmt->fetch()['total_rev'];

    // مجموع مصارف
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount_in_base_afn), 0) as total_exp FROM payment_vouchers WHERE tenant_id = :tid AND payment_status != 'cancelled'");
    $stmt->execute(['tid' => $tenantId]);
    $totalExpenses = (float)$stmt->fetch()['total_exp'];

    // مجموع بودجه مصوب پروژه‌ها
    $stmt = $db->prepare("SELECT COALESCE(SUM(approved_budget), 0) as total_bgt FROM projects WHERE tenant_id = :tid AND status != 'archived'");
    $stmt->execute(['tid' => $tenantId]);
    $totalBudget = (float)$stmt->fetch()['total_bgt'];

    // موجودی بانک‌ها و صندوق‌ها
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN account_type = 'bank' THEN current_balance ELSE 0 END), 0) as bank_balance,
            COALESCE(SUM(CASE WHEN account_type = 'central_cash' THEN current_balance ELSE 0 END), 0) as cash_balance,
            COALESCE(SUM(CASE WHEN account_type = 'petty_cash' THEN current_balance ELSE 0 END), 0) as petty_balance
        FROM treasury_accounts
        WHERE tenant_id = :tid
    ");
    $stmt->execute(['tid' => $tenantId]);
    $treasury = $stmt->fetch();

    // مطالبات از مشتریان (Receivables)
    $stmt = $db->prepare("SELECT COALESCE(SUM(outstanding_receivable), 0) as total_rec FROM clients WHERE tenant_id = :tid");
    $stmt->execute(['tid' => $tenantId]);
    $totalReceivables = (float)$stmt->fetch()['total_rec'];

    // بدهی‌ها به تأمین‌کنندگان (Payables)
    $stmt = $db->prepare("SELECT COALESCE(SUM(current_payable_balance), 0) as total_pay FROM suppliers WHERE tenant_id = :tid");
    $stmt->execute(['tid' => $tenantId]);
    $totalPayables = (float)$stmt->fetch()['total_pay'];

    // تعداد پروژه‌های فعال و تکمیل شده
    $stmt = $db->prepare("
        SELECT
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count
        FROM projects
        WHERE tenant_id = :tid
    ");
    $stmt->execute(['tid' => $tenantId]);
    $counts = $stmt->fetch();

    $netProfit = $totalRevenue - $totalExpenses;
    $profitMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;
    $budgetUsedPercent = $totalBudget > 0 ? ($totalExpenses / $totalBudget) * 100 : 0;

    jsonResponse(true, [
        'totalRevenue' => $totalRevenue,
        'totalExpenses' => $totalExpenses,
        'netProfit' => $netProfit,
        'profitMarginPercent' => round($profitMargin, 2),
        'totalBudget' => $totalBudget,
        'budgetUsedPercent' => round($budgetUsedPercent, 2),
        'bankBalance' => (float)$treasury['bank_balance'],
        'cashBalance' => (float)$treasury['cash_balance'],
        'pettyBalance' => (float)$treasury['petty_balance'],
        'totalReceivables' => $totalReceivables,
        'totalPayables' => $totalPayables,
        'activeProjectsCount' => (int)$counts['active_count'],
        'completedProjectsCount' => (int)$counts['completed_count']
    ]);
}

// -------------------------------------------------------------
// ۲. گزارش سود و زیان جامع پروژه‌ها (Project P&L Snapshot)
// -------------------------------------------------------------
if ($reportType === 'project_pnl') {
    $stmt = $db->prepare("
        SELECT
            p.id, p.project_code, p.name, p.status, p.approved_budget,
            COALESCE((SELECT SUM(amount_in_base_afn) FROM revenue_entries WHERE project_id = p.id AND tenant_id = :tid1), 0) as actual_revenue,
            COALESCE((SELECT SUM(amount_in_base_afn) FROM payment_vouchers WHERE project_id = p.id AND tenant_id = :tid2 AND payment_status != 'cancelled'), 0) as actual_cost
        FROM projects p
        WHERE p.tenant_id = :tid3" . ($projectId ? " AND p.id=:pid" : "") . "
        ORDER BY p.created_at DESC
    ");
    $stmt->execute(['tid1'=>$tenantId,'tid2'=>$tenantId,'tid3'=>$tenantId]+($projectId?['pid'=>$projectId]:[]));
    $rows = $stmt->fetchAll();

    $pnlList = [];
    foreach ($rows as $row) {
        $rev = (float)$row['actual_revenue'];
        $cost = (float)$row['actual_cost'];
        $budget = (float)$row['approved_budget'];
        $profit = $rev - $cost;
        $margin = $rev > 0 ? ($profit / $rev) * 100 : 0;
        $budgetUsed = $budget > 0 ? ($cost / $budget) * 100 : 0;
        $remainingBudget = $budget - $cost;

        $pnlList[] = [
            'projectId' => $row['id'],
            'projectCode' => $row['project_code'],
            'projectName' => $row['name'],
            'status' => $row['status'],
            'budget' => $budget,
            'revenue' => $rev,
            'actualCost' => $cost,
            'grossProfit' => $profit,
            'profitMarginPercent' => round($margin, 2),
            'budgetUsedPercent' => round($budgetUsed, 2),
            'remainingBudget' => $remainingBudget,
            'isOverBudget' => $cost > $budget
        ];
    }

    jsonResponse(true, ['pnl' => $pnlList]);
}

// -------------------------------------------------------------
// ۳. گزارش مقایسه بودجه با مصرف واقعی (Budget vs Actual)
// -------------------------------------------------------------
if ($reportType === 'budget_vs_actual') {
    if (empty($projectId)) {
        jsonResponse(false, null, 'شناسه پروژه مشخص نشده است.', 400);
    }

    // امنیت: پروژه باید متعلق به همین شرکت باشد
    $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
    $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
    if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }

    // بودجه‌های سرفصل
    $bStmt = $db->prepare("SELECT * FROM project_budgets WHERE project_id = :pid AND tenant_id = :tid");
    $bStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
    $budgets = $bStmt->fetchAll();

    // مصارف تفکیک شده
    $eStmt = $db->prepare("
        SELECT expense_category, SUM(amount_in_base_afn) as actual_spent
        FROM payment_vouchers
        WHERE project_id = :pid AND tenant_id = :tid AND payment_status != 'cancelled'
        GROUP BY expense_category
    ");
    $eStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
    $spentMap = [];
    foreach ($eStmt->fetchAll() as $s) {
        $spentMap[$s['expense_category']] = (float)$s['actual_spent'];
    }

    $comparison = [];
    foreach ($budgets as $b) {
        $code = $b['category_code'];
        $allocated = (float)$b['budgeted_amount'];
        $actual = $spentMap[$code] ?? 0;
        $variance = $allocated - $actual;

        $comparison[] = [
            'categoryCode' => $code,
            'categoryName' => $b['category_name'],
            'budgeted' => $allocated,
            'actual' => $actual,
            'variance' => $variance,
            'isOverBudget' => $actual > $allocated,
            'percentUsed' => $allocated > 0 ? round(($actual / $allocated) * 100, 2) : 0
        ];
    }

    jsonResponse(true, ['comparison' => $comparison]);
}

// -------------------------------------------------------------
// ۴. گزارش ماهانه هوشمند با تفکیک سال (Monthly Report - Flaw #1 Solved)
// -------------------------------------------------------------
if ($reportType === 'monthly_timeline') {
    // محاسبه دقیق عواید ماهانه برای سال مشخص
    $revStmt = $db->prepare("
        SELECT
            MONTH(received_date) as m,
            SUM(amount_in_base_afn) as monthly_income
        FROM revenue_entries
        WHERE YEAR(received_date) = :yr AND tenant_id = :tid" . ($projectId ? " AND project_id=:pid" : "") . "
        GROUP BY MONTH(received_date)
    ");
    $revStmt->execute(['yr'=>$year,'tid'=>$tenantId]+($projectId?['pid'=>$projectId]:[]));
    $revMonths = [];
    foreach ($revStmt->fetchAll() as $r) {
        $revMonths[(int)$r['m']] = (float)$r['monthly_income'];
    }

    // محاسبه مصارف ماهانه برای همان سال
    $expStmt = $db->prepare("
        SELECT
            MONTH(payment_date) as m,
            SUM(amount_in_base_afn) as monthly_expense
        FROM payment_vouchers
        WHERE YEAR(payment_date) = :yr AND tenant_id = :tid" . ($projectId ? " AND project_id=:pid" : "") . " AND payment_status != 'cancelled'
        GROUP BY MONTH(payment_date)
    ");
    $expStmt->execute(['yr'=>$year,'tid'=>$tenantId]+($projectId?['pid'=>$projectId]:[]));
    $expMonths = [];
    foreach ($expStmt->fetchAll() as $e) {
        $expMonths[(int)$e['m']] = (float)$e['monthly_expense'];
    }

    $monthsNames = [
        1 => 'جنوری', 2 => 'فبروری', 3 => 'مارچ',
        4 => 'اپریل', 5 => 'می', 6 => 'جون',
        7 => 'جولای', 8 => 'اگست', 9 => 'سپتمبر',
        10 => 'اکتوبر', 11 => 'نوامبر', 12 => 'دسمبر'
    ];

    $timeline = [];
    for ($m = 1; $m <= 12; $m++) {
        $inc = $revMonths[$m] ?? 0;
        $exp = $expMonths[$m] ?? 0;
        $net = $inc - $exp;

        $timeline[] = [
            'year' => $year,
            'month' => $m,
            'monthName' => $monthsNames[$m],
            'income' => $inc,
            'expense' => $exp,
            'netMovement' => $net
        ];
    }

    jsonResponse(true, ['year' => $year, 'months' => $timeline]);
}

// -------------------------------------------------------------
// ۵. گزارش یکپارچه مالی و سیخ‌گول پروژه (Phase 8: Unified Financial & Steel Rebar Report)
// -------------------------------------------------------------
if ($reportType === 'unified_financial' || $reportType === 'steel_rebar') {
    $steelRows = [];
    $totalSteelKg = 0.0;
    $totalSteelTon = 0.0;
    $totalSteelCostUSD = 0.0;
    $totalSteelPaidUSD = 0.0;
    $steelCostByCurrency = [];

    try {
        foreach (steelReportRows($db,$tenantId,$projectId) as $r) {
            $status = strtolower((string)($r['status'] ?? $r['doc_status'] ?? 'active'));
            if ($status === 'cancelled' || $status === 'void' || $status === 'deleted') {
                continue;
            }
            $ton = (float)($r['confirmed_total_ton'] ?? $r['actual_total_ton'] ?? $r['tons'] ?? 0);
            $kg = (float)($r['kg'] ?? ($ton * 1000));
            if ($ton <= 0 && $kg > 0) $ton = round($kg / 1000, 4);
            if ($kg <= 0 && $ton > 0) $kg = round($ton * 1000, 3);

            $currency = strtoupper(trim((string)($r['currency'] ?? 'USD'))) ?: 'USD';
            $exRate = isset($r['exchange_rate']) && (float)$r['exchange_rate'] > 0 ? (float)$r['exchange_rate'] : null;
            $nativeCost = (float)($r['total_native_cost'] ?? ($currency === 'AFN' ? ($r['amount_in_afn'] ?? 0) : ($r['total_amount'] ?? 0)));
            $costUSD = null;
            if ($currency === 'USD') {
                $costUSD = round((float)($r['amount_in_usd'] ?? $r['total_amount'] ?? $nativeCost), 2);
            } elseif ($exRate !== null && $exRate > 0) {
                $costUSD = round($nativeCost / $exRate, 2);
            }

            $paidUSD = (float)($r['paid_amount'] ?? 0);
            $totalSteelKg += $kg;
            $totalSteelTon += $ton;
            if ($costUSD !== null) $totalSteelCostUSD += $costUSD;
            $totalSteelPaidUSD += $paidUSD;
            $steelCostByCurrency[$currency] = round(($steelCostByCurrency[$currency] ?? 0) + $nativeCost, 2);

            $steelRows[] = [
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
                'paidAmountUSD' => round($paidUSD, 2),
                'remainingBalanceUSD' => $costUSD !== null ? max(0, round($costUSD - $paidUSD, 2)) : 0,
            ];
        }
    } catch (Throwable $e) {
        throw $e;
    }

    jsonResponse(true, [
        'projectId' => $projectId,
        'steelReport' => [
            'totalWeightKg' => round($totalSteelKg, 3),
            'totalWeightTon' => round($totalSteelTon, 4),
            'totalCostUSD' => round($totalSteelCostUSD, 2),
            'totalPaidUSD' => round($totalSteelPaidUSD, 2),
            'totalRemainingUSD' => max(0, round($totalSteelCostUSD - $totalSteelPaidUSD, 2)),
            'totalCostByCurrency' => $steelCostByCurrency,
            'columns' => ['Date', 'Size', 'Brand', 'Supplier', 'Bundles', 'Weight Kg', 'Weight Ton', 'Cost USD', 'Actions'],
            'rows' => $steelRows,
        ],
    ]);
}

// -------------------------------------------------------------
// ۶. کاوش تعاملی شاخص‌های داشبورد و جزئیات تراکنش و بل‌ها (Phase 9: Interactive Drill-Down & Invoice Links)
// -------------------------------------------------------------
if ($reportType === 'drilldown' || $reportType === 'transaction_detail') {
    $authUser = $user;
    $metric = strtolower(trim((string)($_GET['metric'] ?? 'total_expenses')));
    $recordId = isset($_GET['recordId']) ? trim((string)$_GET['recordId']) : null;
    $attachmentId = isset($_GET['attachmentId']) ? trim((string)$_GET['attachmentId']) : null;

    // Permission check helper per metric/module
    $moduleMap = [
        'steel_cost' => 'steel',
        'steel_weight_kg' => 'steel',
        'steel_weight_ton' => 'steel',
        'steel_paid' => 'steel',
        'steel_debt' => 'steel',
        'concrete_cost' => 'concrete',
        'concrete_volume' => 'concrete',
        'concrete_paid' => 'concrete',
        'concrete_debt' => 'concrete',
        'contractor_payments' => 'payments',
        'contractor_debt' => 'contractors',
        'supplier_payments' => 'payments',
        'supplier_debt' => 'suppliers',
        'sales_total' => 'sales',
        'sales_collected' => 'sales',
        'sales_receivables' => 'sales',
    ];
    $requiredSection = $moduleMap[$metric] ?? 'reports';

    assertModulePermission($authUser, $requiredSection, 'view', $projectId ? (string)$projectId : null);
    if ($attachmentId !== null) {
        assertModulePermission($authUser, 'documents', 'view', $projectId ? (string)$projectId : null);
    }

    $items = [];
    $totalContributedValue = 0.0;
    $contributedUnit = 'USD';
    if ($metric === 'steel_weight_kg') $contributedUnit = 'kg';
    elseif ($metric === 'steel_weight_ton') $contributedUnit = 't';
    elseif ($metric === 'concrete_volume') $contributedUnit = 'm³';

    try {
        if (strpos($metric, 'steel_') === 0) {
            foreach (steelReportRows($db,$tenantId,$projectId,$recordId) as $r) {
                $status = strtolower((string)($r['status'] ?? $r['doc_status'] ?? 'active'));
                if ($status === 'cancelled' || $status === 'void' || $status === 'deleted') continue;

                $ton = (float)($r['confirmed_total_ton'] ?? $r['actual_total_ton'] ?? $r['tons'] ?? 0);
                $kg = (float)($r['kg'] ?? ($ton * 1000));
                if ($ton <= 0 && $kg > 0) $ton = round($kg / 1000, 4);
                if ($kg <= 0 && $ton > 0) $kg = round($ton * 1000, 3);

                $currency = strtoupper(trim((string)($r['currency'] ?? 'USD'))) ?: 'USD';
                $exRate = isset($r['exchange_rate']) && (float)$r['exchange_rate'] > 0 ? (float)$r['exchange_rate'] : 70.0;
                $nativeCost = (float)($r['total_native_cost'] ?? ($currency === 'AFN' ? ($r['amount_in_afn'] ?? 0) : ($r['total_amount'] ?? 0)));
                $costUSD = $currency === 'USD'
                    ? round((float)($r['amount_in_usd'] ?? $r['total_amount'] ?? $nativeCost), 2)
                    : round($nativeCost / $exRate, 2);
                $paidUSD = round((float)($r['paid_amount'] ?? 0), 2);
                $remUSD = max(0, round($costUSD - $paidUSD, 2));

                $contrib = $costUSD;
                if ($metric === 'steel_weight_kg') $contrib = $kg;
                elseif ($metric === 'steel_weight_ton') $contrib = $ton;
                elseif ($metric === 'steel_paid') $contrib = $paidUSD;
                elseif ($metric === 'steel_debt') $contrib = $remUSD;

                if (($metric === 'steel_paid' || $metric === 'steel_debt') && $contrib <= 0) continue;

                $invoices = [];
                if (!empty($r['invoice_url'])) {
                    $invoices[] = [
                        'id' => 'inv-' . $r['id'],
                        'title' => $r['invoice_number'] ?: 'Invoice',
                        'url' => $r['invoice_url'],
                        'source' => 'invoice_url',
                    ];
                }

                $totalContributedValue += $contrib;
                $items[] = [
                    'id' => $r['id'],
                    'sourceModule' => 'steel',
                    'date' => $r['date'],
                    'time' => !empty($r['created_at']) ? substr((string)$r['created_at'], 11, 5) : '—',
                    'projectId' => $r['project_id'],
                    'reference' => $r['invoice_number'] ?: ('STL-' . substr((string)$r['id'], 0, 8)),
                    'responsibleUser' => $r['created_by_name'] ?? $r['created_by'] ?? 'سیستم',
                    'partyName' => $r['supplier_name'] ?: '—',
                    'partyRole' => 'supplier',
                    'quantity' => $metric === 'steel_weight_kg' ? round($kg, 3) : round($ton, 4),
                    'unit' => $metric === 'steel_weight_kg' ? 'kg' : 't',
                    'unitRate' => isset($r['price_per_ton']) ? (float)$r['price_per_ton'] : null,
                    'totalAmountNative' => round($nativeCost, 2),
                    'totalAmountUSD' => $costUSD,
                    'currency' => $currency,
                    'paymentStatus' => $remUSD <= 0.01 ? 'paid' : ($paidUSD > 0 ? 'partial' : 'unpaid'),
                    'hasInvoice' => count($invoices) > 0,
                    'invoices' => $invoices,
                    'contributedValue' => round($contrib, 4),
                    'contributedUnit' => $contributedUnit,
                ];
            }
        }
    } catch (Throwable $e) {
        throw $e;
    }

    jsonResponse(true, [
        'metric' => $metric,
        'projectId' => $projectId,
        'contributedUnit' => $contributedUnit,
        'totalContributedValue' => round($totalContributedValue, 4),
        'items' => $items,
    ]);
}

jsonResponse(false,null,'روش یا عملیات پشتیبانی نمی‌شود.', in_array($method,['GET','POST','PUT','DELETE'],true)?404:405);
