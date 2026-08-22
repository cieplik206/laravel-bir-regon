<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data;

use Spatie\LaravelData\Data;

class ServiceStatusData extends Data
{
    public function __construct(
        public int $status,
        public string $message,
    ) {}

    public function isAvailable(): bool
    {
        return $this->status === 1;
    }
}
