<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\BulkReportData;
use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\DiagnosticsData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Data\ServiceStatusData;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use DateTimeImmutable;

interface BirClientInterface
{
    public function withEnvironment(Environment $environment): BirClientInterface;

    /**
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByNip(string $nip): CompanyData;

    /**
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByRegon(string $regon): CompanyData;

    /**
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByKrs(string $krs): CompanyData;

    /**
     * @param  array<int, string>  $nips
     * @return array<int, CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByNips(array $nips): array;

    /**
     * @param  array<int, string>  $krsNumbers
     * @return array<int, CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByKrsNumbers(array $krsNumbers): array;

    /**
     * @param  array<int, string>  $regons
     * @return array<int, CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByRegons9(array $regons): array;

    /**
     * @param  array<int, string>  $regons
     * @return array<int, CompanyData>
     *
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function searchByRegons14(array $regons): array;

    /**
     * @throws BirNotFoundException
     * @throws BirAuthenticationException
     * @throws BirException
     */
    public function getFullReport(string $regon, ReportType $reportType): FullCompanyReportData;

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
}
