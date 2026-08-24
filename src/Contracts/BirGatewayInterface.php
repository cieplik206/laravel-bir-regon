<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Contracts;

use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Protocol\DiagnosticsSnapshot;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use DateTimeImmutable;

interface BirGatewayInterface
{
    /** @return list<SearchResult> */
    public function search(#[\SensitiveParameter] SearchCriteria $criteria): array;

    /** @return list<array<string, string>> */
    public function fullReport(
        #[\SensitiveParameter] string $regon,
        ReportType $reportType,
    ): array;

    /** @return list<string> */
    public function bulkReport(DateTimeImmutable $date, BulkReportType $reportType): array;

    public function getValue(GetValueParameter $parameter): string;

    public function diagnostics(): DiagnosticsSnapshot;

    public function logout(): bool;
}
