<?php

$accessibleBranchIds = array_map('intval', $accessibleBranchIds ?? []);
$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$currentBookingRecord = is_array($currentBookingRecord ?? null) ? $currentBookingRecord : null;
$bookingSearchResults = is_array($bookingSearchResults ?? null) ? $bookingSearchResults : [];
$bookingTravelers = is_array($bookingTravelers ?? null) ? $bookingTravelers : [];
$selectedTravelerProfile = is_array($selectedTravelerProfile ?? null) ? $selectedTravelerProfile : null;
$bookingServices = is_array($bookingServices ?? null) ? $bookingServices : [];
$currentSearchTerm = trim((string) ($currentSearchTerm ?? ''));
$travelerSearchResults = is_array($travelerSearchResults ?? null) ? $travelerSearchResults : [];
$travelerSearchTerm = trim((string) ($travelerSearchTerm ?? ''));
$serviceSupplierOptions = is_array($serviceSupplierOptions ?? null) ? $serviceSupplierOptions : [];
$businessSourceOptions = is_array($businessSourceOptions ?? null) ? $businessSourceOptions : [];
$supplierModeOptions = is_array($supplierModeOptions ?? null) ? $supplierModeOptions : [
    'normal_payable' => 'Normal Payable',
    'running_balance' => 'Running Balance',
];
$documents = is_array($documents ?? null) ? $documents : [];
$documentLinkTargets = is_array($documentLinkTargets ?? null) ? $documentLinkTargets : [];
$documentTypeDefinitions = is_array($documentTypeDefinitions ?? null) ? $documentTypeDefinitions : [];
$documentTargetOptionsByType = is_array($documentTargetOptionsByType ?? null) ? $documentTargetOptionsByType : [];
$replaceableDocuments = is_array($replaceableDocuments ?? null) ? $replaceableDocuments : [];
$reminders = is_array($reminders ?? null) ? $reminders : [];
$receivableAlerts = is_array($receivableAlerts ?? null) ? $receivableAlerts : [];
$reminderTypeOptions = is_array($reminderTypeOptions ?? null) ? $reminderTypeOptions : [
    'due_date' => 'Due Date Reminder',
    'passport_expiry' => 'Passport Expiry Reminder',
    'visa_expiry' => 'Visa Expiry Reminder',
    'supplier_payment' => 'Supplier Payment Reminder',
    'document_missing' => 'Document Missing Reminder',
    'travel_date' => 'Travel Date Reminder',
    'custom_manual' => 'Custom Manual Reminder',
];
$reminderChannels = is_array($reminderChannels ?? null) ? $reminderChannels : ['Call', 'WhatsApp', 'Email', 'Counter Follow-Up', 'System'];
$reminderLinkTargets = is_array($reminderLinkTargets ?? null) ? $reminderLinkTargets : [];
$editingReminder = is_array($editingReminder ?? null) ? $editingReminder : null;
$recentBookings = is_array($recentBookings ?? null) ? $recentBookings : [];
$serviceSaveDebug = is_array($serviceSaveDebug ?? null) ? $serviceSaveDebug : null;
$workspaceIsNew = (bool) ($workspaceIsNew ?? false);
$canPostServiceEvents = in_array((string) (($user ?? [])['roleCode'] ?? ($user ?? [])['role_code'] ?? ''), ['super_admin', 'branch_admin'], true);
$receivableAlertCounts = is_array($receivableAlerts['counts'] ?? null) ? $receivableAlerts['counts'] : [
    'overdue' => 0,
    'dueToday' => 0,
    'pending' => 0,
    'missingDueDate' => 0,
    'total' => 0,
];

$branchDirectory = [];
foreach ($branchOptions as $branchRow) {
    $branchDirectory[(int) $branchRow['id']] = trim((string) $branchRow['name'] . (! empty($branchRow['city']) ? ', ' . $branchRow['city'] : ''));
}

$accessibleBranches = array_values(array_filter(array_map(
    static fn (int $branchId): string => $branchDirectory[$branchId] ?? ('Branch ' . $branchId),
    $accessibleBranchIds
)));
$accessibleBranchLabel = $accessibleBranches !== [] ? implode(' | ', $accessibleBranches) : 'Restricted';
$branchBaseCurrencyDirectory = [];
foreach ($branchOptions as $branchRow) {
    $branchBaseCurrencyDirectory[(int) $branchRow['id']] = strtoupper(trim((string) ($branchRow['base_currency'] ?? 'PKR'))) ?: 'PKR';
}

$bookingStatusOptions = [
    'draft' => 'Draft',
    'open' => 'Open',
    'confirmed' => 'Confirmed',
    'on_hold' => 'On Hold',
    'closed' => 'Closed',
];
$serviceTypeOptions = is_array($serviceTypeOptions ?? null) ? $serviceTypeOptions : [
    'air ticket' => 'Air Ticket',
    'visa' => 'Visa',
    'umrah' => 'Umrah',
    'tourism' => 'Tourism',
    'hotel' => 'Hotel',
    'transport' => 'Transport',
    'other' => 'Other Package',
];
$serviceStatusOptions = ['Booked', 'Docs Pending', 'Reserved', 'Open', 'Delivered', 'Cancelled'];
$paymentMethodOptions = is_array($paymentMethodOptions ?? null) ? $paymentMethodOptions : [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'debit_card' => 'Debit Card',
    'credit_card' => 'Credit Card',
];
$documentTypeOptions = is_array($documentTypeOptions ?? null) ? $documentTypeOptions : [
    'passport_copy' => 'Passport Copy',
    'visa_copy' => 'Visa Copy',
    'ticket_copy' => 'Ticket Copy',
    'payment_proof' => 'Payment Proof',
    'supplier_invoice' => 'Supplier Invoice / Supporting Doc',
    'general_attachment' => 'General Attachment',
];
$printFormats = ['A4 Portrait', 'A4 Landscape', 'Thermal Receipt'];

$sessionActiveBranchId = (int) (($user['activeBranchId'] ?? $user['active_branch_id'] ?? 0));
$activeBranchId = (int) ($currentBookingRecord['branch_id'] ?? ($sessionActiveBranchId > 0 ? $sessionActiveBranchId : ($selectedTravelerProfile['branch_id'] ?? ($accessibleBranchIds[0] ?? 0))));
$activeBranchLabel = $branchDirectory[$activeBranchId] ?? 'Select Branch';
$activeBranchBaseCurrency = strtoupper(trim((string) ($currentBookingRecord['base_currency'] ?? ($branchBaseCurrencyDirectory[$activeBranchId] ?? 'PKR'))));
if ($activeBranchBaseCurrency === '') {
    $activeBranchBaseCurrency = 'PKR';
}
$resolvedLeadTravelerProfile = $selectedTravelerProfile;
if ($resolvedLeadTravelerProfile === null) {
    $currentLeadTravelerId = (int) ($currentBookingRecord['lead_traveler_id'] ?? 0);
    if ($currentLeadTravelerId > 0) {
        foreach ($bookingTravelers as $bookingTravelerRow) {
            if ((int) ($bookingTravelerRow['id'] ?? 0) === $currentLeadTravelerId) {
                $resolvedLeadTravelerProfile = $bookingTravelerRow;
                break;
            }
        }
    }
}
$leadTravelerName = trim((string) (($resolvedLeadTravelerProfile['full_name'] ?? '') !== ''
    ? $resolvedLeadTravelerProfile['full_name']
    : ($currentBookingRecord['lead_traveler_name'] ?? ($selectedTravelerProfile['full_name'] ?? ''))));
$contactMobile = trim((string) (($resolvedLeadTravelerProfile['mobile'] ?? '') !== ''
    ? $resolvedLeadTravelerProfile['mobile']
    : ($currentBookingRecord['contact_mobile'] ?? ($selectedTravelerProfile['mobile'] ?? ''))));
$passportNumber = trim((string) (($resolvedLeadTravelerProfile['passport_number'] ?? '') !== ''
    ? $resolvedLeadTravelerProfile['passport_number']
    : ($currentBookingRecord['passport_number'] ?? ($selectedTravelerProfile['passport_number'] ?? ''))));
$bookingRemarks = trim((string) ($currentBookingRecord['remarks'] ?? ''));
$partyNotes = trim((string) ($currentBookingRecord['party_notes'] ?? ''));

$workspaceBooking = [
    'id' => (int) ($currentBookingRecord['id'] ?? 0),
    'number' => (string) ($currentBookingRecord['booking_reference'] ?? 'Auto on Save'),
    'branchId' => $activeBranchId,
    'businessSourceId' => (int) ($currentBookingRecord['business_source_id'] ?? 0),
    'businessSourceName' => (string) ($currentBookingRecord['business_source_name'] ?? ''),
    'businessSourcePhone' => (string) ($currentBookingRecord['business_source_phone'] ?? ''),
    'businessSourceAddress' => (string) ($currentBookingRecord['business_source_address'] ?? ''),
    'businessSourceDescription' => (string) ($currentBookingRecord['business_source_description'] ?? ''),
    'branch' => $activeBranchLabel,
    'bookingDate' => (string) ($currentBookingRecord['booking_date'] ?? date('Y-m-d')),
    'dueDate' => (string) ($currentBookingRecord['due_date'] ?? ''),
    'departureDate' => (string) ($currentBookingRecord['departure_date'] ?? ''),
    'returnDate' => (string) ($currentBookingRecord['return_date'] ?? ''),
    'status' => (string) ($currentBookingRecord['booking_status'] ?? 'draft'),
    'partyLabel' => (string) ($currentBookingRecord['party_label'] ?? 'Lead Traveler / Booking Party'),
    'lead' => $leadTravelerName,
    'mobile' => $contactMobile,
    'passport' => $passportNumber,
    'defaultCurrency' => $activeBranchBaseCurrency,
    'serviceMix' => 'No services yet',
    'currency' => 'Awaiting first service',
    'totalSale' => 'PKR 0',
    'totalCost' => 'PKR 0',
    'profitLoss' => 'PKR 0',
    'totalReceivable' => 'PKR 0',
    'totalPayable' => 'PKR 0',
    'totalReceived' => 'PKR 0',
    'totalOutstanding' => 'PKR 0',
    'totalSupplierPaid' => 'PKR 0',
    'totalSupplierOutstanding' => 'PKR 0',
    'remarks' => $bookingRemarks,
    'partyNotes' => $partyNotes,
    'createdAt' => (string) ($currentBookingRecord['created_at'] ?? ''),
    'updatedAt' => (string) ($currentBookingRecord['updated_at'] ?? ''),
];

$serviceLines = array_map(static function (array $serviceRow): array {
    $isAirTicket = (string) ($serviceRow['service_type'] ?? 'air ticket') === 'air ticket';
    $invoiceCurrency = (string) ($serviceRow['currency'] ?? 'PKR');
    $costCurrency = (string) ($serviceRow['cost_currency'] ?? $invoiceCurrency);
    $pricingExchangeRate = (float) ($serviceRow['pricing_exchange_rate'] ?? 1);
    $convertedPurchaseCost = round(
        (float) ($serviceRow['purchase_cost'] ?? 0) * ($invoiceCurrency === $costCurrency ? 1 : max($pricingExchangeRate, 0)),
        2
    );
    $derivedFinalSalePrice = $isAirTicket
        ? $convertedPurchaseCost + (float) ($serviceRow['service_charge'] ?? 0) + (float) ($serviceRow['vat'] ?? 0) - (float) ($serviceRow['discount_amount'] ?? 0)
        : (float) ($serviceRow['sale_price'] ?? 0) + (float) ($serviceRow['service_charge'] ?? 0) + (float) ($serviceRow['vat'] ?? 0) - (float) ($serviceRow['discount_amount'] ?? 0);
    $finalSalePrice = array_key_exists('final_sale_price', $serviceRow) && $serviceRow['final_sale_price'] !== null
        ? (float) $serviceRow['final_sale_price']
        : $derivedFinalSalePrice;
    $profit = array_key_exists('net_profit_loss', $serviceRow)
        ? (float) ($serviceRow['net_profit_loss'] ?? 0)
        : round($finalSalePrice - $convertedPurchaseCost, 0);

    return [
        'serviceId' => (int) ($serviceRow['id'] ?? 0),
        'lineNumber' => (string) ($serviceRow['line_reference'] ?? 'SV-DRAFT'),
        'type' => (string) ($serviceRow['service_type'] ?? 'air ticket'),
        'supplierId' => (int) ($serviceRow['supplier_id'] ?? 0),
        'supplier' => (string) ($serviceRow['supplier_name'] ?? $serviceRow['supplier_name_snapshot'] ?? 'Supplier not selected'),
        'travelerId' => (int) ($serviceRow['traveler_id'] ?? 0),
        'passengerName' => (string) ($serviceRow['passenger_name'] ?? $serviceRow['passenger_name_snapshot'] ?? ''),
        'currency' => $invoiceCurrency,
        'costCurrency' => $costCurrency,
        'salePrice' => (float) ($serviceRow['sale_price'] ?? 0),
        'purchaseCost' => (float) ($serviceRow['purchase_cost'] ?? 0),
        'pricingExchangeRate' => $pricingExchangeRate > 0 ? round($pricingExchangeRate, 8) : 1.0,
        'pricingRateEffectiveDate' => (string) ($serviceRow['pricing_rate_effective_date'] ?? ''),
        'taxes' => (float) ($serviceRow['taxes'] ?? 0),
        'otherFare' => (float) ($serviceRow['other_fare'] ?? 0),
        'sotoFare' => (float) ($serviceRow['soto_fare'] ?? 0),
        'spyiAmount' => (float) ($serviceRow['spyi_amount'] ?? 0),
        'aqYrPkAmount' => (float) ($serviceRow['aq_yr_pk_amount'] ?? 0),
        'yqAmount' => (float) ($serviceRow['yq_amount'] ?? 0),
        'othAmount' => (float) ($serviceRow['oth_amount'] ?? 0),
        'vatInput' => (float) ($serviceRow['vat_input'] ?? 0),
        'vat' => (float) ($serviceRow['vat'] ?? 0),
        'commission' => (float) ($serviceRow['commission'] ?? 0),
        'serviceCharge' => (float) ($serviceRow['service_charge'] ?? 0),
        'discountAmount' => (float) ($serviceRow['discount_amount'] ?? 0),
        'finalSalePrice' => $finalSalePrice,
        'netProfitLoss' => $profit,
        'dueDate' => (string) ($serviceRow['due_date'] ?? ''),
        'status' => (string) ($serviceRow['service_status'] ?? 'Open'),
        'displayStatus' => (string) (($serviceRow['is_active'] ?? 1) ? ($serviceRow['service_status'] ?? 'Open') : 'Inactive'),
        'latestRefundEventId' => (int) ($serviceRow['latest_refund_event_id'] ?? 0),
        'latestCustomerRefundEventId' => (int) ($serviceRow['latest_customer_refund_event_id'] ?? 0),
        'latestCustomerRefundAmountOnly' => (float) ($serviceRow['latest_customer_refund_amount_only'] ?? 0),
        'latestSupplierRefundEventId' => (int) ($serviceRow['latest_supplier_refund_event_id'] ?? 0),
        'latestSupplierRefundAmountOnly' => (float) ($serviceRow['latest_supplier_refund_amount_only'] ?? 0),
        'latestRefundEventDate' => (string) ($serviceRow['latest_refund_event_date'] ?? ''),
        'latestRefundCustomerAmount' => (float) ($serviceRow['latest_refund_customer_amount'] ?? 0),
        'latestRefundSupplierAmount' => (float) ($serviceRow['latest_refund_supplier_amount'] ?? 0),
        'latestCancelEventId' => (int) ($serviceRow['latest_cancel_event_id'] ?? 0),
        'hasCancellationEvent' => (int) ($serviceRow['latest_cancel_event_id'] ?? 0) > 0,
        'latestCancelFinanciallySettled' => (bool) ($serviceRow['latest_cancel_financially_settled'] ?? false),
        'latestCancelCustomerPenaltyAmount' => (float) ($serviceRow['latest_cancel_customer_penalty_amount'] ?? 0),
        'latestCancelSupplierPenaltyAmount' => (float) ($serviceRow['latest_cancel_supplier_penalty_amount'] ?? 0),
        'latestCancelExpectedSupplierRefundAmount' => (float) ($serviceRow['latest_cancel_expected_supplier_refund_amount'] ?? 0),
        'latestCancelReleasedCustomerCreditAmount' => (float) ($serviceRow['latest_cancel_released_customer_credit_amount'] ?? 0),
        'latestCancelReleasedSupplierCreditAmount' => (float) ($serviceRow['latest_cancel_released_supplier_credit_amount'] ?? 0),
        'customerRefundableCreditAmount' => (float) ($serviceRow['customer_refundable_credit_amount'] ?? 0),
        'supplierRefundableCreditAmount' => (float) ($serviceRow['supplier_refundable_credit_amount'] ?? 0),
        'customerRefundReceivedAmount' => (float) ($serviceRow['customer_refund_received_amount'] ?? 0),
        'remarks' => (string) ($serviceRow['remarks'] ?? ''),
        'lossReason' => (string) ($serviceRow['loss_reason'] ?? ''),
        'pnr' => (string) ($serviceRow['pnr'] ?? ''),
        'ticketNumber' => (string) ($serviceRow['ticket_number'] ?? ''),
        'airline' => (string) ($serviceRow['airline'] ?? ''),
        'sectorFrom' => (string) ($serviceRow['sector_from'] ?? ''),
        'sectorTo' => (string) ($serviceRow['sector_to'] ?? ''),
        'departureDate' => (string) ($serviceRow['departure_date'] ?? ''),
        'returnDate' => (string) ($serviceRow['return_date'] ?? ''),
        'class' => (string) ($serviceRow['travel_class'] ?? ''),
        'fare' => (float) ($serviceRow['fare'] ?? 0),
        'ticketTax' => (float) ($serviceRow['ticket_tax'] ?? 0),
        'ticketVat' => (float) ($serviceRow['ticket_vat'] ?? 0),
        'ticketCommission' => (float) ($serviceRow['ticket_commission'] ?? 0),
        'supplierCost' => (float) ($serviceRow['supplier_cost'] ?? 0),
        'saleAmount' => (float) ($serviceRow['sale_amount'] ?? 0),
        'ticketRemarks' => (string) ($serviceRow['ticket_remarks'] ?? ''),
        'visaCountry' => (string) ($serviceRow['visa_country'] ?? ''),
        'visaType' => (string) ($serviceRow['visa_type'] ?? ''),
        'visaApplicationReference' => (string) ($serviceRow['visa_application_reference'] ?? ''),
        'visaPassportNumber' => (string) ($serviceRow['visa_passport_number'] ?? ''),
        'visaSubmissionDate' => (string) ($serviceRow['visa_submission_date'] ?? ''),
        'visaIssueDate' => (string) ($serviceRow['visa_issue_date'] ?? ''),
        'visaExpiryDate' => (string) ($serviceRow['visa_expiry_date'] ?? ''),
        'visaStatus' => (string) ($serviceRow['visa_status'] ?? ''),
        'visaRemarks' => (string) ($serviceRow['visa_remarks'] ?? ''),
        'umrahPackageName' => (string) ($serviceRow['umrah_package_name'] ?? ''),
        'umrahMofaReference' => (string) ($serviceRow['umrah_mofa_reference'] ?? ''),
        'umrahDepartureDate' => (string) ($serviceRow['umrah_departure_date'] ?? ''),
        'umrahReturnDate' => (string) ($serviceRow['umrah_return_date'] ?? ''),
        'umrahHotelName' => (string) ($serviceRow['umrah_hotel_name'] ?? ''),
        'umrahTransportNotes' => (string) ($serviceRow['umrah_transport_notes'] ?? ''),
        'umrahRemarks' => (string) ($serviceRow['umrah_remarks'] ?? ''),
        'hotelName' => (string) ($serviceRow['hotel_name'] ?? ''),
        'hotelCity' => (string) ($serviceRow['hotel_city'] ?? ''),
        'hotelConfirmationNumber' => (string) ($serviceRow['hotel_confirmation_number'] ?? ''),
        'hotelCheckInDate' => (string) ($serviceRow['hotel_check_in_date'] ?? ''),
        'hotelCheckOutDate' => (string) ($serviceRow['hotel_check_out_date'] ?? ''),
        'hotelRoomType' => (string) ($serviceRow['hotel_room_type'] ?? ''),
        'hotelGuestCount' => (int) ($serviceRow['hotel_guest_count'] ?? 0),
        'hotelRemarks' => (string) ($serviceRow['hotel_remarks'] ?? ''),
        'transportMode' => (string) ($serviceRow['transport_mode'] ?? ''),
        'transportVehicleType' => (string) ($serviceRow['transport_vehicle_type'] ?? ''),
        'transportPickupDate' => (string) ($serviceRow['transport_pickup_date'] ?? ''),
        'transportPickupLocation' => (string) ($serviceRow['transport_pickup_location'] ?? ''),
        'transportDropoffLocation' => (string) ($serviceRow['transport_dropoff_location'] ?? ''),
        'transportDriverDetail' => (string) ($serviceRow['transport_driver_detail'] ?? ''),
        'transportRouteNotes' => (string) ($serviceRow['transport_route_notes'] ?? ''),
        'transportRemarks' => (string) ($serviceRow['transport_remarks'] ?? ''),
        'tourName' => (string) ($serviceRow['tour_name'] ?? ''),
        'tourDestination' => (string) ($serviceRow['tour_destination'] ?? ''),
        'tourConfirmationNumber' => (string) ($serviceRow['tour_confirmation_number'] ?? ''),
        'tourStartDate' => (string) ($serviceRow['tour_start_date'] ?? ''),
        'tourEndDate' => (string) ($serviceRow['tour_end_date'] ?? ''),
        'tourInclusions' => (string) ($serviceRow['tour_inclusions'] ?? ''),
        'tourRemarks' => (string) ($serviceRow['tour_remarks'] ?? ''),
        'otherLabel' => (string) ($serviceRow['other_label'] ?? ''),
        'otherReferenceNumber' => (string) ($serviceRow['other_reference_number'] ?? ''),
        'otherServiceDate' => (string) ($serviceRow['other_service_date'] ?? ''),
        'otherProviderName' => (string) ($serviceRow['other_provider_name'] ?? ''),
        'otherRemarks' => (string) ($serviceRow['other_remarks'] ?? ''),
        'isActive' => (int) ($serviceRow['is_active'] ?? 1),
        'profit' => $profit,
    ];
}, $bookingServices);

if ($serviceLines === []) {
    $serviceLines[] = [
        'serviceId' => 0,
        'lineNumber' => 'SV-DRAFT',
        'type' => 'air ticket',
        'supplierId' => 0,
        'supplier' => '',
        'travelerId' => (int) ($selectedTravelerProfile['id'] ?? 0),
        'passengerName' => $workspaceBooking['lead'],
        'currency' => $workspaceBooking['defaultCurrency'],
        'costCurrency' => $workspaceBooking['defaultCurrency'],
        'salePrice' => 0,
        'purchaseCost' => 0,
        'pricingExchangeRate' => 1.0,
        'pricingRateEffectiveDate' => '',
        'taxes' => 0,
        'otherFare' => 0,
        'sotoFare' => 0,
        'spyiAmount' => 0,
        'aqYrPkAmount' => 0,
        'yqAmount' => 0,
        'othAmount' => 0,
        'vatInput' => 0,
        'vat' => 0,
        'commission' => 0,
        'serviceCharge' => 0,
        'discountAmount' => 0,
        'finalSalePrice' => 0,
        'netProfitLoss' => 0,
        'dueDate' => '',
        'status' => $workspaceBooking['id'] > 0 ? 'Open' : 'Save Booking First',
        'displayStatus' => $workspaceBooking['id'] > 0 ? 'Open' : 'Save Booking First',
        'remarks' => $workspaceBooking['id'] > 0 ? 'Enter service details, then save service.' : 'Enter first service here. The invoice will be saved with it.',
        'lossReason' => '',
        'pnr' => '',
        'ticketNumber' => '',
        'airline' => '',
        'sectorFrom' => '',
        'sectorTo' => '',
        'departureDate' => '',
        'returnDate' => '',
        'class' => '',
        'fare' => 0,
        'ticketTax' => 0,
        'ticketVat' => 0,
        'ticketCommission' => 0,
        'supplierCost' => 0,
        'saleAmount' => 0,
        'ticketRemarks' => '',
        'visaCountry' => '',
        'visaType' => '',
        'visaApplicationReference' => '',
        'visaPassportNumber' => '',
        'visaSubmissionDate' => '',
        'visaIssueDate' => '',
        'visaExpiryDate' => '',
        'visaStatus' => '',
        'visaRemarks' => '',
        'umrahPackageName' => '',
        'umrahMofaReference' => '',
        'umrahDepartureDate' => '',
        'umrahReturnDate' => '',
        'umrahHotelName' => '',
        'umrahTransportNotes' => '',
        'umrahRemarks' => '',
        'hotelName' => '',
        'hotelCity' => '',
        'hotelConfirmationNumber' => '',
        'hotelCheckInDate' => '',
        'hotelCheckOutDate' => '',
        'hotelRoomType' => '',
        'hotelGuestCount' => 0,
        'hotelRemarks' => '',
        'transportMode' => '',
        'transportVehicleType' => '',
        'transportPickupDate' => '',
        'transportPickupLocation' => '',
        'transportDropoffLocation' => '',
        'transportDriverDetail' => '',
        'transportRouteNotes' => '',
        'transportRemarks' => '',
        'tourName' => '',
        'tourDestination' => '',
        'tourConfirmationNumber' => '',
        'tourStartDate' => '',
        'tourEndDate' => '',
        'tourInclusions' => '',
        'tourRemarks' => '',
        'otherLabel' => '',
        'otherReferenceNumber' => '',
        'otherServiceDate' => '',
        'otherProviderName' => '',
        'otherRemarks' => '',
        'isActive' => 1,
        'profit' => 0,
    ];
}

$persistedServiceLines = array_values(array_filter($serviceLines, static fn (array $serviceLine): bool => (int) ($serviceLine['serviceId'] ?? 0) > 0));
$activePersistedServiceLines = array_values(array_filter(
    $persistedServiceLines,
    static fn (array $serviceLine): bool => (int) ($serviceLine['isActive'] ?? 1) === 1
));
if ($activePersistedServiceLines !== []) {
    $serviceTypes = array_values(array_unique(array_map(static fn (array $serviceLine): string => ucwords((string) $serviceLine['type']), $activePersistedServiceLines)));
    $serviceCurrencies = array_values(array_unique(array_map(static fn (array $serviceLine): string => (string) $serviceLine['currency'], $activePersistedServiceLines)));
    $workspaceBooking['serviceMix'] = implode(' | ', $serviceTypes);
    $workspaceBooking['currency'] = implode(' / ', $serviceCurrencies);
} elseif ($persistedServiceLines !== []) {
    $workspaceBooking['serviceMix'] = 'No active service lines';
    $workspaceBooking['currency'] = 'Inactive lines only';
}

$hasActiveServices = $activePersistedServiceLines !== [];
$hasCustomerFinance = ($customerPaymentFoundation['serviceReceivables'] ?? []) !== []
    || ($customerPaymentFoundation['receipts'] ?? []) !== []
    || ($customerPaymentFoundation['allocations'] ?? []) !== [];
$hasSupplierFinance = ($supplierFoundation['obligations'] ?? []) !== []
    || ($supplierFoundation['payments'] ?? []) !== []
    || ($supplierFoundation['advances'] ?? []) !== []
    || ($supplierFoundation['paymentAllocations'] ?? []) !== []
    || ($supplierFoundation['advanceApplications'] ?? []) !== [];

$travelers = array_map(static function (array $travelerRow, int $index): array {
    return [
        'travelerNo' => 'TRV-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
        'bookingTravelerId' => (int) ($travelerRow['booking_traveler_id'] ?? 0),
        'travelerId' => (int) ($travelerRow['id'] ?? 0),
        'type' => (string) (($travelerRow['traveler_role'] ?? 'additional') === 'lead' ? 'Lead' : 'Additional'),
        'firstName' => (string) ($travelerRow['first_name'] ?? ''),
        'lastName' => (string) ($travelerRow['last_name'] ?? ''),
        'fullName' => (string) ($travelerRow['full_name'] ?? ''),
        'gender' => ucfirst((string) ($travelerRow['gender'] ?? 'unspecified')),
        'dateOfBirth' => (string) ($travelerRow['date_of_birth'] ?? ''),
        'passportNo' => (string) ($travelerRow['passport_number'] ?? ''),
        'passportExpiry' => (string) ($travelerRow['passport_expiry'] ?? ''),
        'mobile' => (string) ($travelerRow['mobile'] ?? ''),
        'address' => (string) ($travelerRow['address'] ?? ''),
        'permanentResidence' => (string) ($travelerRow['permanent_residence'] ?? ''),
        'currentResidence' => (string) ($travelerRow['current_residence'] ?? ''),
        'occupation' => (string) ($travelerRow['occupation'] ?? ''),
        'village' => (string) ($travelerRow['village'] ?? ''),
        'district' => (string) ($travelerRow['district'] ?? ''),
        'familyId' => (string) ($travelerRow['family_id'] ?? ''),
        'colorTag' => (string) ($travelerRow['color_tag'] ?? ''),
        'nationality' => (string) ($travelerRow['nationality'] ?? ''),
        'status' => (string) (($travelerRow['traveler_role'] ?? 'additional') === 'lead' ? 'Lead Traveler' : 'Attached'),
        'notes' => (string) ($travelerRow['notes'] ?? ''),
        'travelerRole' => (string) ($travelerRow['traveler_role'] ?? 'additional'),
        'branchId' => (int) ($travelerRow['branch_id'] ?? 0),
    ];
}, $bookingTravelers, array_keys($bookingTravelers));

if ($travelers === []) {
    $travelers[] = [
        'travelerNo' => $resolvedLeadTravelerProfile !== null ? 'TRV-' . str_pad((string) ((int) ($resolvedLeadTravelerProfile['id'] ?? 0)), 3, '0', STR_PAD_LEFT) : 'TRV-DRAFT',
        'bookingTravelerId' => 0,
        'travelerId' => (int) ($resolvedLeadTravelerProfile['id'] ?? 0),
        'type' => 'Lead',
        'firstName' => (string) ($resolvedLeadTravelerProfile['first_name'] ?? ''),
        'lastName' => (string) ($resolvedLeadTravelerProfile['last_name'] ?? ''),
        'fullName' => $workspaceBooking['lead'],
        'gender' => ucfirst((string) ($resolvedLeadTravelerProfile['gender'] ?? 'unspecified')),
        'dateOfBirth' => (string) ($resolvedLeadTravelerProfile['date_of_birth'] ?? ''),
        'passportNo' => $workspaceBooking['passport'],
        'passportExpiry' => (string) ($resolvedLeadTravelerProfile['passport_expiry'] ?? ''),
        'mobile' => $workspaceBooking['mobile'],
        'address' => (string) ($resolvedLeadTravelerProfile['address'] ?? ''),
        'permanentResidence' => (string) ($resolvedLeadTravelerProfile['permanent_residence'] ?? ''),
        'currentResidence' => (string) ($resolvedLeadTravelerProfile['current_residence'] ?? ''),
        'occupation' => (string) ($resolvedLeadTravelerProfile['occupation'] ?? ''),
        'village' => (string) ($resolvedLeadTravelerProfile['village'] ?? ''),
        'district' => (string) ($resolvedLeadTravelerProfile['district'] ?? ''),
        'familyId' => (string) ($resolvedLeadTravelerProfile['family_id'] ?? ''),
        'colorTag' => (string) ($resolvedLeadTravelerProfile['color_tag'] ?? ''),
        'nationality' => (string) ($resolvedLeadTravelerProfile['nationality'] ?? ''),
        'status' => $workspaceBooking['id'] > 0 ? 'No Traveler Attached Yet' : ($selectedTravelerProfile !== null ? 'Selected Customer Profile' : 'Save Booking First'),
        'notes' => $resolvedLeadTravelerProfile !== null
            ? (string) ($resolvedLeadTravelerProfile['notes'] ?? '')
            : ($workspaceBooking['partyNotes'] !== '' ? $workspaceBooking['partyNotes'] : 'No travelers added yet.'),
        'travelerRole' => 'lead',
        'branchId' => $workspaceBooking['branchId'],
    ];
}

$leadTraveler = $travelers[0];

$printPresets = $workspaceBooking['id'] > 0 ? [
    [
        'preset' => 'Customer Invoice',
        'target' => 'Customer Billing',
        'format' => 'A4 Portrait',
        'lastUsed' => 'Ready',
        'url' => url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=invoice'),
    ],
    [
        'preset' => 'Account Statement',
        'target' => 'Customer Accounts',
        'format' => 'A4 Portrait',
        'lastUsed' => 'Ready',
        'url' => url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=account_statement'),
    ],
    [
        'preset' => 'Itinerary',
        'target' => 'Travel Desk',
        'format' => 'A4 Portrait',
        'lastUsed' => 'Ready',
        'url' => url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=itinerary'),
    ],
    [
        'preset' => 'Booking Confirmation',
        'target' => 'Customer Copy',
        'format' => 'A4 Portrait',
        'lastUsed' => 'Ready',
        'url' => url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=booking_confirmation'),
    ],
] : [];

$overviewActivities = [
    [
        'time' => $workspaceBooking['createdAt'] !== '' ? date('H:i', strtotime($workspaceBooking['createdAt'])) : '--:--',
        'event' => $workspaceBooking['id'] > 0 ? 'Booking persisted' : 'Draft not yet saved',
        'note' => $workspaceBooking['id'] > 0
            ? 'Booking reference ' . $workspaceBooking['number'] . ' is stored in the booking master.'
            : 'Use Save Booking to generate the first booking reference.',
    ],
    [
        'time' => $workspaceBooking['updatedAt'] !== '' ? date('H:i', strtotime($workspaceBooking['updatedAt'])) : '--:--',
        'event' => $workspaceBooking['id'] > 0 ? 'Latest booking update' : 'Awaiting first booking update',
        'note' => $workspaceBooking['id'] > 0
            ? 'Branch, dates, status, lead traveler, and notes now update from the real booking record.'
            : 'Booking lifecycle becomes active after first save.',
    ],
];

$checkpoints = [
    ['checkpoint' => 'Booking Header', 'status' => $workspaceBooking['id'] > 0 ? 'Saved' : 'Pending Save', 'nextStep' => 'Maintain branch, dates, status, and booking notes'],
    ['checkpoint' => 'Lead Traveler / Party', 'status' => $bookingTravelers !== [] ? 'Traveler Linked' : 'Pending Attach', 'nextStep' => 'Attach an existing traveler or create a new one in the Travelers tab'],
    ['checkpoint' => 'Search / Open', 'status' => $bookingSearchResults !== [] ? 'Ready' : 'No Matches Yet', 'nextStep' => 'Search by booking no, lead traveler, mobile, or passport'],
    ['checkpoint' => 'Services / Finance', 'status' => $persistedServiceLines !== [] ? 'Live Sync Active' : 'Awaiting First Service', 'nextStep' => 'Saving service lines now creates live receivable and payable positions'],
];

$formatMoney = static fn (float $amount): string => number_format(round($amount, 0), 0);
$sumByCurrency = static function (array $rows, string $currencyKey, callable $amountResolver): array {
    $totals = [];
    foreach ($rows as $row) {
        $currency = trim((string) ($row[$currencyKey] ?? ''));
        if ($currency === '') {
            continue;
        }

        $totals[$currency] = ($totals[$currency] ?? 0.0) + (float) $amountResolver($row);
    }

    return $totals;
};
$formatCurrencyTotals = static function (array $totals) use ($formatMoney): string {
    if ($totals === []) {
        return 'PKR 0';
    }

    $parts = [];
    foreach ($totals as $currency => $amount) {
        $parts[] = (string) $currency . ' ' . $formatMoney((float) $amount);
    }

    return implode(' | ', $parts);
};
$metricRows = static function (array $totals) use ($formatMoney): array {
    if ($totals === []) {
        return [
            [
                'currency' => 'PKR',
                'amount' => $formatMoney(0),
            ],
        ];
    }

    $rows = [];
    foreach ($totals as $currency => $amount) {
        $rows[] = [
            'currency' => (string) $currency,
            'amount' => $formatMoney((float) $amount),
        ];
    }

    return $rows;
};

$lineMetricKey = static fn (string $lineReference, string $currency): string => trim($lineReference) . '|' . strtoupper(trim($currency));
$allocatedByLine = [];
foreach (($customerPaymentFoundation['allocations'] ?? []) as $allocationRow) {
    $receiptStatus = mb_strtolower(trim((string) ($allocationRow['receiptStatusRaw'] ?? $allocationRow['receiptStatus'] ?? '')));
    $allocationBookingReference = trim((string) ($allocationRow['bookingReference'] ?? ''));
    $lineReference = trim((string) ($allocationRow['serviceLineReference'] ?? ''));
    $currency = trim((string) ($allocationRow['receivableCurrency'] ?? $allocationRow['currency'] ?? ''));
    if ($receiptStatus === 'void'
        || $allocationBookingReference !== (string) ($workspaceBooking['number'] ?? '')
        || $lineReference === ''
        || $currency === '') {
        continue;
    }

    $allocationKey = $lineMetricKey($lineReference, $currency);
    $allocatedByLine[$allocationKey] = ($allocatedByLine[$allocationKey] ?? 0.0)
        + (float) ($allocationRow['receivableAmountAllocated'] ?? $allocationRow['allocatedAmount'] ?? 0);
}
$receivableByLine = [];
foreach (($customerPaymentFoundation['serviceReceivables'] ?? []) as $receivableRow) {
    $lineReference = trim((string) ($receivableRow['serviceLineReference'] ?? ''));
    $currency = trim((string) ($receivableRow['currency'] ?? ''));
    if ($lineReference === '' || $currency === '') {
        continue;
    }

    $receivableByLine[$lineMetricKey($lineReference, $currency)] = [
        'dueAmount' => round((float) ($receivableRow['dueAmount'] ?? 0), 2),
        'outstandingAmount' => round((float) ($receivableRow['outstandingAmount'] ?? 0), 2),
        'allocatedAmount' => round((float) ($receivableRow['allocatedAmount'] ?? 0), 2),
    ];
}

$payableByLine = [];
foreach (($supplierFoundation['obligations'] ?? []) as $obligationRow) {
    $lineReference = trim((string) ($obligationRow['serviceLineReference'] ?? ''));
    $currency = trim((string) ($obligationRow['currency'] ?? ''));
    if ($lineReference === '' || $currency === '') {
        continue;
    }

    $payableByLine[$lineMetricKey($lineReference, $currency)] = [
        'grossAmount' => round((float) ($obligationRow['grossAmount'] ?? 0), 2),
        'netPayableAmount' => round((float) ($obligationRow['netPayableAmount'] ?? 0), 2),
        'advanceAppliedAmount' => round((float) ($obligationRow['advanceAppliedAmount'] ?? 0), 2),
    ];
}

foreach ($serviceLines as &$serviceLine) {
    $lineKey = $lineMetricKey((string) ($serviceLine['lineNumber'] ?? ''), (string) ($serviceLine['currency'] ?? ''));
    $derivedReceivable = round((float) ($serviceLine['finalSalePrice'] ?? 0), 2);
    $derivedPayable = round(
        (float) ($serviceLine['purchaseCost'] ?? 0)
        * ((string) ($serviceLine['currency'] ?? 'PKR') === (string) ($serviceLine['costCurrency'] ?? $serviceLine['currency'] ?? 'PKR')
            ? 1
            : max((float) ($serviceLine['pricingExchangeRate'] ?? 1), 0)),
        2
    );
    $hasReceivableRow = array_key_exists($lineKey, $receivableByLine);
    $cancelSettled = (bool) ($serviceLine['latestCancelFinanciallySettled'] ?? false);
    $cancelFinalCharge = round((float) ($serviceLine['latestCancelCustomerFinalChargeAmount'] ?? 0), 2);
    $resolvedReceivable = $hasReceivableRow
        ? (float) ($receivableByLine[$lineKey]['dueAmount'] ?? 0)
        : ($cancelSettled ? max($cancelFinalCharge, 0) : $derivedReceivable);
    $resolvedAllocatedSource = (float) ($allocatedByLine[$lineKey] ?? 0);
    if (! $hasReceivableRow && $cancelSettled && $resolvedAllocatedSource <= 0.005) {
        $resolvedAllocatedSource = $resolvedReceivable;
    }
    $resolvedAllocated = min(max($resolvedAllocatedSource, 0), max($resolvedReceivable, 0));
    $resolvedOutstanding = max($resolvedReceivable - $resolvedAllocated, 0);
    $resolvedPayable = array_key_exists($lineKey, $payableByLine)
        ? (float) ($payableByLine[$lineKey]['netPayableAmount'] ?? 0)
        : (array_key_exists('rowPayable', $serviceLine)
            ? (float) ($serviceLine['rowPayable'] ?? 0)
            : $derivedPayable);
    $resolvedProfit = array_key_exists('rowProfit', $serviceLine)
        ? (float) ($serviceLine['rowProfit'] ?? 0)
        : (array_key_exists('profit', $serviceLine)
            ? (float) ($serviceLine['profit'] ?? 0)
            : round($resolvedReceivable - $derivedPayable, 2));

    $serviceLine['rowSpTotal'] = round($resolvedReceivable, 2);
    $serviceLine['rowReceivable'] = round($resolvedReceivable, 2);
    $serviceLine['allocatedAmount'] = round($resolvedAllocated, 2);
    $serviceLine['outstandingAmount'] = round(max($resolvedOutstanding, 0), 2);
    $serviceLine['rowPayable'] = round($resolvedPayable, 2);
    $serviceLine['rowProfit'] = round($resolvedProfit, 2);
    $serviceLine['rowFinancialSource'] = $hasReceivableRow || array_key_exists($lineKey, $payableByLine)
        ? 'persisted'
        : 'commercial_fallback';
    $serviceLine['profit'] = $serviceLine['rowProfit'];
}
unset($serviceLine);

$activePersistedFinancialLines = array_values(array_filter(
    $serviceLines,
    static fn (array $row): bool => (int) ($row['serviceId'] ?? 0) > 0 && (int) ($row['isActive'] ?? 1) === 1
));

$saleTotals = $sumByCurrency($activePersistedFinancialLines, 'currency', static fn (array $row): float => (float) ($row['rowSpTotal'] ?? 0));
$costTotals = $sumByCurrency($activePersistedFinancialLines, 'currency', static fn (array $row): float => (float) ($row['rowPayable'] ?? 0));
$profitTotals = $sumByCurrency($activePersistedFinancialLines, 'currency', static fn (array $row): float => (float) ($row['rowProfit'] ?? 0));
$receivableTotals = is_array($customerPaymentFoundation['summary']['invoiceReceivable'] ?? null)
    ? $customerPaymentFoundation['summary']['invoiceReceivable']
    : $sumByCurrency($activePersistedFinancialLines, 'currency', static fn (array $row): float => (float) ($row['rowReceivable'] ?? 0));
$receivedTotals = is_array($customerPaymentFoundation['summary']['invoiceReceived'] ?? null)
    ? $customerPaymentFoundation['summary']['invoiceReceived']
    : $sumByCurrency($customerPaymentFoundation['receipts'] ?? [], 'currency', static fn (array $row): float => (float) ($row['receivedAmount'] ?? 0));
$outstandingTotals = is_array($customerPaymentFoundation['summary']['invoiceOutstanding'] ?? null)
    ? $customerPaymentFoundation['summary']['invoiceOutstanding']
    : $sumByCurrency($customerPaymentFoundation['serviceReceivables'] ?? [], 'currency', static fn (array $row): float => (float) ($row['outstandingAmount'] ?? 0));
$payableTotals = $sumByCurrency($activePersistedFinancialLines, 'currency', static fn (array $row): float => (float) ($row['rowPayable'] ?? 0));
$supplierOutstandingTotals = $sumByCurrency($supplierFoundation['obligations'] ?? [], 'currency', static fn (array $row): float => (float) ($row['netPayableAmount'] ?? 0));
$supplierPaidTotals = [];
foreach (array_keys($payableTotals) as $currencyCode) {
    $supplierPaidTotals[$currencyCode] = 0.0;
}
$supplierAdvanceTotals = $sumByCurrency($supplierFoundation['advances'] ?? [], 'currency', static fn (array $row): float => (float) ($row['availableAmount'] ?? 0));
if (($supplierFoundation['payments'] ?? []) !== []) {
    $supplierPaidTotals = $sumByCurrency($supplierFoundation['payments'] ?? [], 'currency', static fn (array $row): float => (float) ($row['paidAmount'] ?? 0));
}

$workspaceBooking['totalSale'] = $formatCurrencyTotals($saleTotals);
$workspaceBooking['totalCost'] = $formatCurrencyTotals($costTotals);
$workspaceBooking['profitLoss'] = $formatCurrencyTotals($profitTotals);
$workspaceBooking['totalReceivable'] = $formatCurrencyTotals($receivableTotals);
$workspaceBooking['totalPayable'] = $formatCurrencyTotals($payableTotals);
$workspaceBooking['totalReceived'] = $formatCurrencyTotals($receivedTotals);
$workspaceBooking['totalOutstanding'] = $formatCurrencyTotals($outstandingTotals);
$workspaceBooking['totalSupplierPaid'] = $formatCurrencyTotals($supplierPaidTotals);
$workspaceBooking['totalSupplierOutstanding'] = $formatCurrencyTotals($supplierOutstandingTotals);

$financialRailMetrics = [
    ['label' => 'Booking Status', 'values' => [['currency' => '', 'amount' => $bookingStatusOptions[$workspaceBooking['status']] ?? 'Draft']], 'tone' => 'sale'],
    ['label' => 'Branch Scope', 'values' => [['currency' => '', 'amount' => $workspaceBooking['branch']]], 'tone' => 'payable'],
    ['label' => 'Last Updated', 'values' => [['currency' => '', 'amount' => $workspaceBooking['updatedAt'] !== '' ? date('Y-m-d H:i', strtotime($workspaceBooking['updatedAt'])) : 'Not Saved Yet']], 'tone' => 'supplier'],
];
if ($hasCustomerFinance) {
    $financialRailMetrics[] = ['label' => 'Receivable', 'values' => $metricRows($receivableTotals), 'tone' => 'received'];
}
if ($hasSupplierFinance) {
    $financialRailMetrics[] = ['label' => 'Payable', 'values' => $metricRows($payableTotals), 'tone' => 'outstanding'];
}
if ($hasActiveServices) {
    $financialRailMetrics[] = ['label' => 'Profit Snapshot', 'values' => $metricRows($profitTotals), 'tone' => 'profit'];
}

$paymentPanelMetrics = [
    ['label' => 'Booking Reference', 'values' => [['currency' => '', 'amount' => $workspaceBooking['number']]]],
    ['label' => 'Lead Traveler', 'values' => [['currency' => '', 'amount' => $workspaceBooking['lead'] !== '' ? $workspaceBooking['lead'] : 'Pending']]] ,
    ['label' => 'Customer Outstanding', 'values' => $metricRows($customerPaymentFoundation['summary']['customerOutstanding'] ?? [])] ,
    ['label' => 'Booking Outstanding', 'values' => $metricRows($customerPaymentFoundation['summary']['bookingOutstanding'] ?? [])],
    ['label' => 'Customer Credit', 'values' => $metricRows($customerPaymentFoundation['summary']['customerCredit'] ?? [])],
    ['label' => 'Total Received', 'values' => $metricRows($receivedTotals)],
];

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
};

$documentRows = array_map(static function (array $document) use ($documentTypeOptions, $formatBytes): array {
    $typeCode = (string) ($document['document_type'] ?? 'general_attachment');
    $linkedTo = 'Booking File';

    if (! empty($document['traveler_name'])) {
        $linkedTo = 'Traveler / ' . (string) $document['traveler_name'];
    } elseif (! empty($document['service_line_reference'])) {
        $linkedTo = 'Service / ' . (string) $document['service_line_reference'];
    } elseif (! empty($document['customer_receipt_no'])) {
        $linkedTo = 'Receipt / ' . (string) $document['customer_receipt_no'];
    } elseif (! empty($document['supplier_payment_no'])) {
        $linkedTo = 'Supplier Payment / ' . (string) $document['supplier_payment_no'];
    } elseif (! empty($document['supplier_obligation_service_line']) || ! empty($document['supplier_name'])) {
        $linkedTo = 'Supplier Obligation / '
            . trim((string) (($document['supplier_name'] ?? 'Supplier') . ' ' . ($document['supplier_obligation_service_line'] ?? '')));
    }

    return [
        'id' => (int) ($document['id'] ?? 0),
        'title' => (string) ($document['title'] ?? ''),
        'type' => $documentTypeOptions[$typeCode] ?? ucwords(str_replace('_', ' ', $typeCode)),
        'linkedTo' => $linkedTo,
        'statusRaw' => (string) ($document['status'] ?? 'active'),
        'status' => ucwords((string) ($document['status'] ?? 'active')),
        'updatedAt' => (string) ($document['updated_at'] ?? ''),
        'fileName' => (string) ($document['original_file_name'] ?? ''),
        'fileSize' => $formatBytes((int) ($document['file_size_bytes'] ?? 0)),
        'notes' => (string) ($document['notes'] ?? ''),
        'downloadUrl' => url('/workspace/documents/download?document_id=' . (int) ($document['id'] ?? 0)),
    ];
}, $documents);

$receiptOutputRows = array_map(
    static fn (array $receipt): array => [
        'label' => (string) ($receipt['receiptNo'] ?? 'Receipt'),
        'date' => (string) ($receipt['receiptDate'] ?? ''),
        'currency' => (string) ($receipt['currency'] ?? ''),
        'amount' => (float) ($receipt['receivedAmount'] ?? 0),
        'status' => (string) ($receipt['status'] ?? ''),
        'url' => url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=customer_receipt&receipt_id=' . (int) ($receipt['id'] ?? 0)),
    ],
    $customerPaymentFoundation['receipts'] ?? []
);

$supplierVoucherRows = array_map(
    static fn (array $payment): array => [
        'label' => (string) ($payment['paymentNo'] ?? 'Supplier Payment'),
        'date' => (string) ($payment['paymentDate'] ?? ''),
        'supplier' => (string) ($payment['supplier'] ?? ''),
        'currency' => (string) ($payment['currency'] ?? ''),
        'amount' => (float) ($payment['paidAmount'] ?? 0),
        'status' => (string) ($payment['status'] ?? ''),
        'url' => url('/workspace/output?booking_id=' . (int) $workspaceBooking['id'] . '&doc=supplier_voucher&supplier_payment_id=' . (int) ($payment['id'] ?? 0)),
    ],
    $supplierFoundation['payments'] ?? []
);

$reminderRows = array_map(static function (array $reminder) use ($reminderTypeOptions): array {
    $linkedTo = 'Booking File';

    if (! empty($reminder['traveler_name'])) {
        $linkedTo = 'Traveler / ' . (string) $reminder['traveler_name'];
    } elseif (! empty($reminder['service_line_reference'])) {
        $linkedTo = 'Service / ' . (string) $reminder['service_line_reference'];
    } elseif (! empty($reminder['customer_receipt_no'])) {
        $linkedTo = 'Receipt / ' . (string) $reminder['customer_receipt_no'];
    } elseif (! empty($reminder['supplier_payment_no'])) {
        $linkedTo = 'Supplier Payment / ' . (string) $reminder['supplier_payment_no'];
    } elseif (! empty($reminder['supplier_obligation_service_line']) || ! empty($reminder['supplier_name'])) {
        $linkedTo = 'Supplier Obligation / '
            . trim((string) (($reminder['supplier_name'] ?? 'Supplier') . ' ' . ($reminder['supplier_obligation_service_line'] ?? '')));
    }

    $linkedTarget = 'booking:' . (int) ($reminder['booking_id'] ?? 0);
    if ((int) ($reminder['traveler_id'] ?? 0) > 0) {
        $linkedTarget = 'traveler:' . (int) $reminder['traveler_id'];
    } elseif ((int) ($reminder['booking_service_id'] ?? 0) > 0) {
        $linkedTarget = 'service:' . (int) $reminder['booking_service_id'];
    } elseif ((int) ($reminder['customer_receipt_id'] ?? 0) > 0) {
        $linkedTarget = 'receipt:' . (int) $reminder['customer_receipt_id'];
    } elseif ((int) ($reminder['supplier_payment_id'] ?? 0) > 0) {
        $linkedTarget = 'supplier_payment:' . (int) $reminder['supplier_payment_id'];
    } elseif ((int) ($reminder['supplier_obligation_id'] ?? 0) > 0) {
        $linkedTarget = 'supplier_obligation:' . (int) $reminder['supplier_obligation_id'];
    }

    $dueAtRaw = (string) ($reminder['due_at'] ?? '');
    $dueAtLabel = $dueAtRaw !== '' ? date('Y-m-d H:i', strtotime($dueAtRaw)) : '';
    $status = (string) ($reminder['status'] ?? 'open');

    return [
        'id' => (int) ($reminder['id'] ?? 0),
        'type' => $reminderTypeOptions[(string) ($reminder['reminder_type'] ?? '')] ?? ucwords(str_replace('_', ' ', (string) ($reminder['reminder_type'] ?? 'custom_manual'))),
        'title' => (string) ($reminder['title'] ?? ''),
        'note' => (string) ($reminder['reminder_note'] ?? ''),
        'dueAt' => $dueAtLabel,
        'dueAtRaw' => $dueAtRaw,
        'owner' => (string) ($reminder['owner_label'] ?? ''),
        'channel' => (string) ($reminder['channel'] ?? ''),
        'status' => ucwords($status),
        'statusKey' => $status,
        'priority' => ucfirst((string) ($reminder['priority'] ?? 'normal')),
        'linkedTo' => $linkedTo,
        'linkedTarget' => $linkedTarget,
        'isSystemGenerated' => (int) ($reminder['system_generated'] ?? 0) === 1,
        'updatedAt' => (string) ($reminder['updated_at'] ?? ''),
    ];
}, $reminders);

$reminderCounts = [
    'open' => 0,
    'due' => 0,
    'completed' => 0,
    'dismissed' => 0,
    'overdue' => 0,
    'upcoming' => 0,
];

foreach ($reminderRows as $reminderRow) {
    $statusKey = (string) ($reminderRow['statusKey'] ?? 'open');
    if (array_key_exists($statusKey, $reminderCounts)) {
        $reminderCounts[$statusKey]++;
    }

    $dueAtTimestamp = strtotime((string) ($reminderRow['dueAtRaw'] ?? ''));
    if ($dueAtTimestamp !== false && in_array($statusKey, ['open', 'due'], true)) {
        if ($dueAtTimestamp <= time()) {
            $reminderCounts['overdue']++;
        } else {
            $reminderCounts['upcoming']++;
        }
    }
}

$openReminderRows = array_values(array_filter(
    $reminderRows,
    static fn (array $row): bool => in_array((string) ($row['statusKey'] ?? 'open'), ['open', 'due'], true)
));
$closedReminderRows = array_values(array_filter(
    $reminderRows,
    static fn (array $row): bool => in_array((string) ($row['statusKey'] ?? ''), ['completed', 'dismissed'], true)
));

$editingReminderForm = [
    'id' => (int) ($editingReminder['id'] ?? 0),
    'type' => (string) ($editingReminder['reminder_type'] ?? 'custom_manual'),
    'title' => (string) ($editingReminder['title'] ?? ''),
    'owner' => (string) ($editingReminder['owner_label'] ?? ''),
    'dueAt' => '',
    'channel' => (string) ($editingReminder['channel'] ?? 'Call'),
    'linkedTarget' => 'booking:' . (int) $workspaceBooking['id'],
    'priority' => (string) ($editingReminder['priority'] ?? 'normal'),
    'note' => (string) ($editingReminder['reminder_note'] ?? ''),
    'isSystemGenerated' => (int) ($editingReminder['system_generated'] ?? 0) === 1,
];

if ($editingReminderForm['id'] > 0) {
    $editingDueAt = trim((string) ($editingReminder['due_at'] ?? ''));
    if ($editingDueAt !== '') {
        $editingReminderForm['dueAt'] = date('Y-m-d\TH:i', strtotime($editingDueAt));
    }

    if ((int) ($editingReminder['traveler_id'] ?? 0) > 0) {
        $editingReminderForm['linkedTarget'] = 'traveler:' . (int) $editingReminder['traveler_id'];
    } elseif ((int) ($editingReminder['booking_service_id'] ?? 0) > 0) {
        $editingReminderForm['linkedTarget'] = 'service:' . (int) $editingReminder['booking_service_id'];
    } elseif ((int) ($editingReminder['customer_receipt_id'] ?? 0) > 0) {
        $editingReminderForm['linkedTarget'] = 'receipt:' . (int) $editingReminder['customer_receipt_id'];
    } elseif ((int) ($editingReminder['supplier_payment_id'] ?? 0) > 0) {
        $editingReminderForm['linkedTarget'] = 'supplier_payment:' . (int) $editingReminder['supplier_payment_id'];
    } elseif ((int) ($editingReminder['supplier_obligation_id'] ?? 0) > 0) {
        $editingReminderForm['linkedTarget'] = 'supplier_obligation:' . (int) $editingReminder['supplier_obligation_id'];
    }
}

$travelerDirectoryById = [];
$travelerDirectoryByName = [];
foreach ($travelers as $travelerRow) {
    $travelerId = (int) ($travelerRow['travelerId'] ?? 0);
    $travelerName = trim((string) ($travelerRow['fullName'] ?? ''));
    if ($travelerId > 0) {
        $travelerDirectoryById[$travelerId] = $travelerRow;
    }
    if ($travelerName !== '') {
        $travelerDirectoryByName[mb_strtolower($travelerName)] = $travelerRow;
    }
}

$passengerSummaryRows = [];
foreach ($serviceLines as $serviceLine) {
    $passengerName = trim((string) ($serviceLine['passengerName'] ?? ''));
    if ($passengerName === '') {
        continue;
    }

    $travelerId = (int) ($serviceLine['travelerId'] ?? 0);
    $travelerProfile = $travelerId > 0
        ? ($travelerDirectoryById[$travelerId] ?? null)
        : ($travelerDirectoryByName[mb_strtolower($passengerName)] ?? null);

    $serviceRoute = trim((string) ($serviceLine['sectorFrom'] ?? ''));
    $sectorTo = trim((string) ($serviceLine['sectorTo'] ?? ''));
    if ($serviceRoute !== '' && $sectorTo !== '') {
        $serviceRoute .= ' / ' . $sectorTo;
    } elseif ($serviceRoute === '' && $sectorTo !== '') {
        $serviceRoute = $sectorTo;
    }
    if ($serviceRoute === '') {
        $serviceRoute = 'N/A';
    }

    $summaryKey = $travelerId > 0 ? 'traveler:' . $travelerId : 'name:' . mb_strtolower($passengerName);
    if (! isset($passengerSummaryRows[$summaryKey])) {
        $relation = trim((string) ($travelerProfile['type'] ?? ''));
        if ($relation === '') {
            $relation = count($passengerSummaryRows) === 0 ? 'Self' : 'Passenger';
        }
        $remarks = trim((string) ($travelerProfile['notes'] ?? ''));
        if ($remarks === '' && count($passengerSummaryRows) === 0) {
            $remarks = 'Lead Traveler';
        }

        $passengerSummaryRows[$summaryKey] = [
            'travelerId' => $travelerId,
            'passengerName' => $passengerName,
            'relation' => $relation,
            'routes' => [],
            'pnrs' => [],
            'invoiceAmount' => 0.0,
            'paidAmount' => 0.0,
            'outstandingAmount' => 0.0,
            'currency' => (string) ($serviceLine['currency'] ?? $invoiceCurrency),
            'remarks' => $remarks,
        ];
    }

    $passengerSummaryRows[$summaryKey]['routes'][$serviceRoute] = true;
    $servicePnr = trim((string) ($serviceLine['pnr'] ?? ''));
    if ($servicePnr !== '') {
        $passengerSummaryRows[$summaryKey]['pnrs'][$servicePnr] = true;
    }
    $passengerSummaryRows[$summaryKey]['invoiceAmount'] += round((float) ($serviceLine['rowReceivable'] ?? 0), 2);
    $passengerSummaryRows[$summaryKey]['paidAmount'] += max(
        0,
        round((float) ($serviceLine['allocatedAmount'] ?? 0), 2)
    );
    $passengerSummaryRows[$summaryKey]['outstandingAmount'] += round((float) ($serviceLine['outstandingAmount'] ?? 0), 2);
}

if ($passengerSummaryRows === []) {
    foreach ($travelers as $travelerIndex => $travelerRow) {
        $passengerName = trim((string) ($travelerRow['fullName'] ?? ''));
        if ($passengerName === '') {
            continue;
        }

        $summaryKey = 'traveler:' . (int) ($travelerRow['travelerId'] ?? 0) . ':' . $travelerIndex;
        $passengerSummaryRows[$summaryKey] = [
            'travelerId' => (int) ($travelerRow['travelerId'] ?? 0),
            'passengerName' => $passengerName,
            'relation' => $travelerIndex === 0 ? 'Self' : (string) ($travelerRow['type'] ?? 'Passenger'),
            'routes' => ['N/A' => true],
            'pnrs' => [],
            'invoiceAmount' => 0.0,
            'paidAmount' => 0.0,
            'outstandingAmount' => 0.0,
            'currency' => $invoiceCurrency,
            'remarks' => $travelerIndex === 0 ? 'Lead Traveler' : (string) ($travelerRow['notes'] ?? ''),
        ];
    }
}

$passengerSummaryRows = array_values(array_map(
    static function (array $row) use ($formatMoney): array {
        $routeLabels = array_keys($row['routes'] ?? []);
        sort($routeLabels);
        $pnrLabels = array_keys($row['pnrs'] ?? []);
        sort($pnrLabels);
        $currency = (string) ($row['currency'] ?? 'PKR');

        return [
            'travelerId' => (int) ($row['travelerId'] ?? 0),
            'passengerName' => (string) ($row['passengerName'] ?? ''),
            'relation' => (string) ($row['relation'] ?? 'Passenger'),
            'pnr' => $pnrLabels !== [] ? implode(' / ', $pnrLabels) : 'N/A',
            'route' => $routeLabels !== [] ? implode(' | ', $routeLabels) : 'N/A',
            'invoiceAmountDisplay' => $currency . ' ' . $formatMoney((float) ($row['invoiceAmount'] ?? 0)),
            'paidDisplay' => $currency . ' ' . $formatMoney((float) ($row['paidAmount'] ?? 0)),
            'outstandingDisplay' => $currency . ' ' . $formatMoney((float) ($row['outstandingAmount'] ?? 0)),
            'remarks' => (string) ($row['remarks'] ?? ''),
        ];
    },
    $passengerSummaryRows
));

$ledgerMetrics = [
    ['label' => 'Booking No.', 'values' => [['currency' => '', 'amount' => $workspaceBooking['number']]]],
    ['label' => 'Status', 'values' => [['currency' => '', 'amount' => $bookingStatusOptions[$workspaceBooking['status']] ?? 'Draft']]],
    ['label' => 'Branch', 'values' => [['currency' => '', 'amount' => $workspaceBooking['branch']]]],
    ['label' => 'Booking Date', 'values' => [['currency' => '', 'amount' => $workspaceBooking['bookingDate']]]],
    ['label' => 'Departure', 'values' => [['currency' => '', 'amount' => $workspaceBooking['departureDate'] !== '' ? $workspaceBooking['departureDate'] : 'Not Set']]],
    ['label' => 'Return', 'values' => [['currency' => '', 'amount' => $workspaceBooking['returnDate'] !== '' ? $workspaceBooking['returnDate'] : 'Not Set']]],
    ['label' => 'Receivable', 'values' => $metricRows($receivableTotals)],
    ['label' => 'Payable', 'values' => $metricRows($payableTotals)],
    ['label' => 'Outstanding', 'values' => $metricRows($outstandingTotals)],
    ['label' => 'Supplier Outstanding', 'values' => $metricRows($supplierOutstandingTotals)],
];

$serviceLinesJson = json_encode($serviceLines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
$travelersJson = json_encode($travelers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
$customerDirectoryJson = json_encode($travelerSearchResults, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
$serviceSaveDebugJson = $serviceSaveDebug !== null
    ? (json_encode($serviceSaveDebug, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}')
    : null;

require __DIR__ . '/partials/station.php';
return;
