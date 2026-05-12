<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;

abstract class Middleware
{
    public function __construct(protected readonly App $app)
    {
    }

    abstract public function handle(): void;
}
