<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;
use RuntimeException;

final class BookingRepository extends BaseRepository
{
    public function branchOptions(array $accessibleBranchIds): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT id, code, name, city, country_code, base_currency
             FROM branches
             WHERE is_active = 1
               AND id IN ({$placeholders})
             ORDER BY name ASC"
        );
        $statement->execute(array_map('intval', $accessibleBranchIds));

        return $statement->fetchAll() ?: [];
    }

    public function activeBranchDirectory(): array
    {
        $statement = $this->db->query(
            'SELECT id, code, name, city, country_code, base_currency
             FROM branches
             WHERE is_active = 1
             ORDER BY id ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    public function createBooking(array $bookingData, array $partyData): int
    {
        return $this->transaction(function () use ($bookingData, $partyData): int {
            $reference = $this->nextBookingReference();

            $statement = $this->db->prepare(
                'INSERT INTO bookings (
                    booking_reference, branch_id, business_source_id, booking_status, booking_date, due_date, departure_date, return_date, remarks,
                    created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :booking_reference, :branch_id, :business_source_id, :booking_status, :booking_date, :due_date, :departure_date, :return_date, :remarks,
                    :created_by_user_id, :updated_by_user_id
                 )'
            );
            $statement->execute([
                'booking_reference' => $reference,
                'branch_id' => $bookingData['branch_id'],
                'business_source_id' => $bookingData['business_source_id'],
                'booking_status' => $bookingData['booking_status'],
                'booking_date' => $bookingData['booking_date'],
                'due_date' => $bookingData['due_date'],
                'departure_date' => $bookingData['departure_date'],
                'return_date' => $bookingData['return_date'],
                'remarks' => $bookingData['remarks'],
                'created_by_user_id' => $bookingData['actor_user_id'],
                'updated_by_user_id' => $bookingData['actor_user_id'],
            ]);

            $bookingId = (int) $this->db->lastInsertId();
            $this->upsertParty($bookingId, $partyData);

            return $bookingId;
        });
    }

    public function lockBookingForFinancialWrite(int $bookingId): bool
    {
        if (! $this->db->inTransaction()) {
            throw new RuntimeException('Booking financial lock requires a database transaction.');
        }
        $statement = $this->db->prepare('SELECT id FROM bookings WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $bookingId]);

        return $statement->fetchColumn() !== false;
    }

    public function updateBooking(int $bookingId, array $bookingData, array $partyData): void
    {
        $this->transaction(function () use ($bookingId, $bookingData, $partyData): void {
            $bookingStatement = $this->db->prepare(
                'SELECT branch_id, business_source_id, booking_reference
                 FROM bookings
                 WHERE id = :id
                 FOR UPDATE'
            );
            $bookingStatement->execute(['id' => $bookingId]);
            $currentBooking = $bookingStatement->fetch();

            if (! is_array($currentBooking)) {
                throw new RuntimeException('The booking could not be found for update.');
            }

            $priorBranchId = (int) ($currentBooking['branch_id'] ?? 0);
            $targetBranchId = (int) ($bookingData['branch_id'] ?? 0);
            $priorBusinessSourceId = (int) ($currentBooking['business_source_id'] ?? 0);
            $targetBusinessSourceId = (int) ($bookingData['business_source_id'] ?? 0);
            $bookingReference = (string) ($currentBooking['booking_reference'] ?? '');

            $cashCorrections = [];
            if ($priorBranchId !== $targetBranchId) {
                $branchTrace = array_merge(app_request_log_context(), [
                    'booking_id' => $bookingId,
                    'booking_reference' => $bookingReference,
                    'from_branch_id' => $priorBranchId,
                    'to_branch_id' => $targetBranchId,
                ]);
                app_write_log('booking.branch_change.guard', 'Checking destination branch and linked money.', $branchTrace);
                try {
                    $cashCorrections = $this->prepareBranchCashCorrections($bookingReference, $priorBranchId, $targetBranchId);
                    $this->assertBranchCorrectionIsSafe($bookingReference, $targetBranchId);
                } catch (RuntimeException $exception) {
                    app_write_log('booking.branch_change.blocked', $exception->getMessage(), $branchTrace);
                    throw $exception;
                }
                app_write_log('booking.branch_change.guard_passed', 'Branch correction may proceed.', $branchTrace);
            }
            if ($priorBusinessSourceId !== $targetBusinessSourceId) {
                $offsetStatement = $this->db->prepare(
                    'SELECT offset_record.offset_no
                     FROM counterparty_offset_account_allocations allocation
                     INNER JOIN counterparty_offsets offset_record
                        ON offset_record.id = allocation.counterparty_offset_id
                       AND offset_record.status = "posted"
                     INNER JOIN customer_receivable_items receivable
                        ON receivable.id = allocation.customer_receivable_item_id
                     WHERE receivable.booking_reference = :booking_reference
                     LIMIT 1'
                );
                $offsetStatement->execute(['booking_reference' => $bookingReference]);
                $offsetNo = $offsetStatement->fetchColumn();
                if ($offsetNo !== false) {
                    throw new RuntimeException(
                        'The account holder cannot be changed because linked-party adjustment '
                        . (string) $offsetNo . ' is posted against this booking. Void that adjustment first.'
                    );
                }
            }

            $statement = $this->db->prepare(
                'UPDATE bookings
                 SET branch_id = :branch_id,
                     business_source_id = :business_source_id,
                     booking_status = :booking_status,
                     booking_date = :booking_date,
                     due_date = :due_date,
                     departure_date = :departure_date,
                     return_date = :return_date,
                     remarks = :remarks,
                     updated_by_user_id = :updated_by_user_id
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $bookingId,
                'branch_id' => $bookingData['branch_id'],
                'business_source_id' => $bookingData['business_source_id'],
                'booking_status' => $bookingData['booking_status'],
                'booking_date' => $bookingData['booking_date'],
                'due_date' => $bookingData['due_date'],
                'departure_date' => $bookingData['departure_date'],
                'return_date' => $bookingData['return_date'],
                'remarks' => $bookingData['remarks'],
                'updated_by_user_id' => $bookingData['actor_user_id'],
            ]);

            if ($priorBranchId !== $targetBranchId) {
                $preservedJournalIds = $this->reverseBranchCashReceipts(
                    $cashCorrections,
                    $bookingReference,
                    $priorBranchId,
                    (int) $bookingData['actor_user_id']
                );
                $this->synchronizeBookingBranch($bookingId, $bookingReference, $targetBranchId, $preservedJournalIds);
                $this->repostBranchCashReceipts(
                    $cashCorrections,
                    $bookingReference,
                    $targetBranchId,
                    (int) $bookingData['actor_user_id']
                );
                $this->reclassifyRetainedReceiptCredits(
                    $cashCorrections,
                    $bookingReference,
                    $priorBranchId,
                    $targetBranchId,
                    (int) $bookingData['actor_user_id']
                );
            }

            $this->upsertParty($bookingId, $partyData);
        });
    }

    private function assertBranchCorrectionIsSafe(string $bookingReference, int $targetBranchId): void
    {
        $targetStatement = $this->db->prepare('SELECT 1 FROM branches WHERE id = :id AND is_active = 1 LIMIT 1');
        $targetStatement->execute(['id' => $targetBranchId]);
        if ($targetStatement->fetchColumn() === false) {
            throw new RuntimeException('The selected destination branch is not active.');
        }

        $offsetConflict = $this->db->prepare(
            'SELECT offset_rows.offset_no
             FROM (
                SELECT offset_record.offset_no
                FROM counterparty_offset_account_allocations allocation
                INNER JOIN counterparty_offsets offset_record
                    ON offset_record.id = allocation.counterparty_offset_id
                   AND offset_record.status = "posted"
                INNER JOIN customer_receivable_items receivable
                    ON receivable.id = allocation.customer_receivable_item_id
                WHERE receivable.booking_reference = :receivable_booking_reference
                UNION ALL
                SELECT offset_record.offset_no
                FROM counterparty_offset_payable_allocations allocation
                INNER JOIN counterparty_offsets offset_record
                    ON offset_record.id = allocation.counterparty_offset_id
                   AND offset_record.status = "posted"
                INNER JOIN supplier_obligations obligation
                    ON obligation.id = allocation.supplier_obligation_id
                WHERE obligation.booking_reference = :payable_booking_reference
             ) offset_rows
             LIMIT 1'
        );
        $offsetConflict->execute([
            'receivable_booking_reference' => $bookingReference,
            'payable_booking_reference' => $bookingReference,
        ]);
        $offsetNo = $offsetConflict->fetchColumn();
        if ($offsetNo !== false) {
            throw new RuntimeException(
                'Branch cannot be changed because linked-party adjustment ' . (string) $offsetNo
                . ' is posted against this booking. Void that adjustment first.'
            );
        }

        $advanceConflict = $this->db->prepare(
            'SELECT sa.reference_no
             FROM supplier_advance_applications saa
             INNER JOIN supplier_obligations so ON so.id = saa.supplier_obligation_id
             INNER JOIN supplier_advances sa ON sa.id = saa.supplier_advance_id
             WHERE so.booking_reference = :booking_reference AND sa.branch_id <> :target_branch_id
             LIMIT 1'
        );
        $advanceConflict->execute([
            'booking_reference' => $bookingReference,
            'target_branch_id' => $targetBranchId,
        ]);
        $advanceReference = $advanceConflict->fetchColumn();
        if ($advanceReference !== false) {
            throw new RuntimeException(
                'Branch cannot be changed because supplier advance ' . (string) $advanceReference
                . ' belongs to another branch. The shared supplier balance needs a separate reviewed correction.'
            );
        }

        $supplierPaymentConflict = $this->db->prepare(
            "SELECT sp.payment_no
             FROM supplier_payment_allocations spa
             INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
             INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
             LEFT JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
             WHERE so.booking_reference = :booking_reference
               AND sp.status <> 'void'
               AND (
                    sp.branch_id <> :target_branch_id
                    OR (ta.id IS NOT NULL AND ta.branch_id <> :target_treasury_branch_id)
               )
             LIMIT 1"
        );
        $supplierPaymentConflict->execute([
            'booking_reference' => $bookingReference,
            'target_branch_id' => $targetBranchId,
            'target_treasury_branch_id' => $targetBranchId,
        ]);
        $paymentNo = $supplierPaymentConflict->fetchColumn();
        if ($paymentNo !== false) {
            throw new RuntimeException(
                'Branch cannot be changed because supplier payment ' . (string) $paymentNo
                . ' belongs to another branch. Correct or void that payment first.'
            );
        }

        $directSupplierPaymentConflict = $this->db->prepare(
            "SELECT sp.payment_no
             FROM supplier_payments sp
             LEFT JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
             WHERE sp.booking_reference = :booking_reference
               AND sp.status <> 'void'
               AND ta.id IS NOT NULL
               AND ta.branch_id <> :target_branch_id
             LIMIT 1"
        );
        $directSupplierPaymentConflict->execute([
            'booking_reference' => $bookingReference,
            'target_branch_id' => $targetBranchId,
        ]);
        $directPaymentNo = $directSupplierPaymentConflict->fetchColumn();
        if ($directPaymentNo !== false) {
            throw new RuntimeException(
                'Branch cannot be changed because supplier payment ' . (string) $directPaymentNo
                . ' belongs to a cash/bank account in another branch. Correct or void that payment first.'
            );
        }
    }

    private function prepareBranchCashCorrections(string $bookingReference, int $priorBranchId, int $targetBranchId): array
    {
        $statement = $this->db->prepare(
            "SELECT cr.id, cr.receipt_no, cr.branch_id, cr.receipt_date, cr.currency,
                    cr.received_amount, cr.charges_amount, cr.returned_amount, cr.payment_method, cr.treasury_account_id,
                    ta.branch_id AS treasury_branch_id, ta.account_type, ta.linked_account_id
             FROM customer_receipts cr
             LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
             WHERE cr.booking_reference = :booking_reference AND cr.status <> 'void'
             FOR UPDATE"
        );
        $statement->execute(['booking_reference' => $bookingReference]);
        $corrections = [];
        foreach ($statement->fetchAll() ?: [] as $receipt) {
            $receiptNo = (string) $receipt['receipt_no'];
            $method = (string) $receipt['payment_method'];
            $treasuryId = (int) ($receipt['treasury_account_id'] ?? 0);
            if ($treasuryId <= 0) {
                if (in_array($method, ['cash', 'bank_transfer', 'debit_card', 'credit_card'], true)) {
                    throw new RuntimeException('Receipt ' . $receiptNo . ' has no linked cash/bank account; branch correction needs a reviewed account.');
                }
                continue;
            }
            if ((int) $receipt['branch_id'] !== $priorBranchId) {
                throw new RuntimeException('Receipt ' . $receiptNo . ' does not belong to the current booking branch.');
            }
            if (! in_array($method, ['cash', 'bank_transfer'], true)
                || (string) $receipt['account_type'] !== ($method === 'cash' ? 'cash' : 'bank')
            ) {
                throw new RuntimeException('Receipt ' . $receiptNo . ' has an unsupported funding method or account for branch correction.');
            }
            if (round((float) ($receipt['returned_amount'] ?? 0), 2) > 0) {
                throw new RuntimeException('Receipt ' . $receiptNo . ' has money returned to the customer; review its funding before correcting branches.');
            }

            $allocationStatement = $this->db->prepare(
                'SELECT cri.booking_reference
                 FROM customer_receipt_allocations cra
                 INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
                 WHERE cra.customer_receipt_id = :receipt_id AND cri.booking_reference <> :booking_reference
                 LIMIT 1'
            );
            $allocationStatement->execute(['receipt_id' => (int) $receipt['id'], 'booking_reference' => $bookingReference]);
            if ($allocationStatement->fetchColumn() !== false) {
                throw new RuntimeException('Receipt ' . $receiptNo . ' also pays another booking; its branch cannot be inferred from this booking alone.');
            }

            $accountCorrection = $this->db->prepare(
                'SELECT 1 FROM journal_entries je
                 INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 WHERE jel.customer_receipt_id = :receipt_id
                   AND je.source_type = "customer_receipt_account_corrected"
                 LIMIT 1'
            );
            $accountCorrection->execute(['receipt_id' => (int) $receipt['id']]);
            if ($method === 'bank_transfer' || (int) $receipt['treasury_branch_id'] !== $priorBranchId
                || $accountCorrection->fetchColumn() !== false
            ) {
                $history = $this->db->prepare(
                    'SELECT DISTINCT je.id, je.source_type
                     FROM journal_entries je
                     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                     WHERE jel.customer_receipt_id = :receipt_id
                       AND je.source_type IN (
                           "customer_receipt_recorded", "customer_receipt_amount_corrected",
                           "customer_receipt_account_corrected", "booking_branch_credit_reclassified",
                           "booking_branch_receipt_reversal"
                       )'
                );
                $history->execute(['receipt_id' => (int) $receipt['id']]);
                $historyRows = $history->fetchAll() ?: [];
                if (! in_array('customer_receipt_recorded', array_column($historyRows, 'source_type'), true)) {
                    throw new RuntimeException('Receipt ' . $receiptNo . ' has no original funding journal; review it before changing branches.');
                }
                $receipt['branch_correction_mode'] = 'retain_account';
                $receipt['history_journal_ids'] = array_map('intval', array_column($historyRows, 'id'));
                $corrections[] = $receipt;
                continue;
            }

            $targetAccount = $this->db->prepare(
                'SELECT id, is_default FROM treasury_accounts
                 WHERE branch_id = :branch_id AND account_type = :account_type
                   AND currency = :currency AND is_active = 1
                 ORDER BY is_default DESC, id ASC'
            );
            $targetAccount->execute([
                'branch_id' => $targetBranchId,
                'account_type' => 'cash',
                'currency' => (string) $receipt['currency'],
            ]);
            $accounts = $targetAccount->fetchAll() ?: [];
            $defaults = array_values(array_filter($accounts, static fn (array $row): bool => (int) $row['is_default'] === 1));
            $selected = count($defaults) === 1 ? $defaults[0] : (count($accounts) === 1 ? $accounts[0] : null);
            if ($selected === null) {
                throw new RuntimeException('No unique active ' . (string) $receipt['currency'] . ' cash account exists in the destination branch for receipt ' . $receiptNo . '.');
            }

            $cashJournal = $this->db->prepare(
                'SELECT je.id, je.branch_id, je.currency, je.source_type, je.source_reference,
                        MAX(CASE WHEN jel.account_id = :cash_account_id THEN 1 ELSE 0 END) AS uses_current_cash_account
                 FROM journal_entries je
                 INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 WHERE jel.customer_receipt_id = :receipt_id
                 GROUP BY je.id, je.branch_id, je.currency, je.source_type, je.source_reference
                 ORDER BY je.id ASC'
            );
            $cashJournal->execute([
                'receipt_id' => (int) $receipt['id'],
                'cash_account_id' => (int) $receipt['linked_account_id'],
            ]);
            $cashJournals = $cashJournal->fetchAll() ?: [];
            $receiptJournals = [];
            $reversedJournalIds = [];
            $historyJournalIds = [];
            foreach ($cashJournals as $journal) {
                $type = (string) $journal['source_type'];
                if ($type === 'customer_receipt_recorded') {
                    $receiptJournals[] = $journal;
                    $historyJournalIds[] = (int) $journal['id'];
                } elseif ($type === 'booking_branch_receipt_reversal') {
                    $prefix = $receiptNo . '-BRANCH-REV-';
                    $reference = (string) ($journal['source_reference'] ?? '');
                    if (! str_starts_with($reference, $prefix)
                        || ! ctype_digit(substr($reference, strlen($prefix)))
                    ) {
                        throw new RuntimeException('Receipt ' . $receiptNo . ' has an unrecognized branch reversal.');
                    }
                    $reversedJournalIds[(int) substr($reference, strlen($prefix))] = true;
                    $historyJournalIds[] = (int) $journal['id'];
                } elseif ((int) $journal['uses_current_cash_account'] === 1) {
                    throw new RuntimeException('Receipt ' . $receiptNo . ' has another cash correction; review it before changing branches.');
                }
            }
            $activeJournals = array_values(array_filter(
                $receiptJournals,
                static fn (array $journal): bool => ! isset($reversedJournalIds[(int) $journal['id']])
            ));
            if (count($activeJournals) !== 1
                || (int) $activeJournals[0]['branch_id'] !== $priorBranchId
                || (string) $activeJournals[0]['currency'] !== (string) $receipt['currency']
                || (int) $activeJournals[0]['uses_current_cash_account'] !== 1
            ) {
                throw new RuntimeException('Receipt ' . $receiptNo . ' has a nonstandard cash journal; review it before correcting branches.');
            }
            $receipt['old_journal_id'] = (int) $activeJournals[0]['id'];
            $receipt['history_journal_ids'] = $historyJournalIds;
            $receipt['target_treasury_account_id'] = (int) $selected['id'];
            $receipt['branch_correction_mode'] = 'move_cash';
            $corrections[] = $receipt;
        }

        return $corrections;
    }

    private function reverseBranchCashReceipts(array $corrections, string $bookingReference, int $priorBranchId, int $actorUserId): array
    {
        $accounting = new AccountingRepository($this->app);
        $preserved = [];
        foreach ($corrections as $receipt) {
            if (($receipt['branch_correction_mode'] ?? '') === 'retain_account') {
                array_push($preserved, ...$receipt['history_journal_ids']);
                continue;
            }
            $oldJournalId = (int) $receipt['old_journal_id'];
            $reversalId = $accounting->reverseJournalEntry($oldJournalId, [
                'branch_id' => $priorBranchId,
                'booking_reference' => $bookingReference,
                'source_type' => 'booking_branch_receipt_reversal',
                'source_reference' => (string) $receipt['receipt_no'] . '-BRANCH-REV-' . $oldJournalId,
                'entry_date' => date('Y-m-d'),
                'currency' => (string) $receipt['currency'],
                'narration' => 'Reverse receipt recorded against the wrong booking branch',
                'actor_user_id' => $actorUserId,
            ]);
            if ($reversalId === null) {
                throw new RuntimeException('The receipt cash journal could not be reversed.');
            }
            array_push($preserved, ...$receipt['history_journal_ids']);
            $preserved[] = $reversalId;
        }

        return array_values(array_unique($preserved));
    }

    private function repostBranchCashReceipts(array $corrections, string $bookingReference, int $targetBranchId, int $actorUserId): void
    {
        $accounting = new AccountingRepository($this->app);
        $update = $this->db->prepare(
            'UPDATE customer_receipts SET treasury_account_id = :treasury_account_id
             WHERE id = :id AND branch_id = :branch_id'
        );
        foreach ($corrections as $receipt) {
            if (($receipt['branch_correction_mode'] ?? '') !== 'move_cash') {
                continue;
            }
            $update->execute([
                'treasury_account_id' => (int) $receipt['target_treasury_account_id'],
                'id' => (int) $receipt['id'],
                'branch_id' => $targetBranchId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Receipt branch correction did not update exactly one receipt.');
            }
            $newJournalId = $accounting->postCustomerReceiptRecorded([
                'branch_id' => $targetBranchId,
                'booking_reference' => $bookingReference,
                'customer_receipt_id' => (int) $receipt['id'],
                'receipt_no' => (string) $receipt['receipt_no'],
                'source_reference' => (string) $receipt['receipt_no'] . '-BRANCH-CORR-' . (int) $receipt['old_journal_id'],
                'received_amount' => (float) $receipt['received_amount'],
                'charges_amount' => (float) $receipt['charges_amount'],
                'payment_method' => (string) $receipt['payment_method'],
                'treasury_account_id' => (int) $receipt['target_treasury_account_id'],
                'entry_date' => date('Y-m-d'),
                'currency' => (string) $receipt['currency'],
                'actor_user_id' => $actorUserId,
                'narration' => 'Receipt reclassified to the corrected booking branch',
            ]);
            AuditLog::recordRequired($this->app, 'booking.branch_receipt_reclassified', [
                'user_id' => $actorUserId,
                'booking_reference' => $bookingReference,
                'customer_receipt_id' => (int) $receipt['id'],
                'from_branch_id' => (int) $receipt['branch_id'],
                'to_branch_id' => $targetBranchId,
                'from_treasury_account_id' => (int) $receipt['treasury_account_id'],
                'to_treasury_account_id' => (int) $receipt['target_treasury_account_id'],
                'old_journal_id' => (int) $receipt['old_journal_id'],
                'new_journal_id' => $newJournalId,
                'currency' => (string) $receipt['currency'],
                'amount' => (string) $receipt['received_amount'],
            ]);
            app_write_log('booking.branch_change.receipt_reclassification_staged', 'Receipt cash posting staged in the booking transaction.', [
                'booking_reference' => $bookingReference,
                'receipt_id' => (int) $receipt['id'],
                'from_treasury_account_id' => (int) $receipt['treasury_account_id'],
                'to_treasury_account_id' => (int) $receipt['target_treasury_account_id'],
                'old_journal_id' => (int) $receipt['old_journal_id'],
                'new_journal_id' => $newJournalId,
            ]);
        }
    }

    private function reclassifyRetainedReceiptCredits(
        array $corrections,
        string $bookingReference,
        int $priorBranchId,
        int $targetBranchId,
        int $actorUserId
    ): void {
        $accounting = new AccountingRepository($this->app);
        foreach ($corrections as $receipt) {
            if (($receipt['branch_correction_mode'] ?? '') !== 'retain_account') {
                continue;
            }
            $journalIds = $accounting->postBookingBranchCreditReclassification([
                'from_branch_id' => $priorBranchId,
                'to_branch_id' => $targetBranchId,
                'booking_reference' => $bookingReference,
                'customer_receipt_id' => (int) $receipt['id'],
                'source_reference' => (string) $receipt['receipt_no'] . '-BRANCH-CREDIT-' . strtoupper(bin2hex(random_bytes(4))),
                'entry_date' => date('Y-m-d'),
                'currency' => (string) $receipt['currency'],
                'amount' => (float) $receipt['received_amount'],
                'actor_user_id' => $actorUserId,
            ]);
            AuditLog::recordRequired($this->app, 'booking.branch_receipt_credit_reclassified', [
                'user_id' => $actorUserId,
                'booking_reference' => $bookingReference,
                'customer_receipt_id' => (int) $receipt['id'],
                'from_branch_id' => $priorBranchId,
                'to_branch_id' => $targetBranchId,
                'retained_treasury_account_id' => (int) $receipt['treasury_account_id'],
                'amount' => (string) $receipt['received_amount'],
                'currency' => (string) $receipt['currency'],
                'journal_ids' => $journalIds,
            ]);
        }
    }

    private function synchronizeBookingBranch(int $bookingId, string $bookingReference, int $targetBranchId, array $preservedJournalIds = []): void
    {
        $bookingTables = [
            'booking_services' => 'booking_id',
            'booking_service_events' => 'booking_id',
            'service_financial_corrections' => 'booking_id',
            'booking_documents' => 'booking_id',
            'booking_reminders' => 'booking_id',
        ];

        foreach ($bookingTables as $table => $bookingColumn) {
            if (! $this->tableExists($table) || ! $this->columnExists($table, 'branch_id')) {
                continue;
            }

            $statement = $this->db->prepare(
                "UPDATE {$table} SET branch_id = :branch_id WHERE {$bookingColumn} = :booking_id"
            );
            $statement->execute([
                'branch_id' => $targetBranchId,
                'booking_id' => $bookingId,
            ]);
        }

        foreach (['customer_receivable_items', 'supplier_obligations'] as $table) {
            $statement = $this->db->prepare(
                "UPDATE {$table} SET branch_id = :branch_id WHERE booking_reference = :booking_reference"
            );
            $statement->execute([
                'branch_id' => $targetBranchId,
                'booking_reference' => $bookingReference,
            ]);
        }

        $receiptStatement = $this->db->prepare(
            "UPDATE customer_receipts
             SET branch_id = :branch_id
             WHERE booking_reference = :booking_reference
               AND status <> 'void'"
        );
        $receiptStatement->execute([
            'branch_id' => $targetBranchId,
            'booking_reference' => $bookingReference,
        ]);

        $supplierPaymentStatement = $this->db->prepare(
            "UPDATE supplier_payments
             SET branch_id = :branch_id
             WHERE booking_reference = :booking_reference
               AND status <> 'void'"
        );
        $supplierPaymentStatement->execute([
            'branch_id' => $targetBranchId,
            'booking_reference' => $bookingReference,
        ]);

        $excluded = [];
        $journalParams = [
            'branch_id' => $targetBranchId,
            'booking_reference' => $bookingReference,
            'booking_id' => $bookingId,
        ];
        foreach ($preservedJournalIds as $index => $journalId) {
            $key = 'preserved_journal_' . $index;
            $excluded[] = ':' . $key;
            $journalParams[$key] = $journalId;
        }
        $exclusionSql = $excluded === [] ? '' : ' AND je.id NOT IN (' . implode(', ', $excluded) . ')';
        $journalStatement = $this->db->prepare(
            'UPDATE journal_entries je
             SET je.branch_id = :branch_id
             WHERE je.booking_reference = :booking_reference
               ' . $exclusionSql . '
               AND je.source_type NOT IN (
                   "customer_receipt_recorded", "customer_receipt_amount_corrected",
                   "customer_receipt_account_corrected", "booking_branch_credit_reclassified",
                   "booking_branch_receipt_reversal", "customer_receipt_void_reversal",
                   "customer_receipt_void_reversed", "customer_receipt_wrong_currency_reversed"
               )
               AND NOT EXISTS (
                    SELECT 1 FROM journal_entry_lines treasury_line
                    INNER JOIN treasury_accounts treasury_account
                        ON treasury_account.linked_account_id = treasury_line.account_id
                       AND treasury_account.branch_id = je.branch_id
                       AND treasury_account.currency = je.currency
                    WHERE treasury_line.journal_entry_id = je.id
               )
               AND EXISTS (
                    SELECT 1
                    FROM booking_services bs
                    WHERE bs.booking_id = :booking_id
                      AND (bs.currency = je.currency OR bs.cost_currency = je.currency)
               )'
        );
        $journalStatement->execute($journalParams);
    }

    public function closeReadinessTotals(int $bookingId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                COALESCE(recv.customer_outstanding, 0) AS customer_outstanding,
                COALESCE(supp.supplier_outstanding, 0) AS supplier_outstanding,
                COALESCE(svc.service_count, 0) AS service_count
             FROM bookings b
             LEFT JOIN (
                SELECT booking_reference, SUM(GREATEST(outstanding_amount, 0)) AS customer_outstanding
                FROM customer_receivable_items
                WHERE status IN ("open", "partially_paid")
                GROUP BY booking_reference
             ) recv ON recv.booking_reference = b.booking_reference
             LEFT JOIN (
                SELECT booking_reference, SUM(GREATEST(net_payable_amount, 0)) AS supplier_outstanding
                FROM supplier_obligations
                WHERE status IN ("open", "partially_covered")
                GROUP BY booking_reference
             ) supp ON supp.booking_reference = b.booking_reference
             LEFT JOIN (
                SELECT booking_id, COUNT(*) AS service_count
                FROM booking_services
                WHERE LOWER(status) <> "cancelled"
                GROUP BY booking_id
             ) svc ON svc.booking_id = b.id
             WHERE b.id = :booking_id'
        );
        $statement->execute(['booking_id' => $bookingId]);
        $row = $statement->fetch() ?: [];

        return [
            'customerOutstanding' => round((float) ($row['customer_outstanding'] ?? 0), 2),
            'supplierOutstanding' => round((float) ($row['supplier_outstanding'] ?? 0), 2),
            'serviceCount' => (int) ($row['service_count'] ?? 0),
        ];
    }

    public function findBookingById(int $bookingId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                b.id,
                b.booking_reference,
                b.branch_id,
                b.business_source_id,
                b.lead_traveler_id,
                b.booking_status,
                b.booking_date,
                b.due_date,
                b.departure_date,
                b.return_date,
                b.remarks,
                b.created_at,
                b.updated_at,
                bp.party_label,
                bp.lead_traveler_name,
                bp.contact_mobile,
                bp.passport_number,
                bp.notes AS party_notes,
                br.code AS branch_code,
                br.name AS branch_name,
                br.city AS branch_city,
                br.country_code,
                br.base_currency,
                bsr.name AS business_source_name,
                bsr.phone AS business_source_phone,
                bsr.address AS business_source_address,
                bsr.description AS business_source_description,
                CASE WHEN created_user.username IS NOT NULL AND created_user.username <> "" THEN created_user.username ELSE created_user.email END AS created_by_name,
                CASE WHEN updated_user.username IS NOT NULL AND updated_user.username <> "" THEN updated_user.username ELSE updated_user.email END AS updated_by_name
             FROM bookings b
             INNER JOIN branches br ON br.id = b.branch_id
             LEFT JOIN business_sources bsr ON bsr.id = b.business_source_id
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN users created_user ON created_user.id = b.created_by_user_id
             LEFT JOIN users updated_user ON updated_user.id = b.updated_by_user_id
             WHERE b.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $bookingId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findBookingByReference(string $bookingReference, array $accessibleBranchIds): ?array
    {
        $trimmedReference = trim($bookingReference);
        if ($trimmedReference === '' || $accessibleBranchIds === []) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT
                b.id,
                b.booking_reference,
                b.branch_id,
                b.business_source_id,
                b.lead_traveler_id,
                b.booking_status,
                b.booking_date,
                b.due_date,
                b.departure_date,
                b.return_date,
                b.remarks,
                b.created_at,
                b.updated_at,
                bp.party_label,
                bp.lead_traveler_name,
                bp.contact_mobile,
                bp.passport_number,
                bp.notes AS party_notes,
                br.code AS branch_code,
                br.name AS branch_name,
                br.city AS branch_city,
                br.country_code,
                br.base_currency,
                bsr.name AS business_source_name,
                bsr.phone AS business_source_phone,
                bsr.address AS business_source_address,
                bsr.description AS business_source_description,
                CASE WHEN created_user.username IS NOT NULL AND created_user.username <> '' THEN created_user.username ELSE created_user.email END AS created_by_name,
                CASE WHEN updated_user.username IS NOT NULL AND updated_user.username <> '' THEN updated_user.username ELSE updated_user.email END AS updated_by_name
             FROM bookings b
             INNER JOIN branches br ON br.id = b.branch_id
             LEFT JOIN business_sources bsr ON bsr.id = b.business_source_id
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN users created_user ON created_user.id = b.created_by_user_id
             LEFT JOIN users updated_user ON updated_user.id = b.updated_by_user_id
             WHERE b.booking_reference = ?
               AND b.branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $statement->execute(array_merge([$trimmedReference], array_map('intval', $accessibleBranchIds)));
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function updateBookingDueDate(int $bookingId, ?string $dueDate, ?int $actorUserId = null): void
    {
        $statement = $this->db->prepare(
            'UPDATE bookings
             SET due_date = :due_date,
                 updated_by_user_id = COALESCE(:updated_by_user_id, updated_by_user_id)
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $bookingId,
            'due_date' => $dueDate,
            'updated_by_user_id' => $actorUserId,
        ]);
    }

    public function searchBookings(string $query, array $accessibleBranchIds, int $limit = 20): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $likeQuery = '%' . trim($query) . '%';
        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "SELECT
                    b.id,
                    b.booking_reference,
                    b.booking_status,
                    b.booking_date,
                    b.departure_date,
                    b.return_date,
                    b.updated_at,
                    br.name AS branch_name,
                    br.base_currency,
                    COALESCE(bsr.name, '') AS business_source_name,
                    bp.lead_traveler_name,
                    bp.contact_mobile,
                    bp.passport_number,
                    COALESCE(svc.service_line_reference, '') AS service_line_reference,
                    COALESCE(svc.ticket_number, '') AS ticket_number,
                    COALESCE(svc.pnr, '') AS pnr,
                    COALESCE(svc.supplier_name, '') AS supplier_name,
                    COALESCE(rcpt.latest_receipt_no, '') AS receipt_no,
                    COALESCE(svc.booking_currency, recv.outstanding_currency, br.base_currency, 'PKR') AS booking_currency,
                    COALESCE(recv.total_outstanding, 0) AS total_outstanding
                FROM bookings b
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN business_sources bsr ON bsr.id = b.business_source_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN (
                    SELECT
                        bs.booking_id,
                        MAX(bs.line_reference) AS service_line_reference,
                        MAX(COALESCE(sat.ticket_number, '')) AS ticket_number,
                        MAX(COALESCE(sat.pnr, '')) AS pnr,
                        MAX(COALESCE(s.name, '')) AS supplier_name,
                        MAX(COALESCE(bs.currency, 'PKR')) AS booking_currency
                    FROM booking_services bs
                    LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                    LEFT JOIN suppliers s ON s.id = bs.supplier_id
                    GROUP BY bs.booking_id
                ) svc ON svc.booking_id = b.id
                LEFT JOIN (
                    SELECT
                        booking_reference,
                        MAX(currency) AS outstanding_currency,
                        SUM(CASE WHEN status <> 'cancelled' THEN outstanding_amount ELSE 0 END) AS total_outstanding
                    FROM customer_receivable_items
                    GROUP BY booking_reference
                ) recv ON recv.booking_reference = b.booking_reference
                LEFT JOIN (
                    SELECT
                        booking_reference,
                        MAX(receipt_no) AS latest_receipt_no
                    FROM customer_receipts
                    GROUP BY booking_reference
                ) rcpt ON rcpt.booking_reference = b.booking_reference
                WHERE b.branch_id IN ({$placeholders})";

        $params = array_map('intval', $accessibleBranchIds);

        if ($query !== '') {
            $sql .= '
                AND (
                    CAST(b.id AS CHAR) = ?
                    OR b.booking_reference LIKE ?
                    OR bp.lead_traveler_name LIKE ?
                    OR COALESCE(bsr.name, "") LIKE ?
                    OR COALESCE(bp.contact_mobile, "") LIKE ?
                    OR COALESCE(bp.passport_number, "") LIKE ?
                    OR COALESCE(svc.service_line_reference, "") LIKE ?
                    OR COALESCE(svc.ticket_number, "") LIKE ?
                    OR COALESCE(svc.pnr, "") LIKE ?
                    OR COALESCE(svc.supplier_name, "") LIKE ?
                    OR COALESCE(rcpt.latest_receipt_no, "") LIKE ?
                    OR EXISTS (
                        SELECT 1
                        FROM customer_receipts crx
                        WHERE crx.booking_reference = b.booking_reference
                          AND crx.receipt_no LIKE ?
                    )
                )';
            $params[] = trim($query);
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
            $params[] = $likeQuery;
        }

        $sql .= '
            ORDER BY b.updated_at DESC, b.id DESC
            LIMIT ' . max(1, $limit);

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function recentBookingsForWorkspace(array $accessibleBranchIds, int $limit = 12): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "SELECT
                    b.id,
                    b.booking_reference,
                    b.booking_date,
                    b.updated_at,
                    bp.lead_traveler_name AS customer_name,
                    br.name AS branch_name,
                    br.base_currency,
                    COALESCE(svc.passenger_names, '') AS passenger_names,
                    COALESCE(svc.pnrs, '') AS pnrs,
                    COALESCE(svc.routes, '') AS routes,
                    COALESCE(recv.invoice_currency, br.base_currency, 'PKR') AS invoice_currency,
                    COALESCE(recv.invoice_amount, 0) AS invoice_amount,
                    COALESCE(recv.paid_amount, 0) AS paid_amount,
                    COALESCE(recv.outstanding_amount, 0) AS outstanding_amount
                FROM bookings b
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN (
                    SELECT
                        bs.booking_id,
                        GROUP_CONCAT(DISTINCT NULLIF(COALESCE(t.full_name, bs.passenger_name_snapshot), '') ORDER BY COALESCE(t.full_name, bs.passenger_name_snapshot) SEPARATOR ', ') AS passenger_names,
                        GROUP_CONCAT(DISTINCT NULLIF(COALESCE(sat.pnr, ''), '') ORDER BY sat.pnr SEPARATOR ', ') AS pnrs,
                        GROUP_CONCAT(
                            DISTINCT NULLIF(
                                CONCAT_WS('/',
                                    NULLIF(COALESCE(sat.sector_from, ''), ''),
                                    NULLIF(COALESCE(sat.sector_to, ''), '')
                                ),
                                ''
                            )
                            ORDER BY sat.sector_from, sat.sector_to SEPARATOR ', '
                        ) AS routes
                    FROM booking_services bs
                    LEFT JOIN travelers t ON t.id = bs.traveler_id
                    LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                    GROUP BY bs.booking_id
                ) svc ON svc.booking_id = b.id
                LEFT JOIN (
                    SELECT
                        cri.booking_reference,
                        MAX(cri.currency) AS invoice_currency,
                        SUM(cri.due_amount) AS invoice_amount,
                        SUM(cri.allocated_amount) AS paid_amount,
                        SUM(cri.outstanding_amount) AS outstanding_amount
                    FROM customer_receivable_items cri
                    WHERE cri.status <> 'cancelled'
                    GROUP BY cri.booking_reference
                ) recv ON recv.booking_reference = b.booking_reference
                WHERE b.branch_id IN ({$placeholders})
                ORDER BY COALESCE(b.updated_at, b.booking_date) DESC, b.id DESC
                LIMIT " . max(1, $limit);

        $statement = $this->db->prepare($sql);
        $statement->execute(array_map('intval', $accessibleBranchIds));

        return $statement->fetchAll() ?: [];
    }

    public function bookingExistsInBranches(int $bookingId, array $accessibleBranchIds): bool
    {
        if ($accessibleBranchIds === []) {
            return false;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT id
             FROM bookings
             WHERE id = ?
               AND branch_id IN ({$placeholders})
             LIMIT 1"
        );
        $params = array_merge([$bookingId], array_map('intval', $accessibleBranchIds));
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function recentActivityForBooking(string $bookingReference, int $limit = 12): array
    {
        $statement = $this->db->prepare(
            'SELECT event_name, created_at, payload_json
             FROM audit_logs
             WHERE payload_json LIKE :reference_pattern
             ORDER BY created_at DESC, id DESC
             LIMIT :limit_rows'
        );
        $statement->bindValue(':reference_pattern', '%"booking_reference":"' . $bookingReference . '"%');
        $statement->bindValue(':limit_rows', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    private function upsertParty(int $bookingId, array $partyData): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO booking_parties (
                booking_id, party_label, lead_traveler_name, contact_mobile, passport_number, notes
             ) VALUES (
                :booking_id, :party_label, :lead_traveler_name, :contact_mobile, :passport_number, :notes
             )
             ON DUPLICATE KEY UPDATE
                party_label = VALUES(party_label),
                lead_traveler_name = VALUES(lead_traveler_name),
                contact_mobile = VALUES(contact_mobile),
                passport_number = VALUES(passport_number),
                notes = VALUES(notes)'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'party_label' => $partyData['party_label'],
            'lead_traveler_name' => $partyData['lead_traveler_name'],
            'contact_mobile' => $partyData['contact_mobile'],
            'passport_number' => $partyData['passport_number'],
            'notes' => $partyData['notes'],
        ]);
    }

    private function nextBookingReference(): string
    {
        $statement = $this->db->prepare(
            'SELECT id, setting_value
             FROM app_settings
             WHERE setting_key = :setting_key
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['setting_key' => 'booking.reference.sequence']);
        $row = $statement->fetch();

        $nextSequence = ((int) ($row['setting_value'] ?? 0)) + 1;

        if ($row === false) {
            $insert = $this->db->prepare(
                'INSERT INTO app_settings (setting_key, setting_value)
                 VALUES (:setting_key, :setting_value)'
            );
            $insert->execute([
                'setting_key' => 'booking.reference.sequence',
                'setting_value' => (string) $nextSequence,
            ]);
        } else {
            $update = $this->db->prepare(
                'UPDATE app_settings
                 SET setting_value = :setting_value
                 WHERE id = :id'
            );
            $update->execute([
                'setting_value' => (string) $nextSequence,
                'id' => $row['id'],
            ]);
        }

        return 'BK-' . str_pad((string) $nextSequence, 6, '0', STR_PAD_LEFT);
    }
}
