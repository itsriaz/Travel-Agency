<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS booking_service_refund_details (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_event_id BIGINT UNSIGNED NOT NULL,
            treasury_account_id BIGINT UNSIGNED NULL,
            refund_payment_method VARCHAR(30) NOT NULL,
            customer_bank_name VARCHAR(190) NULL,
            customer_bank_account_title VARCHAR(190) NULL,
            customer_bank_account_no VARCHAR(120) NULL,
            customer_bank_iban VARCHAR(120) NULL,
            transfer_reference VARCHAR(190) NULL,
            charges DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            remarks TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            updated_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_booking_service_refund_details_event (service_event_id),
            KEY idx_booking_service_refund_details_treasury (treasury_account_id),
            KEY idx_booking_service_refund_details_method (refund_payment_method),
            CONSTRAINT fk_booking_service_refund_details_event FOREIGN KEY (service_event_id) REFERENCES booking_service_events (id) ON DELETE CASCADE,
            CONSTRAINT fk_booking_service_refund_details_treasury FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts (id) ON DELETE SET NULL,
            CONSTRAINT fk_booking_service_refund_details_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_booking_service_refund_details_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS booking_service_refund_details',
    ],
];
