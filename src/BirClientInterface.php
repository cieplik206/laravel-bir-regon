<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\BulkReportData;
use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\DiagnosticsData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Data\ServiceStatusData;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirAmbiguousResultException;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use DateTimeImmutable;

interface BirClientInterface
{
    /**
     * @return list<CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByNip(#[\SensitiveParameter] string $nip): array;

    /**
     * @return list<CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByRegon(#[\SensitiveParameter] string $regon): array;

    /**
     * @return list<CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByKrs(#[\SensitiveParameter] string $krs): array;

    /**
     * @param  array<int, string>  $nips
     * @return list<CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByNips(#[\SensitiveParameter] array $nips): array;

    /**
     * @param  array<int, string>  $krsNumbers
     * @return list<CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByKrsNumbers(#[\SensitiveParameter] array $krsNumbers): array;

    /**
     * @param  array<int, string>  $regons
     * @return list<CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByRegons9(#[\SensitiveParameter] array $regons): array;

    /**
     * @param  array<int, string>  $regons
     * @return list<CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByRegons14(#[\SensitiveParameter] array $regons): array;

    /**
     * @return list<FullCompanyReportData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getFullReportsByNip(#[\SensitiveParameter] string $nip, ReportType $reportType): array;

    /**
     * @return list<FullCompanyReportData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getFullReportsByKrs(#[\SensitiveParameter] string $krs, ReportType $reportType): array;

    /**
     * @return list<FullCompanyReportData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getFullReports(#[\SensitiveParameter] string $regon, ReportType $reportType): array;

    /**
     * @throws BirAmbiguousResultException
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getFullReportByNip(#[\SensitiveParameter] string $nip, ReportType $reportType): FullCompanyReportData;

    /**
     * @throws BirAmbiguousResultException
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getFullReportByKrs(#[\SensitiveParameter] string $krs, ReportType $reportType): FullCompanyReportData;

    /**
     * @throws BirAmbiguousResultException
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getFullReport(#[\SensitiveParameter] string $regon, ReportType $reportType): FullCompanyReportData;

    /**
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getBulkReport(DateTimeImmutable $date, BulkReportType $reportType): BulkReportData;

    /** @throws BirException */
    public function getServiceStatus(): ServiceStatusData;

    /**
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getDataStatus(): DateTimeImmutable;

    /**
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getDiagnostics(): DiagnosticsData;

    /** @throws BirException */
    public function logout(): bool;
}
