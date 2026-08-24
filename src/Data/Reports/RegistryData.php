<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

final class RegistryData extends Data
{
    public function __construct(
        #[\SensitiveParameter] public ?string $number,
        public ?DateTimeImmutable $enteredAt,
        public ?DateTimeImmutable $removedAt,
        public ?CodeNameData $authority,
        public ?CodeNameData $type,
        public ?bool $notStarted,
    ) {}
}
