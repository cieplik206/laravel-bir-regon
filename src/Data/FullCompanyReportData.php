<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data;

use GusApi\SearchReport;
use Spatie\LaravelData\Data;

class FullCompanyReportData extends Data
{
    /**
     * @param  array<int, array<string, string>>  $reportData
     */
    public function __construct(
        public CompanyData $basicData,
        public array $reportData,
    ) {}

    /**
     * @param  array<int, array<string, string>>  $reportData
     */
    public static function fromGusApiReport(SearchReport $report, array $reportData): self
    {
        return new self(
            basicData: CompanyData::fromGusApiResult($report),
            reportData: $reportData,
        );
    }
}
