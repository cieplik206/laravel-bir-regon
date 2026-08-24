<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

use cieplik206\BirRegon\Protocol\SearchResult;

enum ReportType: string
{
    case NaturalPerson = 'BIR12OsFizycznaDaneOgolne';
    case NaturalPersonCeidg = 'BIR12OsFizycznaDzialalnoscCeidg';
    case NaturalPersonAgro = 'BIR12OsFizycznaDzialalnoscRolnicza';
    case NaturalPersonOther = 'BIR12OsFizycznaDzialalnoscPozostala';
    case NaturalPersonDeletedBefore20141108 = 'BIR12OsFizycznaDzialalnoscSkreslonaDo20141108';
    case NaturalPersonLocals = 'BIR12OsFizycznaListaJednLokalnych';
    case NaturalPersonLocal = 'BIR12JednLokalnaOsFizycznej';
    case NaturalPersonActivity = 'BIR12OsFizycznaPkd';
    case NaturalPersonLocalActivity = 'BIR12JednLokalnaOsFizycznejPkd';
    case Organization = 'BIR12OsPrawna';
    case OrganizationActivity = 'BIR12OsPrawnaPkd';
    case OrganizationLocals = 'BIR12OsPrawnaListaJednLokalnych';
    case OrganizationLocal = 'BIR12JednLokalnaOsPrawnej';
    case OrganizationLocalWithNip = 'BIR121JednLokalnaOsPrawnej';
    case OrganizationLocalActivity = 'BIR12JednLokalnaOsPrawnejPkd';
    case OrganizationPartners = 'BIR12OsPrawnaSpCywilnaWspolnicy';
    case UnitType = 'BIR12TypPodmiotu';

    public function supports(#[\SensitiveParameter] SearchResult $result): bool
    {
        if (! $result->hasConsistentClassification()) {
            return false;
        }

        $type = $result->type;
        $silo = $result->silo;
        $regonLength = strlen($result->regon);

        return match ($this) {
            self::NaturalPerson => $type === EntityType::NaturalPerson
                && in_array($silo, [
                    Silo::Ceidg,
                    Silo::Agriculture,
                    Silo::Other,
                    Silo::DeletedBefore20141108,
                ], true)
                && $regonLength === 9,
            self::NaturalPersonLocals,
            self::NaturalPersonActivity => $type === EntityType::NaturalPerson
                && in_array($silo, [Silo::Ceidg, Silo::Agriculture, Silo::Other], true)
                && $regonLength === 9,
            self::NaturalPersonCeidg => $type === EntityType::NaturalPerson
                && $silo === Silo::Ceidg
                && $regonLength === 9,
            self::NaturalPersonAgro => $type === EntityType::NaturalPerson
                && $silo === Silo::Agriculture
                && $regonLength === 9,
            self::NaturalPersonOther => $type === EntityType::NaturalPerson
                && $silo === Silo::Other
                && $regonLength === 9,
            self::NaturalPersonDeletedBefore20141108 => $type === EntityType::NaturalPerson
                && $silo === Silo::DeletedBefore20141108
                && $regonLength === 9,
            self::NaturalPersonLocal,
            self::NaturalPersonLocalActivity => $type === EntityType::NaturalPersonLocalUnit
                && in_array($silo, [Silo::Ceidg, Silo::Agriculture, Silo::Other], true)
                && $regonLength === 14,
            self::Organization,
            self::OrganizationActivity,
            self::OrganizationLocals,
            self::OrganizationPartners => $type === EntityType::LegalUnit
                && $silo === Silo::LegalUnits
                && $regonLength === 9,
            self::OrganizationLocal,
            self::OrganizationLocalWithNip,
            self::OrganizationLocalActivity => $type === EntityType::LegalUnitLocalUnit
                && $silo === Silo::LegalUnits
                && $regonLength === 14,
            self::UnitType => in_array($regonLength, [9, 14], true),
        };
    }

    public function reportRegon(#[\SensitiveParameter] SearchResult $result): string
    {
        return $result->regon;
    }

    public function requiresRegon14(): bool
    {
        return in_array($this, [
            self::NaturalPersonLocal,
            self::NaturalPersonLocalActivity,
            self::OrganizationLocal,
            self::OrganizationLocalWithNip,
            self::OrganizationLocalActivity,
        ], true);
    }
}
