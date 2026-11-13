<?php

declare(strict_types=1);

/** Read-only comparison of supplier source records and reusable credit. */
final class SupplierPositionReconciliationAudit
{
    public static function inspect(PDO $db, int $supplierId = 0, ?\App\Core\App $app = null): array
    {
        $groups = [];
        $advances = [];
        $reviews = [];
        $paymentCurrencies = [];
        $obligationCurrencies = [];

        $group = static function (int $id, string $currency) use (&$groups): string {
            $key = $id . '|' . strtoupper($currency);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'supplier_id' => $id,
                    'currency' => strtoupper($currency),
                    'paid_less_returned' => 0.0,
                    'standalone_advance_paid' => 0.0,
                    'obligations' => 0.0,
                    'prior_period_adjustment' => 0.0,
                    'linked_offsets' => 0.0,
                    'available_credit' => 0.0,
                    'open_payable' => 0.0,
                    'payment_ids' => [],
                    'advance_ids' => [],
                    'obligation_ids' => [],
                    'offset_ids' => [],
                    'exclusions' => [],
                ];
            }
            return $key;
        };

        $read = static function (string $sql) use ($db): array {
            $statement = $db->query($sql);
            return $statement instanceof PDOStatement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        };

        foreach ($read('SELECT id, supplier_id, currency, paid_amount, returned_amount, unallocated_amount, status
                        FROM supplier_payments WHERE status <> "void"') as $row) {
            $id = (int) $row['supplier_id'];
            if ($supplierId > 0 && $supplierId !== $id) {
                continue;
            }
            $key = $group($id, (string) $row['currency']);
            $groups[$key]['paid_less_returned'] += (float) $row['paid_amount'] - (float) $row['returned_amount'];
            $groups[$key]['available_credit'] += (float) $row['unallocated_amount'];
            $groups[$key]['payment_ids'][] = (int) $row['id'];
            $paymentCurrencies[(int) $row['id']] = $key;
        }

        foreach ($read('SELECT id, supplier_id, currency, gross_amount, net_payable_amount
                        FROM supplier_obligations') as $row) {
            $id = (int) $row['supplier_id'];
            if ($supplierId > 0 && $supplierId !== $id) {
                continue;
            }
            $key = $group($id, (string) $row['currency']);
            $groups[$key]['obligations'] += (float) $row['gross_amount'];
            $groups[$key]['open_payable'] += (float) $row['net_payable_amount'];
            $groups[$key]['obligation_ids'][] = (int) $row['id'];
            $obligationCurrencies[(int) $row['id']] = $key;
        }

        $priorPeriodFailures = [];
        $hasJournalTables = $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || (bool) $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'journal_entries'")->fetchColumn();
        $priorPeriodRows = $hasJournalTables ? $read('SELECT je.id, je.currency, jel.supplier_id_snapshot AS supplier_id,
                            jel.debit_amount, jel.credit_amount,
                            EXISTS (
                                SELECT 1 FROM journal_entry_lines equity_line
                                INNER JOIN chart_of_accounts equity_account
                                    ON equity_account.id = equity_line.account_id
                                WHERE equity_line.journal_entry_id = je.id
                                  AND equity_account.code = "PRIOR_PERIOD_SUPPLIER_ADJUSTMENT"
                                  AND equity_line.supplier_id_snapshot = jel.supplier_id_snapshot
                                  AND equity_line.debit_amount = jel.credit_amount
                                  AND equity_line.credit_amount = jel.debit_amount
                            ) AS has_matching_equity
                        FROM journal_entries je
                        INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                        INNER JOIN chart_of_accounts account ON account.id = jel.account_id
                        WHERE je.source_type IN (
                            "supplier_prior_period_adjustment",
                            "supplier_prior_period_adjustment_reversed"
                        ) AND account.code = "SUPPLIER_ADVANCES"') : [];
        foreach ($priorPeriodRows as $row) {
            $id = (int) $row['supplier_id'];
            if ($supplierId > 0 && $supplierId !== $id) {
                continue;
            }
            if ($id <= 0 || (int) $row['has_matching_equity'] !== 1) {
                $priorPeriodFailures[] = [
                    'type' => 'prior_period_supplier_adjustment_evidence',
                    'journal_id' => (int) $row['id'],
                    'supplier_id' => $id,
                    'currency' => (string) $row['currency'],
                ];
                continue;
            }
            $key = $group($id, (string) $row['currency']);
            $groups[$key]['prior_period_adjustment'] +=
                (float) $row['credit_amount'] - (float) $row['debit_amount'];
        }

        foreach ($read('SELECT id, supplier_id, currency, deposit_amount, available_amount,
                            source_supplier_payment_id FROM supplier_advances') as $row) {
            $id = (int) $row['supplier_id'];
            if ($supplierId > 0 && $supplierId !== $id) {
                continue;
            }
            $key = $group($id, (string) $row['currency']);
            $groups[$key]['available_credit'] += (float) $row['available_amount'];
            if ((int) $row['source_supplier_payment_id'] <= 0) {
                $groups[$key]['standalone_advance_paid'] += (float) $row['deposit_amount'];
            }
            $groups[$key]['advance_ids'][] = (int) $row['id'];
            $advances[(int) $row['id']] = $row + ['group_key' => $key, 'used' => 0.0, 'application_ids' => []];
        }

        foreach ($read('SELECT a.id, a.supplier_payment_id, a.supplier_obligation_id,
                            p.supplier_id AS payment_supplier_id, p.currency AS payment_currency,
                            o.supplier_id AS obligation_supplier_id, o.currency AS obligation_currency
                        FROM supplier_payment_allocations a
                        INNER JOIN supplier_payments p ON p.id = a.supplier_payment_id AND p.status <> "void"
                        INNER JOIN supplier_obligations o ON o.id = a.supplier_obligation_id') as $row) {
            $paymentKey = $paymentCurrencies[(int) $row['supplier_payment_id']] ?? null;
            $obligationKey = $obligationCurrencies[(int) $row['supplier_obligation_id']] ?? null;
            $actualObligationKey = (int) $row['obligation_supplier_id'] . '|'
                . strtoupper((string) $row['obligation_currency']);
            $actualPaymentKey = (int) $row['payment_supplier_id'] . '|'
                . strtoupper((string) $row['payment_currency']);
            if ($paymentKey !== null && $paymentKey !== $actualObligationKey) {
                $groups[$paymentKey]['exclusions'][] = 'cross-currency or cross-supplier payment allocation #' . $row['id'];
            }
            if ($obligationKey !== null && $actualPaymentKey !== $obligationKey) {
                $groups[$obligationKey]['exclusions'][] = 'cross-currency or cross-supplier payment allocation #' . $row['id'];
            }
        }

        foreach ($read('SELECT x.id, x.supplier_advance_id, x.supplier_obligation_id,
                            x.applied_amount, x.advance_amount_consumed,
                            a.supplier_id AS advance_supplier_id, a.currency AS advance_currency,
                            o.supplier_id AS obligation_supplier_id, o.currency AS obligation_currency
                        FROM supplier_advance_applications x
                        INNER JOIN supplier_advances a ON a.id = x.supplier_advance_id
                        INNER JOIN supplier_obligations o ON o.id = x.supplier_obligation_id') as $row) {
            $advanceId = (int) $row['supplier_advance_id'];
            $obligationKey = $obligationCurrencies[(int) $row['supplier_obligation_id']] ?? null;
            $actualAdvanceKey = (int) $row['advance_supplier_id'] . '|'
                . strtoupper((string) $row['advance_currency']);
            $actualObligationKey = (int) $row['obligation_supplier_id'] . '|'
                . strtoupper((string) $row['obligation_currency']);
            if ($obligationKey !== null && $actualAdvanceKey !== $obligationKey) {
                $groups[$obligationKey]['exclusions'][] = 'cross-currency or cross-supplier advance application #' . $row['id'];
            }
            if (! isset($advances[$advanceId])) {
                continue;
            }
            $advanceKey = $advances[$advanceId]['group_key'];
            if ($advanceKey !== $actualObligationKey) {
                $groups[$advanceKey]['exclusions'][] = 'cross-currency or cross-supplier advance application #' . $row['id'];
            }
            $advances[$advanceId]['used'] += (float) ($row['advance_amount_consumed'] ?? $row['applied_amount']);
            $advances[$advanceId]['application_ids'][] = (int) $row['id'];
        }

        foreach ($read('SELECT a.id, a.supplier_obligation_id, a.allocated_amount
                        FROM counterparty_offset_payable_allocations a
                        INNER JOIN counterparty_offsets c ON c.id = a.counterparty_offset_id
                        WHERE c.status = "posted"') as $row) {
            $key = $obligationCurrencies[(int) $row['supplier_obligation_id']] ?? null;
            if ($key !== null) {
                $groups[$key]['linked_offsets'] += (float) $row['allocated_amount'];
                $groups[$key]['offset_ids'][] = (int) $row['id'];
            }
        }

        foreach ($read('SELECT e.id, bs.supplier_id, e.currency
                        FROM booking_service_events e
                        INNER JOIN booking_services bs ON bs.id = e.booking_service_id
                        WHERE e.event_status = "posted" AND e.supplier_refund_amount > 0.005') as $row) {
            $id = (int) $row['supplier_id'];
            if ($supplierId > 0 && $supplierId !== $id) {
                continue;
            }
            foreach ($groups as $key => $value) {
                if ($value['supplier_id'] === $id) {
                    $groups[$key]['exclusions'][] = 'supplier refund event #' . $row['id'] . ' requires cash/credit classification';
                }
            }
        }

        $exceptions = $priorPeriodFailures;
        foreach ($advances as $advance) {
            $difference = round((float) $advance['deposit_amount'] - (float) $advance['available_amount'] - $advance['used'], 2);
            if (abs($difference) > 0.005) {
                $exceptions[] = [
                    'type' => 'advance_conservation',
                    'supplier_id' => (int) $advance['supplier_id'],
                    'currency' => (string) $advance['currency'],
                    'advance_id' => (int) $advance['id'],
                    'application_ids' => $advance['application_ids'],
                    'expected_available' => round((float) $advance['deposit_amount'] - $advance['used'], 2),
                    'actual_available' => round((float) $advance['available_amount'], 2),
                    'difference' => $difference,
                ];
            }
        }

        ksort($groups);
        foreach ($groups as &$row) {
            $row['expected_net_position'] = round($row['paid_less_returned'] + $row['standalone_advance_paid']
                - $row['obligations'] - $row['prior_period_adjustment'] + $row['linked_offsets'], 2);
            $row['actual_net_position'] = round($row['available_credit'] - $row['open_payable'], 2);
            $row['difference'] = round($row['expected_net_position'] - $row['actual_net_position'], 2);
            $row['exclusions'] = array_values(array_unique($row['exclusions']));
            if ($row['exclusions'] === [] && abs($row['difference']) > 0.005) {
                $exceptions[] = [
                    'type' => 'supplier_position',
                    'supplier_id' => $row['supplier_id'],
                    'currency' => $row['currency'],
                    'expected_net_position' => $row['expected_net_position'],
                    'actual_net_position' => $row['actual_net_position'],
                    'difference' => $row['difference'],
                    'payment_ids' => $row['payment_ids'],
                    'advance_ids' => $row['advance_ids'],
                    'obligation_ids' => $row['obligation_ids'],
                    'offset_ids' => $row['offset_ids'],
                ];
            }
        }
        unset($row);

        if ($app !== null) {
            $branchIds = array_map('intval', $db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $reportRepository = new \App\Repositories\ReportRepository($app);
            $ledgerService = new \App\Services\SupplierLedgerService($app);
            foreach ($groups as &$row) {
                $rawLedgerRows = $reportRepository->supplierLedger(
                    $branchIds, null, null, $row['currency'], '', $row['supplier_id'], 0, ''
                );
                $ledger = $ledgerService->buildReport($rawLedgerRows, $row['currency']);
                $card = null;
                foreach ($ledger['summaryCards'] as $candidate) {
                    if ($candidate['label'] === 'Supplier Balance / ' . $row['currency']) {
                        $card = $candidate;
                        break;
                    }
                }
                if ($card === null) {
                    throw new RuntimeException('Supplier balance card missing for supplier #' . $row['supplier_id']);
                }
                $value = (string) $card['value'];
                if (preg_match('/^[A-Z]{3} ([\d,]+\.\d{2})(?: (Advance|Payable))?$/', $value, $matches) !== 1) {
                    throw new RuntimeException('Unrecognized supplier balance card: ' . $value);
                }
                $magnitude = (float) str_replace(',', '', $matches[1]);
                $ledgerNetPosition = ($matches[2] ?? '') === 'Payable' ? -$magnitude : $magnitude;
                $row['ledger_net_position'] = round($ledgerNetPosition, 2);
                $row['ledger_difference'] = round($row['ledger_net_position'] - $row['actual_net_position'], 2);
                if (abs($row['ledger_difference']) > 0.005) {
                    $reviews[] = [
                        'type' => 'supplier_ledger_vs_operational_records',
                        'supplier_id' => $row['supplier_id'],
                        'currency' => $row['currency'],
                        'ledger_net_position' => $row['ledger_net_position'],
                        'operational_credit_minus_open_payable' => $row['actual_net_position'],
                        'difference' => $row['ledger_difference'],
                        'exclusions' => $row['exclusions'],
                        'payment_ids' => $row['payment_ids'],
                        'obligation_ids' => $row['obligation_ids'],
                    ];
                }
            }
            unset($row);
        }

        return ['groups' => array_values($groups), 'exceptions' => $exceptions, 'reviews' => $reviews];
    }
}
