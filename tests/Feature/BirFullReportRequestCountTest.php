<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use cieplik206\BirRegon\Tests\Support\FakeBirGateway;

it('fetches a full report with one search request for every identifier type', function (
    string $identifierType,
    string $identifier,
    SearchCriteria $criteria,
): void {
    $searchResult = makeFullReportSearchResult();
    $gateway = new FakeBirGateway(
        searchResults: [$searchResult],
        fullReportData: [['praw_regon9' => '610188201']],
    );
    $client = new BirClient($gateway);
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
        ->and($gateway->calls)->toEqual([
            ['search', $criteria],
            ['fullReport', '610188201', ReportType::Organization],
        ]);
})->with([
    'NIP' => ['nip', '7740001454', SearchCriteria::nip('7740001454')],
    'KRS' => ['krs', '0000028860', SearchCriteria::krs('0000028860')],
    'REGON' => ['regon', '610188201', SearchCriteria::regon('610188201')],
]);

function makeFullReportSearchResult(): SearchResult
{
    return new SearchResult(
        regon: '610188201',
        nip: '7740001454',
        name: 'Test Company',
        city: 'Warszawa',
        postalCode: '00-001',
        street: 'Testowa',
        buildingNumber: '1',
        apartmentNumber: null,
        province: 'MAZOWIECKIE',
        district: 'Warszawa',
        commune: 'Warszawa',
        type: EntityType::LegalUnit,
        regon14: null,
        nipStatus: null,
        silo: Silo::LegalUnits,
        activityEndDate: null,
        postCity: 'Warszawa',
    );
}
