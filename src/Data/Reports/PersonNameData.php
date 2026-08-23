<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use Spatie\LaravelData\Data;

final class PersonNameData extends Data
{
    public function __construct(
        public ?string $firstName,
        public ?string $secondName,
        public ?string $lastName,
    ) {}
}
