<?php

declare(strict_types=1);

return [
    'up' => [
        // Candidate IDs intentionally have no FK: a later valid correction can
        // delete a live allocation while this historical evidence remains.
        'CREATE TABLE supplier_allocation_journal_evidence (
            journal_line_id BIGINT UNSIGNED PRIMARY KEY,
            journal_entry_id BIGINT UNSIGNED NOT NULL,
            supplier_payment_id BIGINT UNSIGNED NOT NULL,
            supplier_obligation_id BIGINT UNSIGNED NOT NULL,
            allocated_amount DECIMAL(18,2) NOT NULL,
            candidate_allocation_id BIGINT UNSIGNED NULL,
            evidence_status ENUM("time_correlated_candidate", "unresolved") NOT NULL,
            evidence_reason VARCHAR(100) NOT NULL,
            evidence_json JSON NOT NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_supplier_allocation_evidence_payment (supplier_payment_id),
            KEY idx_supplier_allocation_evidence_candidate (candidate_allocation_id),
            KEY idx_supplier_allocation_evidence_status (evidence_status),
            CONSTRAINT fk_supplier_allocation_evidence_journal_line
                FOREIGN KEY (journal_line_id) REFERENCES journal_entry_lines (id),
            CONSTRAINT fk_supplier_allocation_evidence_journal_entry
                FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [],
];
