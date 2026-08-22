<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

use GusApi\BulkReportTypes;

enum BulkReportType: string
{
    case NewLegalEntitiesAndNaturalPersons = BulkReportTypes::REPORT_NEW_LEGAL_ENTITY_AND_NATURAL_PERSON;
    case UpdatedLegalEntitiesAndNaturalPersons = BulkReportTypes::REPORT_UPDATED_LEGAL_ENTITY_AND_NATURAL_PERSON;
    case DeletedLegalEntitiesAndNaturalPersons = BulkReportTypes::REPORT_DELETED_LEGAL_ENTITY_AND_NATURAL_PERSON;
    case NewLocalUnits = BulkReportTypes::REPORT_NEW_LOCAL_UNITS;
    case UpdatedLocalUnits = BulkReportTypes::REPORT_UPDATED_LOCAL_UNITS;
    case DeletedLocalUnits = BulkReportTypes::REPORT_DELETED_LOCAL_UNITS;
}
