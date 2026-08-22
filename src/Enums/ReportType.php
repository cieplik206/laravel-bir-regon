<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

use GusApi\ReportTypes;

enum ReportType: string
{
    case NaturalPerson = ReportTypes::REPORT_PERSON;
    case NaturalPersonCeidg = ReportTypes::REPORT_PERSON_CEIDG;
    case NaturalPersonAgro = ReportTypes::REPORT_PERSON_AGRO;
    case NaturalPersonOther = ReportTypes::REPORT_PERSON_OTHER;
    case NaturalPersonDeletedBefore20141108 = ReportTypes::REPORT_PERSON_DELETED_BEFORE_20141108;
    case NaturalPersonLocals = ReportTypes::REPORT_PERSON_LOCALS;
    case NaturalPersonLocal = ReportTypes::REPORT_PERSON_LOCAL;
    case NaturalPersonActivity = ReportTypes::REPORT_PERSON_ACTIVITY;
    case NaturalPersonLocalActivity = ReportTypes::REPORT_PERSON_LOCAL_ACTIVITY;
    case Organization = ReportTypes::REPORT_ORGANIZATION;
    case OrganizationActivity = ReportTypes::REPORT_ORGANIZATION_ACTIVITY;
    case OrganizationLocals = ReportTypes::REPORT_ORGANIZATION_LOCALS;
    case OrganizationLocal = ReportTypes::REPORT_ORGANIZATION_LOCAL;
    case OrganizationLocalActivity = ReportTypes::REPORT_ORGANIZATION_LOCAL_ACTIVITY;
    case OrganizationPartners = ReportTypes::REPORT_ORGANIZATION_PARTNERS;
    case UnitType = ReportTypes::REPORT_UNIT_TYPE;
}
