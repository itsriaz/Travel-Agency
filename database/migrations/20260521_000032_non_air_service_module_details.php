<?php

declare(strict_types=1);

return [
    'up' => [
        static function (\PDO $db): void {
            $columnExists = static function (string $table, string $column) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND column_name = :column_name'
                );
                $statement->execute([
                    'table_name' => $table,
                    'column_name' => $column,
                ]);

                return (int) $statement->fetchColumn() > 0;
            };

            $tables = [
                'service_visa' => [
                    'application_reference' => 'VARCHAR(120) NULL AFTER visa_type',
                    'passport_number' => 'VARCHAR(80) NULL AFTER application_reference',
                    'submission_date' => 'DATE NULL AFTER passport_number',
                    'issue_date' => 'DATE NULL AFTER submission_date',
                    'expiry_date' => 'DATE NULL AFTER issue_date',
                    'visa_status' => 'VARCHAR(80) NULL AFTER expiry_date',
                ],
                'service_umrah' => [
                    'mofa_reference' => 'VARCHAR(120) NULL AFTER package_name',
                    'departure_date' => 'DATE NULL AFTER mofa_reference',
                    'return_date' => 'DATE NULL AFTER departure_date',
                    'hotel_name' => 'VARCHAR(190) NULL AFTER return_date',
                    'transport_notes' => 'VARCHAR(255) NULL AFTER hotel_name',
                ],
                'service_hotel' => [
                    'confirmation_number' => 'VARCHAR(120) NULL AFTER city',
                    'check_in_date' => 'DATE NULL AFTER confirmation_number',
                    'check_out_date' => 'DATE NULL AFTER check_in_date',
                    'room_type' => 'VARCHAR(120) NULL AFTER check_out_date',
                    'guest_count' => 'INT UNSIGNED NULL AFTER room_type',
                ],
                'service_transport' => [
                    'vehicle_type' => 'VARCHAR(120) NULL AFTER transport_mode',
                    'pickup_date' => 'DATE NULL AFTER vehicle_type',
                    'pickup_location' => 'VARCHAR(190) NULL AFTER pickup_date',
                    'dropoff_location' => 'VARCHAR(190) NULL AFTER pickup_location',
                    'driver_detail' => 'VARCHAR(190) NULL AFTER dropoff_location',
                ],
                'service_tour' => [
                    'confirmation_number' => 'VARCHAR(120) NULL AFTER destination',
                    'start_date' => 'DATE NULL AFTER confirmation_number',
                    'end_date' => 'DATE NULL AFTER start_date',
                    'inclusions' => 'VARCHAR(255) NULL AFTER end_date',
                ],
                'service_other' => [
                    'reference_number' => 'VARCHAR(120) NULL AFTER label',
                    'service_date' => 'DATE NULL AFTER reference_number',
                    'provider_name' => 'VARCHAR(190) NULL AFTER service_date',
                ],
            ];

            foreach ($tables as $table => $columns) {
                foreach ($columns as $column => $definition) {
                    if (! $columnExists($table, $column)) {
                        $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
                    }
                }
            }
        },
    ],
    'down' => [
        static function (\PDO $db): void {
            $columnExists = static function (string $table, string $column) use ($db): bool {
                $statement = $db->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = :table_name
                       AND column_name = :column_name'
                );
                $statement->execute([
                    'table_name' => $table,
                    'column_name' => $column,
                ]);

                return (int) $statement->fetchColumn() > 0;
            };

            $tables = [
                'service_other' => ['provider_name', 'service_date', 'reference_number'],
                'service_tour' => ['inclusions', 'end_date', 'start_date', 'confirmation_number'],
                'service_transport' => ['driver_detail', 'dropoff_location', 'pickup_location', 'pickup_date', 'vehicle_type'],
                'service_hotel' => ['guest_count', 'room_type', 'check_out_date', 'check_in_date', 'confirmation_number'],
                'service_umrah' => ['transport_notes', 'hotel_name', 'return_date', 'departure_date', 'mofa_reference'],
                'service_visa' => ['visa_status', 'expiry_date', 'issue_date', 'submission_date', 'passport_number', 'application_reference'],
            ];

            foreach ($tables as $table => $columns) {
                foreach ($columns as $column) {
                    if ($columnExists($table, $column)) {
                        $db->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $column);
                    }
                }
            }
        },
    ],
];
