<?php

$booking = is_array($booking ?? null) ? $booking : [];
$travelers = is_array($travelers ?? null) ? $travelers : [];
$services = is_array($services ?? null) ? $services : [];
$serviceEvents = is_array($serviceEvents ?? null) ? $serviceEvents : [];
$customerPaymentFoundation = is_array($customerPaymentFoundation ?? null) ? $customerPaymentFoundation : [];
$supplierFoundation = is_array($supplierFoundation ?? null) ? $supplierFoundation : [];
$selectedReceipt = is_array($selectedReceipt ?? null) ? $selectedReceipt : null;
$selectedSupplierPayment = is_array($selectedSupplierPayment ?? null) ? $selectedSupplierPayment : null;
$selectedRefundEvent = is_array($selectedRefundEvent ?? null) ? $selectedRefundEvent : null;
$selectedRefundDetail = is_array($selectedRefundDetail ?? null) ? $selectedRefundDetail : null;
$branchBranding = is_array($branchBranding ?? null) ? $branchBranding : [];
$branchDirectory = is_array($branchDirectory ?? null) ? $branchDirectory : [];
$summary = is_array($summary ?? null) ? $summary : [];
$outputType = (string) ($outputType ?? 'invoice');
$outputTypeLabel = (string) ($outputTypeLabel ?? 'Operational Output');
$generatedAt = (string) ($generatedAt ?? '');
$backUrl = (string) ($backUrl ?? url('/workspace'));
$showOutputDebug = app_debug_tools_enabled() && (string) ($_GET['debug_ui'] ?? '') === '1';

$formatMoney = static fn (float $amount): string => number_format($amount, 2);
$formatCurrencyTotals = static function (array $totals) use ($formatMoney): string {
    if ($totals === []) {
        return 'PKR 0.00';
    }

    $parts = [];
    foreach ($totals as $currency => $amount) {
        $parts[] = trim((string) $currency) . ' ' . $formatMoney((float) $amount);
    }

    return implode(' / ', $parts);
};
$serviceTaxTotalAmount = static function (array $service): float {
    return (float) ($service['spyi_amount'] ?? 0)
        + (float) ($service['aq_yr_pk_amount'] ?? 0)
        + (float) ($service['yq_amount'] ?? 0)
        + (float) ($service['oth_amount'] ?? 0)
        + (float) ($service['vat_input'] ?? 0)
        + (float) ($service['taxes'] ?? 0);
};
$serviceConvertedPayableAmount = static function (array $service) use ($serviceTaxTotalAmount): float {
    $invoiceCurrency = trim((string) ($service['currency'] ?? 'PKR')) ?: 'PKR';
    $costCurrency = trim((string) ($service['cost_currency'] ?? $invoiceCurrency)) ?: $invoiceCurrency;
    $pricingExchangeRate = (float) ($service['pricing_exchange_rate'] ?? 1);
    $isAirTicket = (string) ($service['service_type'] ?? 'air ticket') === 'air ticket';
    $payableAmount = $isAirTicket
        ? (float) ($service['purchase_cost'] ?? 0)
        : ((float) ($service['purchase_cost'] ?? 0) > 0.005 ? (float) ($service['purchase_cost'] ?? 0) : (float) ($service['sale_price'] ?? 0));

    return round($payableAmount * ($invoiceCurrency === $costCurrency ? 1 : max($pricingExchangeRate, 0)), 2);
};
$serviceReceivableAmount = static function (array $service) use ($serviceTaxTotalAmount): float {
    $savedFinalSale = (float) ($service['final_sale_price'] ?? 0);
    if (abs($savedFinalSale) > 0.005) {
        return $savedFinalSale;
    }

    $invoiceCurrency = trim((string) ($service['currency'] ?? 'PKR')) ?: 'PKR';
    $costCurrency = trim((string) ($service['cost_currency'] ?? $invoiceCurrency)) ?: $invoiceCurrency;
    $pricingExchangeRate = (float) ($service['pricing_exchange_rate'] ?? 1);
    $isAirTicket = (string) ($service['service_type'] ?? 'air ticket') === 'air ticket';

    if ($isAirTicket) {
        $costCurrencyReceivable = round(
            (float) ($service['purchase_cost'] ?? 0)
            + (float) ($service['service_charge'] ?? 0)
            + (float) ($service['vat'] ?? 0)
            - (float) ($service['discount_amount'] ?? 0),
            2
        );

        return round($costCurrencyReceivable * ($invoiceCurrency === $costCurrency ? 1 : max($pricingExchangeRate, 0)), 2);
    }

    return round(
        (float) ($service['sale_price'] ?? 0)
        + (float) ($service['service_charge'] ?? 0)
        + (float) ($service['vat'] ?? 0)
        - (float) ($service['discount_amount'] ?? 0),
        2
    );
};
$formatStatusLabel = static function (?string $status): string {
    $normalized = str_replace(' ', '_', mb_strtolower(trim((string) $status)));
    if ($normalized === 'void') {
        return 'VOID';
    }

    return ucwords(str_replace('_', ' ', $normalized));
};
$countryNameForCode = static function (string $countryCode): string {
    return match (strtoupper(trim($countryCode))) {
        'PK' => 'Pakistan',
        'AE' => 'UAE',
        default => strtoupper(trim($countryCode)),
    };
};
$formatBranchDirectoryLine = static function (array $branch) use ($countryNameForCode): string {
    $place = trim((string) (($branch['city'] ?? '') . ((string) ($branch['country_code'] ?? '') !== '' ? ', ' . $countryNameForCode((string) $branch['country_code']) : '')));
    $branchName = trim((string) ($branch['receipt_name'] ?? ''));
    if ($branchName === '') {
        $branchName = (string) ($branch['name'] ?? 'Branch');
    }

    return trim($branchName . ($place !== '' ? ' - ' . $place : ''));
};
$contactPhoneLines = static function (array $contact): array {
    $lines = [];
    $landline = trim((string) ($contact['landline'] ?? ''));
    if ($landline !== '') {
        $lines[] = [
            'label' => trim((string) ($contact['landline_label'] ?? 'Phone')) ?: 'Phone',
            'name' => trim((string) ($contact['landline_label'] ?? 'Phone')) ?: 'Phone',
            'role' => '',
            'value' => $landline,
            'type' => 'phone',
            'whatsapp' => false,
        ];
    }

    foreach (($contact['contacts'] ?? []) as $row) {
        if (! is_array($row)) {
            continue;
        }

        $phone = trim((string) ($row['phone'] ?? ''));
        if ($phone === '') {
            continue;
        }

        $name = trim((string) ($row['name'] ?? ''));
        $role = trim((string) ($row['role'] ?? ''));
        $label = trim($name . ($role !== '' && $role !== $name ? ' - ' . $role : ''));
        $lines[] = [
            'label' => $label !== '' ? $label : 'Contact',
            'name' => $name !== '' ? $name : 'Contact',
            'role' => $role,
            'value' => $phone,
            'type' => 'mobile',
            'whatsapp' => true,
        ];
    }

    return $lines;
};
$receiptIcon = static function (string $name): string {
    return match ($name) {
        'phone' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.62 10.79a15.47 15.47 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1-.24c1.12.37 2.32.56 3.59.56a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.3 21 3 13.7 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.27.19 2.47.56 3.59a1 1 0 0 1-.24 1.01l-2.2 2.19Z"/></svg>',
        'mobile' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 3v12h10V5H7Zm5 15a1.25 1.25 0 1 0 0-2.5A1.25 1.25 0 0 0 12 20Z"/></svg>',
        'whatsapp' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.5 2 2 6.4 2 11.83c0 1.74.46 3.37 1.33 4.8L2 22l5.56-1.46a10.18 10.18 0 0 0 4.48 1.03c5.53 0 10.03-4.4 10.03-9.82C22.07 6.4 17.57 2 12.04 2Zm0 17.92a8.5 8.5 0 0 1-4.33-1.18l-.31-.18-3.3.87.88-3.2-.2-.32a8.23 8.23 0 0 1-1.28-4.38c0-4.53 3.82-8.22 8.54-8.22 4.7 0 8.53 3.69 8.53 8.22s-3.83 8.39-8.53 8.39Zm4.68-6.28c-.25-.12-1.48-.72-1.71-.8-.23-.08-.4-.12-.57.12-.17.24-.65.8-.8.96-.15.16-.29.18-.54.06-.25-.12-1.05-.38-2-1.22-.74-.66-1.24-1.47-1.39-1.72-.15-.24-.02-.38.1-.5.11-.11.25-.28.38-.42.12-.14.17-.24.25-.4.08-.16.04-.3-.02-.42-.06-.12-.57-1.36-.78-1.86-.21-.5-.42-.43-.57-.44h-.49c-.17 0-.44.06-.67.3s-.88.86-.88 2.1.9 2.45 1.02 2.62c.13.16 1.77 2.8 4.38 3.81 2.61 1.01 2.61.67 3.08.63.48-.04 1.48-.6 1.69-1.18.21-.58.21-1.08.15-1.18-.06-.1-.23-.16-.48-.28Z"/></svg>',
        'email' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm0 2v.01L12 12l8-4.99V7H4Zm16 10V9.3l-7.47 4.66a1 1 0 0 1-1.06 0L4 9.3V17h16Z"/></svg>',
        default => '',
    };
};
$normalizeReceiptLogoAssetPath = static function (string $path): string {
    $normalized = '/' . ltrim(trim($path), '/');

    if (str_starts_with($normalized, '/public/')) {
        return substr($normalized, 8);
    }

    return $normalized;
};
$normalizeReceiptLogoPublicPath = static function (string $path): string {
    $normalized = '/' . ltrim(trim($path), '/');

    return str_starts_with($normalized, '/public/')
        ? $normalized
        : '/public' . $normalized;
};
$primaryBranchContact = is_array($branchBranding['contact'] ?? null) ? $branchBranding['contact'] : [];
$primaryContactPhones = $contactPhoneLines($primaryBranchContact);
$primaryContactLogo = trim((string) ($primaryBranchContact['logo_path'] ?? ''));
$primaryContactLogoSrc = $primaryContactLogo !== '' ? asset(ltrim($normalizeReceiptLogoAssetPath($primaryContactLogo), '/')) : '';
$primaryContactLogoFallbackSrc = $primaryContactLogo !== '' ? url($normalizeReceiptLogoPublicPath($primaryContactLogo)) : '';
$primaryBranchCode = mb_strtolower(trim((string) ($branchBranding['code'] ?? '')));
$primaryBranchIdentity = mb_strtolower(trim(implode(' ', [
    (string) ($branchBranding['receipt_name'] ?? ''),
    (string) ($branchBranding['name'] ?? ''),
])));
$isNobleRouteReceipt = $primaryBranchCode === 'dubai'
    || str_contains($primaryBranchIdentity, 'noble route');
$nobleRouteSignatureSrc = '';
if ($isNobleRouteReceipt) {
    $nobleRouteSignaturePath = base_path('public/assets/images/receipt-branches/noble-route-signature.png');
    $nobleRouteSignatureBytes = is_readable($nobleRouteSignaturePath)
        ? file_get_contents($nobleRouteSignaturePath)
        : false;
    $nobleRouteSignatureSrc = is_string($nobleRouteSignatureBytes)
        ? 'data:image/png;base64,' . base64_encode($nobleRouteSignatureBytes)
        : asset('images/receipt-branches/noble-route-signature.png') . '?v=20260727-2';
}
$primaryContactBranchLabel = trim((string) ($primaryBranchContact['branch_label'] ?? ''));
$primaryContactLocationLabel = trim((string) ($primaryBranchContact['location_label'] ?? ''));
$primaryContactPerson = trim((string) ($primaryBranchContact['contact_person'] ?? ''));
$primaryContactEmail = trim((string) ($primaryBranchContact['email'] ?? ''));
$primaryContactAddress = trim((string) ($primaryBranchContact['address'] ?? ''));
$primaryContactLicense = trim((string) ($primaryBranchContact['license'] ?? ''));
$primaryContactServiceNote = trim((string) ($primaryBranchContact['service_note'] ?? ''));
$branchDirectoryContactLine = static function (array $branch) use ($formatBranchDirectoryLine, $contactPhoneLines): array {
    $contact = is_array($branch['contact'] ?? null) ? $branch['contact'] : [];
    $phones = array_map(static fn (array $row): string => $row['value'], $contactPhoneLines($contact));
    $email = trim((string) ($contact['email'] ?? ''));

    return [
        'branch' => $formatBranchDirectoryLine($branch),
        'contact' => implode(' | ', array_filter([
            $phones !== [] ? implode(', ', array_slice($phones, 0, 3)) : '',
            $email,
        ], static fn (string $part): bool => trim($part) !== '')),
    ];
};
$leadTravelerName = trim((string) ($booking['lead_traveler_name'] ?? ''));
$customerName = $leadTravelerName !== '' ? $leadTravelerName : trim((string) ($booking['party_label'] ?? 'Booking Party'));
$bookingReference = (string) ($booking['booking_reference'] ?? '');
$bookingDate = (string) ($booking['booking_date'] ?? '');
$bookingDueDate = trim((string) ($booking['due_date'] ?? ''));
$customerMobile = trim((string) ($booking['contact_mobile'] ?? ''));
$customerPassport = trim((string) ($booking['passport_number'] ?? ''));
$servicePassengerKeys = [];
foreach ($services as $servicePassengerRow) {
    $serviceTravelerId = (int) ($servicePassengerRow['traveler_id'] ?? 0);
    $servicePassengerName = mb_strtolower(trim((string) ($servicePassengerRow['passenger_name'] ?? $servicePassengerRow['passenger_name_snapshot'] ?? '')));
    if ($serviceTravelerId > 0) {
        $servicePassengerKeys['id:' . $serviceTravelerId] = true;
    } elseif ($servicePassengerName !== '') {
        $servicePassengerKeys['name:' . $servicePassengerName] = true;
    }
}
$travelerCount = count($servicePassengerKeys);
$serviceCount = count($services);
$serviceTypeLabels = [];
foreach ($services as $service) {
    $serviceType = ucwords((string) ($service['service_type'] ?? 'Service'));
    if ($serviceType !== '') {
        $serviceTypeLabels[$serviceType] = true;
    }
}
$serviceSummary = 'General Services';
if ($serviceTypeLabels !== []) {
    $serviceNames = array_keys($serviceTypeLabels);
    $serviceSummary = count($serviceNames) === 1 ? $serviceNames[0] : 'Mixed Services';
}
$receiptRemarks = trim((string) ($selectedReceipt['remarks'] ?? ''));
$receiptReference = trim((string) ($selectedReceipt['referenceNumber'] ?? ''));
$receiptBankCard = trim((string) ($selectedReceipt['bankCardDetail'] ?? ''));
$selectedReceiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($selectedReceipt['statusRaw'] ?? $selectedReceipt['status'] ?? ''))));
$selectedReceiptIsVoid = $selectedReceiptStatusRaw === 'void';
$selectedReceiptVoidReason = trim((string) ($selectedReceipt['voidReason'] ?? ''));
$selectedReceiptVoidedAt = trim((string) ($selectedReceipt['voidedAt'] ?? ''));
$receiptAgainst = trim($receiptRemarks !== '' ? $receiptRemarks : $serviceSummary);
$receivedBy = trim((string) ($booking['updated_by_name'] ?? $booking['created_by_name'] ?? 'Authorized Staff'));
$passportNumber = trim((string) ($booking['passport_number'] ?? ''));
if ($passportNumber === '') {
    foreach ($travelers as $traveler) {
        $travelerPassport = trim((string) ($traveler['passport_number'] ?? ''));
        if ($travelerPassport !== '') {
            $passportNumber = $travelerPassport;
            break;
        }
    }
}
$invoiceReceivableTotals = is_array($customerPaymentFoundation['summary']['invoiceReceivable'] ?? null)
    ? $customerPaymentFoundation['summary']['invoiceReceivable']
    : [];
$invoiceOutstandingTotals = is_array($customerPaymentFoundation['summary']['invoiceOutstanding'] ?? null)
    ? $customerPaymentFoundation['summary']['invoiceOutstanding']
    : [];
$invoiceReceivedTotals = is_array($customerPaymentFoundation['summary']['invoiceReceived'] ?? null)
    ? $customerPaymentFoundation['summary']['invoiceReceived']
    : [];
$customerPreviousBalanceTotals = is_array($customerPaymentFoundation['summary']['previousBalance'] ?? null)
    ? $customerPaymentFoundation['summary']['previousBalance']
    : [];
$customerCreditTotals = is_array($customerPaymentFoundation['summary']['customerCredit'] ?? null)
    ? $customerPaymentFoundation['summary']['customerCredit']
    : [];
$supplierOutstandingTotals = is_array($summary['supplierOutstanding'] ?? null) ? $summary['supplierOutstanding'] : [];
$supplierPaidTotals = is_array($summary['supplierPaid'] ?? null) ? $summary['supplierPaid'] : [];
$selectedSupplierPaymentStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($selectedSupplierPayment['statusRaw'] ?? $selectedSupplierPayment['status'] ?? ''))));
$selectedSupplierPaymentIsVoid = $selectedSupplierPaymentStatusRaw === 'void';
$selectedSupplierPaymentVoidReason = trim((string) ($selectedSupplierPayment['voidReason'] ?? ''));
$selectedSupplierPaymentVoidedAt = trim((string) ($selectedSupplierPayment['voidedAt'] ?? ''));
$invoiceServiceSummary = $serviceSummary;
$documentAudienceNote = match ($outputType) {
    'invoice' => 'Customer-facing invoice generated from the current saved booking services.',
    'account_statement' => 'Customer account statement for this booking and its saved receipts.',
    'itinerary' => 'Travel service summary for passenger and booking reference use.',
    'booking_confirmation' => 'Booking confirmation generated from the current saved booking record.',
    'service_refund_receipt' => 'Cancellation and refund receipt.',
    'supplier_voucher' => 'Supplier-facing or finance-facing payment voucher for the selected supplier payment.',
    default => 'Operational output generated from the current saved booking record.',
};

$cleanServiceDescription = static function (array $service): string {
    $sector = trim((string) (($service['sector_from'] ?? '') . (((string) ($service['sector_to'] ?? '') !== '') ? '/' . (string) ($service['sector_to'] ?? '') : '')));
    if ($sector !== '') {
        return $sector;
    }

    $remarks = trim((string) ($service['remarks'] ?? ''));
    $ticketRemarks = trim((string) ($service['ticket_remarks'] ?? ''));
    $rawDescription = $ticketRemarks !== '' ? $ticketRemarks : $remarks;
    $normalizedRaw = mb_strtolower($rawDescription);
    $blockedSnippets = [
        'enter first service here',
        'invoice will be saved with it',
        'save invoice first',
        'create the first persisted service line',
        'persisted service line',
        'workspace',
    ];
    foreach ($blockedSnippets as $blockedSnippet) {
        if ($normalizedRaw !== '' && str_contains($normalizedRaw, $blockedSnippet)) {
            $rawDescription = '';
            break;
        }
    }

    if ($rawDescription !== '') {
        return $rawDescription;
    }

    return match (mb_strtolower((string) ($service['service_type'] ?? ''))) {
        'air ticket' => 'Air Ticket Service',
        'visa' => 'Visa Service',
        'hotel' => 'Hotel Service',
        'umrah' => 'Umrah Service',
        'transport' => 'Transport Service',
        'tour', 'tourism' => 'Tour Service',
        default => 'Travel Service',
    };
};
$extractServiceRoute = static function (array $service): string {
    $sectorFrom = trim((string) ($service['sector_from'] ?? ''));
    $sectorTo = trim((string) ($service['sector_to'] ?? ''));
    $sectorRoute = trim((string) implode('/', array_values(array_filter([$sectorFrom, $sectorTo], static fn ($value) => $value !== ''))));
    if ($sectorRoute !== '') {
        return $sectorRoute;
    }

    $routeCandidates = [
        trim((string) ($service['transport_route_notes'] ?? '')),
        trim((string) ($service['tour_destination'] ?? '')),
        trim((string) ($service['hotel_city'] ?? '')),
        trim((string) ($service['visa_country'] ?? '')),
        trim((string) ($service['umrah_transport_notes'] ?? '')),
    ];

    foreach ($routeCandidates as $routeCandidate) {
        if ($routeCandidate !== '') {
            return $routeCandidate;
        }
    }

    return '';
};
$extractServiceReference = static function (array $service): string {
    $serviceType = mb_strtolower(trim((string) ($service['service_type'] ?? '')));
    $referenceFields = match ($serviceType) {
        'air ticket' => ['pnr'],
        'umrah' => ['umrah_mofa_reference'],
        'tour', 'tourism' => ['tour_confirmation_number'],
        'visa' => ['visa_application_reference'],
        'hotel' => ['hotel_confirmation_number'],
        'transport' => ['transport_driver_detail'],
        default => ['other_reference_number', 'pnr', 'ticket_number', 'line_reference'],
    };

    foreach ($referenceFields as $referenceField) {
        $reference = trim((string) ($service[$referenceField] ?? ''));
        if ($reference !== '') {
            return $reference;
        }
    }

    return 'N/A';
};
$extractServiceReferenceLabel = static function (array $service): string {
    return match (mb_strtolower(trim((string) ($service['service_type'] ?? '')))) {
        'air ticket' => 'PNR',
        'umrah' => 'Umrah Ref.',
        'tour', 'tourism' => 'Tourism Ref.',
        'visa' => 'Visa Ref.',
        'hotel' => 'Hotel Ref.',
        'transport' => 'Transport Ref.',
        default => 'Reference',
    };
};
$receiptCurrency = (string) ($selectedReceipt['currency'] ?? 'PKR');
$receiptInvoiceCurrency = (string) (
    array_key_first($invoiceReceivableTotals)
    ?? array_key_first($invoiceOutstandingTotals)
    ?? array_key_first($serviceInvoiceCandidateTotals ?? [])
    ?? $receiptCurrency
);
$receiptDisplayZeroTotals = [$receiptInvoiceCurrency => 0.0];
$totalInvoiceAmount = (float) ($invoiceReceivableTotals[$receiptInvoiceCurrency] ?? 0);
$receiptCurrencyReceivedTotal = (float) ($invoiceReceivedTotals[$receiptInvoiceCurrency] ?? 0);
$serviceCurrencyFallbackTotal = 0.0;
if ($totalInvoiceAmount <= 0) {
    foreach ($services as $service) {
        if ((string) ($service['currency'] ?? '') === $receiptInvoiceCurrency) {
            $serviceCurrencyFallbackTotal += $serviceReceivableAmount($service);
        }
    }
    $totalInvoiceAmount = $serviceCurrencyFallbackTotal;
}

$balanceDue = max($totalInvoiceAmount - $receiptCurrencyReceivedTotal, 0);
$dueDateForReceipt = '';
if ($balanceDue > 0) {
    foreach (($customerPaymentFoundation['openReceivables'] ?? []) as $openReceivableRow) {
        if ((string) ($openReceivableRow['currency'] ?? '') === $receiptInvoiceCurrency && (float) ($openReceivableRow['outstandingAmount'] ?? 0) > 0 && ! empty($openReceivableRow['nextDueDate'])) {
            $dueDateForReceipt = (string) $openReceivableRow['nextDueDate'];
            break;
        }
    }
}
$receiptDebug = [
    'receiptCurrency' => $receiptCurrency,
    'receiptInvoiceCurrency' => $receiptInvoiceCurrency,
    'invoiceReceivableTotals' => $invoiceReceivableTotals,
    'invoiceOutstandingTotals' => $invoiceOutstandingTotals,
    'invoiceReceivedTotals' => $invoiceReceivedTotals,
    'serviceCurrencyFallbackTotal' => $serviceCurrencyFallbackTotal,
    'receiptCurrencyReceivedTotal' => $receiptCurrencyReceivedTotal,
    'serviceRowsCount' => count($services),
    'services' => array_map(
        static fn (array $service): array => [
            'currency' => (string) ($service['currency'] ?? ''),
            'sale_price' => (float) ($service['sale_price'] ?? 0),
            'service_charge' => (float) ($service['service_charge'] ?? 0),
            'discount_amount' => (float) ($service['discount_amount'] ?? 0),
            'final_sale_price' => (float) ($service['final_sale_price'] ?? 0),
            'line_reference' => (string) ($service['line_reference'] ?? ''),
        ],
        $services
    ),
    'openReceivables' => array_map(
        static fn (array $row): array => [
            'currency' => (string) ($row['currency'] ?? ''),
            'dueAmount' => (float) ($row['dueAmount'] ?? 0),
            'outstandingAmount' => (float) ($row['outstandingAmount'] ?? 0),
            'nextDueDate' => (string) ($row['nextDueDate'] ?? ''),
            'serviceLineReference' => (string) ($row['serviceLineReference'] ?? ''),
        ],
        $customerPaymentFoundation['openReceivables'] ?? []
    ),
];
$statementInvoiceCurrencies = [];
foreach (array_keys($invoiceReceivableTotals) as $currency) {
    $statementInvoiceCurrencies[(string) $currency] = true;
}
foreach (array_keys($invoiceOutstandingTotals) as $currency) {
    $statementInvoiceCurrencies[(string) $currency] = true;
}
if ($statementInvoiceCurrencies === []) {
    foreach ($services as $service) {
        $serviceCurrency = trim((string) ($service['currency'] ?? ''));
        if ($serviceCurrency !== '') {
            $statementInvoiceCurrencies[$serviceCurrency] = true;
        }
    }
}
$statementSameCurrencyPreviousTotals = [];
$statementOtherCurrencyPreviousTotals = [];
foreach ($customerPreviousBalanceTotals as $currency => $amount) {
    if (isset($statementInvoiceCurrencies[(string) $currency])) {
        $statementSameCurrencyPreviousTotals[(string) $currency] = (float) $amount;
    } elseif (abs((float) $amount) > 0.005) {
        $statementOtherCurrencyPreviousTotals[(string) $currency] = (float) $amount;
    }
}
$statementTotalOutstandingTotals = $invoiceOutstandingTotals;
foreach ($statementSameCurrencyPreviousTotals as $currency => $amount) {
    $statementTotalOutstandingTotals[(string) $currency] = (float) ($statementTotalOutstandingTotals[(string) $currency] ?? 0) + (float) $amount;
}
$statementDisplayCurrency = $statementInvoiceCurrencies !== []
    ? (string) array_key_first($statementInvoiceCurrencies)
    : ((string) array_key_first($customerPreviousBalanceTotals) !== '' ? (string) array_key_first($customerPreviousBalanceTotals) : 'PKR');
$statementPreviousBalanceDisplay = $statementSameCurrencyPreviousTotals !== []
    ? $formatCurrencyTotals($statementSameCurrencyPreviousTotals)
    : ($statementDisplayCurrency . ' ' . $formatMoney(0));
$nonZeroCurrencyTotals = static function (array $totals): array {
    $filtered = [];
    foreach ($totals as $currency => $amount) {
        if (abs((float) $amount) > 0.005) {
            $filtered[(string) $currency] = round((float) $amount, 2);
        }
    }

    return $filtered;
};
$statementServiceReceivableByLineCurrency = [];
foreach ($services as $statementServiceRow) {
    $statementServiceLine = trim((string) ($statementServiceRow['line_reference'] ?? ''));
    $statementServiceCurrency = trim((string) ($statementServiceRow['currency'] ?? ''));
    if ($statementServiceLine === '' || $statementServiceCurrency === '') {
        continue;
    }

    $statementServiceReceivableByLineCurrency[$statementServiceLine . '|' . $statementServiceCurrency] =
        round($serviceReceivableAmount($statementServiceRow), 2);
}
$statementInternalAdjustmentReceiptIds = [];
$statementInternalAdjustmentReceiptLabels = [];
foreach ($serviceEvents as $statementServiceEvent) {
    $statementEventType = str_replace(' ', '_', mb_strtolower(trim((string) ($statementServiceEvent['event_type'] ?? $statementServiceEvent['eventType'] ?? ''))));
    $statementEventStatus = str_replace(' ', '_', mb_strtolower(trim((string) ($statementServiceEvent['event_status'] ?? $statementServiceEvent['eventStatus'] ?? ''))));
    if ($statementEventType !== 'cancel' || $statementEventStatus !== 'posted') {
        continue;
    }

    $statementEventCurrency = trim((string) ($statementServiceEvent['currency'] ?? ''));
    if ($statementEventCurrency === '') {
        continue;
    }

    $statementEventPayload = [];
    $statementEventPayloadJson = (string) ($statementServiceEvent['payload_json'] ?? $statementServiceEvent['payloadJson'] ?? '');
    if ($statementEventPayloadJson !== '') {
        $decodedStatementEventPayload = json_decode($statementEventPayloadJson, true);
        if (is_array($decodedStatementEventPayload)) {
            $statementEventPayload = $decodedStatementEventPayload;
        }
    }

    $statementCustomerPenalty = round((float) (
        $statementEventPayload['customer_penalty_amount']
        ?? $statementEventPayload['customer_penalty']
        ?? $statementServiceEvent['penalty_amount']
        ?? $statementServiceEvent['penaltyAmount']
        ?? 0
    ), 2);
    $statementEventLine = trim((string) ($statementServiceEvent['service_line_reference'] ?? $statementServiceEvent['serviceLineReference'] ?? ''));
    $statementEventBaseAmount = $statementEventLine !== ''
        ? (float) ($statementServiceReceivableByLineCurrency[$statementEventLine . '|' . $statementEventCurrency] ?? 0)
        : 0.0;
    if ($statementEventBaseAmount <= 0.005) {
        $statementEventBaseAmount = (float) ($invoiceReceivableTotals[$statementEventCurrency] ?? 0);
    }

    $statementExpectedAdjustment = round(max($statementEventBaseAmount - $statementCustomerPenalty, 0), 2);
    if ($statementExpectedAdjustment <= 0.005) {
        continue;
    }

    $statementEventDate = trim((string) ($statementServiceEvent['event_date'] ?? $statementServiceEvent['eventDate'] ?? ''));
    foreach (($customerPaymentFoundation['receipts'] ?? []) as $statementReceiptCandidate) {
        $statementReceiptId = (int) ($statementReceiptCandidate['id'] ?? 0);
        if ($statementReceiptId <= 0 || isset($statementInternalAdjustmentReceiptIds[$statementReceiptId])) {
            continue;
        }

        $statementReceiptCurrency = trim((string) ($statementReceiptCandidate['currency'] ?? ''));
        $statementReceiptStatus = str_replace(' ', '_', mb_strtolower(trim((string) ($statementReceiptCandidate['statusRaw'] ?? $statementReceiptCandidate['status'] ?? ''))));
        $statementReceiptDate = trim((string) ($statementReceiptCandidate['receiptDate'] ?? ''));
        if ($statementReceiptCurrency !== $statementEventCurrency || $statementReceiptStatus === 'void') {
            continue;
        }
        if ($statementEventDate !== '' && $statementReceiptDate !== '' && strcmp($statementReceiptDate, $statementEventDate) < 0) {
            continue;
        }

        $statementReceiptTendered = round((float) ($statementReceiptCandidate['tenderedAmount'] ?? $statementReceiptCandidate['receivedAmount'] ?? 0), 2);
        $statementReceiptReturned = round((float) ($statementReceiptCandidate['returnedAmount'] ?? 0), 2);
        $statementReceiptAllocated = round((float) ($statementReceiptCandidate['allocatedAmount'] ?? 0), 2);
        if (
            abs($statementReceiptTendered - $statementExpectedAdjustment) <= 0.005
            && abs($statementReceiptAllocated - $statementExpectedAdjustment) <= 0.005
            && abs($statementReceiptReturned) <= 0.005
        ) {
            $statementInternalAdjustmentReceiptIds[$statementReceiptId] = true;
            $statementInternalAdjustmentReceiptLabels[$statementReceiptId] = 'Settlement Adjustment';
            break;
        }
    }
}
$remainingCustomerBalanceSource = is_array($customerPaymentFoundation['summary']['fullCustomerOutstanding'] ?? null)
    ? $customerPaymentFoundation['summary']['fullCustomerOutstanding']
    : [];
$remainingCustomerBalanceTotals = $nonZeroCurrencyTotals($remainingCustomerBalanceSource);
$statementPreviousBalanceTotals = $nonZeroCurrencyTotals($customerPreviousBalanceTotals);
$statementTenderedTotals = [];
$statementReturnedTotals = [];
foreach (($customerPaymentFoundation['receipts'] ?? []) as $statementReceiptRow) {
    $statementReceiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($statementReceiptRow['statusRaw'] ?? $statementReceiptRow['status'] ?? ''))));
    if ($statementReceiptStatusRaw === 'void') {
        continue;
    }

    $statementReceiptCurrency = trim((string) ($statementReceiptRow['currency'] ?? ''));
    if ($statementReceiptCurrency === '') {
        continue;
    }

    $statementReceiptId = (int) ($statementReceiptRow['id'] ?? 0);
    if ($statementReceiptId > 0 && isset($statementInternalAdjustmentReceiptIds[$statementReceiptId])) {
        continue;
    }

    $statementTenderedAmount = round((float) ($statementReceiptRow['tenderedAmount'] ?? $statementReceiptRow['receivedAmount'] ?? 0), 2);
    $statementReturnedAmount = round((float) ($statementReceiptRow['returnedAmount'] ?? 0), 2);
    $statementTenderedTotals[$statementReceiptCurrency] = ($statementTenderedTotals[$statementReceiptCurrency] ?? 0.0) + $statementTenderedAmount;
    $statementReturnedTotals[$statementReceiptCurrency] = ($statementReturnedTotals[$statementReceiptCurrency] ?? 0.0) + $statementReturnedAmount;
}
$statementTenderedTotals = $nonZeroCurrencyTotals($statementTenderedTotals);
$statementReturnedTotals = $nonZeroCurrencyTotals($statementReturnedTotals);
$selectedReceiptId = (int) ($selectedReceipt['id'] ?? 0);
$selectedReceiptNo = (string) ($selectedReceipt['receiptNo'] ?? '');
$selectedRefundPayload = [];
if ($selectedRefundEvent !== null && ! empty($selectedRefundEvent['payload_json'])) {
    $decodedPayload = json_decode((string) $selectedRefundEvent['payload_json'], true);
    if (is_array($decodedPayload)) {
        $selectedRefundPayload = $decodedPayload;
    }
}
$selectedRefundPaymentMethodRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($selectedRefundPayload['payment_method'] ?? 'cash'))));
$selectedRefundPaymentMethod = match ($selectedRefundPaymentMethodRaw) {
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'credit_card' => 'Credit Card',
    'debit_card' => 'Debit Card',
    'wallet', 'wallet_mobile', 'mobile_wallet' => 'Wallet',
    default => ucwords(str_replace('_', ' ', $selectedRefundPaymentMethodRaw !== '' ? $selectedRefundPaymentMethodRaw : 'cash')),
};
$selectedRefundSourceAccount = trim((string) (($selectedRefundPayload['refund_detail']['treasury_account_snapshot']['account_name'] ?? '') !== ''
    ? $selectedRefundPayload['refund_detail']['treasury_account_snapshot']['account_name']
    : ''));
$selectedRefundTransferReference = trim((string) ($selectedRefundDetail['transfer_reference'] ?? ($selectedRefundPayload['refund_detail']['transfer_reference'] ?? '')));
$selectedRefundCustomerBankName = trim((string) ($selectedRefundDetail['customer_bank_name'] ?? ($selectedRefundPayload['refund_detail']['customer_bank_name'] ?? '')));
$selectedRefundCustomerBankAccountTitle = trim((string) ($selectedRefundDetail['customer_bank_account_title'] ?? ($selectedRefundPayload['refund_detail']['customer_bank_account_title'] ?? '')));
$selectedRefundCustomerBankAccountNo = trim((string) ($selectedRefundDetail['customer_bank_account_no'] ?? ($selectedRefundPayload['refund_detail']['customer_bank_account_no'] ?? '')));
$selectedRefundCustomerBankIban = trim((string) ($selectedRefundDetail['customer_bank_iban'] ?? ($selectedRefundPayload['refund_detail']['customer_bank_iban'] ?? '')));
$selectedRefundCharges = (float) ($selectedRefundDetail['charges'] ?? ($selectedRefundPayload['refund_detail']['charges'] ?? 0));
$selectedRefundDestinationSummary = trim(implode(' | ', array_filter([
    $selectedRefundCustomerBankName,
    $selectedRefundCustomerBankAccountTitle,
    $selectedRefundCustomerBankAccountNo !== '' ? 'A/C ' . $selectedRefundCustomerBankAccountNo : '',
    $selectedRefundCustomerBankIban !== '' ? 'IBAN ' . $selectedRefundCustomerBankIban : '',
])));
$selectedRefundService = null;
$serviceById = [];
foreach ($services as $service) {
    $serviceId = (int) ($service['id'] ?? 0);
    if ($serviceId > 0) {
        $serviceById[$serviceId] = $service;
    }
}
if ($selectedRefundEvent !== null) {
    $refundServiceId = (int) ($selectedRefundEvent['booking_service_id'] ?? 0);
    $selectedRefundService = $serviceById[$refundServiceId] ?? null;
}
$reissueEvents = array_values(array_filter(
    $serviceEvents,
    static fn (array $event): bool => (string) ($event['event_type'] ?? '') === 'reissue'
        && (string) ($event['event_status'] ?? '') === 'posted'
));
$reissueAdjustmentTotals = [];
foreach ($reissueEvents as $event) {
    $currency = (string) ($event['currency'] ?? 'PKR');
    $fareDifference = (float) ($event['fare_difference_amount'] ?? 0);
    $serviceFee = (float) ($event['service_fee_amount'] ?? 0);
    $customerDelta = round($fareDifference + $serviceFee, 2);
    if (abs($customerDelta) <= 0.005 && ! empty($event['payload_json'])) {
        $payload = json_decode((string) $event['payload_json'], true);
        if (is_array($payload)) {
            $customerDelta = round((float) ($payload['customer_delta'] ?? 0), 2);
        }
    }

    if (abs($customerDelta) > 0.005) {
        $reissueAdjustmentTotals[$currency] = ($reissueAdjustmentTotals[$currency] ?? 0.0) + $customerDelta;
    }
}

$serviceInvoiceCandidateTotals = [];
foreach ($services as $service) {
    $serviceStatus = str_replace(' ', '_', mb_strtolower(trim((string) ($service['status'] ?? 'open'))));
    if ($serviceStatus === 'cancelled') {
        continue;
    }

    $currency = trim((string) ($service['currency'] ?? ''));
    if ($currency === '') {
        continue;
    }

    $serviceAmount = $serviceReceivableAmount($service);

    $serviceInvoiceCandidateTotals[$currency] = ($serviceInvoiceCandidateTotals[$currency] ?? 0.0) + $serviceAmount;
}

foreach ($serviceInvoiceCandidateTotals as $currency => $serviceTotal) {
    $reissueTotal = (float) ($reissueAdjustmentTotals[$currency] ?? 0);
    $reissueInclusiveTotal = round((float) $serviceTotal + $reissueTotal, 2);
    if ($reissueInclusiveTotal > (float) ($invoiceReceivableTotals[$currency] ?? 0) + 0.005) {
        $invoiceReceivableTotals[$currency] = $reissueInclusiveTotal;
    }
}

foreach ($invoiceReceivableTotals as $currency => $amount) {
    $invoiceReceivableTotals[$currency] = round((float) $amount, 2);
    $paidAmount = (float) ($invoiceReceivedTotals[$currency] ?? 0);
    $derivedOutstanding = max(round((float) $amount - $paidAmount, 2), 0.0);
    if ($derivedOutstanding > (float) ($invoiceOutstandingTotals[$currency] ?? 0) + 0.005) {
        $invoiceOutstandingTotals[$currency] = $derivedOutstanding;
    }
}

$statementTotalOutstandingTotals = $invoiceOutstandingTotals;
foreach ($statementSameCurrencyPreviousTotals as $currency => $amount) {
    $statementTotalOutstandingTotals[(string) $currency] = (float) ($statementTotalOutstandingTotals[(string) $currency] ?? 0) + (float) $amount;
}

$receiptInvoiceCurrency = (string) (
    array_key_first($invoiceReceivableTotals)
    ?? array_key_first($invoiceOutstandingTotals)
    ?? array_key_first($serviceInvoiceCandidateTotals)
    ?? $receiptCurrency
);
$receiptDisplayZeroTotals = [$receiptInvoiceCurrency => 0.0];
$totalInvoiceAmount = (float) ($invoiceReceivableTotals[$receiptInvoiceCurrency] ?? 0);
$receiptCurrencyReceivedTotal = (float) ($invoiceReceivedTotals[$receiptInvoiceCurrency] ?? 0);
if ($totalInvoiceAmount <= 0 && isset($serviceInvoiceCandidateTotals[$receiptInvoiceCurrency])) {
    $serviceCurrencyFallbackTotal = (float) $serviceInvoiceCandidateTotals[$receiptInvoiceCurrency];
    $totalInvoiceAmount = $serviceCurrencyFallbackTotal;
}

$balanceDue = max($totalInvoiceAmount - $receiptCurrencyReceivedTotal, 0);
$dueDateForReceipt = '';
if ($balanceDue > 0) {
    foreach (($customerPaymentFoundation['openReceivables'] ?? []) as $openReceivableRow) {
        if ((string) ($openReceivableRow['currency'] ?? '') === $receiptInvoiceCurrency && (float) ($openReceivableRow['outstandingAmount'] ?? 0) > 0 && ! empty($openReceivableRow['nextDueDate'])) {
            $dueDateForReceipt = (string) $openReceivableRow['nextDueDate'];
            break;
        }
    }
}
$receiptOriginalPaymentRowsByKey = [];

$registerReceiptOriginalPayment = static function (array $receiptRow) use (&$receiptOriginalPaymentRowsByKey): void {
    $receiptId = (int) ($receiptRow['id'] ?? $receiptRow['receiptId'] ?? 0);
    $receiptNo = trim((string) ($receiptRow['receiptNo'] ?? $receiptRow['receipt_no'] ?? ''));
    $currency = trim((string) ($receiptRow['currency'] ?? $receiptRow['paymentCurrency'] ?? ''));
    $receivedAmount = (float) ($receiptRow['tenderedAmount'] ?? $receiptRow['tendered_amount'] ?? $receiptRow['receivedAmount'] ?? $receiptRow['received_amount'] ?? 0);
    $receiptPurpose = trim((string) ($receiptRow['receiptPurpose'] ?? $receiptRow['receipt_purpose'] ?? 'booking_payment'));

    if (($receiptId <= 0 && $receiptNo === '') || $currency === '' || abs($receivedAmount) <= 0.005) {
        return;
    }

    $payload = [
        'currency' => $currency,
        'receivedAmount' => $receivedAmount,
        'receiptPurpose' => $receiptPurpose !== '' ? $receiptPurpose : 'booking_payment',
    ];

    if ($receiptId > 0) {
        $receiptOriginalPaymentRowsByKey['id:' . $receiptId] = $payload;
    }

    if ($receiptNo !== '') {
        $receiptOriginalPaymentRowsByKey['no:' . $receiptNo] = $payload;
    }
};

foreach (($customerPaymentFoundation['receipts'] ?? []) as $receiptRow) {
    $registerReceiptOriginalPayment($receiptRow);
}

if ($selectedReceipt !== null) {
    $registerReceiptOriginalPayment($selectedReceipt);
}

$getOriginalReceiptPaymentDisplay = static function (array $historyRow) use ($receiptOriginalPaymentRowsByKey, $receiptCurrency, $formatMoney): string {
    $historyReceiptId = (int) ($historyRow['receiptId'] ?? $historyRow['id'] ?? 0);
    $historyReceiptNo = trim((string) ($historyRow['receiptNo'] ?? $historyRow['receipt_no'] ?? ''));

    $originalPayment = null;

    if ($historyReceiptId > 0 && isset($receiptOriginalPaymentRowsByKey['id:' . $historyReceiptId])) {
        $originalPayment = $receiptOriginalPaymentRowsByKey['id:' . $historyReceiptId];
    } elseif ($historyReceiptNo !== '' && isset($receiptOriginalPaymentRowsByKey['no:' . $historyReceiptNo])) {
        $originalPayment = $receiptOriginalPaymentRowsByKey['no:' . $historyReceiptNo];
    }

    if (is_array($originalPayment)) {
        if ((string) ($originalPayment['receiptPurpose'] ?? '') === 'customer_advance') {
            return 'Advance Applied: ' . (string) $originalPayment['currency'] . ' ' . $formatMoney((float) $originalPayment['receivedAmount']);
        }

        return (string) $originalPayment['currency'] . ' ' . $formatMoney((float) $originalPayment['receivedAmount']);
    }

    $fallbackCurrency = (string) ($historyRow['paymentCurrency'] ?? $historyRow['currency'] ?? $receiptCurrency);
    $fallbackAmount = (float) (
        $historyRow['paymentAmountConsumed']
        ?? $historyRow['receivedAmount']
        ?? $historyRow['allocatedAmount']
        ?? $historyRow['receivableAmountAllocated']
        ?? 0
    );

    $fallbackDisplay = $fallbackCurrency . ' ' . $formatMoney($fallbackAmount);

    return (string) ($historyRow['receiptPurpose'] ?? '') === 'customer_advance'
        ? 'Advance Applied: ' . $fallbackDisplay
        : $fallbackDisplay;
};
$settlementAllocationIds = array_fill_keys(array_map(
    'intval',
    is_array($selectedReceipt['allocationIds'] ?? null) ? $selectedReceipt['allocationIds'] : []
), true);
$receiptAllocationSource = $outputType === 'customer_settlement_receipt'
    ? ($customerPaymentFoundation['invoicePaymentHistory'] ?? [])
    : ($customerPaymentFoundation['allocations'] ?? []);
$receiptAllocations = array_values(array_filter(
    $receiptAllocationSource,
    static function (array $allocation) use ($selectedReceiptId, $selectedReceiptNo, $settlementAllocationIds): bool {
        $allocationId = (int) ($allocation['allocationId'] ?? 0);
        if ($settlementAllocationIds !== []) {
            return $allocationId > 0 && isset($settlementAllocationIds[$allocationId]);
        }

        return (int) ($allocation['receiptId'] ?? 0) === $selectedReceiptId
            || ((string) ($allocation['receiptNo'] ?? '') !== '' && (string) ($allocation['receiptNo'] ?? '') === $selectedReceiptNo);
    }
));
$currentBookingReceiptAllocations = array_values(array_filter(
    $receiptAllocations,
    static fn (array $allocation): bool => (string) ($allocation['bookingReference'] ?? '') === $bookingReference
));
$receiptIsPassengerSpecific = false;
$receiptScopedLineReferences = [];
foreach ($currentBookingReceiptAllocations as $allocation) {
    $allocationNote = mb_strtolower(trim((string) ($allocation['allocationTrail'] ?? $allocation['allocationNote'] ?? '')));
    if (str_contains($allocationNote, 'selected passenger due')) {
        $receiptIsPassengerSpecific = true;
    }

    $allocationServiceLineReference = trim((string) ($allocation['serviceLineReference'] ?? ''));
    if ($allocationServiceLineReference !== '') {
        $receiptScopedLineReferences[$allocationServiceLineReference] = true;
    }
}
$receiptScopedLineReferences = $receiptIsPassengerSpecific ? $receiptScopedLineReferences : [];
$serviceRowsByLineReference = [];
foreach ($services as $service) {
    $serviceLineReference = trim((string) ($service['line_reference'] ?? ''));
    if ($serviceLineReference !== '') {
        $serviceRowsByLineReference[$serviceLineReference] = $service;
    }
}
$receiptPassengerNames = [];
$receiptPassengerReferences = [];
$receiptPassengerDepartureDates = [];
foreach ($currentBookingReceiptAllocations as $allocation) {
    $allocationPassenger = trim((string) ($allocation['passengerName'] ?? ''));
    if ($allocationPassenger !== '') {
        $receiptPassengerNames[$allocationPassenger] = true;
    }

    $allocationServiceLineReference = trim((string) ($allocation['serviceLineReference'] ?? ''));
    $allocationService = $allocationServiceLineReference !== '' ? ($serviceRowsByLineReference[$allocationServiceLineReference] ?? null) : null;
    if (! is_array($allocationService)) {
        continue;
    }

    $servicePassenger = trim((string) ($allocationService['passenger_name'] ?? ''));
    if ($servicePassenger !== '') {
        $receiptPassengerNames[$servicePassenger] = true;
    }

    $serviceReference = $extractServiceReference($allocationService);
    if ($serviceReference !== 'N/A') {
        $receiptPassengerReferences[$serviceReference] = true;
    }

    $serviceDepartureDate = trim((string) ($allocationService['departure_date'] ?? ''));
    if ($serviceDepartureDate !== '') {
        $receiptPassengerDepartureDates[$serviceDepartureDate] = true;
    }
}
foreach ($services as $service) {
    $servicePassenger = trim((string) ($service['passenger_name'] ?? ''));
    if ($servicePassenger !== '') {
        $receiptPassengerNames[$servicePassenger] = true;
    }

    $serviceReference = $extractServiceReference($service);
    if ($serviceReference !== 'N/A') {
        $receiptPassengerReferences[$serviceReference] = true;
    }

    $serviceDepartureDate = trim((string) ($service['departure_date'] ?? ''));
    if ($serviceDepartureDate !== '') {
        $receiptPassengerDepartureDates[$serviceDepartureDate] = true;
    }
}
$receiptPassengerSummary = implode(', ', array_keys($receiptPassengerNames));
if ($receiptPassengerSummary === '') {
    $receiptPassengerSummary = $customerName;
}
$receiptReferenceSummary = implode(' / ', array_keys($receiptPassengerReferences));
$receiptDepartureDateSummary = implode(' / ', array_keys($receiptPassengerDepartureDates));
$receiptPassengerCount = count($receiptPassengerNames);
$receiptPassengerCaption = $receiptPassengerCount > 1 ? 'Passengers Covered' : 'Passenger';
$normalizeReceiptPersonName = static function (string $value): string {
    $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));

    return $normalized;
};
$normalizedCustomerName = $normalizeReceiptPersonName($customerName);
$normalizedReceiptPassengerSummary = $normalizeReceiptPersonName($receiptPassengerSummary);
$receiptPassengerNameDirectory = [];
foreach (array_keys($receiptPassengerNames) as $receiptPassengerName) {
    $normalizedPassengerName = $normalizeReceiptPersonName((string) $receiptPassengerName);
    if ($normalizedPassengerName !== '') {
        $receiptPassengerNameDirectory[$normalizedPassengerName] = true;
    }
}
$passengerOutstandingBalanceTotals = [];
$showPassengerOutstandingBalance = false;
if ($receiptIsPassengerSpecific && $receiptScopedLineReferences !== []) {
    foreach (($customerPaymentFoundation['openReceivables'] ?? []) as $openReceivableRow) {
        if (! is_array($openReceivableRow)) {
            continue;
        }

        $serviceLineReference = trim((string) ($openReceivableRow['serviceLineReference'] ?? ''));
        if ($serviceLineReference === '' || ! isset($receiptScopedLineReferences[$serviceLineReference])) {
            continue;
        }

        $currency = trim((string) ($openReceivableRow['currency'] ?? ''));
        $outstandingAmount = round((float) ($openReceivableRow['outstandingAmount'] ?? 0), 2);
        if ($currency === '') {
            continue;
        }

        $passengerOutstandingBalanceTotals[$currency] = ($passengerOutstandingBalanceTotals[$currency] ?? 0.0)
            + max(0.0, $outstandingAmount);
    }

    foreach ($currentBookingReceiptAllocations as $allocation) {
        $serviceLineReference = trim((string) ($allocation['serviceLineReference'] ?? ''));
        if ($serviceLineReference === '' || ! isset($receiptScopedLineReferences[$serviceLineReference])) {
            continue;
        }

        $currency = trim((string) ($allocation['receivableCurrency'] ?? $allocation['currency'] ?? ''));
        if ($currency !== '' && ! isset($passengerOutstandingBalanceTotals[$currency])) {
            $passengerOutstandingBalanceTotals[$currency] = 0.0;
        }
    }

    $showPassengerOutstandingBalance = $passengerOutstandingBalanceTotals !== [];
}
$currentInvoicePaidByReceiptTotals = [];
foreach ($currentBookingReceiptAllocations as $allocation) {
    $currency = (string) ($allocation['receivableCurrency'] ?? $allocation['currency'] ?? '');
    if ($currency === '') {
        continue;
    }

    $currentInvoicePaidByReceiptTotals[$currency] = ($currentInvoicePaidByReceiptTotals[$currency] ?? 0.0)
        + (float) ($allocation['receivableAmountAllocated'] ?? $allocation['allocatedAmount'] ?? 0);
}
if (
    $currentInvoicePaidByReceiptTotals === []
    && $selectedReceipt !== null
    && ! $selectedReceiptIsVoid
) {
    $fallbackCurrency = trim((string) ($selectedReceipt['currency'] ?? ''));
    $fallbackAmount = (float) ($selectedReceipt['allocatedAmount'] ?? 0);
    if (abs($fallbackAmount) <= 0.005) {
        $fallbackAmount = (float) ($selectedReceipt['receivedAmount'] ?? 0) - (float) ($selectedReceipt['returnedAmount'] ?? $selectedReceipt['returned_amount'] ?? 0);
    }
    if ($fallbackCurrency !== '' && abs($fallbackAmount) > 0.005) {
        $currentInvoicePaidByReceiptTotals[$fallbackCurrency] = round($fallbackAmount, 2);
    }
}
$receiptServiceRows = [];
$receiptSubtotalTotals = [];
$receiptDiscountTotals = [];
$receiptGrandTotals = [];
foreach ($services as $serviceIndex => $service) {
    $serviceLineReference = trim((string) ($service['line_reference'] ?? ''));
    if (
        $receiptIsPassengerSpecific
        && $receiptScopedLineReferences !== []
        && ($serviceLineReference === '' || ! isset($receiptScopedLineReferences[$serviceLineReference]))
    ) {
        continue;
    }

    $serviceCurrency = trim((string) ($service['currency'] ?? $receiptInvoiceCurrency ?? 'PKR'));
    if ($serviceCurrency === '') {
        $serviceCurrency = $receiptInvoiceCurrency !== '' ? $receiptInvoiceCurrency : 'PKR';
    }

    $servicePassenger = trim((string) ($service['passenger_name'] ?? ''));
    if ($servicePassenger === '') {
        $servicePassenger = $customerName !== '' ? $customerName : 'Passenger';
    }

    $serviceRoute = $extractServiceRoute($service);
    $serviceReference = $extractServiceReference($service);

    $serviceDeparture = trim((string) ($service['departure_date'] ?? ''));
    if ($serviceDeparture === '') {
        $serviceDeparture = trim((string) ($service['due_date'] ?? ''));
    }

    $serviceDiscountAmount = round((float) ($service['discount_amount'] ?? 0), 2);
    $serviceLineTotal = round($serviceReceivableAmount($service), 2);
    $serviceLineSubtotal = round($serviceLineTotal + max(0, $serviceDiscountAmount), 2);

    $receiptSubtotalTotals[$serviceCurrency] = ($receiptSubtotalTotals[$serviceCurrency] ?? 0.0) + $serviceLineSubtotal;
    if ($serviceDiscountAmount > 0.005) {
        $receiptDiscountTotals[$serviceCurrency] = ($receiptDiscountTotals[$serviceCurrency] ?? 0.0) + $serviceDiscountAmount;
    }
    $receiptGrandTotals[$serviceCurrency] = ($receiptGrandTotals[$serviceCurrency] ?? 0.0) + $serviceLineTotal;

    $receiptServiceRows[] = [
        'index' => count($receiptServiceRows) + 1,
        'service' => ucwords((string) ($service['service_type'] ?? 'service')),
        'passenger' => $servicePassenger,
        'route' => $serviceRoute !== '' ? $serviceRoute : 'N/A',
        'referenceLabel' => $extractServiceReferenceLabel($service),
        'reference' => $serviceReference,
        'departure' => $serviceDeparture !== '' ? $serviceDeparture : 'N/A',
        'currency' => $serviceCurrency,
        'amount' => $serviceLineTotal,
    ];
}
$receiptReferenceLabels = array_values(array_unique(array_map(
    static fn (array $receiptServiceRow): string => (string) ($receiptServiceRow['referenceLabel'] ?? 'Reference'),
    $receiptServiceRows
)));
$receiptReferenceColumnLabel = count($receiptReferenceLabels) === 1 ? $receiptReferenceLabels[0] : 'Reference';
$receiptDiscountTotals = $nonZeroCurrencyTotals($receiptDiscountTotals);
$receiptSubtotalTotals = $nonZeroCurrencyTotals($receiptSubtotalTotals);
$receiptGrandTotals = $nonZeroCurrencyTotals($receiptGrandTotals);
$previousBalancePaidByReceiptTotals = [];
$previousBalanceAllocationRows = [];

foreach ($receiptAllocations as $allocation) {
    $allocationBookingReference = (string) ($allocation['bookingReference'] ?? '');
    if ($allocationBookingReference === '' || $allocationBookingReference === $bookingReference) {
        continue;
    }

    $currency = (string) ($allocation['receivableCurrency'] ?? $allocation['currency'] ?? $receiptCurrency);
    if ($currency === '') {
        continue;
    }

    $amount = (float) ($allocation['receivableAmountAllocated'] ?? $allocation['allocatedAmount'] ?? 0);
    if (abs($amount) <= 0.005) {
        continue;
    }

    $previousBalancePaidByReceiptTotals[$currency] = ($previousBalancePaidByReceiptTotals[$currency] ?? 0.0) + $amount;
    $previousBalanceAllocationRows[] = $allocation;
}

$previousBalancePaidByReceiptDisplay = $nonZeroCurrencyTotals($previousBalancePaidByReceiptTotals);

$previousBalanceBeforeReceiptTotals = $remainingCustomerBalanceTotals;
foreach ($previousBalancePaidByReceiptTotals as $currency => $amount) {
    $previousBalanceBeforeReceiptTotals[$currency] = round(
        (float) ($previousBalanceBeforeReceiptTotals[$currency] ?? 0) + (float) $amount,
        2
    );
}
$previousBalanceBeforeReceiptDisplay = $nonZeroCurrencyTotals($previousBalanceBeforeReceiptTotals);
$receiptAllocationTotals = [];
foreach ($receiptAllocations as $allocation) {
    $currency = (string) ($allocation['paymentCurrency'] ?? $allocation['receivableCurrency'] ?? $allocation['currency'] ?? '');
    if ($currency === '') {
        continue;
    }

    $receiptAllocationTotals[$currency] = ($receiptAllocationTotals[$currency] ?? 0.0)
        + (float) ($allocation['paymentAmountConsumed'] ?? $allocation['allocatedAmount'] ?? 0);
}
$currentInvoiceBalanceDisplay = $nonZeroCurrencyTotals($invoiceOutstandingTotals);
$currentInvoiceAmountDisplay = $nonZeroCurrencyTotals($invoiceReceivableTotals);
if ($receiptIsPassengerSpecific && $receiptScopedLineReferences !== []) {
    $currentInvoiceAmountDisplay = $receiptGrandTotals !== [] ? $receiptGrandTotals : $currentInvoicePaidByReceiptTotals;
    $currentInvoiceBalanceDisplay = $passengerOutstandingBalanceTotals;
}
$receiptTotalAllocated = (float) ($selectedReceipt['allocatedAmount'] ?? 0);
$receiptUnallocatedAmount = (float) ($selectedReceipt['unallocatedAmount'] ?? 0);
$receiptReturnedAmount = (float) ($selectedReceipt['returnedAmount'] ?? $selectedReceipt['returned_amount'] ?? 0);
$invoicePaymentHistoryRows = array_values(array_filter(
    $customerPaymentFoundation['invoicePaymentHistory'] ?? [],
    static function (array $allocation) use ($bookingReference, $receiptIsPassengerSpecific, $receiptScopedLineReferences): bool {
        if ((string) ($allocation['bookingReference'] ?? '') !== $bookingReference) {
            return false;
        }

        $receiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($allocation['receiptStatusRaw'] ?? ''))));

        if ($receiptStatusRaw === 'void') {
            return false;
        }

        if ($receiptIsPassengerSpecific && $receiptScopedLineReferences !== []) {
            $serviceLineReference = trim((string) ($allocation['serviceLineReference'] ?? ''));
            $allocationTrail = mb_strtolower(trim((string) ($allocation['allocationTrail'] ?? $allocation['allocationNote'] ?? '')));

            return $serviceLineReference !== ''
                && isset($receiptScopedLineReferences[$serviceLineReference])
                && str_contains($allocationTrail, 'selected passenger due');
        }

        return true;
    }
));
$statementInvoiceAllocationRows = $invoicePaymentHistoryRows !== []
    ? $invoicePaymentHistoryRows
    : ($customerPaymentFoundation['allocations'] ?? []);
$statementRemainingByServiceLineCurrency = [];
foreach (($customerPaymentFoundation['openReceivables'] ?? []) as $statementOpenReceivable) {
    $statementOpenBookingReference = trim((string) ($statementOpenReceivable['bookingReference'] ?? $statementOpenReceivable['booking_reference'] ?? ''));
    if ($statementOpenBookingReference !== '' && $statementOpenBookingReference !== $bookingReference) {
        continue;
    }

    $statementOpenServiceLine = trim((string) ($statementOpenReceivable['serviceLineReference'] ?? $statementOpenReceivable['service_line_reference'] ?? ''));
    $statementOpenCurrency = trim((string) ($statementOpenReceivable['currency'] ?? ''));
    if ($statementOpenServiceLine === '' || $statementOpenCurrency === '') {
        continue;
    }

    $statementOpenKey = $statementOpenServiceLine . '|' . $statementOpenCurrency;
    $statementRemainingByServiceLineCurrency[$statementOpenKey] = ($statementRemainingByServiceLineCurrency[$statementOpenKey] ?? 0.0)
        + max(0.0, round((float) ($statementOpenReceivable['outstandingAmount'] ?? $statementOpenReceivable['outstanding_amount'] ?? 0), 2));
}
$statementAdvanceAppliedTotals = [];
$statementAdvanceReceiptRows = [];
$statementReceiptKeys = [];
foreach (($customerPaymentFoundation['receipts'] ?? []) as $statementReceiptRow) {
    $statementReceiptId = (int) ($statementReceiptRow['id'] ?? 0);
    $statementReceiptNo = trim((string) ($statementReceiptRow['receiptNo'] ?? ''));
    if ($statementReceiptId > 0) {
        $statementReceiptKeys['id:' . $statementReceiptId] = true;
    }
    if ($statementReceiptNo !== '') {
        $statementReceiptKeys['no:' . $statementReceiptNo] = true;
    }
}
foreach ($statementInvoiceAllocationRows as $statementAllocationRow) {
    $statementReceiptPurpose = str_replace(' ', '_', mb_strtolower(trim((string) ($statementAllocationRow['receiptPurpose'] ?? 'booking_payment'))));
    if ($statementReceiptPurpose !== 'customer_advance') {
        continue;
    }

    $statementAllocationReceiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($statementAllocationRow['receiptStatusRaw'] ?? ''))));
    if ($statementAllocationReceiptStatusRaw === 'void') {
        continue;
    }

    $statementAdvanceCurrency = trim((string) ($statementAllocationRow['receivableCurrency'] ?? $statementAllocationRow['currency'] ?? ''));
    $statementAdvanceAmount = round((float) ($statementAllocationRow['receivableAmountAllocated'] ?? $statementAllocationRow['allocatedAmount'] ?? 0), 2);
    if ($statementAdvanceCurrency === '' || abs($statementAdvanceAmount) <= 0.005) {
        continue;
    }

    $statementAdvanceAppliedTotals[$statementAdvanceCurrency] = ($statementAdvanceAppliedTotals[$statementAdvanceCurrency] ?? 0.0) + $statementAdvanceAmount;

    $statementAdvanceReceiptId = (int) ($statementAllocationRow['receiptId'] ?? 0);
    $statementAdvanceReceiptNo = trim((string) ($statementAllocationRow['receiptNo'] ?? ''));
    $statementAdvanceReceiptKey = $statementAdvanceReceiptId > 0
        ? 'id:' . $statementAdvanceReceiptId
        : ($statementAdvanceReceiptNo !== '' ? 'no:' . $statementAdvanceReceiptNo : 'advance:' . count($statementAdvanceReceiptRows));

    if (isset($statementReceiptKeys[$statementAdvanceReceiptKey])) {
        continue;
    }

    if (!isset($statementAdvanceReceiptRows[$statementAdvanceReceiptKey])) {
        $statementAdvanceReceiptRows[$statementAdvanceReceiptKey] = [
            'receiptNo' => $statementAdvanceReceiptNo !== '' ? $statementAdvanceReceiptNo : 'Advance',
            'receiptDate' => (string) ($statementAllocationRow['receiptDate'] ?? mb_substr((string) ($statementAllocationRow['allocatedAt'] ?? ''), 0, 10)),
            'currency' => $statementAdvanceCurrency,
            'allocatedAmount' => 0.0,
        ];
    }

    $statementAdvanceReceiptRows[$statementAdvanceReceiptKey]['allocatedAmount'] =
        (float) ($statementAdvanceReceiptRows[$statementAdvanceReceiptKey]['allocatedAmount'] ?? 0) + $statementAdvanceAmount;
}
$statementAdvanceAppliedTotals = $nonZeroCurrencyTotals($statementAdvanceAppliedTotals);
$isSelectedReceiptHistoryRow = static function (array $historyRow) use ($selectedReceiptId, $selectedReceiptNo): bool {
    $historyReceiptId = (int) ($historyRow['receiptId'] ?? 0);
    $historyReceiptNo = trim((string) ($historyRow['receiptNo'] ?? ''));

    if ($selectedReceiptId > 0 && $historyReceiptId > 0) {
        return $historyReceiptId === $selectedReceiptId;
    }

    return $selectedReceiptNo !== '' && $historyReceiptNo !== '' && $historyReceiptNo === $selectedReceiptNo;
};

$previousInvoicePaymentsTotals = [];
$currentInvoicePaymentHistoryTotals = [];
$totalPaidAgainstInvoiceTotals = [];
$previousInvoicePaymentHasAdvance = false;
$previousInvoicePaymentHasNonAdvance = false;

foreach ($invoicePaymentHistoryRows as $historyRow) {
    $currency = (string) ($historyRow['receivableCurrency'] ?? $historyRow['currency'] ?? '');
    if ($currency === '') {
        continue;
    }

    $paidAmount = (float) ($historyRow['receivableAmountAllocated'] ?? $historyRow['allocatedAmount'] ?? 0);
    $totalPaidAgainstInvoiceTotals[$currency] = ($totalPaidAgainstInvoiceTotals[$currency] ?? 0.0) + $paidAmount;

    if ($isSelectedReceiptHistoryRow($historyRow)) {
        $currentInvoicePaymentHistoryTotals[$currency] = ($currentInvoicePaymentHistoryTotals[$currency] ?? 0.0) + $paidAmount;
    } else {
        $previousInvoicePaymentsTotals[$currency] = ($previousInvoicePaymentsTotals[$currency] ?? 0.0) + $paidAmount;
        $receiptPurpose = str_replace(' ', '_', mb_strtolower(trim((string) ($historyRow['receiptPurpose'] ?? 'booking_payment'))));
        if ($receiptPurpose === 'customer_advance') {
            $previousInvoicePaymentHasAdvance = true;
        } else {
            $previousInvoicePaymentHasNonAdvance = true;
        }
    }
}

$previousInvoicePaymentsDisplay = $nonZeroCurrencyTotals($previousInvoicePaymentsTotals);
$previousInvoicePaymentsLabel = $previousInvoicePaymentHasAdvance && ! $previousInvoicePaymentHasNonAdvance
    ? 'Advance Payment'
    : ($previousInvoicePaymentHasAdvance ? 'Previous / Advance Payments' : 'Previous Payments');
$currentInvoicePaymentHistoryDisplay = $nonZeroCurrencyTotals($currentInvoicePaymentHistoryTotals);
$totalPaidAgainstInvoiceDisplay = $nonZeroCurrencyTotals($totalPaidAgainstInvoiceTotals);

// The allocation history is the authoritative payment truth for settlement output.
// This also includes credit transferred from a refund on another booking, even
// though no new cash receipt belongs to the target booking.
if ($totalPaidAgainstInvoiceDisplay !== []) {
    foreach ($totalPaidAgainstInvoiceDisplay as $currency => $paidAmount) {
        $invoiceReceivedTotals[(string) $currency] = round((float) $paidAmount, 2);
    }
    foreach ($invoiceReceivableTotals as $currency => $invoiceAmount) {
        $invoiceOutstandingTotals[(string) $currency] = max(round(
            (float) $invoiceAmount - (float) ($invoiceReceivedTotals[(string) $currency] ?? 0),
            2
        ), 0.0);
    }
}

$openSupplierHistoryUrl = null;
if ($outputType === 'supplier_voucher' && (int) ($booking['id'] ?? 0) > 0) {
    $openSupplierHistoryUrl = url('/workspace?booking_id=' . (int) $booking['id'] . '#dock-panel-suppliers');
}
$usesReceiptLayout = in_array($outputType, ['customer_receipt', 'customer_settlement_receipt', 'booking_summary_receipt', 'service_refund_receipt'], true);
?>
<main class="output-page">
    <div class="output-toolbar no-print">
        <?php if ($openSupplierHistoryUrl !== null): ?>
            <a class="btn btn-sm" href="<?= e($openSupplierHistoryUrl) ?>">Open Supplier History</a>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">Print</button>
    </div>

    <?php if (! $usesReceiptLayout): ?>
        <section class="output-sheet">
            <header class="output-head">
                <div>
                    <div class="output-branch"><?= e((string) ($branchBranding['name'] ?? 'Travel Agency Branch')) ?></div>
                    <div class="output-branch-meta">
                        <?= e(trim((string) (($branchBranding['city'] ?? '') . ((string) ($branchBranding['country'] ?? '') !== '' ? ', ' . (string) ($branchBranding['country'] ?? '') : '')))) ?>
                    </div>
                    <div class="output-branch-tagline"><?= e((string) ($branchBranding['tagline'] ?? 'Travel Agency Operations')) ?></div>
                </div>
                <div class="output-doc-meta">
                    <div class="output-doc-title"><?= e($outputTypeLabel) ?></div>
                    <div><strong>Booking / Invoice:</strong> <?= e($bookingReference) ?></div>
                    <div><strong>Generated:</strong> <?= e($generatedAt) ?></div>
                    <div><strong>Base Currency:</strong> <?= e((string) ($branchBranding['baseCurrency'] ?? 'PKR')) ?></div>
                </div>
            </header>
            <section class="output-top-grid">
                <div class="output-block">
                    <h2>Document Details</h2>
                    <div class="output-kv-grid">
                        <div><span>Booking / Invoice No.</span><strong><?= e($bookingReference !== '' ? $bookingReference : 'N/A') ?></strong></div>
                        <div><span>Invoice Date</span><strong><?= e($bookingDate !== '' ? $bookingDate : 'N/A') ?></strong></div>
                        <div><span>Due Date</span><strong><?= e($bookingDueDate !== '' ? $bookingDueDate : 'N/A') ?></strong></div>
                        <div><span>Service Summary</span><strong><?= e($invoiceServiceSummary) ?></strong></div>
                        <div><span>Passengers</span><strong><?= e((string) $travelerCount) ?></strong></div>
                        <div><span>Service Rows</span><strong><?= e((string) $serviceCount) ?></strong></div>
                    </div>
                </div>
                <div class="output-block">
                    <h2><?= e($outputType === 'supplier_voucher' ? 'Supplier / Booking' : 'Customer / Party') ?></h2>
                    <div class="output-kv-grid">
                        <div><span>Customer Name</span><strong><?= e($customerName) ?></strong></div>
                        <div><span>Party Label</span><strong><?= e((string) (($booking['party_label'] ?? '') !== '' ? $booking['party_label'] : 'Booking Party')) ?></strong></div>
                        <div><span>Mobile</span><strong><?= e($customerMobile !== '' ? $customerMobile : 'N/A') ?></strong></div>
                        <div><span>Passport No.</span><strong><?= e($customerPassport !== '' ? $customerPassport : 'N/A') ?></strong></div>
                        <div><span>Branch</span><strong><?= e((string) ($branchBranding['name'] ?? 'Travel Agency Branch')) ?></strong></div>
                        <div><span>Remarks</span><strong><?= e((string) (($booking['remarks'] ?? '') !== '' ? $booking['remarks'] : 'N/A')) ?></strong></div>
                    </div>
                </div>
            </section>
            <?php if ($outputType !== 'reissue_voucher'): ?>
                <section class="output-note-bar">
                    <?= e($documentAudienceNote) ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($outputType === 'invoice'): ?>
            <section class="output-block">
                <h2>Invoice Services</h2>
                <table class="output-table">
                    <thead><tr><th>Line</th><th>Service</th><th>Passenger</th><th>Reference</th><th>Sector / Description</th><th>Currency</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <?php
                        $serviceReference = trim((string) ($service['ticket_number'] ?? $service['line_reference'] ?? ''));
                        $servicePassenger = trim((string) ($service['passenger_name'] ?? ''));
                        $serviceAmount = $serviceReceivableAmount($service);
                        ?>
                        <tr>
                            <td><?= e((string) ($service['line_reference'] ?? '')) ?></td>
                            <td><?= e(ucwords((string) ($service['service_type'] ?? 'service'))) ?></td>
                            <td><?= e($servicePassenger !== '' ? $servicePassenger : $customerName) ?></td>
                            <td><?= e($serviceReference !== '' ? $serviceReference : 'N/A') ?></td>
                            <td><?= e($cleanServiceDescription($service)) ?></td>
                            <td><?= e((string) ($service['currency'] ?? 'PKR')) ?></td>
                            <td><?= e($formatMoney($serviceAmount)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($services === []): ?><tr><td colspan="7">No service lines recorded for this invoice yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Invoice Summary</h2>
                <div class="output-summary-strip">
                    <div><span>Total Invoice Amount</span><strong><?= e($formatCurrencyTotals($invoiceReceivableTotals)) ?></strong></div>
                    <div><span>Payment Received</span><strong><?= e($formatCurrencyTotals($invoiceReceivedTotals)) ?></strong></div>
                    <div><span>Balance Due</span><strong><?= e($formatCurrencyTotals($invoiceOutstandingTotals)) ?></strong></div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'reissue_voucher'): ?>
            <?php
            $bookingSummaryPaymentRows = [];

            foreach ($invoicePaymentHistoryRows as $historyRow) {
                $receiptNo = trim((string) ($historyRow['receiptNo'] ?? ''));
                $receiptId = (int) ($historyRow['receiptId'] ?? 0);
                $receiptKey = $receiptId > 0 ? 'id:' . $receiptId : 'no:' . $receiptNo;

                if ($receiptKey === 'no:') {
                    $receiptKey = 'row:' . count($bookingSummaryPaymentRows);
                }

                if (!isset($bookingSummaryPaymentRows[$receiptKey])) {
                    $bookingSummaryPaymentRows[$receiptKey] = [
                        'receiptNo' => $receiptNo,
                        'allocatedAt' => (string) ($historyRow['allocatedAt'] ?? ''),
                        'status' => (string) ($historyRow['receiptStatusRaw'] ?? $historyRow['receiptStatus'] ?? 'posted'),
                        'totals' => [],
                    ];
                }

                $currency = (string) ($historyRow['receivableCurrency'] ?? $historyRow['currency'] ?? 'PKR');
                $amount = (float) ($historyRow['receivableAmountAllocated'] ?? $historyRow['allocatedAmount'] ?? 0);

                if ($currency !== '' && abs($amount) > 0.005) {
                    $bookingSummaryPaymentRows[$receiptKey]['totals'][$currency] =
                        (float) ($bookingSummaryPaymentRows[$receiptKey]['totals'][$currency] ?? 0) + $amount;
                }
            }
            ?>

            <section class="output-block">
                <h2><?= e(match ($outputType) {
                    'customer_settlement_receipt' => 'Customer Payment Receipt',
                    'reissue_voucher' => 'Ticket Reissue Voucher',
                    default => 'Booking Summary Receipt',
                }) ?></h2>
                <div class="output-summary-strip">
                    <?php if ($outputType !== 'reissue_voucher'): ?>
                        <div><span>Booking / Invoice No.</span><strong><?= e($bookingReference) ?></strong></div>
                        <div><span>Customer</span><strong><?= e($customerName) ?></strong></div>
                    <?php endif; ?>
                    <div><span>Total Invoice Amount</span><strong><?= e($formatCurrencyTotals($invoiceReceivableTotals !== [] ? $invoiceReceivableTotals : ['PKR' => 0])) ?></strong></div>
                    <div><span>Total Paid</span><strong><?= e($formatCurrencyTotals($invoiceReceivedTotals !== [] ? $invoiceReceivedTotals : ['PKR' => 0])) ?></strong></div>
                    <div><span>Balance Due</span><strong><?= e($formatCurrencyTotals($invoiceOutstandingTotals !== [] ? $invoiceOutstandingTotals : ['PKR' => 0])) ?></strong></div>
                    <?php if ($outputType !== 'reissue_voucher' && $bookingDueDate !== ''): ?>
                        <div><span>Due Date</span><strong><?= e($bookingDueDate) ?></strong></div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="output-block">
                <h2>Services Included in This Booking</h2>
                <table class="output-table">
                    <thead>
                    <tr>
                        <th>Line</th>
                        <th>Service</th>
                        <th>Passenger</th>
                        <th>Ticket / Ref.</th>
                        <th>Detail</th>
                        <th>Currency</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <?php
                        $summaryServiceReference = trim((string) (($service['ticket_number'] ?? '') !== '' ? $service['ticket_number'] : ($service['line_reference'] ?? '')));
                        $summaryPassenger = trim((string) ($service['passenger_name'] ?? ''));
                        $summaryServiceAmount = $serviceReceivableAmount($service);
                        ?>
                        <tr>
                            <td><?= e((string) ($service['line_reference'] ?? '')) ?></td>
                            <td><?= e(ucwords((string) ($service['service_type'] ?? 'service'))) ?></td>
                            <td><?= e($summaryPassenger !== '' ? $summaryPassenger : $customerName) ?></td>
                            <td><?= e($summaryServiceReference !== '' ? $summaryServiceReference : 'N/A') ?></td>
                            <td><?= e($cleanServiceDescription($service)) ?></td>
                            <td><?= e((string) ($service['currency'] ?? 'PKR')) ?></td>
                            <td><?= e($formatMoney($summaryServiceAmount)) ?></td>
                            <td><?= e(ucwords((string) ($service['status'] ?? 'Open'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($services === []): ?>
                        <tr><td colspan="8">No service lines recorded for this booking yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <?php if ($reissueEvents !== []): ?>
                <section class="output-block">
                    <h2>Reissue Adjustments Added to Invoice</h2>
                    <table class="output-table">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Service Ref.</th>
                            <th>Old Ticket</th>
                            <th>New Ticket</th>
                            <th>Reissue Charges</th>
                            <th>Reason</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reissueEvents as $reissueEvent): ?>
                            <?php
                            $reissueCurrency = (string) ($reissueEvent['currency'] ?? 'PKR');
                            $reissuePayload = json_decode((string) ($reissueEvent['payload_json'] ?? ''), true);
                            $reissuePayload = is_array($reissuePayload) ? $reissuePayload : [];
                            $reissueFareDifference = (float) ($reissuePayload['supplier_cost_difference_amount'] ?? $reissueEvent['fare_difference_amount'] ?? 0);
                            $reissueServiceFee = (float) ($reissueEvent['service_fee_amount'] ?? 0);
                            $reissueCustomerDelta = round($reissueFareDifference + $reissueServiceFee, 2);
                            if (abs($reissueCustomerDelta) <= 0.005 && ! empty($reissueEvent['payload_json'])) {
                                $reissueCustomerDelta = round((float) ($reissuePayload['customer_delta'] ?? 0), 2);
                            }
                            ?>
                            <tr>
                                <td><?= e((string) ($reissueEvent['event_date'] ?? '')) ?></td>
                                <td><?= e((string) ($reissueEvent['service_line_reference'] ?? '')) ?></td>
                                <td><?= e((string) (($reissueEvent['original_ticket_number'] ?? '') !== '' ? $reissueEvent['original_ticket_number'] : 'N/A')) ?></td>
                                <td><?= e((string) (($reissueEvent['new_ticket_number'] ?? '') !== '' ? $reissueEvent['new_ticket_number'] : 'N/A')) ?></td>
                                <td><strong><?= e($reissueCurrency) ?> <?= e($formatMoney($reissueCustomerDelta)) ?></strong></td>
                                <td><?= e((string) (($reissueEvent['reason'] ?? '') !== '' ? $reissueEvent['reason'] : 'Reissue adjustment')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($reissueAdjustmentTotals !== []): ?>
                        <div class="output-summary-strip<?= $outputType === 'reissue_voucher' ? ' output-summary-strip--reissue' : '' ?>" style="margin-top:12px;">
                            <div><span>Reissue Charges</span><strong><?= e($formatCurrencyTotals($reissueAdjustmentTotals)) ?></strong></div>
                            <?php if ($outputType === 'reissue_voucher' && isset($selectedReissueEvent)): ?>
                                <?php $selectedReissuePayload = json_decode((string) ($selectedReissueEvent['payload_json'] ?? ''), true); $selectedReissuePayload = is_array($selectedReissuePayload) ? $selectedReissuePayload : []; ?>
                                <div><span>Cash Received Now</span><strong><?= e((string) ($selectedReissuePayload['cash_received_currency'] ?? $selectedReissueEvent['currency'] ?? 'PKR')) ?> <?= e($formatMoney((float) ($selectedReissuePayload['cash_received'] ?? 0))) ?></strong></div>
                                <div><span>Customer Credit Used</span><strong><?= e((string) ($selectedReissueEvent['currency'] ?? 'PKR')) ?> <?= e($formatMoney((float) ($selectedReissuePayload['customer_credit_applied'] ?? 0))) ?></strong></div>
                                <div><span>Revised Outstanding</span><strong><?= e((string) ($selectedReissueEvent['currency'] ?? 'PKR')) ?> <?= e($formatMoney((float) ($selectedReissuePayload['customer_outstanding'] ?? 0))) ?></strong></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($outputType !== 'reissue_voucher'): ?>
                <section class="output-block">
                    <h2>Payments Applied to This Booking</h2>
                    <table class="output-table">
                    <thead>
                    <tr>
                        <th>Receipt No.</th>
                        <th>Applied At</th>
                        <th>Amount Applied</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($bookingSummaryPaymentRows === []): ?>
                        <tr><td colspan="4">No customer payment has been applied to this booking yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($bookingSummaryPaymentRows as $paymentRow): ?>
                        <tr>
                            <td><?= e((string) ($paymentRow['receiptNo'] ?? '')) ?></td>
                            <td><?= e((string) ($paymentRow['allocatedAt'] ?? '')) ?></td>
                            <td><?= e($formatCurrencyTotals($paymentRow['totals'] ?? [])) ?></td>
                            <td><?= e($formatStatusLabel((string) ($paymentRow['status'] ?? 'posted'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    </table>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (in_array($outputType, ['customer_receipt', 'customer_settlement_receipt', 'booking_summary_receipt'], true) && $selectedReceipt !== null): ?>
            <section class="receipt-sheet">
                <header class="receipt-sheet__head">
                    <div class="receipt-sheet__brand-block">
                        <div class="receipt-sheet__brand-main">
                            <?php if ($primaryContactLogo !== ''): ?>
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
                                <div class="receipt-sheet__meta"><?= e($primaryContactLocationLabel !== '' ? $primaryContactLocationLabel : trim((string) (($branchBranding['city'] ?? '') . ((string) ($branchBranding['country'] ?? '') !== '' ? ', ' . (string) ($branchBranding['country'] ?? '') : '')))) ?></div>
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
                                <div class="receipt-sheet__contact-grid<?= $primaryBranchCode === 'dubai' ? ' receipt-sheet__contact-grid--three-up' : '' ?>">
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
                                                    <strong><?= e((string) ($phoneLine['name'] ?? $phoneLine['label'] ?? 'Contact')) ?></strong>
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
                        <div class="receipt-sheet__title">
                            <?= e($outputType === 'booking_summary_receipt' ? 'Booking Summary Receipt' : 'Customer Payment Receipt') ?>
                            <?php if ($selectedReceiptIsVoid): ?>
                                <span style="display:inline-block;margin-left:10px;padding:4px 10px;border:1px solid #8b0000;border-radius:999px;background:#fff0f0;color:#8b0000;font-size:12px;font-weight:700;letter-spacing:0.08em;">VOID</span>
                            <?php endif; ?>
                        </div>
                        <div class="receipt-sheet__subtitle"><?= e(match ($outputType) {
                            'booking_summary_receipt' => 'Official branch booking receipt. No payment was received with this booking.',
                            'customer_settlement_receipt' => 'Official branch receipt for payment or customer credit applied to this invoice.',
                            default => 'Official branch receipt generated for customer payment confirmation.',
                        }) ?></div>
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

                <?php if ($selectedReceiptIsVoid): ?>
                    <section class="receipt-card" style="border-color:#8b0000;background:#fff7f7;">
                        <div class="receipt-card__grid">
                            <div><span>Status</span><strong>VOID</strong></div>
                            <?php if ($selectedReceiptVoidedAt !== ''): ?>
                                <div><span>Voided At</span><strong><?= e($selectedReceiptVoidedAt) ?></strong></div>
                            <?php endif; ?>
                            <?php if ($selectedReceiptVoidReason !== ''): ?>
                                <div style="grid-column:1 / -1;"><span>Void Reason</span><strong><?= e($selectedReceiptVoidReason) ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="receipt-card">
                    <div class="receipt-card__grid receipt-card__grid--inline">
                        <div><span>Receipt No.</span><strong><?= e((string) ($selectedReceipt['receiptNo'] ?? '')) ?></strong></div>
                        <div><span>Receipt Date</span><strong><?= e((string) ($selectedReceipt['receiptDate'] ?? '')) ?></strong></div>
                        <div><span>Booking / Invoice No.</span><strong><?= e((string) ($booking['booking_reference'] ?? '')) ?></strong></div>
                        <div><span>Customer Name</span><strong><?= e($customerName) ?></strong></div>
                        <?php if ($passportNumber !== ''): ?>
                            <div><span>Passport No.</span><strong><?= e($passportNumber) ?></strong></div>
                        <?php endif; ?>
                        <div><span>Mobile</span><strong><?= e((string) (($booking['contact_mobile'] ?? '') !== '' ? $booking['contact_mobile'] : 'N/A')) ?></strong></div>
                        <div><span>Payment Method</span><strong><?= e(ucwords(str_replace('_', ' ', (string) ($selectedReceipt['paymentMethod'] ?? '')))) ?></strong></div>
                        <div><span>Reference No.</span><strong><?= e($receiptReference !== '' ? $receiptReference : 'N/A') ?></strong></div>
                        <?php if ($receiptBankCard !== ''): ?>
                            <div><span>Bank / Card Detail</span><strong><?= e($receiptBankCard) ?></strong></div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="receipt-card receipt-card--highlight">
                    <table class="output-table receipt-table receipt-service-table">
                        <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Service</th>
                            <th>Passenger</th>
                            <th>Route</th>
                            <th><?= e($receiptReferenceColumnLabel) ?></th>
                            <th>Departure Date</th>
                            <th>Price</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($receiptServiceRows as $receiptServiceRow): ?>
                            <tr>
                                <td><?= e((string) $receiptServiceRow['index']) ?></td>
                                <td><?= e((string) $receiptServiceRow['service']) ?></td>
                                <td><?= e((string) $receiptServiceRow['passenger']) ?></td>
                                <td><?= e((string) $receiptServiceRow['route']) ?></td>
                                <td><?= e((string) $receiptServiceRow['reference']) ?></td>
                                <td><?= e((string) $receiptServiceRow['departure']) ?></td>
                                <td><?= e((string) $receiptServiceRow['currency']) ?> <?= e($formatMoney((float) $receiptServiceRow['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($receiptServiceRows === []): ?>
                            <tr><td colspan="7">No service lines recorded for this receipt yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="receipt-service-totals">
                        <?php if ($receiptSubtotalTotals !== []): ?>
                            <div class="receipt-service-totals__row">
                                <span>Subtotal</span>
                                <strong><?= e($formatCurrencyTotals($receiptSubtotalTotals)) ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if ($receiptDiscountTotals !== []): ?>
                            <div class="receipt-service-totals__row">
                                <span>Discount</span>
                                <strong><?= e($formatCurrencyTotals($receiptDiscountTotals)) ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="receipt-service-totals__row receipt-service-totals__row--grand">
                            <span>Total</span>
                            <strong><?= e($formatCurrencyTotals($receiptGrandTotals !== [] ? $receiptGrandTotals : $receiptDisplayZeroTotals)) ?></strong>
                        </div>
                    </div>
                </section>

                <section class="receipt-card receipt-card--finance">
        <?php $showReturnedAmount = abs($receiptReturnedAmount) > 0.005; ?>
        <?php if ($currentInvoiceAmountDisplay !== [] || $currentInvoicePaidByReceiptTotals !== [] || $currentInvoiceBalanceDisplay !== []): ?>
                        <div class="receipt-due-line" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:12px;">
                            <div>
                                <span>Current Invoice No.</span>
                                <strong><?= e($bookingReference !== '' ? $bookingReference : 'N/A') ?></strong>
                            </div>
                            <div>
                                <span>Current Invoice Amount</span>
                                <strong><?= e($formatCurrencyTotals($currentInvoiceAmountDisplay !== [] ? $currentInvoiceAmountDisplay : $invoiceReceivableTotals)) ?></strong>
                            </div>
                           <?php if ($previousInvoicePaymentsDisplay !== []): ?>
    <div>
        <span><?= e($previousInvoicePaymentsLabel) ?></span>
        <strong><?= e($formatCurrencyTotals($previousInvoicePaymentsDisplay)) ?></strong>
    </div>
<?php endif; ?>
                            <div>
                                <span>Current Invoice Payment</span>
                                <strong><?= e($formatCurrencyTotals($currentInvoicePaidByReceiptTotals !== [] ? $currentInvoicePaidByReceiptTotals : $receiptDisplayZeroTotals)) ?></strong>
                            </div>
                            <div>
                                <span>Total Paid</span>
                                <strong><?= e($formatCurrencyTotals($totalPaidAgainstInvoiceDisplay !== [] ? $totalPaidAgainstInvoiceDisplay : $receiptDisplayZeroTotals)) ?></strong>
                            </div>
                        </div>
                        <div class="receipt-due-line" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px;align-items:end;">
                            <div>
                                <span>Invoice Outstanding Balance</span>
                                <strong><?= e($formatCurrencyTotals($currentInvoiceBalanceDisplay !== [] ? $currentInvoiceBalanceDisplay : $receiptDisplayZeroTotals)) ?></strong>
                            </div>
                            <?php if (! $receiptIsPassengerSpecific && $remainingCustomerBalanceTotals !== []): ?>
                                <div>
                                    <span>Customer Total Outstanding Balance</span>
                                    <strong><?= e($formatCurrencyTotals($remainingCustomerBalanceTotals)) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($showPassengerOutstandingBalance): ?>
                                <div>
                                    <span>Passenger Outstanding Balance</span>
                                    <strong><?= e($formatCurrencyTotals($passengerOutstandingBalanceTotals)) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($previousBalancePaidByReceiptDisplay !== []): ?>
                                <div>
                                    <span>Paid Against Previous Balance</span>
                                    <strong><?= e($formatCurrencyTotals($previousBalancePaidByReceiptDisplay)) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($showReturnedAmount): ?>
                                <div>
                                    <span>Return to Customer</span>
                                    <strong><?= e($receiptCurrency) ?> <?= e($formatMoney($receiptReturnedAmount)) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($dueDateForReceipt !== '' && $currentInvoiceBalanceDisplay !== []): ?>
                                <div>
                                    <span>Due Date</span>
                                    <strong><?= e($dueDateForReceipt) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($showOutputDebug): ?>
                    <div style="margin-top:12px;padding:10px;border:1px dashed #c96;background:#fff8ef;font-size:11px;white-space:pre-wrap;">
Receipt debug
receiptCurrency: <?= e($receiptCurrency) . "\n" ?>
invoiceReceivableTotals: <?= e(json_encode($invoiceReceivableTotals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n" ?>
invoiceOutstandingTotals: <?= e(json_encode($invoiceOutstandingTotals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n" ?>
invoiceReceivedTotals: <?= e(json_encode($invoiceReceivedTotals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n" ?>
serviceCurrencyFallbackTotal: <?= e($formatMoney($serviceCurrencyFallbackTotal)) . "\n" ?>
receiptCurrencyReceivedTotal: <?= e($formatMoney($receiptCurrencyReceivedTotal)) . "\n" ?>
serviceRowsCount: <?= e((string) count($services)) . "\n" ?>
services: <?= e(json_encode($receiptDebug['services'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]') . "\n" ?>
openReceivables: <?= e(json_encode($receiptDebug['openReceivables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]') ?>
                    </div>
                    <?php endif; ?>
                </section>

                                <section class="receipt-card">
                    <?php
                    $receiptPaymentHistoryRows = [];

                    foreach ($invoicePaymentHistoryRows as $historyRow) {
                        $receiptNo = trim((string) ($historyRow['receiptNo'] ?? ''));
                        $receiptId = (int) ($historyRow['receiptId'] ?? 0);
                        $historyKey = $receiptId > 0 ? 'id:' . $receiptId : 'no:' . $receiptNo;

                        if ($historyKey === 'no:') {
                            $historyKey = 'row:' . count($receiptPaymentHistoryRows);
                        }

                        if (!isset($receiptPaymentHistoryRows[$historyKey])) {
                            $receiptPaymentHistoryRows[$historyKey] = [
                                'receiptNo' => $receiptNo,
                                'receiptDate' => (string) ($historyRow['receiptDate'] ?? ''),
                                'paymentReceivedDisplay' => $getOriginalReceiptPaymentDisplay($historyRow),
                                'currencyTotals' => [],
                                'isCurrentReceipt' => false,
                            ];
                        }

                        $historyCurrency = (string) ($historyRow['receivableCurrency'] ?? $historyRow['currency'] ?? $receiptCurrency);
                        $historyAmount = (float) ($historyRow['receivableAmountAllocated'] ?? $historyRow['allocatedAmount'] ?? 0);

                        if ($historyCurrency !== '' && abs($historyAmount) > 0.005) {
                            $receiptPaymentHistoryRows[$historyKey]['currencyTotals'][$historyCurrency] =
                                (float) ($receiptPaymentHistoryRows[$historyKey]['currencyTotals'][$historyCurrency] ?? 0) + $historyAmount;
                        }

                        if ($isSelectedReceiptHistoryRow($historyRow)) {
                            $receiptPaymentHistoryRows[$historyKey]['isCurrentReceipt'] = true;
                        }
                    }
                    ?>

                    <h2 style="margin:0 0 12px;">Invoice Payment History</h2>
                    <table class="output-table receipt-table">
                        <thead>
                        <tr>
                            <th>Receipt No.</th>
                            <th>Receipt Date</th>
                            <th>Payment Received</th>
                            <th>Invoice Payment</th>
                            <th>Current Receipt</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($receiptPaymentHistoryRows === []): ?>
                            <tr><td colspan="5">No payment history is available for this invoice yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($receiptPaymentHistoryRows as $historyRow): ?>
                            <tr>
                                <td><?= e((string) ($historyRow['receiptNo'] ?? '')) ?></td>
                                <td><?= e((string) ($historyRow['receiptDate'] ?? '')) ?></td>
                                <td><?= e((string) ($historyRow['paymentReceivedDisplay'] ?? '')) ?></td>
                                <td><?= e($formatCurrencyTotals($historyRow['currencyTotals'] ?? [])) ?></td>
                                <td><?= e(($historyRow['isCurrentReceipt'] ?? false) ? 'Current Receipt' : 'Previous Payment') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <?php if ($outputType === 'customer_receipt' && $reissueEvents !== []): ?>
                    <section class="receipt-card">
                        <h2 style="margin:0 0 12px;">Reissue Adjustments Added to Invoice</h2>
                        <table class="output-table receipt-table">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Service Ref.</th>
                                <th>Old Ticket</th>
                                <th>New Ticket</th>
                                <th>Reissue Charges</th>
                                <th>Reason</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($reissueEvents as $reissueEvent): ?>
                                <?php
                                $reissueCurrency = (string) ($reissueEvent['currency'] ?? $receiptCurrency);
                                $reissueFareDifference = (float) ($reissueEvent['fare_difference_amount'] ?? 0);
                                $reissueServiceFee = (float) ($reissueEvent['service_fee_amount'] ?? 0);
                                $reissueCustomerDelta = round($reissueFareDifference + $reissueServiceFee, 2);
                                if (abs($reissueCustomerDelta) <= 0.005 && ! empty($reissueEvent['payload_json'])) {
                                    $reissuePayload = json_decode((string) $reissueEvent['payload_json'], true);
                                    if (is_array($reissuePayload)) {
                                        $reissueCustomerDelta = round((float) ($reissuePayload['customer_delta'] ?? 0), 2);
                                    }
                                }
                                ?>
                                <tr>
                                    <td><?= e((string) ($reissueEvent['event_date'] ?? '')) ?></td>
                                    <td><?= e((string) ($reissueEvent['service_line_reference'] ?? '')) ?></td>
                                    <td><?= e((string) (($reissueEvent['original_ticket_number'] ?? '') !== '' ? $reissueEvent['original_ticket_number'] : 'N/A')) ?></td>
                                    <td><?= e((string) (($reissueEvent['new_ticket_number'] ?? '') !== '' ? $reissueEvent['new_ticket_number'] : 'N/A')) ?></td>
                                    <td><strong><?= e($reissueCurrency) ?> <?= e($formatMoney($reissueCustomerDelta)) ?></strong></td>
                                    <td><?= e((string) (($reissueEvent['reason'] ?? '') !== '' ? $reissueEvent['reason'] : 'Reissue adjustment')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($reissueAdjustmentTotals !== []): ?>
                            <div class="receipt-due-line" style="margin-top:12px;">
                                <span>Reissue Charges</span>
                                <strong><?= e($formatCurrencyTotals($reissueAdjustmentTotals)) ?></strong>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <footer class="receipt-sheet__foot">
                    <div class="receipt-signatures">
                        <div class="receipt-signatures__identity">
                            <span>Received By</span>
                            <strong><?= e($receivedBy !== '' ? $receivedBy : 'Authorized Staff') ?></strong>
                        </div>
                        <div class="receipt-sheet__note receipt-signatures__thank-you">Thank you for your business.</div>
                        <?php if ($nobleRouteSignatureSrc !== ''): ?>
                            <img
                                class="receipt-signatures__image"
                                src="<?= e($nobleRouteSignatureSrc) ?>"
                                alt="Authorized signature"
                            >
                        <?php else: ?>
                            <span class="receipt-signatures__image-placeholder" aria-hidden="true"></span>
                        <?php endif; ?>
                        <div class="receipt-sheet__note receipt-sheet__note--muted receipt-signatures__generated">
                            This is a computer-generated receipt.
                        </div>
                    </div>
                    <?php if ($branchDirectory !== []): ?>
                        <div class="receipt-branches">
                            <span>Our branches</span>
                            <?php foreach (array_map($branchDirectoryContactLine, $branchDirectory) as $branchLine): ?>
                                <?php $isNobleRouteBranchLine = str_contains(mb_strtolower((string) ($branchLine['branch'] ?? '')), 'noble route'); ?>
                                <div class="receipt-branches__row<?= $isNobleRouteBranchLine ? ' receipt-branches__row--right' : '' ?>">
                                    <strong class="receipt-branches__name"><?= e((string) ($branchLine['branch'] ?? 'Branch')) ?></strong>
                                    <?php if (trim((string) ($branchLine['contact'] ?? '')) !== ''): ?>
                                        <small class="receipt-branches__contact"><?= e((string) $branchLine['contact']) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="receipt-developer-credit">Developed by CoreLogic IT Solutions - +92-3462331012</div>
                </footer>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'service_refund_receipt' && $selectedRefundEvent !== null): ?>
            <section class="receipt-sheet">
                <header class="receipt-sheet__head">
                    <div class="receipt-sheet__brand-block">
                        <div class="receipt-sheet__branch"><?= e((string) (($branchBranding['receipt_name'] ?? '') !== '' ? $branchBranding['receipt_name'] : ($branchBranding['name'] ?? 'Travel Agency Branch'))) ?></div>
                        <div class="receipt-sheet__meta"><?= e(trim((string) (($branchBranding['city'] ?? '') . ((string) ($branchBranding['country'] ?? '') !== '' ? ', ' . (string) ($branchBranding['country'] ?? '') : '')))) ?></div>
                        <?php if ($primaryContactLicense !== ''): ?>
                            <div class="receipt-sheet__meta">Licence No. <?= e($primaryContactLicense) ?></div>
                        <?php endif; ?>
                        <?php if ($primaryContactAddress !== ''): ?>
                            <div class="receipt-sheet__meta receipt-sheet__address"><?= e($primaryContactAddress) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="receipt-sheet__title-wrap">
                        <div class="receipt-sheet__title">Cancellation / Refund Receipt</div>
                        <?php if ($primaryContactPhones !== [] || $primaryContactEmail !== ''): ?>
                            <div class="receipt-contact-panel">
                                <span>Customer Support</span>
                                <?php foreach (array_slice($primaryContactPhones, 0, 5) as $phoneLine): ?>
                                    <div class="receipt-contact-panel__row">
                                        <strong class="receipt-contact-panel__label"><?= e($phoneLine['label']) ?></strong>
                                        <em class="receipt-contact-panel__value"><?= e($phoneLine['value']) ?></em>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($primaryContactEmail !== ''): ?>
                                    <div class="receipt-contact-panel__row">
                                        <strong class="receipt-contact-panel__label">Email</strong>
                                        <em class="receipt-contact-panel__value"><?= e($primaryContactEmail) ?></em>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </header>

                <section class="receipt-card">
                    <div class="receipt-card__grid">
                        <div><span>Booking / Invoice No.</span><strong><?= e((string) ($booking['booking_reference'] ?? '')) ?></strong></div>
                        <div><span>Refund Date</span><strong><?= e((string) ($selectedRefundEvent['event_date'] ?? '')) ?></strong></div>
                        <div><span>Customer Name</span><strong><?= e($customerName) ?></strong></div>
                        <div><span>Service Line</span><strong><?= e((string) ($selectedRefundEvent['service_line_reference'] ?? 'N/A')) ?></strong></div>
                        <div><span>Payment Method</span><strong><?= e($selectedRefundPaymentMethod) ?></strong></div>
                        <?php if ($selectedRefundDestinationSummary !== ''): ?>
                            <div><span>Customer Bank</span><strong><?= e($selectedRefundDestinationSummary) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($selectedRefundTransferReference !== ''): ?>
                            <div><span>Transaction ID / Ref.</span><strong><?= e($selectedRefundTransferReference) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($selectedRefundCharges > 0): ?>
                            <div><span>Charges</span><strong><?= e((string) ($selectedRefundEvent['currency'] ?? 'PKR')) ?> <?= e($formatMoney($selectedRefundCharges)) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($selectedRefundService !== null): ?>
                            <div><span>Service Type</span><strong><?= e(ucwords((string) ($selectedRefundService['service_type'] ?? 'service'))) ?></strong></div>
                            <div><span>Service Detail</span><strong><?= e($cleanServiceDescription($selectedRefundService)) ?></strong></div>
                            <?php $refundServiceReference = trim((string) (($selectedRefundService['ticket_number'] ?? '') !== '' ? $selectedRefundService['ticket_number'] : ($selectedRefundService['line_reference'] ?? ''))); ?>
                            <?php if ($refundServiceReference !== ''): ?>
                                <div><span>Ticket / Reference No.</span><strong><?= e($refundServiceReference) ?></strong></div>
                            <?php endif; ?>
                            <?php if (trim((string) ($selectedRefundService['pnr'] ?? '')) !== ''): ?>
                                <div><span>PNR</span><strong><?= e((string) $selectedRefundService['pnr']) ?></strong></div>
                            <?php endif; ?>
                            <?php if (trim((string) ($selectedRefundService['airline'] ?? '')) !== ''): ?>
                                <div><span>Airline / Supplier</span><strong><?= e((string) $selectedRefundService['airline']) ?></strong></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="receipt-card receipt-card--finance">
                    <div class="receipt-finance-strip">
                        <div>
                            <span>Customer Refund Paid</span>
                            <strong><?= e((string) ($selectedRefundEvent['currency'] ?? 'PKR')) ?> <?= e($formatMoney((float) ($selectedRefundEvent['customer_refund_amount'] ?? 0))) ?></strong>
                        </div>
                    </div>
                </section>

                <section class="receipt-card">
                    <div class="receipt-card__grid">
                        <div><span>Document Type</span><strong>Service Refund</strong></div>
                        <div><span>Generated At</span><strong><?= e($generatedAt) ?></strong></div>
                        <div><span>Prepared By</span><strong><?= e($receivedBy) ?></strong></div>
                    </div>
                </section>
                <footer class="receipt-sheet__foot">
                    <div class="receipt-sheet__note">Thank you for your business.</div>
                    <div class="receipt-sheet__note receipt-sheet__note--muted">This is a computer-generated receipt.</div>
                    <?php if ($branchDirectory !== []): ?>
                        <div class="receipt-branches">
                            <span>Our branches</span>
                            <?php foreach (array_map($branchDirectoryContactLine, $branchDirectory) as $branchLine): ?>
                                <?php $isNobleRouteBranchLine = str_contains(mb_strtolower((string) ($branchLine['branch'] ?? '')), 'noble route'); ?>
                                <div class="receipt-branches__row<?= $isNobleRouteBranchLine ? ' receipt-branches__row--right' : '' ?>">
                                    <strong class="receipt-branches__name"><?= e((string) ($branchLine['branch'] ?? 'Branch')) ?></strong>
                                    <?php if (trim((string) ($branchLine['contact'] ?? '')) !== ''): ?>
                                        <small class="receipt-branches__contact"><?= e((string) $branchLine['contact']) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="receipt-developer-credit">Developed by CoreLogic IT Solutions - +92-3462331012</div>
                </footer>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'supplier_voucher' && $selectedSupplierPayment !== null): ?>
            <section class="output-block">
                <h2>
                    Supplier Payment Voucher
                    <?php if ($selectedSupplierPaymentIsVoid): ?>
                        <span style="display:inline-block;margin-left:10px;padding:4px 10px;border:1px solid #8b0000;border-radius:999px;background:#fff0f0;color:#8b0000;font-size:12px;font-weight:700;letter-spacing:0.08em;vertical-align:middle;">VOID</span>
                    <?php endif; ?>
                </h2>
                <div class="output-kv-grid">
                    <div><span>Voucher No.</span><strong><?= e((string) ($selectedSupplierPayment['paymentNo'] ?? '')) ?></strong></div>
                    <div><span>Voucher Date</span><strong><?= e((string) ($selectedSupplierPayment['paymentDate'] ?? '')) ?></strong></div>
                    <div><span>Supplier</span><strong><?= e((string) ($selectedSupplierPayment['supplier'] ?? '')) ?></strong></div>
                    <div><span>Currency</span><strong><?= e((string) ($selectedSupplierPayment['currency'] ?? '')) ?></strong></div>
                    <div><span>Amount Paid</span><strong><?= e($formatMoney((float) ($selectedSupplierPayment['paidAmount'] ?? 0))) ?></strong></div>
                    <div><span>Applied to Payables</span><strong><?= e($formatMoney((float) ($selectedSupplierPayment['allocatedAmount'] ?? 0))) ?></strong></div>
                    <div><span>Open Supplier Credit</span><strong><?= e($formatMoney((float) ($selectedSupplierPayment['unallocatedAmount'] ?? 0))) ?></strong></div>
                    <div><span>Payment Method</span><strong><?= e(ucwords(str_replace('_', ' ', (string) ($selectedSupplierPayment['paymentMethod'] ?? '')))) ?></strong></div>
                    <div><span>Reference No.</span><strong><?= e((string) (($selectedSupplierPayment['referenceNumber'] ?? '') !== '' ? $selectedSupplierPayment['referenceNumber'] : 'N/A')) ?></strong></div>
                    <div><span>Status</span><strong><?= e($formatStatusLabel((string) ($selectedSupplierPayment['statusRaw'] ?? $selectedSupplierPayment['status'] ?? ''))) ?></strong></div>
                    <?php if ((float) ($selectedSupplierPayment['exchangeRateToBooking'] ?? 0) > 0): ?>
                        <div><span>Exchange Rate</span><strong><?= e(number_format((float) $selectedSupplierPayment['exchangeRateToBooking'], 8)) ?></strong></div>
                    <?php endif; ?>
                </div>
                <?php if ($selectedSupplierPaymentIsVoid): ?>
                    <div class="output-kv-grid" style="margin-top:14px;padding:14px;border:1px solid #8b0000;background:#fff7f7;">
                        <div><span>Status</span><strong>VOID</strong></div>
                        <?php if ($selectedSupplierPaymentVoidedAt !== ''): ?>
                            <div><span>Voided At</span><strong><?= e($selectedSupplierPaymentVoidedAt) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($selectedSupplierPaymentVoidReason !== ''): ?>
                            <div style="grid-column:1 / -1;"><span>Void Reason</span><strong><?= e($selectedSupplierPaymentVoidReason) ?></strong></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
            <section class="output-block">
                <h2>Applied Supplier Payables</h2>
                <table class="output-table">
                    <thead><tr><th>Allocated At</th><th>Service Line</th><th>Currency</th><th>Allocated Amount</th><th>Note</th></tr></thead>
                    <tbody>
                    <?php $matchingSupplierAllocations = array_values(array_filter($supplierFoundation['paymentAllocations'] ?? [], static fn (array $allocation): bool => (string) ($allocation['paymentNo'] ?? '') === (string) ($selectedSupplierPayment['paymentNo'] ?? ''))); ?>
                    <?php if ($matchingSupplierAllocations === []): ?>
                        <tr><td colspan="5">No supplier payable has been linked to this payment yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($matchingSupplierAllocations as $allocation): ?>
                        <tr>
                            <td><?= e((string) ($allocation['allocatedAt'] ?? '')) ?></td>
                            <td><?= e((string) ($allocation['serviceLineReference'] ?? '')) ?></td>
                            <td><?= e((string) ($allocation['currency'] ?? '')) ?></td>
                            <td><?= e($formatMoney((float) ($allocation['allocatedAmount'] ?? 0))) ?></td>
                            <td><?= e((string) (($allocation['allocationNote'] ?? '') !== '' ? $allocation['allocationNote'] : 'N/A')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'account_statement'): ?>
            <section class="output-block">
                <h2>Statement Summary</h2>
                <div class="output-summary-strip">
                    <div><span>Invoice Amount</span><strong><?= e($formatCurrencyTotals($invoiceReceivableTotals)) ?></strong></div>
                    <?php if ($statementTenderedTotals !== []): ?>
                        <div><span>Amount Received</span><strong><?= e($formatCurrencyTotals($statementTenderedTotals)) ?></strong></div>
                    <?php endif; ?>
                    <?php if ($statementAdvanceAppliedTotals !== []): ?>
                        <div><span>Advance Applied</span><strong><?= e($formatCurrencyTotals($statementAdvanceAppliedTotals)) ?></strong></div>
                    <?php endif; ?>
                    <?php if ($statementPreviousBalanceTotals !== []): ?>
                        <div><span>Previous Balance</span><strong><?= e($formatCurrencyTotals($statementPreviousBalanceTotals)) ?></strong></div>
                    <?php endif; ?>
                    <?php if ($statementReturnedTotals !== []): ?>
                        <div><span>Returned / Refunded</span><strong><?= e($formatCurrencyTotals($statementReturnedTotals)) ?></strong></div>
                    <?php endif; ?>
                    <div><span>Amount Applied</span><strong><?= e($formatCurrencyTotals($invoiceReceivedTotals)) ?></strong></div>
                    <?php if ($remainingCustomerBalanceTotals !== [] || $invoiceReceivableTotals !== []): ?>
                        <div><span>Outstanding Balance</span><strong><?= e($formatCurrencyTotals($remainingCustomerBalanceTotals !== [] ? $remainingCustomerBalanceTotals : ['PKR' => 0])) ?></strong></div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="output-block">
                <h2>Invoice Exposure</h2>
                <table class="output-table">
                    <thead><tr><th>Service Line</th><th>Passenger</th><th>Service</th><th>Currency</th><th>Invoice Amount</th><th>Applied</th><th>Outstanding</th><th>Status</th><th>Due Date</th></tr></thead>
                    <tbody>
                    <?php foreach (($customerPaymentFoundation['serviceReceivables'] ?? []) as $receivable): ?>
                        <tr>
                            <td><?= e((string) ($receivable['serviceLineReference'] ?? '')) ?></td>
                            <td><?= e((string) (($receivable['passengerName'] ?? '') !== '' ? $receivable['passengerName'] : ($booking['lead_traveler_name'] ?? 'Passenger'))) ?></td>
                            <td><?= e(ucwords((string) ($receivable['serviceType'] ?? 'Service'))) ?></td>
                            <td><?= e((string) ($receivable['currency'] ?? '')) ?></td>
                            <td><?= e($formatMoney((float) ($receivable['dueAmount'] ?? 0))) ?></td>
                            <td><?= e($formatMoney((float) ($receivable['allocatedAmount'] ?? 0))) ?></td>
                            <td><?= e($formatMoney((float) ($receivable['outstandingAmount'] ?? 0))) ?></td>
                            <td><?= e((string) ($receivable['status'] ?? '')) ?></td>
                            <td><?= e((string) ($receivable['nextDueDate'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (($customerPaymentFoundation['serviceReceivables'] ?? []) === []): ?><tr><td colspan="9">No receivable entries are available for this booking yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Receipt History</h2>
                <table class="output-table">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Curr.</th><th>Amount Received</th><th>Returned / Refunded</th><th>Applied</th><th>Method</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (($customerPaymentFoundation['receipts'] ?? []) === [] && $statementAdvanceReceiptRows === []): ?>
                        <tr><td colspan="8">No receipts recorded against this booking yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach (($customerPaymentFoundation['receipts'] ?? []) as $receipt): ?>
                        <?php
                        $statementReceiptId = (int) ($receipt['id'] ?? 0);
                        $statementReceiptIsInternalAdjustment = $statementReceiptId > 0 && isset($statementInternalAdjustmentReceiptIds[$statementReceiptId]);
                        $statementReceiptTendered = (float) ($receipt['tenderedAmount'] ?? $receipt['receivedAmount'] ?? 0);
                        $statementReceiptReturned = (float) ($receipt['returnedAmount'] ?? 0);
                        $statementReceiptMethod = $statementReceiptIsInternalAdjustment
                            ? (string) ($statementInternalAdjustmentReceiptLabels[$statementReceiptId] ?? 'Settlement Adjustment')
                            : ucwords(str_replace('_', ' ', (string) ($receipt['paymentMethod'] ?? '')));
                        $statementReceiptStatus = $statementReceiptIsInternalAdjustment
                            ? 'Internal Adjustment'
                            : $formatStatusLabel((string) ($receipt['statusRaw'] ?? $receipt['status'] ?? ''));
                        ?>
                        <tr>
                            <td><?= e((string) ($receipt['receiptNo'] ?? '')) ?></td>
                            <td><?= e((string) ($receipt['receiptDate'] ?? '')) ?></td>
                            <td><?= e((string) ($receipt['currency'] ?? '')) ?></td>
                            <td><?= e($formatMoney($statementReceiptIsInternalAdjustment ? 0.0 : $statementReceiptTendered)) ?></td>
                            <td><?= e($formatMoney($statementReceiptReturned)) ?></td>
                            <td><?= e($formatMoney((float) ($receipt['allocatedAmount'] ?? 0))) ?></td>
                            <td><?= e($statementReceiptMethod) ?></td>
                            <td><?= e($statementReceiptStatus) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($statementAdvanceReceiptRows as $advanceReceiptRow): ?>
                        <tr>
                            <td><?= e((string) ($advanceReceiptRow['receiptNo'] ?? 'Advance')) ?></td>
                            <td><?= e((string) ($advanceReceiptRow['receiptDate'] ?? '')) ?></td>
                            <td><?= e((string) ($advanceReceiptRow['currency'] ?? '')) ?></td>
                            <td><?= e($formatMoney(0)) ?></td>
                            <td><?= e($formatMoney(0)) ?></td>
                            <td><?= e($formatMoney((float) ($advanceReceiptRow['allocatedAmount'] ?? 0))) ?></td>
                            <td>Customer Advance</td>
                            <td>Advance Applied</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Receipt Allocation Details</h2>
                <table class="output-table">
                    <thead><tr><th>Receipt</th><th>Allocated At</th><th>Allocation Type</th><th>Booking / Invoice No.</th><th>Service Line</th><th>Service Type</th><th>Passenger</th><th>Currency</th><th>Allocated</th><th>Remaining After This Allocation</th></tr></thead>
                    <tbody>
                    <?php if ($statementInvoiceAllocationRows === []): ?>
                        <tr><td colspan="10">No receipt allocation rows are available for this booking yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($statementInvoiceAllocationRows as $allocation): ?>
                        <?php
                        $allocationCurrency = (string) ($allocation['receivableCurrency'] ?? $allocation['currency'] ?? 'PKR');
                        $allocationPassenger = trim((string) ($allocation['passengerName'] ?? ''));
                        $allocationReceiptStatusRaw = str_replace(' ', '_', mb_strtolower(trim((string) ($allocation['receiptStatusRaw'] ?? ''))));
                        $allocationServiceLineReference = trim((string) ($allocation['serviceLineReference'] ?? $allocation['service_line_reference'] ?? ''));
                        $allocationCurrentRemainingKey = $allocationServiceLineReference . '|' . $allocationCurrency;
                        $allocationCurrentRemaining = $allocationServiceLineReference !== ''
                            ? (float) ($statementRemainingByServiceLineCurrency[$allocationCurrentRemainingKey] ?? 0.0)
                            : (float) ($allocation['remainingAfterAllocation'] ?? $allocation['remaining_after_allocation'] ?? 0);
                        ?>
                        <tr>
                            <td><?= e((string) ($allocation['receiptNo'] ?? '')) ?></td>
                            <td><?= e((string) ($allocation['allocatedAt'] ?? '')) ?></td>
                            <td><?= e((string) ($allocation['allocationType'] ?? 'Allocated')) ?></td>
                            <td><?= e((string) ($allocation['bookingReference'] ?? 'N/A')) ?></td>
                            <td><?= e($allocationServiceLineReference !== '' ? $allocationServiceLineReference : 'N/A') ?></td>
                            <td><?= e((string) ($allocation['serviceType'] ?? 'Service')) ?></td>
                            <td><?= e($allocationPassenger !== '' ? $allocationPassenger : $customerName) ?></td>
                            <td><?= e($allocationCurrency) ?></td>
                            <td><?= e($allocationCurrency) ?> <?= e($formatMoney((float) ($allocation['receivableAmountAllocated'] ?? $allocation['allocatedAmount'] ?? 0))) ?></td>
                            <td><?php if ($allocationReceiptStatusRaw === 'void'): ?>VOIDED<?php else: ?><?= e($allocationCurrency) ?> <?= e($formatMoney($allocationCurrentRemaining)) ?><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'itinerary'): ?>
            <section class="output-block">
                <h2>Passenger Details</h2>
                <table class="output-table">
                    <thead><tr><th>Role</th><th>Name</th><th>Passport</th><th>Nationality</th><th>DOB</th><th>Mobile</th></tr></thead>
                    <tbody>
                    <?php foreach ($travelers as $traveler): ?>
                        <tr>
                            <td><?= e(ucfirst((string) ($traveler['traveler_role'] ?? 'additional'))) ?></td>
                            <td><?= e((string) ($traveler['full_name'] ?? '')) ?></td>
                            <td><?= e((string) (($traveler['passport_number'] ?? '') !== '' ? $traveler['passport_number'] : 'N/A')) ?></td>
                            <td><?= e((string) (($traveler['nationality'] ?? '') !== '' ? $traveler['nationality'] : 'N/A')) ?></td>
                            <td><?= e((string) (($traveler['date_of_birth'] ?? '') !== '' ? $traveler['date_of_birth'] : 'N/A')) ?></td>
                            <td><?= e((string) (($traveler['mobile'] ?? '') !== '' ? $traveler['mobile'] : 'N/A')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($travelers === []): ?><tr><td colspan="6">No passenger details are linked to this booking yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Travel Itinerary</h2>
                <table class="output-table">
                    <thead><tr><th>Line</th><th>Service</th><th>Passenger</th><th>Sector / Destination</th><th>Departure</th><th>Return</th><th>Reference</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><?= e((string) ($service['line_reference'] ?? '')) ?></td>
                            <td><?= e(ucwords((string) ($service['service_type'] ?? 'service'))) ?></td>
                            <td><?= e((string) (($service['passenger_name'] ?? '') !== '' ? $service['passenger_name'] : $customerName)) ?></td>
                            <td><?= e($cleanServiceDescription($service)) ?></td>
                            <td><?= e((string) (($service['departure_date'] ?? '') !== '' ? $service['departure_date'] : (($service['due_date'] ?? '') !== '' ? $service['due_date'] : 'N/A'))) ?></td>
                            <td><?= e((string) (($service['return_date'] ?? '') !== '' ? $service['return_date'] : (($booking['return_date'] ?? '') !== '' ? $booking['return_date'] : 'N/A'))) ?></td>
                            <td><?= e((string) (($service['ticket_number'] ?? '') !== '' ? $service['ticket_number'] : ($service['line_reference'] ?? 'N/A'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($services === []): ?><tr><td colspan="7">No travel services are linked to this booking yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($outputType === 'booking_confirmation'): ?>
            <section class="output-block">
                <h2>Confirmed Services</h2>
                <table class="output-table">
                    <thead><tr><th>Line</th><th>Service</th><th>Passenger</th><th>Travel Window</th><th>Reference</th><th>Currency</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><?= e((string) ($service['line_reference'] ?? '')) ?></td>
                            <td><?= e(ucwords((string) ($service['service_type'] ?? 'service'))) ?></td>
                            <td><?= e((string) (($service['passenger_name'] ?? '') !== '' ? $service['passenger_name'] : $customerName)) ?></td>
                            <td><?php $window = trim((string) (($service['departure_date'] ?? $booking['departure_date'] ?? '') . (((string) ($service['return_date'] ?? $booking['return_date'] ?? '') !== '') ? ' to ' . (string) ($service['return_date'] ?? $booking['return_date'] ?? '') : ''))); echo e($window !== '' ? $window : 'As per booking file'); ?></td>
                            <td><?= e((string) (($service['ticket_number'] ?? '') !== '' ? $service['ticket_number'] : ($service['line_reference'] ?? 'N/A'))) ?></td>
                            <td><?= e((string) ($service['currency'] ?? 'PKR')) ?></td>
                            <td><?= e($formatMoney($serviceReceivableAmount($service))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($services === []): ?><tr><td colspan="7">No confirmed service lines are available for this booking yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
            <section class="output-block">
                <h2>Booking Notes</h2>
                <div class="output-note-box">
                    <p>Please review passenger names, passport details, and travel dates before final handover.</p>
                    <p>Service references and travel windows shown here reflect the current saved booking record.</p>
                    <?php if (trim((string) ($booking['remarks'] ?? '')) !== ''): ?><p><?= e((string) $booking['remarks']) ?></p><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (! $usesReceiptLayout): ?>
            <footer class="output-foot">
                <div><?= e((string) ($branchBranding['name'] ?? 'Travel Agency')) ?> / <?= e((string) ($booking['booking_reference'] ?? '')) ?></div>
                <div><?= e($generatedAt) ?></div>
            </footer>
        <?php endif; ?>
    <?php if (! $usesReceiptLayout): ?>
        </section>
    <?php endif; ?>
</main>
<?php if (! empty($autoPrint)): ?>
    <script>
        window.addEventListener('load', async function () {
            const pendingImages = Array.from(document.images).filter(function (image) {
                return !image.complete;
            });
            await Promise.all(pendingImages.map(function (image) {
                return new Promise(function (resolve) {
                    image.addEventListener('load', resolve, { once: true });
                    image.addEventListener('error', resolve, { once: true });
                });
            }));
            if (document.fonts && document.fonts.ready) {
                await document.fonts.ready;
            }
            window.requestAnimationFrame(function () {
                window.setTimeout(function () {
                    window.print();
                }, 60);
            });
        });
    </script>
<?php endif; ?>
