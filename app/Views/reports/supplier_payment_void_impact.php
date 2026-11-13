<?php
$payment = $impact['payment'];
$currency = strtoupper((string) $payment['currency']);
$money = static fn ($amount): string => number_format((float) $amount, 2);
$totals = $impact['totals'];
$isGlobal = strtolower((string) ($payment['payment_scope'] ?? '')) === 'global'
    || strtoupper((string) ($payment['booking_reference'] ?? '')) === 'GLOBAL';
$hasPostedSupplierRefund = count(array_filter($impact['cancellation_events'],
    static fn (array $event): bool => $event['has_posted_supplier_refund'])) > 0;
$paymentDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $payment['payment_date']);
$statusLabel = ucwords(str_replace('_', ' ', (string) $payment['status']));
$impactSummary = [];
foreach ($impact['replacement']['obligations'] as $obligation) {
    $obligationCurrency = strtoupper((string) $obligation['currency']);
    $impactSummary[$obligationCurrency] ??= ['reopens' => 0.0, 'covered' => 0.0, 'open' => 0.0];
    $impactSummary[$obligationCurrency]['reopens'] += (float) $obligation['reopened_amount'];
    $impactSummary[$obligationCurrency]['covered'] += (float) $obligation['covered_amount'];
    $impactSummary[$obligationCurrency]['open'] += (float) $obligation['remaining_open'];
}
?>
<style>
.supplier-void-impact{--void-ink:#12324b;--void-muted:#587187;--void-line:#d5e2eb;--void-blue:#1177a1;max-width:1340px;margin:0 auto;display:grid;gap:14px;color:var(--void-ink)}
.supplier-void-impact__head,.supplier-void-impact__panel{min-width:0;background:#fff;border:1px solid var(--void-line);border-radius:14px;box-shadow:0 6px 20px rgba(18,50,75,.045)}
.supplier-void-impact__head{position:relative;overflow:hidden;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:center;padding:22px 25px;background:linear-gradient(112deg,#fff 0%,#fff 60%,#eef7fa 100%)}
.supplier-void-impact__head::before{content:"";position:absolute;inset:0 auto 0 0;width:5px;background:linear-gradient(#137ba5,#27aa99)}
.supplier-void-impact__eyebrow{display:block;margin-bottom:5px;color:#407c96;font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
.supplier-void-impact__head h1{margin:0;color:#0e2b43;font-size:clamp(22px,2.15vw,30px);letter-spacing:-.035em;line-height:1.13}
.supplier-void-impact__head h1 span{color:#126d96}
.supplier-void-impact__meta{display:flex;align-items:center;flex-wrap:wrap;gap:6px 10px;margin:10px 0 0;color:var(--void-muted);font-size:12px}
.supplier-void-impact__meta span+span:not(.supplier-void-impact__status)::before{content:"";display:inline-block;width:3px;height:3px;margin:0 10px 3px 0;border-radius:50%;background:#9bb3c3}
.supplier-void-impact__meta strong{color:#264c65}
.supplier-void-impact__status{display:inline-flex;align-items:center;padding:4px 9px;border:1px solid #b9d8e5;border-radius:999px;background:#edf8fc;color:#0d6388;font-size:11px;font-weight:800}
.supplier-void-impact__status::before{content:"";width:6px;height:6px;margin-right:6px;border-radius:50%;background:#188baa}
.supplier-void-impact__head-note{margin:13px 0 0;color:#4f697d;font-size:12px}
.supplier-void-impact__head-side{display:grid;justify-items:end;gap:13px;text-align:right}
.supplier-void-impact__head-amount{display:grid;gap:2px}
.supplier-void-impact__head-amount span{color:#688397;font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}
.supplier-void-impact__head-amount strong{color:#103550;font-size:24px;letter-spacing:-.035em;line-height:1.1}
.supplier-void-impact__head .btn{white-space:nowrap}
.supplier-void-impact__panel{padding:18px 20px}
.supplier-void-impact__section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:13px}
.supplier-void-impact__section-head h2{margin:0;color:#12324b;font-size:17px;letter-spacing:-.02em}
.supplier-void-impact__section-head p{margin:3px 0 0;color:var(--void-muted);font-size:12px}
.supplier-void-impact__section-tag{flex-shrink:0;padding:4px 8px;border:1px solid #d5e5ed;border-radius:6px;background:#f3f8fb;color:#46677e;font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.supplier-void-impact__metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.supplier-void-impact__metric{min-width:0;display:flex;flex-direction:column;justify-content:center;padding:10px 12px;border:1px solid #d9e7ef;border-radius:9px;background:#f6fafc}
.supplier-void-impact__metric span{color:#587992;font-size:10px;font-weight:800;letter-spacing:.055em;text-transform:uppercase}
.supplier-void-impact__metric strong{display:block;margin-top:3px;color:#12324b;font-size:17px;font-variant-numeric:tabular-nums;line-height:1.2}
.supplier-void-impact__notice{margin:10px 0 0;padding:10px 12px;border:1px solid #e8d2aa;border-left:3px solid #c99237;border-radius:7px;background:#fff9ee;color:#77551e;font-size:12px;line-height:1.45}
.supplier-void-impact__notice--critical{border-color:#ebc1c0;border-left-color:#b84b48;background:#fff5f4;color:#8b3635}
.supplier-void-impact__table-wrap{overflow-x:auto;border:1px solid #e0eaf0;border-radius:8px}
.supplier-void-impact table{width:100%;border-collapse:collapse;font-size:12px;font-variant-numeric:tabular-nums}
.supplier-void-impact th,.supplier-void-impact td{padding:9px 11px;border-bottom:1px solid #e6edf2;text-align:left;white-space:nowrap}
.supplier-void-impact tbody tr:last-child td{border-bottom:0}
.supplier-void-impact tbody tr:nth-child(even){background:#fbfdfe}
.supplier-void-impact tbody tr:hover{background:#f4f9fc}
.supplier-void-impact th{background:#f1f6f9;color:#45667e;font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.supplier-void-impact td:first-child{font-weight:700;color:#244b66}
.supplier-void-impact td.num,.supplier-void-impact th.num{text-align:right}
.supplier-void-impact__trail{display:grid;gap:15px}
.supplier-void-impact__trail-block+.supplier-void-impact__trail-block{padding-top:15px;border-top:1px solid #e3edf3}
.supplier-void-impact__trail-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 8px}
.supplier-void-impact__trail-title h3{margin:0;color:#214762;font-size:13px}
.supplier-void-impact__trail-title span{color:#6c8799;font-size:11px;font-weight:700}
.supplier-void-impact__impact{border-color:#aacddd;background:linear-gradient(180deg,#f6fbfd 0,#fff 140px)}
.supplier-void-impact__impact-summary{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:13px}
.supplier-void-impact__impact-stat{display:grid;gap:2px;min-width:145px;padding:8px 11px;border:1px solid #cfe3eb;border-radius:8px;background:#fff}
.supplier-void-impact__impact-stat span{color:#5b7a8e;font-size:10px;font-weight:800;text-transform:uppercase}
.supplier-void-impact__impact-stat strong{font-size:15px;font-variant-numeric:tabular-nums}
.supplier-void-impact__impact-stat--open{border-color:#ead1af;background:#fffaf2;color:#875a1e}
.supplier-void-impact__proposal{display:block;color:#176c89;font-weight:700}
.supplier-void-impact__empty{color:#8295a1;font-style:italic;font-weight:400}
.supplier-void-impact__available{margin:10px 0 0;color:#315f74;font-size:12px}
.supplier-void-impact__available strong{display:inline-block;margin:3px 7px 0 0;padding:4px 8px;border:1px solid #c8e2e7;border-radius:6px;background:#eef9f8;color:#116775}
.supplier-void-impact__confirm{border-color:#e3c9c8;background:linear-gradient(180deg,#fffafa 0,#fff 145px)}
.supplier-void-impact__confirm .supplier-void-impact__section-head h2{color:#8e3338}
.supplier-void-impact__actions{display:grid;gap:13px}
.supplier-void-impact__actions label{color:#31546c;font-size:12px;font-weight:700}
.supplier-void-impact__choice{display:flex!important;align-items:flex-start;gap:11px;padding:11px 13px;border:1px solid #badbd9;border-radius:8px;background:#f0faf8;cursor:pointer}
.supplier-void-impact__choice:has(input:checked){border-color:#258b7b;box-shadow:0 0 0 2px rgba(37,139,123,.1)}
.supplier-void-impact__choice input{width:16px;min-width:16px;height:16px;min-height:16px;margin:1px 0 0;accent-color:#147c73}
.supplier-void-impact__choice span{display:grid;gap:3px}
.supplier-void-impact__choice small{color:#567788;font-size:11px;font-weight:400}
.supplier-void-impact__reason{max-width:720px}
.supplier-void-impact__reason label{display:grid;gap:5px}
.supplier-void-impact__reason input{min-height:39px}
.supplier-void-impact__form-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-top:12px;border-top:1px solid #eddddd}
.supplier-void-impact__form-foot p{margin:0;color:#7b6666;font-size:11px}
.supplier-void-impact__form-foot .btn{min-height:38px}
.supplier-void-impact__cancellation-fields{display:flex;gap:10px;flex-wrap:wrap}
.supplier-void-impact__cancellation-fields label{display:grid;gap:5px}
.supplier-void-impact__cancellation-fields input{width:140px}
@media(max-width:650px){.supplier-void-impact{gap:10px}.supplier-void-impact__head{grid-template-columns:1fr;padding:18px}.supplier-void-impact__head-side{justify-items:start;text-align:left}.supplier-void-impact__head-amount strong{font-size:22px}.supplier-void-impact__panel{padding:14px}.supplier-void-impact__metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.supplier-void-impact__section-head{flex-wrap:wrap}.supplier-void-impact__impact-stat{flex:1 1 130px}}
</style>
<main class="supplier-void-impact">
    <section class="supplier-void-impact__head">
        <div>
            <span class="supplier-void-impact__eyebrow">Supplier payments / void review</span>
            <h1>Review payment <span><?= e((string) $payment['payment_no']) ?></span></h1>
            <div class="supplier-void-impact__meta">
                <span><strong><?= e((string) $impact['supplier_name']) ?></strong></span>
                <span><?= e($currency) ?></span>
                <span>Paid <?= e($paymentDate ? $paymentDate->format('d/m/Y') : (string) $payment['payment_date']) ?></span>
                <span class="supplier-void-impact__status"><?= e($statusLabel) ?></span>
            </div>
            <p class="supplier-void-impact__head-note">Review only. No money changes until you confirm the action below.</p>
        </div>
        <div class="supplier-void-impact__head-side">
            <div class="supplier-void-impact__head-amount"><span>Payment under review</span><strong><?= e($currency) ?> <?= e($money($totals['paid'])) ?></strong></div>
            <a class="btn btn-sm" href="<?= e(url('/suppliers/settlements/global')) ?>">Back to supplier payments</a>
        </div>
    </section>

    <section class="supplier-void-impact__panel">
        <div class="supplier-void-impact__section-head"><div><h2>Payment at a glance</h2><p>How this payment was applied and how much credit remains.</p></div><span class="supplier-void-impact__section-tag">Recorded position</span></div>
        <div class="supplier-void-impact__metrics">
            <?php foreach ([
                'Directly applied' => $totals['direct_allocated'],
                'Credit created' => $totals['credit_created'],
                'Credit used later' => $totals['credit_used'],
                'Credit still available' => $totals['credit_available'],
                'Payment returned' => $totals['returned'],
                'Unallocated payment' => $totals['unallocated'],
            ] as $label => $amount): ?>
                <div class="supplier-void-impact__metric">
                    <span><?= e($label) ?></span>
                    <strong><?= e($totals['mixed_currencies'] && in_array($label, ['Directly applied', 'Credit created', 'Credit used later', 'Credit still available'], true) ? 'See currency breakdown below' : $currency . ' ' . $money($amount)) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($totals['mixed_currencies']): ?>
            <p class="supplier-void-impact__notice">This payment has allocations or credit in more than one currency. The amounts are not added together; review each currency below before any correction.</p>
        <?php elseif (! $totals['source_reconciles'] || ! $totals['credit_reconciles']): ?>
            <p class="supplier-void-impact__notice supplier-void-impact__notice--critical">These payment and credit totals do not reconcile. Do not void this payment until the source records are reviewed.</p>
        <?php endif; ?>
        <?php if ($impact['advance_applications'] !== []): ?>
            <p class="supplier-void-impact__notice">This payment's credit has covered later supplier payables. Review below whether another available advance can cover the reopened amounts.</p>
        <?php endif; ?>
        <?php if ($impact['unsupported_credit_sources'] !== []): ?>
            <p class="supplier-void-impact__notice supplier-void-impact__notice--critical">This payment backs a separately recorded supplier refund credit. Review and reassign that refund source before voiding this payment.</p>
        <?php endif; ?>
        <?php if ($impact['cancellation_credit_ids'] !== []): ?>
            <p class="supplier-void-impact__notice">This payment funds cancellation credit. To void it, remove only its credit share. Enter the exact booking-currency reduction below; the other payment's share remains intact.</p>
        <?php endif; ?>
        <?php if ($hasPostedSupplierRefund): ?>
            <p class="supplier-void-impact__notice supplier-void-impact__notice--critical">A cancellation funded by this payment also has a separately posted supplier refund. Review that refund first; this payment cannot be voided from this screen yet.</p>
        <?php endif; ?>
    </section>

    <?php if ($impact['cancellation_events'] !== []): ?>
        <section class="supplier-void-impact__panel">
            <div class="supplier-void-impact__section-head"><div><h2>Cancellation credit to review</h2><p>The booking-currency figure belongs to the whole cancellation, not to one payment. Enter any partial share separately below.</p></div><span class="supplier-void-impact__section-tag">Additional review</span></div>
            <div class="supplier-void-impact__table-wrap"><table>
                <thead><tr><th>Booking / event</th><th>Date</th><th class="num">Credit from this payment</th><th class="num">Credit from other payments</th><th class="num">Whole cancellation credit</th><th>Funding records</th></tr></thead>
                <tbody>
                <?php foreach ($impact['cancellation_events'] as $event): ?>
                    <tr>
                        <td><?= e((string) $event['booking_reference']) ?> / <?= e((string) $event['event_id']) ?></td>
                        <td><?= e((string) $event['event_date']) ?></td>
                        <td class="num"><?= e((string) $event['recorded_source_currency']) ?> <?= e($money($event['selected_source_amount'])) ?></td>
                        <td class="num"><?= e((string) $event['recorded_source_currency']) ?> <?= e($money($event['other_source_amount'])) ?></td>
                        <td class="num"><?= e((string) $event['recorded_source_currency']) ?> <?= e($money($event['recorded_source_amount'])) ?> / <?= e((string) $event['booking_currency']) ?> <?= e($money($event['recorded_booking_equivalent'])) ?></td>
                        <td>
                            <?php foreach ($event['funding'] as $funding): ?>
                                <div><?= e((string) $funding['payment_no']) ?>: <?= e((string) $funding['currency']) ?> <?= e($money($funding['amount'])) ?></div>
                            <?php endforeach; ?>
                            <?php if ($event['selected_source_amount'] < $event['recorded_source_amount'] - 0.01): ?>
                                <small>Exact <?= e((string) $event['booking_currency']) ?> share of this payment is not recorded.</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    <?php endif; ?>

    <section class="supplier-void-impact__panel">
        <div class="supplier-void-impact__section-head"><div><h2>Payment trail</h2><p>The original allocations, credit created, and later use remain visible for review.</p></div><span class="supplier-void-impact__section-tag">Source records</span></div>
        <div class="supplier-void-impact__trail">
        <div class="supplier-void-impact__trail-block">
        <div class="supplier-void-impact__trail-title"><h3>Paid directly to bookings</h3><span><?= count($impact['direct_allocations']) ?> allocation<?= count($impact['direct_allocations']) === 1 ? '' : 's' ?></span></div>
        <div class="supplier-void-impact__table-wrap"><table>
            <thead><tr><th>Allocation</th><th>Booking</th><th>Branch</th><th>Obligation</th><th class="num">Amount</th><th class="num">Current payable</th></tr></thead>
            <tbody>
            <?php foreach ($impact['direct_allocations'] as $row): ?>
                <tr><td><?= e((string) $row['allocation_id']) ?></td><td><?= e((string) $row['booking_reference']) ?></td><td><?= e((string) $row['branch_name']) ?></td><td><?= e((string) $row['obligation_id']) ?></td><td class="num"><?= e((string) $row['currency']) ?> <?= e($money($row['amount'])) ?></td><td class="num"><?= e((string) $row['currency']) ?> <?= e($money($row['net_payable_amount'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($impact['direct_allocations'] === []): ?><tr><td colspan="6">No direct allocations.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        </div>

        <div class="supplier-void-impact__trail-block">
        <div class="supplier-void-impact__trail-title"><h3>Supplier credit created</h3><span><?= count($impact['linked_advances']) ?> record<?= count($impact['linked_advances']) === 1 ? '' : 's' ?></span></div>
        <div class="supplier-void-impact__table-wrap"><table>
            <thead><tr><th>Credit ID</th><th>Reference</th><th>Branch</th><th class="num">Created</th><th class="num">Still available</th></tr></thead>
            <tbody>
            <?php foreach ($impact['linked_advances'] as $row): ?>
                <tr><td><?= e((string) $row['id']) ?></td><td><?= e((string) $row['reference_no']) ?></td><td><?= e((string) $row['branch_name']) ?></td><td class="num"><?= e((string) $row['currency']) ?> <?= e($money($row['deposit_amount'])) ?></td><td class="num"><?= e((string) $row['currency']) ?> <?= e($money($row['available_amount'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($impact['linked_advances'] === []): ?><tr><td colspan="5">No linked supplier credit.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        </div>

        <div class="supplier-void-impact__trail-block">
        <div class="supplier-void-impact__trail-title"><h3>Later bookings covered by this credit</h3><span><?= count($impact['advance_applications']) ?> application<?= count($impact['advance_applications']) === 1 ? '' : 's' ?></span></div>
        <div class="supplier-void-impact__table-wrap"><table>
            <thead><tr><th>Application</th><th>Booking</th><th>Branch</th><th>Obligation</th><th class="num">Credit used</th><th class="num">Payable covered</th><th class="num">Current payable</th></tr></thead>
            <tbody>
            <?php foreach ($impact['advance_applications'] as $row): ?>
                <tr><td><?= e((string) $row['application_id']) ?></td><td><?= e((string) $row['booking_reference']) ?></td><td><?= e((string) $row['branch_name']) ?></td><td><?= e((string) $row['obligation_id']) ?></td><td class="num"><?= e((string) $row['advance_currency']) ?> <?= e($money($row['advance_amount'])) ?></td><td class="num"><?= e((string) $row['obligation_currency']) ?> <?= e($money($row['obligation_amount'])) ?></td><td class="num"><?= e((string) $row['obligation_currency']) ?> <?= e($money($row['net_payable_amount'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($impact['advance_applications'] === []): ?><tr><td colspan="7">No later applications.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        </div>
        </div>
    </section>

    <section class="supplier-void-impact__panel supplier-void-impact__impact">
        <div class="supplier-void-impact__section-head"><div><h2>What happens to supplier payables?</h2><p>Only the amounts reopened by this void can use another advance. Existing open balances are not silently paid.</p></div><span class="supplier-void-impact__section-tag">Projected outcome</span></div>
        <?php if ($impactSummary !== []): ?>
            <div class="supplier-void-impact__impact-summary">
                <?php foreach ($impactSummary as $impactCurrency => $amounts): ?>
                    <div class="supplier-void-impact__impact-stat"><span><?= e($impactCurrency) ?> reopens</span><strong><?= e($impactCurrency) ?> <?= e($money($amounts['reopens'])) ?></strong></div>
                    <div class="supplier-void-impact__impact-stat"><span><?= e($impactCurrency) ?> proposed from other advances</span><strong><?= e($impactCurrency) ?> <?= e($money($amounts['covered'])) ?></strong></div>
                    <div class="supplier-void-impact__impact-stat supplier-void-impact__impact-stat--open"><span><?= e($impactCurrency) ?> open if applied</span><strong><?= e($impactCurrency) ?> <?= e($money($amounts['open'])) ?></strong></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="supplier-void-impact__table-wrap"><table>
            <thead><tr><th>Booking</th><th class="num">Current open</th><th class="num">Reopens</th><th>Proposed advance</th><th class="num">Open if applied</th></tr></thead>
            <tbody>
            <?php foreach ($impact['replacement']['obligations'] as $row): ?>
                <tr>
                    <td><?= e((string) $row['booking_reference']) ?></td>
                    <td class="num"><?= e((string) $row['currency']) ?> <?= e($money($row['current_payable'])) ?></td>
                    <td class="num"><?= e((string) $row['currency']) ?> <?= e($money($row['reopened_amount'])) ?></td>
                    <td>
                        <?php foreach ($impact['replacement']['lines'] as $line): ?>
                            <?php if ((int) $line['obligation_id'] === (int) $row['id']): ?>
                                <span class="supplier-void-impact__proposal"><?= e((string) $line['source_label']) ?>: <?= e((string) $line['currency']) ?> <?= e($money($line['amount'])) ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ((float) $row['covered_amount'] <= 0.005): ?><span class="supplier-void-impact__empty">No matching credit</span><?php endif; ?>
                    </td>
                    <td class="num"><strong><?= (string) $row['status'] === 'cancelled' ? 'Cancelled booking; reviewed separately' : e((string) $row['currency']) . ' ' . e($money($row['remaining_open'])) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($impact['replacement']['obligations'] === []): ?><tr><td colspan="5">This payment has no affected supplier payables.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if ($impact['replacement']['can_apply']): ?>
            <p class="supplier-void-impact__available">Matching advance available:
                <?php foreach ($impact['replacement']['other_advances'] as $advance): ?>
                    <?php if (in_array((int) $advance['id'], array_column($impact['replacement']['lines'], 'advance_id'), true)): ?>
                        <strong><?= e((string) $advance['source_label']) ?>: <?= e((string) $advance['currency']) ?> <?= e($money($advance['available_amount'])) ?></strong>
                    <?php endif; ?>
                <?php endforeach; ?>
            </p>
        <?php elseif ($impact['replacement']['obligations'] !== []): ?>
            <p class="supplier-void-impact__notice"><?= $impact['cancellation_events'] !== []
                ? 'Cancellation credit requires its own review; no other advance is applied during this void.'
                : 'No matching advance can be applied in this void. Any reopened payable will remain open unless settled separately.' ?></p>
        <?php endif; ?>
    </section>

    <?php if ((string) $payment['status'] !== 'void'): ?>
        <section class="supplier-void-impact__panel supplier-void-impact__confirm">
            <div class="supplier-void-impact__section-head"><div><h2>Confirm financial reversal</h2><p>Void only if this payment did not happen or was entered twice. For wrong payment details, use a correction instead. Customer invoices will not change.</p></div><span class="supplier-void-impact__section-tag">Final step</span></div>
            <?php if ($impact['unsupported_credit_sources'] === []
                && ($impact['cancellation_credit_ids'] === [] || $impact['cancellation_events_complete'])
                && ! $hasPostedSupplierRefund
                && $totals['credit_reconciles']
                && ($totals['source_reconciles'] || $totals['mixed_currencies'])): ?>
                <form method="post" action="<?= e(url($isGlobal ? '/suppliers/settlements/global/void' : '/workspace/suppliers/payments/void')) ?>" class="supplier-void-impact__actions" onsubmit="return confirm(this.elements.apply_replacement_advances?.checked ? 'Void this payment and apply the exact replacement advances shown above? Any uncovered payable will stay open.' : 'Void this payment and leave the affected supplier payables open? No other advance will be applied.');">
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="supplier_payment_id" value="<?= e((string) $payment['id']) ?>">
                    <input type="hidden" name="impact_token" value="<?= e((string) $impact['impact_token']) ?>">
                    <input type="hidden" name="replacement_token" value="<?= e((string) $impact['replacement']['token']) ?>">
                    <?php if (! $isGlobal): ?><input type="hidden" name="booking_id" value="<?= e((string) ($booking['id'] ?? 0)) ?>"><?php endif; ?>
                    <input type="hidden" name="confirm_reopen_payables" value="1">
                    <?php if ($impact['replacement']['can_apply']): ?>
                        <label class="supplier-void-impact__choice"><input type="checkbox" name="apply_replacement_advances" value="1"><span>Use the matching advances shown above<small>Optional. Leave unchecked to reopen the payables without applying other credit. Any uncovered amount stays open.</small></span></label>
                    <?php endif; ?>
                    <?php if ($impact['cancellation_events'] !== []): ?>
                        <div class="supplier-void-impact__cancellation-fields">
                            <?php foreach ($impact['cancellation_events'] as $event): ?>
                                <?php $wholeEvent = (float) $event['other_source_amount'] <= 0.005; ?>
                                <?php $sameCurrency = (string) $event['booking_currency'] === (string) $event['recorded_source_currency']; ?>
                                <label><?= e((string) $event['booking_reference']) ?>: <?= e((string) $event['booking_currency']) ?> credit to remove
                                    <input type="text" inputmode="decimal" name="cancellation_credit_equivalent[<?= e((string) $event['event_id']) ?>]"
                                        value="<?= $wholeEvent || $sameCurrency ? e($money($sameCurrency ? $event['selected_source_amount'] : $event['recorded_booking_equivalent'])) : '' ?>"
                                        placeholder="<?= $wholeEvent || $sameCurrency ? '' : 'Exact amount' ?>" <?= $wholeEvent || $sameCurrency ? 'readonly' : '' ?> required>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="supplier-void-impact__choice"><input type="checkbox" name="confirm_remove_cancellation_credit" value="1" required><span>Remove only this payment's cancellation credit<small>Other payments' credit remains unchanged.</small></span></label>
                    <?php endif; ?>
                    <div class="supplier-void-impact__reason"><label>Reason for void (required)<input type="text" name="void_reason" minlength="5" maxlength="500" required placeholder="For example: duplicate supplier payment"></label></div>
                    <div class="supplier-void-impact__form-foot"><p>This action reverses the payment and records the reason in its audit history.</p><button class="btn btn-danger" type="submit"><?= $impact['cancellation_events'] !== [] ? 'Void payment and remove its credit' : 'Void this payment' ?></button></div>
                </form>
            <?php else: ?>
                <p class="supplier-void-impact__notice">Posting is unavailable until the cancellation or refund credit source is resolved and the payment totals reconcile.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
