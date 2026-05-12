<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\BaseController;

final class Router
{
    private array $routes = [];

    public function __construct(private readonly App $app)
    {
    }

    public function get(string $uri, array $action, array $middleware = []): void
    {
        $this->addRoute('GET', $uri, $action, $middleware);
    }

    public function post(string $uri, array $action, array $middleware = []): void
    {
        $this->addRoute('POST', $uri, $action, $middleware);
    }

    public function dispatch(string $method, string $uri): void
    {
        $route = $this->routes[$method][$uri] ?? null;

        if ($route === null) {
            http_response_code(404);
            echo View::make($this->app->basePath('/app/Views/errors/404.php'), ['title' => 'Not Found']);
            return;
        }

        foreach ($route['middleware'] as $middlewareClass) {
            (new $middlewareClass($this->app))->handle();
        }

        [$controllerClass, $controllerMethod] = $route['action'];
        /** @var BaseController $controller */
        $controller = new $controllerClass($this->app);
        echo $controller->{$controllerMethod}();
    }

    private function addRoute(string $method, string $uri, array $action, array $middleware): void
    {
        $this->routes[$method][$uri] = [
            'action' => $action,
            'middleware' => $middleware,
        ];
    }
}
