<?php

declare(strict_types=1);

return static function (\PDO $db): void {
    $db->exec("INSERT INTO currencies (code, name, symbol, reporting_role, sort_order, is_system, is_active) VALUES
        ('PKR', 'Pakistani Rupee', 'Rs', 'Consolidation Base', 10, 1, 1),
        ('AED', 'UAE Dirham', 'AED', 'Branch Transaction Currency', 20, 1, 1),
        ('USD', 'US Dollar', '$', 'Foreign Supplier / Package Currency', 30, 1, 1)
        ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        symbol = VALUES(symbol),
        reporting_role = VALUES(reporting_role),
        sort_order = VALUES(sort_order),
        is_system = VALUES(is_system),
        is_active = VALUES(is_active)
    ");

    $db->exec("INSERT INTO service_types (code, name, posting_mode, sort_order, is_system, is_active) VALUES
        ('AIR', 'Air Ticket', 'Receivable + Revenue + Supplier Payable', 10, 1, 1),
        ('VISA', 'Visa', 'Receivable + Supplier Payable', 20, 1, 1),
        ('UMR', 'Umrah', 'Package Service Posting', 30, 1, 1),
        ('TOUR', 'Tourism', 'Package Service Posting', 40, 1, 1),
        ('HOT', 'Hotel', 'Receivable + Supplier Payable', 50, 1, 1),
        ('TRN', 'Transport', 'Receivable + Supplier Payable', 60, 1, 1),
        ('OTH', 'Other Package', 'Receivable + Supplier Payable', 70, 1, 1)
        ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        posting_mode = VALUES(posting_mode),
        sort_order = VALUES(sort_order),
        is_system = VALUES(is_system),
        is_active = VALUES(is_active)
    ");

    $db->exec("INSERT INTO payment_methods (code, name, ledger_target, charges_target, sort_order, is_system, is_active) VALUES
        ('cash', 'Cash', 'Cash On Hand', NULL, 10, 1, 1),
        ('bank_transfer', 'Bank Transfer', 'Bank Clearing', NULL, 20, 1, 1),
        ('debit_card', 'Debit Card', 'Card Clearing', 'Card Charges Expense', 30, 1, 1),
        ('credit_card', 'Credit Card', 'Card Clearing', 'Card Charges Expense', 40, 1, 1)
        ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        ledger_target = VALUES(ledger_target),
        charges_target = VALUES(charges_target),
        sort_order = VALUES(sort_order),
        is_system = VALUES(is_system),
        is_active = VALUES(is_active)
    ");

    $db->exec("INSERT INTO supplier_modes (code, name, behavior, sort_order, is_system, is_active) VALUES
        ('normal_payable', 'Normal Payable', 'Creates direct supplier payable', 10, 1, 1),
        ('running_balance', 'Running Balance', 'Consumes supplier advance first', 20, 1, 1)
        ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        behavior = VALUES(behavior),
        sort_order = VALUES(sort_order),
        is_system = VALUES(is_system),
        is_active = VALUES(is_active)
    ");

    $db->exec("INSERT INTO document_types (code, name, linked_area, sort_order, is_system, is_active) VALUES
        ('passport_copy', 'Passport Copy', 'Traveler', 10, 1, 1),
        ('visa_form', 'Visa Form', 'Service Line', 20, 1, 1),
        ('voucher', 'Voucher', 'Service Line / Print', 30, 1, 1),
        ('invoice', 'Invoice', 'Booking / Accounts', 40, 1, 1)
        ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        linked_area = VALUES(linked_area),
        sort_order = VALUES(sort_order),
        is_system = VALUES(is_system),
        is_active = VALUES(is_active)
    ");

    $db->exec(
        'UPDATE chart_of_accounts
         SET purpose = CASE code
             WHEN "AR_CONTROL" THEN "Customer service dues by booking and service line"
             WHEN "AP_CONTROL" THEN "Supplier obligations generated from booking services"
             WHEN "CUSTOMER_CREDIT" THEN "Unallocated customer money pending allocation"
             WHEN "SUPPLIER_ADVANCES" THEN "Prepaid supplier balances and running advances"
             WHEN "CASH_ON_HAND" THEN "Counter cash collections"
             WHEN "BANK_CLEARING" THEN "Bank transfer and settlement staging"
             WHEN "CARD_CLEARING" THEN "Card settlement staging account"
             WHEN "SERVICE_REVENUE" THEN "Customer-side booking revenue"
             WHEN "SERVICE_COST" THEN "Supplier-side booking cost"
             WHEN "CARD_CHARGES" THEN "Card charge expense recognition"
             ELSE purpose
         END,
         is_active = 1'
    );

    $accountsStatement = $db->query('SELECT code, id FROM chart_of_accounts');
    $accountRows = $accountsStatement !== false ? $accountsStatement->fetchAll(\PDO::FETCH_KEY_PAIR) : [];

    $postingRules = [
        [
            'event_key' => 'service_line_created',
            'event_name' => 'Service line created',
            'source_area' => 'Booking Workspace',
            'financial_effect' => 'Immediate customer receivable created',
            'debit_code' => 'AR_CONTROL',
            'credit_code' => 'SERVICE_REVENUE',
            'rule_note' => 'Booking service creation posts receivable and revenue immediately.',
            'sort_order' => 10,
        ],
        [
            'event_key' => 'supplier_obligation_created',
            'event_name' => 'Supplier obligation created',
            'source_area' => 'Suppliers Panel',
            'financial_effect' => 'Immediate supplier payable created',
            'debit_code' => 'SERVICE_COST',
            'credit_code' => 'AP_CONTROL',
            'rule_note' => 'Supplier-side service cost creates cost and payable immediately.',
            'sort_order' => 20,
        ],
        [
            'event_key' => 'supplier_advance_deposit',
            'event_name' => 'Supplier advance deposited',
            'source_area' => 'Supplier Finance Control',
            'financial_effect' => 'Advance asset recorded before utilization',
            'debit_code' => 'SUPPLIER_ADVANCES',
            'credit_code' => 'BANK_CLEARING',
            'rule_note' => 'Prepayments to running-balance suppliers sit as advances until consumed.',
            'sort_order' => 30,
        ],
        [
            'event_key' => 'supplier_advance_applied',
            'event_name' => 'Supplier advance applied',
            'source_area' => 'Supplier Obligation Settlement',
            'financial_effect' => 'Advance balance reduces supplier payable',
            'debit_code' => 'AP_CONTROL',
            'credit_code' => 'SUPPLIER_ADVANCES',
            'rule_note' => 'Advance consumption clears payable exposure without another cash movement.',
            'sort_order' => 40,
        ],
        [
            'event_key' => 'customer_receipt_recorded',
            'event_name' => 'Customer receipt recorded',
            'source_area' => 'Payments Tab',
            'financial_effect' => 'Receipt parked as customer credit first',
            'debit_code' => 'BANK_CLEARING',
            'credit_code' => 'CUSTOMER_CREDIT',
            'rule_note' => 'Booking-level receipt capture remains unallocated until staff posts allocations.',
            'sort_order' => 50,
        ],
        [
            'event_key' => 'customer_receipt_allocated',
            'event_name' => 'Customer receipt allocated',
            'source_area' => 'Deferred Allocation',
            'financial_effect' => 'Customer credit clears service receivable',
            'debit_code' => 'CUSTOMER_CREDIT',
            'credit_code' => 'AR_CONTROL',
            'rule_note' => 'Allocation history remains separate from the original receipt event.',
            'sort_order' => 60,
        ],
    ];

    $ruleStatement = $db->prepare(
        'INSERT INTO posting_rules (
            event_key, event_name, source_area, financial_effect, debit_account_id, credit_account_id,
            rule_note, sort_order, is_system, is_active
         ) VALUES (
            :event_key, :event_name, :source_area, :financial_effect, :debit_account_id, :credit_account_id,
            :rule_note, :sort_order, 1, 1
         )
         ON DUPLICATE KEY UPDATE
         event_name = VALUES(event_name),
         source_area = VALUES(source_area),
         financial_effect = VALUES(financial_effect),
         debit_account_id = VALUES(debit_account_id),
         credit_account_id = VALUES(credit_account_id),
         rule_note = VALUES(rule_note),
         sort_order = VALUES(sort_order),
         is_system = VALUES(is_system),
         is_active = VALUES(is_active)'
    );

    foreach ($postingRules as $rule) {
        $debitAccountId = (int) ($accountRows[$rule['debit_code']] ?? 0);
        $creditAccountId = (int) ($accountRows[$rule['credit_code']] ?? 0);

        if ($debitAccountId <= 0 || $creditAccountId <= 0) {
            continue;
        }

        $ruleStatement->execute([
            'event_key' => $rule['event_key'],
            'event_name' => $rule['event_name'],
            'source_area' => $rule['source_area'],
            'financial_effect' => $rule['financial_effect'],
            'debit_account_id' => $debitAccountId,
            'credit_account_id' => $creditAccountId,
            'rule_note' => $rule['rule_note'],
            'sort_order' => $rule['sort_order'],
        ]);
    }
};
