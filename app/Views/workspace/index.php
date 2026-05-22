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
$documents = is_array($documents ?? null) ? $documents : [];
$documentLinkTargets = is_array($documentLinkTargets ?? null) ? $documentLinkTargets : [];
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
$serviceTypeOptions = ['air ticket', 'visa', 'umrah', 'tourism', 'hotel', 'transport', 'other'];
$serviceStatusOptions = ['Booked', 'Docs Pending', 'Reserved', 'Open', 'Delivered', 'Cancelled'];
$paymentMethodOptions = ['cash', 'bank transfer', 'debit card', 'credit card'];
$documentTypeOptions = is_array($documentTypeOptions ?? null) ? $documentTypeOptions : [
    'passport_copy' => 'Passport Copy',
    'visa_copy' => 'Visa Copy',
    'ticket_copy' => 'Ticket Copy',
    'payment_proof' => 'Payment Proof',
    'supplier_invoice' => 'Supplier Invoice / Supporting Doc',
    'general_attachment' => 'General Attachment',
];
$printFormats = ['A4 Portrait', 'A4 Landscape', 'Thermal Receipt'];

$activeBranchId = (int) ($currentBookingRecord['branch_id'] ?? ($selectedTravelerProfile['branch_id'] ?? ($accessibleBranchIds[0] ?? 0)));
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
    'totalSale' => 'PKR 0.00',
    'totalCost' => 'PKR 0.00',
    'profitLoss' => 'PKR 0.00',
    'totalReceivable' => 'PKR 0.00',
    'totalPayable' => 'PKR 0.00',
    'totalReceived' => 'PKR 0.00',
    'totalOutstanding' => 'PKR 0.00',
    'totalSupplierPaid' => 'PKR 0.00',
    'totalSupplierOutstanding' => 'PKR 0.00',
    'remarks' => $bookingRemarks,
    'partyNotes' => $partyNotes,
    'createdAt' => (string) ($currentBookingRecord['created_at'] ?? ''),
    'updatedAt' => (string) ($currentBookingRecord['updated_at'] ?? ''),
];

$serviceLines = array_map(static function (array $serviceRow): array {
    $isAirTicket = (string) ($serviceRow['service_type'] ?? 'air ticket') === 'air ticket';
    $derivedFinalSalePrice = $isAirTicket
        ? (float) ($serviceRow['purchase_cost'] ?? 0) + (float) ($serviceRow['service_charge'] ?? 0) + (float) ($serviceRow['vat'] ?? 0) - (float) ($serviceRow['discount_amount'] ?? 0)
        : (float) ($serviceRow['sale_price'] ?? 0) + (float) ($serviceRow['service_charge'] ?? 0) + (float) ($serviceRow['vat'] ?? 0) - (float) ($serviceRow['discount_amount'] ?? 0);
    $finalSalePrice = array_key_exists('final_sale_price', $serviceRow) && $serviceRow['final_sale_price'] !== null
        ? (float) $serviceRow['final_sale_price']
        : $derivedFinalSalePrice;
    $profit = array_key_exists('net_profit_loss', $serviceRow)
        ? (float) ($serviceRow['net_profit_loss'] ?? 0)
        : round($finalSalePrice - (float) ($serviceRow['purchase_cost'] ?? 0), 2);

    return [
        'serviceId' => (int) ($serviceRow['id'] ?? 0),
        'lineNumber' => (string) ($serviceRow['line_reference'] ?? 'SV-DRAFT'),
        'type' => (string) ($serviceRow['service_type'] ?? 'air ticket'),
        'supplierId' => (int) ($serviceRow['supplier_id'] ?? 0),
        'supplier' => (string) ($serviceRow['supplier_name'] ?? $serviceRow['supplier_name_snapshot'] ?? 'Supplier not selected'),
        'travelerId' => (int) ($serviceRow['traveler_id'] ?? 0),
        'passengerName' => (string) ($serviceRow['passenger_name'] ?? $serviceRow['passenger_name_snapshot'] ?? ''),
        'currency' => (string) ($serviceRow['currency'] ?? 'PKR'),
        'salePrice' => (float) ($serviceRow['sale_price'] ?? 0),
        'purchaseCost' => (float) ($serviceRow['purchase_cost'] ?? 0),
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
        'latestRefundEventDate' => (string) ($serviceRow['latest_refund_event_date'] ?? ''),
        'latestRefundCustomerAmount' => (float) ($serviceRow['latest_refund_customer_amount'] ?? 0),
        'latestRefundSupplierAmount' => (float) ($serviceRow['latest_refund_supplier_amount'] ?? 0),
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
        'salePrice' => 0.00,
        'purchaseCost' => 0.00,
        'taxes' => 0.00,
        'otherFare' => 0.00,
        'sotoFare' => 0.00,
        'spyiAmount' => 0.00,
        'aqYrPkAmount' => 0.00,
        'yqAmount' => 0.00,
        'othAmount' => 0.00,
        'vatInput' => 0.00,
        'vat' => 0.00,
        'commission' => 0.00,
        'serviceCharge' => 0.00,
        'discountAmount' => 0.00,
        'finalSalePrice' => 0.00,
        'netProfitLoss' => 0.00,
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
        'fare' => 0.00,
        'ticketTax' => 0.00,
        'ticketVat' => 0.00,
        'ticketCommission' => 0.00,
        'supplierCost' => 0.00,
        'saleAmount' => 0.00,
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
        'profit' => 0.00,
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

$formatMoney = static fn (float $amount): string => number_format($amount, 2);
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
        return 'PKR 0.00';
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
    $derivedPayable = round((float) ($serviceLine['purchaseCost'] ?? 0), 2);
    $resolvedReceivable = array_key_exists($lineKey, $receivableByLine)
        ? (float) ($receivableByLine[$lineKey]['dueAmount'] ?? 0)
        : $derivedReceivable;
    $resolvedPayable = array_key_exists($lineKey, $payableByLine)
        ? (float) ($payableByLine[$lineKey]['grossAmount'] ?? 0)
        : $derivedPayable;

    $serviceLine['rowSpTotal'] = round($resolvedReceivable, 2);
    $serviceLine['rowReceivable'] = round($resolvedReceivable, 2);
    $serviceLine['rowPayable'] = round($resolvedPayable, 2);
    $serviceLine['rowProfit'] = round($resolvedReceivable - $resolvedPayable, 2);
    $serviceLine['rowFinancialSource'] = array_key_exists($lineKey, $receivableByLine) || array_key_exists($lineKey, $payableByLine)
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
        'status' => ucwords((string) ($document['status'] ?? 'active')),
        'updatedAt' => (string) ($document['updated_at'] ?? ''),
        'fileName' => (string) ($document['original_file_name'] ?? ''),
        'fileSize' => $formatBytes((int) ($document['file_size_bytes'] ?? 0)),
        'notes' => (string) ($document['notes'] ?? ''),
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
__halt_compiler();

<section class="page-head">
    <div>
        <h1>Booking Workspace</h1>
        <p>Compact front-desk workspace for bookings, services, follow-up, and accounting visibility.</p>
    </div>
    <div class="page-actions workspace-head-actions">
        <span class="workspace-mode-chip">Employee Default Workspace</span>
        <button class="btn btn-primary" type="button" data-workspace-action="new-booking">New Booking</button>
        <button class="btn" type="button" data-workspace-action="search-booking">Search Booking</button>
    </div>
</section>

<div class="context-strip workspace-context-strip">
    <span>Accessible Branches:</span>
    <strong><?= e($workspaceBooking['branch']) ?></strong>
    <span class="workspace-context-divider">|</span>
    <span>User:</span>
    <strong><?= e((string) ($user['username'] ?? $user['email'] ?? 'Unknown')) ?></strong>
</div>

<section class="panel compact-panel booking-workspace-shell">
    <div class="workspace-feedback" data-workspace-feedback aria-live="polite"></div>
    <div class="booking-action-bar">
        <button class="btn btn-primary btn-sm" type="button" accesskey="n" data-workspace-action="new-booking">New Booking</button>
        <button class="btn btn-sm" type="button" accesskey="s" data-workspace-action="search-booking">Search Booking</button>
        <button class="btn btn-sm" type="button" data-workspace-action="add-traveler">Add Traveler</button>
        <button class="btn btn-sm" type="button" data-workspace-action="add-service">Add Service</button>
        <button class="btn btn-sm" type="button" data-workspace-action="add-payment">Add Payment</button>
        <button class="btn btn-sm" type="button" data-workspace-action="add-reminder">Add Reminder</button>
        <button class="btn btn-sm" type="button" data-workspace-action="print">Print</button>
        <div class="workspace-search-inline">
            <label for="workspace-search">Quick Search</label>
            <input
                id="workspace-search"
                type="text"
                placeholder="Booking no / traveler / mobile / passport / supplier ref"
                aria-label="Search booking workspace"
            >
        </div>
    </div>

    <div class="booking-summary-band">
        <div class="summary-metric">
            <span>Booking Number</span>
            <strong><?= e($workspaceBooking['number']) ?></strong>
        </div>
        <div class="summary-metric">
            <span>Branch</span>
            <strong><?= e($workspaceBooking['branch']) ?></strong>
        </div>
        <div class="summary-metric">
            <span>Booking Date</span>
            <strong><?= e($workspaceBooking['bookingDate']) ?></strong>
        </div>
        <div class="summary-metric">
            <span>Status</span>
            <strong><?= e($workspaceBooking['status']) ?></strong>
        </div>
        <div class="summary-metric summary-metric-wide">
            <span>Lead Traveler / Booking Party</span>
            <strong><?= e($workspaceBooking['lead']) ?></strong>
        </div>
        <div class="summary-metric">
            <span>Mobile</span>
            <strong><?= e($workspaceBooking['mobile']) ?></strong>
        </div>
        <div class="summary-metric">
            <span>Passport</span>
            <strong><?= e($workspaceBooking['passport']) ?></strong>
        </div>
        <div class="summary-metric">
            <span>Service Mix</span>
            <strong><?= e($workspaceBooking['serviceMix']) ?></strong>
        </div>
        <div class="summary-metric">
            <span>Booking Currency</span>
            <strong><?= e($workspaceBooking['currency']) ?></strong>
        </div>
        <div class="summary-metric financial">
            <span>Total Sale</span>
            <strong><?= e($workspaceBooking['totalSale']) ?></strong>
        </div>
        <div class="summary-metric financial">
            <span>Total Cost</span>
            <strong><?= e($workspaceBooking['totalCost']) ?></strong>
        </div>
        <div class="summary-metric financial">
            <span>Profit / Loss</span>
            <strong><?= e($workspaceBooking['profitLoss']) ?></strong>
        </div>
        <div class="summary-metric financial">
            <span>Total Receivable</span>
            <strong><?= e($workspaceBooking['totalReceivable']) ?></strong>
        </div>
        <div class="summary-metric financial">
            <span>Total Payable</span>
            <strong><?= e($workspaceBooking['totalPayable']) ?></strong>
        </div>
        <div class="summary-metric financial">
            <span>Total Received</span>
            <strong><?= e($workspaceBooking['totalReceived']) ?></strong>
        </div>
        <div class="summary-metric financial emphasis">
            <span>Total Outstanding</span>
            <strong><?= e($workspaceBooking['totalOutstanding']) ?></strong>
        </div>
        <div class="summary-metric financial">
            <span>Supplier Paid</span>
            <strong><?= e($workspaceBooking['totalSupplierPaid']) ?></strong>
        </div>
        <div class="summary-metric financial emphasis">
            <span>Supplier Outstanding</span>
            <strong><?= e($workspaceBooking['totalSupplierOutstanding']) ?></strong>
        </div>
    </div>
</section>

<section class="booking-workspace-grid">
    <aside class="panel compact-panel workspace-left-rail" data-workspace-section="search">
        <div class="panel-header booking-panel-header">
            <div>
                <h2>Search and Queue</h2>
                <div class="panel-meta">Quick access to recent invoices and branch activity.</div>
            </div>
            <div class="workspace-shortcuts">F2 Search | F4 New</div>
        </div>

        <div class="workspace-filter-grid">
            <input type="text" placeholder="Booking No">
            <input type="text" placeholder="Lead Traveler">
            <input type="text" placeholder="Mobile">
            <input type="text" placeholder="Passport">
            <select aria-label="Branch filter">
                <option>All Accessible Branches</option>
            </select>
            <select aria-label="Status filter">
                <option>All Statuses</option>
                <option>Draft</option>
                <option>Confirmed</option>
                <option>In Progress</option>
            </select>
        </div>

        <div class="dense-table-wrap">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Lead</th>
                        <th>Mix</th>
                        <th>Outst.</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="is-active">
                        <td><?= e($workspaceBooking['number']) ?></td>
                        <td><?= e($workspaceBooking['lead'] !== '' ? $workspaceBooking['lead'] : 'No customer selected') ?></td>
                        <td>Ticket</td>
                        <td>0.00</td>
                    </tr>
                    <tr>
                        <td>BK-000002</td>
                        <td>Recent booking</td>
                        <td>Visa</td>
                        <td>12,500</td>
                    </tr>
                    <tr>
                        <td colspan="4" class="empty-cell">Open a booking to continue.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="workspace-mini-stack top-gap">
            <section class="mini-panel">
                <header>
                    <strong>Today's Focus</strong>
                    <span>Daily Follow-Up</span>
                </header>
                <ul class="mini-list">
                    <li>Unconfirmed departures</li>
                    <li>Pending passports</li>
                    <li>Receivable follow-ups</li>
                </ul>
            </section>
            <section class="mini-panel">
                <header>
                    <strong>Booking Notes</strong>
                    <span>Booking Notes</span>
                </header>
                <p>Compact scratchpad zone for booking-level remarks, call notes, and handover context.</p>
            </section>
        </div>
    </aside>

    <div class="workspace-main-column">
        <section class="panel compact-panel booking-tabs-panel">
            <div class="panel-header booking-panel-header">
                <div>
                    <h2>Booking Sections</h2>
                    <div class="panel-meta">Sections for travelers, services, suppliers, collections, reminders, and ledger visibility.</div>
                </div>
                <div class="workspace-shortcuts">Workspace shortcuts</div>
            </div>

            <div class="booking-tab-strip">
                <button class="tab active" type="button" data-tab-target="overview">Overview</button>
                <button class="tab" type="button" data-tab-target="travelers">Travelers</button>
                <button class="tab" type="button" data-tab-target="services">Services</button>
                <button class="tab" type="button" data-tab-target="suppliers">Suppliers</button>
                <button class="tab" type="button" data-tab-target="payments">Payments</button>
                <button class="tab" type="button" data-tab-target="documents">Documents</button>
                <button class="tab" type="button" data-tab-target="reminders">Reminders</button>
                <button class="tab" type="button" data-tab-target="ledger-summary">Ledger Summary</button>
                <button class="tab" type="button" data-tab-target="print">Print</button>
            </div>

            <div class="booking-tab-canvas">
                <section class="workspace-overview-grid">
                    <article class="module-panel" data-workspace-section="overview">
                        <header>
                            <h3>Overview</h3>
                            <span>Operational Snapshot</span>
                        </header>
                        <div class="module-content">
                            <p>Booking progress, service activity, and next actions are shown here.</p>
                            <div class="dense-table-wrap">
                                <table class="dense-table">
                                    <thead>
                                        <tr>
                                            <th>Checkpoint</th>
                                            <th>Status</th>
                                            <th>Next Step</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Client Confirmation</td>
                                            <td>Pending</td>
                                            <td>Call back / collect documents</td>
                                        </tr>
                                        <tr>
                                            <td>Commercial Review</td>
                                            <td>Open</td>
                                            <td>Add or review service lines in the Services tab</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </article>

                    <article class="module-panel" data-workspace-section="travelers">
                        <header>
                            <h3>Travelers</h3>
                            <span>Party Structure</span>
                        </header>
                        <div class="module-content">
                            <p>View the lead traveler, linked passengers, and passport details in one place.</p>

                            <div class="traveler-shell-grid">
                                <section class="traveler-card">
                                    <header>
                                        <strong>Traveler List</strong>
                                        <span>Booking Party</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table traveler-table">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Type</th>
                                                    <th>Name</th>
                                                    <th>Passport</th>
                                                    <th>Expiry</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($travelers as $traveler): ?>
                                                    <tr>
                                                        <td><?= e($traveler['travelerNo']) ?></td>
                                                        <td><?= e($traveler['type']) ?></td>
                                                        <td><?= e($traveler['fullName']) ?></td>
                                                        <td><?= e($traveler['passportNo']) ?></td>
                                                        <td><?= e($traveler['passportExpiry']) ?></td>
                                                        <td><?= e($traveler['status']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="traveler-card">
                                    <header>
                                        <strong>Lead Traveler</strong>
                                        <span>Quick View</span>
                                    </header>
                                    <div class="traveler-summary-grid">
                                        <div><span>Name</span><strong><?= e($travelers[0]['fullName']) ?></strong></div>
                                        <div><span>Mobile</span><strong><?= e($travelers[0]['mobile']) ?></strong></div>
                                        <div><span>Passport</span><strong><?= e($travelers[0]['passportNo']) ?></strong></div>
                                        <div><span>Nationality</span><strong><?= e($travelers[0]['nationality']) ?></strong></div>
                                    </div>
                                </section>
                            </div>

                            <section class="traveler-card">
                                <header>
                                    <strong>Traveler Editor</strong>
                                        <span>Quick Update</span>
                                </header>
                                <div class="traveler-form-grid">
                                    <div class="field">
                                        <span>Traveler Type</span>
                                        <select>
                                            <option>Lead</option>
                                            <option>Adult</option>
                                            <option>Child</option>
                                            <option>Infant</option>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <span>Full Name</span>
                                        <input type="text" value="<?= e($travelers[0]['fullName']) ?>">
                                    </div>
                                    <div class="field">
                                        <span>Gender</span>
                                        <select>
                                            <option>Male</option>
                                            <option>Female</option>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <span>Mobile</span>
                                        <input type="text" value="<?= e($travelers[0]['mobile']) ?>">
                                    </div>
                                    <div class="field">
                                        <span>Passport No</span>
                                        <input type="text" value="<?= e($travelers[0]['passportNo']) ?>">
                                    </div>
                                    <div class="field">
                                        <span>Passport Expiry</span>
                                        <input type="date" value="<?= e($travelers[0]['passportExpiry']) ?>">
                                    </div>
                                    <div class="field">
                                        <span>Nationality</span>
                                        <input type="text" value="<?= e($travelers[0]['nationality']) ?>">
                                    </div>
                                    <div class="field">
                                        <span>Status</span>
                                        <select>
                                            <option>Ready</option>
                                            <option>Passport Check</option>
                                            <option>Docs Pending</option>
                                        </select>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </article>

                    <article class="module-panel module-panel-wide" data-workspace-section="services">
                        <header>
                            <h3>Services</h3>
                            <span>Service Line Engine</span>
                        </header>
                        <div class="module-content">
                            <p>Compact multi-service workspace for air ticket, visa, Umrah, tourism, hotel, transport, and other package items with line-level profit visibility.</p>

                            <div class="service-engine-shell" data-service-engine>
                                <div class="service-type-chip-row">
                                    <span class="service-type-chip active">Air Ticket</span>
                                    <span class="service-type-chip">Visa</span>
                                    <span class="service-type-chip">Umrah</span>
                                    <span class="service-type-chip">Tourism</span>
                                    <span class="service-type-chip">Hotel</span>
                                    <span class="service-type-chip">Transport</span>
                                    <span class="service-type-chip">Other Package</span>
                                </div>

                                <div class="dense-table-wrap">
                                    <table class="dense-table service-grid-table">
                                        <thead>
                                            <tr>
                                                <th>Line</th>
                                                <th>Type</th>
                                                <th>Supplier</th>
                                                <th>Curr.</th>
                                                <th>Sale</th>
                                                <th>Cost</th>
                                                <th>Tax</th>
                                                <th>VAT</th>
                                                <th>Comm.</th>
                                                <th>Srv.</th>
                                                <th>P/L</th>
                                                <th>Due</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($serviceLines as $index => $serviceLine): ?>
                                                <?php $lineProfit = (float) $serviceLine['salePrice'] - ((float) $serviceLine['purchaseCost'] + (float) $serviceLine['taxes'] + (float) $serviceLine['vat']) + (float) $serviceLine['commission'] + (float) $serviceLine['serviceCharge']; ?>
                                                <tr class="<?= $index === 0 ? 'is-active' : '' ?>" data-service-row data-service-index="<?= e((string) $index) ?>">
                                                    <td><?= e($serviceLine['lineNumber']) ?></td>
                                                    <td><?= e(ucwords($serviceLine['type'])) ?></td>
                                                    <td><?= e($serviceLine['supplier']) ?></td>
                                                    <td><?= e($serviceLine['currency']) ?></td>
                                                    <td><?= e(number_format((float) $serviceLine['salePrice'], 2)) ?></td>
                                                    <td><?= e(number_format((float) $serviceLine['purchaseCost'], 2)) ?></td>
                                                    <td><?= e(number_format((float) $serviceLine['taxes'], 2)) ?></td>
                                                    <td><?= e(number_format((float) $serviceLine['vat'], 2)) ?></td>
                                                    <td><?= e(number_format((float) $serviceLine['commission'], 2)) ?></td>
                                                    <td><?= e(number_format((float) $serviceLine['serviceCharge'], 2)) ?></td>
                                                    <td class="profit-cell <?= $lineProfit < 0 ? 'negative' : 'positive' ?>"><?= e(number_format($lineProfit, 2)) ?></td>
                                                    <td><?= e($serviceLine['dueDate']) ?></td>
                                                    <td><?= e($serviceLine['status']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="service-editor-grid" data-legacy-service-editor>
                                    <section class="service-editor-card">
                                        <header>
                                            <strong>Service Line Editor</strong>
                                        <span>Service Details</span>
                                        </header>
                                        <div class="service-form-grid">
                                            <div class="field">
                                                <span>Service Type</span>
                                                <select data-legacy-service-field="type">
                                                    <option value="air ticket">Air Ticket</option>
                                                    <option value="visa">Visa</option>
                                                    <option value="umrah">Umrah</option>
                                                    <option value="tourism">Tourism</option>
                                                    <option value="hotel">Hotel</option>
                                                    <option value="transport">Transport</option>
                                                    <option value="other">Other Package</option>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <span>Supplier</span>
                                                <input type="text" value="<?= e($serviceLines[0]['supplier']) ?>" data-legacy-service-field="supplier">
                                            </div>
                                            <div class="field">
                                                <span>Booking Currency</span>
                                                <select data-legacy-service-field="currency">
                                                    <option value="PKR">PKR</option>
                                                    <option value="AED">AED</option>
                                                    <option value="USD">USD</option>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <span>Status</span>
                                                <select data-legacy-service-field="status">
                                                    <option value="Planned">Planned</option>
                                                    <option value="Booked" selected>Booked</option>
                                                    <option value="Docs Pending">Docs Pending</option>
                                                    <option value="Reserved">Reserved</option>
                                                    <option value="Completed">Completed</option>
                                                    <option value="Cancelled">Cancelled</option>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <span>Sale Price</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['salePrice']) ?>" data-legacy-service-metric="sale">
                                            </div>
                                            <div class="field">
                                                <span>Purchase Cost</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['purchaseCost']) ?>" data-legacy-service-metric="cost">
                                            </div>
                                            <div class="field">
                                                <span>Taxes</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['taxes']) ?>" data-legacy-service-metric="tax">
                                            </div>
                                            <div class="field">
                                                <span>VAT</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['vat']) ?>" data-legacy-service-metric="vat">
                                            </div>
                                            <div class="field">
                                                <span>Commission</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['commission']) ?>" data-legacy-service-metric="commission">
                                            </div>
                                            <div class="field">
                                                <span>Service Charge</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['serviceCharge']) ?>" data-legacy-service-metric="service_charge">
                                            </div>
                                            <div class="field">
                                                <span>Due Date</span>
                                                <input type="date" value="<?= e($serviceLines[0]['dueDate']) ?>" data-legacy-service-field="due_date">
                                            </div>
                                            <div class="field service-profit-box">
                                                <span>Live Profit / Loss</span>
                                                <strong data-legacy-service-profit><?= e(number_format((float) $serviceLines[0]['salePrice'] - ((float) $serviceLines[0]['purchaseCost'] + (float) $serviceLines[0]['taxes'] + (float) $serviceLines[0]['vat']) + (float) $serviceLines[0]['commission'] + (float) $serviceLines[0]['serviceCharge'], 2)) ?></strong>
                                            </div>
                                            <div class="field field-span-2">
                                                <span>Remarks</span>
                                                <input type="text" value="<?= e($serviceLines[0]['remarks']) ?>" data-legacy-service-field="remarks">
                                            </div>
                                        </div>
                                    </section>

                                    <section class="service-editor-card air-ticket-card" data-legacy-air-ticket-panel>
                                        <header>
                                            <strong>Air Ticket Detail</strong>
                                            <span>Subtype Panel</span>
                                        </header>
                                        <div class="service-form-grid">
                                            <div class="field">
                                                <span>PNR</span>
                                                <input type="text" value="<?= e($serviceLines[0]['pnr']) ?>" data-legacy-ticket-field="pnr">
                                            </div>
                                            <div class="field">
                                                <span>Ticket Number</span>
                                                <input type="text" value="<?= e($serviceLines[0]['ticketNumber']) ?>" data-legacy-ticket-field="ticket_number">
                                            </div>
                                            <div class="field">
                                                <span>Airline</span>
                                                <input type="text" value="<?= e($serviceLines[0]['airline']) ?>" data-legacy-ticket-field="airline">
                                            </div>
                                            <div class="field">
                                                <span>Class</span>
                                                <input type="text" value="<?= e($serviceLines[0]['class']) ?>" data-legacy-ticket-field="class">
                                            </div>
                                            <div class="field">
                                                <span>Sector / From</span>
                                                <input type="text" value="<?= e($serviceLines[0]['sectorFrom']) ?>" data-legacy-ticket-field="sector_from">
                                            </div>
                                            <div class="field">
                                                <span>Sector / To</span>
                                                <input type="text" value="<?= e($serviceLines[0]['sectorTo']) ?>" data-legacy-ticket-field="sector_to">
                                            </div>
                                            <div class="field">
                                                <span>Departure Date</span>
                                                <input type="date" value="<?= e($serviceLines[0]['departureDate']) ?>" data-legacy-ticket-field="departure_date">
                                            </div>
                                            <div class="field">
                                                <span>Return Date</span>
                                                <input type="date" value="<?= e($serviceLines[0]['returnDate']) ?>" data-legacy-ticket-field="return_date">
                                            </div>
                                            <div class="field">
                                                <span>Fare</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['fare']) ?>" data-legacy-ticket-metric="fare">
                                            </div>
                                            <div class="field">
                                                <span>Tax</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['ticketTax']) ?>" data-legacy-ticket-metric="tax">
                                            </div>
                                            <div class="field">
                                                <span>VAT</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['ticketVat']) ?>" data-legacy-ticket-metric="vat">
                                            </div>
                                            <div class="field">
                                                <span>Commission</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['ticketCommission']) ?>" data-legacy-ticket-metric="commission">
                                            </div>
                                            <div class="field">
                                                <span>Supplier Cost</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['supplierCost']) ?>" data-legacy-ticket-metric="supplier_cost">
                                            </div>
                                            <div class="field">
                                                <span>Sale Amount</span>
                                                <input type="number" step="0.01" value="<?= e((string) $serviceLines[0]['saleAmount']) ?>" data-legacy-ticket-metric="sale_amount">
                                            </div>
                                            <div class="field field-span-2">
                                                <span>Reissue / Refund / Cancel Remarks</span>
                                                <input type="text" value="<?= e($serviceLines[0]['ticketRemarks']) ?>" data-legacy-ticket-field="ticket_remarks">
                                            </div>
                                        </div>
                                    </section>
                                </div>

                                <div class="service-accounting-strip">
                                    <div>
                                        <span>Immediate Receivable Basis</span>
                                        <strong>Ticket: Fr+Tx + Service Charge - Discount / Non-Ticket: Base Sale + Service Charge - Discount</strong>
                                    </div>
                                    <div>
                                        <span>Immediate Payable Basis</span>
                                        <strong>Ticket: Fr+Tx / Non-Ticket: Vendor Cost</strong>
                                    </div>
                                    <div>
                                        <span>Posting Readiness</span>
                                        <strong>One currency per service line</strong>
                                    </div>
                                </div>

                                <script id="workspace-service-lines-data" type="application/json"><?= $serviceLinesJson ?></script>
                            </div>
                        </div>
                    </article>

                    <article class="module-panel module-panel-wide" data-workspace-section="suppliers">
                        <header>
                            <h3>Suppliers</h3>
                            <span>Payable and Advance Control</span>
                        </header>
                        <div class="module-content">
                            <p>Review supplier-linked services, payable balances, payments, and advances for the current booking.</p>

                            <div class="supplier-summary-strip">
                                <div>
                                    <span>Total Supplier Payable</span>
                                    <strong><?= e(number_format((float) ($supplierFoundation['totals']['totalPayable'] ?? 0), 2)) ?></strong>
                                </div>
                                <div>
                                    <span>Advance Used</span>
                                    <strong><?= e(number_format((float) ($supplierFoundation['totals']['totalAdvanceApplied'] ?? 0), 2)) ?></strong>
                                </div>
                                <div>
                                    <span>Paid to Suppliers</span>
                                    <strong><?= e(number_format((float) ($supplierFoundation['totals']['totalSupplierPaid'] ?? 0), 2)) ?></strong>
                                </div>
                                <div>
                                    <span>Supplier Balance Due</span>
                                    <strong><?= e(number_format((float) ($supplierFoundation['totals']['totalSupplierOutstanding'] ?? 0), 2)) ?></strong>
                                </div>
                                <div>
                                    <span>Supplier Advance / Credit</span>
                                    <strong><?= e(number_format((float) ($supplierFoundation['totals']['totalAdvanceBalance'] ?? 0), 2)) ?></strong>
                                </div>
                            </div>

                            <div class="supplier-panel-grid">
                                <section class="supplier-card">
                                    <header>
                                        <strong>Supplier Summary</strong>
                                        <span>By Supplier</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table">
                                            <thead>
                                                <tr>
                                                    <th>Code</th>
                                                    <th>Supplier</th>
                                                    <th>Mode</th>
                                                    <th>Curr.</th>
                                                    <th>Advance Avl.</th>
                                                    <th>Gross</th>
                                                    <th>Paid</th>
                                                    <th>Unalloc.</th>
                                                    <th>Balance</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (($supplierFoundation['suppliers'] ?? []) === []): ?>
                                                    <tr><td colspan="8" class="empty-cell">No supplier-linked services yet.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach (($supplierFoundation['suppliers'] ?? []) as $supplier): ?>
                                                    <tr>
                                                        <td><?= e($supplier['code']) ?></td>
                                                        <td><?= e($supplier['name']) ?></td>
                                                        <td><?= e($supplier['mode'] === 'running_balance' ? 'Running Balance' : 'Normal Payable') ?></td>
                                                        <td><?= e($supplier['currency']) ?></td>
                                                        <td><?= e(number_format((float) $supplier['advanceBalance'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $supplier['grossObligation'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $supplier['totalPaid'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $supplier['unallocatedPaid'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $supplier['balanceDue'], 2)) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="supplier-card">
                                    <header>
                                        <strong>Service Supplier Position</strong>
                                        <span>Per Service Line</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table supplier-obligation-table">
                                            <thead>
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Svc Line</th>
                                                    <th>Mode</th>
                                                    <th>Curr.</th>
                                                    <th>Gross</th>
                                                    <th>Adv. Used</th>
                                                    <th>Paid</th>
                                                    <th>Balance</th>
                                                    <th>Due</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (($supplierFoundation['obligations'] ?? []) === []): ?>
                                                    <tr><td colspan="9" class="empty-cell">No supplier obligations for this booking yet.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach (($supplierFoundation['obligations'] ?? []) as $obligation): ?>
                                                    <tr>
                                                        <td><?= e($obligation['supplier']) ?></td>
                                                        <td><?= e($obligation['serviceLineReference']) ?></td>
                                                        <td><?= e($obligation['mode'] === 'running_balance' ? 'Running Balance' : 'Normal Payable') ?></td>
                                                        <td><?= e($obligation['currency']) ?></td>
                                                        <td><?= e(number_format((float) $obligation['grossAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $obligation['advanceAppliedAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $obligation['paymentAllocatedAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $obligation['balanceDueAmount'], 2)) ?></td>
                                                        <td><?= e($obligation['dueDate']) ?></td>
                                                        <td><?= e($obligation['status']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            </div>

                            <div class="payment-history-grid">
                                <section class="payment-card">
                                    <header>
                                        <strong>Record Supplier Payment</strong>
                                        <span>Full or Partial Settlement</span>
                                    </header>
                                    <form method="post" action="<?= e(url('/workspace/suppliers/payments/save')) ?>">
                                        <?= \App\Helpers\Csrf::input() ?>
                                        <input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>">
                                        <div class="payment-form-grid">
                                            <div class="field">
                                                <span>Supplier</span>
                                                <input type="text" name="supplier_name" list="service-supplier-options" value="">
                                            </div>
                                            <div class="field">
                                                <span>Payment Date</span>
                                                <input type="date" name="supplier_payment_date" value="<?= e(date('Y-m-d')) ?>">
                                            </div>
                                            <div class="field">
                                                <span>Currency</span>
                                                <select name="supplier_payment_currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>"><?= e($currencyOption) ?></option><?php endforeach; ?></select>
                                            </div>
                                            <div class="field">
                                                <span>Paid Amount</span>
                                                <input type="number" step="0.01" name="supplier_paid_amount" value="0.00">
                                            </div>
                                            <div class="field">
                                                <span>Method</span>
                                                <select name="supplier_payment_method"><?php foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'debit_card' => 'Debit Card', 'credit_card' => 'Credit Card'] as $methodValue => $methodLabel): ?><option value="<?= e($methodValue) ?>"><?= e($methodLabel) ?></option><?php endforeach; ?></select>
                                            </div>
                                            <div class="field">
                                                <span>Reference</span>
                                                <input type="text" name="supplier_reference_number" value="">
                                            </div>
                                            <div class="field">
                                                <span>Bank / Card Detail</span>
                                                <input type="text" name="supplier_bank_card_detail" value="">
                                            </div>
                                            <div class="field">
                                                <span>Charges</span>
                                                <input type="number" step="0.01" name="supplier_charges_amount" value="0.00">
                                            </div>
                                            <div class="field">
                                                <span>Status</span>
                                                <select name="supplier_payment_status"><option value="paid">Paid</option><?php if ($canPostServiceEvents): ?><option value="void">Void</option><?php endif; ?></select>
                                            </div>
                                            <div class="field field-span-3">
                                                <span>Remarks</span>
                                                <input type="text" name="supplier_payment_remarks" value="">
                                            </div>
                                        </div>
                                        <div class="station-command-buttons top-gap">
                                            <button class="btn btn-primary btn-sm" type="submit">Save Supplier Payment</button>
                                        </div>
                                    </form>
                                </section>

                                <section class="payment-card">
                                    <header>
                                        <strong>Record Supplier Advance</strong>
                                        <span>Supplier Credit / Deposit</span>
                                    </header>
                                    <form method="post" action="<?= e(url('/workspace/suppliers/advances/save')) ?>">
                                        <?= \App\Helpers\Csrf::input() ?>
                                        <input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>">
                                        <div class="payment-form-grid">
                                            <div class="field">
                                                <span>Supplier</span>
                                                <input type="text" name="supplier_name" list="service-supplier-options" value="">
                                            </div>
                                            <div class="field">
                                                <span>Advance Date</span>
                                                <input type="date" name="advance_date" value="<?= e(date('Y-m-d')) ?>">
                                            </div>
                                            <div class="field">
                                                <span>Currency</span>
                                                <select name="advance_currency"><?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?><option value="<?= e($currencyOption) ?>"><?= e($currencyOption) ?></option><?php endforeach; ?></select>
                                            </div>
                                            <div class="field">
                                                <span>Advance Amount</span>
                                                <input type="number" step="0.01" name="advance_amount" value="0.00">
                                            </div>
                                            <div class="field">
                                                <span>Reference</span>
                                                <input type="text" name="advance_reference_number" value="">
                                            </div>
                                            <div class="field field-span-3">
                                                <span>Remarks</span>
                                                <input type="text" name="advance_remarks" value="">
                                            </div>
                                        </div>
                                        <div class="station-command-buttons top-gap">
                                            <button class="btn btn-sm" type="submit">Save Supplier Advance</button>
                                        </div>
                                    </form>
                                </section>
                            </div>

                            <div class="payment-history-grid">
                                <section class="payment-card">
                                    <header>
                                        <strong>Apply Advance</strong>
                                        <span>Offset Current Payable</span>
                                    </header>
                                    <form method="post" action="<?= e(url('/workspace/suppliers/advances/apply')) ?>">
                                        <?= \App\Helpers\Csrf::input() ?>
                                        <input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>">
                                        <div class="payment-form-grid">
                                            <div class="field">
                                                <span>Available Advance</span>
                                                <select name="supplier_advance_id">
                                                    <?php foreach (($supplierFoundation['advances'] ?? []) as $advance): ?>
                                                        <?php if ((float) ($advance['availableAmount'] ?? 0) <= 0) { continue; } ?>
                                                        <option value="<?= e((string) $advance['id']) ?>"><?= e($advance['supplier'] . ' / ' . $advance['currency'] . ' ' . number_format((float) $advance['availableAmount'], 2)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <span>Open Obligation</span>
                                                <select name="supplier_obligation_id">
                                                    <?php foreach (($supplierFoundation['openObligations'] ?? []) as $obligation): ?>
                                                        <option value="<?= e((string) $obligation['id']) ?>"><?= e($obligation['supplier'] . ' / ' . $obligation['serviceLineReference'] . ' / ' . $obligation['currency'] . ' ' . number_format((float) $obligation['netPayableAmount'], 2)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <span>Apply Amount</span>
                                                <input type="number" step="0.01" name="advance_apply_amount" value="0.00">
                                            </div>
                                        </div>
                                        <div class="station-command-buttons top-gap">
                                            <button class="btn btn-sm" type="submit">Apply Advance</button>
                                        </div>
                                    </form>
                                </section>

                                <section class="payment-card">
                                    <header>
                                        <strong>Allocate Supplier Payment</strong>
                                        <span>Use Unallocated Payment</span>
                                    </header>
                                    <form method="post" action="<?= e(url('/workspace/suppliers/payments/allocate')) ?>">
                                        <?= \App\Helpers\Csrf::input() ?>
                                        <input type="hidden" name="booking_id" value="<?= e((string) ($workspaceBooking['id'] ?? 0)) ?>">
                                        <div class="payment-form-grid">
                                            <div class="field">
                                                <span>Unallocated Payment</span>
                                                <select name="supplier_payment_id">
                                                    <?php foreach (($supplierFoundation['allocatablePayments'] ?? []) as $payment): ?>
                                                        <option value="<?= e((string) $payment['id']) ?>"><?= e($payment['paymentNo'] . ' / ' . $payment['supplier'] . ' / ' . $payment['currency'] . ' ' . number_format((float) $payment['unallocatedAmount'], 2)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="dense-table-wrap top-gap">
                                            <table class="dense-table supplier-obligation-table">
                                                <thead>
                                                    <tr>
                                                        <th>Supplier</th>
                                                        <th>Svc Line</th>
                                                        <th>Curr.</th>
                                                        <th>Balance</th>
                                                        <th>Allocate</th>
                                                        <th>Note</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (($supplierFoundation['openObligations'] ?? []) === []): ?>
                                                        <tr><td colspan="6" class="empty-cell">No open supplier obligations to allocate.</td></tr>
                                                    <?php endif; ?>
                                                    <?php foreach (($supplierFoundation['openObligations'] ?? []) as $obligation): ?>
                                                        <tr>
                                                            <td><?= e($obligation['supplier']) ?></td>
                                                            <td><?= e($obligation['serviceLineReference']) ?></td>
                                                            <td><?= e($obligation['currency']) ?></td>
                                                            <td><?= e(number_format((float) $obligation['netPayableAmount'], 2)) ?></td>
                                                            <td>
                                                                <input type="hidden" name="supplier_allocation_obligation_id[]" value="<?= e((string) $obligation['id']) ?>">
                                                                <input type="number" step="0.01" name="supplier_allocation_amount[]" value="0.00">
                                                            </td>
                                                            <td><input type="text" name="supplier_allocation_note[]" value=""></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="station-command-buttons top-gap">
                                            <button class="btn btn-sm" type="submit">Allocate Supplier Payment</button>
                                        </div>
                                    </form>
                                </section>
                            </div>

                            <div class="payment-history-grid">
                                <section class="payment-card">
                                    <header>
                                        <strong>Supplier Payment History</strong>
                                        <span>Booking Linked</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table payment-history-table">
                                            <thead>
                                                <tr>
                                                    <th>Payment</th>
                                                    <th>Date</th>
                                                    <th>Supplier</th>
                                                    <th>Curr.</th>
                                                    <th>Paid</th>
                                                    <th>Allocated</th>
                                                    <th>Unalloc.</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (($supplierFoundation['payments'] ?? []) === []): ?>
                                                    <tr><td colspan="8" class="empty-cell">No supplier payments recorded yet.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach (($supplierFoundation['payments'] ?? []) as $payment): ?>
                                                    <tr>
                                                        <td><?= e($payment['paymentNo']) ?></td>
                                                        <td><?= e($payment['paymentDate']) ?></td>
                                                        <td><?= e($payment['supplier']) ?></td>
                                                        <td><?= e($payment['currency']) ?></td>
                                                        <td><?= e(number_format((float) $payment['paidAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $payment['allocatedAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $payment['unallocatedAmount'], 2)) ?></td>
                                                        <td><?= e($payment['status']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="payment-card">
                                    <header>
                                        <strong>Supplier Advance History</strong>
                                        <span>Available Credit</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table payment-history-table">
                                            <thead>
                                                <tr>
                                                    <th>Supplier</th>
                                                    <th>Date</th>
                                                    <th>Curr.</th>
                                                    <th>Deposit</th>
                                                    <th>Used</th>
                                                    <th>Available</th>
                                                    <th>Ref</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (($supplierFoundation['advances'] ?? []) === []): ?>
                                                    <tr><td colspan="7" class="empty-cell">No supplier advances recorded yet.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach (($supplierFoundation['advances'] ?? []) as $advance): ?>
                                                    <tr>
                                                        <td><?= e($advance['supplier']) ?></td>
                                                        <td><?= e($advance['receivedAt']) ?></td>
                                                        <td><?= e($advance['currency']) ?></td>
                                                        <td><?= e(number_format((float) $advance['depositAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $advance['usedAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $advance['availableAmount'], 2)) ?></td>
                                                        <td><?= e($advance['referenceNo']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            </div>

                            <div class="supplier-rule-strip">
                                <?php foreach (($supplierFoundation['postingNotes'] ?? []) as $postingNote): ?>
                                    <div><?= e($postingNote) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>

                    <article id="dock-panel-payments" class="module-panel module-panel-wide" data-workspace-section="payments" data-dock-panel="payments">
                        <header>
                            <h3>Payments</h3>
                            <span>Receipt Entry and Allocation Control</span>
                        </header>
                        <div class="module-content">
                            <p>Booking-level customer receipts are captured first, then allocated across service receivable items with a visible trail showing partial payments across multiple dates and receipts.</p>

                            <div class="payment-summary-strip">
                                <div>
                                    <span>Total Customer Outstanding</span>
                                    <strong>
                                        <?php foreach (($customerPaymentFoundation['summary']['customerOutstanding'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>Booking Outstanding</span>
                                    <strong>
                                        <?php foreach (($customerPaymentFoundation['summary']['bookingOutstanding'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>Customer Credit</span>
                                    <strong>
                                        <?php foreach (($customerPaymentFoundation['summary']['customerCredit'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>History Count</span>
                                    <strong><?= e((string) (($customerPaymentFoundation['summary']['receiptCount'] ?? 0) + ($customerPaymentFoundation['summary']['allocationCount'] ?? 0))) ?></strong>
                                </div>
                            </div>

                            <div class="payment-panel-grid">
                                <section class="payment-card">
                                    <header>
                                        <strong>Receipt Entry</strong>
                                        <span>Booking Level First</span>
                                    </header>
                                    <div class="payment-form-grid">
                                        <div class="field">
                                            <span>Receipt Date</span>
                                            <input type="date" value="<?= e(date('Y-m-d')) ?>">
                                        </div>
                                        <div class="field">
                                            <span>Receipt Currency</span>
                                            <select>
                                                <option>PKR</option>
                                                <option>AED</option>
                                                <option>USD</option>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <span>Received Amount</span>
                                            <input type="number" step="0.01" value="0.00">
                                        </div>
                                        <div class="field">
                                            <span>Payment Method</span>
                                            <select>
                                                <option>cash</option>
                                                <option>bank transfer</option>
                                                <option>debit card</option>
                                                <option>credit card</option>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <span>Reference Number</span>
                                            <input type="text" placeholder="Receipt / transfer / POS ref">
                                        </div>
                                        <div class="field">
                                            <span>Bank / Card Detail</span>
                                            <input type="text" placeholder="Bank name / card terminal / account detail">
                                        </div>
                                        <div class="field">
                                            <span>Charges</span>
                                            <input type="number" step="0.01" value="0.00">
                                        </div>
                                        <div class="field">
                                            <span>Status</span>
                                            <select>
                                                <option>received</option>
                                                <option>partially_allocated</option>
                                                <option>fully_allocated</option>
                                                <option>void</option>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <span>Exchange Rate</span>
                                            <input type="number" step="0.00000001" value="1.00000000">
                                        </div>
                                        <div class="field field-span-3">
                                            <span>Operational Note</span>
                                            <input type="text" placeholder="Cross-currency receipt note / collection detail">
                                        </div>
                                    </div>
                                </section>

                                <section class="payment-card">
                                    <header>
                                        <strong>Deferred Allocation</strong>
                                        <span>Service Wise</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table payment-outstanding-table">
                                            <thead>
                                                <tr>
                                                    <th>Svc Line</th>
                                                    <th>Type</th>
                                                    <th>Curr.</th>
                                                    <th>Due</th>
                                                    <th>Allocated</th>
                                                    <th>Outstanding</th>
                                                    <th>Status</th>
                                                    <th>Due Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (($customerPaymentFoundation['serviceReceivables'] ?? []) as $receivable): ?>
                                                    <tr>
                                                        <td><?= e($receivable['serviceLineReference']) ?></td>
                                                        <td><?= e($receivable['serviceType']) ?></td>
                                                        <td><?= e($receivable['currency']) ?></td>
                                                        <td><?= e(number_format((float) $receivable['dueAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $receivable['allocatedAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $receivable['outstandingAmount'], 2)) ?></td>
                                                        <td><?= e($receivable['status']) ?></td>
                                                        <td><?= e($receivable['nextDueDate']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="payment-rule-strip">
                                        <?php foreach (($customerPaymentFoundation['rules'] ?? []) as $rule): ?>
                                            <div><?= e($rule) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            </div>

                            <div class="payment-history-grid">
                                <section class="payment-card">
                                    <header>
                                        <strong>Receipt History</strong>
                                        <span>Receipt History</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table payment-history-table">
                                            <thead>
                                                <tr>
                                                    <th>Receipt</th>
                                                    <th>Date</th>
                                                    <th>Curr.</th>
                                                    <th>Received</th>
                                                    <th>Allocated</th>
                                                    <th>Unalloc.</th>
                                                    <th>Method</th>
                                                    <th>Ref</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (($customerPaymentFoundation['receipts'] ?? []) as $receipt): ?>
                                                    <tr>
                                                        <td><?= e($receipt['receiptNo']) ?></td>
                                                        <td><?= e($receipt['receiptDate']) ?></td>
                                                        <td><?= e($receipt['currency']) ?></td>
                                                        <td><?= e(number_format((float) $receipt['receivedAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $receipt['allocatedAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $receipt['unallocatedAmount'], 2)) ?></td>
                                                        <td><?= e(str_replace('_', ' ', $receipt['paymentMethod'])) ?></td>
                                                        <td><?= e($receipt['referenceNumber']) ?></td>
                                                        <td><?= e($receipt['status']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="payment-card">
                                    <header>
                                        <strong>Allocation History</strong>
                                        <span>Allocation History</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table payment-allocation-table">
                                            <thead>
                                                <tr>
                                                    <th>Allocated At</th>
                                                    <th>Receipt</th>
                                                    <th>Svc Line</th>
                                                    <th>Type</th>
                                                    <th>Curr.</th>
                                                    <th>Amount</th>
                                                    <th>%</th>
                                                    <th>Trail</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (($customerPaymentFoundation['allocations'] ?? []) as $allocation): ?>
                                                    <tr>
                                                        <td><?= e($allocation['allocatedAt']) ?></td>
                                                        <td><?= e($allocation['receiptNo']) ?></td>
                                                        <td><?= e($allocation['serviceLineReference']) ?></td>
                                                        <td><?= e($allocation['serviceType']) ?></td>
                                                        <td><?= e($allocation['currency']) ?></td>
                                                        <td><?= e(number_format((float) $allocation['allocatedAmount'], 2)) ?></td>
                                                        <td><?= e(number_format((float) $allocation['allocationPercent'], 2)) ?></td>
                                                        <td><?= e($allocation['allocationTrail']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </article>

                    <article class="module-panel" data-workspace-section="documents">
                        <header>
                            <h3>Documents</h3>
                            <span>Booking Attachments</span>
                        </header>
                        <div class="module-content">
                            <p>Store passport scans, visa files, ticket copies, vouchers, and other booking documents here.</p>
                            <div class="empty-state-line">No documents added yet.</div>
                        </div>
                    </article>

                    <article id="dock-panel-reminders" class="module-panel" data-workspace-section="reminders" data-dock-panel="reminders">
                        <header>
                            <h3>Reminders</h3>
                            <span>Collection Follow-Up</span>
                        </header>
                        <div class="module-content">
                            <p>Use this tab to follow overdue, due-today, pending, and missing-due-date collections.</p>

                            <div class="ledger-rule-strip">
                                <div>Overdue: <?= e((string) $receivableAlertCounts['overdue']) ?></div>
                                <div>Due Today: <?= e((string) $receivableAlertCounts['dueToday']) ?></div>
                                <div>Pending: <?= e((string) $receivableAlertCounts['pending']) ?></div>
                                <div>Missing Due Date: <?= e((string) $receivableAlertCounts['missingDueDate']) ?></div>
                                <div>Total Follow-Up: <?= e((string) $receivableAlertCounts['total']) ?></div>
                            </div>

                            <div class="payment-history-grid">
                                <section class="payment-card">
                                    <header>
                                        <strong>Overdue Receivables</strong>
                                        <span>Immediate Collection</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table payment-outstanding-table">
                                            <thead>
                                                <tr>
                                                    <th>Invoice</th>
                                                    <th>Customer</th>
                                                    <th>Branch</th>
                                                    <th>Curr.</th>
                                                    <th>Outstanding</th>
                                                    <th>Due Date</th>
                                                    <th>Days Overdue</th>
                                                    <th>Services</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (($receivableAlerts['overdue'] ?? []) === []): ?>
                                                    <tr>
                                                        <td colspan="9" class="empty-cell">No overdue receivables in your accessible branches.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach (($receivableAlerts['overdue'] ?? []) as $alert): ?>
                                                    <tr>
                                                        <td><?= e($alert['bookingReference']) ?></td>
                                                        <td>
                                                            <?= e($alert['customerName']) ?>
                                                            <?php if ($alert['contactMobile'] !== ''): ?><br><small><?= e($alert['contactMobile']) ?></small><?php endif; ?>
                                                        </td>
                                                        <td><?= e(trim($alert['branchName'] . ($alert['branchCity'] !== '' ? ', ' . $alert['branchCity'] : ''))) ?></td>
                                                        <td><?= e($alert['currency']) ?></td>
                                                        <td><?= e(number_format((float) $alert['outstandingAmount'], 2)) ?></td>
                                                        <td><?= e($alert['dueDate']) ?></td>
                                                        <td><?= e($alert['daysDelta'] === null ? '' : (string) $alert['daysDelta']) ?></td>
                                                        <td><?= e($alert['serviceSummary']) ?></td>
                                                        <td><a href="<?= e(url('/workspace?booking_id=' . (int) $alert['bookingId'])) ?>">Open Invoice</a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="payment-card">
                                    <header>
                                        <strong>Due Today / Pending</strong>
                                        <span>Upcoming Collection</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table payment-outstanding-table">
                                            <thead>
                                                <tr>
                                                    <th>Status</th>
                                                    <th>Invoice</th>
                                                    <th>Customer</th>
                                                    <th>Branch</th>
                                                    <th>Curr.</th>
                                                    <th>Outstanding</th>
                                                    <th>Due Date</th>
                                                    <th>Days</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ((($receivableAlerts['dueToday'] ?? []) === []) && (($receivableAlerts['pending'] ?? []) === [])): ?>
                                                    <tr>
                                                        <td colspan="9" class="empty-cell">No due-today or pending receivables need follow-up right now.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach (($receivableAlerts['dueToday'] ?? []) as $alert): ?>
                                                    <tr>
                                                        <td>Due Today</td>
                                                        <td><?= e($alert['bookingReference']) ?></td>
                                                        <td><?= e($alert['customerName']) ?></td>
                                                        <td><?= e(trim($alert['branchName'] . ($alert['branchCity'] !== '' ? ', ' . $alert['branchCity'] : ''))) ?></td>
                                                        <td><?= e($alert['currency']) ?></td>
                                                        <td><?= e(number_format((float) $alert['outstandingAmount'], 2)) ?></td>
                                                        <td><?= e($alert['dueDate']) ?></td>
                                                        <td><?= e($alert['daysLabel']) ?></td>
                                                        <td><a href="<?= e(url('/workspace?booking_id=' . (int) $alert['bookingId'])) ?>">Open Invoice</a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php foreach (($receivableAlerts['pending'] ?? []) as $alert): ?>
                                                    <tr>
                                                        <td>Pending</td>
                                                        <td><?= e($alert['bookingReference']) ?></td>
                                                        <td><?= e($alert['customerName']) ?></td>
                                                        <td><?= e(trim($alert['branchName'] . ($alert['branchCity'] !== '' ? ', ' . $alert['branchCity'] : ''))) ?></td>
                                                        <td><?= e($alert['currency']) ?></td>
                                                        <td><?= e(number_format((float) $alert['outstandingAmount'], 2)) ?></td>
                                                        <td><?= e($alert['dueDate']) ?></td>
                                                        <td><?= e($alert['daysLabel']) ?></td>
                                                        <td><a href="<?= e(url('/workspace?booking_id=' . (int) $alert['bookingId'])) ?>">Open Invoice</a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            </div>

                            <section class="payment-card">
                                <header>
                                    <strong>Missing Due Date Follow-Up</strong>
                                    <span>Unassigned Credit Terms</span>
                                </header>
                                <div class="dense-table-wrap">
                                    <table class="dense-table payment-outstanding-table">
                                        <thead>
                                            <tr>
                                                <th>Invoice</th>
                                                <th>Customer</th>
                                                <th>Branch</th>
                                                <th>Curr.</th>
                                                <th>Outstanding</th>
                                                <th>Services</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (($receivableAlerts['missingDueDate'] ?? []) === []): ?>
                                                <tr>
                                                    <td colspan="8" class="empty-cell">All unpaid invoices currently have a due date assigned.</td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php foreach (($receivableAlerts['missingDueDate'] ?? []) as $alert): ?>
                                                <tr>
                                                    <td><?= e($alert['bookingReference']) ?></td>
                                                    <td><?= e($alert['customerName']) ?></td>
                                                    <td><?= e(trim($alert['branchName'] . ($alert['branchCity'] !== '' ? ', ' . $alert['branchCity'] : ''))) ?></td>
                                                    <td><?= e($alert['currency']) ?></td>
                                                    <td><?= e(number_format((float) $alert['outstandingAmount'], 2)) ?></td>
                                                    <td><?= e($alert['serviceSummary']) ?></td>
                                                    <td>Due Date Missing</td>
                                                    <td><a href="<?= e(url('/workspace?booking_id=' . (int) $alert['bookingId'])) ?>">Open Invoice</a></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <?php if ($reminders !== []): ?>
                                <section class="payment-card">
                                    <header>
                                        <strong>Current Booking Reminder Queue</strong>
                                        <span>Current Invoice</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table payment-history-table">
                                            <thead>
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Title</th>
                                                    <th>Due At</th>
                                                    <th>Channel</th>
                                                    <th>Status</th>
                                                    <th>Priority</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reminders as $reminder): ?>
                                                    <tr>
                                                        <td><?= e($reminderTypeOptions[(string) ($reminder['reminder_type'] ?? 'custom_manual')] ?? (string) ($reminder['reminder_type'] ?? 'Reminder')) ?></td>
                                                        <td><?= e((string) ($reminder['title'] ?? '')) ?></td>
                                                        <td><?= e((string) ($reminder['due_at'] ?? '')) ?></td>
                                                        <td><?= e((string) ($reminder['channel'] ?? '')) ?></td>
                                                        <td><?= e(ucwords(str_replace('_', ' ', (string) ($reminder['status'] ?? 'open')))) ?></td>
                                                        <td><?= e(ucwords((string) ($reminder['priority'] ?? 'normal'))) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            <?php endif; ?>
                        </div>
                    </article>

                    <article id="dock-panel-ledger-summary" class="module-panel" data-workspace-section="ledger-summary" data-dock-panel="ledger-summary">
                        <header>
                            <h3>Ledger Summary</h3>
                            <span>Accounting Underneath</span>
                        </header>
                        <div class="module-content">
                            <p>Review the booking’s receivable, payable, receipt, and journal position here.</p>
                            <div class="ledger-summary-strip">
                                <div>
                                    <span>Total Receivable</span>
                                    <strong>
                                        <?php foreach (($accountingFoundation['summary']['totalReceivable'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>Total Payable</span>
                                    <strong>
                                        <?php foreach (($accountingFoundation['summary']['totalPayable'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>Total Received</span>
                                    <strong>
                                        <?php foreach (($accountingFoundation['summary']['totalReceived'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>Total Outstanding</span>
                                    <strong>
                                        <?php foreach (($accountingFoundation['summary']['totalOutstanding'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>Total Supplier Paid</span>
                                    <strong>
                                        <?php foreach (($accountingFoundation['summary']['totalSupplierPaid'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>Total Supplier Outstanding</span>
                                    <strong>
                                        <?php foreach (($accountingFoundation['summary']['totalSupplierOutstanding'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                                <div>
                                    <span>Profit / Loss Snapshot</span>
                                    <strong>
                                        <?php foreach (($accountingFoundation['summary']['profitLossSnapshot'] ?? []) as $currencyCode => $amount): ?>
                                            <?= e($currencyCode . ' ' . number_format((float) $amount, 2)) ?><br>
                                        <?php endforeach; ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="ledger-journal-grid">
                                <section class="ledger-card">
                                    <header>
                                        <strong>Journal Preview</strong>
                                        <span>Double Entry</span>
                                    </header>
                                    <div class="dense-table-wrap">
                                        <table class="dense-table ledger-journal-table">
                                            <thead>
                                                <tr>
                                                    <th>Event</th>
                                                    <th>Ref</th>
                                                    <th>Curr.</th>
                                                    <th>Debit</th>
                                                    <th>Credit</th>
                                                    <th>Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (($accountingFoundation['journalPreview'] ?? []) === []): ?>
                                                    <tr>
                                                        <td colspan="6" class="empty-cell">No posted journal entries yet for this booking.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach (($accountingFoundation['journalPreview'] ?? []) as $journalRow): ?>
                                                    <tr>
                                                        <td><?= e($journalRow['event']) ?></td>
                                                        <td><?= e($journalRow['reference']) ?></td>
                                                        <td><?= e($journalRow['currency']) ?></td>
                                                        <td><?= e($journalRow['debit']) ?></td>
                                                        <td><?= e($journalRow['credit']) ?></td>
                                                        <td><?= e(number_format((float) $journalRow['amount'], 2)) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="ledger-card">
                                    <header>
                                        <strong>Posting Rules</strong>
                                        <span>Accounting Rules</span>
                                    </header>
                                    <div class="ledger-rule-strip">
                                        <?php foreach (($accountingFoundation['rules'] ?? []) as $ledgerRule): ?>
                                            <div><?= e($ledgerRule) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </article>

                    <article class="module-panel" data-workspace-section="print">
                        <header>
                            <h3>Print</h3>
                            <span>Print Center</span>
                        </header>
                        <div class="module-content">
                            <p>Open booking confirmations, receipts, vouchers, and itinerary prints from here.</p>
                            <div class="empty-state-line">Choose a print option to continue.</div>
                        </div>
                    </article>
                </section>
            </div>
        </section>
    </div>
</section>
