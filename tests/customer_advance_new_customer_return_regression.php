<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$javascript = (string) file_get_contents($root . '/public/assets/js/workspace.js');

$checks = [
    'Customer Advance marks New Customer with an explicit parent return target' => str_contains(
        $javascript,
        "newCustomerModal.dataset.returnTo = 'customer-advance';"
    ),
    'Closing the child customer form restores Customer Advance' => str_contains(
        $javascript,
        "const returnToCustomerAdvance = newCustomerModal.dataset.returnTo === 'customer-advance'"
    )
        && str_contains($javascript, "customerAdvanceModal.setAttribute('aria-hidden', 'false');"),
    'Successful customer save selects it in Customer Advance' => str_contains(
        $javascript,
        "if (newCustomerModal?.dataset.returnTo === 'customer-advance' || customerAdvanceNewCustomerMode)"
    )
        && str_contains($javascript, 'selectCustomerForAdvance(customer);')
        && str_contains($javascript, 'customerAdvanceAmount?.focus();'),
    'Consumed parent state is cleared to prevent later modal misrouting' => str_contains(
        $javascript,
        'delete newCustomerModal.dataset.returnTo;'
    ),
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label);
    $failed = $failed || !$passed;
}

if ($failed) {
    fwrite(STDERR, "Customer Advance new-customer return regression failed.\n");
    exit(1);
}

echo "Customer Advance new-customer return regression passed.\n";
