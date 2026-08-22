<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data;

use Spatie\LaravelData\Data;

class DiagnosticsData extends Data
{
    public function __construct(
        public int $messageCode,
        public string $message,
        public int $sessionStatus,
    ) {}
}
