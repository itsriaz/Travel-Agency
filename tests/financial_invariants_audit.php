<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$failures = [];

$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

$columnExists = static function (string $table, string $column) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return $statement->fetchColumn() !== false;
};

$fetchAll = static function (string $sql) use ($db): array {
    $statement = $db->query($sql);
    return $statement instanceof PDOStatement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
};

echo 'Financial invariants audit' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$receiptAllocationAmountColumn = $columnExists('customer_receipt_allocations', 'payment_amount_consumed')
    ? 'payment_amount_consumed'
    : 'allocated_amount';
$receivableAllocationAmountColumn = $columnExists('customer_receipt_allocations', 'receivable_amount_allocated')
    ? 'receivable_amount_allocated'
    : 'allocated_amount';

$receiptConservationBreaks = $fetchAll(
    'SELECT id, booking_reference, receipt_no, currency, tendered_amount, received_amount, allocated_amount, unallocated_amount, returned_amount, status
     FROM customer_receipts
     WHERE status <> "void"
       AND NOT (
            ABS(received_amount - (allocated_amount + unallocated_amount + returned_amount)) <= 0.005
            OR (
                ABS(received_amount - (allocated_amount + unallocated_amount)) <= 0.005
                AND ABS(tendered_amount - (received_amount + returned_amount)) <= 0.005
            )
       )
     LIMIT 10'
);
$check(
    'Customer receipts conserve value',
    $receiptConservationBreaks === [],
    $receiptConservationBreaks === [] ? 'No broken receipts found' : json_encode($receiptConservationBreaks, JSON_UNESCAPED_SLASHES)
);

$receiptHeaderVsAllocations = $fetchAll(
    'SELECT cr.id,
            cr.booking_reference,
            cr.receipt_no,
            cr.currency,
            ROUND(cr.allocated_amount, 2) AS header_allocated_amount,
            ROUND(COALESCE(SUM(cra.' . $receiptAllocationAmountColumn . '), 0), 2) AS allocation_total
     FROM customer_receipts cr
     LEFT JOIN customer_receipt_allocations cra ON cra.customer_receipt_id = cr.id
     WHERE cr.status <> "void"
     GROUP BY cr.id, cr.booking_reference, cr.receipt_no, cr.currency, cr.allocated_amount
     HAVING ABS(header_allocated_amount - allocation_total) > 0.005
     LIMIT 10'
);
$check(
    'Customer receipt header allocated amount matches allocation rows',
    $receiptHeaderVsAllocations === [],
    $receiptHeaderVsAllocations === [] ? 'No receipt allocation drift found' : json_encode($receiptHeaderVsAllocations, JSON_UNESCAPED_SLASHES)
);

$receivableHeaderVsAllocations = $fetchAll(
    'SELECT cri.id,
            cri.booking_reference,
            cri.service_line_reference,
            cri.currency,
            ROUND(cri.due_amount, 2) AS due_amount,
            ROUND(cri.allocated_amount, 2) AS header_allocated_amount,
            ROUND(cri.outstanding_amount, 2) AS outstanding_amount,
            ROUND(COALESCE(SUM(CASE WHEN cr.id IS NOT NULL THEN cra.' . $receivableAllocationAmountColumn . ' ELSE 0 END), 0), 2) AS allocation_total
     FROM customer_receivable_items cri
     LEFT JOIN customer_receipt_allocations cra ON cra.customer_receivable_item_id = cri.id
     LEFT JOIN customer_receipts cr ON cr.id = cra.customer_receipt_id AND cr.status <> "void"
     GROUP BY cri.id, cri.booking_reference, cri.service_line_reference, cri.currency, cri.due_amount, cri.allocated_amount, cri.outstanding_amount
     HAVING ABS(header_allocated_amount - allocation_total) > 0.005
        OR ABS(due_amount - (header_allocated_amount + outstanding_amount)) > 0.005
     LIMIT 10'
);
$check(
    'Customer receivable rows match allocation rows and due equation',
    $receivableHeaderVsAllocations === [],
    $receivableHeaderVsAllocations === [] ? 'No receivable drift found' : json_encode($receivableHeaderVsAllocations, JSON_UNESCAPED_SLASHES)
);

$negativeReceivableValues = $fetchAll(
    'SELECT id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount
     FROM customer_receivable_items
     WHERE due_amount < -0.005
        OR allocated_amount < -0.005
        OR outstanding_amount < -0.005
     LIMIT 10'
);
$check(
    'Customer receivables have no negative impossible values',
    $negativeReceivableValues === [],
    $negativeReceivableValues === [] ? 'No invalid receivable negatives found' : json_encode($negativeReceivableValues, JSON_UNESCAPED_SLASHES)
);

$supplierPaymentConvertedAdvanceSelect = $columnExists('supplier_payments', 'converted_advance_amount')
    ? 'converted_advance_amount'
    : '0 AS converted_advance_amount';
$supplierPaymentConvertedAdvanceValue = $columnExists('supplier_payments', 'converted_advance_amount')
    ? 'converted_advance_amount'
    : '0';

$supplierPaymentConservationBreaks = $fetchAll(
    'SELECT id, booking_reference, payment_no, currency, paid_amount, allocated_amount, unallocated_amount, '
        . $supplierPaymentConvertedAdvanceSelect . ', status
     FROM supplier_payments
     WHERE status <> "void"
       AND ABS(paid_amount - (allocated_amount + unallocated_amount + ' . $supplierPaymentConvertedAdvanceValue . ')) > 0.005
     LIMIT 10'
);
$check(
    'Supplier payments conserve value',
    $supplierPaymentConservationBreaks === [],
    $supplierPaymentConservationBreaks === [] ? 'No broken supplier payments found' : json_encode($supplierPaymentConservationBreaks, JSON_UNESCAPED_SLASHES)
);

$negativeSupplierPaymentValues = $fetchAll(
    'SELECT id, booking_reference, payment_no, currency, paid_amount, allocated_amount, unallocated_amount, '
        . $supplierPaymentConvertedAdvanceSelect . '
     FROM supplier_payments
     WHERE paid_amount < -0.005
        OR allocated_amount < -0.005
        OR unallocated_amount < -0.005
        OR ' . $supplierPaymentConvertedAdvanceValue . ' < -0.005
     LIMIT 10'
);
$check(
    'Supplier payments have no negative impossible values',
    $negativeSupplierPaymentValues === [],
    $negativeSupplierPaymentValues === [] ? 'No invalid supplier payment negatives found' : json_encode($negativeSupplierPaymentValues, JSON_UNESCAPED_SLASHES)
);

$negativeSupplierObligations = $fetchAll(
    'SELECT id, booking_reference, service_line_reference, currency, gross_amount, advance_applied_amount, net_payable_amount, status
     FROM supplier_obligations
     WHERE gross_amount < -0.005
        OR advance_applied_amount < -0.005
        OR net_payable_amount < -0.005
     LIMIT 10'
);
$check(
    'Supplier obligations have no negative impossible values',
    $negativeSupplierObligations === [],
    $negativeSupplierObligations === [] ? 'No invalid supplier obligation negatives found' : json_encode($negativeSupplierObligations, JSON_UNESCAPED_SLASHES)
);

$journalImbalances = $fetchAll(
    'SELECT je.id,
            ROUND(COALESCE(SUM(jel.debit_amount), 0), 2) AS debit_total,
            ROUND(COALESCE(SUM(jel.credit_amount), 0), 2) AS credit_total
     FROM journal_entries je
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
     GROUP BY je.id
     HAVING ABS(debit_total - credit_total) > 0.005
     LIMIT 10'
);
$check(
    'Journal entries remain balanced',
    $journalImbalances === [],
    $journalImbalances === [] ? 'No journal imbalance found' : json_encode($journalImbalances, JSON_UNESCAPED_SLASHES)
);

$invalidJournalLines = $fetchAll(
    'SELECT id, journal_entry_id, debit_amount, credit_amount
     FROM journal_entry_lines
     WHERE debit_amount < -0.005
        OR credit_amount < -0.005
        OR (debit_amount > 0.005 AND credit_amount > 0.005)
     LIMIT 10'
);
$check(
    'Journal lines are one-sided and non-negative',
    $invalidJournalLines === [],
    $invalidJournalLines === [] ? 'No invalid journal lines found' : json_encode($invalidJournalLines, JSON_UNESCAPED_SLASHES)
);

$zeroJournalLines = $fetchAll(
    'SELECT id, journal_entry_id, debit_amount, credit_amount
     FROM journal_entry_lines
     WHERE ABS(debit_amount) <= 0.005
       AND ABS(credit_amount) <= 0.005
     LIMIT 10'
);
echo ($zeroJournalLines === [] ? '[PASS] ' : '[WARN] ') . 'Journal lines do not contain inert zero-value rows'
    . ' - '
    . ($zeroJournalLines === [] ? 'No inert journal rows found' : json_encode($zeroJournalLines, JSON_UNESCAPED_SLASHES))
    . PHP_EOL;

if ($columnExists('service_financial_corrections', 'new_final_sale_price')) {
    $invalidCorrectionFinalPriceRows = $fetchAll(
        'SELECT id,
                booking_reference,
                service_line_reference,
                service_type,
                new_sale_price,
                new_purchase_cost,
                new_service_charge,
                new_discount_amount,
                new_vat_amount,
                new_final_sale_price,
                ROUND(
                    CASE
                        WHEN LOWER(TRIM(service_type)) = "air ticket" THEN new_purchase_cost
                        ELSE new_sale_price
                    END
                    + new_vat_amount
                    - new_discount_amount,
                    2
                ) AS price_floor_without_service_charge
         FROM service_financial_corrections
         WHERE (
                new_final_sale_price >= ROUND(
                    CASE
                        WHEN LOWER(TRIM(service_type)) = "air ticket" THEN new_purchase_cost
                        ELSE new_sale_price
                    END
                    + new_vat_amount
                    - new_discount_amount,
                    2
                )
                AND ABS(
                    new_final_sale_price - (
                        CASE
                            WHEN LOWER(TRIM(service_type)) = "air ticket" THEN new_purchase_cost
                            ELSE new_sale_price
                        END
                        + new_service_charge
                        + new_vat_amount
                        - new_discount_amount
                    )
                ) > 0.005
              )
            OR (
                new_final_sale_price < ROUND(
                    CASE
                        WHEN LOWER(TRIM(service_type)) = "air ticket" THEN new_purchase_cost
                        ELSE new_sale_price
                    END
                    + new_vat_amount
                    - new_discount_amount,
                    2
                )
                AND ABS(new_service_charge) > 0.005
              )
         LIMIT 10'
    );
    $check(
        'Service financial correction pricing follows normal-sale or loss-sale rules',
        $invalidCorrectionFinalPriceRows === [],
        $invalidCorrectionFinalPriceRows === [] ? 'No service correction pricing drift found' : json_encode($invalidCorrectionFinalPriceRows, JSON_UNESCAPED_SLASHES)
    );

    $invalidCorrectionLossRows = $fetchAll(
        'SELECT id,
                booking_reference,
                service_line_reference,
                new_purchase_cost,
                new_final_sale_price,
                ROUND(new_final_sale_price - new_purchase_cost, 2) AS computed_profit_loss
         FROM service_financial_corrections
         WHERE new_final_sale_price + 0 <> ROUND(new_final_sale_price, 2)
            OR new_purchase_cost + 0 <> ROUND(new_purchase_cost, 2)
         LIMIT 10'
    );
    $check(
        'Service financial correction money values remain normalized to accounting precision',
        $invalidCorrectionLossRows === [],
        $invalidCorrectionLossRows === [] ? 'No malformed correction money values found' : json_encode($invalidCorrectionLossRows, JSON_UNESCAPED_SLASHES)
    );
}

if ($failures !== []) {
    echo PHP_EOL . 'Financial invariants audit failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Financial invariants audit passed.' . PHP_EOL;
return 0;
