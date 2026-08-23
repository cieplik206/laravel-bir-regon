<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Tests\Support\StubGusApiFactory;
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

class ExpiringSessionGusApi extends GusApi
{
    public int $dataStatusCalls = 0;

    public int $loginCalls = 0;

    /** @var list<string> */
    public array $searchedNips = [];

    private bool $expireAfterNextLogin = false;

    private ?Throwable $nextSearchFailure = null;

    private bool $sessionActive = false;

    /** @param list<SearchReport> $searchReports */
    public function __construct(
        private readonly array $searchReports,
        private readonly DateTimeImmutable $dataStatus = new DateTimeImmutable('2026-08-23'),
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

        return $this->searchReports;
    }

    public function dataStatus(): DateTimeImmutable
    {
        $this->dataStatusCalls++;

        if (! $this->sessionActive) {
            throw new RuntimeException('GUS session expired.');
        }

        return $this->dataStatus;
    }

    public function expireSession(): void
    {
        $this->sessionActive = false;
    }

    public function expireImmediatelyAfterNextLogin(): void
    {
        $this->expireAfterNextLogin = true;
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
