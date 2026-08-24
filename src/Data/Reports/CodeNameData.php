<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use Spatie\LaravelData\Data;

final class CodeNameData extends Data
{
    public function __construct(
        public ?string $code,
        public ?string $name,
    ) {}
}
