<?php

declare(strict_types=1);

return [
    'up' => [
        static function (PDO $db): void {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS service_financial_corrections (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    booking_service_id BIGINT UNSIGNED NOT NULL,
                    booking_id BIGINT UNSIGNED NOT NULL,
                    branch_id INT UNSIGNED NOT NULL,
                    booking_reference VARCHAR(50) NOT NULL,
                    service_line_reference VARCHAR(50) NOT NULL,
                    service_type VARCHAR(50) NOT NULL,
                    correction_date DATE NOT NULL,
                    correction_reason VARCHAR(1000) NOT NULL,
                    correction_note VARCHAR(4000) NULL,
                    prior_sale_price DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    new_sale_price DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    prior_purchase_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    new_purchase_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    prior_service_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    new_service_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    prior_discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    new_discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    prior_vat_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    new_vat_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    prior_final_sale_price DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    new_final_sale_price DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    released_customer_credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    released_supplier_credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
                    created_by_user_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_service_financial_corrections_service (booking_service_id),
                    INDEX idx_service_financial_corrections_booking (booking_id),
                    INDEX idx_service_financial_corrections_branch_date (branch_id, correction_date),
                    CONSTRAINT fk_service_financial_corrections_service
                        FOREIGN KEY (booking_service_id) REFERENCES booking_services (id) ON DELETE CASCADE,
                    CONSTRAINT fk_service_financial_corrections_booking
                        FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        },
    ],
    'down' => [
        static function (PDO $db): void {
            $db->exec('DROP TABLE IF EXISTS service_financial_corrections');
        },
    ],
];
