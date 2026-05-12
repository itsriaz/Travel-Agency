<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\AccountingSetupRepository;
use RuntimeException;

final class AccountingSetupService extends Service
{
    public function save(string $register, array $input, int $actorUserId): array
    {
        return match ($register) {
            'control_accounts' => $this->saveControlAccount($input, $actorUserId),
            'posting_rules' => $this->savePostingRule($input, $actorUserId),
            default => throw new RuntimeException('Unknown accounting register.'),
        };
    }

    public function delete(string $register, int $id, int $actorUserId): array
    {
        return match ($register) {
            'control_accounts' => $this->deleteControlAccount($id, $actorUserId),
            'posting_rules' => $this->deletePostingRule($id, $actorUserId),
            default => throw new RuntimeException('Unknown accounting register.'),
        };
    }

    private function saveControlAccount(array $input, int $actorUserId): array
    {
        $repository = new AccountingSetupRepository($this->app);
        $id = (int) ($input['id'] ?? 0);
        $existing = $id > 0 ? $repository->findControlAccount($id) : null;

        if ($id > 0 && $existing === null) {
            throw new RuntimeException('The selected control account could not be found.');
        }

        $payload = [
            'code' => $this->normalizeAccountCode((string) ($input['code'] ?? '')),
            'name' => $this->requiredText($input['name'] ?? null, 'Account name', 190),
            'purpose' => $this->optionalText($input['purpose'] ?? null, 255),
            'account_type' => $this->assertChoice((string) ($input['account_type'] ?? ''), ['asset', 'liability', 'equity', 'revenue', 'expense'], 'Account type is invalid.'),
            'normal_balance' => $this->assertChoice((string) ($input['normal_balance'] ?? ''), ['debit', 'credit'], 'Normal balance is invalid.'),
            'is_active' => $this->normalizeBoolean($input['is_active'] ?? 1),
        ];

        if ($existing !== null && (int) ($existing['is_system'] ?? 0) === 1) {
            if ((string) ($existing['code'] ?? '') !== $payload['code']) {
                throw new RuntimeException('System control account codes cannot be changed.');
            }

            if ($payload['is_active'] !== 1) {
                throw new RuntimeException('System control accounts must remain active.');
            }
        }

        if ($repository->controlAccountCodeExists($payload['code'], $id > 0 ? $id : null)) {
            throw new RuntimeException('This account code already exists.');
        }

        $savedId = $repository->saveControlAccount(array_merge($payload, ['id' => $id]));
        $action = $existing === null ? 'created' : 'updated';

        AuditLog::record($this->app, 'admin.accounting_setup.' . $action, [
            'user_id' => $actorUserId,
            'register' => 'control_accounts',
            'record_id' => $savedId,
            'code' => $payload['code'],
            'name' => $payload['name'],
        ]);

        return [
            'id' => $savedId,
            'action' => $action,
            'label' => $payload['name'],
        ];
    }

    private function deleteControlAccount(int $id, int $actorUserId): array
    {
        $repository = new AccountingSetupRepository($this->app);
        $account = $repository->findControlAccount($id);

        if ($account === null) {
            throw new RuntimeException('The selected control account could not be found.');
        }

        $blockedReason = $repository->controlAccountDeleteBlockedReason($id);

        if ($blockedReason !== null) {
            throw new RuntimeException($blockedReason);
        }

        $repository->deleteControlAccount($id);

        AuditLog::record($this->app, 'admin.accounting_setup.deleted', [
            'user_id' => $actorUserId,
            'register' => 'control_accounts',
            'record_id' => $id,
            'code' => $account['code'] ?? null,
            'name' => $account['name'] ?? null,
        ]);

        return [
            'label' => (string) ($account['name'] ?? $account['code'] ?? 'Account'),
        ];
    }

    private function savePostingRule(array $input, int $actorUserId): array
    {
        $repository = new AccountingSetupRepository($this->app);
        $id = (int) ($input['id'] ?? 0);
        $existing = $id > 0 ? $repository->findPostingRule($id) : null;

        if ($id > 0 && $existing === null) {
            throw new RuntimeException('The selected posting rule could not be found.');
        }

        $payload = [
            'event_key' => $this->normalizeEventKey((string) ($input['event_key'] ?? '')),
            'event_name' => $this->requiredText($input['event_name'] ?? null, 'Event name', 190),
            'source_area' => $this->requiredText($input['source_area'] ?? null, 'Source area', 120),
            'financial_effect' => $this->requiredText($input['financial_effect'] ?? null, 'Financial effect', 255),
            'debit_account_id' => $this->positiveId($input['debit_account_id'] ?? 0, 'Debit account'),
            'credit_account_id' => $this->positiveId($input['credit_account_id'] ?? 0, 'Credit account'),
            'rule_note' => $this->optionalText($input['rule_note'] ?? null, 2000),
            'sort_order' => max(0, (int) ($input['sort_order'] ?? 0)),
            'is_active' => $this->normalizeBoolean($input['is_active'] ?? 1),
        ];

        if ($payload['debit_account_id'] === $payload['credit_account_id']) {
            throw new RuntimeException('Debit and credit accounts must be different.');
        }

        if ($repository->findControlAccount($payload['debit_account_id']) === null || $repository->findControlAccount($payload['credit_account_id']) === null) {
            throw new RuntimeException('Selected debit or credit account is invalid.');
        }

        if ($existing !== null && (int) ($existing['is_system'] ?? 0) === 1) {
            if ((string) ($existing['event_key'] ?? '') !== $payload['event_key']) {
                throw new RuntimeException('System posting rule keys cannot be changed.');
            }

            if ($payload['is_active'] !== 1) {
                throw new RuntimeException('System posting rules must remain active.');
            }
        }

        if ($repository->postingRuleKeyExists($payload['event_key'], $id > 0 ? $id : null)) {
            throw new RuntimeException('This posting rule key already exists.');
        }

        $savedId = $repository->savePostingRule(array_merge($payload, ['id' => $id]));
        $action = $existing === null ? 'created' : 'updated';

        AuditLog::record($this->app, 'admin.accounting_setup.' . $action, [
            'user_id' => $actorUserId,
            'register' => 'posting_rules',
            'record_id' => $savedId,
            'event_key' => $payload['event_key'],
            'event_name' => $payload['event_name'],
        ]);

        return [
            'id' => $savedId,
            'action' => $action,
            'label' => $payload['event_name'],
        ];
    }

    private function deletePostingRule(int $id, int $actorUserId): array
    {
        $repository = new AccountingSetupRepository($this->app);
        $rule = $repository->findPostingRule($id);

        if ($rule === null) {
            throw new RuntimeException('The selected posting rule could not be found.');
        }

        $blockedReason = $repository->postingRuleDeleteBlockedReason($id);

        if ($blockedReason !== null) {
            throw new RuntimeException($blockedReason);
        }

        $repository->deletePostingRule($id);

        AuditLog::record($this->app, 'admin.accounting_setup.deleted', [
            'user_id' => $actorUserId,
            'register' => 'posting_rules',
            'record_id' => $id,
            'event_key' => $rule['event_key'] ?? null,
            'event_name' => $rule['event_name'] ?? null,
        ]);

        return [
            'label' => (string) ($rule['event_name'] ?? $rule['event_key'] ?? 'Posting rule'),
        ];
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
            throw new RuntimeException('One of the optional values exceeds the allowed length.');
        }

        return $text;
    }

    private function normalizeAccountCode(string $value): string
    {
        $code = mb_strtoupper(trim($value));

        if (! preg_match('/^[A-Z0-9_-]{2,50}$/', $code)) {
            throw new RuntimeException('Account code must use uppercase letters, numbers, dashes, or underscores.');
        }

        return $code;
    }

    private function normalizeEventKey(string $value): string
    {
        $eventKey = mb_strtolower(trim($value));

        if (! preg_match('/^[a-z0-9_]{3,100}$/', $eventKey)) {
            throw new RuntimeException('Event key must use lowercase letters, numbers, or underscores.');
        }

        return $eventKey;
    }

    private function assertChoice(string $value, array $allowed, string $message): string
    {
        if (! in_array($value, $allowed, true)) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private function normalizeBoolean(mixed $value): int
    {
        return (int) ((string) $value === '0' ? 0 : 1);
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = (int) $value;

        if ($id <= 0) {
            throw new RuntimeException($label . ' is required.');
        }

        return $id;
    }
}
