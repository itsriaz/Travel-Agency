<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

abstract class Service
{
    public function __construct(protected readonly App $app)
    {
    }
}
