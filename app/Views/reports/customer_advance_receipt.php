<?php

declare(strict_types=1);

$receipt = is_array($receipt ?? null) ? $receipt : [];
$customerName = trim((string) ($customerName ?? 'Customer'));
$branchBranding = is_array($branchBranding ?? null) ? $branchBranding : [];
$branchContact = is_array($branchBranding['contact'] ?? null) ? $branchBranding['contact'] : [];
$branchDirectory = is_array($branchDirectory ?? null) ? $branchDirectory : [];
$receiptCurrency = strtoupper(trim((string) ($receipt['currency'] ?? 'PKR')));
$receiptAmount = (float) ($receipt['received_amount'] ?? $receipt['tendered_amount'] ?? 0);
$receiptCharges = (float) ($receipt['charges_amount'] ?? 0);
$paymentMethod = ucwords(str_replace('_', ' ', trim((string) ($receipt['payment_method'] ?? ''))));
$referenceNumber = trim((string) ($receipt['reference_number'] ?? ''));
$bankCardDetail = trim((string) ($receipt['bank_card_detail'] ?? ''));
$remarks = trim((string) ($receipt['remarks'] ?? ''));
$branchCode = mb_strtolower(trim((string) ($branchBranding['code'] ?? '')));
$generatedAt = trim((string) ($generatedAt ?? ''));
$primaryContactLogo = trim((string) ($branchContact['logo_path'] ?? ''));
$primaryContactLogoSrc = $primaryContactLogo !== '' ? asset(ltrim($primaryContactLogo, '/')) : '';
$primaryContactLogoFallbackSrc = $primaryContactLogo !== '' ? url(ltrim($primaryContactLogo, '/')) : '';
$primaryContactBranchLabel = trim((string) ($branchContact['branch_label'] ?? ''));
$primaryContactLocationLabel = trim((string) ($branchContact['location_label'] ?? ''));
$primaryContactPerson = trim((string) ($branchContact['contact_person'] ?? ''));
$primaryContactServiceNote = trim((string) ($branchContact['service_note'] ?? ''));
$primaryContactAddress = trim((string) ($branchContact['address'] ?? ''));
$primaryContactLicense = trim((string) ($branchContact['license'] ?? ''));
$primaryContactEmail = trim((string) ($branchContact['email'] ?? ''));
$primaryContactPhones = [];
$primaryLandline = trim((string) ($branchContact['landline'] ?? ''));

if ($primaryLandline !== '') {
    $primaryContactPhones[] = [
        'type' => 'phone',
        'value' => $primaryLandline,
    ];
}

foreach ((array) ($branchContact['contacts'] ?? []) as $contactLine) {
    if (! is_array($contactLine)) {
        continue;
    }
    $phoneValue = trim((string) ($contactLine['phone'] ?? ''));
    if ($phoneValue === '') {
        continue;
    }
    $primaryContactPhones[] = [
        'type' => 'mobile',
        'value' => $phoneValue,
        'name' => trim((string) ($contactLine['name'] ?? 'Contact')),
        'role' => trim((string) ($contactLine['role'] ?? '')),
        'whatsapp' => true,
    ];
}

$formatMoney = static fn (float $value): string => number_format($value, 2);
$receiptIcon = static function (string $type): string {
    return match ($type) {
        'phone' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.62 10.79a15.46 15.46 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.01-.24c1.11.37 2.31.56 3.58.56a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.3 21 3 13.7 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.27.19 2.47.56 3.58a1 1 0 0 1-.24 1.01l-2.2 2.2Z"/></svg>',
        'mobile' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm5 18a1.25 1.25 0 1 0 0-2.5A1.25 1.25 0 0 0 12 20Zm4-5V5H8v10h8Z"/></svg>',
        'whatsapp' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19.05 4.94A9.86 9.86 0 0 0 12.04 2C6.57 2 2.12 6.45 2.12 11.92c0 1.75.46 3.46 1.33 4.97L2 22l5.26-1.38a9.9 9.9 0 0 0 4.76 1.21h.01c5.47 0 9.92-4.45 9.92-9.92 0-2.65-1.03-5.14-2.9-6.97ZM12.04 20.1h-.01a8.2 8.2 0 0 1-4.17-1.14l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.33c0-4.53 3.69-8.22 8.23-8.22 2.2 0 4.26.85 5.81 2.4a8.15 8.15 0 0 1 2.4 5.82c0 4.53-3.69 8.22-8.21 8.22Zm4.5-6.15c-.25-.13-1.47-.72-1.7-.8-.23-.08-.4-.13-.56.13-.16.25-.65.8-.79.96-.15.17-.3.19-.55.07-.25-.13-1.07-.39-2.03-1.25-.75-.67-1.26-1.5-1.41-1.75-.15-.25-.02-.39.11-.52.11-.11.25-.3.37-.45.13-.15.17-.25.25-.42.08-.17.04-.32-.02-.45-.07-.13-.56-1.35-.77-1.84-.2-.49-.4-.42-.56-.43h-.47c-.16 0-.42.06-.64.3s-.84.82-.84 2c0 1.18.86 2.32.98 2.48.12.17 1.68 2.57 4.07 3.61.57.25 1.01.39 1.36.5.57.18 1.1.15 1.51.09.46-.07 1.47-.6 1.68-1.18.21-.58.21-1.08.15-1.18-.06-.09-.22-.14-.47-.27Z"/></svg>',
        'email' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm0 2v.2l8 5.34 8-5.34V7H4Zm16 10V9.61l-7.45 4.97a1 1 0 0 1-1.1 0L4 9.61V17h16Z"/></svg>',
        default => '',
    };
};
?>

<main class="report-print-shell">
    <div class="no-print" style="display:flex;justify-content:flex-end;margin:0 0 12px;">
        <button class="btn btn-primary" type="button" onclick="window.print()">Print / Save PDF</button>
    </div>

    <section class="receipt-sheet">
        <header class="receipt-sheet__head">
            <div class="receipt-sheet__brand-block">
                <div class="receipt-sheet__brand-main">
                    <?php if ($primaryContactLogoSrc !== ''): ?>
                        <div class="receipt-sheet__logo-wrap">
                            <img
                                class="receipt-sheet__logo"
                                src="<?= e($primaryContactLogoSrc) ?>"
                                data-fallback-src="<?= e($primaryContactLogoFallbackSrc) ?>"
                                onerror="if(this.dataset.fallbackApplied!=='1' && this.dataset.fallbackSrc){this.dataset.fallbackApplied='1';this.src=this.dataset.fallbackSrc;}"
                                alt="<?= e((string) ($branchBranding['receipt_name'] ?? $branchBranding['name'] ?? 'Branch Logo')) ?>"
                            >
                        </div>
                    <?php endif; ?>
                    <div class="receipt-sheet__brand-copy">
                        <?php if ($primaryContactBranchLabel !== ''): ?>
                            <div class="receipt-sheet__branch-label"><?= e($primaryContactBranchLabel) ?></div>
                        <?php endif; ?>
                        <div class="receipt-sheet__branch"><?= e((string) (($branchBranding['receipt_name'] ?? '') !== '' ? $branchBranding['receipt_name'] : ($branchBranding['name'] ?? 'Travel Agency Branch'))) ?></div>
                        <div class="receipt-sheet__meta"><?= e($primaryContactLocationLabel) ?></div>
                        <?php if ($primaryContactPerson !== ''): ?>
                            <div class="receipt-sheet__meta receipt-sheet__meta--person"><?= e($primaryContactPerson) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="receipt-sheet__contact-stack">
                    <?php if ($primaryContactPhones !== []): ?>
                        <div class="receipt-sheet__contact-inline receipt-sheet__contact-inline--primary">
                            <span class="receipt-sheet__contact-icon receipt-sheet__contact-icon--phone"><?= $receiptIcon((string) ($primaryContactPhones[0]['type'] ?? 'phone')) ?></span>
                            <span><?= e((string) ($primaryContactPhones[0]['value'] ?? '')) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (array_slice($primaryContactPhones, 1) !== []): ?>
                        <div class="receipt-sheet__contact-grid<?= $branchCode === 'dubai' ? ' receipt-sheet__contact-grid--three-up' : '' ?>">
                            <?php foreach (array_slice($primaryContactPhones, 1) as $phoneLine): ?>
                                <div class="receipt-sheet__contact-card">
                                    <div class="receipt-sheet__contact-card-main">
                                        <span class="receipt-sheet__contact-card-icons">
                                            <span class="receipt-sheet__contact-icon receipt-sheet__contact-icon--mobile"><?= $receiptIcon((string) ($phoneLine['type'] ?? 'mobile')) ?></span>
                                            <?php if (! empty($phoneLine['whatsapp'])): ?>
                                                <span class="receipt-sheet__contact-icon receipt-sheet__contact-icon--whatsapp"><?= $receiptIcon('whatsapp') ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <div class="receipt-sheet__contact-card-copy">
                                            <strong><?= e((string) ($phoneLine['name'] ?? 'Contact')) ?></strong>
                                            <span class="receipt-sheet__contact-role"><?= e((string) ($phoneLine['role'] ?? '')) ?></span>
                                            <span class="receipt-sheet__contact-number"><?= e((string) ($phoneLine['value'] ?? '')) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="receipt-sheet__title-wrap">
                <div class="receipt-sheet__title">Customer Advance Receipt</div>
                <div class="receipt-sheet__subtitle">Official branch receipt generated for customer advance confirmation.</div>
                <?php if ($primaryContactServiceNote !== ''): ?>
                    <div class="receipt-sheet__service-note"><?= e($primaryContactServiceNote) ?></div>
                <?php endif; ?>
                <?php if ($generatedAt !== ''): ?>
                    <div class="receipt-contact-panel receipt-contact-panel--generated-only">
                        <div class="receipt-contact-panel__row">
                            <strong class="receipt-contact-panel__label">Generated</strong>
                            <em class="receipt-contact-panel__value"><?= e($generatedAt) ?></em>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($primaryContactAddress !== '' || $primaryContactLicense !== '' || $primaryContactEmail !== ''): ?>
                    <div class="receipt-sheet__info-lines receipt-sheet__info-lines--right">
                        <?php if ($primaryContactAddress !== '' || $primaryContactLicense !== ''): ?>
                            <div class="receipt-sheet__meta receipt-sheet__meta-row">
                                <?php if ($primaryContactAddress !== ''): ?>
                                    <span class="receipt-sheet__meta-row-item receipt-sheet__address"><?= e($primaryContactAddress) ?></span>
                                <?php endif; ?>
                                <?php if ($primaryContactLicense !== ''): ?>
                                    <span class="receipt-sheet__meta-row-item">Licence No. <?= e($primaryContactLicense) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($primaryContactEmail !== ''): ?>
                            <div class="receipt-sheet__contact-inline receipt-sheet__contact-inline--email">
                                <span class="receipt-sheet__contact-icon receipt-sheet__contact-icon--email"><?= $receiptIcon('email') ?></span>
                                <span><?= e($primaryContactEmail) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <section class="receipt-card">
            <div class="receipt-card__grid receipt-card__grid--inline">
                <div><span>Receipt No.</span><strong><?= e((string) ($receipt['receipt_no'] ?? '')) ?></strong></div>
                <div><span>Receipt Date</span><strong><?= e((string) ($receipt['receipt_date'] ?? '')) ?></strong></div>
                <div><span>Customer Name</span><strong><?= e($customerName !== '' ? $customerName : 'Customer') ?></strong></div>
                <div><span>Branch</span><strong><?= e((string) ($branchBranding['receipt_name'] ?? $branchName ?? '')) ?></strong></div>
                <div><span>Payment Method</span><strong><?= e($paymentMethod !== '' ? $paymentMethod : 'N/A') ?></strong></div>
                <div><span>Deposit Account</span><strong><?= e(trim((string) ($receipt['treasury_account_name'] ?? '')) !== '' ? (string) ($receipt['treasury_account_name'] ?? '') : 'N/A') ?></strong></div>
            </div>
        </section>

        <section class="receipt-card receipt-card--highlight">
            <table class="output-table receipt-table">
                <thead>
                <tr>
                    <th>Customer</th>
                    <th>Currency</th>
                    <th>Advance Amount</th>
                    <th>Reference No.</th>
                    <th>Bank / Card Detail</th>
                    <th>Charges</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td><?= e($customerName !== '' ? $customerName : 'Customer') ?></td>
                    <td><?= e($receiptCurrency) ?></td>
                    <td><?= e($receiptCurrency) ?> <?= e($formatMoney($receiptAmount)) ?></td>
                    <td><?= e($referenceNumber !== '' ? $referenceNumber : 'N/A') ?></td>
                    <td><?= e($bankCardDetail !== '' ? $bankCardDetail : 'N/A') ?></td>
                    <td><?= e($receiptCurrency) ?> <?= e($formatMoney($receiptCharges)) ?></td>
                </tr>
                </tbody>
            </table>
            <div class="receipt-service-totals">
                <div class="receipt-service-totals__row receipt-service-totals__row--grand">
                    <span>Total Advance Received</span>
                    <strong><?= e($receiptCurrency) ?> <?= e($formatMoney($receiptAmount)) ?></strong>
                </div>
            </div>
        </section>

        <?php if ($remarks !== ''): ?>
            <section class="receipt-card">
                <div class="receipt-due-line">
                    <span>Remarks</span>
                    <strong><?= e($remarks) ?></strong>
                </div>
            </section>
        <?php endif; ?>

        <footer class="receipt-sheet__foot">
            <div class="receipt-signatures">
                <div>
                    <span>Received By</span>
                    <strong>Authorized Staff</strong>
                </div>
                <div>
                    <span>Authorized By</span>
                    <strong><?= e((string) ($branchBranding['name'] ?? 'Travel Agency')) ?></strong>
                </div>
            </div>
            <div class="receipt-sheet__note">Thank you for your business.</div>
            <div class="receipt-sheet__note receipt-sheet__note--muted">This is a computer-generated receipt.</div>
            <?php if ($branchDirectory !== []): ?>
                <div class="receipt-branches">
                    <span>Our branches</span>
                    <?php foreach ($branchDirectory as $branchLine): ?>
                        <div class="receipt-branches__row">
                            <strong class="receipt-branches__name"><?= e((string) ($branchLine['branch'] ?? 'Branch')) ?></strong>
                            <?php
                            $branchLineMeta = array_values(array_filter([
                                trim((string) ($branchLine['location'] ?? '')),
                                trim((string) ($branchLine['contact'] ?? '')),
                            ]));
                            ?>
                            <?php if ($branchLineMeta !== []): ?>
                                <small class="receipt-branches__contact"><?= e(implode(' | ', $branchLineMeta)) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="receipt-developer-credit">Developed by CoreLogic IT Solutions - +92-3462331012</div>
        </footer>
    </section>
</main>
