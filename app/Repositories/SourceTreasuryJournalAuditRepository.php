<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SourceTreasuryJournalAuditRepository extends BaseRepository
{
    public function customerReceipts(): array
    {
        return $this->rows(
            'SELECT cr.id, cr.receipt_no AS source_reference, cr.branch_id, cr.currency,
                    cr.received_amount AS amount, cr.charges_amount, cr.status, cr.payment_method,
                    cr.treasury_account_id, cr.reversal_journal_entry_id,
                    ta.branch_id AS account_branch_id,
                    ta.currency AS account_currency, ta.linked_account_id AS account_id
             FROM customer_receipts cr
             LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
             WHERE cr.received_amount > 0
             ORDER BY cr.id'
        );
    }

    public function supplierPayments(): array
    {
        return $this->rows(
            'SELECT sp.id, sp.payment_no AS source_reference, sp.branch_id, sp.currency,
                    sp.paid_amount AS amount, sp.charges_amount, sp.status, sp.payment_method,
                    sp.treasury_account_id, sp.reversal_journal_entry_id,
                    ta.branch_id AS account_branch_id,
                    ta.currency AS account_currency, ta.linked_account_id AS account_id
             FROM supplier_payments sp
             LEFT JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
             WHERE sp.paid_amount > 0
             ORDER BY sp.id'
        );
    }

    public function customerReceiptJournalLines(): array
    {
        return $this->rows(
            'SELECT jel.customer_receipt_id AS source_id, je.id AS journal_id, je.source_type,
                    je.branch_id, je.currency, jel.account_id, coa.code AS account_code,
                    jel.debit_amount, jel.credit_amount
             FROM journal_entry_lines jel
             INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE jel.customer_receipt_id IS NOT NULL
             ORDER BY jel.customer_receipt_id, je.id, jel.id'
        );
    }

    public function supplierPaymentJournalLines(): array
    {
        return $this->rows(
            'SELECT jel.supplier_payment_id AS source_id, je.id AS journal_id, je.source_type,
                    je.branch_id, je.currency, jel.account_id, coa.code AS account_code,
                    jel.debit_amount, jel.credit_amount
             FROM journal_entry_lines jel
             INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE jel.supplier_payment_id IS NOT NULL
             ORDER BY jel.supplier_payment_id, je.id, jel.id'
        );
    }

    public function treasuryLinkedAccountIds(): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT linked_account_id
             FROM treasury_accounts
             WHERE linked_account_id IS NOT NULL'
        );
        $statement->execute();

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function rows(string $sql): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
