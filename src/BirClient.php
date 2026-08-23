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
use GusApi\Exception\InvalidUserKeyException;
use GusApi\Exception\NotFoundException;
use GusApi\GusApi;
use GusApi\SearchReport;
use Throwable;

class BirClient implements BirClientInterface
{
    private ?GusApi $api = null;

    private bool $loggedIn = false;

    private string $apiKey;

    private Environment $environment;

    public function __construct(
        private readonly GusApiFactoryInterface $gusApiFactory,
        ?string $apiKey = null,
        ?Environment $environment = null,
    ) {
        $apiKey ??= (string) config('bir-regon.api_key', '');
        $this->apiKey = $apiKey;
        $this->environment = $environment ?? Environment::Production;
    }

    public function searchByNip(string $nip): CompanyData
    {
        return $this->searchOne(fn (GusApi $api) => $api->getByNip($nip), $nip, 'NIP');
    }

    public function searchByRegon(string $regon): CompanyData
    {
        return $this->searchOne(fn (GusApi $api) => $api->getByRegon($regon), $regon, 'REGON');
    }

    public function searchByKrs(string $krs): CompanyData
    {
        return $this->searchOne(fn (GusApi $api) => $api->getByKrs($krs), $krs, 'KRS');
    }

    /**
     * @param  array<int, string>  $nips
     * @return array<int, CompanyData>
     */
    public function searchByNips(array $nips): array
    {
        return $this->searchMany(
            fn (GusApi $api) => $api->getByNips($nips),
            $nips,
            'NIP',
        );
    }

    /**
     * @param  array<int, string>  $krsNumbers
     * @return array<int, CompanyData>
     */
    public function searchByKrsNumbers(array $krsNumbers): array
    {
        return $this->searchMany(
            fn (GusApi $api) => $api->getByKrses($krsNumbers),
            $krsNumbers,
            'KRS',
        );
    }

    /**
     * @param  array<int, string>  $regons
     * @return array<int, CompanyData>
     */
    public function searchByRegons9(array $regons): array
    {
        return $this->searchMany(
            fn (GusApi $api) => $api->getByRegons9($regons),
            $regons,
            'REGON9',
        );
    }

    /**
     * @param  array<int, string>  $regons
     * @return array<int, CompanyData>
     */
    public function searchByRegons14(array $regons): array
    {
        return $this->searchMany(
            fn (GusApi $api) => $api->getByregons14($regons),
            $regons,
            'REGON14',
        );
    }

    public function getFullReportByNip(string $nip, ReportType $reportType): FullCompanyReportData
    {
        return $this->getFullReportFromSearch(
            fn (GusApi $api) => $api->getByNip($nip),
            $nip,
            'NIP',
            $reportType,
        );
    }

    public function getFullReportByKrs(string $krs, ReportType $reportType): FullCompanyReportData
    {
        return $this->getFullReportFromSearch(
            fn (GusApi $api) => $api->getByKrs($krs),
            $krs,
            'KRS',
            $reportType,
        );
    }

    public function getFullReport(string $regon, ReportType $reportType): FullCompanyReportData
    {
        return $this->getFullReportFromSearch(
            fn (GusApi $api) => $api->getByRegon($regon),
            $regon,
            'REGON',
            $reportType,
        );
    }

    public function getBulkReport(DateTimeImmutable $date, BulkReportType $reportType): BulkReportData
    {
        return $this->execute(function () use ($date, $reportType): BulkReportData {
            $reportData = $this->executeWithSessionRecovery(
                fn (GusApi $api): array => $api->getBulkReport($date, $reportType->value),
            );

            return new BulkReportData($date, $reportType, $reportData);
        });
    }

    public function getServiceStatus(): ServiceStatusData
    {
        return $this->execute(function (): ServiceStatusData {
            $api = $this->getUnauthenticatedApi();

            return new ServiceStatusData(
                status: $api->serviceStatus(),
                message: $api->serviceMessage(),
            );
        });
    }

    public function getDataStatus(): DateTimeImmutable
    {
        return $this->execute(
            fn (): DateTimeImmutable => $this->executeWithSessionRecovery(
                fn (GusApi $api): DateTimeImmutable => $api->dataStatus(),
            ),
        );
    }

    public function getDiagnostics(): DiagnosticsData
    {
        return $this->execute(
            fn (): DiagnosticsData => $this->executeWithSessionRecovery(
                fn (GusApi $api): DiagnosticsData => new DiagnosticsData(
                    messageCode: $api->getMessageCode(),
                    message: $api->getMessage(),
                    sessionStatus: $api->getSessionStatus(),
                ),
            ),
        );
    }

    /**
     * @param  callable(GusApi): array<SearchReport>  $search
     */
    private function searchOne(callable $search, string $identifier, string $type): CompanyData
    {
        $reports = $this->searchReports($search, $identifier, $type);

        return CompanyData::fromGusApiResult($reports[0]);
    }

    /**
     * @param  callable(GusApi): array<SearchReport>  $search
     * @param  array<int, string>  $identifiers
     * @return array<int, CompanyData>
     */
    private function searchMany(callable $search, array $identifiers, string $type): array
    {
        if ($identifiers === []) {
            return [];
        }

        $reports = $this->searchReports($search, implode(', ', $identifiers), $type);

        return array_map(
            static fn (SearchReport $report): CompanyData => CompanyData::fromGusApiResult($report),
            $reports,
        );
    }

    /**
     * @param  callable(GusApi): array<SearchReport>  $search
     * @return array<SearchReport>
     */
    private function searchReports(callable $search, string $identifier, string $type): array
    {
        try {
            $reports = $this->executeWithSessionRecovery($search);

            if ($reports === []) {
                throw new BirNotFoundException($identifier, $type);
            }

            return $reports;
        } catch (NotFoundException) {
            throw new BirNotFoundException($identifier, $type);
        } catch (InvalidUserKeyException $exception) {
            $this->loggedIn = false;

            throw new BirAuthenticationException('Invalid API key', 0, $exception);
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BirException('GUS API error: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param  callable(GusApi): array<SearchReport>  $search
     */
    private function getFullReportFromSearch(
        callable $search,
        string $identifier,
        string $identifierType,
        ReportType $reportType,
    ): FullCompanyReportData {
        $reports = $this->searchReports($search, $identifier, $identifierType);
        $report = $reports[0];

        return $this->execute(function () use ($report, $reportType): FullCompanyReportData {
            $reportData = $this->executeWithSessionRecovery(
                fn (GusApi $api): array => $api->getFullReport($report, $reportType->value),
            );

            return FullCompanyReportData::fromGusApiReport($report, $reportData);
        });
    }

    /**
     * @template TResult
     *
     * @param  callable(GusApi): TResult  $operation
     * @return TResult
     */
    private function executeWithSessionRecovery(callable $operation): mixed
    {
        $api = $this->getAuthenticatedApi();

        try {
            return $operation($api);
        } catch (InvalidUserKeyException|BirException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (! $this->sessionHasExpired($api)) {
                throw $exception;
            }
        }

        $this->resetAuthenticatedApi();

        return $operation($this->getAuthenticatedApi());
    }

    private function sessionHasExpired(GusApi $api): bool
    {
        try {
            return ! $api->isLogged();
        } catch (Throwable) {
            return false;
        }
    }

    private function resetAuthenticatedApi(): void
    {
        $this->api = null;
        $this->loggedIn = false;
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @return TResult
     */
    private function execute(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (InvalidUserKeyException $exception) {
            $this->loggedIn = false;

            throw new BirAuthenticationException('Invalid API key', 0, $exception);
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BirException('GUS API error: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function getAuthenticatedApi(): GusApi
    {
        $this->ensureLoggedIn();

        return $this->getInitializedApi();
    }

    private function getUnauthenticatedApi(): GusApi
    {
        if ($this->api !== null) {
            return $this->api;
        }

        try {
            $this->api = $this->gusApiFactory->make($this->apiKey, $this->environment);
        } catch (Throwable $exception) {
            throw new BirException('Failed to connect to GUS API: '.$exception->getMessage(), 0, $exception);
        }

        return $this->api;
    }

    private function getInitializedApi(): GusApi
    {
        if ($this->api === null) {
            throw new BirException('GUS API client is not initialized.');
        }

        return $this->api;
    }

    private function ensureLoggedIn(): void
    {
        if ($this->apiKey === '') {
            throw new BirAuthenticationException(
                'BIR API key is not configured. Set BIR_API_KEY in your .env file.',
            );
        }

        if ($this->loggedIn && $this->api !== null) {
            return;
        }

        try {
            $api = $this->getUnauthenticatedApi();
            $api->login();
            $this->loggedIn = true;
        } catch (InvalidUserKeyException $exception) {
            throw new BirAuthenticationException('Invalid API key', 0, $exception);
        } catch (BirException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BirException('Failed to connect to GUS API: '.$exception->getMessage(), 0, $exception);
        }
    }
}
