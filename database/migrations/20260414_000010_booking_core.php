<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS bookings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_reference VARCHAR(50) NOT NULL UNIQUE,
            branch_id INT UNSIGNED NOT NULL,
            booking_status ENUM("draft", "open", "confirmed", "on_hold", "closed") NOT NULL DEFAULT "draft",
            booking_date DATE NOT NULL,
            departure_date DATE NULL,
            return_date DATE NULL,
            remarks TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            updated_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bookings_branch (branch_id),
            KEY idx_bookings_status (booking_status),
            KEY idx_bookings_date (booking_date),
            CONSTRAINT fk_bookings_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
            CONSTRAINT fk_bookings_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_bookings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS booking_parties (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id BIGINT UNSIGNED NOT NULL,
            party_label VARCHAR(120) NOT NULL DEFAULT "Lead Traveler / Booking Party",
            lead_traveler_name VARCHAR(190) NOT NULL,
            contact_mobile VARCHAR(50) NULL,
            passport_number VARCHAR(50) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_booking_party_booking (booking_id),
            KEY idx_booking_parties_lead_name (lead_traveler_name),
            KEY idx_booking_parties_mobile (contact_mobile),
            KEY idx_booking_parties_passport (passport_number),
            CONSTRAINT fk_booking_parties_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS booking_parties',
        'DROP TABLE IF EXISTS bookings',
    ],
];
