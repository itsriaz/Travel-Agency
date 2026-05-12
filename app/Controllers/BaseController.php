<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\View;

abstract class BaseController
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function view(string $view, array $data = [], string $layout = 'layouts/app'): string
    {
        /** @var View $renderer */
        $renderer = $this->app->get('view');

        return $renderer->render($view, $data, $layout);
    }

    protected function redirect(string $path): never
    {
        header('Location: ' . url($path));
        exit;
    }

    protected function jsonResponse(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
