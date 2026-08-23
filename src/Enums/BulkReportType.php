<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum BulkReportType: string
{
    case NewLegalEntitiesAndNaturalPersons = 'BIR11NowePodmiotyPrawneOrazDzialalnosciOsFizycznych';
    case UpdatedLegalEntitiesAndNaturalPersons = 'BIR11AktualizowanePodmiotyPrawneOrazDzialalnosciOsFizycznych';
    case DeletedLegalEntitiesAndNaturalPersons = 'BIR11SkreslonePodmiotyPrawneOrazDzialalnosciOsFizycznych';
    case NewLocalUnits = 'BIR11NoweJednostkiLokalne';
    case UpdatedLocalUnits = 'BIR11AktualizowaneJednostkiLokalne';
    case DeletedLocalUnits = 'BIR11SkresloneJednostkiLokalne';
}
