<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS booking_exchange_rates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id BIGINT UNSIGNED NOT NULL,
            branch_id INT UNSIGNED NOT NULL,
            from_currency VARCHAR(10) NOT NULL,
            to_currency VARCHAR(10) NOT NULL,
            rate_value DECIMAL(18,8) NOT NULL,
            effective_date DATE NOT NULL,
            created_by_user_id INT UNSIGNED NULL,
            updated_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_booking_exchange_pair (booking_id, from_currency, to_currency),
            KEY idx_booking_exchange_lookup (booking_id, from_currency, to_currency),
            KEY idx_booking_exchange_branch (branch_id),
            CONSTRAINT fk_booking_exchange_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
            CONSTRAINT fk_booking_exchange_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
            CONSTRAINT fk_booking_exchange_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_booking_exchange_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS booking_exchange_rates',
    ],
];
