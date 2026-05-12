<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

final class Gate
{
    private array $abilities = [];

    public function define(string $ability, Closure $callback): void
    {
        $this->abilities[$ability] = $callback;
    }

    public function allows(string $ability, mixed ...$arguments): bool
    {
        if (! isset($this->abilities[$ability])) {
            return false;
        }

        return (bool) ($this->abilities[$ability])(...$arguments);
    }
}
