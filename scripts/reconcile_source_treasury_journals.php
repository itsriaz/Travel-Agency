<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$result = (new \App\Services\SourceTreasuryJournalAuditService($app))->run();
$result['checked_at'] = date(DATE_ATOM);

if (in_array('--json', $argv, true)) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} else {
    echo 'Source-to-treasury journal reconciliation (read-only)', PHP_EOL;
    foreach ($result['checked'] as $type => $count) {
        echo $type, ': ', $count, ' cash/bank sources checked; ',
            $result['skipped_non_cash_bank'][$type], ' non-cash/bank skipped', PHP_EOL;
    }
    echo count($result['exceptions']), ' exception(s); ', count($result['warnings']), ' warning(s)', PHP_EOL;
    foreach ($result['exceptions'] as $finding) {
        echo '[FAIL] ', $finding['type'], ' #', $finding['source_id'],
            ' ', $finding['source_reference'] ?? '',
            ' ', implode(', ', $finding['reasons']),
            ' expected=', $finding['expected_amount'] ?? 'unknown',
            ' actual=', $finding['actual_amount'] ?? 'unknown',
            ' currency=', $finding['currency'] ?? 'unknown',
            ' branch=', $finding['branch_id'] ?? 'unknown',
            ' treasury=', $finding['treasury_account_id'] ?? 'unknown',
            ' journals=', implode(',', $finding['journal_ids'] ?? []), PHP_EOL;
    }
    foreach ($result['warnings'] as $finding) {
        echo '[WARN] ', $finding['type'], ' #', $finding['source_id'],
            ' ', $finding['reason'],
            ' journals=', implode(',', $finding['journal_ids'] ?? []), PHP_EOL;
    }
}

exit($result['exceptions'] === [] ? 0 : 1);
