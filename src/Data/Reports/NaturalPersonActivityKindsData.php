<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use Spatie\LaravelData\Data;

final class NaturalPersonActivityKindsData extends Data
{
    public function __construct(
        public ?int $ceidgCount,
        public ?int $agricultureCount,
        public ?int $otherCount,
        public ?int $deletedBefore20141108Count,
    ) {}
}
