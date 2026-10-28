<?php
/**
 * NSC Exchange / Sarafi Module API (صرافی و هدایات پرداخت)
 * Supports:
 * - Money Out (پول رفت) & Money In (پول آمد)
 * - Separate AFN and USD amounts (never combined without explicit exchange_rate)
 * - Statuses: draft, pending, paid, received, cancelled
 * - Draft and Pending do NOT count as completed payments
 * - Links to existing obligations (expense, contractor, supplier, steel, concrete) without creating duplicate expenses
 * - Secure attachment storage (photo/PDF)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();
$user = authenticateUser(false);
$tenantId = currentTenantId($user);

// جدول exchange_transactions اکنون در api/.secrets/schema.sql تعریف شده و با
// nscSchemaEnsure خودکار ساخته می‌شود. اجرای CREATE TABLE در هر درخواست یک DDL
// اضافی و قفل متادیتا روی MySQL بود؛ اکنون فقط اگر جدول واقعاً نبود، یک‌بار اجرا می‌شود.
static $nscExchangeTableChecked = false;
if (!$nscExchangeTableChecked) {
    $nscExchangeTableChecked = true;
    try {
        $db->query("SELECT 1 FROM `exchange_transactions` LIMIT 1");
    } catch (Throwable $missingTable) {
        try {
            $db->exec("
CREATE TABLE IF NOT EXISTS `exchange_transactions` (
  `id` VARCHAR(64) NOT NULL PRIMARY KEY,
  `tenant_id` VARCHAR(64) NULL,
  `project_id` VARCHAR(64) NOT NULL,
  `direction` ENUM('out','in') NOT NULL DEFAULT 'out',
  `exchange_agent` VARCHAR(200) NOT NULL,
  `sender_name` VARCHAR(200) NOT NULL,
  `recipient_name` VARCHAR(200) NOT NULL,
  `transaction_date` DATE NOT NULL,
  `transaction_time` VARCHAR(20) NULL,
  `reference_number` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(16,2) NOT NULL,
  `currency` ENUM('AFN','USD') NOT NULL DEFAULT 'AFN',
  `exchange_rate` DECIMAL(12,4) NULL DEFAULT NULL,
  `amount_afn` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `amount_usd` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `purpose` VARCHAR(500) NOT NULL,
  `notes` TEXT NULL,
  `status` ENUM('draft','pending','paid','received','cancelled') NOT NULL DEFAULT 'draft',
  `linked_obligation_type` VARCHAR(50) NULL DEFAULT NULL,
  `linked_obligation_id` VARCHAR(64) NULL DEFAULT NULL,
  `linked_obligation_label` VARCHAR(255) NULL DEFAULT NULL,
  `prepared_by` VARCHAR(150) NULL DEFAULT NULL,
  `authorized_by` VARCHAR(150) NULL DEFAULT NULL,
  `attachment_name` VARCHAR(255) NULL DEFAULT NULL,
  `attachment_url` LONGTEXT NULL DEFAULT NULL,
  `created_by` VARCHAR(150) NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_exc_tenant_proj` (`tenant_id`, `project_id`),
  INDEX `idx_exc_status` (`status`),
  INDEX `idx_exc_currency` (`currency`),
  INDEX `idx_exc_ref` (`reference_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
        } catch (Throwable $cannotCreate) {
            error_log('exchange table ensure failed: ' . $cannotCreate->getMessage());
        }
    }
}

/**
 * Compute separate AFN and USD values.
 * Rule: Do NOT combine or convert AFN and USD without an explicitly recorded exchange rate (> 0).
 */
function computeCanonicalAmounts(float $amount, string $currency, ?float $rate): array {
    $hasRate = ($rate !== null && $rate > 0);
    if ($currency === 'AFN') {
        return [
            'amount_afn' => round($amount, 2),
            'amount_usd' => $hasRate ? round($amount / $rate, 2) : 0.00,
        ];
    }
    return [
        'amount_usd' => round($amount, 2),
        'amount_afn' => $hasRate ? round($amount * $rate, 2) : 0.00,
    ];
}

function isCompletedStatus(string $status): bool {
    return $status === 'paid' || $status === 'received';
}

// -------------------------------------------------------------
// GET: دریافت لیست معاملات صرافی و خلاصه بیلانس جداگانه AFN / USD
// -------------------------------------------------------------
if ($method === 'GET') {
    $projectId = $_GET['projectId'] ?? null;
    if ($projectId) {
        assertTenantProjectAccess($db, $projectId, $tenantId, false);
    }
    assertModulePermission($user, 'exchange', 'view', $projectId ? (string)$projectId : null);

    $sql = "SELECT * FROM exchange_transactions WHERE tenant_id = :tid";
    $params = ['tid' => $tenantId];
    if ($projectId) {
        $sql .= " AND project_id = :pid";
        $params['pid'] = $projectId;
    }
    $sql .= " ORDER BY transaction_date DESC, created_at DESC LIMIT 500";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Calculate strictly separated AFN and USD totals (only completed: paid / received)
    $outAFN = 0.0;
    $inAFN = 0.0;
    $outUSD = 0.0;
    $inUSD = 0.0;
    $completedCount = 0;
    $draftOrPendingCount = 0;

    foreach ($rows as $r) {
        $st = $r['status'] ?? 'draft';
        if ($st === 'draft' || $st === 'pending') {
            $draftOrPendingCount++;
            continue;
        }
        if (!isCompletedStatus($st)) {
            continue;
        }
        $completedCount++;
        $amt = (float)$r['amount'];
        $curr = strtoupper($r['currency'] ?? 'AFN');
        $dir = $r['direction'] ?? 'out';
        if ($curr === 'AFN') {
            if ($dir === 'out') $outAFN += $amt;
            else $inAFN += $amt;
        } elseif ($curr === 'USD') {
            if ($dir === 'out') $outUSD += $amt;
            else $inUSD += $amt;
        }
    }

    jsonResponse(true, [
        'transactions' => $rows,
        'totals' => [
            'outAFN' => round($outAFN, 2),
            'inAFN' => round($inAFN, 2),
            'balanceAFN' => round($inAFN - $outAFN, 2),
            'outUSD' => round($outUSD, 2),
            'inUSD' => round($inUSD, 2),
            'balanceUSD' => round($inUSD - $outUSD, 2),
            'totalCount' => count($rows),
            'completedCount' => $completedCount,
            'draftOrPendingCount' => $draftOrPendingCount,
        ],
    ]);
}

// -------------------------------------------------------------
// POST: ثبت، ویرایش یا حذف معامله صرافی
// -------------------------------------------------------------
if ($method === 'POST') {
    $action = $_GET['action'] ?? 'create';
    $data = getJsonInput();
    $permAction = $action === 'delete' ? 'del' : ($action === 'update' ? 'edit' : 'create');
    $reqProjectId = !empty($data['projectId']) ? (string)$data['projectId'] : null;
    if ($reqProjectId) {
        assertTenantProjectAccess($db, $reqProjectId, $tenantId, false);
    }
    assertModulePermission($user, 'exchange', $permAction, $reqProjectId);
    enforceIdempotencyGuard($db, $user, 'exchange:' . $action, $data);

    if ($action === 'create') {
        $id = !empty($data['id']) ? trim((string)$data['id']) : generateUuid();
        $projectId = trim((string)($data['projectId'] ?? ''));
        assertTenantProjectAccess($db, $projectId, $tenantId, true);
        $direction = in_array($data['direction'] ?? 'out', ['out', 'in'], true) ? $data['direction'] : 'out';
        $agent = trim((string)($data['exchangeAgent'] ?? ''));
        $sender = trim((string)($data['senderName'] ?? ''));
        $recipient = trim((string)($data['recipientName'] ?? ''));
        $date = !empty($data['date']) ? $data['date'] : date('Y-m-d');
        $time = !empty($data['time']) ? trim((string)$data['time']) : date('H:i');
        $ref = !empty($data['referenceNumber']) ? trim((string)$data['referenceNumber']) : ('EXC-' . strtoupper(substr(md5(uniqid()), 0, 6)));
        $amount = (float)($data['amount'] ?? 0);
        $currency = in_array(strtoupper($data['currency'] ?? 'AFN'), ['AFN', 'USD'], true) ? strtoupper($data['currency']) : 'AFN';
        $rate = (isset($data['exchangeRate']) && is_numeric($data['exchangeRate']) && (float)$data['exchangeRate'] > 0)
            ? (float)$data['exchangeRate']
            : null;
        $purpose = trim((string)($data['purpose'] ?? ''));
        $notes = isset($data['notes']) ? trim((string)$data['notes']) : null;
        $allowedStatuses = ['draft', 'pending', 'paid', 'received', 'cancelled'];
        $status = in_array($data['status'] ?? 'draft', $allowedStatuses, true) ? $data['status'] : 'draft';

        if ($projectId === '' || $agent === '' || $recipient === '' || $amount <= 0) {
            jsonResponse(false, null, 'لطفاً پروژه، نام صراف، گیرنده و مبلغ معتبر را وارد کنید.', 400);
        }

        $canon = computeCanonicalAmounts($amount, $currency, $rate);

        $stmt = $db->prepare("
            INSERT INTO exchange_transactions (
                id, tenant_id, project_id, direction, exchange_agent, sender_name, recipient_name,
                transaction_date, transaction_time, reference_number, amount, currency, exchange_rate,
                amount_afn, amount_usd, purpose, notes, status,
                linked_obligation_type, linked_obligation_id, linked_obligation_label,
                prepared_by, authorized_by, attachment_name, attachment_url, created_by
            ) VALUES (
                :id, :tid, :pid, :dir, :agent, :sender, :recipient,
                :dt, :tm, :ref, :amt, :curr, :rate,
                :afn, :usd, :purpose, :notes, :status,
                :ltype, :lid, :llabel,
                :prep, :auth, :aname, :aurl, :cby
            )
        ");
        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'pid' => $projectId,
            'dir' => $direction,
            'agent' => $agent,
            'sender' => $sender,
            'recipient' => $recipient,
            'dt' => $date,
            'tm' => $time,
            'ref' => $ref,
            'amt' => $amount,
            'curr' => $currency,
            'rate' => $rate,
            'afn' => $canon['amount_afn'],
            'usd' => $canon['amount_usd'],
            'purpose' => $purpose,
            'notes' => $notes,
            'status' => $status,
            'ltype' => $data['linkedObligationType'] ?? null,
            'lid' => $data['linkedObligationId'] ?? null,
            'llabel' => $data['linkedObligationLabel'] ?? null,
            'prep' => $data['preparedBy'] ?? ($user['name'] ?? 'Admin'),
            'auth' => $data['authorizedBy'] ?? null,
            'aname' => $data['attachmentName'] ?? null,
            'aurl' => $data['attachmentData'] ?? ($data['attachmentUrl'] ?? null),
            'cby' => $user['name'] ?? 'Admin',
        ]);

        logSystemAudit($db, $user['id'] ?? null, $user['name'] ?? 'Admin', 'create', 'exchange', $id, "Created exchange {$direction} {$amount} {$currency} via {$agent} (status: {$status})");
        jsonResponse(true, ['id' => $id, 'status' => $status], 'معامله صرافی با موفقیت در دیتابیس ثبت شد.', 201);
    }

    if ($action === 'update') {
        $id = trim((string)($_GET['id'] ?? ($data['id'] ?? '')));
        if ($id === '') {
            jsonResponse(false, null, 'شناسه معامله الزامی است.', 400);
        }
        $check = $db->prepare("SELECT * FROM exchange_transactions WHERE id = :id AND tenant_id = :tid LIMIT 1");
        $check->execute(['id' => $id, 'tid' => $tenantId]);
        $existing = $check->fetch();
        if (!$existing) {
            jsonResponse(false, null, 'معامله یافت نشد یا دسترسی ندارید.', 404);
        }

        $amount = isset($data['amount']) ? (float)$data['amount'] : (float)$existing['amount'];
        $currency = isset($data['currency']) ? strtoupper((string)$data['currency']) : $existing['currency'];
        $rate = array_key_exists('exchangeRate', $data)
            ? ((is_numeric($data['exchangeRate']) && (float)$data['exchangeRate'] > 0) ? (float)$data['exchangeRate'] : null)
            : ($existing['exchange_rate'] !== null ? (float)$existing['exchange_rate'] : null);
        $canon = computeCanonicalAmounts($amount, $currency, $rate);

        $stmt = $db->prepare("
            UPDATE exchange_transactions SET
                direction = :dir,
                exchange_agent = :agent,
                sender_name = :sender,
                recipient_name = :recipient,
                transaction_date = :dt,
                transaction_time = :tm,
                reference_number = :ref,
                amount = :amt,
                currency = :curr,
                exchange_rate = :rate,
                amount_afn = :afn,
                amount_usd = :usd,
                purpose = :purpose,
                notes = :notes,
                status = :status,
                linked_obligation_type = :ltype,
                linked_obligation_id = :lid,
                linked_obligation_label = :llabel,
                prepared_by = :prep,
                authorized_by = :auth,
                attachment_name = :aname,
                attachment_url = :aurl
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([
            'dir' => $data['direction'] ?? $existing['direction'],
            'agent' => $data['exchangeAgent'] ?? $existing['exchange_agent'],
            'sender' => $data['senderName'] ?? $existing['sender_name'],
            'recipient' => $data['recipientName'] ?? $existing['recipient_name'],
            'dt' => $data['date'] ?? $existing['transaction_date'],
            'tm' => $data['time'] ?? $existing['transaction_time'],
            'ref' => $data['referenceNumber'] ?? $existing['reference_number'],
            'amt' => $amount,
            'curr' => $currency,
            'rate' => $rate,
            'afn' => $canon['amount_afn'],
            'usd' => $canon['amount_usd'],
            'purpose' => $data['purpose'] ?? $existing['purpose'],
            'notes' => $data['notes'] ?? $existing['notes'],
            'status' => $data['status'] ?? $existing['status'],
            'ltype' => $data['linkedObligationType'] ?? $existing['linked_obligation_type'],
            'lid' => $data['linkedObligationId'] ?? $existing['linked_obligation_id'],
            'llabel' => $data['linkedObligationLabel'] ?? $existing['linked_obligation_label'],
            'prep' => $data['preparedBy'] ?? $existing['prepared_by'],
            'auth' => $data['authorizedBy'] ?? $existing['authorized_by'],
            'aname' => $data['attachmentName'] ?? $existing['attachment_name'],
            'aurl' => $data['attachmentData'] ?? ($data['attachmentUrl'] ?? $existing['attachment_url']),
            'id' => $id,
            'tid' => $tenantId,
        ]);

        logSystemAudit($db, $user['id'] ?? null, $user['name'] ?? 'Admin', 'update', 'exchange', $id, "Updated exchange transaction {$id}");
        jsonResponse(true, ['id' => $id], 'معامله صرافی بروزرسانی شد.');
    }

    if ($action === 'delete') {
        $id = trim((string)($_GET['id'] ?? ($data['id'] ?? '')));
        if ($id === '') {
            jsonResponse(false, null, 'شناسه معامله الزامی است.', 400);
        }
        $stmt = $db->prepare("DELETE FROM exchange_transactions WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        logSystemAudit($db, $user['id'] ?? null, $user['name'] ?? 'Admin', 'delete', 'exchange', $id, "Deleted exchange transaction {$id}");
        jsonResponse(true, ['id' => $id], 'معامله صرافی حذف گردید.');
    }
}
