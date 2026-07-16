<?php

declare(strict_types=1);

namespace App\Repositories;

final class BusinessExpenseCorrectionRepository extends BaseRepository
{
    public function correctionsTableExists(): bool
    {
        return parent::tableExists('business_expense_corrections');
    }

    public function recordCorrection(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO business_expense_corrections (
                business_expense_id,
                branch_id,
                correction_date,
                correction_reason,
                correction_note,
                prior_expense_date,
                new_expense_date,
                prior_category_id,
                new_category_id,
                prior_category_name,
                new_category_name,
                prior_title,
                new_title,
                prior_amount,
                new_amount,
                prior_currency,
                new_currency,
                prior_payment_method,
                new_payment_method,
                prior_treasury_account_id,
                new_treasury_account_id,
                prior_treasury_account_name,
                new_treasury_account_name,
                prior_paid_to_name,
                new_paid_to_name,
                prior_reference_number,
                new_reference_number,
                prior_notes,
                new_notes,
                prior_expense_status,
                new_expense_status,
                prior_journal_entry_id,
                reversal_journal_entry_id,
                new_journal_entry_id,
                created_by_user_id
             ) VALUES (
                :business_expense_id,
                :branch_id,
                :correction_date,
                :correction_reason,
                :correction_note,
                :prior_expense_date,
                :new_expense_date,
                :prior_category_id,
                :new_category_id,
                :prior_category_name,
                :new_category_name,
                :prior_title,
                :new_title,
                :prior_amount,
                :new_amount,
                :prior_currency,
                :new_currency,
                :prior_payment_method,
                :new_payment_method,
                :prior_treasury_account_id,
                :new_treasury_account_id,
                :prior_treasury_account_name,
                :new_treasury_account_name,
                :prior_paid_to_name,
                :new_paid_to_name,
                :prior_reference_number,
                :new_reference_number,
                :prior_notes,
                :new_notes,
                :prior_expense_status,
                :new_expense_status,
                :prior_journal_entry_id,
                :reversal_journal_entry_id,
                :new_journal_entry_id,
                :created_by_user_id
             )'
        );
        $statement->execute([
            'business_expense_id' => $data['business_expense_id'],
            'branch_id' => $data['branch_id'],
            'correction_date' => $data['correction_date'],
            'correction_reason' => $data['correction_reason'],
            'correction_note' => $data['correction_note'] ?? null,
            'prior_expense_date' => $data['prior_expense_date'],
            'new_expense_date' => $data['new_expense_date'],
            'prior_category_id' => $data['prior_category_id'],
            'new_category_id' => $data['new_category_id'],
            'prior_category_name' => $data['prior_category_name'],
            'new_category_name' => $data['new_category_name'],
            'prior_title' => $data['prior_title'],
            'new_title' => $data['new_title'],
            'prior_amount' => $data['prior_amount'],
            'new_amount' => $data['new_amount'],
            'prior_currency' => $data['prior_currency'],
            'new_currency' => $data['new_currency'],
            'prior_payment_method' => $data['prior_payment_method'],
            'new_payment_method' => $data['new_payment_method'],
            'prior_treasury_account_id' => $data['prior_treasury_account_id'] ?? null,
            'new_treasury_account_id' => $data['new_treasury_account_id'] ?? null,
            'prior_treasury_account_name' => $data['prior_treasury_account_name'] ?? null,
            'new_treasury_account_name' => $data['new_treasury_account_name'] ?? null,
            'prior_paid_to_name' => $data['prior_paid_to_name'] ?? null,
            'new_paid_to_name' => $data['new_paid_to_name'] ?? null,
            'prior_reference_number' => $data['prior_reference_number'] ?? null,
            'new_reference_number' => $data['new_reference_number'] ?? null,
            'prior_notes' => $data['prior_notes'] ?? null,
            'new_notes' => $data['new_notes'] ?? null,
            'prior_expense_status' => $data['prior_expense_status'],
            'new_expense_status' => $data['new_expense_status'],
            'prior_journal_entry_id' => $data['prior_journal_entry_id'] ?? null,
            'reversal_journal_entry_id' => $data['reversal_journal_entry_id'] ?? null,
            'new_journal_entry_id' => $data['new_journal_entry_id'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function rowsForExpense(int $expenseId): array
    {
        if (! $this->correctionsTableExists()) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT
                bec.*,
                COALESCE(u.name, u.username, u.email, "User") AS actor_name
             FROM business_expense_corrections bec
             LEFT JOIN users u ON u.id = bec.created_by_user_id
             WHERE bec.business_expense_id = :business_expense_id
             ORDER BY bec.id DESC'
        );
        $statement->execute(['business_expense_id' => $expenseId]);

        return $statement->fetchAll() ?: [];
    }
}
