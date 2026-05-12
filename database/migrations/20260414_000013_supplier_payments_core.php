<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS supplier_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT UNSIGNED NOT NULL,
            branch_id INT UNSIGNED NOT NULL,
            booking_reference VARCHAR(50) NOT NULL,
            payment_no VARCHAR(50) NOT NULL UNIQUE,
            payment_date DATE NOT NULL,
            currency CHAR(3) NOT NULL,
            paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            allocated_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            unallocated_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            payment_method ENUM("cash", "bank_transfer", "debit_card", "credit_card") NOT NULL,
            reference_number VARCHAR(100) NULL,
            bank_card_detail VARCHAR(190) NULL,
            charges_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            status ENUM("paid", "partially_allocated", "fully_allocated", "void") NOT NULL DEFAULT "paid",
            exchange_rate_to_booking DECIMAL(18,8) NULL,
            remarks TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_supplier_payments_supplier (supplier_id),
            KEY idx_supplier_payments_branch (branch_id),
            KEY idx_supplier_payments_booking (booking_reference),
            KEY idx_supplier_payments_status (status),
            CONSTRAINT fk_supplier_payments_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE,
            CONSTRAINT fk_supplier_payments_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
            CONSTRAINT fk_supplier_payments_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS supplier_payment_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_payment_id BIGINT UNSIGNED NOT NULL,
            supplier_obligation_id BIGINT UNSIGNED NOT NULL,
            allocated_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            allocation_note VARCHAR(190) NULL,
            exchange_rate_used DECIMAL(18,8) NULL,
            allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by_user_id INT UNSIGNED NULL,
            KEY idx_supplier_payment_allocations_payment (supplier_payment_id),
            KEY idx_supplier_payment_allocations_obligation (supplier_obligation_id),
            CONSTRAINT fk_supplier_payment_allocations_payment FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments (id) ON DELETE CASCADE,
            CONSTRAINT fk_supplier_payment_allocations_obligation FOREIGN KEY (supplier_obligation_id) REFERENCES supplier_obligations (id) ON DELETE CASCADE,
            CONSTRAINT fk_supplier_payment_allocations_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS supplier_payment_allocations',
        'DROP TABLE IF EXISTS supplier_payments',
    ],
];
