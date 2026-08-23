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
use cieplik206\BirRegon\Enums\ReportType;
use DateTimeImmutable;
use RuntimeException;

class StubBirClient implements BirClientInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    /** @param  list<CompanyData>  $companies */
    public function __construct(
        private readonly ?CompanyData $company = null,
        private readonly ?FullCompanyReportData $report = null,
        private readonly array $companies = [],
        private readonly ?BulkReportData $bulkReport = null,
        private readonly ?ServiceStatusData $serviceStatus = null,
        private readonly ?DateTimeImmutable $dataStatus = null,
        private readonly ?DiagnosticsData $diagnostics = null,
        /** @var list<FullCompanyReportData> */
        private readonly array $reports = [],
    ) {}

    /** @return list<CompanyData> */
    public function searchByNip(string $nip): array
    {
        $this->calls[] = ['searchByNip', $nip];

        return $this->configuredCompanies();
    }

    /** @return list<CompanyData> */
    public function searchByRegon(string $regon): array
    {
        $this->calls[] = ['searchByRegon', $regon];

        return $this->configuredCompanies();
    }

    /** @return list<CompanyData> */
    public function searchByKrs(string $krs): array
    {
        $this->calls[] = ['searchByKrs', $krs];

        return $this->configuredCompanies();
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

    /** @return list<FullCompanyReportData> */
    public function getFullReports(string $regon, ReportType $reportType): array
    {
        $this->calls[] = ['getFullReports', $regon, $reportType];

        return $this->configuredReports();
    }

    public function getFullReportByNip(string $nip, ReportType $reportType): FullCompanyReportData
    {
        $this->calls[] = ['getFullReportByNip', $nip, $reportType];

        return $this->report ?? throw new RuntimeException('Report not configured.');
    }

    /** @return list<FullCompanyReportData> */
    public function getFullReportsByNip(string $nip, ReportType $reportType): array
    {
        $this->calls[] = ['getFullReportsByNip', $nip, $reportType];

        return $this->configuredReports();
    }

    public function getFullReportByKrs(string $krs, ReportType $reportType): FullCompanyReportData
    {
        $this->calls[] = ['getFullReportByKrs', $krs, $reportType];

        return $this->report ?? throw new RuntimeException('Report not configured.');
    }

    /** @return list<FullCompanyReportData> */
    public function getFullReportsByKrs(string $krs, ReportType $reportType): array
    {
        $this->calls[] = ['getFullReportsByKrs', $krs, $reportType];

        return $this->configuredReports();
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

    public function logout(): bool
    {
        $this->calls[] = ['logout'];

        return true;
    }

    /** @return list<CompanyData> */
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

    /** @return list<FullCompanyReportData> */
    private function configuredReports(): array
    {
        if ($this->reports !== []) {
            return $this->reports;
        }

        if ($this->report !== null) {
            return [$this->report];
        }

        throw new RuntimeException('Reports not configured.');
    }
}
