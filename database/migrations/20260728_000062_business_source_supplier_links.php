<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS business_source_supplier_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_source_id INT UNSIGNED NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,
            linked_by_user_id INT UNSIGNED NULL,
            linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_bssl_business_source (business_source_id),
            UNIQUE KEY uniq_bssl_supplier (supplier_id),
            KEY idx_bssl_linked_by (linked_by_user_id),
            CONSTRAINT fk_bssl_business_source
                FOREIGN KEY (business_source_id) REFERENCES business_sources (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_bssl_supplier
                FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_bssl_linked_by
                FOREIGN KEY (linked_by_user_id) REFERENCES users (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS business_source_supplier_link_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            business_source_id INT UNSIGNED NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL,
            reason VARCHAR(1000) NULL,
            actor_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bsslh_business_source (business_source_id, created_at),
            KEY idx_bsslh_supplier (supplier_id, created_at),
            KEY idx_bsslh_actor (actor_user_id),
            CONSTRAINT fk_bsslh_business_source
                FOREIGN KEY (business_source_id) REFERENCES business_sources (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_bsslh_supplier
                FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_bsslh_actor
                FOREIGN KEY (actor_user_id) REFERENCES users (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS business_source_supplier_link_history',
        'DROP TABLE IF EXISTS business_source_supplier_links',
    ],
];
