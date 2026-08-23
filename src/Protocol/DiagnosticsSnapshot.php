<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

final readonly class DiagnosticsSnapshot
{
    public function __construct(
        public int $messageCode,
        public string $message,
        public int $sessionStatus,
    ) {}
}
