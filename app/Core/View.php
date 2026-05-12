<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public function __construct(private readonly App $app)
    {
    }

    public function render(string $view, array $data = [], string $layout = 'layouts/app'): string
    {
        $viewPath = $this->resolve($view);
        $content = self::make($viewPath, array_merge($data, ['app' => $this->app]));

        return self::make($this->resolve($layout), array_merge($data, [
            'app' => $this->app,
            'content' => $content,
        ]));
    }

    public static function make(string $path, array $data = []): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require $path;
        return (string) ob_get_clean();
    }

    private function resolve(string $view): string
    {
        return $this->app->basePath('/app/Views/' . str_replace('.', '/', $view) . '.php');
    }
}
