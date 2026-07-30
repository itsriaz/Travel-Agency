<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $columns = [
                'payment_method' => "ADD COLUMN payment_method VARCHAR(50) NULL AFTER received_at",
                'treasury_account_id' => 'ADD COLUMN treasury_account_id BIGINT UNSIGNED NULL AFTER payment_method',
                'journal_entry_id' => 'ADD COLUMN journal_entry_id BIGINT UNSIGNED NULL AFTER treasury_account_id',
                'updated_by_user_id' => 'ADD COLUMN updated_by_user_id INT UNSIGNED NULL AFTER created_by_user_id',
                'updated_at' => 'ADD COLUMN updated_at DATETIME NULL AFTER created_at',
            ];

            foreach ($columns as $column => $definition) {
                $statement = $db->prepare(
                    "SELECT COUNT(*)
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'supplier_advances'
                       AND COLUMN_NAME = :column_name"
                );
                $statement->execute(['column_name' => $column]);
                if ((int) $statement->fetchColumn() === 0) {
                    $db->exec('ALTER TABLE supplier_advances ' . $definition);
                }
            }

            $indexStatement = $db->query(
                "SELECT COUNT(*)
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'supplier_advances'
                   AND INDEX_NAME = 'idx_supplier_advances_treasury'"
            );
            if ((int) $indexStatement->fetchColumn() === 0) {
                $db->exec('ALTER TABLE supplier_advances ADD INDEX idx_supplier_advances_treasury (treasury_account_id)');
            }

            $journalIndexStatement = $db->query(
                "SELECT COUNT(*)
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'supplier_advances'
                   AND INDEX_NAME = 'idx_supplier_advances_journal'"
            );
            if ((int) $journalIndexStatement->fetchColumn() === 0) {
                $db->exec('ALTER TABLE supplier_advances ADD INDEX idx_supplier_advances_journal (journal_entry_id)');
            }

            $db->exec(
                "UPDATE supplier_advances a
                 SET a.journal_entry_id = (
                    SELECT MAX(je.id)
                    FROM journal_entries je
                    WHERE je.source_type = 'supplier_advance_recorded'
                      AND je.source_reference = CONCAT('SADV-', a.id)
                 )
                 WHERE a.journal_entry_id IS NULL"
            );

            $db->exec(
                'CREATE TABLE IF NOT EXISTS supplier_advance_corrections (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    supplier_advance_id BIGINT UNSIGNED NOT NULL,
                    reason VARCHAR(1000) NOT NULL,
                    old_payment_date DATE NULL,
                    new_payment_date DATE NOT NULL,
                    old_amount DECIMAL(18,2) NOT NULL,
                    new_amount DECIMAL(18,2) NOT NULL,
                    old_payment_method VARCHAR(50) NULL,
                    new_payment_method VARCHAR(50) NOT NULL,
                    old_treasury_account_id BIGINT UNSIGNED NULL,
                    new_treasury_account_id BIGINT UNSIGNED NOT NULL,
                    old_reference_no VARCHAR(100) NULL,
                    new_reference_no VARCHAR(100) NULL,
                    old_remarks TEXT NULL,
                    new_remarks TEXT NULL,
                    old_journal_entry_id BIGINT UNSIGNED NULL,
                    reversal_journal_entry_id BIGINT UNSIGNED NULL,
                    new_journal_entry_id BIGINT UNSIGNED NULL,
                    created_by_user_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_sac_advance (supplier_advance_id),
                    INDEX idx_sac_created_at (created_at),
                    CONSTRAINT fk_sac_advance FOREIGN KEY (supplier_advance_id) REFERENCES supplier_advances(id) ON DELETE CASCADE,
                    CONSTRAINT fk_sac_old_treasury FOREIGN KEY (old_treasury_account_id) REFERENCES treasury_accounts(id) ON DELETE SET NULL,
                    CONSTRAINT fk_sac_new_treasury FOREIGN KEY (new_treasury_account_id) REFERENCES treasury_accounts(id),
                    CONSTRAINT fk_sac_old_journal FOREIGN KEY (old_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
                    CONSTRAINT fk_sac_reversal_journal FOREIGN KEY (reversal_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
                    CONSTRAINT fk_sac_new_journal FOREIGN KEY (new_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        },
    ],
    'down' => [],
];
