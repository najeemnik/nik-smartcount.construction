<?php
/** Validation shared by snapshot writes; unchanged legacy records are not rewritten. */
function validateWorkspaceFinancialRow(string $section, array $row, array $records): void {
    $fields = [
        'expenses'=>['amount','amountInUSD','amountInAFN','paidAmount','remainingAmount','quantity','unitPrice'],
        'payments'=>['amount','amountInUSD','amountInAFN','amountUSD','amountAFN'],
        'steel'=>['totalAmount','totalCostUSD','totalNativeCost','amountInUSD','amountInAFN','paidAmount','remainingBalance','tons','confirmedTotalTon','totalKg','pricePerTon'],
        'concrete'=>['totalAmount','totalCostUSD','amountInUSD','amountInAFN','paidAmount','remainingBalance','quantityM3','volumeM3','pricePerM3','pumpCharge'],
        'labor'=>['earnedAmount','grossWage','amountPaid','remainingPayable','dailyRate','daysWorked','advanceDeduction'],
        'assets'=>['purchaseCost','salvageValue','usefulLifeYears','monthlyDepreciation','accumulatedDepreciation','currentBookValue'],
        'apartments'=>['areaM2','salePrice','totalPrice','salePriceInUSD','salePriceInAFN','salePriceNative','amountReceived','amountReceivedNative','downPayment','pricePerM2'],
        'projectBudgets'=>['amount','amountUSD','amountAFN'],
        'exchangeTransactions'=>['amount','exchangeRate'],
    ];
    if (!isset($fields[$section])) return;
    $reversal = !empty($row['reversalOf']);
    if ($reversal) {
        $reference = (string)$row['reversalOf'];
        $original = $records[$reference] ?? null;
        $project = (string)($row['projectId'] ?? $row['assignedProjectId'] ?? '');
        if (!$original || $reference === (string)($row['id'] ?? '') || (string)($original['projectId'] ?? $original['assignedProjectId'] ?? '') !== $project) {
            throw new InvalidArgumentException('سند اصلیِ برگشت باید در همان پروژه موجود باشد.');
        }
    }
    foreach ($fields[$section] as $field) {
        if (!array_key_exists($field,$row) || $row[$field] === null || $row[$field] === '') continue;
        $value=$row[$field];
        if (!is_numeric($value) || !is_finite((float)$value) || (!$reversal && (float)$value < 0)) throw new InvalidArgumentException('مقدار عددی معتبر و غیرمنفی لازم است: '.$field);
    }
    if ($section === 'assets') {
        $cost=(float)($row['purchaseCost']??0);$salvage=(float)($row['salvageValue']??0);$life=(float)($row['usefulLifeYears']??5);$depreciation=(float)($row['accumulatedDepreciation']??0);
        if ($salvage>$cost || $life<=0 || $depreciation>$cost-$salvage+.01) throw new InvalidArgumentException('ارزش اسقاط، عمر مفید یا استهلاک دارایی معتبر نیست.');
    }
}

/** Some records use assignedProjectId or multiple assignments instead of projectId. */
function workspaceRecordProjects(string $section, array $row): array {
    $ids=[];
    $primary=$section==='projects'?($row['id']??null):($row['projectId']??$row['project_id']??($section==='assets'?($row['assignedProjectId']??null):null));
    if(is_string($primary)&&$primary!=='')$ids[]=$primary;
    if($section==='employees'&&is_array($row['assignedProjectIds']??null))foreach($row['assignedProjectIds']as$id)if(is_string($id)&&$id!=='')$ids[]=$id;
    if($section==='interProjectTransfers')foreach(['fromProjectId','toProjectId','sourceProjectId','targetProjectId']as$key)if(is_string($row[$key]??null)&&$row[$key]!=='')$ids[]=$row[$key];
    return array_values(array_unique($ids));
}
function workspaceRecordAllowed(array $user,string $module,string $action,string $section,array $row): bool {
    $ids=workspaceRecordProjects($section,$row);
    if(!$ids)return canAccessModule($user,$module,$action,null);
    if($action==='view'){foreach($ids as$id)if(canAccessModule($user,$module,$action,$id))return true;return false;}
    foreach($ids as$id)if(!canAccessModule($user,$module,$action,$id))return false;
    return true;
}
