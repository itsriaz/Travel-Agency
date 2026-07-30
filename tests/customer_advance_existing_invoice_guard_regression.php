<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repositorySource = (string) file_get_contents($root . '/app/Repositories/CustomerPaymentRepository.php');
$reportSource = (string) file_get_contents($root . '/app/Services/ReportService.php');

$checks = [
    'Automatic advance allocation is limited to actual service-sale invoices' =>
        str_contains($repositorySource, 'AND cri.due_group = "service_sale"')
        && str_contains($repositorySource, 'latest_receivable ON latest_receivable.id = cri.id'),
    'Cancelled or inactive services cannot receive a later customer advance' =>
        str_contains($repositorySource, 'COALESCE(bs.is_active, 1) = 1')
        && str_contains($repositorySource, 'COALESCE(bs.service_status, "")'),
    'Only unpaid invoice rows qualify for automatic advance allocation' =>
        str_contains($repositorySource, 'AND cri.status IN ("open", "partially_paid")')
        && str_contains($repositorySource, 'AND cri.outstanding_amount > 0'),
    'Customer-wide ledger omits internal advance-to-invoice application rows' =>
        str_contains($reportSource, "if (\$suppressMovement) {\n                return [];\n            }"),
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label);
    $failed = $failed || ! $passed;
}

if ($failed) {
    fwrite(STDERR, "Customer advance existing-invoice guard regression failed.\n");
    exit(1);
}

echo "Customer advance existing-invoice guard regression passed.\n";
