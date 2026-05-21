<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\HealthCheckService;

final class HealthController extends BaseController
{
    public function show(): never
    {
        $expectedToken = trim((string) config('security.health.token', ''));
        $providedToken = trim((string) ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? $_GET['token'] ?? ''));

        if (app_is_production() && $expectedToken === '') {
            $this->jsonResponse([
                'status' => 'fail',
                'message' => 'Health check token is not configured.',
            ], 503);
        }

        if ($expectedToken !== '' && ! hash_equals($expectedToken, $providedToken)) {
            $this->jsonResponse([
                'status' => 'forbidden',
                'message' => 'Invalid health check token.',
            ], 403);
        }

        $payload = (new HealthCheckService($this->app))->run();

        $this->jsonResponse($payload, $payload['status'] === 'ok' ? 200 : 503);
    }
}
