<?php

declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;

final class CustomerReceiptCorrectionRepository extends BaseRepository
{
    public function allocationCount(int $receiptId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM customer_receipt_allocations WHERE customer_receipt_id = :id');
        $statement->execute(['id' => $receiptId]);
        return (int) $statement->fetchColumn();
    }

    public function findCurrencyCorrection(int $originalReceiptId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM customer_receipt_currency_corrections WHERE original_receipt_id = :id LIMIT 1');
        $statement->execute(['id' => $originalReceiptId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function recordCurrencyCorrection(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO customer_receipt_currency_corrections (
                original_receipt_id, replacement_receipt_id, booking_reference, service_line_reference,
                old_branch_id, new_branch_id, old_currency, new_currency,
                old_treasury_account_id, new_treasury_account_id, corrected_amount, treatment,
                reversal_journal_entry_id, reason, created_by_user_id
             ) VALUES (
                :original_receipt_id, :replacement_receipt_id, :booking_reference, :service_line_reference,
                :old_branch_id, :new_branch_id, :old_currency, :new_currency,
                :old_treasury_account_id, :new_treasury_account_id, :corrected_amount, :treatment,
                :reversal_journal_entry_id, :reason, :created_by_user_id
             )'
        );
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    public function findIncomeRecognition(int $receiptId, string $accountCode = 'FX_GAIN'): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM customer_credit_income_recognitions
             WHERE customer_receipt_id = :receipt_id AND income_account_code = :account_code
             LIMIT 1'
        );
        $statement->execute(['receipt_id' => $receiptId, 'account_code' => $accountCode]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function consumeReceiptCreditAsIncome(int $receiptId, float $amount): array
    {
        $statement = $this->db->prepare(
            'SELECT id, booking_reference, currency, received_amount, allocated_amount, unallocated_amount, returned_amount, status
             FROM customer_receipts WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $receiptId]);
        $receipt = $statement->fetch();
        if ($receipt === false) {
            throw new RuntimeException('Customer receipt was not found.');
        }

        $amount = round($amount, 2);
        $available = round((float) ($receipt['unallocated_amount'] ?? 0), 2);
        if ($amount <= 0 || $amount > $available + 0.005) {
            throw new RuntimeException('FX gain exceeds the available customer credit.');
        }

        $remaining = round(max($available - $amount, 0), 2);
        $status = $remaining <= 0.005 ? 'fully_allocated' : 'partially_allocated';
        $update = $this->db->prepare(
            'UPDATE customer_receipts SET unallocated_amount = :remaining, status = :status WHERE id = :id'
        );
        $update->execute(['remaining' => $remaining, 'status' => $status, 'id' => $receiptId]);

        $receipt['unallocated_amount'] = $remaining;
        $receipt['status'] = $status;
        return $receipt;
    }

    public function normalizeReceiptStatus(int $receiptId): void
    {
        $statement = $this->db->prepare(
            'UPDATE customer_receipts
             SET status = CASE
                WHEN unallocated_amount <= 0.005 THEN "fully_allocated"
                WHEN allocated_amount > 0.005 THEN "partially_allocated"
                ELSE "received"
             END
             WHERE id = :id AND status <> "void"'
        );
        $statement->execute(['id' => $receiptId]);
    }

    public function recordIncomeRecognition(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO customer_credit_income_recognitions (
                customer_receipt_id, booking_reference, currency, amount, income_account_code,
                journal_entry_id, reason, created_by_user_id
             ) VALUES (
                :customer_receipt_id, :booking_reference, :currency, :amount, :income_account_code,
                :journal_entry_id, :reason, :created_by_user_id
             )'
        );
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }
}
