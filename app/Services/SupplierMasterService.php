<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\SupplierRepository;
use RuntimeException;

final class SupplierMasterService
{
    public function __construct(private readonly App $app)
    {
    }

    public function update(
        int $supplierId,
        array $input,
        int $actorUserId,
        array $accessibleBranchIds
    ): array {
        $accessibleBranchIds = array_values(array_unique(array_filter(array_map('intval', $accessibleBranchIds))));
        $repository = new SupplierRepository($this->app);
        $supplier = $repository->findManageableSupplier($supplierId, $accessibleBranchIds);
        if ($supplier === null) {
            throw new RuntimeException('Supplier record was not found or is outside your branch access.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        $branchId = (int) ($input['branch_id'] ?? 0);
        $currency = strtoupper(trim((string) ($input['default_currency'] ?? '')));
        $contactPerson = trim((string) ($input['contact_person'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $isActive = (string) ($input['is_active'] ?? '1') === '1' ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 190) {
            throw new RuntimeException('Enter a supplier name up to 190 characters.');
        }
        if ($branchId <= 0 || ! in_array($branchId, $accessibleBranchIds, true)) {
            throw new RuntimeException('Select an accessible supplier branch.');
        }
        if (! in_array($currency, ['PKR', 'AED', 'USD'], true)) {
            throw new RuntimeException('Select a valid default supplier currency.');
        }
        if (mb_strlen($contactPerson) > 190) {
            throw new RuntimeException('Contact person is too long.');
        }
        if (mb_strlen($phone) > 50) {
            throw new RuntimeException('Supplier phone is too long.');
        }
        if (mb_strlen($email) > 190 || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new RuntimeException('Enter a valid supplier email address.');
        }
        if (mb_strlen($address) > 500) {
            throw new RuntimeException('Supplier address is too long.');
        }
        if (mb_strlen($notes) > 4000) {
            throw new RuntimeException('Supplier notes are too long.');
        }
        if ($repository->supplierNameBelongsToAnotherRecord($name, $supplierId)) {
            throw new RuntimeException('Another supplier already uses this name. Open that supplier instead of creating a duplicate identity.');
        }

        return $repository->updateSupplierMaster($supplierId, [
            'branch_id' => $branchId,
            'name' => $name,
            'default_currency' => $currency,
            'contact_person' => $contactPerson !== '' ? $contactPerson : null,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'address' => $address !== '' ? $address : null,
            'is_active' => $isActive,
            'notes' => $notes !== '' ? $notes : null,
            'actor_user_id' => $actorUserId,
        ]);
    }
}

