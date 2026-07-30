<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$viewPath = $root . '/app/Views/control/expenses.php';
$controllerPath = $root . '/app/Controllers/ControlController.php';

$view = file_get_contents($viewPath);
$controller = file_get_contents($controllerPath);

if ($view === false || $controller === false) {
    fwrite(STDERR, "[FAIL] Unable to read the expense view or controller.\n");
    exit(1);
}

$checks = [
    'Navigator exposes one clear Add Expense action' => str_contains($view, 'id="expense-entry-open-button"')
        && str_contains($view, '>Add Expense</button>'),
    'Expense entry uses an accessible modal dialog' => str_contains($view, 'id="expense-entry-modal"')
        && str_contains($view, 'role="dialog"')
        && str_contains($view, 'aria-modal="true"'),
    'Existing expense edits reuse the same modal' => str_contains($view, "data-edit-mode=\"<?= \$expenseEditRecord !== null ? '1' : '0' ?>\"")
        && str_contains($view, "'Edit Business Expense' : 'Add Business Expense'"),
    'Modal preserves the existing CSRF-protected financial save endpoint' => str_contains($view, "action=\"<?= e(url('/expenses/save')) ?>\"")
        && str_contains($view, '\\App\\Helpers\\Csrf::input()'),
    'Successful saves still return to the expense register' => str_contains($controller, "\$this->redirectToRegister('/expenses', \$register);"),
    'Failed new entries reopen the Add Expense modal' => str_contains($controller, "'?add=business_expenses#register-business_expenses'"),
    'Close behavior clears edit context before returning to the register' => str_contains($view, 'data-close-url="<?= e(url(\'/expenses\')) ?>#register-business_expenses"')
        && str_contains($view, "modal.dataset.editMode === '1'"),
];

$failed = false;
foreach ($checks as $label => $passed) {
    if ($passed) {
        echo '[PASS] ' . $label . PHP_EOL;
        continue;
    }

    $failed = true;
    echo '[FAIL] ' . $label . PHP_EOL;
}

if ($failed) {
    exit(1);
}

echo 'Business expense modal regression passed.' . PHP_EOL;
