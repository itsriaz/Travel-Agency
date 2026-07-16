<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingRepository;
use App\Repositories\BusinessExpenseCorrectionRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\MasterDataRepository;
use App\Repositories\TreasuryRepository;
use PDO;
use RuntimeException;

final class ExpenseAdminService extends Service
{
    public function save(string $register, array $input, array $files, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new ExpenseRepository($this->app);

        if ($register === 'expense_categories') {
            return $this->saveCategory($repository, $input, $actorUserId);
        }

        if ($register === 'business_expenses') {
            return $this->saveExpense($repository, $input, $files, $actorUserId, $accessibleBranchIds);
        }

        throw new RuntimeException('Unknown expense register.');
    }

    public function delete(string $register, int $id, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new ExpenseRepository($this->app);

        if ($register === 'expense_categories') {
            $record = $repository->findCategory($id);
            if ($record === null) {
                throw new RuntimeException('The selected expense category could not be found.');
            }

            $blockedReason = $repository->categoryDeleteBlockedReason($id);
            if ($blockedReason !== null) {
                throw new RuntimeException($blockedReason);
            }

            $repository->deleteCategory($id);
            AuditLog::record($this->app, 'admin.expense_category.deleted', [
                'user_id' => $actorUserId,
                'expense_category_id' => $id,
                'code' => $record['code'] ?? null,
                'name' => $record['name'] ?? null,
            ]);

            return [
                'label' => (string) ($record['name'] ?? 'Expense category'),
            ];
        }

        if ($register === 'business_expenses') {
            $record = $repository->findExpense($id, $accessibleBranchIds);
            if ($record === null) {
                throw new RuntimeException('The selected expense could not be found.');
            }

            if ((int) ($record['journal_entry_id'] ?? 0) > 0) {
                throw new RuntimeException('Posted expenses linked to a source account cannot be deleted.');
            }

            if ($repository->attachmentsTableExists()) {
                foreach ($repository->attachmentsForExpense($id) as $attachment) {
                    $absolutePath = $this->resolveStoredExpenseProofPath((string) ($attachment['storage_path'] ?? ''), false);
                    if ($absolutePath !== null && is_file($absolutePath)) {
                        @unlink($absolutePath);
                    }
                }
                $repository->deleteAttachmentsForExpense($id);
            }

            $repository->deleteExpense($id, $accessibleBranchIds);
            AuditLog::record($this->app, 'admin.business_expense.deleted', [
                'user_id' => $actorUserId,
                'expense_id' => $id,
                'branch_id' => $record['branch_id'] ?? null,
                'title' => $record['title'] ?? null,
                'amount' => $record['amount'] ?? null,
                'currency' => $record['currency'] ?? null,
            ]);

            return [
                'label' => (string) ($record['title'] ?? 'Expense'),
            ];
        }

        throw new RuntimeException('Unknown expense register.');
    }

    public function downloadAttachment(int $attachmentId, int $actorUserId, array $accessibleBranchIds): array
    {
        $repository = new ExpenseRepository($this->app);
        if (! $repository->attachmentsTableExists()) {
            throw new RuntimeException('Expense attachments are not available until the attachment migration is applied.');
        }

        $attachment = $repository->findAttachmentById($attachmentId, $accessibleBranchIds);
        if ($attachment === null) {
            throw new RuntimeException('The selected expense proof could not be found.');
        }
        if ((string) ($attachment['status'] ?? '') !== 'active') {
            throw new RuntimeException('This expense proof is no longer available for download.');
        }

        $absolutePath = $this->secureStoredExpenseProofPath((string) ($attachment['storage_path'] ?? ''));
        if (! is_file($absolutePath)) {
            throw new RuntimeException('The secure expense proof file is missing from storage.');
        }

        if ((int) filesize($absolutePath) !== (int) ($attachment['file_size_bytes'] ?? 0)) {
            throw new RuntimeException('The secure expense proof file failed integrity validation.');
        }

        $expectedHash = strtolower(trim((string) ($attachment['sha256_hash'] ?? '')));
        if ($expectedHash !== '' && ! hash_equals($expectedHash, strtolower((string) hash_file('sha256', $absolutePath)))) {
            throw new RuntimeException('The secure expense proof file failed integrity validation.');
        }

        AuditLog::record($this->app, 'admin.business_expense.attachment.downloaded', [
            'user_id' => $actorUserId,
            'expense_attachment_id' => $attachmentId,
            'expense_id' => $attachment['business_expense_id'] ?? null,
            'branch_id' => $attachment['branch_id'] ?? null,
            'file_name' => $attachment['original_file_name'] ?? null,
        ]);

        return [
            'absolute_path' => $absolutePath,
            'download_name' => $this->safeOriginalFileName((string) ($attachment['original_file_name'] ?? 'expense-proof')),
            'mime_type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
            'size' => (int) ($attachment['file_size_bytes'] ?? 0),
        ];
    }

    private function saveCategory(ExpenseRepository $repository, array $input, int $actorUserId): array
    {
        $id = (int) ($input['id'] ?? 0);
        $existing = $id > 0 ? $repository->findCategory($id) : null;
        if ($id > 0 && $existing === null) {
            throw new RuntimeException('The selected expense category could not be found.');
        }

        $payload = [
            'code' => $this->assertPattern(
                mb_strtolower(trim((string) ($input['code'] ?? ''))),
                '/^[a-z0-9_]{2,50}$/',
                'Expense category code must use lowercase letters, numbers, or underscores.'
            ),
            'name' => $this->requiredText($input['name'] ?? null, 'Expense category name', 120),
            'sort_order' => max(0, (int) ($input['sort_order'] ?? 0)),
            'is_active' => (int) ((string) ($input['is_active'] ?? '1') === '0' ? 0 : 1),
        ];

        if ($existing !== null && (int) ($existing['is_system'] ?? 0) === 1) {
            if ((string) ($existing['code'] ?? '') !== $payload['code']) {
                throw new RuntimeException('System expense category codes cannot be changed.');
            }
            if ($payload['is_active'] !== 1) {
                throw new RuntimeException('System expense categories must remain active.');
            }
        }

        if ($repository->categoryCodeExists($payload['code'], $id > 0 ? $id : null)) {
            throw new RuntimeException('This expense category code is already in use.');
        }

        $savedId = $repository->saveCategory(array_merge($payload, ['id' => $id]));
        $action = $existing === null ? 'created' : 'updated';

        AuditLog::record($this->app, 'admin.expense_category.' . $action, [
            'user_id' => $actorUserId,
            'expense_category_id' => $savedId,
            'code' => $payload['code'],
            'name' => $payload['name'],
        ]);

        return [
            'id' => $savedId,
            'action' => $action,
            'label' => $payload['name'],
        ];
    }

    private function saveExpense(ExpenseRepository $repository, array $input, array $files, int $actorUserId, array $accessibleBranchIds): array
    {
        $id = (int) ($input['id'] ?? 0);
        $existing = $id > 0 ? $repository->findExpense($id, $accessibleBranchIds) : null;
        if ($id > 0 && $existing === null) {
            throw new RuntimeException('The selected expense could not be found.');
        }

        $masterData = new MasterDataRepository($this->app);
        $availableBranches = array_values(array_filter(
            $masterData->rows('branches'),
            static fn (array $row): bool => in_array((int) ($row['id'] ?? 0), $accessibleBranchIds, true)
        ));
        $validBranchIds = array_map(static fn (array $row): int => (int) $row['id'], $availableBranches);

        $categoryId = (int) ($input['expense_category_id'] ?? 0);
        $category = $categoryId > 0 ? $repository->findCategory($categoryId) : null;
        if ($category === null || (int) ($category['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Please select an active expense category.');
        }

        $currency = mb_strtoupper(trim((string) ($input['currency'] ?? 'PKR')));
        $validCurrencies = array_map(
            static fn (array $row): string => mb_strtoupper((string) ($row['code'] ?? '')),
            $masterData->rows('currencies')
        );
        if (! in_array($currency, $validCurrencies, true)) {
            throw new RuntimeException('Please select a valid expense currency.');
        }

        $paymentMethod = mb_strtolower(trim((string) ($input['payment_method'] ?? '')));
        $validPaymentMethods = array_map(
            static fn (array $row): string => mb_strtolower((string) ($row['code'] ?? '')),
            $masterData->rows('payment_methods')
        );
        if (! in_array($paymentMethod, $validPaymentMethods, true)) {
            throw new RuntimeException('Please select a valid payment method.');
        }

        $branchId = (int) ($input['branch_id'] ?? 0);
        if (! in_array($branchId, $validBranchIds, true)) {
            throw new RuntimeException('Please select an accessible branch for the expense.');
        }

        $status = mb_strtolower(trim((string) ($input['expense_status'] ?? 'posted')));
        if (! in_array($status, ['active', 'posted'], true)) {
            throw new RuntimeException('Please select a valid expense status.');
        }

        $treasuryAccountId = max(0, (int) ($input['treasury_account_id'] ?? 0));
        $treasuryAccount = null;
        if ($status === 'posted' && $this->requiresTreasurySource($paymentMethod)) {
            if (! $repository->hasTreasuryAccountLink() || ! $repository->hasJournalEntryLink()) {
                throw new RuntimeException('Expense source-account posting is not available until the latest migration is applied.');
            }

            $treasuryAccount = (new TreasuryRepository($this->app))->validatePaymentTreasuryAccount(
                $treasuryAccountId,
                $branchId,
                $currency,
                $paymentMethod
            );
            if ((int) ($treasuryAccount['linked_account_id'] ?? 0) <= 0) {
                throw new RuntimeException('Selected source account is not linked to a ledger account.');
            }
        }

        $payload = [
            'id' => $id,
            'expense_date' => $this->normalizeDate((string) ($input['expense_date'] ?? '')),
            'branch_id' => $branchId,
            'expense_category_id' => $categoryId,
            'title' => $this->requiredText($input['title'] ?? null, 'Expense title', 190),
            'amount' => $this->positiveMoney($input['amount'] ?? 0),
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'treasury_account_id' => $treasuryAccount['id'] ?? null,
            'paid_to_name' => $this->optionalText($input['paid_to_name'] ?? null, 190),
            'reference_number' => $this->optionalText($input['reference_number'] ?? null, 120),
            'notes' => $this->optionalText($input['notes'] ?? null, 4000),
            'expense_status' => $status,
            'actor_user_id' => $actorUserId,
        ];
        $correctionReason = $existing !== null ? $this->requiredCorrectionReason($input['correction_reason'] ?? null) : null;
        $correctionNote = $existing !== null ? $this->optionalText($input['correction_note'] ?? null, 4000) : null;
        if ($existing !== null && !(new BusinessExpenseCorrectionRepository($this->app))->correctionsTableExists()) {
            throw new RuntimeException('Expense correction history is not available until the latest expense correction migration is applied.');
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $savedId = $repository->saveExpense($payload);
            $action = $existing === null ? 'created' : 'corrected';
            $attachmentAction = null;
            $priorJournalEntryId = $existing !== null ? (int) ($existing['journal_entry_id'] ?? 0) : 0;
            $reversalJournalEntryId = null;

            if ($repository->attachmentsTableExists() && $this->hasUpload($files['attachment_file'] ?? null)) {
                $attachmentAction = $this->storeExpenseAttachment(
                    $repository,
                    $savedId,
                    $payload['branch_id'],
                    $files['attachment_file'],
                    $actorUserId
                );
            }

            if ($existing !== null && $priorJournalEntryId > 0) {
                $reversalJournalEntryId = (new AccountingRepository($this->app))->postBusinessExpenseEditReversal([
                    'journal_entry_id' => $priorJournalEntryId,
                    'branch_id' => (int) ($existing['branch_id'] ?? $payload['branch_id']),
                    'source_reference' => 'EXP-CORR-' . $savedId,
                    'entry_date' => $payload['expense_date'],
                    'currency' => (string) ($existing['currency'] ?? $payload['currency']),
                    'narration' => 'Business expense correction reversal for expense #' . $savedId,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            $journalEntryId = null;
            if ($status === 'posted' && $treasuryAccount !== null) {
                $journalEntryId = (new AccountingRepository($this->app))->postBusinessExpenseRecorded([
                    'branch_id' => $payload['branch_id'],
                    'entry_date' => $payload['expense_date'],
                    'currency' => $payload['currency'],
                    'amount' => $payload['amount'],
                    'source_reference' => 'EXP-' . $savedId,
                    'narration' => 'Business expense: ' . $payload['title'],
                    'actor_user_id' => $actorUserId,
                    'source_account_id' => (int) ($treasuryAccount['linked_account_id'] ?? 0),
                    'source_account_code' => (string) ($treasuryAccount['account_code'] ?? ''),
                    'expense_line_description' => 'Business expense - ' . (string) ($category['name'] ?? 'Operating'),
                    'source_line_description' => 'Source account payout - ' . (string) ($treasuryAccount['account_name'] ?? 'Treasury account'),
                ]);
                $repository->updatePostingLinks(
                    $savedId,
                    (int) ($treasuryAccount['id'] ?? 0),
                    $journalEntryId
                );
            } elseif ($existing !== null) {
                $repository->updatePostingLinks(
                    $savedId,
                    $payload['treasury_account_id'] !== null ? (int) $payload['treasury_account_id'] : null,
                    null
                );
            }

            if ($existing !== null) {
                $this->recordExpenseCorrection(
                    $savedId,
                    $existing,
                    $payload,
                    $category,
                    $treasuryAccount,
                    (string) $correctionReason,
                    $correctionNote,
                    $actorUserId,
                    $priorJournalEntryId > 0 ? $priorJournalEntryId : null,
                    $reversalJournalEntryId,
                    $journalEntryId
                );
            }

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        AuditLog::record($this->app, 'admin.business_expense.' . $action, [
            'user_id' => $actorUserId,
            'expense_id' => $savedId,
            'branch_id' => $payload['branch_id'],
            'expense_category_id' => $payload['expense_category_id'],
            'title' => $payload['title'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'payment_method' => $payload['payment_method'],
            'treasury_account_id' => $payload['treasury_account_id'],
            'attachment_action' => $attachmentAction,
            'correction_reason' => $correctionReason,
            'correction_note' => $correctionNote,
        ]);

        return [
            'id' => $savedId,
            'action' => $action,
            'label' => $payload['title'],
        ];
    }

    private function requiresTreasurySource(string $paymentMethod): bool
    {
        return in_array(
            str_replace(' ', '_', mb_strtolower(trim($paymentMethod))),
            ['cash', 'bank_transfer', 'wallet', 'wallet_mobile', 'mobile_wallet', 'debit_card', 'credit_card', 'card'],
            true
        );
    }

    private function requiredCorrectionReason(mixed $value): string
    {
        $reason = trim((string) $value);
        if ($reason === '') {
            throw new RuntimeException('Correction reason is required when editing an expense.');
        }
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Correction reason must be at least 5 characters.');
        }
        if (mb_strlen($reason) > 1000) {
            throw new RuntimeException('Correction reason may not exceed 1000 characters.');
        }

        return $reason;
    }

    private function recordExpenseCorrection(
        int $expenseId,
        array $existing,
        array $payload,
        array $category,
        ?array $treasuryAccount,
        string $correctionReason,
        ?string $correctionNote,
        int $actorUserId,
        ?int $priorJournalEntryId,
        ?int $reversalJournalEntryId,
        ?int $newJournalEntryId
    ): void {
        $repository = new BusinessExpenseCorrectionRepository($this->app);
        if (! $repository->correctionsTableExists()) {
            return;
        }

        $repository->recordCorrection([
            'business_expense_id' => $expenseId,
            'branch_id' => $payload['branch_id'],
            'correction_date' => $payload['expense_date'],
            'correction_reason' => $correctionReason,
            'correction_note' => $correctionNote,
            'prior_expense_date' => (string) ($existing['expense_date'] ?? $payload['expense_date']),
            'new_expense_date' => $payload['expense_date'],
            'prior_category_id' => (int) ($existing['expense_category_id'] ?? 0),
            'new_category_id' => (int) $payload['expense_category_id'],
            'prior_category_name' => trim((string) ($existing['category_name'] ?? 'Uncategorised')),
            'new_category_name' => trim((string) ($category['name'] ?? 'Uncategorised')),
            'prior_title' => (string) ($existing['title'] ?? ''),
            'new_title' => (string) $payload['title'],
            'prior_amount' => round((float) ($existing['amount'] ?? 0), 2),
            'new_amount' => round((float) ($payload['amount'] ?? 0), 2),
            'prior_currency' => (string) ($existing['currency'] ?? 'PKR'),
            'new_currency' => (string) $payload['currency'],
            'prior_payment_method' => (string) ($existing['payment_method'] ?? ''),
            'new_payment_method' => (string) $payload['payment_method'],
            'prior_treasury_account_id' => isset($existing['treasury_account_id']) && $existing['treasury_account_id'] !== null ? (int) $existing['treasury_account_id'] : null,
            'new_treasury_account_id' => $payload['treasury_account_id'] !== null ? (int) $payload['treasury_account_id'] : null,
            'prior_treasury_account_name' => $existing['treasury_account_name'] ?? null,
            'new_treasury_account_name' => $treasuryAccount['account_name'] ?? null,
            'prior_paid_to_name' => $existing['paid_to_name'] ?? null,
            'new_paid_to_name' => $payload['paid_to_name'] ?? null,
            'prior_reference_number' => $existing['reference_number'] ?? null,
            'new_reference_number' => $payload['reference_number'] ?? null,
            'prior_notes' => $existing['notes'] ?? null,
            'new_notes' => $payload['notes'] ?? null,
            'prior_expense_status' => (string) ($existing['expense_status'] ?? 'posted'),
            'new_expense_status' => (string) $payload['expense_status'],
            'prior_journal_entry_id' => $priorJournalEntryId,
            'reversal_journal_entry_id' => $reversalJournalEntryId,
            'new_journal_entry_id' => $newJournalEntryId,
            'created_by_user_id' => $actorUserId,
        ]);
    }

    private function storeExpenseAttachment(
        ExpenseRepository $repository,
        int $expenseId,
        int $branchId,
        array $file,
        int $actorUserId
    ): string {
        $fileMeta = $this->validateUpload($file);
        $storageDirectory = $this->buildStorageDirectory($branchId, $expenseId);
        if (! is_dir($storageDirectory) && ! mkdir($storageDirectory, 0775, true) && ! is_dir($storageDirectory)) {
            throw new RuntimeException('Unable to prepare secure expense proof storage.');
        }

        $storedFileName = bin2hex(random_bytes(16)) . '.' . $fileMeta['extension'];
        $absolutePath = $storageDirectory . DIRECTORY_SEPARATOR . $storedFileName;
        if (! move_uploaded_file($fileMeta['tmp_name'], $absolutePath)) {
            throw new RuntimeException('The expense proof could not be stored securely.');
        }

        @chmod($absolutePath, 0640);

        $relativePath = trim(str_replace('\\', '/', substr($absolutePath, strlen(base_path('/storage')))), '/');
        $replacedAttachment = $repository->activeAttachmentForExpense($expenseId);

        try {
            $attachmentId = $repository->createAttachment([
                'business_expense_id' => $expenseId,
                'branch_id' => $branchId,
                'original_file_name' => $fileMeta['original_name'],
                'stored_file_name' => $storedFileName,
                'storage_path' => $relativePath,
                'mime_type' => $fileMeta['mime_type'],
                'file_extension' => $fileMeta['extension'],
                'file_size_bytes' => $fileMeta['size'],
                'sha256_hash' => $fileMeta['sha256_hash'],
                'replaced_attachment_id' => $replacedAttachment['id'] ?? null,
                'actor_user_id' => $actorUserId,
            ]);

            if ($replacedAttachment !== null) {
                $repository->supersedeAttachment((int) $replacedAttachment['id'], $actorUserId);
                AuditLog::record($this->app, 'admin.business_expense.attachment.replaced', [
                    'user_id' => $actorUserId,
                    'expense_id' => $expenseId,
                    'expense_attachment_id' => $attachmentId,
                    'replaced_attachment_id' => $replacedAttachment['id'],
                    'file_name' => $fileMeta['original_name'],
                ]);

                return 'replaced';
            }

            AuditLog::record($this->app, 'admin.business_expense.attachment.uploaded', [
                'user_id' => $actorUserId,
                'expense_id' => $expenseId,
                'expense_attachment_id' => $attachmentId,
                'file_name' => $fileMeta['original_name'],
            ]);

            return 'uploaded';
        } catch (\Throwable $exception) {
            @unlink($absolutePath);
            throw $exception;
        }
    }

    private function requiredText(mixed $value, string $label, int $maxLength): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new RuntimeException($label . ' is required.');
        }
        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException($label . ' exceeds the allowed length.');
        }

        return $text;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maxLength) {
            throw new RuntimeException('One of the optional expense values exceeds the allowed length.');
        }

        return $text;
    }

    private function normalizeDate(string $value): string
    {
        $date = trim($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Expense date must be a valid date in YYYY-MM-DD format.');
        }

        return $date;
    }

    private function positiveMoney(mixed $value): float
    {
        $amount = round((float) $value, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Expense amount must be greater than zero.');
        }

        return $amount;
    }

    private function assertPattern(string $value, string $pattern, string $message): string
    {
        if (! preg_match($pattern, $value)) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private function hasUpload(mixed $file): bool
    {
        return is_array($file)
            && isset($file['error'], $file['name'])
            && (int) $file['error'] !== UPLOAD_ERR_NO_FILE
            && trim((string) $file['name']) !== '';
    }

    private function validateUpload(mixed $file): array
    {
        if (! is_array($file) || ! isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
            throw new RuntimeException('Please choose a valid proof file to upload.');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The proof upload could not be completed securely.');
        }

        $tmpName = (string) $file['tmp_name'];
        if (! is_uploaded_file($tmpName)) {
            throw new RuntimeException('The selected proof upload is invalid.');
        }

        $maxBytes = (int) config('security.documents.max_upload_bytes', 8 * 1024 * 1024);
        $size = (int) $file['size'];
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Proof file size exceeds the allowed secure upload limit.');
        }

        $originalName = trim((string) $file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = array_map('strtolower', (array) config('security.documents.allowed_extensions', ['pdf', 'jpg', 'jpeg', 'png', 'webp']));
        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Only PDF, JPG, JPEG, PNG, and WEBP files are allowed for expense proof.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($tmpName);
        $allowedMimeTypes = (array) config('security.documents.allowed_mime_types', [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ]);
        if (! in_array($mimeType, $allowedMimeTypes, true)) {
            throw new RuntimeException('The uploaded proof file type is not permitted.');
        }

        if (! $this->extensionMatchesMime($extension, $mimeType)) {
            throw new RuntimeException('The uploaded proof extension does not match its detected file type.');
        }

        return [
            'tmp_name' => $tmpName,
            'original_name' => $this->safeOriginalFileName($originalName),
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
            'sha256_hash' => hash_file('sha256', $tmpName),
        ];
    }

    private function buildStorageDirectory(int $branchId, int $expenseId): string
    {
        $datePath = date('Y/m');

        return base_path('/storage/documents/expense-proofs/branch_' . $branchId . '/expense_' . $expenseId . '/' . $datePath);
    }

    private function secureStoredExpenseProofPath(string $storagePath): string
    {
        $absolutePath = $this->resolveStoredExpenseProofPath($storagePath, true);
        if ($absolutePath === null) {
            throw new RuntimeException('The secure expense proof storage path is invalid.');
        }

        return $absolutePath;
    }

    private function resolveStoredExpenseProofPath(string $storagePath, bool $throwOnMissing): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', $storagePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '../') || str_contains($relativePath, '..\\')) {
            if ($throwOnMissing) {
                throw new RuntimeException('The secure expense proof storage path is invalid.');
            }

            return null;
        }

        if (! str_starts_with($relativePath, 'documents/expense-proofs/') && ! str_starts_with($relativePath, 'documents/documents/expense-proofs/')) {
            if ($throwOnMissing) {
                throw new RuntimeException('The secure expense proof storage path is invalid.');
            }

            return null;
        }

        $documentRoot = realpath(base_path('/storage/documents/expense-proofs'));
        if ($documentRoot === false) {
            if ($throwOnMissing) {
                throw new RuntimeException('Secure expense proof storage is not available.');
            }

            return null;
        }

        $candidatePaths = [$relativePath];
        if (str_starts_with($relativePath, 'documents/documents/')) {
            $candidatePaths[] = substr($relativePath, strlen('documents/'));
        }

        foreach ($candidatePaths as $candidatePath) {
            $resolvedPath = realpath(base_path('/storage/' . $candidatePath));
            if ($resolvedPath !== false && str_starts_with($resolvedPath, $documentRoot . DIRECTORY_SEPARATOR)) {
                return $resolvedPath;
            }
        }

        if ($throwOnMissing) {
            throw new RuntimeException('The secure expense proof storage path is invalid.');
        }

        return null;
    }

    private function extensionMatchesMime(string $extension, string $mimeType): bool
    {
        return match ($extension) {
            'pdf' => $mimeType === 'application/pdf',
            'jpg', 'jpeg' => $mimeType === 'image/jpeg',
            'png' => $mimeType === 'image/png',
            'webp' => $mimeType === 'image/webp',
            default => false,
        };
    }

    private function safeOriginalFileName(string $name): string
    {
        $baseName = basename(str_replace('\\', '/', $name));
        $safeName = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $baseName) ?: 'expense-proof';
        $safeName = trim($safeName, " .\t\n\r\0\x0B");

        if ($safeName === '') {
            $safeName = 'expense-proof';
        }

        return mb_substr($safeName, 0, 180);
    }
}
