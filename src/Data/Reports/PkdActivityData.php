<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use cieplik206\BirRegon\Enums\Silo;
use DateTimeImmutable;
use Spatie\LaravelData\Data;

final class PkdActivityData extends Data
{
    public function __construct(
        public ?string $classification,
        public ?string $code,
        public ?string $name,
        public ?bool $predominant,
        public ?Silo $silo,
        public ?string $siloSymbol,
        public ?DateTimeImmutable $removedFromRegonAt,
    ) {}
}
