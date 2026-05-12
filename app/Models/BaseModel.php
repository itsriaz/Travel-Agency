<?php

declare(strict_types=1);

namespace App\Models;

abstract class BaseModel
{
    public function __construct(protected array $attributes = [])
    {
    }

    public function fill(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
