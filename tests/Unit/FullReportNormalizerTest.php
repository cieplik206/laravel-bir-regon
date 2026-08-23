<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\ActivityStatus;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\NipStatus;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use cieplik206\BirRegon\Normalization\FullReportNormalizer;

it('normalizes an organization report into typed projections without sanitizing text', function (): void {
    $unsafeName = '<script>alert("fixture")</script>';
    $row = [
        'praw_regon9' => '012345678',
        'praw_nip' => '0123456789',
        'praw_statusNip' => 'Uchylony',
        'praw_nazwa' => $unsafeName,
        'praw_nazwaSkrocona' => ' FST ',
        'praw_dataPowstania' => '1600-01-02',
        'praw_dataRozpoczeciaDzialalnosci' => '2020-02-03',
        'praw_dataWpisuDoRegon' => '2020-02-04',
        'praw_dataZawieszeniaDzialalnosci' => '2021-01-01',
        'praw_dataWznowieniaDzialalnosci' => '2021-02-01',
        'praw_adSiedzKraj_Symbol' => 'PL',
        'praw_adSiedzKraj_Nazwa' => 'POLSKA',
        'praw_adSiedzWojewodztwo_Symbol' => '14',
        'praw_adSiedzWojewodztwo_Nazwa' => 'MAZOWIECKIE',
        'praw_adSiedzKodPocztowy' => '00-001',
        'praw_adSiedzMiejscowosc_Nazwa' => 'Testowo',
        'praw_adSiedzUlica_Nazwa' => 'ul. Przykładowa',
        'praw_adSiedzNumerNieruchomosci' => '1',
        'praw_numerTelefonu' => '+48 000 000 000',
        'praw_adresEmail' => 'not-validated@example.test',
        'praw_adresStronyinternetowej' => 'javascript:alert(1)',
        'praw_podstawowaFormaPrawna_Symbol' => '1',
        'praw_podstawowaFormaPrawna_Nazwa' => 'OSOBA PRAWNA',
        'praw_szczegolnaFormaPrawna_Symbol' => '117',
        'praw_szczegolnaFormaPrawna_Nazwa' => 'SPÓŁKA TESTOWA',
        'praw_numerWRejestrzeEwidencji' => '0000123456',
        'praw_dataWpisuDoRejestruEwidencji' => '2020-01-10',
        'praw_organRejestrowy_Symbol' => '071010050',
        'praw_organRejestrowy_Nazwa' => 'SĄD TESTOWY',
        'praw_rodzajRejestruEwidencji_Symbol' => '138',
        'praw_rodzajRejestruEwidencji_Nazwa' => 'REJESTR TESTOWY',
        'praw_liczbaJednLokalnych' => '2',
        'future_field' => 'preserved only in raw data',
    ];

    $normalized = (new FullReportNormalizer)->normalize(ReportType::Organization, [$row]);
    $entity = $normalized->entity;

    expect($entity)->not->toBeNull()
        ->and($entity?->identity->regon)->toBe('012345678')
        ->and($entity?->identity->nip)->toBe('0123456789')
        ->and($entity?->identity->nipStatus)->toBe(NipStatus::Revoked)
        ->and($entity?->identity->name)->toBe($unsafeName)
        ->and($entity?->identity->shortName)->toBe(' FST ')
        ->and($entity?->address?->countryCode)->toBe('PL')
        ->and($entity?->address?->cityName)->toBe('Testowo')
        ->and($entity?->contact?->website)->toBe('javascript:alert(1)')
        ->and($entity?->legalForm?->basic?->code)->toBe('1')
        ->and($entity?->legalForm?->specific?->name)->toBe('SPÓŁKA TESTOWA')
        ->and($entity?->registry?->number)->toBe('0000123456')
        ->and($entity?->registry?->authority?->name)->toBe('SĄD TESTOWY')
        ->and($entity?->lifecycle?->createdAt?->format('Y-m-d'))->toBe('1600-01-02')
        ->and($entity?->lifecycle?->status)->toBe(ActivityStatus::Active)
        ->and($entity?->silo)->toBe(Silo::LegalUnits)
        ->and($entity?->localUnitCount)->toBe(2)
        ->and($row['praw_nazwa'])->toBe($unsafeName)
        ->and($row['future_field'])->toBe('preserved only in raw data');
});

it('normalizes natural person general data without guessing overall activity status', function (): void {
    $normalized = (new FullReportNormalizer)->normalize(ReportType::NaturalPerson, [[
        'fiz_regon9' => '012345678',
        'fiz_nip' => '0123456789',
        'fiz_statusNip' => 'Unieważniony',
        'fiz_imie1' => 'Jan',
        'fiz_imie2' => 'Testowy',
        'fiz_nazwisko' => 'Przykład',
        'fiz_dataWpisuPodmiotuDoRegon' => '2020-01-02',
        'fiz_dzialalnoscCeidg' => '1',
        'fiz_dzialalnoscRolnicza' => '1',
        'fiz_dzialalnoscPozostala' => '0',
        'fiz_dzialalnoscSkreslonaDo20141108' => '0',
        'fiz_liczbaJednLokalnych' => '3',
    ]]);
    $entity = $normalized->entity;

    expect($entity?->identity->nipStatus)->toBe(NipStatus::Invalidated)
        ->and($entity?->identity->personName?->firstName)->toBe('Jan')
        ->and($entity?->identity->personName?->lastName)->toBe('Przykład')
        ->and($entity?->activityKinds?->ceidgCount)->toBe(1)
        ->and($entity?->activityKinds?->agricultureCount)->toBe(1)
        ->and($entity?->activityKinds?->otherCount)->toBe(0)
        ->and($entity?->activityKinds?->deletedBefore20141108Count)->toBe(0)
        ->and($entity?->localUnitCount)->toBe(3)
        ->and($entity?->lifecycle?->status)->toBe(ActivityStatus::Unknown);
});

it('derives documented activity status from lifecycle fields', function (
    array $fields,
    ActivityStatus $status,
): void {
    $normalized = (new FullReportNormalizer)->normalize(ReportType::NaturalPersonCeidg, [[
        'fiz_regon9' => '012345678',
        ...$fields,
    ]]);

    expect($normalized->entity?->lifecycle?->status)->toBe($status);
})->with([
    'no blocking lifecycle field' => [[], ActivityStatus::Active],
    'suspended without resumption' => [
        ['fiz_dataZawieszeniaDzialalnosci' => '2024-01-01'],
        ActivityStatus::Inactive,
    ],
    'resumed after suspension' => [[
        'fiz_dataZawieszeniaDzialalnosci' => '2024-01-01',
        'fiz_dataWznowieniaDzialalnosci' => '2024-02-01',
    ], ActivityStatus::Active],
    'ended' => [
        ['fiz_dataZakonczeniaDzialalnosci' => '2024-01-01'],
        ActivityStatus::Inactive,
    ],
    'bankruptcy declared' => [
        ['fiz_dataOrzeczeniaOUpadlosci' => '2024-01-01'],
        ActivityStatus::Inactive,
    ],
    'not started' => [
        ['fizC_NiePodjetoDzialalnosci' => 'true'],
        ActivityStatus::Inactive,
    ],
]);

it('supports the documented agricultural typo and rejects conflicting aliases', function (): void {
    $normalizer = new FullReportNormalizer;
    $normalized = $normalizer->normalize(ReportType::NaturalPersonAgro, [[
        'fiz_regon9' => '012345678',
        'fiz_dataSkresleniaDzialalanosciZRegon' => '2025-01-02',
    ]]);

    expect($normalized->entity?->lifecycle?->removedFromRegonAt?->format('Y-m-d'))
        ->toBe('2025-01-02')
        ->and($normalized->entity?->lifecycle?->status)->toBe(ActivityStatus::Inactive);

    expect(fn () => $normalizer->normalize(ReportType::NaturalPersonAgro, [[
        'fiz_regon9' => '012345678',
        'fiz_dataSkresleniaDzialalanosciZRegon' => '2025-01-02',
        'fiz_dataSkresleniaDzialalnosciZRegon' => '2025-01-03',
    ]]))->toThrow(BirProtocolException::class);
});

it('normalizes current and future PKD versions including the local-silo field absent from XSD', function (): void {
    $normalizer = new FullReportNormalizer;
    $organization = $normalizer->normalize(ReportType::OrganizationActivity, [
        [
            'praw_pkdWersja' => 'PKD-2030',
            'praw_pkdKod' => '6310D',
            'praw_pkdNazwa' => 'DZIAŁALNOŚĆ TESTOWA',
            'praw_pkdPrzewazajace' => '1',
        ],
        [
            'praw_pkdWersja' => '2007',
            'praw_pkdKod' => '6201Z',
            'praw_pkdNazwa' => 'POZOSTAŁA DZIAŁALNOŚĆ TESTOWA',
            'praw_pkdPrzewazajace' => '0',
        ],
    ]);
    $naturalLocal = $normalizer->normalize(ReportType::NaturalPersonLocalActivity, [[
        'lokfiz_pkdWersja' => '2025',
        'lokfiz_pkdKod' => '6210B',
        'lokfiz_pkdPrzewazajace' => '1',
        'lokfiz_SilosId' => '1',
        'lokfiz_SilosSymbol' => 'CEIDG',
    ]]);

    expect($organization->pkdActivities)->toHaveCount(2)
        ->and($organization->pkdActivities[0]->classification)->toBe('PKD-2030')
        ->and($organization->pkdActivities[0]->predominant)->toBeTrue()
        ->and($organization->pkdActivities[0]->silo)->toBe(Silo::LegalUnits)
        ->and($organization->pkdActivities[1]->classification)->toBe('2007')
        ->and($organization->pkdActivities[1]->predominant)->toBeFalse()
        ->and($naturalLocal->pkdActivities[0]->silo)->toBe(Silo::Ceidg)
        ->and($naturalLocal->pkdActivities[0]->siloSymbol)->toBe('CEIDG');
});

it('normalizes BIR121 legal local unit identity, address, registry and status', function (): void {
    $normalized = (new FullReportNormalizer)->normalize(
        ReportType::OrganizationLocalWithNip,
        [[
            'lokpraw_regon14' => '01234567800001',
            'lokpraw_nip' => '0123456789',
            'lokpraw_statusNip' => 'Unieważniony',
            'lokpraw_nazwa' => 'FIKCYJNA JEDNOSTKA',
            'lokpraw_numerWrejestrzeEwidencji' => 'FIXTURE-1',
            'lokpraw_dataWpisuDoRejestruEwidencji' => '2021-01-02',
            'lokpraw_adSiedzMiejscowosc_Nazwa' => 'Testowo',
            'lokpraw_dataZakonczeniaDzialalnosci' => '2025-01-02',
        ]],
    );

    expect($normalized->entity?->identity->nip)->toBe('0123456789')
        ->and($normalized->entity?->identity->nipStatus)->toBe(NipStatus::Invalidated)
        ->and($normalized->entity?->address?->cityName)->toBe('Testowo')
        ->and($normalized->entity?->registry?->number)->toBe('FIXTURE-1')
        ->and($normalized->entity?->lifecycle?->status)->toBe(ActivityStatus::Inactive);
});

it('normalizes local-unit lists, partners and entity type reports', function (): void {
    $normalizer = new FullReportNormalizer;
    $locals = $normalizer->normalize(ReportType::NaturalPersonLocals, [
        [
            'lokfiz_regon14' => '01234567800001',
            'lokfiz_nazwa' => 'ODDZIAŁ 1',
            'lokfiz_silosID' => '1',
            'lokfiz_silos_Symbol' => 'CEIDG',
            'lokfiz_adSiedzMiejscowosc_Nazwa' => 'Testowo',
        ],
        [
            'lokfiz_regon14' => '01234567800002',
            'lokfiz_nazwa' => 'ODDZIAŁ 2',
            'lokfiz_silosID' => '2',
        ],
    ]);
    $partners = $normalizer->normalize(ReportType::OrganizationPartners, [[
        'wspolsc_regonWspolnikSpolki' => '012345678',
        'wspolsc_imiePierwsze' => 'Jan',
        'wspolsc_nazwisko' => 'Przykład',
        'wspolsc_firmaNazwa' => 'FIKCYJNY WSPÓLNIK',
    ]]);
    $type = $normalizer->normalize(ReportType::UnitType, [['Typ' => 'LP']]);

    expect($locals->localUnits)->toHaveCount(2)
        ->and($locals->localUnits[0]->silo)->toBe(Silo::Ceidg)
        ->and($locals->localUnits[0]->siloSymbol)->toBe('CEIDG')
        ->and($locals->localUnits[0]->address?->cityName)->toBe('Testowo')
        ->and($locals->localUnits[1]->silo)->toBe(Silo::Agriculture)
        ->and($partners->partners)->toHaveCount(1)
        ->and($partners->partners[0]->personName?->firstName)->toBe('Jan')
        ->and($partners->partners[0]->companyName)->toBe('FIKCYJNY WSPÓLNIK')
        ->and($type->unitType)->toBe(EntityType::LegalUnitLocalUnit);
});

it('maps every remaining non-empty BIR12 report shape', function (): void {
    $normalizer = new FullReportNormalizer;
    $otherActivity = $normalizer->normalize(ReportType::NaturalPersonOther, [[
        'fiz_regon9' => '012345678',
        'fizP_numerWRejestrzeEwidencji' => 'OTHER-1',
        'fizP_OrganRejestrowy_Nazwa' => 'ORGAN TESTOWY',
    ]]);
    $deletedActivity = $normalizer->normalize(
        ReportType::NaturalPersonDeletedBefore20141108,
        [[
            'fiz_regon9' => '012345678',
            'fiz_adresEmail2' => 'secondary@example.test',
        ]],
    );
    $naturalLocal = $normalizer->normalize(ReportType::NaturalPersonLocal, [[
        'lokfiz_regon14' => '01234567800001',
        'lokfiz_FormaFinansowania_Symbol' => '1',
        'lokfiz_FormaFinansowania_Nazwa' => 'SAMOFINANSOWANIE',
        'lokfiz_numerwRejestrzeEwidencji' => 'LOCAL-1',
        'lokfiz_silosID' => '3',
        'lokfiz_silos_Nazwa' => 'POZOSTAŁA',
    ]]);
    $naturalPkd = $normalizer->normalize(ReportType::NaturalPersonActivity, [[
        'fiz_pkdWersja' => '2025',
        'fiz_pkdKod' => '6210A',
        'fiz_pkdPrzewazajace' => '1',
        'fiz_SilosID' => '2',
        'fiz_SilosSymbol' => 'ROL',
        'fiz_dataSkresleniaDzialalnosciZRegon' => '2025-01-02',
    ]]);
    $organizationLocals = $normalizer->normalize(ReportType::OrganizationLocals, [[
        'lokpraw_regon14' => '01234567800001',
        'lokpraw_nazwa' => 'ODDZIAŁ PRAWNY',
    ]]);
    $organizationLocal = $normalizer->normalize(ReportType::OrganizationLocal, [[
        'lokpraw_regon14' => '01234567800001',
        'lokpraw_nip' => 'unsafe ignored extension',
    ]]);
    $organizationLocalPkd = $normalizer->normalize(
        ReportType::OrganizationLocalActivity,
        [[
            'lokpraw_pkdWersja' => '2007',
            'lokpraw_pkdKod' => '6201Z',
            'lokpraw_pkdPrzewazajace' => '0',
        ]],
    );

    expect($otherActivity->entity?->registry?->number)->toBe('OTHER-1')
        ->and($otherActivity->entity?->silo)->toBe(Silo::Other)
        ->and($deletedActivity->entity?->contact?->secondaryEmail)
        ->toBe('secondary@example.test')
        ->and($deletedActivity->entity?->lifecycle?->status)->toBe(ActivityStatus::Inactive)
        ->and($naturalLocal->entity?->legalForm?->financing?->code)->toBe('1')
        ->and($naturalLocal->entity?->registry?->number)->toBe('LOCAL-1')
        ->and($naturalLocal->entity?->silo)->toBe(Silo::Other)
        ->and($naturalLocal->entity?->siloName)->toBe('POZOSTAŁA')
        ->and($naturalPkd->pkdActivities[0]->silo)->toBe(Silo::Agriculture)
        ->and($naturalPkd->pkdActivities[0]->removedFromRegonAt?->format('Y-m-d'))
        ->toBe('2025-01-02')
        ->and($organizationLocals->localUnits[0]->identity->name)->toBe('ODDZIAŁ PRAWNY')
        ->and($organizationLocal->entity?->identity->nip)->toBeNull()
        ->and($organizationLocalPkd->pkdActivities[0]->classification)
        ->toBe('2007')
        ->and($organizationLocalPkd->pkdActivities[0]->silo)->toBe(Silo::LegalUnits);
});

it('accepts schema-valid xs:date forms and preserves an explicit timezone', function (
    string $value,
    string $expectedTimezone,
    string $expectedOffset,
): void {
    $normalized = (new FullReportNormalizer)->normalize(ReportType::Organization, [[
        'praw_dataPowstania' => $value,
    ]]);
    $date = $normalized->entity?->lifecycle?->createdAt;

    expect($date)->not->toBeNull()
        ->and($date?->format('Y-m-d'))->toBe('2025-02-01')
        ->and($date?->getTimezone()->getName())->toBe($expectedTimezone)
        ->and($date?->format('P'))->toBe($expectedOffset);
})->with([
    'date only uses the package timezone' => [
        '2025-02-01',
        'Europe/Warsaw',
        '+01:00',
    ],
    'UTC designator' => ['2025-02-01Z', 'Z', '+00:00'],
    'positive offset' => ['2025-02-01+05:30', '+05:30', '+05:30'],
    'negative offset' => ['2025-02-01-13:59', '-13:59', '-13:59'],
    'maximum positive offset' => ['2025-02-01+14:00', '+14:00', '+14:00'],
    'maximum negative offset' => ['2025-02-01-14:00', '-14:00', '-14:00'],
    'surrounding XML whitespace is collapsed' => [
        " \t\r\n2025-02-01+02:30\r\n ",
        '+02:30',
        '+02:30',
    ],
]);

it('normalizes empty optional fields to null without creating empty component objects', function (): void {
    $normalized = (new FullReportNormalizer)->normalize(ReportType::Organization, [[
        'praw_regon9' => '012345678',
        'praw_adSiedzMiejscowosc_Nazwa' => '',
        'praw_adresEmail' => '',
        'praw_podstawowaFormaPrawna_Symbol' => '',
        'praw_numerWRejestrzeEwidencji' => '',
    ]]);

    expect($normalized->entity?->address)->toBeNull()
        ->and($normalized->entity?->contact)->toBeNull()
        ->and($normalized->entity?->legalForm)->toBeNull()
        ->and($normalized->entity?->registry)->toBeNull();
});

it('normalizes non-negative XML Schema integer lexical forms', function (): void {
    $normalized = (new FullReportNormalizer)->normalize(ReportType::Organization, [[
        'praw_regon9' => '012345678',
        'praw_liczbaJednLokalnych' => " \t+002\r\n",
    ]]);

    expect($normalized->entity?->localUnitCount)->toBe(2);
});

it('normalizes xs:int lexical forms used by natural-person activity counts', function (): void {
    $normalized = (new FullReportNormalizer)->normalize(ReportType::NaturalPerson, [[
        'fiz_regon9' => '012345678',
        'fiz_dzialalnoscCeidg' => " \t+02\r\n",
        'fiz_dzialalnoscRolnicza' => '-0',
        'fiz_dzialalnoscPozostala' => '2147483647',
    ]]);

    expect($normalized->entity?->activityKinds?->ceidgCount)->toBe(2)
        ->and($normalized->entity?->activityKinds?->agricultureCount)->toBe(0)
        ->and($normalized->entity?->activityKinds?->otherCount)->toBe(2147483647);
});

it('collapses XML Schema whitespace for xs:boolean fields', function (
    string $value,
    bool $expected,
): void {
    $normalized = (new FullReportNormalizer)->normalize(ReportType::NaturalPersonCeidg, [[
        'fizC_NiePodjetoDzialalnosci' => $value,
    ]]);

    expect($normalized->entity?->registry?->notStarted)->toBe($expected);
})->with([
    'true' => [" \ttrue\r\n", true],
    'false' => ["\r\nfalse \t", false],
    'one' => [" \t1\r\n", true],
    'zero' => ["\r\n0 \t", false],
]);

it('still rejects invalid xs:boolean lexical forms after whitespace collapse', function (): void {
    expect(fn () => (new FullReportNormalizer)->normalize(ReportType::NaturalPersonCeidg, [[
        'fizC_NiePodjetoDzialalnosci' => " \ttr ue\r\n",
    ]]))->toThrow(BirProtocolException::class, 'fizC_NiePodjetoDzialalnosci');
});

it('keeps zero-one PKD flags strict instead of applying xs:boolean whitespace collapse', function (): void {
    expect(fn () => (new FullReportNormalizer)->normalize(ReportType::OrganizationActivity, [[
        'praw_pkdPrzewazajace' => " \t1\r\n",
    ]]))->toThrow(BirProtocolException::class, 'praw_pkdPrzewazajace');
});

it('accepts the xs:int maximum and rejects the first value above it', function (): void {
    $normalized = (new FullReportNormalizer)->normalize(ReportType::Organization, [[
        'praw_liczbaJednLokalnych' => '2147483647',
    ]]);

    expect($normalized->entity?->localUnitCount)->toBe(2147483647)
        ->and(fn () => (new FullReportNormalizer)->normalize(ReportType::Organization, [[
            'praw_liczbaJednLokalnych' => '2147483648',
        ]]))
        ->toThrow(BirProtocolException::class, 'praw_liczbaJednLokalnych');
});

it('supports an empty result for every report type', function (ReportType $reportType): void {
    $normalized = (new FullReportNormalizer)->normalize($reportType, []);

    expect($normalized->entity)->toBeNull()
        ->and($normalized->localUnits)->toBe([])
        ->and($normalized->pkdActivities)->toBe([])
        ->and($normalized->partners)->toBe([])
        ->and($normalized->unitType)->toBeNull();
})->with(ReportType::cases());

it('does not create a normalized projection from empty or unknown-only rows', function (
    ReportType $reportType,
): void {
    foreach ([
        [[]],
        [['future_field' => 'preserved only in raw report data']],
    ] as $rows) {
        $normalized = (new FullReportNormalizer)->normalize($reportType, $rows);

        expect($normalized->entity)->toBeNull()
            ->and($normalized->localUnits)->toBe([])
            ->and($normalized->pkdActivities)->toBe([])
            ->and($normalized->partners)->toBe([])
            ->and($normalized->unitType)->toBeNull();
    }
})->with(ReportType::cases());

it('enforces single-report cardinality before omitting empty projections', function (): void {
    expect(fn () => (new FullReportNormalizer)->normalize(ReportType::Organization, [
        [],
        ['praw_nazwa' => 'Recognized organization'],
        [],
    ]))->toThrow(BirProtocolException::class);
});

it('omits empty and unknown-only rows from normalized list projections', function (): void {
    $localUnits = (new FullReportNormalizer)->normalize(ReportType::NaturalPersonLocals, [
        [],
        ['future_field' => 'raw local-unit extension'],
        ['lokfiz_regon14' => '01234567800001'],
        [],
    ]);
    $activities = (new FullReportNormalizer)->normalize(ReportType::OrganizationActivity, [
        [],
        ['future_field' => 'raw PKD extension'],
        ['praw_pkdKod' => '01.11.Z'],
        [],
    ]);
    $partners = (new FullReportNormalizer)->normalize(ReportType::OrganizationPartners, [
        [],
        ['future_field' => 'raw partner extension'],
        ['wspolsc_firmaNazwa' => 'Recognized partner'],
        [],
    ]);

    expect($localUnits->localUnits)->toHaveCount(1)
        ->and($localUnits->localUnits[0]->identity->regon)->toBe('01234567800001')
        ->and($activities->pkdActivities)->toHaveCount(1)
        ->and($activities->pkdActivities[0]->code)->toBe('01.11.Z')
        ->and($partners->partners)->toHaveCount(1)
        ->and($partners->partners[0]->companyName)->toBe('Recognized partner');
});

it('rejects multiple non-empty rows for every single-row report', function (ReportType $reportType): void {
    expect(fn () => (new FullReportNormalizer)->normalize($reportType, [
        ['future_field' => 'first'],
        ['future_field' => 'second'],
    ]))
        ->toThrow(BirProtocolException::class);
})->with([
    ReportType::NaturalPerson,
    ReportType::NaturalPersonCeidg,
    ReportType::NaturalPersonAgro,
    ReportType::NaturalPersonOther,
    ReportType::NaturalPersonDeletedBefore20141108,
    ReportType::NaturalPersonLocal,
    ReportType::Organization,
    ReportType::OrganizationLocal,
    ReportType::OrganizationLocalWithNip,
    ReportType::UnitType,
]);

it('rejects invalid known scalar fields without exposing their values', function (
    ReportType $reportType,
    array $row,
    string $field,
): void {
    try {
        (new FullReportNormalizer)->normalize($reportType, [$row]);
    } catch (BirProtocolException $exception) {
        expect($exception->getMessage())->toContain($field)
            ->and(str_contains($exception->getMessage(), 'unsafe-fixture-value'))->toBeFalse();

        return;
    }

    throw new RuntimeException('Expected a protocol exception.');
})->with([
    'date' => [
        ReportType::Organization,
        ['praw_dataPowstania' => '2025-02-30unsafe-fixture-value'],
        'praw_dataPowstania',
    ],
    'XML boolean' => [
        ReportType::NaturalPersonCeidg,
        ['fizC_NiePodjetoDzialalnosci' => 'unsafe-fixture-value'],
        'fizC_NiePodjetoDzialalnosci',
    ],
    'zero-one flag' => [
        ReportType::OrganizationActivity,
        ['praw_pkdPrzewazajace' => 'unsafe-fixture-value'],
        'praw_pkdPrzewazajace',
    ],
    'negative natural-person activity count' => [
        ReportType::NaturalPerson,
        ['fiz_dzialalnoscCeidg' => '-1'],
        'fiz_dzialalnoscCeidg',
    ],
    'non-negative integer' => [
        ReportType::Organization,
        ['praw_liczbaJednLokalnych' => '-1unsafe-fixture-value'],
        'praw_liczbaJednLokalnych',
    ],
    'PKD classification maximum length' => [
        ReportType::OrganizationActivity,
        ['praw_pkdWersja' => 'unsafe-fixture-value'],
        'praw_pkdWersja',
    ],
    'entity type' => [
        ReportType::UnitType,
        ['Typ' => 'unsafe-fixture-value'],
        'Typ',
    ],
    'NIP status' => [
        ReportType::Organization,
        ['praw_statusNip' => 'unsafe-fixture-value'],
        'praw_statusNip',
    ],
    'REGON' => [
        ReportType::Organization,
        ['praw_regon9' => 'unsafe-fixture-value'],
        'praw_regon9',
    ],
    'NIP' => [
        ReportType::Organization,
        ['praw_nip' => 'unsafe-fixture-value'],
        'praw_nip',
    ],
]);
