<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use Spatie\LaravelData\Data;

final class LegalFormData extends Data
{
    public function __construct(
        public ?CodeNameData $basic,
        public ?CodeNameData $specific,
        public ?CodeNameData $financing,
        public ?CodeNameData $ownership,
        public ?CodeNameData $foundingBody,
    ) {}
}
