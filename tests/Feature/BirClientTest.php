<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirClientInterface;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Tests\Support\FakeGusApi;
use cieplik206\BirRegon\Tests\Support\StubGusApiFactory;
use GusApi\SearchReport;
use GusApi\Type\Response\SearchResponseCompanyData;

it('fails before making a SOAP request when the API key is missing', function (): void {
    config()->set('bir-regon.api_key', '');

    expect(fn () => app(BirClientInterface::class)->searchByNip('1234567890'))
        ->toThrow(
            BirAuthenticationException::class,
            'BIR API key is not configured. Set BIR_API_KEY in your .env file.',
        );
});

it('maps all single and batch search operations without repeated logins', function (): void {
    $api = new FakeGusApi(searchReports: [makeGusSearchReport()]);
    $factory = new StubGusApiFactory($api);
    $client = new BirClient($factory, 'api-key', Environment::Development);

    $singleResults = [
        $client->searchByNip('1234567890'),
        $client->searchByRegon('123456789'),
        $client->searchByKrs('0000123456'),
    ];
    $batchResults = [
        $client->searchByNips(['1234567890']),
        $client->searchByKrsNumbers(['0000123456']),
        $client->searchByRegons9(['123456789']),
        $client->searchByRegons14(['12345678901234']),
    ];

    expect($factory->calls)->toBe([['api-key', Environment::Development]])
        ->and($singleResults)->toHaveCount(3)
        ->and($singleResults[0]->regon)->toBe('123456789')
        ->and($batchResults)->toHaveCount(4)
        ->and($batchResults[0])->toHaveCount(1)
        ->and($batchResults[0][0]->regon)->toBe('123456789')
        ->and($api->calls)->toBe([
            ['login'],
            ['getByNip', '1234567890'],
            ['getByRegon', '123456789'],
            ['getByKrs', '0000123456'],
            ['getByNips', ['1234567890']],
            ['getByKrses', ['0000123456']],
            ['getByRegons9', ['123456789']],
            ['getByregons14', ['12345678901234']],
        ]);
});

it('maps full and bulk reports', function (): void {
    $searchReport = makeGusSearchReport();
    $api = new FakeGusApi(
        searchReports: [$searchReport],
        fullReportData: [['Nazwa' => 'Test Company']],
        bulkReportData: ['row'],
    );
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');
    $date = new DateTimeImmutable('2026-08-22');

    $fullReport = $client->getFullReport('123456789', ReportType::Organization);
    $bulkReport = $client->getBulkReport(
        $date,
        BulkReportType::NewLegalEntitiesAndNaturalPersons,
    );

    expect($fullReport->basicData->regon)->toBe('123456789')
        ->and($fullReport->reportData)->toBe([['Nazwa' => 'Test Company']])
        ->and($bulkReport->date)->toBe($date)
        ->and($bulkReport->reportData)->toBe(['row'])
        ->and($api->calls)->toBe([
            ['login'],
            ['getByRegon', '123456789'],
            ['getFullReport', $searchReport, ReportType::Organization->value],
            ['getBulkReport', $date, BulkReportType::NewLegalEntitiesAndNaturalPersons->value],
        ]);
});

it('provides unauthenticated service status and authenticated diagnostics', function (): void {
    $dataStatus = new DateTimeImmutable('2026-08-22');
    $api = new FakeGusApi(
        status: 1,
        serviceMessageValue: 'Dostępny',
        dataStatusValue: $dataStatus,
        messageCode: 7,
        message: 'Komunikat',
        sessionStatus: 1,
    );
    $factory = new StubGusApiFactory($api);
    $client = new BirClient($factory, 'api-key');

    $serviceStatus = $client->getServiceStatus();
    $actualDataStatus = $client->getDataStatus();
    $diagnostics = $client->getDiagnostics();

    expect($serviceStatus->status)->toBe(1)
        ->and($serviceStatus->message)->toBe('Dostępny')
        ->and($actualDataStatus)->toBe($dataStatus)
        ->and($diagnostics->messageCode)->toBe(7)
        ->and($diagnostics->message)->toBe('Komunikat')
        ->and($diagnostics->sessionStatus)->toBe(1)
        ->and($api->calls)->toBe([
            ['serviceStatus'],
            ['serviceMessage'],
            ['login'],
            ['dataStatus'],
            ['getMessageCode'],
            ['getMessage'],
            ['getSessionStatus'],
        ]);
});

it('can read the public service status without an API key', function (): void {
    $api = new FakeGusApi(status: 2, serviceMessageValue: 'Przerwa techniczna');
    $client = new BirClient(new StubGusApiFactory($api), '');

    $status = $client->getServiceStatus();

    expect($status->status)->toBe(2)
        ->and($status->message)->toBe('Przerwa techniczna')
        ->and($api->calls)->toBe([
            ['serviceStatus'],
            ['serviceMessage'],
        ]);
});

function makeGusSearchReport(): SearchReport
{
    $response = new SearchResponseCompanyData;
    $response->Regon = '123456789';
    $response->Nip = '1234567890';
    $response->Nazwa = 'Test Company';
    $response->Typ = 'P';

    return new SearchReport($response);
}
