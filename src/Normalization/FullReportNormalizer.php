<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Normalization;

use cieplik206\BirRegon\Data\Reports\ActivityLifecycleData;
use cieplik206\BirRegon\Data\Reports\AddressData;
use cieplik206\BirRegon\Data\Reports\CodeNameData;
use cieplik206\BirRegon\Data\Reports\ContactData;
use cieplik206\BirRegon\Data\Reports\EntityDetailsData;
use cieplik206\BirRegon\Data\Reports\EntityIdentityData;
use cieplik206\BirRegon\Data\Reports\LegalFormData;
use cieplik206\BirRegon\Data\Reports\NaturalPersonActivityKindsData;
use cieplik206\BirRegon\Data\Reports\NormalizedFullReportData;
use cieplik206\BirRegon\Data\Reports\PartnerData;
use cieplik206\BirRegon\Data\Reports\PersonNameData;
use cieplik206\BirRegon\Data\Reports\PkdActivityData;
use cieplik206\BirRegon\Data\Reports\RegistryData;
use cieplik206\BirRegon\Enums\ActivityStatus;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use DateTimeImmutable;

final readonly class FullReportNormalizer
{
    /**
     * @param  list<array<string, string>>  $rows
     */
    public function normalize(
        ReportType $reportType,
        #[\SensitiveParameter] array $rows,
    ): NormalizedFullReportData {
        $this->assertCardinality($reportType, $rows);

        $nonEmptyRows = [];

        foreach ($rows as $row) {
            if ($row !== []) {
                $nonEmptyRows[] = $row;
            }
        }

        $reportRows = [];

        foreach ($nonEmptyRows as $row) {
            $reportRows[] = new ReportRow($reportType, $row);
        }

        return match ($reportType) {
            ReportType::NaturalPerson => $this->naturalPersonGeneral($reportRows[0] ?? null),
            ReportType::NaturalPersonCeidg,
            ReportType::NaturalPersonAgro,
            ReportType::NaturalPersonOther,
            ReportType::NaturalPersonDeletedBefore20141108 => $this->naturalPersonActivity(
                $reportType,
                $reportRows[0] ?? null,
            ),
            ReportType::NaturalPersonLocals => $this->localUnits($reportRows, naturalPerson: true),
            ReportType::NaturalPersonLocal => $this->naturalPersonLocal($reportRows[0] ?? null),
            ReportType::NaturalPersonActivity,
            ReportType::NaturalPersonLocalActivity,
            ReportType::OrganizationActivity,
            ReportType::OrganizationLocalActivity => $this->pkdActivities($reportType, $reportRows),
            ReportType::Organization => $this->organization($reportRows[0] ?? null),
            ReportType::OrganizationLocals => $this->localUnits($reportRows, naturalPerson: false),
            ReportType::OrganizationLocal,
            ReportType::OrganizationLocalWithNip => $this->organizationLocal(
                $reportRows[0] ?? null,
                withNip: $reportType === ReportType::OrganizationLocalWithNip,
            ),
            ReportType::OrganizationPartners => $this->partners($reportRows),
            ReportType::UnitType => new NormalizedFullReportData(
                unitType: ($reportRows[0] ?? null)?->entityType('Typ'),
            ),
        };
    }

    /** @param list<array<string, string>> $rows */
    private function assertCardinality(
        ReportType $reportType,
        #[\SensitiveParameter] array $rows,
    ): void {
        $allowsManyRows = match ($reportType) {
            ReportType::NaturalPersonLocals,
            ReportType::NaturalPersonActivity,
            ReportType::NaturalPersonLocalActivity,
            ReportType::OrganizationActivity,
            ReportType::OrganizationLocals,
            ReportType::OrganizationLocalActivity,
            ReportType::OrganizationPartners => true,
            default => false,
        };

        if (! $allowsManyRows && count($rows) > 1) {
            throw new BirProtocolException(
                "GUS BIR returned multiple rows for the single-row {$reportType->value} report.",
            );
        }
    }

    private function naturalPersonGeneral(
        #[\SensitiveParameter] ?ReportRow $row,
    ): NormalizedFullReportData {
        if ($row === null) {
            return new NormalizedFullReportData;
        }

        $activityKinds = new NaturalPersonActivityKindsData(
            ceidgCount: $row->nonNegativeInteger('fiz_dzialalnoscCeidg'),
            agricultureCount: $row->nonNegativeInteger('fiz_dzialalnoscRolnicza'),
            otherCount: $row->nonNegativeInteger('fiz_dzialalnoscPozostala'),
            deletedBefore20141108Count: $row->nonNegativeInteger('fiz_dzialalnoscSkreslonaDo20141108'),
        );

        if ($this->allNull([
            $activityKinds->ceidgCount,
            $activityKinds->agricultureCount,
            $activityKinds->otherCount,
            $activityKinds->deletedBefore20141108Count,
        ])) {
            $activityKinds = null;
        }

        return $this->normalizedEntity($row, new EntityDetailsData(
            identity: new EntityIdentityData(
                regon: $row->regon([9], 'fiz_regon9'),
                nip: $row->nip('fiz_nip'),
                nipStatus: $row->nipStatus('fiz_statusNip'),
                name: null,
                shortName: null,
                personName: $this->personName(
                    $row,
                    'fiz_imie1',
                    'fiz_imie2',
                    'fiz_nazwisko',
                ),
            ),
            address: null,
            contact: null,
            legalForm: $this->legalForm($row, 'fiz'),
            registry: null,
            lifecycle: new ActivityLifecycleData(
                createdAt: null,
                startedAt: null,
                registeredInRegonAt: $row->date('fiz_dataWpisuPodmiotuDoRegon'),
                changedAt: $row->date('fiz_dataZaistnieniaZmiany'),
                suspendedAt: null,
                resumedAt: null,
                endedAt: null,
                removedFromRegonAt: $row->date('fiz_dataSkresleniaPodmiotuZRegon'),
                bankruptcyDeclaredAt: null,
                bankruptcyProceedingsEndedAt: null,
                status: ActivityStatus::Unknown,
            ),
            activityKinds: $activityKinds,
            silo: null,
            siloSymbol: null,
            siloName: null,
            localUnitCount: $row->nonNegativeInteger('fiz_liczbaJednLokalnych'),
        ));
    }

    private function naturalPersonActivity(
        ReportType $reportType,
        #[\SensitiveParameter]
        ?ReportRow $row,
    ): NormalizedFullReportData {
        if ($row === null) {
            return new NormalizedFullReportData;
        }

        $registry = match ($reportType) {
            ReportType::NaturalPersonCeidg => $this->registry(
                row: $row,
                numberFields: ['fizC_numerWRejestrzeEwidencji'],
                enteredFields: ['fizC_dataWpisuDoRejestruEwidencji'],
                removedFields: ['fizC_dataSkresleniaZRejestruEwidencji'],
                authorityCodeFields: ['fizC_OrganRejestrowy_Symbol'],
                authorityNameFields: ['fizC_OrganRejestrowy_Nazwa'],
                typeCodeFields: ['fizC_RodzajRejestru_Symbol'],
                typeNameFields: ['fizC_RodzajRejestru_Nazwa'],
                notStartedFields: ['fizC_NiePodjetoDzialalnosci'],
            ),
            ReportType::NaturalPersonOther => $this->registry(
                row: $row,
                numberFields: ['fizP_numerWRejestrzeEwidencji'],
                enteredFields: ['fizP_dataWpisuDoRejestruEwidencji'],
                authorityCodeFields: ['fizP_OrganRejestrowy_Symbol'],
                authorityNameFields: ['fizP_OrganRejestrowy_Nazwa'],
                typeCodeFields: ['fizP_RodzajRejestru_Symbol'],
                typeNameFields: ['fizP_RodzajRejestru_Nazwa'],
            ),
            default => null,
        };
        $removedFields = $reportType === ReportType::NaturalPersonAgro
            ? [
                'fiz_dataSkresleniaDzialalanosciZRegon',
                'fiz_dataSkresleniaDzialalnosciZRegon',
            ]
            : ['fiz_dataSkresleniaDzialalnosciZRegon'];
        $forcedStatus = $reportType === ReportType::NaturalPersonDeletedBefore20141108
            ? ActivityStatus::Inactive
            : null;

        return $this->normalizedEntity($row, new EntityDetailsData(
            identity: new EntityIdentityData(
                regon: $row->regon([9], 'fiz_regon9'),
                nip: null,
                nipStatus: null,
                name: $row->string('fiz_nazwa'),
                shortName: $row->string('fiz_nazwaSkrocona'),
                personName: null,
            ),
            address: $this->address($row, 'fiz'),
            contact: $this->contact($row, 'fiz'),
            legalForm: null,
            registry: $registry,
            lifecycle: $this->lifecycle(
                row: $row,
                createdFields: ['fiz_dataPowstania'],
                startedFields: ['fiz_dataRozpoczeciaDzialalnosci'],
                registeredFields: ['fiz_dataWpisuDzialalnosciDoRegon'],
                changedFields: ['fiz_dataZaistnieniaZmianyDzialalnosci'],
                suspendedFields: ['fiz_dataZawieszeniaDzialalnosci'],
                resumedFields: ['fiz_dataWznowieniaDzialalnosci'],
                endedFields: ['fiz_dataZakonczeniaDzialalnosci'],
                removedFields: $removedFields,
                bankruptcyFields: ['fiz_dataOrzeczeniaOUpadlosci'],
                bankruptcyEndedFields: ['fiz_dataZakonczeniaPostepowaniaUpadlosciowego'],
                notStarted: $registry?->notStarted,
                forcedStatus: $forcedStatus,
            ),
            activityKinds: null,
            silo: match ($reportType) {
                ReportType::NaturalPersonCeidg => Silo::Ceidg,
                ReportType::NaturalPersonAgro => Silo::Agriculture,
                ReportType::NaturalPersonOther => Silo::Other,
                ReportType::NaturalPersonDeletedBefore20141108 => Silo::DeletedBefore20141108,
                default => null,
            },
            siloSymbol: null,
            siloName: null,
            localUnitCount: null,
        ));
    }

    private function naturalPersonLocal(
        #[\SensitiveParameter] ?ReportRow $row,
    ): NormalizedFullReportData {
        if ($row === null) {
            return new NormalizedFullReportData;
        }

        $registry = $this->registry(
            row: $row,
            numberFields: ['lokfiz_numerwRejestrzeEwidencji', 'lokfiz_numerWRejestrzeEwidencji'],
            enteredFields: ['lokfiz_dataWpisuDoRejestruEwidencji'],
            authorityCodeFields: ['lokfiz_OrganRejestrowy_Symbol'],
            authorityNameFields: ['lokfiz_OrganRejestrowy_Nazwa'],
            typeCodeFields: ['lokfiz_RodzajRejestru_Symbol'],
            typeNameFields: ['lokfiz_RodzajRejestru_Nazwa'],
            notStartedFields: ['lokfizC_NiePodjetoDzialalnosci'],
        );

        return $this->normalizedEntity($row, new EntityDetailsData(
            identity: new EntityIdentityData(
                regon: $row->regon([14], 'lokfiz_regon14'),
                nip: null,
                nipStatus: null,
                name: $row->string('lokfiz_nazwa'),
                shortName: null,
                personName: null,
            ),
            address: $this->address($row, 'lokfiz'),
            contact: null,
            legalForm: $this->financingForm($row, 'lokfiz'),
            registry: $registry,
            lifecycle: $this->lifecycle(
                row: $row,
                createdFields: ['lokfiz_dataPowstania'],
                startedFields: ['lokfiz_dataRozpoczeciaDzialalnosci'],
                registeredFields: ['lokfiz_dataWpisuDoRegon'],
                changedFields: ['lokfiz_dataZaistnieniaZmiany'],
                suspendedFields: ['lokfiz_dataZawieszeniaDzialalnosci'],
                resumedFields: ['lokfiz_dataWznowieniaDzialalnosci'],
                endedFields: ['lokfiz_dataZakonczeniaDzialalnosci'],
                removedFields: ['lokfiz_dataSkresleniaZRegon'],
                notStarted: $registry?->notStarted,
            ),
            activityKinds: null,
            silo: $row->silo('lokfiz_silosID', 'lokfiz_SilosID'),
            siloSymbol: $row->string('lokfiz_silos_Symbol', 'lokfiz_SilosSymbol'),
            siloName: $row->string('lokfiz_silos_Nazwa'),
            localUnitCount: null,
        ));
    }

    private function organization(
        #[\SensitiveParameter] ?ReportRow $row,
    ): NormalizedFullReportData {
        if ($row === null) {
            return new NormalizedFullReportData;
        }

        return $this->normalizedEntity($row, new EntityDetailsData(
            identity: new EntityIdentityData(
                regon: $row->regon([9], 'praw_regon9'),
                nip: $row->nip('praw_nip'),
                nipStatus: $row->nipStatus('praw_statusNip'),
                name: $row->string('praw_nazwa'),
                shortName: $row->string('praw_nazwaSkrocona'),
                personName: null,
            ),
            address: $this->address($row, 'praw'),
            contact: $this->contact($row, 'praw'),
            legalForm: $this->legalForm($row, 'praw'),
            registry: $this->registry(
                row: $row,
                numberFields: ['praw_numerWRejestrzeEwidencji'],
                enteredFields: ['praw_dataWpisuDoRejestruEwidencji'],
                authorityCodeFields: ['praw_organRejestrowy_Symbol'],
                authorityNameFields: ['praw_organRejestrowy_Nazwa'],
                typeCodeFields: ['praw_rodzajRejestruEwidencji_Symbol'],
                typeNameFields: ['praw_rodzajRejestruEwidencji_Nazwa'],
            ),
            lifecycle: $this->lifecycle(
                row: $row,
                createdFields: ['praw_dataPowstania'],
                startedFields: ['praw_dataRozpoczeciaDzialalnosci'],
                registeredFields: ['praw_dataWpisuDoRegon'],
                changedFields: ['praw_dataZaistnieniaZmiany'],
                suspendedFields: ['praw_dataZawieszeniaDzialalnosci'],
                resumedFields: ['praw_dataWznowieniaDzialalnosci'],
                endedFields: ['praw_dataZakonczeniaDzialalnosci'],
                removedFields: ['praw_dataSkresleniaZRegon'],
                bankruptcyFields: ['praw_dataOrzeczeniaOUpadlosci'],
                bankruptcyEndedFields: ['praw_dataZakonczeniaPostepowaniaUpadlosciowego'],
            ),
            activityKinds: null,
            silo: Silo::LegalUnits,
            siloSymbol: null,
            siloName: null,
            localUnitCount: $row->nonNegativeInteger('praw_liczbaJednLokalnych'),
        ));
    }

    private function organizationLocal(
        #[\SensitiveParameter]
        ?ReportRow $row,
        bool $withNip,
    ): NormalizedFullReportData {
        if ($row === null) {
            return new NormalizedFullReportData;
        }

        return $this->normalizedEntity($row, new EntityDetailsData(
            identity: new EntityIdentityData(
                regon: $row->regon([14], 'lokpraw_regon14'),
                nip: $withNip ? $row->nip('lokpraw_nip') : null,
                nipStatus: $withNip ? $row->nipStatus('lokpraw_statusNip') : null,
                name: $row->string('lokpraw_nazwa'),
                shortName: null,
                personName: null,
            ),
            address: $this->address($row, 'lokpraw'),
            contact: null,
            legalForm: $this->financingForm($row, 'lokpraw'),
            registry: $this->registry(
                row: $row,
                numberFields: [
                    'lokpraw_numerWrejestrzeEwidencji',
                    'lokpraw_numerWRejestrzeEwidencji',
                ],
                enteredFields: ['lokpraw_dataWpisuDoRejestruEwidencji'],
                authorityCodeFields: ['lokpraw_organRejestrowy_Symbol'],
                authorityNameFields: ['lokpraw_organRejestrowy_Nazwa'],
                typeCodeFields: ['lokpraw_rodzajRejestruEwidencji_Symbol'],
                typeNameFields: ['lokpraw_rodzajRejestruEwidencji_Nazwa'],
            ),
            lifecycle: $this->lifecycle(
                row: $row,
                createdFields: ['lokpraw_dataPowstania'],
                startedFields: ['lokpraw_dataRozpoczeciaDzialalnosci'],
                registeredFields: ['lokpraw_dataWpisuDoRegon'],
                changedFields: ['lokpraw_dataZaistnieniaZmiany'],
                suspendedFields: ['lokpraw_dataZawieszeniaDzialalnosci'],
                resumedFields: ['lokpraw_dataWznowieniaDzialalnosci'],
                endedFields: ['lokpraw_dataZakonczeniaDzialalnosci'],
                removedFields: ['lokpraw_dataSkresleniaZRegon'],
            ),
            activityKinds: null,
            silo: Silo::LegalUnits,
            siloSymbol: null,
            siloName: null,
            localUnitCount: null,
        ));
    }

    /**
     * @param  list<ReportRow>  $rows
     */
    private function localUnits(
        #[\SensitiveParameter] array $rows,
        bool $naturalPerson,
    ): NormalizedFullReportData {
        $localUnits = [];

        foreach ($rows as $row) {
            $prefix = $naturalPerson ? 'lokfiz' : 'lokpraw';
            $localUnit = new EntityDetailsData(
                identity: new EntityIdentityData(
                    regon: $row->regon([14], "{$prefix}_regon14"),
                    nip: null,
                    nipStatus: null,
                    name: $row->string("{$prefix}_nazwa"),
                    shortName: null,
                    personName: null,
                ),
                address: $this->address($row, $prefix),
                contact: null,
                legalForm: null,
                registry: null,
                lifecycle: $this->lifecycle(
                    row: $row,
                    createdFields: ["{$prefix}_dataPowstania"],
                    startedFields: ["{$prefix}_dataRozpoczeciaDzialalnosci"],
                    registeredFields: ["{$prefix}_dataWpisuDoRegon"],
                    suspendedFields: ["{$prefix}_dataZawieszeniaDzialalnosci"],
                    resumedFields: ["{$prefix}_dataWznowieniaDzialalnosci"],
                    endedFields: ["{$prefix}_dataZakonczeniaDzialalnosci"],
                    removedFields: ["{$prefix}_dataSkresleniaZRegon"],
                ),
                activityKinds: null,
                silo: $naturalPerson
                    ? $row->silo('lokfiz_silosID', 'lokfiz_SilosID')
                    : Silo::LegalUnits,
                siloSymbol: $naturalPerson
                    ? $row->string('lokfiz_silos_Symbol', 'lokfiz_SilosSymbol')
                    : null,
                siloName: $naturalPerson ? $row->string('lokfiz_silos_Nazwa') : null,
                localUnitCount: null,
            );

            if ($row->hasRecognizedValue()) {
                $localUnits[] = $localUnit;
            }
        }

        return new NormalizedFullReportData(localUnits: $localUnits);
    }

    /**
     * @param  list<ReportRow>  $rows
     */
    private function pkdActivities(
        ReportType $reportType,
        #[\SensitiveParameter]
        array $rows,
    ): NormalizedFullReportData {
        $prefix = match ($reportType) {
            ReportType::NaturalPersonActivity => 'fiz',
            ReportType::NaturalPersonLocalActivity => 'lokfiz',
            ReportType::OrganizationActivity => 'praw',
            ReportType::OrganizationLocalActivity => 'lokpraw',
            default => throw new BirProtocolException('Unsupported normalized PKD report type.'),
        };
        $activities = [];

        foreach ($rows as $row) {
            $silo = match ($reportType) {
                ReportType::NaturalPersonActivity => $row->silo('fiz_SilosID'),
                ReportType::NaturalPersonLocalActivity => $row->silo(
                    'lokfiz_SilosId',
                    'lokfiz_SilosID',
                    'lokfiz_silosID',
                ),
                default => Silo::LegalUnits,
            };
            $siloSymbol = match ($reportType) {
                ReportType::NaturalPersonActivity => $row->string('fiz_SilosSymbol'),
                ReportType::NaturalPersonLocalActivity => $row->string(
                    'lokfiz_SilosSymbol',
                    'lokfiz_silos_Symbol',
                ),
                default => null,
            };
            $activity = new PkdActivityData(
                classification: $row->boundedString(8, "{$prefix}_pkdWersja"),
                code: $row->string("{$prefix}_pkdKod"),
                name: $row->string("{$prefix}_pkdNazwa"),
                predominant: $row->flag("{$prefix}_pkdPrzewazajace"),
                silo: $silo,
                siloSymbol: $siloSymbol,
                removedFromRegonAt: $reportType === ReportType::NaturalPersonActivity
                    ? $row->date('fiz_dataSkresleniaDzialalnosciZRegon')
                    : null,
            );

            if ($row->hasRecognizedValue()) {
                $activities[] = $activity;
            }
        }

        return new NormalizedFullReportData(pkdActivities: $activities);
    }

    /** @param list<ReportRow> $rows */
    private function partners(
        #[\SensitiveParameter] array $rows,
    ): NormalizedFullReportData {
        $partners = [];

        foreach ($rows as $row) {
            $partner = new PartnerData(
                regon: $row->regon([9], 'wspolsc_regonWspolnikSpolki'),
                personName: $this->personName(
                    $row,
                    'wspolsc_imiePierwsze',
                    'wspolsc_imieDrugie',
                    'wspolsc_nazwisko',
                ),
                companyName: $row->string('wspolsc_firmaNazwa'),
            );

            if ($row->hasRecognizedValue()) {
                $partners[] = $partner;
            }
        }

        return new NormalizedFullReportData(partners: $partners);
    }

    private function address(
        #[\SensitiveParameter] ReportRow $row,
        string $prefix,
    ): ?AddressData {
        $address = new AddressData(
            countryCode: $row->string("{$prefix}_adSiedzKraj_Symbol"),
            countryName: $row->string("{$prefix}_adSiedzKraj_Nazwa"),
            provinceCode: $row->string("{$prefix}_adSiedzWojewodztwo_Symbol"),
            provinceName: $row->string("{$prefix}_adSiedzWojewodztwo_Nazwa"),
            districtCode: $row->string("{$prefix}_adSiedzPowiat_Symbol"),
            districtName: $row->string("{$prefix}_adSiedzPowiat_Nazwa"),
            communeCode: $row->string("{$prefix}_adSiedzGmina_Symbol"),
            communeName: $row->string("{$prefix}_adSiedzGmina_Nazwa"),
            postalCode: $row->string("{$prefix}_adSiedzKodPocztowy"),
            postCityCode: $row->string("{$prefix}_adSiedzMiejscowoscPoczty_Symbol"),
            postCityName: $row->string("{$prefix}_adSiedzMiejscowoscPoczty_Nazwa"),
            cityCode: $row->string("{$prefix}_adSiedzMiejscowosc_Symbol"),
            cityName: $row->string("{$prefix}_adSiedzMiejscowosc_Nazwa"),
            streetCode: $row->string("{$prefix}_adSiedzUlica_Symbol"),
            streetName: $row->string("{$prefix}_adSiedzUlica_Nazwa"),
            buildingNumber: $row->string("{$prefix}_adSiedzNumerNieruchomosci"),
            apartmentNumber: $row->string("{$prefix}_adSiedzNumerLokalu"),
            nonStandardLocation: $row->string("{$prefix}_adSiedzNietypoweMiejsceLokalizacji"),
        );

        return $this->allNull(array_values(get_object_vars($address))) ? null : $address;
    }

    private function contact(
        #[\SensitiveParameter] ReportRow $row,
        string $prefix,
    ): ?ContactData {
        $contact = new ContactData(
            phoneNumber: $row->string("{$prefix}_numerTelefonu"),
            phoneExtension: $row->string("{$prefix}_numerWewnetrznyTelefonu"),
            faxNumber: $row->string("{$prefix}_numerFaksu"),
            email: $row->string("{$prefix}_adresEmail"),
            secondaryEmail: $row->string("{$prefix}_adresEmail2"),
            website: $row->string("{$prefix}_adresStronyinternetowej"),
        );

        return $this->allNull(array_values(get_object_vars($contact))) ? null : $contact;
    }

    private function legalForm(
        #[\SensitiveParameter] ReportRow $row,
        string $prefix,
    ): ?LegalFormData {
        $legalForm = new LegalFormData(
            basic: $this->codeName(
                $row,
                ["{$prefix}_podstawowaFormaPrawna_Symbol"],
                ["{$prefix}_podstawowaFormaPrawna_Nazwa"],
            ),
            specific: $this->codeName(
                $row,
                ["{$prefix}_szczegolnaFormaPrawna_Symbol"],
                ["{$prefix}_szczegolnaFormaPrawna_Nazwa"],
            ),
            financing: $this->codeName(
                $row,
                ["{$prefix}_formaFinansowania_Symbol", "{$prefix}_FormaFinansowania_Symbol"],
                ["{$prefix}_formaFinansowania_Nazwa", "{$prefix}_FormaFinansowania_Nazwa"],
            ),
            ownership: $this->codeName(
                $row,
                ["{$prefix}_formaWlasnosci_Symbol"],
                ["{$prefix}_formaWlasnosci_Nazwa"],
            ),
            foundingBody: $this->codeName(
                $row,
                ["{$prefix}_organZalozycielski_Symbol"],
                ["{$prefix}_organZalozycielski_Nazwa"],
            ),
        );

        return $this->allNull(array_values(get_object_vars($legalForm))) ? null : $legalForm;
    }

    private function financingForm(
        #[\SensitiveParameter] ReportRow $row,
        string $prefix,
    ): ?LegalFormData {
        $financing = $this->codeName(
            $row,
            ["{$prefix}_formaFinansowania_Symbol", "{$prefix}_FormaFinansowania_Symbol"],
            ["{$prefix}_formaFinansowania_Nazwa", "{$prefix}_FormaFinansowania_Nazwa"],
        );

        return $financing === null
            ? null
            : new LegalFormData(
                basic: null,
                specific: null,
                financing: $financing,
                ownership: null,
                foundingBody: null,
            );
    }

    /**
     * @param  list<string>  $numberFields
     * @param  list<string>  $enteredFields
     * @param  list<string>  $removedFields
     * @param  list<string>  $authorityCodeFields
     * @param  list<string>  $authorityNameFields
     * @param  list<string>  $typeCodeFields
     * @param  list<string>  $typeNameFields
     * @param  list<string>  $notStartedFields
     */
    private function registry(
        #[\SensitiveParameter]
        ReportRow $row,
        array $numberFields = [],
        array $enteredFields = [],
        array $removedFields = [],
        array $authorityCodeFields = [],
        array $authorityNameFields = [],
        array $typeCodeFields = [],
        array $typeNameFields = [],
        array $notStartedFields = [],
    ): ?RegistryData {
        $registry = new RegistryData(
            number: $this->string($row, $numberFields),
            enteredAt: $this->date($row, $enteredFields),
            removedAt: $this->date($row, $removedFields),
            authority: $this->codeName($row, $authorityCodeFields, $authorityNameFields),
            type: $this->codeName($row, $typeCodeFields, $typeNameFields),
            notStarted: $this->boolean($row, $notStartedFields),
        );

        return $this->allNull(array_values(get_object_vars($registry))) ? null : $registry;
    }

    /**
     * @param  list<string>  $createdFields
     * @param  list<string>  $startedFields
     * @param  list<string>  $registeredFields
     * @param  list<string>  $changedFields
     * @param  list<string>  $suspendedFields
     * @param  list<string>  $resumedFields
     * @param  list<string>  $endedFields
     * @param  list<string>  $removedFields
     * @param  list<string>  $bankruptcyFields
     * @param  list<string>  $bankruptcyEndedFields
     */
    private function lifecycle(
        #[\SensitiveParameter]
        ReportRow $row,
        array $createdFields = [],
        array $startedFields = [],
        array $registeredFields = [],
        array $changedFields = [],
        array $suspendedFields = [],
        array $resumedFields = [],
        array $endedFields = [],
        array $removedFields = [],
        array $bankruptcyFields = [],
        array $bankruptcyEndedFields = [],
        ?bool $notStarted = null,
        ?ActivityStatus $forcedStatus = null,
    ): ActivityLifecycleData {
        $createdAt = $this->date($row, $createdFields);
        $startedAt = $this->date($row, $startedFields);
        $registeredInRegonAt = $this->date($row, $registeredFields);
        $changedAt = $this->date($row, $changedFields);
        $suspendedAt = $this->date($row, $suspendedFields);
        $resumedAt = $this->date($row, $resumedFields);
        $endedAt = $this->date($row, $endedFields);
        $removedFromRegonAt = $this->date($row, $removedFields);
        $bankruptcyDeclaredAt = $this->date($row, $bankruptcyFields);
        $bankruptcyProceedingsEndedAt = $this->date($row, $bankruptcyEndedFields);
        $status = $forcedStatus ?? $this->activityStatus(
            startedAt: $startedAt,
            suspendedAt: $suspendedAt,
            resumedAt: $resumedAt,
            endedAt: $endedAt,
            removedFromRegonAt: $removedFromRegonAt,
            bankruptcyDeclaredAt: $bankruptcyDeclaredAt,
            bankruptcyProceedingsEndedAt: $bankruptcyProceedingsEndedAt,
            notStarted: $notStarted,
        );

        return new ActivityLifecycleData(
            createdAt: $createdAt,
            startedAt: $startedAt,
            registeredInRegonAt: $registeredInRegonAt,
            changedAt: $changedAt,
            suspendedAt: $suspendedAt,
            resumedAt: $resumedAt,
            endedAt: $endedAt,
            removedFromRegonAt: $removedFromRegonAt,
            bankruptcyDeclaredAt: $bankruptcyDeclaredAt,
            bankruptcyProceedingsEndedAt: $bankruptcyProceedingsEndedAt,
            status: $status,
        );
    }

    private function activityStatus(
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $suspendedAt,
        ?DateTimeImmutable $resumedAt,
        ?DateTimeImmutable $endedAt,
        ?DateTimeImmutable $removedFromRegonAt,
        ?DateTimeImmutable $bankruptcyDeclaredAt,
        ?DateTimeImmutable $bankruptcyProceedingsEndedAt,
        ?bool $notStarted,
    ): ActivityStatus {
        if (
            $notStarted === true
            || $endedAt !== null
            || $removedFromRegonAt !== null
            || $bankruptcyDeclaredAt !== null
            || $bankruptcyProceedingsEndedAt !== null
        ) {
            return ActivityStatus::Inactive;
        }

        if ($suspendedAt !== null) {
            return $resumedAt !== null && $suspendedAt < $resumedAt
                ? ActivityStatus::Active
                : ActivityStatus::Inactive;
        }

        return $startedAt !== null || $resumedAt !== null
            ? ActivityStatus::Active
            : ActivityStatus::Unknown;
    }

    private function personName(
        #[\SensitiveParameter]
        ReportRow $row,
        string $firstNameField,
        string $secondNameField,
        string $lastNameField,
    ): ?PersonNameData {
        $personName = new PersonNameData(
            firstName: $row->string($firstNameField),
            secondName: $row->string($secondNameField),
            lastName: $row->string($lastNameField),
        );

        return $this->allNull(array_values(get_object_vars($personName))) ? null : $personName;
    }

    /**
     * @param  list<string>  $codeFields
     * @param  list<string>  $nameFields
     */
    private function codeName(
        #[\SensitiveParameter]
        ReportRow $row,
        array $codeFields,
        array $nameFields,
    ): ?CodeNameData {
        $code = $this->string($row, $codeFields);
        $name = $this->string($row, $nameFields);

        return $code === null && $name === null ? null : new CodeNameData($code, $name);
    }

    private function normalizedEntity(
        #[\SensitiveParameter] ReportRow $row,
        #[\SensitiveParameter] EntityDetailsData $entity,
    ): NormalizedFullReportData {
        return $row->hasRecognizedValue()
            ? new NormalizedFullReportData(entity: $entity)
            : new NormalizedFullReportData;
    }

    /** @param list<string> $fields */
    private function string(
        #[\SensitiveParameter] ReportRow $row,
        array $fields,
    ): ?string {
        return $fields === [] ? null : $row->string(...$fields);
    }

    /** @param list<string> $fields */
    private function date(
        #[\SensitiveParameter] ReportRow $row,
        array $fields,
    ): ?DateTimeImmutable {
        return $fields === [] ? null : $row->date(...$fields);
    }

    /** @param list<string> $fields */
    private function boolean(
        #[\SensitiveParameter] ReportRow $row,
        array $fields,
    ): ?bool {
        return $fields === [] ? null : $row->boolean(...$fields);
    }

    /** @param array<int, mixed> $values */
    private function allNull(#[\SensitiveParameter] array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }
}
