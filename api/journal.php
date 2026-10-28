<?php
/**
 * NSC Double-Entry General Journal & Adjustments API
 * Solves Excel Flaw #5: Strict Balanced Journal System (Total Debits == Total Credits)
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
    requireScopedProject($user, 'journal', $_GET['projectId'] ?? null);

    assertModulePermission($user, 'journal', 'view');
    $action = $_GET['action'] ?? 'entries';

    if ($action === 'chart_of_accounts') {
        $stmt = $db->query("SELECT * FROM chart_of_accounts ORDER BY account_code ASC");
        jsonResponse(true, ['accounts' => $stmt->fetchAll()]);
    }

    if ($action === 'entries') {
        $stmt = $db->prepare("
            SELECT j.*, p.name as project_name
            FROM journal_entries j
            LEFT JOIN projects p ON j.project_id = p.id
            WHERE j.tenant_id = :tid" . (!empty($_GET['projectId']) ? " AND j.project_id = :pid" : "") . "
            ORDER BY j.entry_date DESC LIMIT 100
        ");
        $stmt->execute(!empty($_GET['projectId']) ? ['tid'=>$tenantId,'pid'=>$_GET['projectId']] : ['tid'=>$tenantId]);
        $entries = $stmt->fetchAll();

        foreach ($entries as &$entry) {
            $lStmt = $db->prepare("
                SELECT jl.*, ca.account_name, ca.account_code
                FROM journal_lines jl
                LEFT JOIN chart_of_accounts ca ON jl.account_id = ca.id
                WHERE jl.journal_entry_id = :jid
            ");
            $lStmt->execute(['jid' => $entry['id']]);
            $entry['lines'] = $lStmt->fetchAll();
        }

        jsonResponse(true, ['entries' => $entries]);
    }
}

if ($method === 'POST') {
    $input = getJsonInput();
    assertModulePermission($user, 'journal', 'create', $input['projectId'] ?? null);
    enforceIdempotencyGuard($db, $user, 'journal:post', $input);

    $id = generateUuid();
    $voucherNo = 'JV-' . date('ymd') . '-' . bin2hex(random_bytes(6));
    $date = !empty($input['entryDate']) ? $input['entryDate'] : date('Y-m-d');
    $narration = trim($input['narration'] ?? 'سند تعدیلات حسابداری');
    $projectId = $input['projectId'] ?? null;
    $lines = $input['lines'] ?? [];

    if (empty($lines) || count($lines) < 2) {
        jsonResponse(false, null, 'سند روزنامچه باید حداقل دو ردیف حساب (بدهکار و بستانکار) داشته باشد.', 400);
    }

    $totalDebitMinor = 0; $totalCreditMinor = 0;
    foreach ($lines as &$line) {
        if (!is_array($line)) jsonResponse(false,null,'ردیف حساب معتبر نیست.',400);
        $debit = moneyMinor($line['debitAmount'] ?? $line['debit'] ?? 0);
        $credit = moneyMinor($line['creditAmount'] ?? $line['credit'] ?? 0);
        if (($debit > 0) === ($credit > 0)) jsonResponse(false,null,'هر ردیف باید فقط بدهکار یا فقط بستانکار باشد.',400);
        $account = $db->prepare('SELECT id FROM chart_of_accounts WHERE id=:id');
        $account->execute(['id'=>(string)($line['accountId'] ?? '')]);
        if (!$account->fetch()) jsonResponse(false,null,'سرفصل حساب معتبر نیست.',400);
        $line['debitAmount'] = $debit / 100; $line['creditAmount'] = $credit / 100;
        $totalDebitMinor += $debit; $totalCreditMinor += $credit;
        if ($totalDebitMinor > 999999999999999 || $totalCreditMinor > 999999999999999) jsonResponse(false,null,'مجموع سند بیش از حد مجاز است.',400);
    }
    unset($line);
    if ($totalDebitMinor === 0 || $totalDebitMinor !== $totalCreditMinor) jsonResponse(false,null,'سند تراز نیست؛ مجموع بدهکار و بستانکار باید دقیقاً برابر باشد.',400);
    $totalDebit = $totalDebitMinor / 100; $totalCredit = $totalCreditMinor / 100;

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

jsonResponse(false,null,'روش یا عملیات پشتیبانی نمی‌شود.', in_array($method,['GET','POST','PUT','DELETE'],true)?404:405);
