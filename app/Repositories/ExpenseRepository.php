<?php

declare(strict_types=1);

namespace App\Repositories;

final class ExpenseRepository extends BaseRepository
{
    public function attachmentsTableExists(): bool
    {
        return $this->tableExists('expense_attachments');
    }

    public function categoryRows(): array
    {
        $statement = $this->db->query(
            'SELECT id, code, name, sort_order, is_system, is_active
             FROM expense_categories
             ORDER BY sort_order ASC, name ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    public function findCategory(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, name, sort_order, is_system, is_active
             FROM expense_categories
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function categoryCodeExists(string $code, ?int $excludeId = null): bool
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM expense_categories
             WHERE code = :code' . ($excludeId !== null ? ' AND id != :exclude_id' : '') . '
             LIMIT 1'
        );
        $params = ['code' => $code];
        if ($excludeId !== null) {
            $params['exclude_id'] = $excludeId;
        }
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function saveCategory(array $payload): int
    {
        $id = (int) ($payload['id'] ?? 0);
        $data = [
            'code' => $payload['code'],
            'name' => $payload['name'],
            'sort_order' => $payload['sort_order'],
            'is_active' => $payload['is_active'],
        ];

        if ($id > 0) {
            $data['id'] = $id;
            $statement = $this->db->prepare(
                'UPDATE expense_categories
                 SET code = :code,
                     name = :name,
                     sort_order = :sort_order,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute($data);

            return $id;
        }

        $statement = $this->db->prepare(
            'INSERT INTO expense_categories (code, name, sort_order, is_active)
             VALUES (:code, :name, :sort_order, :is_active)'
        );
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    public function deleteCategory(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM expense_categories WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function categoryDeleteBlockedReason(int $id): ?string
    {
        $category = $this->findCategory($id);
        if ($category === null) {
            return 'The selected expense category could not be found.';
        }

        if ((int) ($category['is_system'] ?? 0) === 1) {
            return 'System expense categories cannot be deleted.';
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM business_expenses
             WHERE expense_category_id = :expense_category_id'
        );
        $statement->execute(['expense_category_id' => $id]);

        return (int) $statement->fetchColumn() > 0
            ? 'This expense category is already used by recorded expenses.'
            : null;
    }

    public function expenseRows(array $accessibleBranchIds, array $filters = []): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $attachmentJoin = '';
        $attachmentSelect = 'NULL AS attachment_id, NULL AS attachment_file_name, NULL AS attachment_mime_type,';
        if ($this->attachmentsTableExists()) {
            $attachmentSelect = 'ea.id AS attachment_id, ea.original_file_name AS attachment_file_name, ea.mime_type AS attachment_mime_type,';
            $attachmentJoin = "LEFT JOIN expense_attachments ea
                ON ea.business_expense_id = be.id
               AND ea.status = 'active'";
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $conditions = ["be.branch_id IN ({$placeholders})"];
        $params = array_map('intval', $accessibleBranchIds);

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $conditions[] = 'be.expense_date >= ?';
            $params[] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $conditions[] = 'be.expense_date <= ?';
            $params[] = $dateTo;
        }

        $branchId = (int) ($filters['branch_id'] ?? 0);
        if ($branchId > 0) {
            $conditions[] = 'be.branch_id = ?';
            $params[] = $branchId;
        }

        $categoryId = (int) ($filters['expense_category_id'] ?? 0);
        if ($categoryId > 0) {
            $conditions[] = 'be.expense_category_id = ?';
            $params[] = $categoryId;
        }

        $currency = trim((string) ($filters['currency'] ?? ''));
        if ($currency !== '') {
            $conditions[] = 'be.currency = ?';
            $params[] = mb_strtoupper($currency);
        }

        $statement = $this->db->prepare(
            "SELECT
                be.id,
                be.expense_date,
                be.branch_id,
                b.name AS branch_name,
                be.expense_category_id,
                ec.code AS category_code,
                ec.name AS category_name,
                be.title,
                be.amount,
                be.currency,
                be.payment_method,
                COALESCE(pm.name, REPLACE(be.payment_method, '_', ' ')) AS payment_method_label,
                be.paid_to_name,
                be.reference_number,
                be.notes,
                be.expense_status,
                be.entered_by_user_id,
                COALESCE(u.name, u.username, u.email, 'User') AS entered_by_name,
                " . $attachmentSelect . "
                be.created_at,
                be.updated_at
             FROM business_expenses be
             INNER JOIN branches b ON b.id = be.branch_id
             INNER JOIN expense_categories ec ON ec.id = be.expense_category_id
             LEFT JOIN payment_methods pm ON pm.code = be.payment_method
             LEFT JOIN users u ON u.id = be.entered_by_user_id
             " . $attachmentJoin . "
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY be.expense_date DESC, be.id DESC"
        );
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function findExpense(int $id, array $accessibleBranchIds): ?array
    {
        if ($accessibleBranchIds === []) {
            return null;
        }

        $attachmentJoin = '';
        $attachmentSelect = 'NULL AS attachment_id, NULL AS attachment_file_name, NULL AS attachment_mime_type,';
        if ($this->attachmentsTableExists()) {
            $attachmentSelect = 'ea.id AS attachment_id, ea.original_file_name AS attachment_file_name, ea.mime_type AS attachment_mime_type,';
            $attachmentJoin = "LEFT JOIN expense_attachments ea
                ON ea.business_expense_id = be.id
               AND ea.status = 'active'";
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT
                be.id,
                be.expense_date,
                be.branch_id,
                be.expense_category_id,
                be.title,
                be.amount,
                be.currency,
                be.payment_method,
                be.paid_to_name,
                be.reference_number,
                be.notes,
                be.expense_status,
                be.entered_by_user_id,
                be.updated_by_user_id,
                COALESCE(u.name, u.username, u.email, 'User') AS entered_by_name,
                " . $attachmentSelect . "
                be.created_at,
                be.updated_at
             FROM business_expenses be
             LEFT JOIN users u ON u.id = be.entered_by_user_id
             " . $attachmentJoin . "
             WHERE be.id = ?
               AND be.branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $statement->execute(array_merge([$id], array_map('intval', $accessibleBranchIds)));
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function saveExpense(array $payload): int
    {
        $id = (int) ($payload['id'] ?? 0);
        $data = [
            'expense_date' => $payload['expense_date'],
            'branch_id' => $payload['branch_id'],
            'expense_category_id' => $payload['expense_category_id'],
            'title' => $payload['title'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'payment_method' => $payload['payment_method'],
            'paid_to_name' => $payload['paid_to_name'],
            'reference_number' => $payload['reference_number'],
            'notes' => $payload['notes'],
            'expense_status' => $payload['expense_status'],
            'updated_by_user_id' => $payload['actor_user_id'],
        ];

        if ($id > 0) {
            $data['id'] = $id;
            $statement = $this->db->prepare(
                'UPDATE business_expenses
                 SET expense_date = :expense_date,
                     branch_id = :branch_id,
                     expense_category_id = :expense_category_id,
                     title = :title,
                     amount = :amount,
                     currency = :currency,
                     payment_method = :payment_method,
                     paid_to_name = :paid_to_name,
                     reference_number = :reference_number,
                     notes = :notes,
                     expense_status = :expense_status,
                     updated_by_user_id = :updated_by_user_id
                 WHERE id = :id'
            );
            $statement->execute($data);

            return $id;
        }

        $data['entered_by_user_id'] = $payload['actor_user_id'];
        $statement = $this->db->prepare(
            'INSERT INTO business_expenses (
                expense_date, branch_id, expense_category_id, title, amount, currency, payment_method,
                paid_to_name, reference_number, notes, expense_status, entered_by_user_id, updated_by_user_id
             ) VALUES (
                :expense_date, :branch_id, :expense_category_id, :title, :amount, :currency, :payment_method,
                :paid_to_name, :reference_number, :notes, :expense_status, :entered_by_user_id, :updated_by_user_id
             )'
        );
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    public function deleteExpense(int $id, array $accessibleBranchIds): void
    {
        if ($accessibleBranchIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "DELETE FROM business_expenses
             WHERE id = ?
               AND branch_id IN ({$placeholders})"
        );
        $statement->execute(array_merge([$id], array_map('intval', $accessibleBranchIds)));
    }

    public function activeAttachmentForExpense(int $expenseId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM expense_attachments
             WHERE business_expense_id = :business_expense_id
               AND status = "active"
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['business_expense_id' => $expenseId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function attachmentsForExpense(int $expenseId): array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM expense_attachments
             WHERE business_expense_id = :business_expense_id
             ORDER BY id DESC'
        );
        $statement->execute(['business_expense_id' => $expenseId]);

        return $statement->fetchAll() ?: [];
    }

    public function findAttachmentById(int $attachmentId, array $accessibleBranchIds): ?array
    {
        if ($accessibleBranchIds === []) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT
                ea.*,
                be.title AS expense_title,
                be.reference_number,
                be.expense_date,
                b.name AS branch_name
             FROM expense_attachments ea
             INNER JOIN business_expenses be ON be.id = ea.business_expense_id
             INNER JOIN branches b ON b.id = be.branch_id
             WHERE ea.id = ?
               AND be.branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $statement->execute(array_merge([$attachmentId], array_map('intval', $accessibleBranchIds)));
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function createAttachment(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO expense_attachments (
                business_expense_id, branch_id, original_file_name, stored_file_name, storage_disk, storage_path,
                mime_type, file_extension, file_size_bytes, sha256_hash, visibility, status, replaced_attachment_id,
                uploaded_by_user_id, updated_by_user_id
             ) VALUES (
                :business_expense_id, :branch_id, :original_file_name, :stored_file_name, :storage_disk, :storage_path,
                :mime_type, :file_extension, :file_size_bytes, :sha256_hash, "private", "active", :replaced_attachment_id,
                :uploaded_by_user_id, :updated_by_user_id
             )'
        );
        $statement->execute([
            'business_expense_id' => $data['business_expense_id'],
            'branch_id' => $data['branch_id'],
            'original_file_name' => $data['original_file_name'],
            'stored_file_name' => $data['stored_file_name'],
            'storage_disk' => $data['storage_disk'] ?? 'local',
            'storage_path' => $data['storage_path'],
            'mime_type' => $data['mime_type'],
            'file_extension' => $data['file_extension'],
            'file_size_bytes' => $data['file_size_bytes'],
            'sha256_hash' => $data['sha256_hash'],
            'replaced_attachment_id' => $data['replaced_attachment_id'] ?? null,
            'uploaded_by_user_id' => $data['actor_user_id'] ?? null,
            'updated_by_user_id' => $data['actor_user_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function supersedeAttachment(int $attachmentId, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE expense_attachments
             SET status = "superseded",
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $attachmentId,
            'updated_by_user_id' => $actorUserId,
        ]);
    }

    public function deleteAttachmentsForExpense(int $expenseId): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM expense_attachments
             WHERE business_expense_id = :business_expense_id'
        );
        $statement->execute(['business_expense_id' => $expenseId]);
    }
}
