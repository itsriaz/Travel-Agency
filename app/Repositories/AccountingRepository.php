<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;
use RuntimeException;

final class AccountingRepository extends BaseRepository
{
    private array $accountIdCache = [];

    public function postJournalEntry(array $header, array $lines): int
    {
        return $this->transaction(function () use ($header, $lines): int {
            $debitTotal = 0.0;
            $creditTotal = 0.0;

            foreach ($lines as $line) {
                $debitTotal += (float) ($line['debit_amount'] ?? 0);
                $creditTotal += (float) ($line['credit_amount'] ?? 0);
            }

            if (round($debitTotal, 2) !== round($creditTotal, 2)) {
                throw new RuntimeException('Journal entry is not balanced.');
            }

            $entryStatement = $this->db->prepare(
                'INSERT INTO journal_entries (
                    branch_id, booking_reference, source_type, source_reference, entry_date, currency, narration, posted_by_user_id
                 ) VALUES (
                    :branch_id, :booking_reference, :source_type, :source_reference, :entry_date, :currency, :narration, :posted_by_user_id
                 )'
            );
            $entryStatement->execute([
                'branch_id' => $header['branch_id'],
                'booking_reference' => $header['booking_reference'] ?? null,
                'source_type' => $header['source_type'],
                'source_reference' => $header['source_reference'] ?? null,
                'entry_date' => $header['entry_date'],
                'currency' => $header['currency'],
                'narration' => $header['narration'],
                'posted_by_user_id' => $header['actor_user_id'] ?? null,
            ]);

            $journalEntryId = (int) $this->db->lastInsertId();

            $lineColumns = [
                'journal_entry_id',
                'account_id',
                'service_line_reference',
                'supplier_obligation_id',
            ];
            if ($this->columnExists('journal_entry_lines', 'supplier_payment_id')) {
                $lineColumns[] = 'supplier_payment_id';
            }
            array_push(
                $lineColumns,
                'customer_receivable_item_id',
                'customer_receipt_id',
                'line_description',
                'debit_amount',
                'credit_amount'
            );

            $linePlaceholders = array_map(static fn (string $column): string => ':' . $column, $lineColumns);
            $lineStatement = $this->db->prepare(sprintf(
                'INSERT INTO journal_entry_lines (%s) VALUES (%s)',
                implode(', ', $lineColumns),
                implode(', ', $linePlaceholders)
            ));

            foreach ($lines as $line) {
                $linePayload = [
                    'journal_entry_id' => $journalEntryId,
                    'account_id' => $this->accountIdByCode($line['account_code']),
                    'service_line_reference' => $line['service_line_reference'] ?? null,
                    'supplier_obligation_id' => $line['supplier_obligation_id'] ?? null,
                    'supplier_payment_id' => $line['supplier_payment_id'] ?? null,
                    'customer_receivable_item_id' => $line['customer_receivable_item_id'] ?? null,
                    'customer_receipt_id' => $line['customer_receipt_id'] ?? null,
                    'line_description' => $line['line_description'] ?? null,
                    'debit_amount' => $line['debit_amount'] ?? 0,
                    'credit_amount' => $line['credit_amount'] ?? 0,
                ];
                $lineStatement->execute(array_intersect_key($linePayload, array_flip($lineColumns)));
            }

            AuditLog::record($this->app, 'accounting.journal_entry.posted', [
                'user_id' => $header['actor_user_id'] ?? null,
                'journal_entry_id' => $journalEntryId,
                'booking_reference' => $header['booking_reference'] ?? null,
                'source_type' => $header['source_type'],
                'source_reference' => $header['source_reference'] ?? null,
                'currency' => $header['currency'],
                'debit_total' => round($debitTotal, 2),
                'credit_total' => round($creditTotal, 2),
            ]);

            return $journalEntryId;
        });
    }

    public function postReceivableCreated(array $data): int
    {
        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'service_receivable_created',
            'source_reference' => $data['source_reference'] ?? $data['service_line_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Immediate receivable created from service line',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'AR_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'customer_receivable_item_id' => $data['customer_receivable_item_id'] ?? null,
                'line_description' => 'Accounts receivable control',
                'debit_amount' => $data['gross_amount'],
                'credit_amount' => 0,
            ],
            [
                'account_code' => 'SERVICE_REVENUE',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'customer_receivable_item_id' => $data['customer_receivable_item_id'] ?? null,
                'line_description' => 'Service revenue recognized',
                'debit_amount' => 0,
                'credit_amount' => $data['gross_amount'],
            ],
        ]);
    }

    public function postPayableCreated(array $data): int
    {
        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'supplier_payable_created',
            'source_reference' => $data['source_reference'] ?? $data['service_line_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Immediate payable created from supplier obligation',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'SERVICE_COST',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Service cost recognized',
                'debit_amount' => $data['gross_amount'],
                'credit_amount' => 0,
            ],
            [
                'account_code' => 'AP_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Accounts payable control',
                'debit_amount' => 0,
                'credit_amount' => $data['gross_amount'],
            ],
        ]);
    }

    public function postReceivableAdjusted(array $data): int
    {
        $amount = abs((float) $data['adjustment_amount']);
        $isIncrease = (float) $data['adjustment_amount'] >= 0;

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'service_receivable_adjusted',
            'source_reference' => $data['source_reference'] ?? $data['service_line_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Service receivable adjusted from service update',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'AR_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'customer_receivable_item_id' => $data['customer_receivable_item_id'] ?? null,
                'line_description' => 'Accounts receivable control adjustment',
                'debit_amount' => $isIncrease ? $amount : 0,
                'credit_amount' => $isIncrease ? 0 : $amount,
            ],
            [
                'account_code' => 'SERVICE_REVENUE',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'customer_receivable_item_id' => $data['customer_receivable_item_id'] ?? null,
                'line_description' => 'Service revenue adjustment',
                'debit_amount' => $isIncrease ? 0 : $amount,
                'credit_amount' => $isIncrease ? $amount : 0,
            ],
        ]);
    }

    public function postPayableAdjusted(array $data): int
    {
        $amount = abs((float) $data['adjustment_amount']);
        $isIncrease = (float) $data['adjustment_amount'] >= 0;

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'supplier_payable_adjusted',
            'source_reference' => $data['source_reference'] ?? $data['service_line_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Supplier payable adjusted from service update',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'SERVICE_COST',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Service cost adjustment',
                'debit_amount' => $isIncrease ? $amount : 0,
                'credit_amount' => $isIncrease ? 0 : $amount,
            ],
            [
                'account_code' => 'AP_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Accounts payable control adjustment',
                'debit_amount' => $isIncrease ? 0 : $amount,
                'credit_amount' => $isIncrease ? $amount : 0,
            ],
        ]);
    }

    public function postSupplierAdvanceDeposit(array $data): int
    {
        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'] ?? null,
            'source_type' => 'supplier_advance_recorded',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Supplier advance deposit recorded',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'SUPPLIER_ADVANCES',
                'line_description' => 'Supplier advance asset created',
                'debit_amount' => $data['amount'],
                'credit_amount' => 0,
            ],
            [
                'account_code' => $data['cash_account_code'] ?? 'BANK_CLEARING',
                'line_description' => 'Cash or bank outflow',
                'debit_amount' => 0,
                'credit_amount' => $data['amount'],
            ],
        ]);
    }

    public function postSupplierAdvanceApplication(array $data): int
    {
        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'supplier_advance_applied',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Supplier advance applied against payable',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'AP_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Accounts payable reduced by advance application',
                'debit_amount' => $data['amount'],
                'credit_amount' => 0,
            ],
            [
                'account_code' => 'SUPPLIER_ADVANCES',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Supplier advance consumed',
                'debit_amount' => 0,
                'credit_amount' => $data['amount'],
            ],
        ]);
    }

    public function postSupplierAdvanceApplicationAdjusted(array $data): int
    {
        $amount = abs((float) $data['adjustment_amount']);
        $isIncrease = (float) $data['adjustment_amount'] >= 0;

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'supplier_advance_adjusted',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Supplier advance application adjusted against payable',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'AP_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Accounts payable adjusted by supplier advance reconciliation',
                'debit_amount' => $isIncrease ? $amount : 0,
                'credit_amount' => $isIncrease ? 0 : $amount,
            ],
            [
                'account_code' => 'SUPPLIER_ADVANCES',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Supplier advance reconciliation adjustment',
                'debit_amount' => $isIncrease ? 0 : $amount,
                'credit_amount' => $isIncrease ? $amount : 0,
            ],
        ]);
    }

    public function postSupplierSettlementRelease(array $data): int
    {
        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'supplier_settlement_released',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Supplier settlement released back to supplier credit',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'SUPPLIER_ADVANCES',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Supplier credit restored from cancellation settlement release',
                'debit_amount' => $data['released_amount'],
                'credit_amount' => 0,
            ],
            [
                'account_code' => 'AP_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'line_description' => 'Accounts payable restored from cancellation settlement release',
                'debit_amount' => 0,
                'credit_amount' => $data['released_amount'],
            ],
        ]);
    }

    public function postSupplierPaymentRecorded(array $data): int
    {
        $chargesAmount = (float) ($data['charges_amount'] ?? 0);
        $paidAmount = (float) $data['paid_amount'];
        $cashAccountCode = match ($data['payment_method']) {
            'cash' => 'CASH_ON_HAND',
            'debit_card', 'credit_card' => 'CARD_CLEARING',
            default => 'BANK_CLEARING',
        };

        $lines = [
            [
                'account_code' => 'SUPPLIER_ADVANCES',
                'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                'line_description' => 'Supplier payment recorded as unapplied settlement asset',
                'debit_amount' => $paidAmount,
                'credit_amount' => 0,
            ],
        ];

        if ($chargesAmount > 0) {
            $lines[] = [
                'account_code' => 'CARD_CHARGES',
                'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                'line_description' => 'Supplier payment charges recognized',
                'debit_amount' => $chargesAmount,
                'credit_amount' => 0,
            ];
        }

        $lines[] = [
            'account_code' => $cashAccountCode,
            'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
            'line_description' => 'Cash or bank supplier outflow',
            'debit_amount' => 0,
            'credit_amount' => $paidAmount + $chargesAmount,
        ];

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'supplier_payment_recorded',
            'source_reference' => $data['source_reference'] ?? $data['payment_no'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Supplier payment recorded at booking level',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function postSupplierPaymentAllocation(array $data): int
    {
        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'supplier_payment_allocated',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Supplier payment allocated to supplier obligation',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'AP_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                'line_description' => 'Accounts payable reduced by supplier payment allocation',
                'debit_amount' => $data['allocated_amount'],
                'credit_amount' => 0,
            ],
            [
                'account_code' => 'SUPPLIER_ADVANCES',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                'line_description' => 'Unapplied supplier payment consumed',
                'debit_amount' => 0,
                'credit_amount' => $data['allocated_amount'],
            ],
        ]);
    }

    public function postSupplierPaymentVoidReversal(array $data): ?int
    {
        $rows = [];
        if ($this->columnExists('journal_entry_lines', 'supplier_payment_id')) {
            $statement = $this->db->prepare(
                'SELECT
                    je.id AS journal_entry_id,
                    je.branch_id,
                    je.booking_reference,
                    je.currency,
                    coa.code AS account_code,
                    jel.service_line_reference,
                    jel.supplier_obligation_id,
                    jel.supplier_payment_id,
                    jel.line_description,
                    jel.debit_amount,
                    jel.credit_amount
                 FROM journal_entries je
                 INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                 WHERE jel.supplier_payment_id = :supplier_payment_id
                   AND je.source_type IN ("supplier_payment_recorded", "supplier_payment_allocated")
                 ORDER BY je.id ASC, jel.id ASC'
            );
            $statement->execute([
                'supplier_payment_id' => $data['supplier_payment_id'],
            ]);
            $rows = $statement->fetchAll() ?: [];
        }

        if ($rows === [] && trim((string) ($data['payment_no'] ?? '')) !== '') {
            $statement = $this->db->prepare(
                'SELECT
                    je.id AS journal_entry_id,
                    je.branch_id,
                    je.booking_reference,
                    je.currency,
                    coa.code AS account_code,
                    jel.service_line_reference,
                    jel.supplier_obligation_id,
                    NULL AS supplier_payment_id,
                    jel.line_description,
                    jel.debit_amount,
                    jel.credit_amount
                 FROM journal_entries je
                 INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                 WHERE je.source_type IN ("supplier_payment_recorded", "supplier_payment_allocated")
                   AND (
                        je.source_reference = :payment_no_exact
                        OR je.source_reference LIKE :payment_no_alloc
                   )
                 ORDER BY je.id ASC, jel.id ASC'
            );
            $paymentNo = (string) $data['payment_no'];
            $statement->execute([
                'payment_no_exact' => $paymentNo,
                'payment_no_alloc' => $paymentNo . '-ALLOC-%',
            ]);
            $rows = $statement->fetchAll() ?: [];
        }

        if ($rows === []) {
            return null;
        }

        $lines = [];
        foreach ($rows as $row) {
            $debitAmount = round((float) ($row['debit_amount'] ?? 0), 2);
            $creditAmount = round((float) ($row['credit_amount'] ?? 0), 2);
            if ($debitAmount <= 0 && $creditAmount <= 0) {
                continue;
            }

            $lines[] = [
                'account_code' => (string) ($row['account_code'] ?? ''),
                'service_line_reference' => ($row['service_line_reference'] ?? null) !== null && (string) ($row['service_line_reference'] ?? '') !== ''
                    ? (string) $row['service_line_reference']
                    : null,
                'supplier_obligation_id' => (int) ($row['supplier_obligation_id'] ?? 0) > 0
                    ? (int) $row['supplier_obligation_id']
                    : null,
                'supplier_payment_id' => (int) ($data['supplier_payment_id'] ?? 0) > 0
                    ? (int) $data['supplier_payment_id']
                    : null,
                'line_description' => 'Supplier payment void reversal: ' . (string) ($row['line_description'] ?? ''),
                'debit_amount' => $creditAmount,
                'credit_amount' => $debitAmount,
            ];
        }

        if ($lines === []) {
            return null;
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'supplier_payment_void_reversed',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Supplier payment void reversal',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function postCustomerReceiptRecorded(array $data): int
    {
        $chargesAmount = (float) ($data['charges_amount'] ?? 0);
        $receivedAmount = (float) $data['received_amount'];
        $cashAccountCode = match ($data['payment_method']) {
            'cash' => 'CASH_ON_HAND',
            'debit_card', 'credit_card' => 'CARD_CLEARING',
            default => 'BANK_CLEARING',
        };

        $lines = [
            [
                'account_code' => $cashAccountCode,
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Customer receipt captured',
                'debit_amount' => max(0, $receivedAmount - $chargesAmount),
                'credit_amount' => 0,
            ],
        ];

        if ($chargesAmount > 0) {
            $lines[] = [
                'account_code' => 'CARD_CHARGES',
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Payment charges recognized',
                'debit_amount' => $chargesAmount,
                'credit_amount' => 0,
            ];
        }

        $lines[] = [
            'account_code' => 'CUSTOMER_CREDIT',
            'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
            'line_description' => 'Booking-level unallocated customer credit',
            'debit_amount' => 0,
            'credit_amount' => $receivedAmount,
        ];

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'customer_receipt_recorded',
            'source_reference' => $data['source_reference'] ?? $data['receipt_no'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Customer receipt recorded at booking level',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function postCustomerReceiptAllocation(array $data): int
    {
        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'customer_receipt_allocated',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Customer receipt allocated to receivable item',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'CUSTOMER_CREDIT',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'customer_receivable_item_id' => $data['customer_receivable_item_id'] ?? null,
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Customer credit consumed by allocation',
                'debit_amount' => $data['allocated_amount'],
                'credit_amount' => 0,
            ],
            [
                'account_code' => 'AR_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'customer_receivable_item_id' => $data['customer_receivable_item_id'] ?? null,
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Accounts receivable reduced',
                'debit_amount' => 0,
                'credit_amount' => $data['allocated_amount'],
            ],
        ]);
    }

    public function postCustomerReceiptAllocationRelease(array $data): int
    {
        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'customer_receipt_allocation_released',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Customer allocation released back to booking credit',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'AR_CONTROL',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'customer_receivable_item_id' => $data['customer_receivable_item_id'] ?? null,
                'line_description' => 'Accounts receivable restored from cancellation settlement release',
                'debit_amount' => $data['released_amount'],
                'credit_amount' => 0,
            ],
            [
                'account_code' => 'CUSTOMER_CREDIT',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'customer_receivable_item_id' => $data['customer_receivable_item_id'] ?? null,
                'line_description' => 'Customer credit recreated from released allocation',
                'debit_amount' => 0,
                'credit_amount' => $data['released_amount'],
            ],
        ]);
    }

    public function postCustomerReceiptVoidReversal(array $data): ?int
    {
        $statement = $this->db->prepare(
            'SELECT
                je.id AS journal_entry_id,
                je.branch_id,
                je.booking_reference,
                je.currency,
                coa.code AS account_code,
                jel.service_line_reference,
                jel.customer_receivable_item_id,
                jel.customer_receipt_id,
                jel.line_description,
                jel.debit_amount,
                jel.credit_amount
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE jel.customer_receipt_id = :customer_receipt_id
               AND je.source_type IN ("customer_receipt_recorded", "customer_receipt_allocated")
             ORDER BY je.id ASC, jel.id ASC'
        );
        $statement->execute([
            'customer_receipt_id' => $data['customer_receipt_id'],
        ]);
        $rows = $statement->fetchAll() ?: [];

        if ($rows === []) {
            return null;
        }

        $lines = [];
        foreach ($rows as $row) {
            $debitAmount = round((float) ($row['debit_amount'] ?? 0), 2);
            $creditAmount = round((float) ($row['credit_amount'] ?? 0), 2);
            if ($debitAmount <= 0 && $creditAmount <= 0) {
                continue;
            }

            $lines[] = [
                'account_code' => (string) ($row['account_code'] ?? ''),
                'service_line_reference' => ($row['service_line_reference'] ?? null) !== null && (string) ($row['service_line_reference'] ?? '') !== ''
                    ? (string) $row['service_line_reference']
                    : null,
                'customer_receivable_item_id' => (int) ($row['customer_receivable_item_id'] ?? 0) > 0
                    ? (int) $row['customer_receivable_item_id']
                    : null,
                'customer_receipt_id' => (int) ($row['customer_receipt_id'] ?? 0) > 0
                    ? (int) $row['customer_receipt_id']
                    : null,
                'line_description' => 'Receipt void reversal: ' . (string) ($row['line_description'] ?? ''),
                'debit_amount' => $creditAmount,
                'credit_amount' => $debitAmount,
            ];
        }

        if ($lines === []) {
            return null;
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'customer_receipt_void_reversed',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Customer receipt void reversal',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function postServiceRefund(array $data): int
    {
        $customerRefundAmount = round((float) ($data['customer_refund_amount'] ?? 0), 2);
        $supplierRefundAmount = round((float) ($data['supplier_refund_amount'] ?? 0), 2);
        $cashAccountCode = match ($data['payment_method'] ?? 'bank_transfer') {
            'cash' => 'CASH_ON_HAND',
            'debit_card', 'credit_card' => 'CARD_CLEARING',
            default => 'BANK_CLEARING',
        };

        $lines = [];
        if ($customerRefundAmount > 0) {
            $lines[] = [
                'account_code' => 'CUSTOMER_CREDIT',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'line_description' => 'Customer credit refunded',
                'debit_amount' => $customerRefundAmount,
                'credit_amount' => 0,
            ];
            $lines[] = [
                'account_code' => $cashAccountCode,
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'line_description' => 'Cash or bank customer refund paid',
                'debit_amount' => 0,
                'credit_amount' => $customerRefundAmount,
            ];
        }

        if ($supplierRefundAmount > 0) {
            $lines[] = [
                'account_code' => $cashAccountCode,
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'line_description' => 'Cash or bank supplier refund received',
                'debit_amount' => $supplierRefundAmount,
                'credit_amount' => 0,
            ];
            $lines[] = [
                'account_code' => 'SUPPLIER_ADVANCES',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'line_description' => 'Supplier advance or credit reduced by refund',
                'debit_amount' => 0,
                'credit_amount' => $supplierRefundAmount,
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('Refund journal requires a customer or supplier refund amount.');
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'service_refund_posted',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Service refund posted',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function accountNetBalanceForBooking(string $bookingReference, string $currency, string $accountCode): float
    {
        $statement = $this->db->prepare(
            'SELECT
                coa.normal_balance,
                COALESCE(SUM(jel.debit_amount), 0) AS debit_total,
                COALESCE(SUM(jel.credit_amount), 0) AS credit_total
             FROM chart_of_accounts coa
             LEFT JOIN journal_entry_lines jel ON jel.account_id = coa.id
             LEFT JOIN journal_entries je ON je.id = jel.journal_entry_id
             WHERE coa.code = :account_code
               AND (je.id IS NULL OR (je.booking_reference = :booking_reference AND je.currency = :currency))
             GROUP BY coa.id, coa.normal_balance
             LIMIT 1'
        );
        $statement->execute([
            'booking_reference' => $bookingReference,
            'currency' => $currency,
            'account_code' => $accountCode,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            return 0.0;
        }

        $debitTotal = round((float) ($row['debit_total'] ?? 0), 2);
        $creditTotal = round((float) ($row['credit_total'] ?? 0), 2);

        return (string) ($row['normal_balance'] ?? 'debit') === 'credit'
            ? round($creditTotal - $debitTotal, 2)
            : round($debitTotal - $creditTotal, 2);
    }

    public function ledgerSnapshotByBookingReference(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                coa.code,
                COALESCE(SUM(jel.debit_amount), 0) AS total_debit,
                COALESCE(SUM(jel.credit_amount), 0) AS total_credit,
                je.currency
             FROM journal_entry_lines jel
             INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.booking_reference = :booking_reference
             GROUP BY coa.code, je.currency
             ORDER BY je.currency ASC, coa.code ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function journalPreviewByBookingReference(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                je.id,
                je.branch_id,
                br.name AS branch_name,
                je.booking_reference,
                je.source_type,
                je.source_reference,
                je.entry_date,
                je.currency,
                je.narration,
                coa.code AS account_code,
                coa.name AS account_name,
                jel.line_description,
                jel.service_line_reference,
                jel.debit_amount,
                jel.credit_amount
             FROM journal_entries je
             INNER JOIN branches br ON br.id = je.branch_id
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.booking_reference = :booking_reference
             ORDER BY je.entry_date ASC, je.id ASC, jel.id ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);
        $rows = $statement->fetchAll() ?: [];

        if ($rows === []) {
            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            $entryId = (int) ($row['id'] ?? 0);
            if (! isset($entries[$entryId])) {
                $entries[$entryId] = [
                    'branchName' => (string) ($row['branch_name'] ?? ''),
                    'bookingReference' => (string) ($row['booking_reference'] ?? ''),
                    'sourceType' => (string) ($row['source_type'] ?? ''),
                    'sourceReference' => (string) ($row['source_reference'] ?? ''),
                    'entryDate' => (string) ($row['entry_date'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'narration' => (string) ($row['narration'] ?? ''),
                    'debitAccounts' => [],
                    'creditAccounts' => [],
                    'debitTotal' => 0.0,
                    'creditTotal' => 0.0,
                ];
            }

            $accountLabel = (string) (($row['account_name'] ?? '') !== '' ? $row['account_name'] : ($row['account_code'] ?? 'Account'));
            $debitAmount = (float) ($row['debit_amount'] ?? 0);
            $creditAmount = (float) ($row['credit_amount'] ?? 0);

            if ($debitAmount > 0) {
                $entries[$entryId]['debitAccounts'][] = $accountLabel;
                $entries[$entryId]['debitTotal'] += $debitAmount;
            }

            if ($creditAmount > 0) {
                $entries[$entryId]['creditAccounts'][] = $accountLabel;
                $entries[$entryId]['creditTotal'] += $creditAmount;
            }
        }

        return array_values(array_map(function (array $entry): array {
            return [
                'event' => $this->journalEventLabel($entry['sourceType']),
                'reference' => $entry['sourceReference'] !== '' ? $entry['sourceReference'] : $entry['bookingReference'],
                'currency' => $entry['currency'],
                'debit' => $this->accountLabelList($entry['debitAccounts']),
                'credit' => $this->accountLabelList($entry['creditAccounts']),
                'amount' => round(max((float) $entry['debitTotal'], (float) $entry['creditTotal']), 2),
                'branchName' => $entry['branchName'],
                'entryDate' => $entry['entryDate'],
                'narration' => $entry['narration'],
            ];
        }, $entries));
    }

    private function journalEventLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'service_receivable_created' => 'Service Receivable Created',
            'service_receivable_adjusted' => 'Service Receivable Adjusted',
            'supplier_payable_created' => 'Supplier Payable Created',
            'supplier_payable_adjusted' => 'Supplier Payable Adjusted',
            'customer_receipt_recorded' => 'Customer Receipt Recorded',
            'customer_receipt_allocated' => 'Customer Receipt Allocated',
            'customer_receipt_allocation_released' => 'Customer Allocation Released',
            'customer_receipt_void_reversed' => 'Customer Receipt Void Reversed',
            'supplier_advance_recorded' => 'Supplier Advance Recorded',
            'supplier_advance_applied' => 'Supplier Advance Applied',
            'supplier_advance_adjusted' => 'Supplier Advance Adjusted',
            'supplier_settlement_released' => 'Supplier Settlement Released',
            'supplier_payment_recorded' => 'Supplier Payment Recorded',
            'supplier_payment_allocated' => 'Supplier Payment Allocated',
            default => ucwords(str_replace('_', ' ', $sourceType)),
        };
    }

    private function accountLabelList(array $labels): string
    {
        $labels = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $labels
        ), static fn (string $value): bool => $value !== '')));

        return $labels !== [] ? implode(' + ', $labels) : 'N/A';
    }

    private function accountIdByCode(string $code): int
    {
        if (isset($this->accountIdCache[$code])) {
            return $this->accountIdCache[$code];
        }

        $statement = $this->db->prepare('SELECT id FROM chart_of_accounts WHERE code = :code LIMIT 1');
        $statement->execute(['code' => $code]);
        $accountId = (int) ($statement->fetchColumn() ?: 0);

        if ($accountId <= 0) {
            throw new RuntimeException('Unknown account code: ' . $code);
        }

        $this->accountIdCache[$code] = $accountId;

        return $accountId;
    }
}
