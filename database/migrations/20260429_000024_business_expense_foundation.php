<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS expense_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS business_expenses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            expense_date DATE NOT NULL,
            branch_id INT UNSIGNED NOT NULL,
            expense_category_id INT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            currency CHAR(3) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            paid_to_name VARCHAR(190) NULL,
            reference_number VARCHAR(120) NULL,
            notes TEXT NULL,
            expense_status VARCHAR(20) NOT NULL DEFAULT "posted",
            entered_by_user_id INT UNSIGNED NOT NULL,
            updated_by_user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_business_expenses_date (expense_date),
            KEY idx_business_expenses_branch (branch_id),
            KEY idx_business_expenses_category (expense_category_id),
            KEY idx_business_expenses_currency (currency),
            KEY idx_business_expenses_status (expense_status),
            CONSTRAINT fk_business_expenses_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
            CONSTRAINT fk_business_expenses_category FOREIGN KEY (expense_category_id) REFERENCES expense_categories (id),
            CONSTRAINT fk_business_expenses_entered_by FOREIGN KEY (entered_by_user_id) REFERENCES users (id),
            CONSTRAINT fk_business_expenses_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        "INSERT IGNORE INTO expense_categories (code, name, sort_order, is_system, is_active)
         VALUES
            ('rent', 'Rent', 10, 1, 1),
            ('salary', 'Salary', 20, 1, 1),
            ('utilities', 'Utilities', 30, 1, 1),
            ('internet_phone', 'Internet / Phone', 40, 1, 1),
            ('fuel_transport', 'Fuel / Transport', 50, 1, 1),
            ('office_supplies', 'Office Supplies', 60, 1, 1),
            ('marketing', 'Marketing', 70, 1, 1),
            ('bank_charges', 'Bank Charges', 80, 1, 1),
            ('maintenance', 'Maintenance', 90, 1, 1),
            ('miscellaneous', 'Miscellaneous', 100, 1, 1)",
    ],
    'down' => [
        'DROP TABLE IF EXISTS business_expenses',
        'DROP TABLE IF EXISTS expense_categories',
    ],
];
