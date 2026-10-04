<?php
$closingReport = (string) ($selectedReport ?? '');
$closingCards = array_values(array_filter(
    (array) ($summaryCards ?? []),
    static function (mixed $card) use ($closingReport): bool {
        if (! is_array($card)) {
            return false;
        }
        $label = (string) ($card['label'] ?? '');
        if ($closingReport === 'customer_ledger') {
            return str_starts_with($label, 'Total Sale / ')
                || str_starts_with($label, 'Total Received / ')
                || str_starts_with($label, 'Total Outstanding / ');
        }

        return $closingReport === 'supplier_ledger'
            && (str_starts_with($label, 'Supplier Debit / ')
                || str_starts_with($label, 'Supplier Credit / ')
                || str_starts_with($label, 'Gross Paid to Supplier / ')
                || str_starts_with($label, 'Supplier Balance / '));
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
            <p>Combined totals for the linked account holder and supplier. This is a read-only net position and does not post an automatic adjustment.</p>
        <?php elseif ($closingReport === 'supplier_ledger'): ?>
            <p>Gross paid to supplier is historical payment activity, not the amount still owed. Use Supplier Balance for the net position.</p>
        <?php else: ?>
            <p>Customer ledger totals for the selected filters and currencies.</p>
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
