<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$view = (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php');
$javascript = (string) file_get_contents(BASE_PATH . '/public/assets/js/workspace.js');
$css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');

$formStart = strpos($view, 'data-service-event-bar="refund"');
$formEnd = $formStart !== false ? strpos($view, '</form>', $formStart) : false;
$form = $formStart !== false && $formEnd !== false ? substr($view, $formStart, $formEnd - $formStart) : '';
$hiddenSupplierRow = strpos($form, 'data-service-supplier-refund-entry-row');
$bankFieldsEnd = strpos($form, 'name="refund_charges"');
$submit = strpos($form, 'data-service-refund-submit');

if ($form === ''
    || $hiddenSupplierRow === false
    || $bankFieldsEnd === false
    || $submit === false
    || $submit <= $bankFieldsEnd
    || substr_count($form, 'data-service-refund-submit') !== 1
    || ! str_contains($form, 'legacy-service-event-bar__refund-actions')
    || ! str_contains($javascript, "payNow ? 'Save Customer Refund' : 'Save Refund'")
    || ! str_contains($css, '.legacy-service-event-bar__refund-actions')) {
    fwrite(STDERR, "[FAIL] Customer refund submit must remain visible when supplier credit entry is hidden.\n");
    exit(1);
}

echo "[PASS] Customer refund submit stays outside the hidden supplier-credit row.\n";
