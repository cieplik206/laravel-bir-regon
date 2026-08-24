<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use Spatie\LaravelData\Data;

final class PartnerData extends Data
{
    public function __construct(
        #[\SensitiveParameter] public ?string $regon,
        public ?PersonNameData $personName,
        public ?string $companyName,
    ) {}
}
