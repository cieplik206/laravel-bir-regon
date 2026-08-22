<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Tests\Support;

use cieplik206\BirRegon\BirClientInterface;
use cieplik206\BirRegon\Data\BulkReportData;
use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\DiagnosticsData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Data\ServiceStatusData;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\ReportType;
use DateTimeImmutable;
use RuntimeException;

class StubBirClient implements BirClientInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    public ?Environment $lastEnvironment = null;

    /** @param  array<int, CompanyData>  $companies */
    public function __construct(
        private readonly ?CompanyData $company = null,
        private readonly ?FullCompanyReportData $report = null,
        private readonly ?BirClientInterface $scoped = null,
        private readonly array $companies = [],
        private readonly ?BulkReportData $bulkReport = null,
        private readonly ?ServiceStatusData $serviceStatus = null,
        private readonly ?DateTimeImmutable $dataStatus = null,
        private readonly ?DiagnosticsData $diagnostics = null,
    ) {}

    public function withEnvironment(Environment $environment): BirClientInterface
    {
        $this->lastEnvironment = $environment;

        return $this->scoped ?? $this;
    }

    public function searchByNip(string $nip): CompanyData
    {
        $this->calls[] = ['searchByNip', $nip];

        return $this->company ?? throw new RuntimeException('Company not configured.');
    }

    public function searchByRegon(string $regon): CompanyData
    {
        $this->calls[] = ['searchByRegon', $regon];

        return $this->company ?? throw new RuntimeException('Company not configured.');
    }

    public function searchByKrs(string $krs): CompanyData
    {
        $this->calls[] = ['searchByKrs', $krs];

        return $this->company ?? throw new RuntimeException('Company not configured.');
    }

    public function searchByNips(array $nips): array
    {
        $this->calls[] = ['searchByNips', $nips];

        return $this->configuredCompanies();
    }

    public function searchByKrsNumbers(array $krsNumbers): array
    {
        $this->calls[] = ['searchByKrsNumbers', $krsNumbers];

        return $this->configuredCompanies();
    }

    public function searchByRegons9(array $regons): array
    {
        $this->calls[] = ['searchByRegons9', $regons];

        return $this->configuredCompanies();
    }

    public function searchByRegons14(array $regons): array
    {
        $this->calls[] = ['searchByRegons14', $regons];

        return $this->configuredCompanies();
    }

    public function getFullReport(string $regon, ReportType $reportType): FullCompanyReportData
    {
        $this->calls[] = ['getFullReport', $regon, $reportType];

        return $this->report ?? throw new RuntimeException('Report not configured.');
    }

    public function getBulkReport(DateTimeImmutable $date, BulkReportType $reportType): BulkReportData
    {
        $this->calls[] = ['getBulkReport', $date, $reportType];

        return $this->bulkReport ?? throw new RuntimeException('Bulk report not configured.');
    }

    public function getServiceStatus(): ServiceStatusData
    {
        $this->calls[] = ['getServiceStatus'];

        return $this->serviceStatus ?? throw new RuntimeException('Service status not configured.');
    }

    public function getDataStatus(): DateTimeImmutable
    {
        $this->calls[] = ['getDataStatus'];

        return $this->dataStatus ?? throw new RuntimeException('Data status not configured.');
    }

    public function getDiagnostics(): DiagnosticsData
    {
        $this->calls[] = ['getDiagnostics'];

        return $this->diagnostics ?? throw new RuntimeException('Diagnostics not configured.');
    }

    /** @return array<int, CompanyData> */
    private function configuredCompanies(): array
    {
        if ($this->companies !== []) {
            return $this->companies;
        }

        if ($this->company !== null) {
            return [$this->company];
        }

        throw new RuntimeException('Companies not configured.');
    }
}
