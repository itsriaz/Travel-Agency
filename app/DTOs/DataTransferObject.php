<?php

declare(strict_types=1);

namespace App\DTOs;

abstract class DataTransferObject
{
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
