<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use cieplik206\BirRegon\Enums\NipStatus;
use Spatie\LaravelData\Data;

final class EntityIdentityData extends Data
{
    public function __construct(
        public ?string $regon,
        public ?string $nip,
        public ?NipStatus $nipStatus,
        public ?string $name,
        public ?string $shortName,
        public ?PersonNameData $personName,
    ) {}
}
