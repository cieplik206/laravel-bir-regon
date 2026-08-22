<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use GusApi\BulkReportTypes;
use GusApi\ReportTypes;

it('exposes every full report type supported by gusapi', function (): void {
    $actual = array_map(
        static fn (ReportType $reportType): string => $reportType->value,
        ReportType::cases(),
    );
    $expected = ReportTypes::REPORTS;

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

it('exposes every bulk report type supported by gusapi', function (): void {
    $actual = array_map(
        static fn (BulkReportType $reportType): string => $reportType->value,
        BulkReportType::cases(),
    );
    $expected = BulkReportTypes::REPORTS;

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});
