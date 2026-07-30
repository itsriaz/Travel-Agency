<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\CounterpartyLinkRepository;
use PDO;
use RuntimeException;

final class CounterpartyLinkService extends Service
{
    public function linkAccountToSupplier(
        int $businessSourceId,
        int $supplierId,
        int $actorUserId,
        ?string $reason = null
    ): array {
        $this->assertFinancialAdminActor(
            $actorUserId,
            'Only a financial administrator can link an account holder to a supplier.'
        );
        if ($businessSourceId <= 0 || $supplierId <= 0) {
            throw new RuntimeException('Please select both an account holder and a supplier.');
        }
        $reason = $this->optionalReason($reason);

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CounterpartyLinkRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $account = $repository->findBusinessSource($businessSourceId, true);
            if ($account === null) {
                throw new RuntimeException('The selected account holder could not be found.');
            }
            if ((int) ($account['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('The selected account holder is inactive. Activate it before linking.');
            }
            if ((int) ($account['is_system'] ?? 0) === 1) {
                throw new RuntimeException('A system account such as Walking Client cannot be linked to a supplier.');
            }

            $supplier = $repository->findSupplier($supplierId, true);
            if ($supplier === null) {
                throw new RuntimeException('The selected supplier could not be found.');
            }
            if ((int) ($supplier['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('The selected supplier is inactive. Activate it before linking.');
            }

            $accountLink = $repository->findByBusinessSourceId($businessSourceId, true);
            if ($accountLink !== null) {
                if ((int) ($accountLink['supplier_id'] ?? 0) === $supplierId) {
                    $automaticSettlements = (new CounterpartyOffsetService($this->app))->autoSettleLink(
                        (int) $accountLink['id'],
                        date('Y-m-d'),
                        $actorUserId
                    );
                    if ($startedTransaction && $db->inTransaction()) {
                        $db->commit();
                    }

                    return [
                        'action' => 'unchanged',
                        'link' => $accountLink,
                        'automatic_settlements' => $automaticSettlements,
                    ];
                }
                throw new RuntimeException(
                    'This account holder is already linked to supplier '
                    . (string) ($accountLink['supplier_name'] ?? $accountLink['supplier_code'] ?? '')
                    . '. Unlink it before choosing another supplier.'
                );
            }

            $supplierLink = $repository->findBySupplierId($supplierId, true);
            if ($supplierLink !== null) {
                throw new RuntimeException(
                    'This supplier is already linked to account holder '
                    . (string) ($supplierLink['business_source_name'] ?? $supplierLink['business_source_code'] ?? '')
                    . '. Unlink it before choosing another account holder.'
                );
            }

            $linkId = $repository->createLink($businessSourceId, $supplierId, $actorUserId);
            $repository->recordHistory(
                $businessSourceId,
                $supplierId,
                'linked',
                $reason,
                $actorUserId
            );
            $link = $repository->findById($linkId) ?? [];
            $automaticSettlements = (new CounterpartyOffsetService($this->app))->autoSettleLink(
                $linkId,
                date('Y-m-d'),
                $actorUserId
            );

            AuditLog::record($this->app, 'counterparty.account_supplier.linked', [
                'user_id' => $actorUserId,
                'link_id' => $linkId,
                'business_source_id' => $businessSourceId,
                'business_source_name' => $account['name'] ?? null,
                'supplier_id' => $supplierId,
                'supplier_name' => $supplier['name'] ?? null,
                'reason' => $reason,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'action' => 'linked',
                'link' => $link,
                'automatic_settlements' => $automaticSettlements,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('The account holder could not be linked to the supplier.', 0, $exception);
        }
    }

    public function unlinkAccountFromSupplier(int $linkId, int $actorUserId, string $reason): array
    {
        $this->assertFinancialAdminActor(
            $actorUserId,
            'Only a financial administrator can unlink an account holder from a supplier.'
        );
        if ($linkId <= 0) {
            throw new RuntimeException('Please select a valid account–supplier link.');
        }
        $reason = $this->requiredReason($reason);

        /** @var PDO $db */
        $db = $this->app->get('db');
        $repository = new CounterpartyLinkRepository($this->app);
        $startedTransaction = ! $db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $link = $repository->findById($linkId, true);
            if ($link === null) {
                throw new RuntimeException('The selected account–supplier link could not be found.');
            }

            $repository->recordHistory(
                (int) $link['business_source_id'],
                (int) $link['supplier_id'],
                'unlinked',
                $reason,
                $actorUserId
            );
            $repository->deleteLink($linkId);

            AuditLog::record($this->app, 'counterparty.account_supplier.unlinked', [
                'user_id' => $actorUserId,
                'link_id' => $linkId,
                'business_source_id' => (int) $link['business_source_id'],
                'business_source_name' => $link['business_source_name'] ?? null,
                'supplier_id' => (int) $link['supplier_id'],
                'supplier_name' => $link['supplier_name'] ?? null,
                'reason' => $reason,
            ]);

            if ($startedTransaction && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'action' => 'unlinked',
                'link' => $link,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('The account–supplier link could not be removed.', 0, $exception);
        }
    }

    public function linkForAccount(int $businessSourceId): ?array
    {
        return (new CounterpartyLinkRepository($this->app))->findByBusinessSourceId($businessSourceId);
    }

    public function linkForSupplier(int $supplierId): ?array
    {
        return (new CounterpartyLinkRepository($this->app))->findBySupplierId($supplierId);
    }

    public function links(): array
    {
        return (new CounterpartyLinkRepository($this->app))->listLinks();
    }

    private function optionalReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return null;
        }
        if (mb_strlen($reason) > 1000) {
            throw new RuntimeException('The link note cannot exceed 1,000 characters.');
        }

        return $reason;
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Please enter a reason for unlinking these records.');
        }
        if (mb_strlen($reason) > 1000) {
            throw new RuntimeException('The unlink reason cannot exceed 1,000 characters.');
        }

        return $reason;
    }
}
