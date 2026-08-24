<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data;

use cieplik206\BirRegon\Data\Reports\NormalizedFullReportData;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Normalization\FullReportNormalizer;
use cieplik206\BirRegon\Protocol\SearchResult;
use Spatie\LaravelData\Data;

class FullCompanyReportData extends Data
{
    /**
     * @param  list<array<string, string>>  $reportData
     */
    public function __construct(
        #[\SensitiveParameter]
        public CompanyData $basicData,
        public ReportType $reportType,
        #[\SensitiveParameter]
        public array $reportData,
        #[\SensitiveParameter]
        public NormalizedFullReportData $normalized,
    ) {}

    /**
     * @param  list<array<string, string>>  $reportData
     */
    public static function fromSearchResult(
        #[\SensitiveParameter]
        SearchResult $report,
        ReportType $reportType,
        #[\SensitiveParameter]
        array $reportData,
    ): self {
        return new self(
            basicData: CompanyData::fromSearchResult($report),
            reportType: $reportType,
            reportData: $reportData,
            normalized: (new FullReportNormalizer)->normalize($reportType, $reportData),
        );
    }
}
