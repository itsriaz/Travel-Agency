<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columns = [];
            foreach ($db->query('SHOW COLUMNS FROM booking_service_events') ?: [] as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            $indexes = [];
            foreach ($db->query('SHOW INDEX FROM booking_service_events') ?: [] as $index) {
                $indexes[(string) ($index['Key_name'] ?? '')] = true;
            }

            $foreignKeys = [];
            $foreignKeyRows = $db->query(
                'SELECT CONSTRAINT_NAME
                 FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = "booking_service_events"'
            );
            foreach ($foreignKeyRows ?: [] as $row) {
                $foreignKeys[(string) ($row['CONSTRAINT_NAME'] ?? '')] = true;
            }

            if (! isset($columns['void_reason'])) {
                $db->exec('ALTER TABLE booking_service_events ADD COLUMN void_reason VARCHAR(255) NULL AFTER voided_at');
            }

            if (! isset($columns['reversal_reference'])) {
                $db->exec('ALTER TABLE booking_service_events ADD COLUMN reversal_reference VARCHAR(80) NULL AFTER void_reason');
            }

            if (! isset($columns['reversal_journal_entry_id'])) {
                $db->exec('ALTER TABLE booking_service_events ADD COLUMN reversal_journal_entry_id BIGINT UNSIGNED NULL AFTER reversal_reference');
            }

            if (! isset($indexes['idx_booking_service_events_reversal_journal'])) {
                $db->exec('CREATE INDEX idx_booking_service_events_reversal_journal ON booking_service_events (reversal_journal_entry_id)');
            }

            if (! isset($foreignKeys['fk_booking_service_events_reversal_journal'])) {
                $db->exec(
                    'ALTER TABLE booking_service_events
                     ADD CONSTRAINT fk_booking_service_events_reversal_journal
                     FOREIGN KEY (reversal_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL'
                );
            }
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $indexes = [];
            foreach ($db->query('SHOW INDEX FROM booking_service_events') ?: [] as $index) {
                $indexes[(string) ($index['Key_name'] ?? '')] = true;
            }

            $foreignKeys = [];
            $foreignKeyRows = $db->query(
                'SELECT CONSTRAINT_NAME
                 FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = "booking_service_events"'
            );
            foreach ($foreignKeyRows ?: [] as $row) {
                $foreignKeys[(string) ($row['CONSTRAINT_NAME'] ?? '')] = true;
            }

            $columns = [];
            foreach ($db->query('SHOW COLUMNS FROM booking_service_events') ?: [] as $column) {
                $columns[(string) ($column['Field'] ?? '')] = true;
            }

            if (isset($foreignKeys['fk_booking_service_events_reversal_journal'])) {
                $db->exec('ALTER TABLE booking_service_events DROP FOREIGN KEY fk_booking_service_events_reversal_journal');
            }

            if (isset($indexes['idx_booking_service_events_reversal_journal'])) {
                $db->exec('ALTER TABLE booking_service_events DROP INDEX idx_booking_service_events_reversal_journal');
            }

            foreach (['reversal_journal_entry_id', 'reversal_reference', 'void_reason'] as $columnName) {
                if (isset($columns[$columnName])) {
                    $db->exec('ALTER TABLE booking_service_events DROP COLUMN ' . $columnName);
                }
            }
        },
    ],
];
