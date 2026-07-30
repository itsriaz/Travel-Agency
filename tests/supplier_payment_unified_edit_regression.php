<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Unified supplier payment edit regression' . PHP_EOL;

$serviceCode = (string) file_get_contents(BASE_PATH . '/app/Services/SupplierSettlementWorkspaceService.php');
$controllerCode = (string) file_get_contents(BASE_PATH . '/app/Controllers/ReportsController.php');
$viewCode = (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php');
$javascript = (string) file_get_contents(BASE_PATH . '/public/assets/js/workspace.js');
$routesCode = (string) file_get_contents(BASE_PATH . '/public/index.php');

$check(
    'Every supplier payment uses one unified edit route and modal',
    str_contains($routesCode, '/suppliers/payments/correct')
        && str_contains($controllerCode, 'correctSupplierPayment')
        && str_contains($viewCode, 'data-supplier-payment-edit-modal')
        && str_contains($javascript, 'data-supplier-payment-edit-open')
);
$check(
    'Metadata-only corrections avoid reversing cash and allocations',
    str_contains($serviceCode, "return [\n                'mode' => 'metadata'")
        && str_contains($serviceCode, 'updateSupplierPaymentMetadata')
);
$check(
    'Financial corrections reverse and replace atomically while preserving scope',
    str_contains($serviceCode, 'voidGlobalSupplierPayment')
        && str_contains($serviceCode, 'voidSupplierPayment')
        && str_contains($serviceCode, 'Supplier settlement released for payment correction')
        && str_contains($serviceCode, 'correctSupplierPaymentSupplier(')
        && str_contains($serviceCode, "'payment_scope' => \$isGlobal ? 'global' : 'booking'")
        && str_contains($serviceCode, "'supplier.payment.corrected'")
);
$check(
    'Supplier-only correction keeps the original cash movement',
    str_contains($serviceCode, 'if ($supplierChanged && ! $otherFinancialChanged)')
        && str_contains($serviceCode, 'correctSupplierPaymentSupplier')
        && str_contains($serviceCode, "'mode' => 'supplier'")
);
$check(
    'Allocated supplier payment currency is locked to its payable currency',
    str_contains($serviceCode, 'supplierPaymentAllocationCurrencies')
        && str_contains($serviceCode, 'Supplier payment currency must match the currency of its allocated supplier invoices.')
        && str_contains($javascript, 'Currency is fixed because this payment is already allocated to')
);
$check(
    'Unified editor shows one concise notice only after financial details change',
    str_contains($javascript, 'Payment details changed. The saved payment and its invoice allocations will be updated automatically.')
        && str_contains($javascript, 'supplierPaymentEditPreview.hidden = !financialChanged;')
        && ! str_contains($javascript, 'Only the reference, bank detail, remarks, or note will be updated.')
        && str_contains($viewCode, 'Optional Note')
);
$check(
    'Successful amount edits close the dialog and focus the replacement payment',
    str_contains($javascript, 'const correctedPaymentNumber =')
        && str_contains($javascript, 'closeSupplierPaymentEdit();')
        && str_contains($javascript, "globalPaymentManagerStatus.value = 'posted';")
);

$actorId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1
       AND r.code IN ("super_admin", "branch_admin")
     ORDER BY CASE WHEN r.code = "super_admin" THEN 0 ELSE 1 END, u.id
     LIMIT 1'
)->fetchColumn();

$candidate = $db->query(
    'SELECT p.*
     FROM supplier_payments p
     WHERE p.status <> "void"
       AND p.treasury_account_id IS NOT NULL
       AND p.paid_amount > 1
       AND COALESCE(p.converted_advance_amount, 0) <= 0.005
       AND EXISTS (
           SELECT 1
           FROM supplier_payment_allocations a
           WHERE a.supplier_payment_id = p.id
       )
     ORDER BY CASE WHEN p.payment_scope = "global" OR p.booking_reference = "GLOBAL" THEN 0 ELSE 1 END,
              p.id DESC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if ($actorId <= 0 || $candidate === false) {
    echo '[INFO] No compatible live payment fixture exists; rollback-only correction probe skipped.' . PHP_EOL;
} else {
    $paymentId = (int) $candidate['id'];
    $allocationStatement = $db->prepare(
        'SELECT supplier_obligation_id, allocated_amount
         FROM supplier_payment_allocations
         WHERE supplier_payment_id = :payment_id
         ORDER BY id'
    );
    $allocationStatement->execute(['payment_id' => $paymentId]);
    $beforeAllocations = $allocationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $db->beginTransaction();
    try {
        $service = new \App\Services\SupplierSettlementWorkspaceService($app);
        $wrongCurrency = strtoupper((string) $candidate['currency']) === 'PKR' ? 'AED' : 'PKR';
        $mismatchRejected = false;
        $mismatchMessage = '';
        try {
            $service->correctSupplierPayment([
                'supplier_payment_id' => $paymentId,
                'supplier_id' => (int) $candidate['supplier_id'],
                'payment_date' => (string) $candidate['payment_date'],
                'currency' => $wrongCurrency,
                'paid_amount' => (float) $candidate['paid_amount'],
                'payment_method' => (string) $candidate['payment_method'],
                'treasury_account_id' => (int) $candidate['treasury_account_id'],
                'reference_number' => (string) ($candidate['reference_number'] ?? ''),
                'bank_card_detail' => (string) ($candidate['bank_card_detail'] ?? ''),
                'remarks' => (string) ($candidate['remarks'] ?? ''),
            ], $actorId, [(int) $candidate['branch_id']]);
        } catch (RuntimeException $exception) {
            $mismatchRejected = true;
            $mismatchMessage = $exception->getMessage();
        }
        $unchangedPaymentStatement = $db->prepare(
            'SELECT currency, status FROM supplier_payments WHERE id = :payment_id'
        );
        $unchangedPaymentStatement->execute(['payment_id' => $paymentId]);
        $unchangedPayment = $unchangedPaymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $unchangedAllocationStatement = $db->prepare(
            'SELECT COUNT(*) FROM supplier_payment_allocations WHERE supplier_payment_id = :payment_id'
        );
        $unchangedAllocationStatement->execute(['payment_id' => $paymentId]);
        $check(
            'Rollback probe rejects a payable/payment currency mismatch without changing data',
            $mismatchRejected
                && str_contains($mismatchMessage, strtoupper((string) $candidate['currency']) . ' invoices')
                && str_contains($mismatchMessage, $wrongCurrency . ' cannot be selected')
                && strtoupper((string) ($unchangedPayment['currency'] ?? '')) === strtoupper((string) $candidate['currency'])
                && (string) ($unchangedPayment['status'] ?? '') !== 'void'
                && (int) $unchangedAllocationStatement->fetchColumn() === count($beforeAllocations)
        );

        $metadataResult = $service->correctSupplierPayment([
            'supplier_payment_id' => $paymentId,
            'supplier_id' => (int) $candidate['supplier_id'],
            'payment_date' => (string) $candidate['payment_date'],
            'currency' => (string) $candidate['currency'],
            'paid_amount' => (float) $candidate['paid_amount'],
            'payment_method' => (string) $candidate['payment_method'],
            'treasury_account_id' => (int) $candidate['treasury_account_id'],
            'reference_number' => 'UNIFIED-META-' . $paymentId,
            'bank_card_detail' => (string) ($candidate['bank_card_detail'] ?? ''),
            'remarks' => (string) ($candidate['remarks'] ?? ''),
        ], $actorId, [(int) $candidate['branch_id']]);
        $allocationCountStatement = $db->prepare(
            'SELECT COUNT(*) FROM supplier_payment_allocations WHERE supplier_payment_id = :payment_id'
        );
        $allocationCountStatement->execute(['payment_id' => $paymentId]);
        $samePaymentStatement = $db->prepare(
            'SELECT status, reference_number FROM supplier_payments WHERE id = :payment_id'
        );
        $samePaymentStatement->execute(['payment_id' => $paymentId]);
        $samePayment = $samePaymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $check(
            'Rollback probe updates metadata without replacing the payment',
            (string) ($metadataResult['mode'] ?? '') === 'metadata'
                && (string) ($samePayment['status'] ?? '') !== 'void'
                && (string) ($samePayment['reference_number'] ?? '') === 'UNIFIED-META-' . $paymentId
                && (int) $allocationCountStatement->fetchColumn() === count($beforeAllocations)
        );

        $correctedAmount = round((float) $candidate['paid_amount'] - 0.01, 2);
        $financialResult = $service->correctSupplierPayment([
            'supplier_payment_id' => $paymentId,
            'supplier_id' => (int) $candidate['supplier_id'],
            'payment_date' => (string) $candidate['payment_date'],
            'currency' => (string) $candidate['currency'],
            'paid_amount' => $correctedAmount,
            'payment_method' => (string) $candidate['payment_method'],
            'treasury_account_id' => (int) $candidate['treasury_account_id'],
            'reference_number' => 'UNIFIED-FIN-' . $paymentId,
            'bank_card_detail' => (string) ($candidate['bank_card_detail'] ?? ''),
            'remarks' => (string) ($candidate['remarks'] ?? ''),
        ], $actorId, [(int) $candidate['branch_id']]);
        $replacementId = (int) ($financialResult['supplier_payment_id'] ?? 0);
        $oldStatusStatement = $db->prepare('SELECT status FROM supplier_payments WHERE id = :id');
        $oldStatusStatement->execute(['id' => $paymentId]);
        $replacementStatement = $db->prepare(
            'SELECT payment_scope, booking_reference, paid_amount, status
             FROM supplier_payments
             WHERE id = :id'
        );
        $replacementStatement->execute(['id' => $replacementId]);
        $replacement = $replacementStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $originalWasGlobal = str_replace(' ', '_', strtolower((string) ($candidate['payment_scope'] ?? ''))) === 'global'
            || strtoupper((string) ($candidate['booking_reference'] ?? '')) === 'GLOBAL';
        $replacementIsGlobal = str_replace(' ', '_', strtolower((string) ($replacement['payment_scope'] ?? ''))) === 'global'
            || strtoupper((string) ($replacement['booking_reference'] ?? '')) === 'GLOBAL';
        $check(
            'Rollback probe reverses and replaces a financial correction once',
            (string) $oldStatusStatement->fetchColumn() === 'void'
                && $replacementId > 0
                && (string) ($replacement['status'] ?? '') !== 'void'
                && abs((float) ($replacement['paid_amount'] ?? 0) - $correctedAmount) <= 0.005
        );
        $check(
            'Rollback probe preserves the original payment allocation scope',
            $originalWasGlobal === $replacementIsGlobal
        );

        $journalStatement = $db->prepare(
            'SELECT je.id,
                    ROUND(COALESCE(SUM(jel.debit_amount), 0), 2) AS debit_total,
                    ROUND(COALESCE(SUM(jel.credit_amount), 0), 2) AS credit_total
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             WHERE jel.supplier_payment_id IN (:old_payment_id, :replacement_payment_id)
             GROUP BY je.id'
        );
        $journalStatement->execute([
            'old_payment_id' => $paymentId,
            'replacement_payment_id' => $replacementId,
        ]);
        $balanced = true;
        $journalCount = 0;
        foreach ($journalStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $journal) {
            $journalCount++;
            $balanced = $balanced && abs((float) $journal['debit_total'] - (float) $journal['credit_total']) <= 0.005;
        }
        $check('Every original, reversal, and replacement journal remains balanced', $journalCount > 0 && $balanced);
    } catch (Throwable $exception) {
        $check('Rollback-only unified correction probe completed', false, $exception->getMessage());
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Unified supplier payment edit regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Unified supplier payment edit regression passed.' . PHP_EOL;
