<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE customer_receipts
            ADD COLUMN business_source_id INT UNSIGNED NULL AFTER traveler_id,
            ADD KEY idx_customer_receipts_business_source (business_source_id),
            ADD CONSTRAINT fk_customer_receipts_business_source
                FOREIGN KEY (business_source_id) REFERENCES business_sources (id) ON DELETE SET NULL',
    ],
    'down' => [
        'ALTER TABLE customer_receipts
            DROP FOREIGN KEY fk_customer_receipts_business_source,
            DROP KEY idx_customer_receipts_business_source,
            DROP COLUMN business_source_id',
    ],
];
