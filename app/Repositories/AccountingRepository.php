<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;
use RuntimeException;

final class AccountingRepository extends BaseRepository
{
    private array $accountIdCache = [];

    public function serviceCostBalances(string $bookingReference, string $lineReference): array
    {
        $statement = $this->db->prepare(
            'SELECT je.currency, ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS amount
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.booking_reference = :booking_reference
               AND jel.service_line_reference = :line_reference
               AND coa.code = "SERVICE_COST"
             GROUP BY je.currency'
        );
        $statement->execute([
            'booking_reference' => $bookingReference,
            'line_reference' => $lineReference,
        ]);

        $balances = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $balances[strtoupper((string) $row['currency'])] = round((float) $row['amount'], 2);
        }

        return $balances;
    }

    public function postRefundRecoveryOutstanding(array $data): int
    {
        $customer = $data['scope'] === 'customer';
        $amount = round((float) $data['amount'], 2);
        $reference = ['service_line_reference' => $data['service_line_reference'],
            'customer_receivable_item_id' => $data['customer_receivable_item_id'] ?? null,
            'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
            'line_description' => 'Actual refund retained when restoring original invoice'];
        return $this->postJournalEntry($data, [
            array_merge($reference, ['account_code' => $customer ? 'AR_CONTROL' : 'AP_CONTROL',
                'debit_amount' => $customer ? $amount : 0, 'credit_amount' => $customer ? 0 : $amount]),
            array_merge($reference, ['account_code' => $customer ? 'CUSTOMER_CREDIT' : 'SUPPLIER_ADVANCES',
                'debit_amount' => $customer ? 0 : $amount, 'credit_amount' => $customer ? $amount : 0]),
        ]);
    }

    public function postJournalEntry(array $header, array $lines): int
    {
        return $this->transaction(function () use ($header, $lines): int {
            $effectiveLines = array_values(array_filter($lines, static function (array $line): bool {
                $debitAmount = round((float) ($line['debit_amount'] ?? 0), 2);
                $creditAmount = round((float) ($line['credit_amount'] ?? 0), 2);

                return $debitAmount > 0 || $creditAmount > 0;
            }));

            if ($effectiveLines === []) {
                throw new RuntimeException('Journal entry has no effective lines to post.');
            }

            $debitTotal = 0.0;
            $creditTotal = 0.0;

            foreach ($effectiveLines as $line) {
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

            foreach ($effectiveLines as $line) {
                $linePayload = [
                    'journal_entry_id' => $journalEntryId,
                    'account_id' => isset($line['account_id']) && (int) ($line['account_id'] ?? 0) > 0
                        ? (int) $line['account_id']
                        : $this->accountIdByCode($line['account_code']),
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

    public function reverseJournalEntry(int $journalEntryId, array $options = []): ?int
    {
        if ($journalEntryId <= 0) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT
                je.id AS journal_entry_id,
                je.branch_id,
                je.booking_reference,
                je.currency,
                coa.code AS account_code,
                jel.service_line_reference,
                jel.supplier_obligation_id,
                ' . ($this->columnExists('journal_entry_lines', 'supplier_payment_id') ? 'jel.supplier_payment_id' : 'NULL AS supplier_payment_id') . ',
                jel.customer_receivable_item_id,
                jel.customer_receipt_id,
                jel.line_description,
                jel.debit_amount,
                jel.credit_amount
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id = :journal_entry_id
             ORDER BY jel.id ASC'
        );
        $statement->execute(['journal_entry_id' => $journalEntryId]);
        $rows = $statement->fetchAll() ?: [];

        if ($rows === []) {
            return null;
        }
        $firstRow = $rows[0];
        $lines = [];
        $lineDescriptionOverrides = is_array($options['line_description_overrides'] ?? null)
            ? $options['line_description_overrides']
            : [];
        foreach ($rows as $row) {
            $debitAmount = round((float) ($row['debit_amount'] ?? 0), 2);
            $creditAmount = round((float) ($row['credit_amount'] ?? 0), 2);
            if ($debitAmount <= 0 && $creditAmount <= 0) {
                continue;
            }

            $originalLineDescription = (string) ($row['line_description'] ?? '');
            $lineDescription = array_key_exists($originalLineDescription, $lineDescriptionOverrides)
                ? (string) $lineDescriptionOverrides[$originalLineDescription]
                : (string) ($options['line_description_prefix'] ?? 'Reversal: ') . $originalLineDescription;

            $lines[] = [
                'account_code' => (string) ($row['account_code'] ?? ''),
                'service_line_reference' => ($row['service_line_reference'] ?? null) !== null && (string) ($row['service_line_reference'] ?? '') !== ''
                    ? (string) $row['service_line_reference']
                    : null,
                'supplier_obligation_id' => isset($row['supplier_obligation_id']) && (int) ($row['supplier_obligation_id'] ?? 0) > 0
                    ? (int) $row['supplier_obligation_id']
                    : null,
                'supplier_payment_id' => isset($row['supplier_payment_id']) && (int) ($row['supplier_payment_id'] ?? 0) > 0
                    ? (int) $row['supplier_payment_id']
                    : null,
                'customer_receivable_item_id' => isset($row['customer_receivable_item_id']) && (int) ($row['customer_receivable_item_id'] ?? 0) > 0
                    ? (int) $row['customer_receivable_item_id']
                    : null,
                'customer_receipt_id' => isset($row['customer_receipt_id']) && (int) ($row['customer_receipt_id'] ?? 0) > 0
                    ? (int) $row['customer_receipt_id']
                    : null,
                'line_description' => $lineDescription,
                'debit_amount' => $creditAmount,
                'credit_amount' => $debitAmount,
            ];
        }

        if ($lines === []) {
            return null;
        }

        return $this->postJournalEntry([
            'branch_id' => (int) ($options['branch_id'] ?? $firstRow['branch_id'] ?? 0),
            'booking_reference' => $options['booking_reference'] ?? ($firstRow['booking_reference'] ?? null),
            'source_type' => (string) ($options['source_type'] ?? 'journal_reversal'),
            'source_reference' => $options['source_reference'] ?? ('REV-JE-' . $journalEntryId),
            'entry_date' => $options['entry_date'] ?? date('Y-m-d'),
            'currency' => $options['currency'] ?? ($firstRow['currency'] ?? 'PKR'),
            'narration' => $options['narration'] ?? ('Journal reversal for JE-' . $journalEntryId),
            'actor_user_id' => $options['actor_user_id'] ?? null,
        ], $lines);
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
        $sourceAccount = $this->paymentMethodAssetAccount($data);

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
                'account_code' => $sourceAccount['account_code'],
                'account_id' => $sourceAccount['account_id'],
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

    public function postCrossCurrencySupplierAdvanceConversion(array $data): array
    {
        if (! $this->db->inTransaction()) {
            throw new RuntimeException('Supplier advance FX conversion requires an enclosing transaction.');
        }
        $sourceAmount = round((float) ($data['source_amount'] ?? 0), 2);
        $destinationAmount = round((float) ($data['destination_amount'] ?? 0), 2);
        $sourceCurrency = strtoupper((string) ($data['source_currency'] ?? ''));
        $destinationCurrency = strtoupper((string) ($data['destination_currency'] ?? ''));
        if ($sourceAmount <= 0 || $destinationAmount <= 0 || $sourceCurrency === $destinationCurrency
            || $sourceCurrency === '' || $destinationCurrency === '') {
            throw new RuntimeException('Supplier advance FX conversion requires two positive amounts in different currencies.');
        }

        $clearing = $this->ensureAccountByCode(
            'SUPPLIER_PAYMENT_FX_CLEARING', 'Supplier Payment FX Clearing', 'asset', 'debit'
        );
        $reference = (string) ($data['source_reference'] ?? 'SUPPLIER-ADVANCE-FX');
        $header = [
            'booking_reference' => null,
            'source_type' => 'supplier_advance_fx_conversion',
            'entry_date' => $data['entry_date'] ?? date('Y-m-d'),
            'actor_user_id' => $data['actor_user_id'] ?? null,
            'narration' => 'Supplier advance converted within supplier pool; no cash movement',
        ];
        $sourceJournalId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => (int) $data['source_branch_id'],
            'currency' => $sourceCurrency,
            'source_reference' => $reference . '-SOURCE',
        ]), [
            ['account_id' => $clearing['account_id'], 'line_description' => 'Supplier advance FX source clearing',
                'debit_amount' => $sourceAmount, 'credit_amount' => 0],
            ['account_code' => 'SUPPLIER_ADVANCES', 'line_description' => 'Supplier advance consumed in source currency',
                'debit_amount' => 0, 'credit_amount' => $sourceAmount],
        ]);
        $destinationJournalId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => (int) $data['destination_branch_id'],
            'currency' => $destinationCurrency,
            'source_reference' => $reference . '-DESTINATION',
        ]), [
            ['account_code' => 'SUPPLIER_ADVANCES', 'line_description' => 'Supplier advance created in payable currency',
                'debit_amount' => $destinationAmount, 'credit_amount' => 0],
            ['account_id' => $clearing['account_id'], 'line_description' => 'Supplier advance FX destination clearing',
                'debit_amount' => 0, 'credit_amount' => $destinationAmount],
        ]);

        return [$sourceJournalId, $destinationJournalId];
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
        $sourceAccount = $this->paymentMethodAssetAccount($data);
        $journalCurrency = strtoupper(trim((string) ($data['currency'] ?? 'PKR')));
        $treasuryCurrency = strtoupper(trim((string) ($data['treasury_currency'] ?? $journalCurrency)));
        $treasuryAmount = round((float) ($data['treasury_amount'] ?? ($paidAmount + $chargesAmount)), 2);
        $treasuryAccountId = (int) ($data['treasury_account_id'] ?? 0);
        $treasuryReference = $treasuryAccountId > 0
            ? $this->treasuryAccountLedgerReference($treasuryAccountId)
            : null;
        $separateTreasuryFunding = $treasuryReference !== null
            && ($treasuryCurrency !== $journalCurrency
                || (int) ($treasuryReference['branch_id'] ?? 0) !== (int) ($data['branch_id'] ?? 0));
        $bridgeAccount = $separateTreasuryFunding
            ? $this->ensureAccountByCode(
                'SUPPLIER_PAYMENT_FX_CLEARING',
                'Supplier Payment FX Clearing',
                'asset',
                'debit'
            )
            : null;

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
            'account_code' => $separateTreasuryFunding ? (string) ($bridgeAccount['account_code'] ?? '') : $sourceAccount['account_code'],
            'account_id' => $separateTreasuryFunding ? (int) ($bridgeAccount['account_id'] ?? 0) : $sourceAccount['account_id'],
            'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
            'line_description' => $separateTreasuryFunding
                ? 'Supplier payment funding bridge credited'
                : 'Cash or bank supplier outflow',
            'debit_amount' => 0,
            'credit_amount' => $paidAmount + $chargesAmount,
        ];

        $journalId = $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'supplier_payment_recorded',
            'source_reference' => $data['source_reference'] ?? $data['payment_no'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Supplier payment recorded at booking level',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);

        if ($separateTreasuryFunding && $bridgeAccount !== null && $treasuryReference !== null && $treasuryAmount > 0) {
            $fundingReference = (string) ($data['source_reference'] ?? $data['payment_no'] ?? '');
            if ($fundingReference === '') {
                $fundingReference = 'SUPPLIER-PAYMENT';
            }

            $this->postJournalEntry([
                'branch_id' => (int) ($treasuryReference['branch_id'] ?? $data['branch_id']),
                'booking_reference' => $data['booking_reference'],
                'source_type' => 'supplier_payment_treasury_funded',
                'source_reference' => $fundingReference . '-FX',
                'entry_date' => $data['entry_date'],
                'currency' => $treasuryCurrency,
                'narration' => sprintf(
                    'Supplier payment treasury funding: %s source used for %s payable',
                    $treasuryCurrency,
                    $journalCurrency
                ),
                'actor_user_id' => $data['actor_user_id'] ?? null,
            ], [
                [
                    'account_code' => (string) $bridgeAccount['account_code'],
                    'account_id' => (int) $bridgeAccount['account_id'],
                    'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                    'line_description' => 'Supplier payment funding bridge debited',
                    'debit_amount' => $treasuryAmount,
                    'credit_amount' => 0,
                ],
                [
                    'account_code' => (string) $treasuryReference['account_code'],
                    'account_id' => (int) $treasuryReference['account_id'],
                    'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                    'line_description' => 'Cash or bank supplier outflow',
                    'debit_amount' => 0,
                    'credit_amount' => $treasuryAmount,
                ],
            ]);
        }

        return $journalId;
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
        $sourceTypes = '"supplier_payment_recorded", "supplier_payment_allocated", "supplier_payment_treasury_funded"';
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
                   AND je.source_type IN (' . $sourceTypes . ')
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
                 WHERE je.source_type IN (' . $sourceTypes . ')
                   AND (
                        je.source_reference = :payment_no_exact
                        OR je.source_reference LIKE :payment_no_alloc
                        OR je.source_reference LIKE :payment_no_fx
                   )
                 ORDER BY je.id ASC, jel.id ASC'
            );
            $paymentNo = (string) $data['payment_no'];
            $statement->execute([
                'payment_no_exact' => $paymentNo,
                'payment_no_alloc' => $paymentNo . '-ALLOC-%',
                'payment_no_fx' => $paymentNo . '-FX%',
            ]);
            $rows = $statement->fetchAll() ?: [];
        }

        if ($rows === []) {
            return null;
        }

        $groups = [];
        foreach ($rows as $row) {
            $currencyKey = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            $branchId = (int) ($row['branch_id'] ?? $data['branch_id'] ?? 0);
            $groupKey = $branchId . '|' . $currencyKey;
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'branch_id' => $branchId,
                    'currency' => $currencyKey,
                    'rows' => [],
                ];
            }

            $groups[$groupKey]['rows'][] = $row;
        }

        if ($groups === []) {
            return null;
        }

        $createdJournalId = null;
        $groupIndex = 0;
        $groupCount = count($groups);

        foreach ($groups as $group) {
            $groupIndex++;
            $branchId = (int) ($group['branch_id'] ?? $data['branch_id'] ?? 0);
            $currencyKey = (string) ($group['currency'] ?? $data['currency'] ?? 'PKR');
            $currencyRows = (array) ($group['rows'] ?? []);
            $lines = [];
            foreach ($currencyRows as $row) {
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
                continue;
            }

            $bookingReferences = array_values(array_unique(array_filter(array_map(
                static fn (array $row): string => trim((string) ($row['booking_reference'] ?? '')),
                $currencyRows
            ))));
            $hasUnscopedRows = count(array_filter(
                $currencyRows,
                static fn (array $row): bool => trim((string) ($row['booking_reference'] ?? '')) === ''
            )) > 0;
            $bookingReference = ! $hasUnscopedRows && count($bookingReferences) === 1
                ? $bookingReferences[0]
                : ($data['booking_reference'] ?? null);
            $journalId = $this->postJournalEntry([
                'branch_id' => $branchId,
                'booking_reference' => $bookingReference,
                'source_type' => 'supplier_payment_void_reversed',
                'source_reference' => $groupCount > 1
                    ? (((string) ($data['source_reference'] ?? 'SUPPLIER-PAYMENT-VOID')) . '-B' . $branchId . '-' . $currencyKey)
                    : ($data['source_reference'] ?? null),
                'entry_date' => $data['entry_date'],
                'currency' => $currencyKey,
                'narration' => $data['narration'] ?? 'Supplier payment void reversal',
                'actor_user_id' => $data['actor_user_id'] ?? null,
            ], $lines);

            if ($createdJournalId === null) {
                $createdJournalId = $journalId;
            }
        }

        return $createdJournalId;
    }

    public function postCustomerReceiptRecorded(array $data): int
    {
        $chargesAmount = (float) ($data['charges_amount'] ?? 0);
        $receivedAmount = (float) $data['received_amount'];
        $cashAccount = $this->customerReceiptAssetAccount($data);

        $lines = [
            [
                'account_code' => $cashAccount['account_code'],
                'account_id' => $cashAccount['account_id'],
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

    public function postBookingBranchCreditReclassification(array $data): array
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0 || (int) $data['from_branch_id'] === (int) $data['to_branch_id']) {
            throw new RuntimeException('A cross-branch receipt credit amount is required.');
        }
        $receivable = $this->ensureAccountByCode('INTERBRANCH_TRANSFER_RECEIVABLE', 'Interbranch Transfer Receivable', 'asset', 'debit');
        $payable = $this->ensureAccountByCode('INTERBRANCH_TRANSFER_PAYABLE', 'Interbranch Transfer Payable', 'liability', 'credit');
        $header = [
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'booking_branch_credit_reclassified',
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'actor_user_id' => $data['actor_user_id'],
            'narration' => 'Reclassify customer credit to corrected booking branch; bank account unchanged',
        ];
        $receiptId = (int) $data['customer_receipt_id'];
        $sourceId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => $data['from_branch_id'],
            'source_reference' => $data['source_reference'] . '-SOURCE',
        ]), [
            ['account_code' => 'CUSTOMER_CREDIT', 'customer_receipt_id' => $receiptId, 'line_description' => 'Customer credit moved from booking branch', 'debit_amount' => $amount, 'credit_amount' => 0],
            ['account_id' => $payable['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Interbranch credit reclassification payable', 'debit_amount' => 0, 'credit_amount' => $amount],
        ]);
        $destinationId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => $data['to_branch_id'],
            'source_reference' => $data['source_reference'] . '-DESTINATION',
        ]), [
            ['account_id' => $receivable['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Interbranch credit reclassification receivable', 'debit_amount' => $amount, 'credit_amount' => 0],
            ['account_code' => 'CUSTOMER_CREDIT', 'customer_receipt_id' => $receiptId, 'line_description' => 'Customer credit moved to booking branch', 'debit_amount' => 0, 'credit_amount' => $amount],
        ]);

        return [$sourceId, $destinationId];
    }

    public function postCustomerReceiptAccountCorrection(array $data): array
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new RuntimeException('Only a receipt with positive net cash can change accounts.');
        }
        $from = $this->treasuryAccountLedgerReference((int) $data['from_treasury_account_id']);
        $to = $this->treasuryAccountLedgerReference((int) $data['to_treasury_account_id']);
        if ($from === null || $to === null || $from['account_id'] === $to['account_id']) {
            throw new RuntimeException('Receipt account correction requires two distinct active cash or bank accounts.');
        }
        $header = [
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'customer_receipt_account_corrected',
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'actor_user_id' => $data['actor_user_id'],
            'narration' => 'Correct receipt account selection: ' . $data['reason'],
        ];
        $receiptId = (int) $data['customer_receipt_id'];
        if ($from['branch_id'] === $to['branch_id']) {
            return [$this->postJournalEntry(array_merge($header, [
                'branch_id' => $from['branch_id'],
                'source_reference' => $data['source_reference'],
            ]), [
                ['account_id' => $to['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Correct receipt destination account', 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_id' => $from['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Reverse incorrectly selected receipt account', 'debit_amount' => 0, 'credit_amount' => $amount],
            ])];
        }
        $receivable = $this->ensureAccountByCode('INTERBRANCH_TRANSFER_RECEIVABLE', 'Interbranch Transfer Receivable', 'asset', 'debit');
        $payable = $this->ensureAccountByCode('INTERBRANCH_TRANSFER_PAYABLE', 'Interbranch Transfer Payable', 'liability', 'credit');
        $sourceId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => $from['branch_id'],
            'source_reference' => $data['source_reference'] . '-SOURCE',
        ]), [
            ['account_id' => $receivable['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Interbranch account correction receivable', 'debit_amount' => $amount, 'credit_amount' => 0],
            ['account_id' => $from['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Reverse incorrectly selected receipt account', 'debit_amount' => 0, 'credit_amount' => $amount],
        ]);
        $destinationId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => $to['branch_id'],
            'source_reference' => $data['source_reference'] . '-DESTINATION',
        ]), [
            ['account_id' => $to['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Correct receipt destination account', 'debit_amount' => $amount, 'credit_amount' => 0],
            ['account_id' => $payable['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Interbranch account correction payable', 'debit_amount' => 0, 'credit_amount' => $amount],
        ]);

        return [$sourceId, $destinationId];
    }

    public function receiptAssetBalance(int $receiptId, int $accountId, int $branchId, string $currency): float
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
             FROM journal_entry_lines jel
             INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
             INNER JOIN treasury_accounts ta ON ta.linked_account_id = jel.account_id
                 AND ta.branch_id = :branch_id
             WHERE jel.customer_receipt_id = :receipt_id
               AND jel.account_id = :account_id
               AND je.currency = :currency'
        );
        $statement->execute([
            'receipt_id' => $receiptId,
            'account_id' => $accountId,
            'branch_id' => $branchId,
            'currency' => $currency,
        ]);

        return round((float) $statement->fetchColumn(), 2);
    }

    public function postCrossBranchCustomerReceiptAmountCorrection(array $data): array
    {
        $delta = round((float) $data['corrected_amount'] - (float) $data['original_amount'], 2);
        if (abs($delta) < 0.005 || (int) $data['account_branch_id'] === (int) $data['booking_branch_id']) {
            throw new RuntimeException('A cross-branch receipt amount difference is required.');
        }
        $asset = $this->customerReceiptAssetAccount($data);
        $receivable = $this->ensureAccountByCode('INTERBRANCH_TRANSFER_RECEIVABLE', 'Interbranch Transfer Receivable', 'asset', 'debit');
        $payable = $this->ensureAccountByCode('INTERBRANCH_TRANSFER_PAYABLE', 'Interbranch Transfer Payable', 'liability', 'credit');
        $amount = abs($delta);
        $receiptId = (int) $data['customer_receipt_id'];
        $header = [
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'customer_receipt_amount_corrected',
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'actor_user_id' => $data['actor_user_id'],
            'narration' => $data['narration'],
        ];
        $sourceId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => $data['account_branch_id'],
            'source_reference' => $data['source_reference'] . '-SOURCE',
        ]), [
            ['account_id' => $asset['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Correct actual customer money received', 'debit_amount' => $delta > 0 ? $amount : 0, 'credit_amount' => $delta < 0 ? $amount : 0],
            ['account_id' => $payable['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Interbranch receipt correction payable', 'debit_amount' => $delta < 0 ? $amount : 0, 'credit_amount' => $delta > 0 ? $amount : 0],
        ]);
        $destinationId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => $data['booking_branch_id'],
            'source_reference' => $data['source_reference'] . '-DESTINATION',
        ]), [
            ['account_id' => $receivable['account_id'], 'customer_receipt_id' => $receiptId, 'line_description' => 'Interbranch receipt correction receivable', 'debit_amount' => $delta > 0 ? $amount : 0, 'credit_amount' => $delta < 0 ? $amount : 0],
            ['account_code' => 'CUSTOMER_CREDIT', 'customer_receipt_id' => $receiptId, 'line_description' => 'Correct customer credit from receipt amount', 'debit_amount' => $delta < 0 ? $amount : 0, 'credit_amount' => $delta > 0 ? $amount : 0],
        ]);

        return [$sourceId, $destinationId];
    }

    public function postCustomerReceiptAmountCorrection(array $data): int
    {
        $delta = round((float) $data['corrected_amount'] - (float) $data['original_amount'], 2);
        if (abs($delta) < .005) {
            throw new \RuntimeException('Receipt amount correction requires a nonzero difference.');
        }
        $asset = $this->customerReceiptAssetAccount($data);
        $amount = abs($delta);
        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'customer_receipt_amount_corrected',
            'source_reference' => $data['source_reference'],
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'],
            'actor_user_id' => $data['actor_user_id'],
        ], [
            ['account_id' => $asset['account_id'], 'account_code' => $asset['account_code'],
                'customer_receipt_id' => $data['customer_receipt_id'],
                'line_description' => 'Correct actual customer money received',
                'debit_amount' => $delta > 0 ? $amount : 0,
                'credit_amount' => $delta < 0 ? $amount : 0],
            ['account_code' => 'CUSTOMER_CREDIT', 'customer_receipt_id' => $data['customer_receipt_id'],
                'line_description' => 'Correct customer credit from receipt amount',
                'debit_amount' => $delta < 0 ? $amount : 0,
                'credit_amount' => $delta > 0 ? $amount : 0],
        ]);
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

    public function postWrongCurrencyCustomerReceiptReversal(array $data): int
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Wrong-currency receipt reversal amount must be greater than zero.');
        }
        $cashAccount = $this->customerReceiptAssetAccount($data);

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'customer_receipt_wrong_currency_reversed',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Wrong-currency customer receipt reversed',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'CUSTOMER_CREDIT',
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Wrong-currency customer credit removed',
                'debit_amount' => $amount,
                'credit_amount' => 0,
            ],
            [
                'account_code' => $cashAccount['account_code'],
                'account_id' => $cashAccount['account_id'],
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Wrong-currency treasury receipt removed',
                'debit_amount' => 0,
                'credit_amount' => $amount,
            ],
        ]);
    }

    public function postCustomerCreditFxGain(array $data): int
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('FX gain amount must be greater than zero.');
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'] ?? null,
            'source_type' => 'customer_credit_fx_gain_recognized',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Customer credit recognized as foreign exchange gain',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'CUSTOMER_CREDIT',
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Customer-credit liability released',
                'debit_amount' => $amount,
                'credit_amount' => 0,
            ],
            [
                'account_code' => 'FX_GAIN',
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Foreign exchange profit recognized',
                'debit_amount' => 0,
                'credit_amount' => $amount,
            ],
        ]);
    }

    public function postCustomerDirectSupplierPayment(array $data): int
    {
        $supplierAllocatedAmount = round((float) ($data['supplier_allocated_amount'] ?? 0), 2);
        $supplierAdvanceAmount = round((float) ($data['supplier_advance_amount'] ?? 0), 2);
        $customerAllocations = is_array($data['customer_allocations'] ?? null)
            ? $data['customer_allocations']
            : [];
        $supplierAllocations = is_array($data['supplier_allocations'] ?? null)
            ? $data['supplier_allocations']
            : [];

        $lines = [];
        if ($supplierAllocations !== []) {
            foreach ($supplierAllocations as $allocation) {
                $allocatedAmount = round((float) ($allocation['allocated_amount'] ?? 0), 2);
                if ($allocatedAmount <= 0) {
                    continue;
                }

                $lines[] = [
                    'account_code' => 'AP_CONTROL',
                    'service_line_reference' => $allocation['service_line_reference'] ?? null,
                    'supplier_obligation_id' => $allocation['supplier_obligation_id'] ?? null,
                    'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                    'line_description' => 'Supplier payable reduced by customer-paid supplier settlement',
                    'debit_amount' => $allocatedAmount,
                    'credit_amount' => 0,
                ];
            }
        } elseif ($supplierAllocatedAmount > 0) {
            $lines[] = [
                'account_code' => 'AP_CONTROL',
                'service_line_reference' => $data['supplier_service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                'line_description' => 'Supplier payable reduced by customer-paid supplier settlement',
                'debit_amount' => $supplierAllocatedAmount,
                'credit_amount' => 0,
            ];
        }

        if ($supplierAdvanceAmount > 0) {
            $lines[] = [
                'account_code' => 'SUPPLIER_ADVANCES',
                'service_line_reference' => $data['supplier_service_line_reference'] ?? null,
                'supplier_obligation_id' => $data['supplier_obligation_id'] ?? null,
                'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
                'line_description' => 'Excess customer-paid supplier amount moved to supplier advance',
                'debit_amount' => $supplierAdvanceAmount,
                'credit_amount' => 0,
            ];
        }

        foreach ($customerAllocations as $allocation) {
            $allocatedAmount = round((float) ($allocation['allocated_amount'] ?? 0), 2);
            if ($allocatedAmount <= 0) {
                continue;
            }

            $lines[] = [
                'account_code' => 'AR_CONTROL',
                'service_line_reference' => $allocation['service_line_reference'] ?? null,
                'customer_receivable_item_id' => $allocation['customer_receivable_item_id'] ?? null,
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Customer receivable reduced by customer-paid supplier settlement',
                'debit_amount' => 0,
                'credit_amount' => $allocatedAmount,
            ];
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'customer_direct_supplier_payment',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Customer paid supplier; supplier payable settled first',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function postCustomerAdvanceRefund(array $data): int
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Customer advance refund amount must be greater than zero.');
        }

        $cashAccount = $this->customerReceiptAssetAccount($data);

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => null,
            'source_type' => 'customer_advance_refunded',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Customer advance refunded',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'CUSTOMER_CREDIT',
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Customer advance credit reduced',
                'debit_amount' => $amount,
                'credit_amount' => 0,
            ],
            [
                'account_code' => $cashAccount['account_code'],
                'account_id' => $cashAccount['account_id'],
                'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
                'line_description' => 'Cash or bank returned to customer',
                'debit_amount' => 0,
                'credit_amount' => $amount,
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

    public function postBusinessExpenseRecorded(array $data): int
    {
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Business expense amount must be greater than zero.');
        }

        $sourceAccountId = (int) ($data['source_account_id'] ?? 0);
        $sourceAccountCode = trim((string) ($data['source_account_code'] ?? ''));
        if ($sourceAccountId <= 0 && $sourceAccountCode === '') {
            throw new RuntimeException('Business expense source account could not be resolved.');
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'] ?? null,
            'source_type' => 'business_expense_recorded',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Business expense recorded',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_code' => 'OPERATING_EXPENSES',
                'line_description' => $data['expense_line_description'] ?? 'Operating expense recognized',
                'debit_amount' => $amount,
                'credit_amount' => 0,
            ],
            [
                'account_code' => $sourceAccountCode,
                'account_id' => $sourceAccountId > 0 ? $sourceAccountId : null,
                'line_description' => $data['source_line_description'] ?? 'Cash or bank source account reduced by expense',
                'debit_amount' => 0,
                'credit_amount' => $amount,
            ],
        ]);
    }

    public function postBusinessExpenseEditReversal(array $data): ?int
    {
        $originalJournalEntryId = (int) ($data['journal_entry_id'] ?? 0);
        if ($originalJournalEntryId <= 0) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT
                je.id AS journal_entry_id,
                je.branch_id,
                je.booking_reference,
                je.currency,
                coa.code AS account_code,
                jel.line_description,
                jel.debit_amount,
                jel.credit_amount
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id = :journal_entry_id
               AND je.source_type = "business_expense_recorded"
             ORDER BY jel.id ASC'
        );
        $statement->execute(['journal_entry_id' => $originalJournalEntryId]);
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
                'line_description' => 'Business expense correction reversal: ' . (string) ($row['line_description'] ?? ''),
                'debit_amount' => $creditAmount,
                'credit_amount' => $debitAmount,
            ];
        }

        if ($lines === []) {
            return null;
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'] ?? null,
            'source_type' => 'business_expense_corrected_reversal',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Business expense correction reversal',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
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
               AND je.source_type IN ("customer_receipt_recorded", "customer_receipt_allocated", "customer_receipt_amount_corrected")
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

    public function postCorrectedReceiptVoidReversals(array $data): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT je.id
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             WHERE jel.customer_receipt_id = :receipt_id
               AND je.source_type IN (
                   "customer_receipt_recorded", "customer_receipt_allocated",
                   "customer_receipt_amount_corrected", "customer_receipt_account_corrected",
                   "booking_branch_receipt_reversal", "booking_branch_credit_reclassified"
               )
             ORDER BY je.id ASC'
        );
        $statement->execute(['receipt_id' => (int) $data['customer_receipt_id']]);
        $journalIds = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        if ($journalIds === []) {
            throw new RuntimeException('Receipt has no linked financial journals to reverse.');
        }
        $reversals = [];
        foreach ($journalIds as $journalId) {
            $reversalId = $this->reverseJournalEntry($journalId, [
                'source_type' => 'customer_receipt_void_reversal',
                'source_reference' => (string) $data['source_reference'] . '-JE-' . $journalId,
                'entry_date' => $data['entry_date'],
                'actor_user_id' => $data['actor_user_id'],
                'narration' => 'Void receipt journal ' . $journalId . ' in its original branch',
            ]);
            if ($reversalId === null) {
                throw new RuntimeException('Receipt journal ' . $journalId . ' could not be reversed.');
            }
            $reversals[] = $reversalId;
        }

        return $reversals;
    }

    public function postServiceRefund(array $data): int
    {
        $customerRefundAmount = round((float) ($data['customer_refund_amount'] ?? 0), 2);
        $supplierRefundAmount = round((float) ($data['supplier_refund_amount'] ?? 0), 2);
        $assetAccount = $this->paymentMethodAssetAccount($data);
        $cashAccountCode = (string) $assetAccount['account_code'];

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

    public function postCrossCurrencyCustomerRefund(array $data): array
    {
        if (! $this->db->inTransaction()) {
            throw new RuntimeException('Cross-currency customer refund requires an enclosing transaction.');
        }

        $creditAmount = round((float) ($data['customer_refund_amount'] ?? 0), 2);
        $cashAmount = round((float) ($data['refund_source_amount'] ?? 0), 2);
        $creditCurrency = strtoupper((string) ($data['currency'] ?? ''));
        $cashCurrency = strtoupper((string) ($data['refund_currency'] ?? ''));
        if ($creditAmount <= 0 || $cashAmount <= 0 || $creditCurrency === $cashCurrency
            || $creditCurrency === '' || $cashCurrency === '') {
            throw new RuntimeException('Customer refund requires positive credit and cash amounts in different currencies.');
        }

        $assetAccount = $this->paymentMethodAssetAccount($data);
        $clearing = $this->ensureAccountByCode(
            'CUSTOMER_REFUND_FX_CLEARING', 'Customer Refund FX Clearing', 'asset', 'debit'
        );
        $reference = (string) ($data['source_reference'] ?? 'CUSTOMER-REFUND-FX');
        $header = [
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'entry_date' => $data['entry_date'],
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ];

        $creditJournalId = $this->postJournalEntry(array_merge($header, [
            'source_type' => 'service_refund_posted',
            'source_reference' => $reference,
            'currency' => $creditCurrency,
            'narration' => 'Customer credit settled by foreign-currency refund',
        ]), [
            ['account_code' => 'CUSTOMER_CREDIT', 'service_line_reference' => $data['service_line_reference'],
                'line_description' => 'Customer credit refunded', 'debit_amount' => $creditAmount, 'credit_amount' => 0],
            ['account_id' => $clearing['account_id'], 'service_line_reference' => $data['service_line_reference'],
                'line_description' => 'Customer refund FX clearing', 'debit_amount' => 0, 'credit_amount' => $creditAmount],
        ]);
        $cashJournalId = $this->postJournalEntry(array_merge($header, [
            'source_type' => 'service_refund_treasury_posted',
            'source_reference' => $reference . '-CASH',
            'currency' => $cashCurrency,
            'narration' => 'Customer refund paid in actual cash or bank currency',
        ]), [
            ['account_id' => $clearing['account_id'], 'service_line_reference' => $data['service_line_reference'],
                'line_description' => 'Customer refund FX clearing', 'debit_amount' => $cashAmount, 'credit_amount' => 0],
            ['account_id' => $assetAccount['account_id'], 'service_line_reference' => $data['service_line_reference'],
                'line_description' => 'Actual customer refund paid', 'debit_amount' => 0, 'credit_amount' => $cashAmount],
        ]);

        return ['credit_journal_id' => $creditJournalId, 'cash_journal_id' => $cashJournalId];
    }

    public function postCrossCurrencySupplierRefund(array $data): array
    {
        if (! $this->db->inTransaction()) {
            throw new RuntimeException('Cross-currency supplier refund requires an enclosing transaction.');
        }
        $creditAmount = round((float) ($data['supplier_credit_amount'] ?? 0), 2);
        $cashAmount = round((float) ($data['refund_source_amount'] ?? 0), 2);
        $creditCurrency = strtoupper((string) ($data['credit_currency'] ?? ''));
        $cashCurrency = strtoupper((string) ($data['refund_currency'] ?? ''));
        if ($creditAmount <= 0 || $cashAmount <= 0 || $creditCurrency === $cashCurrency
            || $creditCurrency === '' || $cashCurrency === '') {
            throw new RuntimeException('Supplier refund needs confirmed credit and cash amounts in different currencies.');
        }

        $assetAccount = $this->paymentMethodAssetAccount($data);
        $clearing = $this->ensureAccountByCode('SUPPLIER_REFUND_FX_CLEARING', 'Supplier Refund FX Clearing', 'asset', 'debit');
        $reference = (string) ($data['source_reference'] ?? 'SUPPLIER-REFUND-FX');
        $header = [
            'branch_id' => $data['branch_id'], 'booking_reference' => $data['booking_reference'],
            'entry_date' => $data['entry_date'], 'actor_user_id' => $data['actor_user_id'] ?? null,
        ];
        $creditJournalId = $this->postJournalEntry(array_merge($header, [
            'source_type' => 'service_refund_posted', 'source_reference' => $reference,
            'currency' => $creditCurrency, 'narration' => 'Supplier credit settled by cash refund in another currency',
        ]), [
            ['account_code' => 'SUPPLIER_ADVANCES', 'service_line_reference' => $data['service_line_reference'],
                'line_description' => 'Supplier credit reduced', 'debit_amount' => 0, 'credit_amount' => $creditAmount],
            ['account_id' => $clearing['account_id'], 'service_line_reference' => $data['service_line_reference'],
                'line_description' => 'Supplier refund clearing', 'debit_amount' => $creditAmount, 'credit_amount' => 0],
        ]);
        $cashJournalId = $this->postJournalEntry(array_merge($header, [
            'source_type' => 'service_refund_treasury_posted', 'source_reference' => $reference . '-CASH',
            'currency' => $cashCurrency, 'narration' => 'Supplier refund received in actual currency',
        ]), [
            ['account_id' => $assetAccount['account_id'], 'service_line_reference' => $data['service_line_reference'],
                'line_description' => 'Supplier cash refund received', 'debit_amount' => $cashAmount, 'credit_amount' => 0],
            ['account_id' => $clearing['account_id'], 'service_line_reference' => $data['service_line_reference'],
                'line_description' => 'Supplier refund clearing', 'debit_amount' => 0, 'credit_amount' => $cashAmount],
        ]);
        return ['credit_journal_id' => $creditJournalId, 'cash_journal_id' => $cashJournalId];
    }

    public function postServiceRefundReversal(array $data): ?int
    {
        $originalJournalEntryId = (int) ($data['journal_entry_id'] ?? 0);
        if ($originalJournalEntryId <= 0) {
            return null;
        }

        $originalSourceType = (string) ($data['original_source_type'] ?? 'service_refund_posted');
        if (! in_array($originalSourceType, ['service_refund_posted', 'service_refund_treasury_posted'], true)) {
            throw new RuntimeException('Unsupported refund journal type for reversal.');
        }
        $statement = $this->db->prepare(
            'SELECT
                je.id AS journal_entry_id,
                je.branch_id,
                je.booking_reference,
                je.currency,
                coa.code AS account_code,
                jel.service_line_reference,
                jel.line_description,
                jel.debit_amount,
                jel.credit_amount
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id = :journal_entry_id
               AND je.source_type = :source_type
             ORDER BY jel.id ASC'
        );
        $statement->execute(['journal_entry_id' => $originalJournalEntryId, 'source_type' => $originalSourceType]);
        $rows = $statement->fetchAll() ?: [];

        if ($rows === []) {
            return null;
        }
        if (strtoupper((string) ($rows[0]['currency'] ?? '')) !== strtoupper((string) ($data['currency'] ?? ''))
            || (int) ($rows[0]['branch_id'] ?? 0) !== (int) ($data['branch_id'] ?? 0)
            || (string) ($rows[0]['booking_reference'] ?? '') !== (string) ($data['booking_reference'] ?? '')) {
            throw new RuntimeException('Refund reversal cannot change the original journal currency, branch or booking.');
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
                'line_description' => 'Service refund reversal: ' . (string) ($row['line_description'] ?? ''),
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
            'source_type' => $originalSourceType === 'service_refund_treasury_posted'
                ? 'service_refund_treasury_reversed' : 'service_refund_reversed',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Service refund reversal',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function postServiceRefundComponentReversal(array $data): ?int
    {
        $customerRefundAmount = round((float) ($data['customer_refund_amount'] ?? 0), 2);
        $supplierRefundAmount = round((float) ($data['supplier_refund_amount'] ?? 0), 2);
        if ($customerRefundAmount <= 0 && $supplierRefundAmount <= 0) {
            return null;
        }

        $assetAccount = $this->paymentMethodAssetAccount($data);
        $cashAccountCode = (string) $assetAccount['account_code'];
        $lines = [];

        if ($customerRefundAmount > 0) {
            $lines[] = [
                'account_code' => $cashAccountCode,
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'line_description' => 'Customer refund reversal cash restored',
                'debit_amount' => $customerRefundAmount,
                'credit_amount' => 0,
            ];
            $lines[] = [
                'account_code' => 'CUSTOMER_CREDIT',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'line_description' => 'Customer refund reversal credit restored',
                'debit_amount' => 0,
                'credit_amount' => $customerRefundAmount,
            ];
        }

        if ($supplierRefundAmount > 0) {
            $lines[] = [
                'account_code' => 'SUPPLIER_ADVANCES',
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'line_description' => 'Supplier refund reversal credit restored',
                'debit_amount' => $supplierRefundAmount,
                'credit_amount' => 0,
            ];
            $lines[] = [
                'account_code' => $cashAccountCode,
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'line_description' => 'Supplier refund reversal cash reversed',
                'debit_amount' => 0,
                'credit_amount' => $supplierRefundAmount,
            ];
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => $data['booking_reference'],
            'source_type' => 'service_refund_component_reversed',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Service refund component reversal',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function postDirectTreasuryEntry(array $data): int
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Direct treasury entry amount must be greater than zero.');
        }

        $transactionType = (string) ($data['transaction_type'] ?? '');
        if (! in_array($transactionType, ['adjustment_increase', 'adjustment_decrease'], true)) {
            throw new RuntimeException('Direct treasury entry type is invalid.');
        }

        $treasuryAccount = $this->treasuryAccountLedgerReference((int) ($data['treasury_account_id'] ?? 0));
        if ($treasuryAccount === null) {
            throw new RuntimeException('Direct treasury entry requires a valid cash or bank account.');
        }

        $counterparty = trim((string) ($data['counterparty_name'] ?? ''));
        $counterpartyLabel = $counterparty !== '' ? $counterparty : 'Direct treasury counterparty';
        $accountHolderId = (int) ($data['business_source_id'] ?? 0);
        $accountHolderLabel = trim((string) ($data['business_source_name'] ?? ''));
        if ($accountHolderId > 0 && $accountHolderLabel === '') {
            $statement = $this->db->prepare('SELECT name FROM business_sources WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $accountHolderId]);
            $accountHolderLabel = trim((string) (($statement->fetchColumn() ?: '')));
        }
        if ($accountHolderId > 0 && $accountHolderLabel === '') {
            $accountHolderLabel = 'Account Holder';
        }
        $treasuryAccountName = trim((string) ($treasuryAccount['account_name'] ?? ''));
        if ($treasuryAccountName === '') {
            $treasuryAccountName = (string) ($treasuryAccount['account_code'] ?? 'treasury account');
        }

        if ($accountHolderId > 0) {
            $contraAccount = $this->ensureAccountByCode(
                'ACCOUNT_HOLDER_BALANCES',
                'Account Holder Balances',
                'liability',
                'credit'
            );

            if ($transactionType === 'adjustment_increase') {
                $lines = [
                    [
                        'account_id' => $treasuryAccount['account_id'],
                        'account_code' => $treasuryAccount['account_code'],
                        'line_description' => 'Money received into ' . $treasuryAccountName . ' for ' . $accountHolderLabel,
                        'debit_amount' => $amount,
                        'credit_amount' => 0,
                    ],
                    [
                        'account_id' => $contraAccount['account_id'],
                        'account_code' => $contraAccount['account_code'],
                        'line_description' => 'Account-holder balance increased for ' . $accountHolderLabel,
                        'debit_amount' => 0,
                        'credit_amount' => $amount,
                    ],
                ];
            } else {
                $lines = [
                    [
                        'account_id' => $contraAccount['account_id'],
                        'account_code' => $contraAccount['account_code'],
                        'line_description' => 'Account-holder balance reduced for ' . $accountHolderLabel,
                        'debit_amount' => $amount,
                        'credit_amount' => 0,
                    ],
                    [
                        'account_id' => $treasuryAccount['account_id'],
                        'account_code' => $treasuryAccount['account_code'],
                        'line_description' => 'Money paid out from ' . $treasuryAccountName . ' for ' . $accountHolderLabel,
                        'debit_amount' => 0,
                        'credit_amount' => $amount,
                    ],
                ];
            }

            return $this->postJournalEntry([
                'branch_id' => $data['branch_id'],
                'booking_reference' => null,
                'source_type' => 'direct_treasury_entry_posted',
                'source_reference' => $data['source_reference'] ?? null,
                'entry_date' => $data['entry_date'],
                'currency' => $data['currency'],
                'narration' => $data['narration'] ?? 'Account-holder treasury balance posted',
                'actor_user_id' => $data['actor_user_id'] ?? null,
            ], $lines);
        }

        if ($transactionType === 'adjustment_increase') {
            $contraAccount = $this->ensureAccountByCode(
                'TREASURY_MISC_RECEIPTS',
                'Direct Treasury Receipts',
                'revenue',
                'credit'
            );

            $lines = [
                [
                    'account_id' => $treasuryAccount['account_id'],
                    'account_code' => $treasuryAccount['account_code'],
                    'line_description' => 'Money received into ' . $treasuryAccountName . ' from ' . $counterpartyLabel,
                    'debit_amount' => $amount,
                    'credit_amount' => 0,
                ],
                [
                    'account_id' => $contraAccount['account_id'],
                    'account_code' => $contraAccount['account_code'],
                    'line_description' => 'Direct treasury receipt recognized for ' . $counterpartyLabel,
                    'debit_amount' => 0,
                    'credit_amount' => $amount,
                ],
            ];
        } else {
            $contraAccount = $this->ensureAccountByCode(
                'TREASURY_MISC_PAYMENTS',
                'Direct Treasury Payments',
                'expense',
                'debit'
            );

            $lines = [
                [
                    'account_id' => $contraAccount['account_id'],
                    'account_code' => $contraAccount['account_code'],
                    'line_description' => 'Direct treasury payment recognized for ' . $counterpartyLabel,
                    'debit_amount' => $amount,
                    'credit_amount' => 0,
                ],
                [
                    'account_id' => $treasuryAccount['account_id'],
                    'account_code' => $treasuryAccount['account_code'],
                    'line_description' => 'Money paid out from ' . $treasuryAccountName . ' to ' . $counterpartyLabel,
                    'debit_amount' => 0,
                    'credit_amount' => $amount,
                ],
            ];
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => null,
            'source_type' => 'direct_treasury_entry_posted',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Direct treasury entry posted',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function postTreasuryTransfer(array $data): int
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Treasury transfer amount must be greater than zero.');
        }

        $fromAccount = $this->treasuryAccountLedgerReference((int) ($data['from_treasury_account_id'] ?? 0));
        $toAccount = $this->treasuryAccountLedgerReference((int) ($data['to_treasury_account_id'] ?? 0));

        if ($fromAccount === null || $toAccount === null) {
            throw new RuntimeException('Treasury transfer requires valid source and destination treasury accounts.');
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => null,
            'source_type' => 'treasury_transfer_posted',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Internal treasury transfer posted',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], [
            [
                'account_id' => $toAccount['account_id'],
                'account_code' => $toAccount['account_code'],
                'line_description' => $data['to_line_description'] ?? 'Treasury transfer in',
                'debit_amount' => $amount,
                'credit_amount' => 0,
            ],
            [
                'account_id' => $fromAccount['account_id'],
                'account_code' => $fromAccount['account_code'],
                'line_description' => $data['from_line_description'] ?? 'Treasury transfer out',
                'debit_amount' => 0,
                'credit_amount' => $amount,
            ],
        ]);
    }

    public function postCrossDimensionTreasuryTransfer(array $data): array
    {
        if (! $this->db->inTransaction()) {
            throw new RuntimeException('Cross-dimension treasury journals require an enclosing transfer transaction.');
        }
        $sourceAmount = round((float) ($data['source_amount'] ?? 0), 2);
        $destinationAmount = round((float) ($data['destination_amount'] ?? 0), 2);
        if ($sourceAmount <= 0 || $destinationAmount <= 0) {
            throw new RuntimeException('Both treasury transfer amounts must be greater than zero.');
        }

        $fromAccount = $this->treasuryAccountLedgerReference((int) ($data['from_treasury_account_id'] ?? 0));
        $toAccount = $this->treasuryAccountLedgerReference((int) ($data['to_treasury_account_id'] ?? 0));
        if ($fromAccount === null || $toAccount === null) {
            throw new RuntimeException('Treasury transfer requires valid source and destination ledger accounts.');
        }

        $crossBranch = (int) $data['source_branch_id'] !== (int) $data['destination_branch_id'];
        $sourceClearing = $crossBranch
            ? $this->ensureAccountByCode('INTERBRANCH_TRANSFER_RECEIVABLE', 'Interbranch Transfer Receivable', 'asset', 'debit')
            : $this->ensureAccountByCode('TREASURY_FX_CLEARING', 'Treasury FX Clearing', 'asset', 'debit');
        $destinationClearing = $crossBranch
            ? $this->ensureAccountByCode('INTERBRANCH_TRANSFER_PAYABLE', 'Interbranch Transfer Payable', 'liability', 'credit')
            : $sourceClearing;
        $reference = 'TR-' . (int) $data['treasury_transaction_id'];
        $header = [
            'booking_reference' => null,
            'source_type' => 'treasury_transfer_posted',
            'entry_date' => $data['entry_date'],
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ];

        $sourceJournalId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => $data['source_branch_id'],
            'currency' => $data['source_currency'],
            'source_reference' => $reference . '-SOURCE',
            'narration' => $data['source_narration'] ?? 'Treasury transfer out to ' . (string) $data['destination_currency'],
        ]), [
            ['account_id' => $sourceClearing['account_id'], 'line_description' => 'Transfer clearing receivable', 'debit_amount' => $sourceAmount, 'credit_amount' => 0],
            ['account_id' => $fromAccount['account_id'], 'line_description' => $data['source_line_description'] ?? 'Cash or bank transfer out', 'debit_amount' => 0, 'credit_amount' => $sourceAmount],
        ]);
        $destinationJournalId = $this->postJournalEntry(array_merge($header, [
            'branch_id' => $data['destination_branch_id'],
            'currency' => $data['destination_currency'],
            'source_reference' => $reference . '-DESTINATION',
            'narration' => $data['destination_narration'] ?? 'Treasury transfer in from ' . (string) $data['source_currency'],
        ]), [
            ['account_id' => $toAccount['account_id'], 'line_description' => $data['destination_line_description'] ?? 'Cash or bank transfer in', 'debit_amount' => $destinationAmount, 'credit_amount' => 0],
            ['account_id' => $destinationClearing['account_id'], 'line_description' => 'Transfer clearing payable', 'debit_amount' => 0, 'credit_amount' => $destinationAmount],
        ]);

        return [$sourceJournalId, $destinationJournalId];
    }

    public function postTreasuryTransferVoidReversal(array $data): ?int
    {
        $originalJournalEntryId = (int) ($data['journal_entry_id'] ?? 0);
        if ($originalJournalEntryId <= 0) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT
                je.id AS journal_entry_id,
                je.branch_id,
                je.currency,
                coa.code AS account_code,
                jel.line_description,
                jel.debit_amount,
                jel.credit_amount
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id = :journal_entry_id
               AND je.source_type = "treasury_transfer_posted"
             ORDER BY jel.id ASC'
        );
        $statement->execute(['journal_entry_id' => $originalJournalEntryId]);
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
                'line_description' => 'Treasury transfer void reversal: ' . (string) ($row['line_description'] ?? ''),
                'debit_amount' => $creditAmount,
                'credit_amount' => $debitAmount,
            ];
        }

        if ($lines === []) {
            return null;
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => null,
            'source_type' => 'treasury_transfer_void_reversed',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Treasury transfer void reversal',
            'actor_user_id' => $data['actor_user_id'] ?? null,
        ], $lines);
    }

    public function postDirectTreasuryEntryVoidReversal(array $data): ?int
    {
        $originalJournalEntryId = (int) ($data['journal_entry_id'] ?? 0);
        if ($originalJournalEntryId <= 0) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT
                je.id AS journal_entry_id,
                je.branch_id,
                je.currency,
                coa.code AS account_code,
                jel.line_description,
                jel.debit_amount,
                jel.credit_amount
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.id = :journal_entry_id
               AND je.source_type = "direct_treasury_entry_posted"
             ORDER BY jel.id ASC'
        );
        $statement->execute(['journal_entry_id' => $originalJournalEntryId]);
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
                'line_description' => 'Direct treasury void reversal: ' . (string) ($row['line_description'] ?? ''),
                'debit_amount' => $creditAmount,
                'credit_amount' => $debitAmount,
            ];
        }

        if ($lines === []) {
            return null;
        }

        return $this->postJournalEntry([
            'branch_id' => $data['branch_id'],
            'booking_reference' => null,
            'source_type' => 'direct_treasury_entry_void_reversed',
            'source_reference' => $data['source_reference'] ?? null,
            'entry_date' => $data['entry_date'],
            'currency' => $data['currency'],
            'narration' => $data['narration'] ?? 'Direct treasury entry void reversal',
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
            'customer_advance_refunded' => 'Customer Advance Refunded',
            'service_refund_reversed' => 'Service Refund Reversed',
            'supplier_advance_recorded' => 'Supplier Advance Recorded',
            'supplier_advance_applied' => 'Supplier Advance Applied',
            'supplier_advance_adjusted' => 'Supplier Advance Adjusted',
            'supplier_settlement_released' => 'Supplier Settlement Released',
            'supplier_payment_recorded' => 'Supplier Payment Recorded',
            'supplier_payment_allocated' => 'Supplier Payment Allocated',
            'treasury_transfer_posted' => 'Treasury Transfer Posted',
            'treasury_transfer_void_reversed' => 'Treasury Transfer Void Reversed',
            'direct_treasury_entry_posted' => 'Direct Treasury Entry Posted',
            'direct_treasury_entry_void_reversed' => 'Direct Treasury Entry Void Reversed',
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

    private function ensureAccountByCode(string $code, string $name, string $accountType, string $normalBalance): array
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM chart_of_accounts
             WHERE code = :code
             LIMIT 1'
        );
        $statement->execute(['code' => $code]);
        $accountId = (int) ($statement->fetchColumn() ?: 0);

        if ($accountId <= 0) {
            $insert = $this->db->prepare(
                'INSERT INTO chart_of_accounts (
                    code, name, account_type, normal_balance, is_system, is_active
                 ) VALUES (
                    :code, :name, :account_type, :normal_balance, 1, 1
                 )'
            );
            $insert->execute([
                'code' => $code,
                'name' => $name,
                'account_type' => $accountType,
                'normal_balance' => $normalBalance,
            ]);
            $accountId = (int) $this->db->lastInsertId();
        }

        $this->accountIdCache[$code] = $accountId;

        return [
            'account_code' => $code,
            'account_id' => $accountId,
        ];
    }

    private function customerReceiptAssetAccount(array $data): array
    {
        return $this->paymentMethodAssetAccount($data);
    }

    private function paymentMethodAssetAccount(array $data): array
    {
        $paymentMethod = (string) ($data['payment_method'] ?? '');
        if ($paymentMethod === 'account_holder_balance') {
            return $this->ensureAccountByCode(
                'ACCOUNT_HOLDER_BALANCES',
                'Account Holder Balances',
                'liability',
                'credit'
            );
        }
        if (in_array($paymentMethod, ['debit_card', 'credit_card'], true)) {
            return [
                'account_code' => 'CARD_CLEARING',
                'account_id' => $this->accountIdByCode('CARD_CLEARING'),
            ];
        }

        $treasuryAccountId = (int) ($data['treasury_account_id'] ?? 0);
        if ($treasuryAccountId > 0) {
            $treasuryAccount = $this->treasuryAccountLedgerReference($treasuryAccountId);
            if ($treasuryAccount !== null) {
                return $treasuryAccount;
            }
        }

        $fallbackCode = $paymentMethod === 'cash' ? 'CASH_ON_HAND' : 'BANK_CLEARING';

        return [
            'account_code' => $fallbackCode,
            'account_id' => $this->accountIdByCode($fallbackCode),
        ];
    }

    private function treasuryAccountLedgerReference(int $treasuryAccountId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                ta.id,
                ta.branch_id,
                ta.account_type,
                ta.account_name,
                ta.account_code,
                ta.linked_account_id,
                coa.code AS linked_account_code
             FROM treasury_accounts ta
             LEFT JOIN chart_of_accounts coa ON coa.id = ta.linked_account_id
             WHERE ta.id = :id
               AND ta.is_active = 1
             LIMIT 1'
        );
        $statement->execute(['id' => $treasuryAccountId]);
        $account = $statement->fetch();

        if ($account === false) {
            return null;
        }

        $accountType = (string) ($account['account_type'] ?? '');
        if (! in_array($accountType, ['cash', 'bank', 'wallet'], true)) {
            return null;
        }

        $desiredCode = strtoupper(trim((string) ($account['account_code'] ?? '')));
        if ($desiredCode === '') {
            return null;
        }

        $linkedAccountId = (int) ($account['linked_account_id'] ?? 0);
        $linkedAccountCode = strtoupper(trim((string) ($account['linked_account_code'] ?? '')));

        if ($linkedAccountId > 0 && $linkedAccountCode === $desiredCode) {
            return [
                'branch_id' => (int) ($account['branch_id'] ?? 0),
                'account_code' => $desiredCode,
                'account_id' => $linkedAccountId,
                'account_name' => (string) ($account['account_name'] ?? $desiredCode),
            ];
        }

        $statement = $this->db->prepare(
            'SELECT id
             FROM chart_of_accounts
             WHERE code = :code
             LIMIT 1'
        );
        $statement->execute(['code' => $desiredCode]);
        $existingId = (int) ($statement->fetchColumn() ?: 0);

        if ($existingId <= 0) {
            $insert = $this->db->prepare(
                'INSERT INTO chart_of_accounts (
                    code, name, purpose, account_type, normal_balance, is_system, is_active
                 ) VALUES (
                    :code, :name, :purpose, "asset", "debit", 0, 1
                 )'
            );
            $insert->execute([
                'code' => $desiredCode,
                'name' => (string) ($account['account_name'] ?? $desiredCode),
                'purpose' => 'Operational treasury account',
            ]);
            $existingId = (int) $this->db->lastInsertId();
        }

        if ($existingId > 0 && $linkedAccountId !== $existingId) {
            $update = $this->db->prepare(
                'UPDATE treasury_accounts
                 SET linked_account_id = :linked_account_id
                 WHERE id = :id'
            );
            $update->execute([
                'linked_account_id' => $existingId,
                'id' => $treasuryAccountId,
            ]);
        }

        return $existingId > 0
            ? [
                'branch_id' => (int) ($account['branch_id'] ?? 0),
                'account_code' => $desiredCode,
                'account_id' => $existingId,
                'account_name' => (string) ($account['account_name'] ?? $desiredCode),
            ]
            : null;
    }
}
