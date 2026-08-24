<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

final readonly class BirErrorData
{
    public function __construct(
        public int $code,
        public string $message,
    ) {}
}
