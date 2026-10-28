<?php
/**
 * NSC Double-Entry General Journal & Adjustments API
 * Solves Excel Flaw #5: Strict Balanced Journal System (Total Debits == Total Credits)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
$user = authenticateUser(false);
$tenantId = currentTenantId($user);

if ($method === 'GET') {
    assertModulePermission($user, 'journal', 'view');
    $action = $_GET['action'] ?? 'entries';

    if ($action === 'chart_of_accounts') {
        // حساب‌های سیستمی (tenant_id = NULL) + حساب‌های سفارشی همین شرکت.
        // پیش از این بدون فیلتر خوانده می‌شد، یعنی حساب‌های سفارشی شرکت‌های دیگر هم دیده می‌شد.
        $stmt = $db->prepare("SELECT * FROM chart_of_accounts WHERE tenant_id IS NULL OR tenant_id = :tid ORDER BY account_code ASC");
        $stmt->execute(['tid' => $tenantId]);
        jsonResponse(true, ['accounts' => $stmt->fetchAll()]);
    }

    if ($action === 'entries') {
        $stmt = $db->prepare("
            SELECT j.*, p.name as project_name 
            FROM journal_entries j 
            LEFT JOIN projects p ON j.project_id = p.id 
            WHERE j.tenant_id = :tid
            ORDER BY j.entry_date DESC LIMIT 100
        ");
        $stmt->execute(['tid' => $tenantId]);
        $entries = $stmt->fetchAll();

        // رفع N+1: قبلاً برای هر سند یک کوئری جدا اجرا می‌شد (تا ۱۰۱ کوئری برای ۱۰۰ سند).
        // اکنون همهٔ ردیف‌ها با یک کوئری خوانده و در PHP گروه‌بندی می‌شوند.
        $linesByEntry = [];
        if (!empty($entries)) {
            $ids = array_values(array_filter(array_map(fn($e) => (string)($e['id'] ?? ''), $entries)));
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $lStmt = $db->prepare("
                    SELECT jl.*, ca.account_name, ca.account_code
                    FROM journal_lines jl
                    LEFT JOIN chart_of_accounts ca ON jl.account_id = ca.id
                    WHERE jl.journal_entry_id IN ($placeholders)
                ");
                $lStmt->execute($ids);
                foreach ($lStmt->fetchAll() as $line) {
                    $linesByEntry[(string)$line['journal_entry_id']][] = $line;
                }
            }
        }
        foreach ($entries as &$entry) {
            $entry['lines'] = $linesByEntry[(string)$entry['id']] ?? [];
        }
        unset($entry);

        jsonResponse(true, ['entries' => $entries]);
    }
}

if ($method === 'POST') {
    $input = getJsonInput();
    assertModulePermission($user, 'journal', 'create', $input['projectId'] ?? null);
    enforceIdempotencyGuard($db, $user, 'journal:post', $input);

    $id = generateUuid();
    $voucherNo = 'JV-' . date('ymd') . '-' . rand(100, 999);
    $date = !empty($input['entryDate']) ? $input['entryDate'] : date('Y-m-d');
    $narration = trim($input['narration'] ?? 'سند تعدیلات حسابداری');
    $projectId = $input['projectId'] ?? null;
    $lines = $input['lines'] ?? [];

    if (empty($lines) || count($lines) < 2) {
        jsonResponse(false, null, 'سند روزنامچه باید حداقل دو ردیف حساب (بدهکار و بستانکار) داشته باشد.', 400);
    }

    $totalDebit = 0;
    $totalCredit = 0;
    foreach ($lines as $line) {
        $totalDebit += (float)($line['debitAmount'] ?? $line['debit'] ?? 0);
        $totalCredit += (float)($line['creditAmount'] ?? $line['credit'] ?? 0);
    }

    // بررسی اصل تعادل حسابداری دوطرفه (Total Debits == Total Credits)
    if (abs($totalDebit - $totalCredit) > 0.01) {
        jsonResponse(false, null, "سند تراز نیست! مجموع بدهکار ($totalDebit) با بستانکار ($totalCredit) برابر نمی‌باشد.", 400);
    }

    // اعتبارسنجی حساب‌ها: بدون این بررسی، ردیف‌هایی با account_id ناموجود در دیتابیس ثبت می‌شد
    // (هیچ کلید خارجی وجود ندارد) و سند در گزارش‌ها بدون نام حساب ظاهر می‌شد.
    $accountIds = [];
    foreach ($lines as $line) {
        if (!is_array($line)) continue;
        $aid = trim((string)($line['accountId'] ?? ''));
        if ($aid !== '') $accountIds[$aid] = true;
    }
    if (empty($accountIds)) {
        jsonResponse(false, null, 'هر ردیف سند باید حساب انتخاب‌شده داشته باشد.', 400);
    }
    $ids = array_keys($accountIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $chk = $db->prepare("SELECT id FROM chart_of_accounts WHERE id IN ($placeholders) AND (tenant_id IS NULL OR tenant_id = ?)");
    $chk->execute(array_merge($ids, [$tenantId]));
    $valid = array_flip(array_map('strval', $chk->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($ids as $aid) {
        if (!isset($valid[(string)$aid])) {
            jsonResponse(false, null, 'حساب انتخاب‌شده معتبر نیست: ' . $aid, 400);
        }
    }

    // امنیت: پروژه (در صورت انتخاب) باید متعلق به همین شرکت باشد
    if (!empty($projectId)) {
        $ownStmt = $db->prepare("SELECT id FROM projects WHERE id = :pid AND tenant_id = :tid LIMIT 1");
        $ownStmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        if (!$ownStmt->fetch()) { jsonResponse(false, null, 'پروژه یافت نشد یا دسترسی ندارید.', 404); }
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO journal_entries (
                id, tenant_id, voucher_number, entry_date, project_id, narration,
                total_debit, total_credit, is_balanced, is_posted, created_by_user_id
            ) VALUES (
                :id, :tid, :vno, :dt, :pid, :nar,
                :deb, :cred, 1, 1, :uid
            )
        ");
        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'vno' => $voucherNo,
            'dt' => $date,
            'pid' => $projectId,
            'nar' => $narration,
            'deb' => $totalDebit,
            'cred' => $totalCredit,
            'uid' => $user['id']
        ]);

        $lineStmt = $db->prepare("
            INSERT INTO journal_lines (
                id, journal_entry_id, account_id, debit_amount, credit_amount, memo
            ) VALUES (
                :lid, :jid, :aid, :deb, :cred, :memo
            )
        ");

        foreach ($lines as $line) {
            $lid = generateUuid();
            $lineStmt->execute([
                'lid' => $lid,
                'jid' => $id,
                'aid' => $line['accountId'],
                'deb' => (float)($line['debitAmount'] ?? $line['debit'] ?? 0),
                'cred' => (float)($line['creditAmount'] ?? $line['credit'] ?? 0),
                'memo' => $line['memo'] ?? null
            ]);
        }

        $db->commit();
        logAudit($db, $user['id'], $user['name'], 'create', 'journal', $id, "ثبت سند روزنامچه $voucherNo به مبلغ $totalDebit AFN");
        jsonResponse(true, ['id' => $id, 'voucherNumber' => $voucherNo], 'سند حسابداری با رعایت تراز دوطرفه با موفقیت ثبت گردید.');
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Journal POST error: " . $e->getMessage());
        jsonResponse(false, null, 'خطا در ثبت سند حسابداری در پایگاه داده.', 500);
    }
}
