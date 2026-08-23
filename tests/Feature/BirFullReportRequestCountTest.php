<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Tests\Support\FakeGusApi;
use cieplik206\BirRegon\Tests\Support\StubGusApiFactory;
use GusApi\SearchReport;
use GusApi\Type\Response\SearchResponseCompanyData;

it('fetches a full report with one search request for every identifier type', function (
    string $identifierType,
    string $identifier,
    string $searchMethod,
): void {
    $searchReport = makeFullReportSearchReport();
    $api = new FakeGusApi(
        searchReports: [$searchReport],
        fullReportData: [['praw_regon9' => '610188201']],
    );
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');
    $service = new BirRegonService($client);

    $builder = match ($identifierType) {
        'nip' => $service->forNip($identifier),
        'krs' => $service->forKrs($identifier),
        'regon' => $service->forRegon($identifier),
        default => throw new InvalidArgumentException("Unsupported identifier type: {$identifierType}."),
    };

    $result = $builder
        ->reportType(ReportType::Organization)
        ->getFullReport();

    expect($result->basicData->regon)->toBe('610188201')
        ->and($result->reportData)->toBe([['praw_regon9' => '610188201']])
        ->and($api->calls)->toBe([
            ['login'],
            [$searchMethod, $identifier],
            ['getFullReport', $searchReport, ReportType::Organization->value],
        ]);
})->with([
    'NIP' => ['nip', '7740001454', 'getByNip'],
    'KRS' => ['krs', '0000028860', 'getByKrs'],
    'REGON' => ['regon', '610188201', 'getByRegon'],
]);

function makeFullReportSearchReport(): SearchReport
{
    $response = new SearchResponseCompanyData;
    $response->Regon = '610188201';
    $response->Nip = '7740001454';
    $response->Nazwa = 'Test Company';
    $response->Typ = 'P';
    $response->SilosID = '6';

    return new SearchReport($response);
}
