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
$tableExists = static function (string $table) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
    );
    $statement->execute(['table_name' => $table]);
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

$recognizedCustomerCreditExpression = $tableExists('customer_credit_income_recognitions')
    ? '(SELECT COALESCE(SUM(ccir.amount), 0) FROM customer_credit_income_recognitions ccir WHERE ccir.customer_receipt_id = customer_receipts.id)'
    : '0';
$receiptConservationBreaks = $fetchAll(
    'SELECT id, booking_reference, receipt_no, currency, tendered_amount, received_amount, allocated_amount, unallocated_amount, returned_amount, status,
            ' . $recognizedCustomerCreditExpression . ' AS recognized_income_amount
     FROM customer_receipts
     WHERE status <> "void"
       AND NOT (
            ABS(received_amount - (allocated_amount + unallocated_amount + returned_amount + ' . $recognizedCustomerCreditExpression . ')) <= 0.005
            OR (
                ABS(received_amount - (allocated_amount + unallocated_amount + ' . $recognizedCustomerCreditExpression . ')) <= 0.005
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
            ROUND(COALESCE(receipt_allocations.allocated_amount, 0), 2) AS allocation_total
     FROM customer_receivable_items cri
     LEFT JOIN (
        SELECT cra.customer_receivable_item_id, SUM(cra.' . $receivableAllocationAmountColumn . ') AS allocated_amount
        FROM customer_receipt_allocations cra
        INNER JOIN customer_receipts cr ON cr.id = cra.customer_receipt_id AND cr.status <> "void"
        GROUP BY cra.customer_receivable_item_id
     ) receipt_allocations ON receipt_allocations.customer_receivable_item_id = cri.id
     HAVING ABS(header_allocated_amount - allocation_total) > 0.005
        OR ABS(due_amount - (header_allocated_amount + outstanding_amount)) > 0.005
     LIMIT 10'
);

if ($tableExists('counterparty_offsets')) {
    $brokenCounterpartyOffsets = $fetchAll(
        'SELECT o.id, o.offset_no, o.branch_id, o.currency, o.amount,
                ROUND(COALESCE(a.account_total, 0), 2) AS account_total,
                ROUND(COALESCE(p.payable_total, 0), 2) AS payable_total
         FROM counterparty_offsets o
         LEFT JOIN (SELECT counterparty_offset_id, SUM(allocated_amount) AS account_total FROM counterparty_offset_account_allocations GROUP BY counterparty_offset_id) a ON a.counterparty_offset_id = o.id
         LEFT JOIN (SELECT counterparty_offset_id, SUM(allocated_amount) AS payable_total FROM counterparty_offset_payable_allocations GROUP BY counterparty_offset_id) p ON p.counterparty_offset_id = o.id
         WHERE o.status = "posted"
           AND (ABS(o.amount - COALESCE(a.account_total, 0)) > 0.005 OR ABS(o.amount - COALESCE(p.payable_total, 0)) > 0.005)
         LIMIT 10'
    );
    $check(
        'Linked-party offsets allocate equal account-holder and supplier value',
        $brokenCounterpartyOffsets === [],
        $brokenCounterpartyOffsets === [] ? 'Every posted linked-party offset is conserved' : json_encode($brokenCounterpartyOffsets, JSON_UNESCAPED_SLASHES)
    );

    $crossDimensionOffsets = $fetchAll(
        'SELECT DISTINCT o.id, o.offset_no, o.branch_id, o.currency
         FROM counterparty_offsets o
         LEFT JOIN counterparty_offset_account_allocations ra ON ra.counterparty_offset_id = o.id
         LEFT JOIN customer_receivable_items cri ON cri.id = ra.customer_receivable_item_id
         LEFT JOIN counterparty_offset_payable_allocations pa ON pa.counterparty_offset_id = o.id
         LEFT JOIN supplier_obligations so ON so.id = pa.supplier_obligation_id
         WHERE o.status = "posted"
           AND (cri.branch_id <> o.branch_id OR cri.currency <> o.currency OR so.branch_id <> o.branch_id OR so.currency <> o.currency)
         LIMIT 10'
    );
    $check(
        'Linked-party offsets remain in one branch and currency',
        $crossDimensionOffsets === [],
        $crossDimensionOffsets === [] ? 'No cross-branch or cross-currency offset found' : json_encode($crossDimensionOffsets, JSON_UNESCAPED_SLASHES)
    );
}
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
$supplierPaymentReturnedSelect = $columnExists('supplier_payments', 'returned_amount')
    ? 'returned_amount'
    : '0 AS returned_amount';
$supplierPaymentReturnedValue = $columnExists('supplier_payments', 'returned_amount')
    ? 'returned_amount'
    : '0';

$supplierPaymentConservationBreaks = $fetchAll(
    'SELECT id, booking_reference, payment_no, currency, paid_amount, allocated_amount, unallocated_amount, '
        . $supplierPaymentConvertedAdvanceSelect . ', ' . $supplierPaymentReturnedSelect . ', status
     FROM supplier_payments
     WHERE status <> "void"
       AND ABS(paid_amount - (allocated_amount + unallocated_amount + '
        . $supplierPaymentConvertedAdvanceValue . ' + ' . $supplierPaymentReturnedValue . ')) > 0.005
     LIMIT 10'
);
$check(
    'Supplier payments conserve value',
    $supplierPaymentConservationBreaks === [],
    $supplierPaymentConservationBreaks === [] ? 'No broken supplier payments found' : json_encode($supplierPaymentConservationBreaks, JSON_UNESCAPED_SLASHES)
);

$negativeSupplierPaymentValues = $fetchAll(
    'SELECT id, booking_reference, payment_no, currency, paid_amount, allocated_amount, unallocated_amount, '
        . $supplierPaymentConvertedAdvanceSelect . ', ' . $supplierPaymentReturnedSelect . '
     FROM supplier_payments
     WHERE paid_amount < -0.005
        OR allocated_amount < -0.005
        OR unallocated_amount < -0.005
        OR ' . $supplierPaymentConvertedAdvanceValue . ' < -0.005
        OR ' . $supplierPaymentReturnedValue . ' < -0.005
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

if ($columnExists('booking_services', 'service_charge_currency')) {
    $invalidServiceCurrencySnapshots = $fetchAll(
        'SELECT id, booking_id, line_reference, currency, cost_currency,
                service_charge_currency, pricing_exchange_rate,
                service_charge_exchange_rate, service_charge,
                vat, discount_amount
         FROM booking_services
         WHERE is_active = 1
           AND (
                UPPER(TRIM(currency)) NOT IN ("PKR", "AED", "USD")
                OR UPPER(TRIM(cost_currency)) NOT IN ("PKR", "AED", "USD")
                OR UPPER(TRIM(service_charge_currency)) NOT IN ("PKR", "AED", "USD")
                OR (
                    UPPER(TRIM(currency)) <> UPPER(TRIM(cost_currency))
                    AND purchase_cost > 0.005
                    AND pricing_exchange_rate <= 0
                )
                OR (
                    UPPER(TRIM(currency)) <> UPPER(TRIM(service_charge_currency))
                    AND ABS(service_charge + vat - discount_amount) > 0.005
                    AND service_charge_exchange_rate <= 0
                )
           )
         LIMIT 10'
    );
    $check(
        'Service supplier, agency, and invoice currency snapshots remain valid',
        $invalidServiceCurrencySnapshots === [],
        $invalidServiceCurrencySnapshots === []
            ? 'No invalid multi-currency service snapshot found'
            : json_encode($invalidServiceCurrencySnapshots, JSON_UNESCAPED_SLASHES)
    );
}

$activeBookingBranchMismatches = $fetchAll(
    'SELECT mismatch_type, booking_reference, booking_branch_id, financial_branch_id
     FROM (
        SELECT "service" AS mismatch_type,
               b.booking_reference,
               b.branch_id AS booking_branch_id,
               bs.branch_id AS financial_branch_id
        FROM booking_services bs
        INNER JOIN bookings b ON b.id = bs.booking_id
        WHERE bs.branch_id <> b.branch_id
        UNION ALL
        SELECT "receivable",
               b.booking_reference,
               b.branch_id,
               cri.branch_id
        FROM customer_receivable_items cri
        INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
        WHERE cri.branch_id <> b.branch_id
        UNION ALL
        SELECT "supplier_obligation",
               b.booking_reference,
               b.branch_id,
               so.branch_id
        FROM supplier_obligations so
        INNER JOIN bookings b ON b.booking_reference = so.booking_reference
        WHERE so.branch_id <> b.branch_id
     ) branch_mismatches
     LIMIT 10'
);
$check(
    'Current booking services and subledgers belong to the booking branch',
    $activeBookingBranchMismatches === [],
    $activeBookingBranchMismatches === [] ? 'No current booking branch split found' : json_encode($activeBookingBranchMismatches, JSON_UNESCAPED_SLASHES)
);

$activeMoneyBranchMismatches = $fetchAll(
    'SELECT mismatch_type, booking_reference, source_reference, money_branch_id, target_branch_id
     FROM (
        SELECT "customer_receipt_treasury" AS mismatch_type,
               cr.booking_reference,
               cr.receipt_no AS source_reference,
               cr.branch_id AS money_branch_id,
               ta.branch_id AS target_branch_id
        FROM customer_receipts cr
        INNER JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
        WHERE cr.status <> "void"
          AND cr.branch_id <> ta.branch_id
        UNION ALL
        SELECT "customer_allocation",
               cri.booking_reference,
               cr.receipt_no,
               cr.branch_id,
               cri.branch_id
        FROM customer_receipt_allocations cra
        INNER JOIN customer_receipts cr ON cr.id = cra.customer_receipt_id
        INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
        WHERE cr.status <> "void"
          AND cr.branch_id <> cri.branch_id
        UNION ALL
        SELECT "supplier_allocation",
               so.booking_reference,
               sp.payment_no,
               sp.branch_id,
               so.branch_id
        FROM supplier_payment_allocations spa
        INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
        INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
        WHERE sp.status <> "void"
          AND sp.branch_id <> so.branch_id
     ) money_branch_mismatches
     LIMIT 10'
);
$check(
    'Active money and allocations remain within their financial branch',
    $activeMoneyBranchMismatches === [],
    $activeMoneyBranchMismatches === [] ? 'No active cross-branch money allocation found' : json_encode($activeMoneyBranchMismatches, JSON_UNESCAPED_SLASHES)
);

$currentCurrencyJournalBranchMismatches = $fetchAll(
    'SELECT DISTINCT je.id,
            je.booking_reference,
            je.currency,
            b.branch_id AS booking_branch_id,
            je.branch_id AS journal_branch_id,
            je.source_type,
            je.source_reference
     FROM journal_entries je
     INNER JOIN bookings b ON b.booking_reference = je.booking_reference
     WHERE je.branch_id <> b.branch_id
       AND EXISTS (
            SELECT 1
            FROM booking_services bs
            WHERE bs.booking_id = b.id
              AND (bs.currency = je.currency OR bs.cost_currency = je.currency)
       )
     LIMIT 10'
);
$check(
    'Current-currency booking journals belong to the booking branch',
    $currentCurrencyJournalBranchMismatches === [],
    $currentCurrencyJournalBranchMismatches === [] ? 'No current-currency journal branch split found' : json_encode($currentCurrencyJournalBranchMismatches, JSON_UNESCAPED_SLASHES)
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
                        WHEN UPPER(TRIM(new_invoice_currency)) = UPPER(TRIM(new_cost_currency))
                            THEN new_purchase_cost
                        ELSE ROUND(new_purchase_cost * new_pricing_exchange_rate, 0)
                    END
                    + (
                        new_vat_amount - new_discount_amount
                      ) * CASE
                            WHEN UPPER(TRIM(new_invoice_currency)) = UPPER(TRIM(new_service_charge_currency))
                                THEN 1
                            ELSE new_service_charge_exchange_rate
                          END,
                    2
                ) AS price_floor_without_service_charge
         FROM service_financial_corrections
         WHERE (
                new_final_sale_price >= ROUND(
                    CASE
                        WHEN UPPER(TRIM(new_invoice_currency)) = UPPER(TRIM(new_cost_currency))
                            THEN new_purchase_cost
                        ELSE ROUND(new_purchase_cost * new_pricing_exchange_rate, 0)
                    END
                    + (
                        new_vat_amount - new_discount_amount
                      ) * CASE
                            WHEN UPPER(TRIM(new_invoice_currency)) = UPPER(TRIM(new_service_charge_currency))
                                THEN 1
                            ELSE new_service_charge_exchange_rate
                          END,
                    2
                )
                AND ABS(
                    new_final_sale_price - (
                        CASE
                            WHEN UPPER(TRIM(new_invoice_currency)) = UPPER(TRIM(new_cost_currency))
                                THEN new_purchase_cost
                            ELSE ROUND(new_purchase_cost * new_pricing_exchange_rate, 0)
                        END
                        + (
                            new_service_charge + new_vat_amount - new_discount_amount
                          ) * CASE
                                WHEN UPPER(TRIM(new_invoice_currency)) = UPPER(TRIM(new_service_charge_currency))
                                    THEN 1
                                ELSE new_service_charge_exchange_rate
                              END
                    )
                ) > 0.005
              )
            OR (
                new_final_sale_price < ROUND(
                    CASE
                        WHEN UPPER(TRIM(new_invoice_currency)) = UPPER(TRIM(new_cost_currency))
                            THEN new_purchase_cost
                        ELSE ROUND(new_purchase_cost * new_pricing_exchange_rate, 0)
                        END
                        + (
                            new_vat_amount - new_discount_amount
                          ) * CASE
                                WHEN UPPER(TRIM(new_invoice_currency)) = UPPER(TRIM(new_service_charge_currency))
                                    THEN 1
                                ELSE new_service_charge_exchange_rate
                              END,
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
    exit(1);
}

echo PHP_EOL . 'Financial invariants audit passed.' . PHP_EOL;
exit(0);
