<?php if (($linkedSettlementHistory['rows'] ?? []) !== []): ?>
    <style>
        .linked-settlement-history { margin-top: 16px; }
        .linked-settlement-history h3 { margin: 0 0 8px; }
        .linked-settlement-history p { margin: 6px 0; font-size: 12px; }
        .linked-settlement-history table { width: 100%; border-collapse: collapse; }
        .linked-settlement-history th, .linked-settlement-history td {
            padding: 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere;
        }
        .linked-settlement-history td:last-child { min-width: 220px; }
        @media print {
            .linked-settlement-history .dense-table-wrap { overflow: visible; }
            .linked-settlement-history table { table-layout: fixed; font-size: 8px; }
            .linked-settlement-history th, .linked-settlement-history td { padding: 4px; }
            .linked-settlement-history td:last-child { min-width: 0; }
            .linked-settlement-history tr { break-inside: avoid; }
        }
    </style>
    <section class="panel linked-settlement-history">
        <h3>Reversed Linked Adjustments</h3>
        <p>History only: these records do not add to balances, payments, or totals above.
            Amounts are full adjustment totals, not individual invoice shares.
            Date filters match the original or void date; both entries are shown for context.
            Debit/credit amount filters apply only to the ledger above.</p>
        <p>Earlier releases are summarised where saved. Older released invoice links may no longer be available;
            clear booking/customer filters to see all reversed adjustments for this account or supplier.</p>
        <div class="dense-table-wrap">
            <table class="dense-table">
                <thead><tr>
                    <?php foreach ($linkedSettlementHistory['columns'] as $historyColumn): ?>
                        <th><?= e($historyColumn['label']) ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($linkedSettlementHistory['rows'] as $historyRow): ?>
                        <tr>
                            <?php foreach ($linkedSettlementHistory['columns'] as $historyColumn): ?>
                                <td><?= e((string) ($historyRow[$historyColumn['key']] ?? '')) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
