<?php

declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;

final class AccountingSetupRepository extends BaseRepository
{
    public function listControlAccounts(): array
    {
        $statement = $this->db->query(
            'SELECT id, code, name, purpose, account_type, normal_balance, is_system, is_active
             FROM chart_of_accounts
             ORDER BY is_system DESC, code ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    public function findControlAccount(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, name, purpose, account_type, normal_balance, is_system, is_active
             FROM chart_of_accounts
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function controlAccountCodeExists(string $code, ?int $excludeId = null): bool
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM chart_of_accounts
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

    public function saveControlAccount(array $payload): int
    {
        $id = (int) ($payload['id'] ?? 0);
        $data = [
            'code' => $payload['code'],
            'name' => $payload['name'],
            'purpose' => $payload['purpose'] ?? null,
            'account_type' => $payload['account_type'],
            'normal_balance' => $payload['normal_balance'],
            'is_active' => $payload['is_active'],
        ];

        if ($id > 0) {
            $data['id'] = $id;
            $statement = $this->db->prepare(
                'UPDATE chart_of_accounts
                 SET code = :code,
                     name = :name,
                     purpose = :purpose,
                     account_type = :account_type,
                     normal_balance = :normal_balance,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute($data);

            return $id;
        }

        $statement = $this->db->prepare(
            'INSERT INTO chart_of_accounts (
                code, name, purpose, account_type, normal_balance, is_system, is_active
             ) VALUES (
                :code, :name, :purpose, :account_type, :normal_balance, 0, :is_active
             )'
        );
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    public function controlAccountDeleteBlockedReason(int $id): ?string
    {
        $account = $this->findControlAccount($id);

        if ($account === null) {
            return 'Account not found.';
        }

        if ((int) ($account['is_system'] ?? 0) === 1) {
            return 'System control accounts cannot be deleted.';
        }

        if ($this->countByValue('journal_entry_lines', 'account_id', $id) > 0) {
            return 'This account is already used in posted journal lines.';
        }

        if ($this->countByValue('posting_rules', 'debit_account_id', $id) > 0 || $this->countByValue('posting_rules', 'credit_account_id', $id) > 0) {
            return 'This account is already referenced by posting rules.';
        }

        return null;
    }

    public function deleteControlAccount(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM chart_of_accounts WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function listPostingRules(): array
    {
        $statement = $this->db->query(
            'SELECT
                pr.id,
                pr.event_key,
                pr.event_name,
                pr.source_area,
                pr.financial_effect,
                pr.rule_note,
                pr.sort_order,
                pr.is_system,
                pr.is_active,
                pr.debit_account_id,
                pr.credit_account_id,
                debit.code AS debit_account_code,
                debit.name AS debit_account_name,
                credit.code AS credit_account_code,
                credit.name AS credit_account_name
             FROM posting_rules pr
             INNER JOIN chart_of_accounts debit ON debit.id = pr.debit_account_id
             INNER JOIN chart_of_accounts credit ON credit.id = pr.credit_account_id
             ORDER BY pr.sort_order ASC, pr.event_name ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    public function findPostingRule(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                id,
                event_key,
                event_name,
                source_area,
                financial_effect,
                rule_note,
                sort_order,
                is_system,
                is_active,
                debit_account_id,
                credit_account_id
             FROM posting_rules
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function postingRuleKeyExists(string $eventKey, ?int $excludeId = null): bool
    {
        $statement = $this->db->prepare(
            'SELECT id
             FROM posting_rules
             WHERE event_key = :event_key' . ($excludeId !== null ? ' AND id != :exclude_id' : '') . '
             LIMIT 1'
        );
        $params = ['event_key' => $eventKey];

        if ($excludeId !== null) {
            $params['exclude_id'] = $excludeId;
        }

        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function savePostingRule(array $payload): int
    {
        $data = [
            'event_key' => $payload['event_key'],
            'event_name' => $payload['event_name'],
            'source_area' => $payload['source_area'],
            'financial_effect' => $payload['financial_effect'],
            'debit_account_id' => $payload['debit_account_id'],
            'credit_account_id' => $payload['credit_account_id'],
            'rule_note' => $payload['rule_note'] ?? null,
            'sort_order' => $payload['sort_order'],
            'is_active' => $payload['is_active'],
        ];
        $id = (int) ($payload['id'] ?? 0);

        if ($id > 0) {
            $data['id'] = $id;
            $statement = $this->db->prepare(
                'UPDATE posting_rules
                 SET event_key = :event_key,
                     event_name = :event_name,
                     source_area = :source_area,
                     financial_effect = :financial_effect,
                     debit_account_id = :debit_account_id,
                     credit_account_id = :credit_account_id,
                     rule_note = :rule_note,
                     sort_order = :sort_order,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute($data);

            return $id;
        }

        $statement = $this->db->prepare(
            'INSERT INTO posting_rules (
                event_key, event_name, source_area, financial_effect,
                debit_account_id, credit_account_id, rule_note, sort_order, is_system, is_active
             ) VALUES (
                :event_key, :event_name, :source_area, :financial_effect,
                :debit_account_id, :credit_account_id, :rule_note, :sort_order, 0, :is_active
             )'
        );
        $statement->execute($data);

        return (int) $this->db->lastInsertId();
    }

    public function postingRuleDeleteBlockedReason(int $id): ?string
    {
        $rule = $this->findPostingRule($id);

        if ($rule === null) {
            return 'Posting rule not found.';
        }

        return (int) ($rule['is_system'] ?? 0) === 1 ? 'System posting rules cannot be deleted.' : null;
    }

    public function deletePostingRule(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM posting_rules WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function accountOptions(): array
    {
        $statement = $this->db->query(
            'SELECT id, code, name
             FROM chart_of_accounts
             WHERE is_active = 1
             ORDER BY code ASC'
        );

        return $statement->fetchAll() ?: [];
    }

    private function countByValue(string $table, string $column, int $value): int
    {
        $statement = $this->db->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE %s = :value', $table, $column));
        $statement->execute(['value' => $value]);

        return (int) $statement->fetchColumn();
    }
}
