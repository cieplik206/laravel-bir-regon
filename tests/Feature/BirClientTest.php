<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use cieplik206\BirRegon\Tests\Support\FakeBirGateway;

it('preserves authentication failures reported by the gateway', function (): void {
    $gateway = (new FakeBirGateway)
        ->queueSearch(new BirAuthenticationException(
            'BIR API key is not configured. Set BIR_API_KEY in your .env file.',
        ));
    $client = new BirClient($gateway);

    expect(fn () => $client->searchByNip('1234567890'))
        ->toThrow(
            BirAuthenticationException::class,
            'BIR API key is not configured. Set BIR_API_KEY in your .env file.',
        )
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::nip('1234567890')],
        ]);
});

it('maps every single and batch search operation to native search criteria', function (): void {
    $gateway = new FakeBirGateway(searchResults: [makeBirClientSearchResult()]);
    $client = new BirClient($gateway);

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

    expect($singleResults)->toHaveCount(3)
        ->and($singleResults[0])->toHaveCount(1)
        ->and($singleResults[0][0]->regon)->toBe('123456789')
        ->and($batchResults)->toHaveCount(4)
        ->and($batchResults[0])->toHaveCount(1)
        ->and($batchResults[0][0]->regon)->toBe('123456789')
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::nip('1234567890')],
            ['search', SearchCriteria::regon('123456789')],
            ['search', SearchCriteria::krs('0000123456')],
            ['search', SearchCriteria::nips(['1234567890'])],
            ['search', SearchCriteria::krsNumbers(['0000123456'])],
            ['search', SearchCriteria::regons9(['123456789'])],
            ['search', SearchCriteria::regons14(['12345678901234'])],
        ]);
});

it('preserves every company returned for a single identifier', function (): void {
    $gateway = new FakeBirGateway(searchResults: [
        makeBirClientSearchResult(
            regon: '123456789',
            type: EntityType::NaturalPerson,
            silo: Silo::Ceidg,
            name: 'CEIDG activity',
        ),
        makeBirClientSearchResult(
            regon: '987654321',
            type: EntityType::NaturalPerson,
            silo: Silo::Agriculture,
            name: 'Agricultural activity',
        ),
    ]);
    $client = new BirClient($gateway);

    $companies = $client->searchByNip('1234567890');

    expect($companies)->toHaveCount(2)
        ->and(array_column($companies, 'regon'))->toBe(['123456789', '987654321'])
        ->and(array_column($companies, 'name'))->toBe([
            'CEIDG activity',
            'Agricultural activity',
        ])
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::nip('1234567890')],
        ]);
});

it('preserves same-regon natural person results from different silos in source order', function (): void {
    $gateway = new FakeBirGateway(searchResults: [
        makeBirClientSearchResult(
            regon: '123456789',
            type: EntityType::NaturalPerson,
            silo: Silo::Ceidg,
            name: 'CEIDG activity',
        ),
        makeBirClientSearchResult(
            regon: '123456789',
            type: EntityType::NaturalPerson,
            silo: Silo::Agriculture,
            name: 'Agricultural activity',
        ),
    ]);
    $client = new BirClient($gateway);

    $companies = $client->searchByRegon('123456789');

    expect($companies)->toHaveCount(2)
        ->and(array_column($companies, 'regon'))->toBe(['123456789', '123456789'])
        ->and(array_column($companies, 'silo'))->toBe([Silo::Ceidg, Silo::Agriculture])
        ->and(array_column($companies, 'name'))->toBe([
            'CEIDG activity',
            'Agricultural activity',
        ])
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::regon('123456789')],
        ]);
});

it('returns empty batch results without calling the gateway', function (): void {
    $gateway = new FakeBirGateway(searchResults: [makeBirClientSearchResult()]);
    $client = new BirClient($gateway);

    expect($client->searchByNips([]))->toBe([])
        ->and($client->searchByKrsNumbers([]))->toBe([])
        ->and($client->searchByRegons9([]))->toBe([])
        ->and($client->searchByRegons14([]))->toBe([])
        ->and($gateway->calls)->toBe([]);
});

it('rejects invalid identifiers before calling the gateway', function (Closure $operation): void {
    $gateway = new FakeBirGateway(searchResults: [makeBirClientSearchResult()]);
    $client = new BirClient($gateway);

    expect(fn () => $operation($client))
        ->toThrow(BirValidationException::class)
        ->and($gateway->calls)->toBe([]);
})->with([
    'NIP search' => [static fn (BirClient $client): mixed => $client->searchByNip('123456789')],
    'REGON search' => [static fn (BirClient $client): mixed => $client->searchByRegon('12345678')],
    'KRS search' => [static fn (BirClient $client): mixed => $client->searchByKrs('000012345A')],
    'NIP batch' => [static fn (BirClient $client): mixed => $client->searchByNips(['123456789A'])],
    'KRS batch' => [static fn (BirClient $client): mixed => $client->searchByKrsNumbers(['000012345'])],
    'REGON9 batch' => [static fn (BirClient $client): mixed => $client->searchByRegons9(['12345678A'])],
    'REGON14 batch' => [static fn (BirClient $client): mixed => $client->searchByRegons14(['123456789'])],
    'batch limit' => [static fn (BirClient $client): mixed => $client->searchByNips(
        array_fill(0, SearchCriteria::MAX_BATCH_SIZE + 1, '1234567890'),
    )],
    'full report by NIP' => [static fn (BirClient $client): mixed => $client->getFullReportByNip(
        '123456789',
        ReportType::Organization,
    )],
    'full report by KRS' => [static fn (BirClient $client): mixed => $client->getFullReportByKrs(
        '000012345A',
        ReportType::Organization,
    )],
    'full report by REGON' => [static fn (BirClient $client): mixed => $client->getFullReport(
        '12345678',
        ReportType::Organization,
    )],
]);

it('translates non-string batch identifiers into validation failures', function (
    string $method,
): void {
    $gateway = new FakeBirGateway(searchResults: [makeBirClientSearchResult()]);
    $client = new BirClient($gateway);
    $operation = new ReflectionMethod($client, $method);

    expect(fn () => $operation->invoke($client, [1234567890]))
        ->toThrow(BirValidationException::class)
        ->and($gateway->calls)->toBe([]);
})->with([
    'NIP batch' => ['searchByNips'],
    'KRS batch' => ['searchByKrsNumbers'],
    'REGON9 batch' => ['searchByRegons9'],
    'REGON14 batch' => ['searchByRegons14'],
]);

it('selects a compatible search result before requesting a full report', function (): void {
    $incompatibleResult = makeBirClientSearchResult(
        regon: '987654321',
        type: EntityType::NaturalPerson,
        silo: Silo::Ceidg,
        name: 'Natural Person',
    );
    $compatibleResult = makeBirClientSearchResult(
        regon: '123456789',
        type: EntityType::LegalUnit,
        silo: Silo::LegalUnits,
        name: 'Test Company',
    );
    $gateway = new FakeBirGateway(
        searchResults: [$incompatibleResult, $compatibleResult],
        fullReportData: [['Nazwa' => 'Test Company']],
    );
    $client = new BirClient($gateway);

    $fullReport = $client->getFullReport('123456789', ReportType::Organization);

    expect($fullReport->basicData->regon)->toBe('123456789')
        ->and($fullReport->reportData)->toBe([['Nazwa' => 'Test Company']])
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::regon('123456789')],
            ['fullReport', '123456789', ReportType::Organization],
        ]);
});

it('maps bulk reports and validates their date before calling the gateway', function (): void {
    $timeZone = new DateTimeZone('Europe/Warsaw');
    $date = new DateTimeImmutable('yesterday', $timeZone);
    $gateway = new FakeBirGateway(bulkReportData: ['row']);
    $client = new BirClient($gateway);

    $bulkReport = $client->getBulkReport(
        $date,
        BulkReportType::NewLegalEntitiesAndNaturalPersons,
    );

    expect($bulkReport->date)->toBe($date)
        ->and($bulkReport->reportData)->toBe(['row'])
        ->and($gateway->calls)->toEqual([
            [
                'bulkReport',
                $date,
                BulkReportType::NewLegalEntitiesAndNaturalPersons,
            ],
        ]);
});

it('normalizes a bulk report date to the Warsaw calendar day before using it', function (): void {
    $warsaw = new DateTimeZone('Europe/Warsaw');
    $expectedDate = new DateTimeImmutable('yesterday', $warsaw);
    $dateFromAnExtremeTimeZone = $expectedDate
        ->setTime(23, 30)
        ->setTimezone(new DateTimeZone('Pacific/Kiritimati'));
    $reportType = BulkReportType::NewLegalEntitiesAndNaturalPersons;
    $gateway = new FakeBirGateway(bulkReportData: ['row']);
    $client = new BirClient($gateway);

    $report = $client->getBulkReport($dateFromAnExtremeTimeZone, $reportType);
    $gatewayDate = $gateway->calls[0][1] ?? null;

    expect($dateFromAnExtremeTimeZone->format('Y-m-d'))->not->toBe($expectedDate->format('Y-m-d'))
        ->and($gatewayDate)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($gatewayDate)->toEqual($expectedDate)
        ->and($gatewayDate->getTimezone()->getName())->toBe('Europe/Warsaw')
        ->and($gatewayDate->format('H:i:s'))->toBe('00:00:00')
        ->and($report->date)->toEqual($gatewayDate)
        ->and($report->date->getTimezone()->getName())->toBe('Europe/Warsaw')
        ->and($report->date->format('H:i:s'))->toBe('00:00:00');
});

it('accepts the oldest available bulk report day', function (): void {
    $warsaw = new DateTimeZone('Europe/Warsaw');
    $oldestAvailableDate = (new DateTimeImmutable('today', $warsaw))->modify('-7 days');
    $reportType = BulkReportType::NewLegalEntitiesAndNaturalPersons;
    $gateway = new FakeBirGateway(bulkReportData: ['row']);
    $client = new BirClient($gateway);

    $report = $client->getBulkReport($oldestAvailableDate, $reportType);

    expect($report->date)->toBe($oldestAvailableDate)
        ->and($gateway->calls)->toEqual([
            ['bulkReport', $oldestAvailableDate, $reportType],
        ]);
});

it('normalizes a date from a DST-aware extreme time zone at the seven-day boundary', function (): void {
    $warsaw = new DateTimeZone('Europe/Warsaw');
    $expectedDate = (new DateTimeImmutable('today', $warsaw))->modify('-7 days');
    $dateFromDstAwareExtremeTimeZone = $expectedDate
        ->setTime(23, 30)
        ->setTimezone(new DateTimeZone('Pacific/Chatham'));
    $reportType = BulkReportType::NewLegalEntitiesAndNaturalPersons;
    $gateway = new FakeBirGateway(bulkReportData: ['row']);
    $client = new BirClient($gateway);

    $report = $client->getBulkReport($dateFromDstAwareExtremeTimeZone, $reportType);
    $gatewayDate = $gateway->calls[0][1] ?? null;

    expect($dateFromDstAwareExtremeTimeZone->format('Y-m-d'))
        ->not->toBe($expectedDate->format('Y-m-d'))
        ->and($gatewayDate)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($gatewayDate)->toEqual($expectedDate)
        ->and($gatewayDate->getTimezone()->getName())->toBe('Europe/Warsaw')
        ->and($gatewayDate->format('H:i:s'))->toBe('00:00:00')
        ->and($report->date)->toEqual($expectedDate);
});

it('rejects an unavailable bulk report date before calling the gateway', function (
    string $relativeDate,
): void {
    $gateway = new FakeBirGateway(bulkReportData: ['row']);
    $client = new BirClient($gateway);
    $date = new DateTimeImmutable($relativeDate, new DateTimeZone('Europe/Warsaw'));

    expect(fn () => $client->getBulkReport(
        $date,
        BulkReportType::NewLegalEntitiesAndNaturalPersons,
    ))
        ->toThrow(BirValidationException::class)
        ->and($gateway->calls)->toBe([]);
})->with([
    'today is not published yet' => ['today'],
    'future dates are unavailable' => ['tomorrow'],
    'more than seven days ago is unavailable' => ['-8 days'],
]);

it('provides public service status and authenticated diagnostics in stable order', function (): void {
    $gateway = new FakeBirGateway(values: [
        GetValueParameter::ServiceStatus->value => '1',
        GetValueParameter::ServiceMessage->value => 'Dostępny',
        GetValueParameter::DataStatus->value => '22-08-2026',
        GetValueParameter::MessageCode->value => '7',
        GetValueParameter::Message->value => 'Komunikat',
        GetValueParameter::SessionStatus->value => '1',
    ]);
    $client = new BirClient($gateway);

    $serviceStatus = $client->getServiceStatus();
    $dataStatus = $client->getDataStatus();
    $diagnostics = $client->getDiagnostics();

    expect($serviceStatus->status)->toBe(1)
        ->and($serviceStatus->message)->toBe('Dostępny')
        ->and($dataStatus->format('Y-m-d'))->toBe('2026-08-22')
        ->and($dataStatus->getTimezone()->getName())->toBe('Europe/Warsaw')
        ->and($diagnostics->messageCode)->toBe(7)
        ->and($diagnostics->message)->toBe('Komunikat')
        ->and($diagnostics->sessionStatus)->toBe(1)
        ->and($gateway->calls)->toBe([
            ['getValue', GetValueParameter::ServiceStatus],
            ['getValue', GetValueParameter::ServiceMessage],
            ['getValue', GetValueParameter::DataStatus],
            ['diagnostics'],
        ]);
});

function makeBirClientSearchResult(
    string $regon = '123456789',
    EntityType $type = EntityType::LegalUnit,
    Silo $silo = Silo::LegalUnits,
    string $name = 'Test Company',
): SearchResult {
    return new SearchResult(
        regon: $regon,
        nip: '1234567890',
        name: $name,
        city: 'Warszawa',
        postalCode: '00-001',
        street: 'Testowa',
        buildingNumber: '1',
        apartmentNumber: null,
        province: 'MAZOWIECKIE',
        district: 'Warszawa',
        commune: 'Warszawa',
        type: $type,
        regon14: strlen($regon) === 14 ? $regon : null,
        nipStatus: null,
        silo: $silo,
        activityEndDate: null,
        postCity: 'Warszawa',
    );
}
