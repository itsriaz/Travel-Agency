<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $ok) $failures[] = $label;
};
foreach ([
    'repair_bk000001_bk000002_account_supplier_offset.php' => 'REPAIR-BK1-BK2',
    'repair_bk532_bk533_account_supplier_settlement.php' => 'REPAIR-BK532-BK533',
] as $file => $reference) {
    $source = file_get_contents($root . '/scripts/' . $file);
    token_get_all($source, TOKEN_PARSE);
    preg_match('/\$service->settle\(\[(.*?)\], \$actorId/s', $source, $match);
    $input = $match[1] ?? '';
    $check($file . ': selected receivable and payable IDs passed into settlement',
        str_contains($input, "'customer_receivable_item_id' => (int) \$sale['id']")
        && str_contains($input, "'supplier_obligation_id' => (int) \$purchase['id']"));
    $check($file . ': CLI and argument guards precede database bootstrap',
        strpos($source, "PHP_SAPI !== 'cli'") < strpos($source, 'App::bootstrap')
        && str_contains($source, "array_diff(\$arguments, ['--apply', '--rehearse'])"));
    $check($file . ': dry run defaults to no writes; rehearsal rolls back',
        str_contains($source, 'if (! $apply && ! $rehearse)')
        && str_contains($source, 'if ($rehearse) { $db->rollBack(); } else { $db->commit(); }'));
    $check($file . ': ambiguous historical invoice/offset choices rejected',
        substr_count($source, 'LIMIT 2') === 4
        && str_contains($source, 'count($saleRows) === 1')
        && str_contains($source, 'count($purchaseRows) === 1')
        && str_contains($source, 'count($customerRows) === 1')
        && str_contains($source, 'count($offsetRows) === 1'));
    $check($file . ': historical scope and repeat protection retained',
        str_contains($source, "strtoupper((string) \$sale['currency']) !== 'PKR'")
        && str_contains($source, "['reference_no'] ?? '') === '" . $reference . "'"));
    foreach (['--unknown', '--apply --rehearse'] as $arguments) {
        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/' . $file) . ' ' . $arguments . ' 2>&1', $output, $code);
        $check($file . ': rejects ' . $arguments . ' before any database action', $code === 1 && str_contains(implode("\n", $output), 'Usage:'));
    }
}
if ($failures !== []) exit(1);
echo 'Legacy linked repair script readiness passed. No repair was applied.' . PHP_EOL;
