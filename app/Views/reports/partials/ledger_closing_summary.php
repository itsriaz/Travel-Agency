<?php
$closingReport = (string) ($selectedReport ?? '');
$closingCards = array_values(array_filter(
    (array) ($summaryCards ?? []),
    static function (mixed $card) use ($closingReport): bool {
        if (! is_array($card)) {
            return false;
        }
        if (($card['group'] ?? '') === 'linked_roles') {
            return false;
        }
        $label = (string) ($card['label'] ?? '');
        if ($closingReport === 'customer_ledger') {
            return str_starts_with($label, 'Total Sale / ')
                || str_starts_with($label, 'Total Received / ')
                || str_starts_with($label, 'Total Outstanding / ')
                || str_starts_with($label, 'Total Customer Invoice Outstanding / ')
                || str_starts_with($label, 'Customer Sale / ')
                || str_starts_with($label, 'Customer Cash Received / ')
                || str_starts_with($label, 'Customer Outstanding / ')
                || str_starts_with($label, 'Invoice Outstanding / ');
        }

        return $closingReport === 'supplier_ledger'
            && (str_starts_with($label, 'Supplier Debit / ')
                || str_starts_with($label, 'Supplier Ledger Debit / ')
                || str_starts_with($label, 'Supplier Credit / ')
                || str_starts_with($label, 'Gross Paid to Supplier / ')
                || str_starts_with($label, 'Supplier bills and adjustments / ')
                || str_starts_with($label, 'Payments to suppliers / ')
                || str_starts_with($label, 'Supplier Payments / ')
                || str_starts_with($label, 'Supplier Balance / ')
                || str_starts_with($label, 'Supplier Invoice Balance / ')
                || str_starts_with($label, 'Payable to ')
                || str_starts_with($label, 'Receivable from ')
                || str_starts_with($label, 'Settled with '));
    }
));
?>
<?php if ($closingCards !== []): ?>
    <style>
        .ledger-closing-summary { margin-top: 14px; padding: 12px; border: 1px solid #cbddeb; border-radius: 10px; background: #f7fbff; }
        .ledger-closing-summary h3 { margin: 0 0 5px; }
        .ledger-closing-summary p { margin: 4px 0 9px; font-size: 12px; }
        .ledger-closing-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 8px; }
        .ledger-closing-summary-grid div { padding: 8px 10px; border: 1px solid #dce8f2; border-radius: 7px; background: #fff; }
        .ledger-closing-summary-grid span { display: block; font-size: 11px; }
        .ledger-closing-summary-grid strong { display: block; margin-top: 3px; }
        @media print { .ledger-closing-summary { break-inside: avoid; background: #fff; } }
    </style>
    <section class="ledger-closing-summary">
        <h3>Ledger Totals</h3>
        <?php if ($closingReport === 'supplier_ledger' && ($isLinkedPartyLedger ?? false)): ?>
            <p>Supplier-role totals only. The linked account receivable and combined net are shown separately above.</p>
        <?php elseif ($closingReport === 'supplier_ledger'): ?>
            <p>Payments include advances and direct payments to suppliers. Supplier Balance shows the amount still payable or held as an advance.</p>
        <?php else: ?>
            <p>Sales and receipts follow the selected filters. Invoice outstanding shows the current customer balance.</p>
        <?php endif; ?>
        <div class="ledger-closing-summary-grid">
            <?php foreach ($closingCards as $closingCard): ?>
                <div>
                    <span><?= e((string) ($closingCard['label'] ?? '')) ?></span>
                    <strong><?= e((string) ($closingCard['value'] ?? '')) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
