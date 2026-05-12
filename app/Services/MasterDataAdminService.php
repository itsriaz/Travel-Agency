<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\MasterDataRepository;
use RuntimeException;

final class MasterDataAdminService extends Service
{
    public function save(string $register, array $input, int $actorUserId): array
    {
        $repository = new MasterDataRepository($this->app);
        $id = (int) ($input['id'] ?? 0);
        $existing = $id > 0 ? $repository->find($register, $id) : null;

        if ($id > 0 && $existing === null) {
            throw new RuntimeException('The selected register row could not be found.');
        }

        $payload = $this->validatedPayload($register, $input, $existing);

        if ($repository->codeExists($register, (string) $payload['code'], $id > 0 ? $id : null)) {
            throw new RuntimeException('This code is already in use in the selected register.');
        }

        $savedId = $repository->save($register, array_merge($payload, ['id' => $id]));
        $action = $existing === null ? 'created' : 'updated';

        AuditLog::record($this->app, 'admin.master_data.' . $action, [
            'user_id' => $actorUserId,
            'register' => $register,
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

    public function delete(string $register, int $id, int $actorUserId): array
    {
        $repository = new MasterDataRepository($this->app);
        $record = $repository->find($register, $id);

        if ($record === null) {
            throw new RuntimeException('The selected register row could not be found.');
        }

        $blockedReason = $repository->deleteBlockedReason($register, $id);

        if ($blockedReason !== null) {
            throw new RuntimeException($blockedReason);
        }

        $repository->delete($register, $id);

        AuditLog::record($this->app, 'admin.master_data.deleted', [
            'user_id' => $actorUserId,
            'register' => $register,
            'record_id' => $id,
            'code' => $record['code'] ?? null,
            'name' => $record['name'] ?? null,
        ]);

        return [
            'label' => (string) ($record['name'] ?? $record['code'] ?? 'Record'),
        ];
    }

    private function validatedPayload(string $register, array $input, ?array $existing): array
    {
        return match ($register) {
            'branches' => $this->validateBranch($input),
            'currencies' => $this->validateCurrency($input, $existing),
            'service_types' => $this->validateServiceType($input, $existing),
            'payment_methods' => $this->validatePaymentMethod($input, $existing),
            'supplier_modes' => $this->validateSupplierMode($input, $existing),
            'document_types' => $this->validateDocumentType($input, $existing),
            default => throw new RuntimeException('Unknown master-data register.'),
        };
    }

    private function validateBranch(array $input): array
    {
        $code = $this->normalizeCode((string) ($input['code'] ?? ''), 'lower');
        $name = $this->requiredText($input['name'] ?? null, 'Branch name', 190);
        $city = $this->optionalText($input['city'] ?? null, 120);
        $countryCode = $this->normalizeCountryCode((string) ($input['country_code'] ?? ''));
        $baseCurrency = $this->normalizeCurrencyCode((string) ($input['base_currency'] ?? ''));

        return [
            'code' => $this->assertPattern($code, '/^[a-z0-9_-]{2,50}$/', 'Branch code must use lowercase letters, numbers, dashes, or underscores.'),
            'name' => $name,
            'city' => $city,
            'country_code' => $countryCode,
            'base_currency' => $baseCurrency,
            'is_active' => $this->normalizeBoolean($input['is_active'] ?? 1),
        ];
    }

    private function validateCurrency(array $input, ?array $existing): array
    {
        $code = $this->normalizeCurrencyCode((string) ($input['code'] ?? ''));
        $payload = [
            'code' => $code,
            'name' => $this->requiredText($input['name'] ?? null, 'Currency name', 120),
            'symbol' => $this->optionalText($input['symbol'] ?? null, 10),
            'reporting_role' => $this->requiredText($input['reporting_role'] ?? null, 'Reporting role', 190),
            'sort_order' => $this->normalizeSortOrder($input['sort_order'] ?? 0),
            'is_active' => $this->normalizeBoolean($input['is_active'] ?? 1),
        ];

        $this->assertSystemRowEditable($existing, $payload['code'], $payload['is_active']);

        return $payload;
    }

    private function validateServiceType(array $input, ?array $existing): array
    {
        $code = $this->normalizeCode((string) ($input['code'] ?? ''), 'upper');
        $payload = [
            'code' => $this->assertPattern($code, '/^[A-Z0-9_-]{2,20}$/', 'Service type code must use uppercase letters, numbers, dashes, or underscores.'),
            'name' => $this->requiredText($input['name'] ?? null, 'Service type name', 120),
            'posting_mode' => $this->requiredText($input['posting_mode'] ?? null, 'Posting mode', 190),
            'sort_order' => $this->normalizeSortOrder($input['sort_order'] ?? 0),
            'is_active' => $this->normalizeBoolean($input['is_active'] ?? 1),
        ];

        $this->assertSystemRowEditable($existing, $payload['code'], $payload['is_active']);

        return $payload;
    }

    private function validatePaymentMethod(array $input, ?array $existing): array
    {
        $code = $this->normalizeCode((string) ($input['code'] ?? ''), 'lower');
        $payload = [
            'code' => $this->assertPattern($code, '/^[a-z0-9_]{2,50}$/', 'Payment method code must use lowercase letters, numbers, or underscores.'),
            'name' => $this->requiredText($input['name'] ?? null, 'Payment method name', 120),
            'ledger_target' => $this->requiredText($input['ledger_target'] ?? null, 'Ledger target', 120),
            'charges_target' => $this->optionalText($input['charges_target'] ?? null, 120),
            'sort_order' => $this->normalizeSortOrder($input['sort_order'] ?? 0),
            'is_active' => $this->normalizeBoolean($input['is_active'] ?? 1),
        ];

        $this->assertSystemRowEditable($existing, $payload['code'], $payload['is_active']);

        return $payload;
    }

    private function validateSupplierMode(array $input, ?array $existing): array
    {
        $code = $this->normalizeCode((string) ($input['code'] ?? ''), 'lower');
        $payload = [
            'code' => $this->assertPattern($code, '/^[a-z0-9_]{2,50}$/', 'Supplier mode code must use lowercase letters, numbers, or underscores.'),
            'name' => $this->requiredText($input['name'] ?? null, 'Supplier mode name', 120),
            'behavior' => $this->requiredText($input['behavior'] ?? null, 'Behavior', 190),
            'sort_order' => $this->normalizeSortOrder($input['sort_order'] ?? 0),
            'is_active' => $this->normalizeBoolean($input['is_active'] ?? 1),
        ];

        $this->assertSystemRowEditable($existing, $payload['code'], $payload['is_active']);

        return $payload;
    }

    private function validateDocumentType(array $input, ?array $existing): array
    {
        $code = $this->normalizeCode((string) ($input['code'] ?? ''), 'lower');
        $payload = [
            'code' => $this->assertPattern($code, '/^[a-z0-9_]{2,50}$/', 'Document type code must use lowercase letters, numbers, or underscores.'),
            'name' => $this->requiredText($input['name'] ?? null, 'Document type name', 120),
            'linked_area' => $this->requiredText($input['linked_area'] ?? null, 'Linked area', 120),
            'sort_order' => $this->normalizeSortOrder($input['sort_order'] ?? 0),
            'is_active' => $this->normalizeBoolean($input['is_active'] ?? 1),
        ];

        $this->assertSystemRowEditable($existing, $payload['code'], $payload['is_active']);

        return $payload;
    }

    private function assertSystemRowEditable(?array $existing, string $newCode, int $isActive): void
    {
        if ($existing === null || (int) ($existing['is_system'] ?? 0) !== 1) {
            return;
        }

        if ((string) ($existing['code'] ?? '') !== $newCode) {
            throw new RuntimeException('System register codes cannot be changed.');
        }

        if ($isActive !== 1) {
            throw new RuntimeException('System register rows must remain active.');
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
            throw new RuntimeException('One of the optional values exceeds the allowed length.');
        }

        return $text;
    }

    private function normalizeCode(string $value, string $mode): string
    {
        $normalized = trim($value);

        return match ($mode) {
            'upper' => mb_strtoupper($normalized),
            'lower' => mb_strtolower($normalized),
            default => $normalized,
        };
    }

    private function normalizeCurrencyCode(string $value): string
    {
        $code = mb_strtoupper(trim($value));

        return $this->assertPattern($code, '/^[A-Z]{3}$/', 'Currency code must be a three-letter ISO-style code.');
    }

    private function normalizeCountryCode(string $value): string
    {
        $code = mb_strtoupper(trim($value));

        return $this->assertPattern($code, '/^[A-Z]{2}$/', 'Country code must be a two-letter ISO-style code.');
    }

    private function normalizeSortOrder(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function normalizeBoolean(mixed $value): int
    {
        return (int) ((string) $value === '0' ? 0 : 1);
    }

    private function assertPattern(string $value, string $pattern, string $message): string
    {
        if (! preg_match($pattern, $value)) {
            throw new RuntimeException($message);
        }

        return $value;
    }
}
