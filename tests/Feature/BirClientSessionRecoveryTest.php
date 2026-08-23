<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Tests\Support\StubGusApiFactory;
use GusApi\Exception\InvalidServerResponseException;
use GusApi\Exception\NotFoundException;
use GusApi\GusApi;
use GusApi\SearchReport;
use GusApi\Type\Response\SearchResponseCompanyData;

it('renews an expired session in a long-lived client and retries the failed search once', function (): void {
    $api = new ExpiringSessionGusApi([makeSessionRecoverySearchReport()]);
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');

    $initialResult = $client->searchByNip('1111111111');
    $api->expireSession();
    $recoveredResult = $client->searchByNip('2222222222');

    expect($initialResult->regon)->toBe('123456789')
        ->and($recoveredResult->regon)->toBe('123456789')
        ->and($api->loginCalls)->toBe(2)
        ->and($api->searchedNips)->toBe([
            '1111111111',
            '2222222222',
            '2222222222',
        ]);
});

it('renews an expired session for authenticated operations outside the search flow', function (): void {
    $dataStatus = new DateTimeImmutable('2026-08-23');
    $api = new ExpiringSessionGusApi(
        searchReports: [makeSessionRecoverySearchReport()],
        dataStatus: $dataStatus,
    );
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');

    $initialStatus = $client->getDataStatus();
    $api->expireSession();
    $recoveredStatus = $client->getDataStatus();

    expect($initialStatus)->toBe($dataStatus)
        ->and($recoveredStatus)->toBe($dataStatus)
        ->and($api->loginCalls)->toBe(2)
        ->and($api->dataStatusCalls)->toBe(3);
});

it('renews an expired session when a bulk report silently returns empty data', function (): void {
    $api = new ExpiringSessionGusApi(
        searchReports: [makeSessionRecoverySearchReport()],
        bulkReportData: ['123456789'],
    );
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');
    $reportType = BulkReportType::NewLegalEntitiesAndNaturalPersons;

    $initialReport = $client->getBulkReport(new DateTimeImmutable('2026-08-22'), $reportType);
    $api->expireSession();
    $recoveredReport = $client->getBulkReport(new DateTimeImmutable('2026-08-23'), $reportType);

    expect($initialReport->reportData)->toBe(['123456789'])
        ->and($recoveredReport->reportData)->toBe(['123456789'])
        ->and($api->loginCalls)->toBe(2)
        ->and($api->bulkReportCalls)->toBe(3);
});

it('does not renew an active session for a genuinely empty bulk report', function (): void {
    $api = new ExpiringSessionGusApi([makeSessionRecoverySearchReport()]);
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');

    $report = $client->getBulkReport(
        new DateTimeImmutable('2026-08-23'),
        BulkReportType::NewLegalEntitiesAndNaturalPersons,
    );

    expect($report->reportData)->toBe([])
        ->and($api->loginCalls)->toBe(1)
        ->and($api->bulkReportCalls)->toBe(1);
});

it('renews a session that expires between a search and a full report', function (): void {
    $api = new ExpiringSessionGusApi(
        searchReports: [makeSessionRecoverySearchReport()],
        fullReportData: [['praw_regon9' => '123456789']],
    );
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');
    $api->expireAfterNextSearch();

    $report = $client->getFullReportByNip('1111111111', ReportType::Organization);

    expect($report->reportData)->toBe([['praw_regon9' => '123456789']])
        ->and($api->loginCalls)->toBe(2)
        ->and($api->fullReportCalls)->toBe(2);
});

it('retries an expired session only once when the replacement session also expires', function (): void {
    $api = new ExpiringSessionGusApi([makeSessionRecoverySearchReport()]);
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');

    $client->searchByNip('1111111111');
    $api->expireSession();
    $api->expireImmediatelyAfterNextLogin();

    expect(fn () => $client->searchByNip('2222222222'))
        ->toThrow(BirException::class, 'GUS API error: GUS session expired.')
        ->and($api->loginCalls)->toBe(2)
        ->and($api->searchedNips)->toBe([
            '1111111111',
            '2222222222',
            '2222222222',
        ]);
});

it('does not retry or renew an active session after an ordinary API failure', function (): void {
    $api = new ExpiringSessionGusApi([makeSessionRecoverySearchReport()]);
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');

    $client->searchByNip('1111111111');
    $api->failNextSearchWith(new RuntimeException('Temporary transport error.'));

    expect(fn () => $client->searchByNip('2222222222'))
        ->toThrow(BirException::class, 'GUS API error: Temporary transport error.');

    $resultAfterFailure = $client->searchByNip('3333333333');

    expect($resultAfterFailure->regon)->toBe('123456789')
        ->and($api->loginCalls)->toBe(1)
        ->and($api->searchedNips)->toBe([
            '1111111111',
            '2222222222',
            '3333333333',
        ]);
});

it('checks the remote session once after a not-found response that may indicate expiry', function (): void {
    $api = new ExpiringSessionGusApi([makeSessionRecoverySearchReport()]);
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');
    $api->failNextSearchWith(new NotFoundException('No data found'));

    expect(fn () => $client->searchByNip('1111111111'))
        ->toThrow(BirNotFoundException::class)
        ->and($api->loginCalls)->toBe(1)
        ->and($api->sessionStatusCalls)->toBe(1);
});

it('does not query the remote session status after local batch validation fails', function (): void {
    $api = new ExpiringSessionGusApi([makeSessionRecoverySearchReport()]);
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');

    expect(fn () => $client->searchByNips(array_fill(0, 21, '1111111111')))
        ->toThrow(BirException::class, 'GUS API error: Too many identifiers. Maximum allowed is 20.')
        ->and($api->loginCalls)->toBe(1)
        ->and($api->sessionStatusCalls)->toBe(0);
});

class ExpiringSessionGusApi extends GusApi
{
    public int $bulkReportCalls = 0;

    public int $dataStatusCalls = 0;

    public int $fullReportCalls = 0;

    public int $loginCalls = 0;

    public int $sessionStatusCalls = 0;

    /** @var list<string> */
    public array $searchedNips = [];

    private bool $expireAfterNextLogin = false;

    private bool $expireAfterSearch = false;

    private ?Throwable $nextSearchFailure = null;

    private bool $sessionActive = false;

    /**
     * @param  list<SearchReport>  $searchReports
     * @param  list<string>  $bulkReportData
     * @param  array<int, array<string, string>>  $fullReportData
     */
    public function __construct(
        private readonly array $searchReports,
        private readonly DateTimeImmutable $dataStatus = new DateTimeImmutable('2026-08-23'),
        private readonly array $bulkReportData = [],
        private readonly array $fullReportData = [],
    ) {}

    public function login(): bool
    {
        $this->loginCalls++;
        $this->sessionActive = ! $this->expireAfterNextLogin;
        $this->expireAfterNextLogin = false;

        return true;
    }

    public function isLogged(): bool
    {
        $this->sessionStatusCalls++;

        return $this->sessionActive;
    }

    public function getByNip(string $nip): array
    {
        $this->searchedNips[] = $nip;

        if (! $this->sessionActive) {
            throw new RuntimeException('GUS session expired.');
        }

        if ($this->nextSearchFailure !== null) {
            $failure = $this->nextSearchFailure;
            $this->nextSearchFailure = null;

            throw $failure;
        }

        if ($this->expireAfterSearch) {
            $this->sessionActive = false;
            $this->expireAfterSearch = false;
        }

        return $this->searchReports;
    }

    public function dataStatus(): DateTimeImmutable
    {
        $this->dataStatusCalls++;

        if (! $this->sessionActive) {
            throw new InvalidServerResponseException('Invalid empty data status response.');
        }

        return $this->dataStatus;
    }

    public function getBulkReport(DateTimeImmutable $date, string $reportName): array
    {
        $this->bulkReportCalls++;

        if (! $this->sessionActive) {
            return [];
        }

        return $this->bulkReportData;
    }

    public function getFullReport(SearchReport $searchReport, string $reportName): array
    {
        $this->fullReportCalls++;

        if (! $this->sessionActive) {
            return [];
        }

        return $this->fullReportData;
    }

    public function expireSession(): void
    {
        $this->sessionActive = false;
    }

    public function expireImmediatelyAfterNextLogin(): void
    {
        $this->expireAfterNextLogin = true;
    }

    public function expireAfterNextSearch(): void
    {
        $this->expireAfterSearch = true;
    }

    public function failNextSearchWith(Throwable $failure): void
    {
        $this->nextSearchFailure = $failure;
    }
}

function makeSessionRecoverySearchReport(): SearchReport
{
    $response = new SearchResponseCompanyData;
    $response->Regon = '123456789';
    $response->Nip = '1111111111';
    $response->Nazwa = 'Test Company';
    $response->Typ = 'P';

    return new SearchReport($response);
}
