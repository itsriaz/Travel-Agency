<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BookingRepository;

final class BranchContextService extends Service
{
    public function resolveLoginActiveBranchId(int $storedDefaultBranchId, array $accessibleBranchIds): int
    {
        $branchOptions = (new BookingRepository($this->app))->branchOptions(array_map('intval', $accessibleBranchIds));
        if ($branchOptions === []) {
            return $storedDefaultBranchId > 0 ? $storedDefaultBranchId : 0;
        }

        $normalizedDefaultBranchId = 0;
        foreach ($branchOptions as $branchOption) {
            if ((int) ($branchOption['id'] ?? 0) === $storedDefaultBranchId) {
                $normalizedDefaultBranchId = $storedDefaultBranchId;
                break;
            }
        }

        $overrideBranchId = $this->resolveOverrideBranchId($branchOptions);
        if ($overrideBranchId > 0) {
            return $overrideBranchId;
        }

        $countryBranchId = $this->resolveCountryBranchId($branchOptions);
        if ($countryBranchId > 0) {
            return $countryBranchId;
        }

        if ($normalizedDefaultBranchId > 0) {
            return $normalizedDefaultBranchId;
        }

        return (int) ($branchOptions[0]['id'] ?? 0);
    }

    private function resolveOverrideBranchId(array $branchOptions): int
    {
        $overrides = config('branches.login.ip_overrides', []);
        if (! is_array($overrides) || $overrides === []) {
            return 0;
        }

        $branchByCode = [];
        $branchById = [];
        foreach ($branchOptions as $branchOption) {
            $branchId = (int) ($branchOption['id'] ?? 0);
            if ($branchId <= 0) {
                continue;
            }

            $branchById[(string) $branchId] = $branchId;
            $branchCode = mb_strtolower(trim((string) ($branchOption['code'] ?? '')));
            if ($branchCode !== '') {
                $branchByCode[$branchCode] = $branchId;
            }
        }

        foreach ($this->clientIpCandidates() as $ipAddress) {
            $overrideTarget = trim((string) ($overrides[$ipAddress] ?? ''));
            if ($overrideTarget === '') {
                continue;
            }

            if (isset($branchById[$overrideTarget])) {
                return $branchById[$overrideTarget];
            }

            $overrideCode = mb_strtolower($overrideTarget);
            if (isset($branchByCode[$overrideCode])) {
                return $branchByCode[$overrideCode];
            }
        }

        return 0;
    }

    private function resolveCountryBranchId(array $branchOptions): int
    {
        $countryCode = $this->detectedCountryCode();
        if ($countryCode === '') {
            return 0;
        }

        foreach ($branchOptions as $branchOption) {
            if (mb_strtoupper(trim((string) ($branchOption['country_code'] ?? ''))) !== $countryCode) {
                continue;
            }

            $branchId = (int) ($branchOption['id'] ?? 0);
            if ($branchId > 0) {
                return $branchId;
            }
        }

        return 0;
    }

    private function detectedCountryCode(): string
    {
        $headerNames = config('branches.login.country_headers', []);
        if (! is_array($headerNames)) {
            return '';
        }

        foreach ($headerNames as $headerName) {
            $normalizedHeaderName = trim((string) $headerName);
            if ($normalizedHeaderName === '') {
                continue;
            }

            $value = trim((string) ($_SERVER[$normalizedHeaderName] ?? $_ENV[$normalizedHeaderName] ?? ''));
            if ($value === '') {
                continue;
            }

            $countryCode = mb_strtoupper(substr($value, 0, 2));
            if (preg_match('/^[A-Z]{2}$/', $countryCode) === 1) {
                return $countryCode;
            }
        }

        return '';
    }

    private function clientIpCandidates(): array
    {
        $candidates = [];
        $serverKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

        foreach ($serverKeys as $serverKey) {
            $rawValue = trim((string) ($_SERVER[$serverKey] ?? ''));
            if ($rawValue === '') {
                continue;
            }

            foreach (explode(',', $rawValue) as $segment) {
                $candidate = trim($segment);
                if ($candidate === '') {
                    continue;
                }

                $candidates[] = $candidate;
            }
        }

        return array_values(array_unique($candidates));
    }
}
