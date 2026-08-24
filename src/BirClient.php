<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use cieplik206\BirRegon\Contracts\BirGatewayInterface;
use cieplik206\BirRegon\Data\BulkReportData;
use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\DiagnosticsData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Data\ServiceStatusData;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\IdentifierValidationMode;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirAmbiguousResultException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameterValue;
use Throwable;

class BirClient implements BirClientInterface
{
    use PreventsSerialization;

    private readonly SensitiveParameterValue $gateway;

    public function __construct(
        #[\SensitiveParameter] BirGatewayInterface $gateway,
        private readonly IdentifierValidationMode $identifierValidationMode = IdentifierValidationMode::FormatOnly,
    ) {
        $this->gateway = new SensitiveParameterValue($gateway);
    }

    /** @return list<CompanyData> */
    public function searchByNip(#[\SensitiveParameter] string $nip): array
    {
        $this->ensureNotRestoredFromSerialization();

        return $this->searchMany(
            SearchCriteria::nip($nip, validateChecksum: $this->validatesChecksums()),
            'NIP',
        );
    }

    /** @return list<CompanyData> */
    public function searchByRegon(#[\SensitiveParameter] string $regon): array
    {
        $this->ensureNotRestoredFromSerialization();

        return $this->searchMany(
            SearchCriteria::regon($regon, validateChecksum: $this->validatesChecksums()),
            'REGON',
        );
    }

    /** @return list<CompanyData> */
    public function searchByKrs(#[\SensitiveParameter] string $krs): array
    {
        $this->ensureNotRestoredFromSerialization();

        return $this->searchMany(SearchCriteria::krs($krs), 'KRS');
    }

    public function searchByNips(#[\SensitiveParameter] array $nips): array
    {
        $this->ensureNotRestoredFromSerialization();

        return $nips === []
            ? []
            : $this->searchMany(
                SearchCriteria::nips(
                    array_values($nips),
                    validateChecksum: $this->validatesChecksums(),
                ),
                'NIP',
            );
    }

    public function searchByKrsNumbers(#[\SensitiveParameter] array $krsNumbers): array
    {
        $this->ensureNotRestoredFromSerialization();

        return $krsNumbers === []
            ? []
            : $this->searchMany(
                SearchCriteria::krsNumbers(array_values($krsNumbers)),
                'KRS',
            );
    }

    public function searchByRegons9(#[\SensitiveParameter] array $regons): array
    {
        $this->ensureNotRestoredFromSerialization();

        return $regons === []
            ? []
            : $this->searchMany(
                SearchCriteria::regons9(
                    array_values($regons),
                    validateChecksum: $this->validatesChecksums(),
                ),
                'REGON9',
            );
    }

    public function searchByRegons14(#[\SensitiveParameter] array $regons): array
    {
        $this->ensureNotRestoredFromSerialization();

        return $regons === []
            ? []
            : $this->searchMany(
                SearchCriteria::regons14(
                    array_values($regons),
                    validateChecksum: $this->validatesChecksums(),
                ),
                'REGON14',
            );
    }

    public function getFullReportByNip(
        #[\SensitiveParameter] string $nip,
        ReportType $reportType,
    ): FullCompanyReportData {
        $this->ensureNotRestoredFromSerialization();

        return $this->getFullReportFromSearch(
            SearchCriteria::nip($nip, validateChecksum: $this->validatesChecksums()),
            'NIP',
            $reportType,
        );
    }

    /** @return list<FullCompanyReportData> */
    public function getFullReportsByNip(
        #[\SensitiveParameter] string $nip,
        ReportType $reportType,
    ): array {
        $this->ensureNotRestoredFromSerialization();

        return $this->getFullReportsFromSearch(
            SearchCriteria::nip($nip, validateChecksum: $this->validatesChecksums()),
            'NIP',
            $reportType,
        );
    }

    public function getFullReportByKrs(
        #[\SensitiveParameter] string $krs,
        ReportType $reportType,
    ): FullCompanyReportData {
        $this->ensureNotRestoredFromSerialization();

        return $this->getFullReportFromSearch(SearchCriteria::krs($krs), 'KRS', $reportType);
    }

    /** @return list<FullCompanyReportData> */
    public function getFullReportsByKrs(
        #[\SensitiveParameter] string $krs,
        ReportType $reportType,
    ): array {
        $this->ensureNotRestoredFromSerialization();

        return $this->getFullReportsFromSearch(SearchCriteria::krs($krs), 'KRS', $reportType);
    }

    public function getFullReport(
        #[\SensitiveParameter] string $regon,
        ReportType $reportType,
    ): FullCompanyReportData {
        $this->ensureNotRestoredFromSerialization();

        return $this->getFullReportFromSearch(
            SearchCriteria::regon($regon, validateChecksum: $this->validatesChecksums()),
            'REGON',
            $reportType,
        );
    }

    /** @return list<FullCompanyReportData> */
    public function getFullReports(
        #[\SensitiveParameter] string $regon,
        ReportType $reportType,
    ): array {
        $this->ensureNotRestoredFromSerialization();

        return $this->getFullReportsFromSearch(
            SearchCriteria::regon($regon, validateChecksum: $this->validatesChecksums()),
            'REGON',
            $reportType,
        );
    }

    public function getBulkReport(DateTimeImmutable $date, BulkReportType $reportType): BulkReportData
    {
        $this->ensureNotRestoredFromSerialization();
        $date = $this->normalizeBulkReportDate($date);

        try {
            return new BulkReportData($date, $reportType, $this->gateway()->bulkReport($date, $reportType));
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BirException('GUS BIR bulk report operation failed.');
        }
    }

    public function getServiceStatus(): ServiceStatusData
    {
        $this->ensureNotRestoredFromSerialization();

        try {
            $gateway = $this->gateway();
            $rawStatus = $gateway->getValue(GetValueParameter::ServiceStatus);

            if (preg_match('/^[012]$/D', $rawStatus) !== 1) {
                throw new BirProtocolException('GUS BIR returned an invalid service status.');
            }

            return new ServiceStatusData(
                status: (int) $rawStatus,
                message: $gateway->getValue(GetValueParameter::ServiceMessage),
            );
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BirException('GUS BIR service status operation failed.');
        }
    }

    public function getDataStatus(): DateTimeImmutable
    {
        $this->ensureNotRestoredFromSerialization();

        try {
            $rawStatus = $this->gateway()->getValue(GetValueParameter::DataStatus);
            $timeZone = new DateTimeZone('Europe/Warsaw');
            $status = DateTimeImmutable::createFromFormat('!d-m-Y', $rawStatus, $timeZone);
            $errors = DateTimeImmutable::getLastErrors();

            if (
                $status === false
                || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
                || $status->format('d-m-Y') !== $rawStatus
            ) {
                throw new BirProtocolException('GUS BIR returned an invalid data status.');
            }

            return $status;
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BirException('GUS BIR data status operation failed.');
        }
    }

    public function getDiagnostics(): DiagnosticsData
    {
        $this->ensureNotRestoredFromSerialization();

        try {
            $snapshot = $this->gateway()->diagnostics();

            if (
                $snapshot->messageCode < 0
                || ! in_array($snapshot->sessionStatus, [0, 1], true)
            ) {
                throw new BirProtocolException('GUS BIR returned invalid diagnostics.');
            }

            return new DiagnosticsData(
                messageCode: $snapshot->messageCode,
                message: $snapshot->message,
                sessionStatus: $snapshot->sessionStatus,
            );
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BirException('GUS BIR diagnostics operation failed.');
        }
    }

    public function logout(): bool
    {
        $this->ensureNotRestoredFromSerialization();

        try {
            return $this->gateway()->logout();
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BirException('GUS BIR logout operation failed.');
        }
    }

    private function validatesChecksums(): bool
    {
        return $this->identifierValidationMode->validatesChecksum();
    }

    /** @return list<CompanyData> */
    private function searchMany(
        #[\SensitiveParameter] SearchCriteria $criteria,
        string $type,
    ): array {
        $reports = $this->searchResults($criteria, $type);

        return array_map(CompanyData::fromSearchResult(...), $reports);
    }

    /** @return list<SearchResult> */
    private function searchResults(
        #[\SensitiveParameter] SearchCriteria $criteria,
        string $type,
    ): array {
        try {
            $reports = $this->gateway()->search($criteria);

            if ($reports === []) {
                throw new BirNotFoundException($type);
            }

            return $reports;
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BirException('GUS BIR search operation failed.');
        }
    }

    private function getFullReportFromSearch(
        #[\SensitiveParameter] SearchCriteria $criteria,
        string $identifierType,
        ReportType $reportType,
    ): FullCompanyReportData {
        $compatibleReports = $this->uniqueReportRequests(
            $this->compatibleReportsFromSearch(
                $criteria,
                $identifierType,
                $reportType,
            ),
            $reportType,
        );

        if (count($compatibleReports) > 1) {
            throw new BirAmbiguousResultException(
                $identifierType,
                count($compatibleReports),
            );
        }

        return $this->fetchFullReport($compatibleReports[0], $reportType);
    }

    /** @return list<FullCompanyReportData> */
    private function getFullReportsFromSearch(
        #[\SensitiveParameter] SearchCriteria $criteria,
        string $identifierType,
        ReportType $reportType,
    ): array {
        $compatibleReports = $this->uniqueReportRequests(
            $this->compatibleReportsFromSearch(
                $criteria,
                $identifierType,
                $reportType,
            ),
            $reportType,
        );

        $fullReports = [];

        foreach ($compatibleReports as $report) {
            $fullReports[] = $this->fetchFullReport($report, $reportType);
        }

        return $fullReports;
    }

    /**
     * @param  non-empty-list<SearchResult>  $reports
     * @return non-empty-list<SearchResult>
     */
    private function uniqueReportRequests(
        #[\SensitiveParameter] array $reports,
        ReportType $reportType,
    ): array {
        $uniqueReports = [];

        foreach ($reports as $report) {
            $reportRegon = $reportType->reportRegon($report);

            if (! array_key_exists($reportRegon, $uniqueReports)) {
                $uniqueReports[$reportRegon] = $report;
            }
        }

        return array_values($uniqueReports);
    }

    /** @return non-empty-list<SearchResult> */
    private function compatibleReportsFromSearch(
        #[\SensitiveParameter] SearchCriteria $criteria,
        string $identifierType,
        ReportType $reportType,
    ): array {
        $compatibleReports = array_values(array_filter(
            $this->searchResults($criteria, $identifierType),
            $reportType->supports(...),
        ));

        if ($compatibleReports === []) {
            throw new BirValidationException(
                "Report {$reportType->value} is not compatible with the returned entity type and silo.",
            );
        }

        return $compatibleReports;
    }

    private function fetchFullReport(
        #[\SensitiveParameter]
        SearchResult $report,
        ReportType $reportType,
    ): FullCompanyReportData {
        try {
            $reportData = $this->gateway()->fullReport(
                $reportType->reportRegon($report),
                $reportType,
            );

            return FullCompanyReportData::fromSearchResult($report, $reportType, $reportData);
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BirException('GUS BIR full report operation failed.');
        }
    }

    private function normalizeBulkReportDate(DateTimeImmutable $date): DateTimeImmutable
    {
        $timeZone = new DateTimeZone('Europe/Warsaw');
        $today = new DateTimeImmutable('today', $timeZone);
        $localizedDate = $date->getTimezone()->getName() === $timeZone->getName()
            ? $date
            : $date->setTimezone($timeZone);
        $reportDate = $localizedDate->format('H:i:s.u') === '00:00:00.000000'
            ? $localizedDate
            : new DateTimeImmutable($localizedDate->format('Y-m-d'), $timeZone);

        if ($reportDate >= $today || $reportDate < $today->modify('-7 days')) {
            throw new BirValidationException(
                'Bulk report date must be between yesterday and seven days ago.',
            );
        }

        return $reportDate;
    }

    private function gateway(): BirGatewayInterface
    {
        $gateway = $this->gateway->getValue();

        if (! $gateway instanceof BirGatewayInterface) {
            throw new \LogicException('The BIR gateway is unavailable.');
        }

        return $gateway;
    }
}
