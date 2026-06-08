<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Authorization;
use App\Helpers\Csrf;
use App\Services\OfflineWorkspaceService;
use RuntimeException;

final class OfflineController extends BaseController
{
    public function ping(): never
    {
        $this->jsonResponse([
            'ok' => true,
            'server_time' => date(DATE_ATOM),
        ]);
    }

    public function snapshot(): never
    {
        $service = new OfflineWorkspaceService($this->app);
        $this->jsonResponse([
            'ok' => true,
            'csrf_token' => Csrf::token(),
            'user' => [
                'id' => Auth::id(),
                'name' => (string) (Auth::user()['name'] ?? ''),
                'role' => Auth::roleCode(),
                'active_branch_id' => Auth::activeBranchId(),
            ],
            'snapshot' => $service->snapshot(Authorization::accessibleBranchIds(), (int) Auth::id()),
        ]);
    }

    public function syncDrafts(): never
    {
        $payload = $this->jsonPayload();
        Csrf::verifyOrFail((string) ($payload['_token'] ?? ''));

        $drafts = $payload['drafts'] ?? [];
        if (! is_array($drafts)) {
            throw new RuntimeException('Draft payload is invalid.');
        }

        $sourceDevice = trim((string) ($payload['source_device'] ?? 'Windows launcher'));
        $sourceDevice = mb_substr($sourceDevice !== '' ? $sourceDevice : 'Windows launcher', 0, 120);

        $results = (new OfflineWorkspaceService($this->app))->syncDrafts(
            $drafts,
            (int) Auth::id(),
            Authorization::accessibleBranchIds(),
            $sourceDevice
        );

        $this->jsonResponse([
            'ok' => true,
            'results' => $results,
        ]);
    }

    private function jsonPayload(): array
    {
        $raw = (string) file_get_contents('php://input');
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Expected a JSON request body.');
        }

        return $decoded;
    }
}
