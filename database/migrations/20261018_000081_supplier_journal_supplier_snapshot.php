<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE journal_entry_lines
         ADD COLUMN supplier_id_snapshot INT(10) UNSIGNED NULL AFTER supplier_obligation_id,
         ADD INDEX idx_journal_lines_supplier_snapshot (supplier_id_snapshot)',
    ],
    'down' => [],
];
