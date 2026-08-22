<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data;

use cieplik206\BirRegon\Enums\BulkReportType;
use DateTimeImmutable;
use Spatie\LaravelData\Data;

class BulkReportData extends Data
{
    /**
     * @param  array<int, string>  $reportData
     */
    public function __construct(
        public DateTimeImmutable $date,
        public BulkReportType $reportType,
        public array $reportData,
    ) {}
}
