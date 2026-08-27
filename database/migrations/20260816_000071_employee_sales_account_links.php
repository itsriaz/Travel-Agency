<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS employee_business_source_links (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            business_source_id INT UNSIGNED NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(255) NULL,
            linked_by_user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_employee_business_source_pair (user_id, business_source_id),
            UNIQUE KEY uq_employee_business_source_account (business_source_id),
            KEY idx_emp_bs_links_user (user_id),
            KEY idx_emp_bs_links_active (is_active),
            CONSTRAINT fk_emp_bs_link_user FOREIGN KEY (user_id) REFERENCES users (id),
            CONSTRAINT fk_emp_bs_link_account FOREIGN KEY (business_source_id) REFERENCES business_sources (id),
            CONSTRAINT fk_emp_bs_link_actor FOREIGN KEY (linked_by_user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS employee_business_source_links',
    ],
];
