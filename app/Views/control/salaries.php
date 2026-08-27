<?php

declare(strict_types=1);

$salaryTablesReady = (bool) ($salaryTablesReady ?? false);
$salaryActiveTab = in_array(($salaryActiveTab ?? 'record'), ['record', 'pay', 'link'], true) ? $salaryActiveTab : 'record';
$salaryRecordRows = is_array($salaryRecordRows ?? null) ? $salaryRecordRows : [];
$salaryPaymentRows = is_array($salaryPaymentRows ?? null) ? $salaryPaymentRows : [];
$salaryOpenRecordRows = is_array($salaryOpenRecordRows ?? null) ? $salaryOpenRecordRows : [];
$salaryBranchOptions = is_array($salaryBranchOptions ?? null) ? $salaryBranchOptions : [];
$salaryEmployeeOptions = is_array($salaryEmployeeOptions ?? null) ? $salaryEmployeeOptions : [];
$salaryUserOptions = is_array($salaryUserOptions ?? null) ? $salaryUserOptions : [];
$salaryBusinessSourceOptions = is_array($salaryBusinessSourceOptions ?? null) ? $salaryBusinessSourceOptions : [];
$salaryCurrencyOptions = is_array($salaryCurrencyOptions ?? null) ? $salaryCurrencyOptions : [];
$salaryPaymentMethodOptions = is_array($salaryPaymentMethodOptions ?? null) ? $salaryPaymentMethodOptions : [];
$salaryTreasuryAccountOptions = is_array($salaryTreasuryAccountOptions ?? null) ? $salaryTreasuryAccountOptions : [];
$salaryEmployeePerformanceRows = is_array($salaryEmployeePerformanceRows ?? null) ? $salaryEmployeePerformanceRows : [];
$salarySummaryCards = is_array($salarySummaryCards ?? null) ? $salarySummaryCards : [];
$salaryEmployeeMappingRows = is_array($salaryEmployeeMappingRows ?? null) ? $salaryEmployeeMappingRows : [];
$salaryMappingTableReady = (bool) ($salaryMappingTableReady ?? false);
$salaryEditingRecord = is_array($salaryEditingRecord ?? null) ? $salaryEditingRecord : null;
$salaryEditingPayment = is_array($salaryEditingPayment ?? null) ? $salaryEditingPayment : null;
$today = date('Y-m-d');
$currentMonth = date('Y-m');
$salaryLedgerRows = [];
$salaryRecordLedgerRows = [];
$salaryPaymentLedgerRows = [];
$salaryRunningBalances = [];
$salaryEmployeeMappingGroups = [];
$salaryCompactBranchName = static function (string $name): string {
    $normalized = trim(preg_replace('/\s+/', ' ', $name));
    $map = [
        'Imdad International Travel Agency' => 'Imdad Int',
        'Noble Route' => 'Noble Route',
    ];

    return $map[$normalized] ?? $normalized;
};

foreach ($salaryEmployeeMappingRows as $mappingRow) {
    $mappingUserId = (int) ($mappingRow['user_id'] ?? 0);
    $mappingGroupKey = $mappingUserId > 0 ? 'user-' . $mappingUserId : 'link-' . (string) ($mappingRow['id'] ?? uniqid('map', true));
    if (! isset($salaryEmployeeMappingGroups[$mappingGroupKey])) {
        $salaryEmployeeMappingGroups[$mappingGroupKey] = [
            'user_name' => (string) ($mappingRow['user_name'] ?? 'Employee'),
            'username' => (string) ($mappingRow['username'] ?? ''),
            'accounts' => [],
        ];
    }

    $salaryEmployeeMappingGroups[$mappingGroupKey]['accounts'][] = [
        'id' => (string) ($mappingRow['id'] ?? '0'),
        'business_source_name' => (string) ($mappingRow['business_source_name'] ?? 'Sales Account'),
        'business_source_code' => (string) ($mappingRow['business_source_code'] ?? ''),
    ];
}

foreach ($salaryRecordRows as $row) {
    $ledgerKey = implode('|', [
        (string) ($row['business_source_id'] ?? 0),
        (string) ($row['currency'] ?? 'PKR'),
        (string) ($row['salary_month'] ?? ''),
    ]);
    $salaryRunningBalances[$ledgerKey] = round(($salaryRunningBalances[$ledgerKey] ?? 0) + (float) ($row['gross_debit_amount'] ?? 0), 2);
    $salaryRecordLedgerRows[] = [
        'row_kind' => 'record',
        'row_id' => (int) ($row['id'] ?? 0),
        'salary_record_id' => (int) ($row['id'] ?? 0),
        'salary_payment_id' => 0,
        'sort_date' => (string) ($row['record_date'] ?? ''),
        'display_date' => (string) ($row['record_date'] ?? ''),
        'employee_name' => (string) ($row['employee_name'] ?? 'Employee'),
        'business_source_name' => (string) ($row['business_source_name'] ?? ''),
        'month_label' => date('M Y', strtotime((string) ($row['salary_month'] ?? $today))),
        'branch_name' => $salaryCompactBranchName((string) ($row['branch_name'] ?? '')),
        'currency' => (string) ($row['currency'] ?? 'PKR'),
        'entry_type' => 'Salary Recorded',
        'basic_salary_amount' => (float) ($row['basic_salary_amount'] ?? 0),
        'bonus_amount' => (float) ($row['bonus_amount'] ?? 0),
        'customer_penalty_profit_amount' => (float) ($row['customer_penalty_profit_amount'] ?? 0),
        'paid_salary_amount' => 0.0,
        'deduction_amount' => 0.0,
        'debit_amount' => (float) ($row['gross_debit_amount'] ?? 0),
        'credit_amount' => 0.0,
        'balance_amount' => $salaryRunningBalances[$ledgerKey],
        'detail' => (string) ($row['notes'] ?? ''),
        'status' => (string) ($row['status'] ?? 'open'),
        'void_reason' => (string) ($row['void_reason'] ?? ''),
        'is_void' => strtolower(str_replace(' ', '_', (string) ($row['status'] ?? 'open'))) === 'void',
    ];
}

foreach ($salaryPaymentRows as $row) {
    $ledgerKey = implode('|', [
        (string) ($row['business_source_id'] ?? 0),
        (string) ($row['currency'] ?? 'PKR'),
        date('Y-m-01', strtotime((string) ($row['salary_month'] ?? $today))),
    ]);
    $paymentStatus = strtolower(str_replace(' ', '_', (string) ($row['status'] ?? 'posted')));
    $isVoidedPayment = $paymentStatus === 'void';
    $creditAmount = round((float) ($row['paid_amount'] ?? 0) + (float) ($row['deduction_amount'] ?? 0), 2);
    if (! $isVoidedPayment) {
        $salaryRunningBalances[$ledgerKey] = round(($salaryRunningBalances[$ledgerKey] ?? 0) - $creditAmount, 2);
    }
    $detailParts = [];
    if ((float) ($row['paid_amount'] ?? 0) > 0.005) {
        $paymentCurrency = (string) ($row['payment_currency'] ?? $row['currency'] ?? 'PKR');
        $recordCurrency = (string) ($row['record_currency'] ?? $row['currency'] ?? 'PKR');
        $detailParts[] = 'Paid via ' . (string) (($row['payment_method_label'] ?? '') !== '' ? $row['payment_method_label'] : 'source account');
        if ($paymentCurrency !== '' && $paymentCurrency !== $recordCurrency) {
            $detailParts[] = sprintf(
                '%s %s settled as %s %s @ 1 %s = %s %s',
                number_format((float) ($row['source_paid_amount'] ?? 0), 2),
                $paymentCurrency,
                number_format((float) ($row['paid_amount'] ?? 0), 2),
                $recordCurrency,
                $paymentCurrency,
                number_format((float) ($row['exchange_rate_to_salary'] ?? 0), 8),
                $recordCurrency
            );
        }
    }
    if ((float) ($row['deduction_amount'] ?? 0) > 0.005) {
        $detailParts[] = 'Deduction: ' . (string) (($row['deduction_reason'] ?? '') !== '' ? $row['deduction_reason'] : 'Penalty');
    }
    if ($isVoidedPayment) {
        $detailParts[] = 'Voided: ' . (string) (($row['void_reason'] ?? '') !== '' ? $row['void_reason'] : 'Salary credit entry reversed');
    }
    $salaryPaymentLedgerRows[] = [
        'row_kind' => 'payment',
        'row_id' => (int) ($row['id'] ?? 0),
        'salary_record_id' => (int) ($row['salary_record_id'] ?? 0),
        'salary_payment_id' => (int) ($row['id'] ?? 0),
        'sort_date' => (string) ($row['payment_date'] ?? ''),
        'display_date' => (string) ($row['payment_date'] ?? ''),
        'employee_name' => (string) ($row['employee_name'] ?? 'Employee'),
        'business_source_name' => (string) ($row['business_source_name'] ?? ''),
        'month_label' => date('M Y', strtotime((string) ($row['salary_month'] ?? $today))),
        'branch_name' => $salaryCompactBranchName((string) (($row['record_branch_name'] ?? '') !== '' ? $row['record_branch_name'] : ($row['payment_branch_name'] ?? ''))),
        'currency' => (string) ($row['currency'] ?? 'PKR'),
        'payment_currency' => (string) ($row['payment_currency'] ?? $row['currency'] ?? 'PKR'),
        'entry_type' => ((float) ($row['paid_amount'] ?? 0) > 0.005 && (float) ($row['deduction_amount'] ?? 0) > 0.005)
            ? 'Payment + Deduction'
            : (((float) ($row['deduction_amount'] ?? 0) > 0.005) ? 'Deduction / Penalty' : 'Salary Paid'),
        'basic_salary_amount' => 0.0,
        'bonus_amount' => 0.0,
        'customer_penalty_profit_amount' => 0.0,
        'paid_salary_amount' => (float) ($row['paid_amount'] ?? 0),
        'deduction_amount' => (float) ($row['deduction_amount'] ?? 0),
        'debit_amount' => 0.0,
        'credit_amount' => $isVoidedPayment ? 0.0 : $creditAmount,
        'balance_amount' => $salaryRunningBalances[$ledgerKey],
        'detail' => implode(' | ', $detailParts) !== '' ? implode(' | ', $detailParts) : (string) ($row['remarks'] ?? ''),
        'status' => (string) ($row['status'] ?? 'posted'),
        'void_reason' => (string) ($row['void_reason'] ?? ''),
        'is_void' => $isVoidedPayment,
    ];
}

$salaryRecordRowByDateKey = [];
foreach ($salaryRecordLedgerRows as $row) {
    $salaryRecordRowByDateKey[(int) ($row['salary_record_id'] ?? 0) . '|' . (string) ($row['sort_date'] ?? '')] = $row;
}

$salaryNonVoidPaymentGroupCounts = [];
foreach ($salaryPaymentLedgerRows as $row) {
    if ((bool) ($row['is_void'] ?? false)) {
        continue;
    }

    $groupKey = (int) ($row['salary_record_id'] ?? 0) . '|' . (string) ($row['sort_date'] ?? '');
    $salaryNonVoidPaymentGroupCounts[$groupKey] = ($salaryNonVoidPaymentGroupCounts[$groupKey] ?? 0) + 1;
}

$salaryCombinedPaymentIds = [];
foreach ($salaryRecordLedgerRows as $row) {
    $groupKey = (int) ($row['salary_record_id'] ?? 0) . '|' . (string) ($row['sort_date'] ?? '');
    if (($salaryNonVoidPaymentGroupCounts[$groupKey] ?? 0) !== 1) {
        $salaryLedgerRows[] = $row;
        continue;
    }

    $matchedPaymentRow = null;
    foreach ($salaryPaymentLedgerRows as $paymentRow) {
        if ((bool) ($paymentRow['is_void'] ?? false)) {
            continue;
        }
        if ((int) ($paymentRow['salary_record_id'] ?? 0) !== (int) ($row['salary_record_id'] ?? 0)) {
            continue;
        }
        if ((string) ($paymentRow['sort_date'] ?? '') !== (string) ($row['sort_date'] ?? '')) {
            continue;
        }
        $matchedPaymentRow = $paymentRow;
        break;
    }

    if ($matchedPaymentRow === null) {
        $salaryLedgerRows[] = $row;
        continue;
    }

    $salaryCombinedPaymentIds[(int) ($matchedPaymentRow['salary_payment_id'] ?? 0)] = true;
    $combinedDetailParts = [];
    if (trim((string) ($row['detail'] ?? '')) !== '') {
        $combinedDetailParts[] = trim((string) ($row['detail'] ?? ''));
    }
    if (trim((string) ($matchedPaymentRow['detail'] ?? '')) !== '') {
        $combinedDetailParts[] = trim((string) ($matchedPaymentRow['detail'] ?? ''));
    }
    $recordCurrency = (string) ($row['currency'] ?? 'PKR');
    $paymentCurrency = (string) ($matchedPaymentRow['payment_currency'] ?? $matchedPaymentRow['currency'] ?? $recordCurrency);

    $salaryLedgerRows[] = [
        'row_kind' => 'combined',
        'row_id' => (int) ($matchedPaymentRow['salary_payment_id'] ?? 0),
        'salary_record_id' => (int) ($row['salary_record_id'] ?? 0),
        'salary_payment_id' => (int) ($matchedPaymentRow['salary_payment_id'] ?? 0),
        'sort_date' => (string) ($matchedPaymentRow['sort_date'] ?? $row['sort_date'] ?? ''),
        'display_date' => (string) ($matchedPaymentRow['display_date'] ?? $row['display_date'] ?? ''),
        'employee_name' => (string) ($row['employee_name'] ?? 'Employee'),
        'business_source_name' => (string) ($row['business_source_name'] ?? ''),
        'month_label' => (string) ($row['month_label'] ?? ''),
        'branch_name' => (string) ($row['branch_name'] ?? ''),
        'currency' => $recordCurrency === $paymentCurrency ? $recordCurrency : ($recordCurrency . '/' . $paymentCurrency),
        'entry_type' => 'Salary Recorded + Credit',
        'basic_salary_amount' => (float) ($row['basic_salary_amount'] ?? 0),
        'bonus_amount' => (float) ($row['bonus_amount'] ?? 0),
        'customer_penalty_profit_amount' => (float) ($row['customer_penalty_profit_amount'] ?? 0),
        'paid_salary_amount' => (float) ($matchedPaymentRow['paid_salary_amount'] ?? 0),
        'deduction_amount' => (float) ($matchedPaymentRow['deduction_amount'] ?? 0),
        'debit_amount' => (float) ($row['debit_amount'] ?? 0),
        'credit_amount' => (float) ($matchedPaymentRow['credit_amount'] ?? 0),
        'balance_amount' => (float) ($matchedPaymentRow['balance_amount'] ?? 0),
        'detail' => implode(' | ', $combinedDetailParts),
        'status' => (string) ($matchedPaymentRow['status'] ?? 'posted'),
        'void_reason' => '',
        'is_void' => false,
    ];
}

foreach ($salaryPaymentLedgerRows as $row) {
    if ((bool) ($salaryCombinedPaymentIds[(int) ($row['salary_payment_id'] ?? 0)] ?? false)) {
        continue;
    }

    $salaryLedgerRows[] = $row;
}

usort($salaryLedgerRows, static function (array $left, array $right): int {
    $dateCompare = strcmp((string) ($right['sort_date'] ?? ''), (string) ($left['sort_date'] ?? ''));
    if ($dateCompare !== 0) {
        return $dateCompare;
    }

    $leftPriority = (string) ($left['row_kind'] ?? '') === 'payment' ? 2 : 1;
    $rightPriority = (string) ($right['row_kind'] ?? '') === 'payment' ? 2 : 1;
    if ($leftPriority !== $rightPriority) {
        return $leftPriority <=> $rightPriority;
    }

    return strcmp((string) ($left['employee_name'] ?? ''), (string) ($right['employee_name'] ?? ''));
});
?>
<style>
    .salary-page-head {
        position: relative;
        overflow: hidden;
        background:
            radial-gradient(circle at top left, rgba(86, 161, 213, 0.28), transparent 42%),
            linear-gradient(135deg, #0c3d63, #155784 58%, #1f709b);
        color: #fff;
        border-radius: 24px;
        padding: 1.5rem 1.75rem;
        box-shadow: 0 24px 50px rgba(11, 55, 88, 0.18);
        margin-bottom: 1rem;
    }
    .salary-page-head::after {
        content: "";
        position: absolute;
        inset: auto -70px -80px auto;
        width: 220px;
        height: 220px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.08);
        transform: rotate(18deg);
    }
    .salary-page-head h1 {
        margin: 0;
        font-size: 2rem;
        font-weight: 800;
        letter-spacing: -0.02em;
    }
    .salary-page-head p {
        margin: 0.45rem 0 0;
        max-width: 68rem;
        color: rgba(255, 255, 255, 0.88);
    }
    .salary-page-head .page-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-top: 1rem;
    }
    .salary-page-head .btn {
        border-color: rgba(255, 255, 255, 0.28);
        color: #fff;
        background: rgba(255, 255, 255, 0.12);
    }
    .salary-page-head .btn.btn-primary {
        background: #fff;
        color: #155784;
        border-color: #fff;
    }
    .salary-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.9rem;
        margin-bottom: 1rem;
    }
    .salary-stat-card {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(25, 84, 126, 0.12);
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff, #f4f9fd);
        padding: 1rem 1.05rem;
        box-shadow: 0 14px 30px rgba(17, 57, 90, 0.08);
    }
    .salary-stat-card::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 5px;
        background: linear-gradient(180deg, #2a87c9, #1d5b8f);
    }
    .salary-stat-card strong {
        display: block;
        margin-top: 0.45rem;
        color: #123b5d;
        font-size: 1.08rem;
        line-height: 1.35;
    }
    .salary-stat-card span {
        color: #5f7990;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .salary-panel {
        border: 1px solid rgba(28, 90, 135, 0.12);
        border-radius: 24px;
        background: linear-gradient(180deg, #ffffff, #f8fbfe);
        box-shadow: 0 18px 40px rgba(12, 54, 86, 0.08);
        overflow: hidden;
    }
    .salary-panel__header {
        padding: 1rem 1.2rem 0.85rem;
        border-bottom: 1px solid rgba(28, 90, 135, 0.09);
    }
    .salary-panel__header h2 {
        margin: 0;
        font-size: 1.2rem;
        color: #123b5d;
    }
    .salary-panel__header p {
        margin: 0.35rem 0 0;
        color: #617b91;
        font-size: 0.92rem;
    }
    .salary-panel__body {
        padding: 1rem 1.15rem 1.2rem;
    }
    .salary-workflow-form {
        border: 1px solid #d7e7f3;
        border-radius: 20px;
        background:
            radial-gradient(circle at top right, rgba(73, 146, 201, 0.08), transparent 32%),
            linear-gradient(180deg, #ffffff, #f7fbfe);
        padding: 1.05rem 1.05rem 1.15rem;
        box-shadow: 0 14px 26px rgba(14, 57, 90, 0.06);
    }
    .salary-workflow-form h3 {
        margin: 0 0 0.3rem;
        color: #123b5d;
        font-size: 1.05rem;
    }
    .salary-workflow-form p {
        margin: 0 0 0.95rem;
        color: #69839a;
        font-size: 0.9rem;
    }
    .salary-mapping-grid {
        display: grid;
        grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
        gap: 1rem;
    }
    .salary-table-wrap--mapping {
        overflow-x: hidden;
    }
    .salary-table.salary-mapping-table td:last-child,
    .salary-table.salary-mapping-table th:last-child {
        width: auto;
    }
    .salary-table.salary-mapping-table {
        min-width: 0;
        width: 100%;
        table-layout: auto;
    }
    .salary-table.salary-mapping-table th:first-child,
    .salary-table.salary-mapping-table td:first-child {
        width: 126px;
        min-width: 126px;
        white-space: nowrap;
    }
    .salary-table.salary-mapping-table th,
    .salary-table.salary-mapping-table td {
        white-space: normal;
    }
    .salary-table.salary-mapping-table th:nth-child(2),
    .salary-table.salary-mapping-table td:nth-child(2) {
        width: auto;
        min-width: 0;
    }
    .salary-inline-form {
        margin: 0;
    }
    .salary-inline-form .btn {
        min-width: 78px;
        justify-content: center;
        padding-inline: 0.7rem;
    }
    .salary-account-stack {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.9rem;
        width: 100%;
    }
    .salary-mapping-table td {
        vertical-align: middle;
    }
    .salary-mapping-employee {
        position: relative;
        padding: 0.55rem 0.1rem 0.55rem 0;
    }
    .salary-mapping-employee strong {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        color: #123b5d;
        font-size: 0.98rem;
    }
    .salary-mapping-employee strong::before {
        content: "";
        width: 12px;
        height: 12px;
        border-radius: 999px;
        background: linear-gradient(135deg, #1d79b7, #7fd0ff);
        box-shadow: 0 0 0 4px rgba(29, 121, 183, 0.14);
        flex: 0 0 12px;
    }
    .salary-account-flow {
        display: grid;
        grid-template-columns: 28px max-content;
        align-items: center;
        gap: 0.35rem;
        max-width: 100%;
    }
    .salary-account-arrow {
        position: relative;
        height: 14px;
        align-self: center;
    }
    .salary-account-arrow::before {
        content: "";
        position: absolute;
        left: 0;
        right: 7px;
        top: 50%;
        height: 2px;
        transform: translateY(-50%);
        border-radius: 999px;
        background: linear-gradient(90deg, rgba(29, 121, 183, 0.08), rgba(29, 121, 183, 0.55), rgba(29, 121, 183, 0.12));
    }
    .salary-account-arrow::after {
        content: "";
        position: absolute;
        top: 50%;
        right: 0;
        width: 0;
        height: 0;
        transform: translateY(-50%);
        border-top: 4px solid transparent;
        border-bottom: 4px solid transparent;
        border-left: 7px solid #1d79b7;
        filter: drop-shadow(0 2px 3px rgba(18, 59, 93, 0.18));
    }
    .salary-account-arrow span {
        position: absolute;
        top: 50%;
        left: 1px;
        width: 12px;
        height: 12px;
        border-radius: 999px;
        transform: translateY(-50%);
        background: radial-gradient(circle at 35% 35%, #ffffff 0, #ffffff 22%, #8de1ff 24%, #1d79b7 70%);
        box-shadow: 0 0 0 2px rgba(29, 121, 183, 0.12);
        animation: salaryArrowPulse 2.2s ease-in-out infinite;
    }
    @keyframes salaryArrowPulse {
        0% {
            left: 1px;
            opacity: 0.2;
        }
        15% {
            opacity: 1;
        }
        75% {
            opacity: 1;
        }
        100% {
            left: calc(100% - 15px);
            opacity: 0.18;
        }
    }
    .salary-account-chip {
        display: inline-grid;
        grid-template-columns: minmax(297px, max-content) 86px;
        align-items: center;
        gap: 0.65rem;
        padding: 0.62rem 0.85rem;
        border: 1px solid #dbe8f2;
        border-radius: 12px;
        background: linear-gradient(180deg, #fbfdff 0%, #f4f9fd 100%);
        box-shadow: 0 10px 24px rgba(18, 59, 93, 0.05);
        width: fit-content;
        max-width: 100%;
        box-sizing: border-box;
    }
    .salary-account-chip > div:first-child {
        flex: 1 1 auto;
        min-width: 0;
    }
    .salary-account-chip strong {
        display: block;
        color: #123b5d;
    }
    .salary-account-chip .btn {
        width: 86px;
        min-width: 86px;
    }
    .salary-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem;
    }
    .salary-form-grid--triple {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .salary-form-grid--quad {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    .salary-performance-panel {
        margin: 0.85rem 0 0;
        border: 1px solid rgba(29, 121, 183, 0.16);
        border-radius: 18px;
        background: linear-gradient(180deg, rgba(244, 250, 255, 0.92), rgba(255, 255, 255, 0.98));
        padding: 0.9rem 0.95rem;
    }
    .salary-performance-panel[hidden] {
        display: none;
    }
    .salary-performance-panel__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.8rem;
        margin-bottom: 0.75rem;
    }
    .salary-performance-panel__head strong {
        color: #123b5d;
        font-size: 0.95rem;
    }
    .salary-performance-panel__head span {
        color: #6a8397;
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .salary-performance-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 0.8rem;
    }
    .salary-performance-card {
        position: relative;
        border: 1px solid #d7e7f3;
        border-radius: 16px;
        background:
            linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(246,251,255,0.98) 100%),
            radial-gradient(circle at top right, rgba(67, 154, 214, 0.12), transparent 42%);
        padding: 0.85rem 0.9rem;
        box-shadow: 0 10px 22px rgba(16, 62, 97, 0.06);
        overflow: hidden;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }
    .salary-performance-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 26px rgba(16, 62, 97, 0.09);
    }
    .salary-performance-card.is-matched {
        border-color: rgba(29, 121, 183, 0.5);
        box-shadow: 0 12px 24px rgba(29, 121, 183, 0.14);
    }
    .salary-performance-card__title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.65rem;
        margin-bottom: 0.7rem;
    }
    .salary-performance-card__title strong {
        color: #123b5d;
        font-size: 0.95rem;
        line-height: 1.3;
    }
    .salary-performance-card__currency {
        flex: 0 0 auto;
        padding: 0.22rem 0.55rem;
        border-radius: 999px;
        background: rgba(29, 121, 183, 0.12);
        color: #135581;
        font-size: 0.74rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }
    .salary-performance-metrics {
        display: flex;
        flex-direction: column;
        gap: 0.72rem;
    }
    .salary-performance-group {
        position: relative;
        border: 1px solid rgba(182, 209, 230, 0.82);
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.82);
        padding: 0.7rem 0.72rem 0.78rem;
    }
    .salary-performance-group--overall::after,
    .salary-performance-group--month::after {
        content: '';
        position: absolute;
        top: 50%;
        left: calc(50% - 0.6rem);
        width: 1.2rem;
        height: 2px;
        background: linear-gradient(90deg, rgba(45, 124, 182, 0.18), rgba(45, 124, 182, 0.55), rgba(214, 145, 52, 0.5));
        transform: translateY(-50%);
        border-radius: 999px;
        transition: transform 0.18s ease, opacity 0.18s ease;
        opacity: 0.85;
    }
    .salary-performance-group--month::before {
        content: '';
        position: absolute;
        left: 1.05rem;
        bottom: -0.9rem;
        width: 2px;
        height: 0.82rem;
        background: linear-gradient(180deg, rgba(45, 124, 182, 0.48), rgba(42, 144, 94, 0.18));
        border-radius: 999px;
        transition: transform 0.18s ease, opacity 0.18s ease;
        opacity: 0.82;
    }
    .salary-performance-group--month::after {
        left: calc(50% - 0.55rem);
    }
    .salary-performance-card:hover .salary-performance-group--overall::after,
    .salary-performance-card:hover .salary-performance-group--month::after {
        transform: translateY(-50%) scaleX(1.08);
        opacity: 1;
    }
    .salary-performance-card:hover .salary-performance-group--month::before {
        transform: scaleY(1.08);
        opacity: 1;
    }
    .salary-performance-group__label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.6rem;
        color: #62819a;
        font-size: 0.7rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        margin-bottom: 0.55rem;
    }
    .salary-performance-group__hint {
        color: #7d96aa;
        font-size: 0.67rem;
        font-weight: 800;
        letter-spacing: 0.03em;
        text-transform: none;
    }
    .salary-performance-pair {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        align-items: stretch;
    }
    .salary-performance-metric {
        position: relative;
        min-height: 58px;
        padding: 0.05rem 0;
    }
    .salary-performance-metric span {
        display: block;
        color: #6a8397;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        margin-bottom: 0.16rem;
    }
    .salary-performance-metric strong {
        display: block;
        color: #123b5d;
        font-size: 0.95rem;
        line-height: 1.2;
    }
    .salary-performance-metric--sale strong {
        color: #145c92;
    }
    .salary-performance-metric--outstanding strong {
        color: #aa6b16;
    }
    .salary-performance-metric--profit strong {
        color: #19704f;
    }
    .salary-performance-profit {
        position: relative;
        border: 1px solid rgba(180, 224, 201, 0.9);
        border-radius: 14px;
        background: linear-gradient(180deg, rgba(240, 252, 246, 0.96), rgba(231, 247, 238, 0.98));
        padding: 0.76rem 0.78rem 0.8rem;
    }
    .salary-performance-empty {
        color: #69839a;
        font-size: 0.9rem;
        padding: 0.2rem 0;
    }
    .salary-field {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }
    .salary-field--full {
        grid-column: 1 / -1;
    }
    .salary-field span {
        color: #204d73;
        font-size: 0.79rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.07em;
    }
    .salary-field input,
    .salary-field select,
    .salary-field textarea {
        width: 100%;
        border: 1px solid #b9d1e4;
        border-radius: 14px;
        padding: 0.78rem 0.88rem;
        color: #183f61;
        background: linear-gradient(180deg, #ffffff, #fbfdff);
        box-sizing: border-box;
        box-shadow: inset 0 1px 1px rgba(255,255,255,0.9);
        transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }
    .salary-field input:focus,
    .salary-field select:focus,
    .salary-field textarea:focus {
        outline: none;
        border-color: #5a9ad0;
        box-shadow: 0 0 0 3px rgba(90, 154, 208, 0.16);
        background: #fff;
    }
    .salary-field textarea {
        min-height: 108px;
        resize: vertical;
    }
    .salary-field input[readonly] {
        background: linear-gradient(180deg, #f6fbff, #edf6fd);
        color: #335b7a;
        cursor: default;
    }
    .salary-field--action {
        justify-content: flex-end;
    }
    .salary-field--action .btn {
        min-height: 48px;
        width: 100%;
        justify-content: center;
        border-radius: 14px;
    }
    .salary-form-note {
        margin: 0.85rem 0 1rem;
        padding: 0.9rem 1rem;
        border-radius: 16px;
        background: #eef7fd;
        color: #335b7a;
        border: 1px solid #d1e6f5;
        font-size: 0.92rem;
    }
    .salary-form-actions {
        display: flex;
        gap: 0.7rem;
        flex-wrap: wrap;
        margin-top: 1rem;
    }
    .salary-form-actions .btn {
        min-width: 160px;
        justify-content: center;
    }
    .salary-form-actions .btn.btn-secondary {
        background: #f3f8fc;
        color: #17496d;
        border-color: #bcd3e4;
    }
    .salary-credit-row {
        margin-top: 0.82rem;
        padding-top: 0.15rem;
    }
    .salary-fx-modal[hidden] {
        display: none !important;
    }
    .salary-fx-modal {
        position: fixed;
        inset: 0;
        z-index: 1200;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.25rem;
        background: rgba(9, 31, 48, 0.42);
        backdrop-filter: blur(4px);
    }
    .salary-fx-modal__dialog {
        width: min(720px, 100%);
        border-radius: 24px;
        overflow: hidden;
        border: 1px solid rgba(30, 91, 133, 0.16);
        background:
            radial-gradient(circle at top right, rgba(79, 152, 206, 0.18), transparent 32%),
            linear-gradient(180deg, #ffffff, #f7fbfe);
        box-shadow: 0 28px 60px rgba(8, 40, 64, 0.22);
    }
    .salary-fx-modal__head {
        padding: 1.15rem 1.25rem 0.85rem;
        border-bottom: 1px solid rgba(28, 90, 135, 0.10);
    }
    .salary-fx-modal__head h4 {
        margin: 0;
        color: #123b5d;
        font-size: 1.15rem;
    }
    .salary-fx-modal__head p {
        margin: 0.35rem 0 0;
        color: #67839a;
        font-size: 0.92rem;
    }
    .salary-fx-modal__body {
        padding: 1rem 1.2rem 1.15rem;
    }
    .salary-fx-summary-note {
        margin-top: 0.85rem;
        padding: 0.85rem 0.95rem;
        border-radius: 16px;
        background: #eef7fd;
        border: 1px solid #d5e9f6;
        color: #315b7a;
        font-weight: 700;
        line-height: 1.45;
    }
    .salary-fx-modal__actions {
        display: flex;
        gap: 0.7rem;
        justify-content: flex-end;
        margin-top: 1rem;
    }
    .salary-table-wrap {
        overflow: auto;
    }
    .salary-ledger-panel {
        position: relative;
    }
    .salary-ledger-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .salary-ledger-actions {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        flex-wrap: wrap;
    }
    .salary-ledger-close {
        display: none;
    }
    .salary-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1710px;
    }
    .salary-table th,
    .salary-table td {
        padding: 0.78rem 0.62rem;
        border-bottom: 1px solid #dbe8f2;
        text-align: left;
        vertical-align: top;
    }
    .salary-table thead th {
        color: #31597a;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        background: #eef5fb;
        white-space: nowrap;
    }
    .salary-table thead th.salary-table__stacked {
        white-space: normal;
        line-height: 1.15;
    }
    .salary-table tbody tr:nth-child(even) {
        background: rgba(238, 245, 251, 0.48);
    }
    .salary-table th:nth-child(1),
    .salary-table td:nth-child(1) {
        min-width: 108px;
        width: 108px;
        white-space: nowrap;
    }
    .salary-table th:nth-child(5),
    .salary-table td:nth-child(5) {
        min-width: 64px;
        width: 64px;
        white-space: nowrap;
    }
    .salary-table th:nth-child(3),
    .salary-table td:nth-child(3),
    .salary-table th:nth-child(4),
    .salary-table td:nth-child(4) {
        white-space: nowrap;
    }
    .salary-table th:nth-child(8),
    .salary-table td:nth-child(8),
    .salary-table th:nth-child(9),
    .salary-table td:nth-child(9),
    .salary-table th:nth-child(10),
    .salary-table td:nth-child(10) {
        min-width: 102px;
        width: 102px;
        white-space: nowrap;
    }
    .salary-table th:nth-child(11),
    .salary-table td:nth-child(11),
    .salary-table th:nth-child(12),
    .salary-table td:nth-child(12),
    .salary-table th:nth-child(13),
    .salary-table td:nth-child(13) {
        min-width: 96px;
        width: 96px;
        white-space: nowrap;
    }
    .salary-table th:nth-child(14),
    .salary-table td:nth-child(14) {
        min-width: 390px;
        width: 390px;
    }
    .salary-table th:nth-child(15),
    .salary-table td:nth-child(15) {
        min-width: 156px;
        width: 156px;
    }
    .salary-table td:nth-child(14) {
        white-space: normal;
        line-height: 1.45;
        word-break: normal;
    }
    .salary-table__actions {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        flex-wrap: nowrap;
    }
    .salary-table__actions .btn {
        min-width: 72px;
        justify-content: center;
    }
    .salary-table__actions .btn.btn-danger {
        background: #fff3f0;
        border-color: #e9b2a7;
        color: #b5412d;
    }
    .salary-table__void-note {
        color: #8c5b22;
        font-size: 0.78rem;
        font-weight: 700;
    }
    .salary-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.32rem 0.7rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #17496d;
        background: #e5f2fb;
    }
    .salary-pill--paid {
        background: #e5f7ed;
        color: #236942;
    }
    .salary-pill--partial {
        background: #fff2d8;
        color: #92622a;
    }
    .salary-pill--open {
        background: #e9f2fb;
        color: #205780;
    }
    .salary-kicker {
        display: block;
        margin-top: 0.25rem;
        color: #6b869d;
        font-size: 0.82rem;
    }
    .salary-empty {
        padding: 2rem 1rem;
        text-align: center;
        color: #69839a;
    }
    .salary-ledger-panel.is-expanded {
        position: fixed;
        inset: 1rem;
        z-index: 1200;
        display: flex;
        flex-direction: column;
        border-radius: 24px;
        box-shadow: 0 30px 70px rgba(8, 40, 64, 0.24);
    }
    .salary-ledger-panel.is-expanded .salary-table-wrap {
        flex: 1 1 auto;
    }
    .salary-ledger-panel.is-expanded .salary-ledger-close {
        display: inline-flex;
    }
    .salary-ledger-panel.is-expanded #salary-ledger-expand-button {
        display: none;
    }
    @media (max-width: 1180px) {
        .salary-stat-grid,
        .salary-mapping-grid,
        .salary-form-grid,
        .salary-form-grid--triple,
        .salary-form-grid--quad {
            grid-template-columns: 1fr;
        }
        .salary-account-flow {
            grid-template-columns: 58px minmax(0, 1fr);
            gap: 0.65rem;
        }
        .salary-account-chip .btn {
            flex-basis: 110px;
        }
    }
</style>

<section class="salary-page-head">
    <h1>Salary</h1>
    <p>Record monthly employee salary debit first, then settle it through salary payment and deduction credits. Employees use the same account-holder master, while salary history remains separate from business expenses.</p>
    <div class="page-actions">
        <a class="btn <?= $salaryActiveTab === 'record' ? 'btn-primary' : '' ?>" href="<?= e(url('/salaries?tab=record')) ?>">Record Salary</a>
        <a class="btn <?= $salaryActiveTab === 'pay' ? 'btn-primary' : '' ?>" href="<?= e(url('/salaries?tab=pay')) ?>">Pay Salary</a>
        <a class="btn <?= $salaryActiveTab === 'link' ? 'btn-primary' : '' ?>" href="<?= e(url('/salaries?tab=link')) ?>">Link Employee</a>
        <a class="btn" href="<?= e(url('/reports?report=salary_report')) ?>">Open Reports</a>
    </div>
</section>

<section class="salary-stat-grid">
    <?php foreach ($salarySummaryCards as $card): ?>
        <article class="salary-stat-card">
            <span><?= e((string) ($card['label'] ?? 'Summary')) ?></span>
            <strong><?= e((string) ($card['value'] ?? 'PKR 0.00')) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<?php if (! $salaryTablesReady): ?>
    <section class="salary-panel">
        <div class="salary-panel__header">
            <h2>Salary Module Not Ready</h2>
            <p>Apply the latest migration before using this screen.</p>
        </div>
        <div class="salary-panel__body">
            <div class="salary-form-note">
                Run migration: <code>20260816_000070_employee_salary_module.php</code>
            </div>
        </div>
    </section>
<?php else: ?>
    <?php if ($salaryActiveTab === 'link' && $salaryMappingTableReady): ?>
        <section class="salary-panel" style="margin-bottom:1rem;">
            <div class="salary-panel__header">
                <h2>Employee Sales Account Mapping</h2>
            </div>
            <div class="salary-panel__body">
                <div class="salary-mapping-grid">
                    <div class="salary-workflow-form">
                        <h3>Link Employee Name</h3>
                        <p>Select the real employee user and then link every sales account that belongs to that employee, for example PKR and AED accounts. One employee can have multiple linked sales accounts.</p>
                        <form method="post" action="<?= e(url('/salaries/mappings/save')) ?>">
                            <?= \App\Helpers\Csrf::input() ?>
                            <div class="salary-form-grid">
                                <label class="salary-field">
                                    <span>Employee User</span>
                                    <select name="user_id" required>
                                        <option value="">Select employee</option>
                                        <?php foreach ($salaryUserOptions as $option): ?>
                                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"><?= e((string) ($option['label'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="salary-field">
                                    <span>Sales Account</span>
                                    <select name="business_source_id" required>
                                        <option value="">Select sales account</option>
                                        <?php foreach ($salaryBusinessSourceOptions as $option): ?>
                                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"><?= e((string) ($option['label'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <div class="salary-form-grid" style="margin-top:0.8rem;">
                                <label class="salary-field salary-field--full">
                                    <span>Notes</span>
                                    <input type="text" name="notes" maxlength="255" placeholder="Optional note for this employee-account link">
                                </label>
                            </div>
                            <div class="salary-form-actions">
                                <button class="btn btn-primary" type="submit">Save Link</button>
                            </div>
                        </form>
                    </div>
                    <div class="salary-workflow-form">
                        <h3>Current Links</h3>
                        <div class="salary-table-wrap salary-table-wrap--mapping">
                            <table class="salary-table salary-mapping-table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Linked Sales Accounts</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($salaryEmployeeMappingGroups === []): ?>
                                        <tr><td colspan="2" class="salary-empty">No employee sales-account links have been saved yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($salaryEmployeeMappingGroups as $group): ?>
                                            <tr>
                                                <td>
                                                    <div class="salary-mapping-employee">
                                                        <strong><?= e((string) ($group['user_name'] ?? 'Employee')) ?></strong>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="salary-account-stack">
                                                        <?php foreach ((array) ($group['accounts'] ?? []) as $account): ?>
                                                            <div class="salary-account-flow">
                                                                <div class="salary-account-arrow" aria-hidden="true"><span></span></div>
                                                                <div class="salary-account-chip">
                                                                    <div>
                                                                        <strong><?= e((string) ($account['business_source_name'] ?? 'Sales Account')) ?></strong>
                                                                    </div>
                                                                    <form method="post" action="<?= e(url('/salaries/mappings/unlink')) ?>" class="salary-inline-form">
                                                                        <?= \App\Helpers\Csrf::input() ?>
                                                                        <input type="hidden" name="link_id" value="<?= e((string) ($account['id'] ?? '0')) ?>">
                                                                        <button class="btn" type="submit">Remove</button>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    <?php elseif ($salaryActiveTab === 'link'): ?>
        <section class="salary-panel" style="margin-bottom:1rem;">
            <div class="salary-panel__header">
                <h2>Employee Sales Account Mapping</h2>
            </div>
            <div class="salary-panel__body">
                <div class="salary-form-note">
                    Run migration: <code>20260816_000071_employee_sales_account_links.php</code>
                </div>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($salaryActiveTab !== 'link'): ?>
    <section class="salary-panel">
        <div class="salary-panel__header">
            <h2>Salary Workflow</h2>
        </div>
        <div class="salary-panel__body">
            <?php if ($salaryActiveTab === 'record'): ?>
                <?php
                $isEditingSalaryRecord = is_array($salaryEditingRecord);
                $recordFormAction = $isEditingSalaryRecord ? url('/salaries/record/update') : url('/salaries/record');
                $recordFormTitle = $isEditingSalaryRecord ? 'Edit Salary Debit' : 'Record Salary Debit';
                $recordFormSummary = $isEditingSalaryRecord
                    ? 'Correct branch, employee, month, date, currency, salary, bonus, and notes from the saved salary debit.'
                    : 'Record the monthly employee salary due. Only basic salary and bonus increase salary debit.';
                ?>
                <div class="salary-workflow-form">
                    <h3><?= e($recordFormTitle) ?></h3>
                    <p><?= e($recordFormSummary) ?></p>
                    <form method="post" action="<?= e($recordFormAction) ?>" id="salary-record-form">
                        <?= \App\Helpers\Csrf::input() ?>
                        <?php if ($isEditingSalaryRecord): ?>
                            <input type="hidden" name="salary_record_id" value="<?= e((string) ($salaryEditingRecord['id'] ?? 0)) ?>">
                        <?php endif; ?>
                        <div class="salary-form-grid salary-form-grid--quad">
                            <label class="salary-field">
                                <span>Branch</span>
                                <select name="branch_id" required>
                                    <option value="">Select branch</option>
                                    <?php foreach ($salaryBranchOptions as $option): ?>
                                        <option value="<?= e((string) ($option['value'] ?? '')) ?>" <?= (string) ($option['value'] ?? '') === (string) ($salaryEditingRecord['branch_id'] ?? '') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="salary-field">
                                <span>Employee</span>
                                <select name="employee_user_id" required>
                                    <option value="">Select employee</option>
                                    <?php foreach ($salaryEmployeeOptions as $option): ?>
                                        <option value="<?= e((string) ($option['value'] ?? '')) ?>" <?= (string) ($option['value'] ?? '') === (string) ($salaryEditingRecord['employee_user_id'] ?? '') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="salary-field">
                                <span>Salary Month</span>
                                <input type="month" name="salary_month" value="<?= e((string) ($salaryEditingRecord['salary_month'] ?? $currentMonth)) ?>" required>
                            </label>
                            <label class="salary-field">
                                <span>Record Date</span>
                                <input type="date" name="record_date" value="<?= e((string) ($salaryEditingRecord['record_date'] ?? $today)) ?>" required>
                            </label>
                        </div>
                        <div class="salary-form-grid salary-form-grid--quad" style="margin-top:0.8rem;">
                            <label class="salary-field">
                                <span>Currency</span>
                                <select name="currency" required>
                                    <?php foreach ($salaryCurrencyOptions as $option): ?>
                                        <option value="<?= e((string) ($option['value'] ?? '')) ?>" <?= (string) ($option['value'] ?? '') === (string) ($salaryEditingRecord['currency'] ?? 'PKR') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="salary-field">
                                <span>Basic Salary</span>
                                <input type="number" step="0.01" min="0" name="basic_salary_amount" value="<?= e((string) ($salaryEditingRecord['basic_salary_amount'] ?? '0.00')) ?>" required data-clear-zero-on-focus>
                            </label>
                            <label class="salary-field">
                                <span>Bonus</span>
                                <input type="number" step="0.01" min="0" name="bonus_amount" value="<?= e((string) ($salaryEditingRecord['bonus_amount'] ?? '0.00')) ?>" data-clear-zero-on-focus>
                            </label>
                        </div>
                        <div class="salary-performance-panel" data-salary-performance-panel hidden>
                            <div class="salary-performance-panel__head">
                                <strong>Employee Sales Snapshot</strong>
                                <span>Overall + <?= e(date('M Y')) ?></span>
                            </div>
                            <div class="salary-performance-grid" data-salary-performance-grid></div>
                            <div class="salary-performance-empty" data-salary-performance-empty>Select an employee to view linked sales-account performance.</div>
                        </div>
                        <div class="salary-form-grid" style="margin-top:0.8rem;">
                            <label class="salary-field salary-field--full">
                                <span>Notes</span>
                                <textarea name="notes" placeholder="Optional salary notes, targets, or month remarks"><?= e((string) ($salaryEditingRecord['notes'] ?? '')) ?></textarea>
                            </label>
                        </div>
                        <?php if ($isEditingSalaryRecord): ?>
                            <div class="salary-form-grid" style="margin-top:0.8rem;">
                                <label class="salary-field salary-field--full">
                                    <span>Void Reason</span>
                                    <input type="text" name="void_reason" maxlength="500" value="" placeholder="Why this salary record should be voided">
                                </label>
                            </div>
                        <?php endif; ?>
                        <div class="salary-form-actions">
                            <button class="btn btn-primary" type="submit" form="salary-record-form"><?= e($isEditingSalaryRecord ? 'Save Salary Changes' : 'Save Salary Debit') ?></button>
                            <?php if ($isEditingSalaryRecord): ?>
                                <button class="btn btn-danger" type="submit" form="salary-record-form" formaction="<?= e(url('/salaries/record/void')) ?>" onclick="return window.confirm('Void this salary record?');">Void Record</button>
                                <a class="btn btn-secondary" href="<?= e(url('/salaries?tab=record')) ?>">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <?php
                $isEditingSalaryPayment = is_array($salaryEditingPayment);
                $paymentFormAction = $isEditingSalaryPayment ? url('/salaries/pay/update') : url('/salaries/pay');
                $paymentFormTitle = $isEditingSalaryPayment ? 'Edit Salary Credit' : 'Record Salary Credit';
                $paymentFormSummary = $isEditingSalaryPayment
                    ? 'Correct branch, salary record, payment date, paid amount, deduction, source account, method, reference, and remarks from the saved salary credit.'
                    : 'Salary paid and employee penalty both reduce the employee balance, so both are recorded here as credit movements.';
                $salaryRecordSelectRows = $isEditingSalaryPayment ? $salaryRecordRows : $salaryOpenRecordRows;
                ?>
                <?php if ($isEditingSalaryPayment && is_array($salaryEditingRecord)): ?>
                    <div class="salary-workflow-form" style="margin-bottom:1rem;">
                        <h3>Linked Salary Debit</h3>
                        <p>Adjust the original salary record here when the debit side itself needs correction before or alongside the salary credit update.</p>
                        <form method="post" action="<?= e(url('/salaries/record/update')) ?>" id="salary-linked-record-form">
                            <?= \App\Helpers\Csrf::input() ?>
                            <input type="hidden" name="salary_record_id" value="<?= e((string) ($salaryEditingRecord['id'] ?? 0)) ?>">
                            <div class="salary-form-grid salary-form-grid--quad">
                                <label class="salary-field">
                                    <span>Branch</span>
                                    <select name="branch_id" required>
                                        <option value="">Select branch</option>
                                        <?php foreach ($salaryBranchOptions as $option): ?>
                                            <option value="<?= e((string) ($option['value'] ?? '')) ?>" <?= (string) ($option['value'] ?? '') === (string) ($salaryEditingRecord['branch_id'] ?? '') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="salary-field">
                                    <span>Employee</span>
                                    <select name="employee_user_id" required>
                                        <option value="">Select employee</option>
                                        <?php foreach ($salaryEmployeeOptions as $option): ?>
                                            <option value="<?= e((string) ($option['value'] ?? '')) ?>" <?= (string) ($option['value'] ?? '') === (string) ($salaryEditingRecord['employee_user_id'] ?? '') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="salary-field">
                                    <span>Salary Month</span>
                                    <input type="month" name="salary_month" value="<?= e((string) ($salaryEditingRecord['salary_month'] ?? $currentMonth)) ?>" required>
                                </label>
                                <label class="salary-field">
                                    <span>Record Date</span>
                                    <input type="date" name="record_date" value="<?= e((string) ($salaryEditingRecord['record_date'] ?? $today)) ?>" required>
                                </label>
                            </div>
                            <div class="salary-form-grid salary-form-grid--quad" style="margin-top:0.8rem;">
                                <label class="salary-field">
                                    <span>Currency</span>
                                    <select name="currency" required>
                                        <?php foreach ($salaryCurrencyOptions as $option): ?>
                                            <option value="<?= e((string) ($option['value'] ?? '')) ?>" <?= (string) ($option['value'] ?? '') === (string) ($salaryEditingRecord['currency'] ?? 'PKR') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="salary-field">
                                    <span>Basic Salary</span>
                                    <input type="number" step="0.01" min="0" name="basic_salary_amount" value="<?= e((string) ($salaryEditingRecord['basic_salary_amount'] ?? '0.00')) ?>" required data-clear-zero-on-focus>
                                </label>
                                <label class="salary-field">
                                    <span>Bonus</span>
                                    <input type="number" step="0.01" min="0" name="bonus_amount" value="<?= e((string) ($salaryEditingRecord['bonus_amount'] ?? '0.00')) ?>" data-clear-zero-on-focus>
                                </label>
                            </div>
                            <div class="salary-performance-panel" data-salary-performance-panel hidden>
                                <div class="salary-performance-panel__head">
                                    <strong>Employee Sales Snapshot</strong>
                                    <span>Overall + <?= e(date('M Y')) ?></span>
                                </div>
                                <div class="salary-performance-grid" data-salary-performance-grid></div>
                                <div class="salary-performance-empty" data-salary-performance-empty>Select an employee to view linked sales-account performance.</div>
                            </div>
                            <div class="salary-form-grid salary-form-grid--quad" style="margin-top:0.8rem;">
                                <label class="salary-field salary-field--full" style="grid-column: 1 / span 2;">
                                    <span>Notes</span>
                                    <input type="text" name="notes" value="<?= e((string) ($salaryEditingRecord['notes'] ?? '')) ?>" placeholder="Optional salary notes, targets, or month remarks">
                                </label>
                                <label class="salary-field salary-field--full" style="grid-column: 3 / span 2;">
                                    <span>Void Reason</span>
                                    <input type="text" name="void_reason" value="" maxlength="500" placeholder="Why this salary record should be voided">
                                </label>
                            </div>
                            <div class="salary-form-actions">
                                <button class="btn btn-primary" type="submit" form="salary-linked-record-form">Save Debit Changes</button>
                                <button class="btn btn-danger" type="submit" form="salary-linked-record-form" formaction="<?= e(url('/salaries/record/void')) ?>" onclick="return window.confirm('Void this salary record?');">Void Record</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                <div class="salary-workflow-form">
                    <h3><?= e($paymentFormTitle) ?></h3>
                    <p><?= e($paymentFormSummary) ?></p>
                    <form method="post" action="<?= e($paymentFormAction) ?>" id="salary-credit-form" data-salary-payment-form>
                        <?= \App\Helpers\Csrf::input() ?>
                        <?php if ($isEditingSalaryPayment): ?>
                            <input type="hidden" name="salary_payment_id" value="<?= e((string) ($salaryEditingPayment['id'] ?? 0)) ?>">
                        <?php endif; ?>
                        <div class="salary-form-grid salary-form-grid--quad">
                            <label class="salary-field">
                                <span>Open Salary Record</span>
                                <select name="salary_record_id" required data-salary-record-select>
                                    <option value="">Select salary record</option>
                                    <?php foreach ($salaryRecordSelectRows as $row): ?>
                                        <option
                                            value="<?= e((string) ($row['id'] ?? '')) ?>"
                                            data-salary-currency="<?= e((string) ($row['currency'] ?? 'PKR')) ?>"
                                            data-salary-branch="<?= e((string) ($row['branch_id'] ?? '')) ?>"
                                            data-employee-user-id="<?= e((string) ($row['user_id'] ?? 0)) ?>"
                                            <?= (string) ($row['id'] ?? '') === (string) ($salaryEditingPayment['salary_record_id'] ?? '') ? 'selected' : '' ?>
                                        >
                                            <?= e((string) ($row['employee_name'] ?? 'Employee')) ?>
                                            | <?= e(date('M Y', strtotime((string) ($row['salary_month'] ?? $today)))) ?>
                                            | <?= e((string) ($row['branch_name'] ?? 'Branch')) ?>
                                            | <?= e((string) ($row['currency'] ?? 'PKR')) ?> <?= e(number_format((float) ($row['outstanding_amount'] ?? 0), 2)) ?> outstanding
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="salary-field">
                                <span>Payment Date</span>
                                <input type="date" name="payment_date" value="<?= e((string) ($salaryEditingPayment['payment_date'] ?? $today)) ?>" required>
                            </label>
                            <label class="salary-field">
                                <span>Payment Currency</span>
                                <select name="payment_currency" data-salary-payment-currency data-explicit-currency="<?= $isEditingSalaryPayment ? '1' : '0' ?>">
                                    <?php foreach ($salaryCurrencyOptions as $option): ?>
                                        <option value="<?= e((string) ($option['value'] ?? '')) ?>" <?= (string) ($option['value'] ?? '') === (string) ($salaryEditingPayment['payment_currency'] ?? '') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="salary-field">
                                <span>Cash / Bank Paid</span>
                                <input type="number" step="0.01" min="0" name="paid_amount" value="<?= e((string) ($salaryEditingPayment['paid_amount'] ?? '0.00')) ?>" data-clear-zero-on-focus>
                            </label>
                        </div>
                        <div class="salary-performance-panel" data-salary-performance-panel hidden>
                            <div class="salary-performance-panel__head">
                                <strong>Employee Sales Snapshot</strong>
                                <span>Overall + <?= e(date('M Y')) ?></span>
                            </div>
                            <div class="salary-performance-grid" data-salary-performance-grid></div>
                            <div class="salary-performance-empty" data-salary-performance-empty>Select a salary record to view linked sales-account performance.</div>
                        </div>
                        <div class="salary-form-grid salary-form-grid--quad salary-credit-row">
                            <label class="salary-field">
                                <span>Salary Deduction / Penalty</span>
                                <input type="number" step="0.01" min="0" name="deduction_amount" value="<?= e((string) ($salaryEditingPayment['deduction_amount'] ?? '0.00')) ?>" data-clear-zero-on-focus>
                            </label>
                            <label class="salary-field">
                                <span>Deduction Reason</span>
                                <input type="text" name="deduction_reason" maxlength="255" value="<?= e((string) ($salaryEditingPayment['deduction_reason'] ?? '')) ?>" placeholder="Reason if salary was reduced">
                            </label>
                            <label class="salary-field">
                                <span>Payment Method</span>
                                <select name="payment_method">
                                    <option value="">Select payment method</option>
                                    <?php foreach ($salaryPaymentMethodOptions as $option): ?>
                                        <option value="<?= e((string) ($option['value'] ?? '')) ?>" <?= (string) ($option['value'] ?? '') === (string) ($salaryEditingPayment['payment_method'] ?? '') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="salary-field">
                                <span>Source Account</span>
                                <select name="treasury_account_id" data-salary-treasury-select>
                                    <option value="">Select source account</option>
                                    <?php foreach ($salaryTreasuryAccountOptions as $option): ?>
                                        <option value="<?= e((string) ($option['value'] ?? '')) ?>" data-currency="<?= e((string) ($option['currency'] ?? 'PKR')) ?>" <?= (string) ($option['value'] ?? '') === (string) ($salaryEditingPayment['treasury_account_id'] ?? '') ? 'selected' : '' ?>>
                                            <?= e((string) ($option['label'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <div class="salary-form-grid salary-form-grid--quad salary-credit-row">
                            <label class="salary-field">
                                <span>Reference</span>
                                <input type="text" name="reference_number" maxlength="120" value="<?= e((string) ($salaryEditingPayment['reference_number'] ?? '')) ?>" placeholder="Cheque / transfer / note reference">
                            </label>
                            <label class="salary-field salary-field--full" style="grid-column: span 3;">
                                <span>Remarks</span>
                                <input type="text" name="remarks" maxlength="4000" value="<?= e((string) ($salaryEditingPayment['remarks'] ?? '')) ?>" placeholder="Optional payment or deduction remarks">
                            </label>
                            <input type="hidden" name="exchange_rate_to_salary" value="<?= e((string) ($salaryEditingPayment['exchange_rate_to_salary'] ?? '')) ?>" data-salary-fx-input>
                        </div>
                        <?php if ($isEditingSalaryPayment): ?>
                            <div class="salary-form-grid salary-form-grid--quad salary-credit-row">
                                <label class="salary-field salary-field--full" style="grid-column: 1 / -1;">
                                    <span>Void Reason</span>
                                    <input type="text" name="void_reason" maxlength="500" value="" placeholder="Why this salary payment or deduction should be voided">
                                </label>
                            </div>
                        <?php endif; ?>
                        <div class="salary-form-actions">
                            <button class="btn btn-primary" type="submit" form="salary-credit-form"><?= e($isEditingSalaryPayment ? 'Save Credit Changes' : 'Save Salary Credit') ?></button>
                            <?php if ($isEditingSalaryPayment): ?>
                                <button class="btn btn-danger" type="submit" form="salary-credit-form" formaction="<?= e(url('/salaries/pay/void')) ?>" onclick="return window.confirm('Void this salary payment?');">Void Payment</button>
                                <button class="btn btn-danger" type="submit" form="salary-credit-form" formaction="<?= e(url('/salaries/pay/void-all')) ?>" onclick="return window.confirm('Void this salary record and all linked salary payments?');">Void All</button>
                                <a class="btn btn-secondary" href="<?= e(url('/salaries?tab=pay')) ?>">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <div class="salary-fx-modal" data-salary-fx-modal hidden>
                        <div class="salary-fx-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="salary-fx-modal-title">
                            <div class="salary-fx-modal__head">
                                <h4 id="salary-fx-modal-title">Currency Conversion</h4>
                                <p>Set the exact rate only when payment currency differs from the salary currency.</p>
                            </div>
                            <div class="salary-fx-modal__body">
                                <div class="salary-form-grid salary-form-grid--quad">
                                    <label class="salary-field">
                                        <span>Payment Currency</span>
                                        <input type="text" value="" readonly data-salary-fx-payment-currency>
                                    </label>
                                    <label class="salary-field">
                                        <span>Salary Currency</span>
                                        <input type="text" value="" readonly data-salary-fx-salary-currency>
                                    </label>
                                    <label class="salary-field">
                                        <span>Cash / Bank Paid</span>
                                        <input type="text" value="" readonly data-salary-fx-paid-amount>
                                    </label>
                                    <label class="salary-field">
                                        <span>FX Rate</span>
                                        <input type="number" step="0.00000001" min="0.00000001" value="<?= e((string) ($salaryEditingPayment['exchange_rate_to_salary'] ?? '')) ?>" data-salary-fx-modal-rate placeholder="1 payment = ? salary">
                                    </label>
                                </div>
                                <div class="salary-fx-summary-note" data-salary-fx-preview>
                                    Enter the exact conversion rate before saving the salary payment.
                                </div>
                                <div class="salary-fx-modal__actions">
                                    <button class="btn btn-secondary" type="button" data-salary-fx-cancel>Cancel</button>
                                    <button class="btn btn-primary" type="button" data-salary-fx-save>Save Rate</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <section class="salary-panel salary-ledger-panel" id="salary-ledger-panel" style="margin-top:1rem;">
        <div class="salary-panel__header">
            <div class="salary-ledger-toolbar">
                <h2>Salary Ledger</h2>
                <div class="salary-ledger-actions">
                    <button class="btn btn-sm" type="button" id="salary-ledger-expand-button">Open Wide View</button>
                    <button class="btn btn-sm salary-ledger-close" type="button" id="salary-ledger-close-button">Close Wide View</button>
                </div>
            </div>
        </div>
        <div class="salary-table-wrap">
            <table class="salary-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Month</th>
                        <th>Branch</th>
                        <th>Curr.</th>
                        <th class="salary-table__stacked">Basic<br>Salary</th>
                        <th>Bonus</th>
                        <th class="salary-table__stacked">Paid<br>Salary</th>
                        <th>Deduction</th>
                        <th>Debit</th>
                        <th>Credit</th>
                        <th>Balance</th>
                        <th>Detail</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($salaryLedgerRows === []): ?>
                        <tr><td colspan="14" class="salary-empty">No salary debit or credit movement has been entered yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($salaryLedgerRows as $row): ?>
                            <tr>
                                <td><?= e((string) ($row['display_date'] ?? '')) ?></td>
                                <td><strong><?= e((string) ($row['employee_name'] ?? 'Employee')) ?></strong></td>
                                <td><?= e((string) ($row['month_label'] ?? '')) ?></td>
                                <td><?= e((string) ($row['branch_name'] ?? '')) ?></td>
                                <td><?= e((string) ($row['currency'] ?? 'PKR')) ?></td>
                                <td><?= e(number_format((float) ($row['basic_salary_amount'] ?? 0), 2)) ?></td>
                                <td><?= e(number_format((float) ($row['bonus_amount'] ?? 0), 2)) ?></td>
                                <td><?= e(number_format((float) ($row['paid_salary_amount'] ?? 0), 2)) ?></td>
                                <td><?= e(number_format((float) ($row['deduction_amount'] ?? 0), 2)) ?></td>
                                <td><?= e(number_format((float) ($row['debit_amount'] ?? 0), 2)) ?></td>
                                <td><?= e(number_format((float) ($row['credit_amount'] ?? 0), 2)) ?></td>
                                <td><?= e(number_format((float) ($row['balance_amount'] ?? 0), 2)) ?></td>
                                <td><?= e((string) ($row['detail'] ?? '')) ?></td>
                                <td>
                                    <div class="salary-table__actions">
                                        <?php if ((string) ($row['row_kind'] ?? '') === 'combined'): ?>
                                            <a class="btn btn-sm btn-primary" href="<?= e(url('/salaries?tab=pay&edit_payment_id=' . (int) ($row['salary_payment_id'] ?? 0) . '&edit_record_id=' . (int) ($row['salary_record_id'] ?? 0))) ?>">Edit</a>
                                        <?php elseif ((string) ($row['row_kind'] ?? '') === 'record'): ?>
                                            <a class="btn btn-sm btn-primary" href="<?= e(url('/salaries?tab=record&edit_record_id=' . (int) ($row['row_id'] ?? 0))) ?>">Edit</a>
                                        <?php elseif (strtolower(str_replace(' ', '_', (string) ($row['status'] ?? 'posted'))) === 'void'): ?>
                                            <span class="salary-table__void-note">Voided</span>
                                        <?php else: ?>
                                            <a class="btn btn-sm btn-primary" href="<?= e(url('/salaries?tab=pay&edit_payment_id=' . (int) ($row['row_id'] ?? 0))) ?>">Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <script type="application/json" id="salary-performance-data"><?= json_encode($salaryEmployeePerformanceRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script>
        (function () {
            var salaryPerformanceData = [];
            var salaryPerformanceSource = document.getElementById('salary-performance-data');
            if (salaryPerformanceSource) {
                try {
                    salaryPerformanceData = JSON.parse(salaryPerformanceSource.textContent || '[]');
                } catch (error) {
                    salaryPerformanceData = [];
                }
            }

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function renderSalaryPerformancePanel(panel, rows, activeCurrency) {
                if (!panel) {
                    return;
                }

                var grid = panel.querySelector('[data-salary-performance-grid]');
                var empty = panel.querySelector('[data-salary-performance-empty]');
                if (!grid || !empty) {
                    return;
                }

                if (!rows.length) {
                    grid.innerHTML = '';
                    empty.hidden = false;
                    panel.hidden = false;
                    return;
                }

                var normalizedActiveCurrency = String(activeCurrency || '').trim().toUpperCase();
                var sortedRows = rows.slice().sort(function (left, right) {
                    var leftMatch = normalizedActiveCurrency !== '' && String(left.currency || '').toUpperCase() === normalizedActiveCurrency ? 0 : 1;
                    var rightMatch = normalizedActiveCurrency !== '' && String(right.currency || '').toUpperCase() === normalizedActiveCurrency ? 0 : 1;
                    if (leftMatch !== rightMatch) {
                        return leftMatch - rightMatch;
                    }
                    return String(left.business_source_name || '').localeCompare(String(right.business_source_name || ''));
                });

                grid.innerHTML = sortedRows.map(function (row) {
                    var currency = String(row.currency || '').trim().toUpperCase();
                    var matched = normalizedActiveCurrency !== '' && currency === normalizedActiveCurrency;
                    return ''
                        + '<article class="salary-performance-card' + (matched ? ' is-matched' : '') + '">'
                        +   '<div class="salary-performance-card__title">'
                        +     '<strong>' + escapeHtml(row.business_source_name || 'Sales Account') + '</strong>'
                        +     '<span class="salary-performance-card__currency">' + escapeHtml(currency || 'N/A') + '</span>'
                        +   '</div>'
                        +   '<div class="salary-performance-metrics">'
                        +     '<section class="salary-performance-group salary-performance-group--overall">'
                        +       '<div class="salary-performance-group__label"><span>Overall Position</span><span class="salary-performance-group__hint">sale -> unpaid</span></div>'
                        +       '<div class="salary-performance-pair">'
                        +         '<div class="salary-performance-metric salary-performance-metric--sale"><span>Total Sale</span><strong>' + escapeHtml(row.total_sale_display || '0.00') + '</strong></div>'
                        +         '<div class="salary-performance-metric salary-performance-metric--outstanding"><span>Total Outstanding</span><strong>' + escapeHtml(row.total_outstanding_display || '0.00') + '</strong></div>'
                        +       '</div>'
                        +     '</section>'
                        +     '<section class="salary-performance-group salary-performance-group--month">'
                        +       '<div class="salary-performance-group__label"><span>' + escapeHtml(row.month_label || 'This Month') + '</span><span class="salary-performance-group__hint">current month</span></div>'
                        +       '<div class="salary-performance-pair">'
                        +         '<div class="salary-performance-metric salary-performance-metric--sale"><span>Month Sale</span><strong>' + escapeHtml(row.month_sale_display || '0.00') + '</strong></div>'
                        +         '<div class="salary-performance-metric salary-performance-metric--outstanding"><span>Month Outstanding</span><strong>' + escapeHtml(row.month_outstanding_display || '0.00') + '</strong></div>'
                        +       '</div>'
                        +     '</section>'
                        +     '<section class="salary-performance-profit">'
                        +       '<div class="salary-performance-group__label"><span>Month Profit</span><span class="salary-performance-group__hint">service + customer penalty</span></div>'
                        +       '<div class="salary-performance-metric salary-performance-metric--profit"><strong>' + escapeHtml(row.month_profit_display || '0.00') + '</strong></div>'
                        +     '</section>'
                        +   '</div>'
                        + '</article>';
                }).join('');
                empty.hidden = true;
                panel.hidden = false;
            }

            function updateSalaryPerformanceForRecordForm(form) {
                var panel = form.querySelector('[data-salary-performance-panel]');
                var employeeSelect = form.querySelector('select[name="employee_user_id"]');
                var currencySelect = form.querySelector('select[name="currency"]');
                if (!panel || !employeeSelect) {
                    return;
                }

                var userId = String(employeeSelect.value || '');
                if (userId === '') {
                    panel.hidden = true;
                    return;
                }

                var activeCurrency = currencySelect ? currencySelect.value : '';
                var rows = salaryPerformanceData.filter(function (row) {
                    return String(row.user_id || '') === userId;
                });
                renderSalaryPerformancePanel(panel, rows, activeCurrency);
            }

            function updateSalaryPerformanceForPaymentForm(form) {
                var panel = form.querySelector('[data-salary-performance-panel]');
                var recordSelect = form.querySelector('select[name="salary_record_id"]');
                if (!panel || !recordSelect) {
                    return;
                }

                var selectedOption = recordSelect.options[recordSelect.selectedIndex];
                if (!selectedOption || !selectedOption.value) {
                    panel.hidden = true;
                    return;
                }

                var userId = String(selectedOption.getAttribute('data-employee-user-id') || '');
                var activeCurrency = String(selectedOption.getAttribute('data-salary-currency') || '');
                var rows = salaryPerformanceData.filter(function (row) {
                    return String(row.user_id || '') === userId;
                });
                renderSalaryPerformancePanel(panel, rows, activeCurrency);
            }

            Array.prototype.forEach.call(document.querySelectorAll('#salary-record-form, #salary-credit-form'), function (form) {
                if (!form) {
                    return;
                }

                form.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) {
                        return;
                    }

                    var target = event.target;
                    if (!(target instanceof HTMLElement)) {
                        return;
                    }

                    if (target.tagName === 'TEXTAREA') {
                        return;
                    }

                    if (target.tagName === 'BUTTON' || target.type === 'submit') {
                        return;
                    }

                    var focusableFields = Array.prototype.filter.call(
                        form.querySelectorAll('input, select, textarea, button'),
                        function (field) {
                            if (!(field instanceof HTMLElement)) {
                                return false;
                            }

                            if (field.disabled) {
                                return false;
                            }

                            if (field.tabIndex < 0) {
                                return false;
                            }

                            if (field.tagName === 'INPUT') {
                                var inputType = (field.getAttribute('type') || '').toLowerCase();
                                if (inputType === 'hidden') {
                                    return false;
                                }
                            }

                            return field.offsetParent !== null;
                        }
                    );

                    var currentIndex = focusableFields.indexOf(target);
                    if (currentIndex === -1) {
                        return;
                    }

                    var nextField = focusableFields[currentIndex + 1];
                    if (!nextField) {
                        return;
                    }

                    event.preventDefault();
                    nextField.focus();
                    if (typeof nextField.select === 'function' && nextField.tagName === 'INPUT') {
                        nextField.select();
                    }
                });
            });

            Array.prototype.forEach.call(document.querySelectorAll('[data-clear-zero-on-focus]'), function (input) {
                input.addEventListener('focus', function () {
                    var value = (input.value || '').trim();
                    if (value === '0' || value === '0.0' || value === '0.00') {
                        input.value = '';
                    }
                });
            });

            var salaryRecordSelect = document.querySelector('[data-salary-record-select]');
            var treasurySelect = document.querySelector('[data-salary-treasury-select]');
            var paymentCurrencySelect = document.querySelector('[data-salary-payment-currency]');
            var salaryFxInput = document.querySelector('[data-salary-fx-input]');
            var salaryPaidAmountInput = document.querySelector('input[name="paid_amount"]');
            var salaryFxModal = document.querySelector('[data-salary-fx-modal]');
            var salaryFxModalRate = document.querySelector('[data-salary-fx-modal-rate]');
            var salaryFxModalSave = document.querySelector('[data-salary-fx-save]');
            var salaryFxModalCancel = document.querySelector('[data-salary-fx-cancel]');
            var salaryFxPaymentCurrency = document.querySelector('[data-salary-fx-payment-currency]');
            var salaryFxSalaryCurrency = document.querySelector('[data-salary-fx-salary-currency]');
            var salaryFxPaidAmount = document.querySelector('[data-salary-fx-paid-amount]');
            var salaryFxPreview = document.querySelector('[data-salary-fx-preview]');
            var paymentCurrencyTouched = false;

            var getSalaryCurrency = function () {
                if (!salaryRecordSelect) {
                    return '';
                }
                var selectedRecord = salaryRecordSelect.options[salaryRecordSelect.selectedIndex];
                return selectedRecord ? (selectedRecord.getAttribute('data-salary-currency') || '') : '';
            };

            var crossCurrencyActive = function () {
                var salaryCurrency = getSalaryCurrency();
                var paymentCurrency = paymentCurrencySelect ? (paymentCurrencySelect.value || '') : '';
                return salaryCurrency !== '' && paymentCurrency !== '' && salaryCurrency !== paymentCurrency;
            };

            var filterTreasuryByCurrency = function () {
                if (!paymentCurrencySelect || !treasurySelect) {
                    return;
                }
                var requiredCurrency = paymentCurrencySelect.value || '';
                Array.prototype.forEach.call(treasurySelect.options, function (option, index) {
                    if (index === 0) {
                        option.hidden = false;
                        return;
                    }
                    var optionCurrency = option.getAttribute('data-currency') || '';
                    option.hidden = requiredCurrency !== '' && optionCurrency !== requiredCurrency;
                    if (option.hidden && option.selected) {
                        treasurySelect.selectedIndex = 0;
                    }
                });
            };

            var renderSalaryFxPreview = function () {
                if (!salaryFxPreview || !salaryFxModalRate) {
                    return;
                }
                var salaryCurrency = getSalaryCurrency();
                var paymentCurrency = paymentCurrencySelect.value || '';
                var paidAmount = salaryPaidAmountInput ? parseFloat(salaryPaidAmountInput.value || '0') : 0;
                var rate = parseFloat(salaryFxModalRate.value || '0');
                if (!(rate > 0) || !(paidAmount > 0)) {
                    salaryFxPreview.textContent = 'Enter the exact conversion rate before saving the salary payment.';
                    return;
                }
                var converted = paidAmount * rate;
                salaryFxPreview.textContent = paidAmount.toFixed(2) + ' ' + paymentCurrency + ' = ' + converted.toFixed(2) + ' ' + salaryCurrency + ' at 1 ' + paymentCurrency + ' = ' + rate.toFixed(8) + ' ' + salaryCurrency + '.';
            };

            var openSalaryFxModal = function () {
                if (!salaryFxModal || !salaryFxModalRate || !salaryFxPaymentCurrency || !salaryFxSalaryCurrency || !salaryFxPaidAmount) {
                    return;
                }
                if (!crossCurrencyActive()) {
                    return;
                }
                var salaryCurrency = getSalaryCurrency();
                var paymentCurrency = paymentCurrencySelect ? (paymentCurrencySelect.value || '') : '';
                salaryFxPaymentCurrency.value = paymentCurrency;
                salaryFxSalaryCurrency.value = salaryCurrency;
                salaryFxPaidAmount.value = salaryPaidAmountInput ? (salaryPaidAmountInput.value || '0.00') : '0.00';
                salaryFxModalRate.value = salaryFxInput.value || '';
                salaryFxModal.hidden = false;
                renderSalaryFxPreview();
                window.setTimeout(function () {
                    salaryFxModalRate.focus();
                    salaryFxModalRate.select();
                }, 0);
            };

            var closeSalaryFxModal = function () {
                if (!salaryFxModal) {
                    return;
                }
                salaryFxModal.hidden = true;
            };

            var syncPaymentCurrencyToRecord = function () {
                if (!salaryRecordSelect || !paymentCurrencySelect || paymentCurrencyTouched) {
                    return;
                }
                if ((paymentCurrencySelect.getAttribute('data-explicit-currency') || '') === '1') {
                    return;
                }
                var selectedRecord = salaryRecordSelect.options[salaryRecordSelect.selectedIndex];
                var recordCurrency = selectedRecord ? (selectedRecord.getAttribute('data-salary-currency') || '') : '';
                if (recordCurrency !== '') {
                    paymentCurrencySelect.value = recordCurrency;
                }
            };

            if (salaryRecordSelect) {
                salaryRecordSelect.addEventListener('change', function () {
                    syncPaymentCurrencyToRecord();
                    filterTreasuryByCurrency();
                });
            }
            if (paymentCurrencySelect) {
                paymentCurrencySelect.addEventListener('change', function () {
                    paymentCurrencyTouched = true;
                    filterTreasuryByCurrency();
                    if (crossCurrencyActive()) {
                        openSalaryFxModal();
                    } else if (salaryFxInput) {
                        salaryFxInput.value = '';
                    }
                });
            }
            if (salaryPaidAmountInput) {
                salaryPaidAmountInput.addEventListener('input', function () {
                    renderSalaryFxPreview();
                });
            }
            if (salaryFxModalCancel) {
                salaryFxModalCancel.addEventListener('click', function () {
                    closeSalaryFxModal();
                });
            }
            if (salaryFxModalSave) {
                salaryFxModalSave.addEventListener('click', function () {
                    var rate = parseFloat(salaryFxModalRate.value || '0');
                    if (!(rate > 0)) {
                        window.alert('Enter a valid exchange rate before saving.');
                        salaryFxModalRate.focus();
                        return;
                    }
                    salaryFxInput.value = rate.toFixed(8);
                    closeSalaryFxModal();
                });
            }
            if (salaryFxModalRate) {
                salaryFxModalRate.addEventListener('input', renderSalaryFxPreview);
            }
            if (salaryFxModal) {
                salaryFxModal.addEventListener('click', function (event) {
                    if (event.target === salaryFxModal) {
                        closeSalaryFxModal();
                    }
                });
            }
            syncPaymentCurrencyToRecord();
            filterTreasuryByCurrency();

            var salaryCreditForm = document.getElementById('salary-credit-form');
            if (salaryCreditForm) {
                salaryCreditForm.addEventListener('submit', function (event) {
                    if (!crossCurrencyActive()) {
                        return;
                    }
                    var rate = parseFloat((salaryFxInput && salaryFxInput.value) ? salaryFxInput.value : '0');
                    if (!(rate > 0)) {
                        event.preventDefault();
                        window.alert('Set the exchange rate first for the different-currency salary payment.');
                        openSalaryFxModal();
                    }
                });
            }

            Array.prototype.forEach.call(document.querySelectorAll('[data-salary-void-form]'), function (form) {
                form.addEventListener('submit', function (event) {
                    var reasonField = form.querySelector('input[name=\"void_reason\"]');
                    if (!(reasonField instanceof HTMLInputElement)) {
                        return;
                    }

                    var reason = window.prompt('Enter the reason for voiding this salary credit entry:');
                    if (reason === null) {
                        event.preventDefault();
                        return;
                    }

                    var trimmedReason = reason.trim();
                    if (trimmedReason.length < 5) {
                        event.preventDefault();
                        window.alert('Please enter at least 5 characters for the void reason.');
                        return;
                    }

                    reasonField.value = trimmedReason;
                });
            });

            Array.prototype.forEach.call(document.querySelectorAll('#salary-record-form, #salary-linked-record-form'), function (form) {
                var employeeSelect = form.querySelector('select[name="employee_user_id"]');
                var currencySelect = form.querySelector('select[name="currency"]');
                if (employeeSelect) {
                    employeeSelect.addEventListener('change', function () {
                        updateSalaryPerformanceForRecordForm(form);
                    });
                }
                if (currencySelect) {
                    currencySelect.addEventListener('change', function () {
                        updateSalaryPerformanceForRecordForm(form);
                    });
                }
                updateSalaryPerformanceForRecordForm(form);
            });

            var salaryCreditForm = document.getElementById('salary-credit-form');
            if (salaryCreditForm) {
                var salaryRecordSelect = salaryCreditForm.querySelector('select[name="salary_record_id"]');
                if (salaryRecordSelect) {
                    salaryRecordSelect.addEventListener('change', function () {
                        updateSalaryPerformanceForPaymentForm(salaryCreditForm);
                    });
                }
                updateSalaryPerformanceForPaymentForm(salaryCreditForm);
            }

            var salaryLedgerPanel = document.getElementById('salary-ledger-panel');
            var salaryLedgerExpandButton = document.getElementById('salary-ledger-expand-button');
            var salaryLedgerCloseButton = document.getElementById('salary-ledger-close-button');

            if (salaryLedgerPanel && salaryLedgerExpandButton && salaryLedgerCloseButton) {
                var openSalaryLedgerWideView = function () {
                    salaryLedgerPanel.classList.add('is-expanded');
                    document.body.style.overflow = 'hidden';
                    salaryLedgerCloseButton.focus();
                };

                var closeSalaryLedgerWideView = function () {
                    salaryLedgerPanel.classList.remove('is-expanded');
                    document.body.style.overflow = '';
                    salaryLedgerExpandButton.focus();
                };

                salaryLedgerExpandButton.addEventListener('click', openSalaryLedgerWideView);
                salaryLedgerCloseButton.addEventListener('click', closeSalaryLedgerWideView);

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && salaryLedgerPanel.classList.contains('is-expanded')) {
                        event.preventDefault();
                        closeSalaryLedgerWideView();
                    }
                });
            }
        }());
    </script>
    <?php endif; ?>
<?php endif; ?>
