<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use cieplik206\BirRegon\Enums\ActivityStatus;
use DateTimeImmutable;
use Spatie\LaravelData\Data;

final class ActivityLifecycleData extends Data
{
    public function __construct(
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $registeredInRegonAt,
        public ?DateTimeImmutable $changedAt,
        public ?DateTimeImmutable $suspendedAt,
        public ?DateTimeImmutable $resumedAt,
        public ?DateTimeImmutable $endedAt,
        public ?DateTimeImmutable $removedFromRegonAt,
        public ?DateTimeImmutable $bankruptcyDeclaredAt,
        public ?DateTimeImmutable $bankruptcyProceedingsEndedAt,
        public ActivityStatus $status,
    ) {}
}
