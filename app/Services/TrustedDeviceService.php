<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\TrustedDeviceRepository;

final class TrustedDeviceService extends Service
{
    public function shouldOfferRememberDevice(): bool
    {
        return ! \App\Helpers\Auth::isSuperAdmin();
    }

    public function createForCurrentUser(int $userId): void
    {
        if (\App\Helpers\Auth::isSuperAdmin()) {
            return;
        }

        $selector = bin2hex(random_bytes(9));
        $token = bin2hex(random_bytes(32));
        $tokenHash = password_hash($token, PASSWORD_DEFAULT);
        $deviceLabel = $this->deviceLabel();
        $userAgentHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $validDays = min((int) config('security.trusted_device.max_days', 7), 7);

        (new TrustedDeviceRepository($this->app))->create(
            $userId,
            $selector,
            $tokenHash,
            $deviceLabel,
            $userAgentHash,
            $ipAddress,
            $validDays
        );

        $cookieValue = $selector . ':' . $token;
        setcookie(config('security.trusted_device.cookie_name', 'travel_ops_trusted_device'), $cookieValue, [
            'expires' => time() + ($validDays * 86400),
            'path' => '/',
            'secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        AuditLog::record($this->app, 'auth.trusted_device.created', [
            'user_id' => $userId,
            'device_label' => $deviceLabel,
        ]);
    }

    public function canBypassOtp(int $userId): bool
    {
        $cookieName = (string) config('security.trusted_device.cookie_name', 'travel_ops_trusted_device');
        $cookieValue = (string) ($_COOKIE[$cookieName] ?? '');

        if ($cookieValue === '' || ! str_contains($cookieValue, ':')) {
            return false;
        }

        [$selector, $token] = explode(':', $cookieValue, 2);
        $device = (new TrustedDeviceRepository($this->app))->findActiveBySelector($userId, $selector);

        if ($device === null) {
            $this->clearCookie();
            return false;
        }

        $currentUserAgentHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        if (! hash_equals((string) $device['user_agent_hash'], $currentUserAgentHash)) {
            $this->clearCookie();
            return false;
        }

        if (! password_verify($token, (string) $device['token_hash'])) {
            $this->clearCookie();
            return false;
        }

        (new TrustedDeviceRepository($this->app))->touchUsage((int) $device['id'], $_SERVER['REMOTE_ADDR'] ?? null);
        AuditLog::record($this->app, 'auth.trusted_device.used', [
            'user_id' => $userId,
            'device_id' => (int) $device['id'],
        ]);

        return true;
    }

    public function revokeAllForUser(int $userId, string $reason, int $actorUserId): void
    {
        (new TrustedDeviceRepository($this->app))->revokeAllForUser($userId);

        AuditLog::record($this->app, 'auth.trusted_device.revoked', [
            'user_id' => $actorUserId,
            'target_user_id' => $userId,
            'reason' => $reason,
        ]);

        if ($userId === \App\Helpers\Auth::id()) {
            $this->clearCookie();
        }
    }

    public function revokeDevice(int $userId, int $deviceId, string $reason, int $actorUserId): void
    {
        (new TrustedDeviceRepository($this->app))->revokeById($userId, $deviceId);

        AuditLog::record($this->app, 'auth.trusted_device.revoked', [
            'user_id' => $actorUserId,
            'target_user_id' => $userId,
            'device_id' => $deviceId,
            'reason' => $reason,
        ]);

        if ($userId === \App\Helpers\Auth::id()) {
            $this->clearCookie();
        }
    }

    public function clearCookie(): void
    {
        setcookie(config('security.trusted_device.cookie_name', 'travel_ops_trusted_device'), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function deviceLabel(): string
    {
        $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device'));
        return mb_substr($agent, 0, 120);
    }
}
