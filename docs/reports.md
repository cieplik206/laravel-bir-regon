[← Documentation](README.md)

# Full and bulk reports

The package exposes every full and bulk report type supported by
`gusapi/gusapi` as a native PHP enum. This avoids passing undocumented GUS
report-name strings through application code.

## Full company reports

Start a search, select a `ReportType`, and call `getFullReport()`:

```php
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Facades\BirRegon;

$report = BirRegon::forRegon('123456789')
    ->reportType(ReportType::Organization)
    ->getFullReport();
```

Full reports may also start with a NIP or KRS. The package first resolves the
entity's REGON and then requests the selected report:

```php
$report = BirRegon::forNip('1234567890')
    ->reportType(ReportType::OrganizationActivity)
    ->getFullReport();
```

The result is a `FullCompanyReportData` object:

```php
$report->basicData; // CompanyData
$report->reportData; // array<int, array<string, string>>
```

### Available full report types

| Enum case | GUS report scope |
| --- | --- |
| `NaturalPerson` | Natural person |
| `NaturalPersonCeidg` | CEIDG activity |
| `NaturalPersonAgro` | Agricultural activity |
| `NaturalPersonOther` | Other natural-person activity |
| `NaturalPersonDeletedBefore20141108` | Activity deleted before 2014-11-08 |
| `NaturalPersonLocals` | Local units of a natural person |
| `NaturalPersonLocal` | A natural-person local unit |
| `NaturalPersonActivity` | Natural-person PKD activity |
| `NaturalPersonLocalActivity` | Local-unit PKD activity for a natural person |
| `Organization` | Legal entity or organizational unit |
| `OrganizationActivity` | Organization PKD activity |
| `OrganizationLocals` | Local units of an organization |
| `OrganizationLocal` | An organization local unit |
| `OrganizationLocalActivity` | Local-unit PKD activity for an organization |
| `OrganizationPartners` | Organization partners |
| `UnitType` | Unit type |

The selected report must match the entity represented by the REGON. GUS
decides whether a report is available and which fields it contains.

## Bulk reports

Bulk reports describe registry changes for a given date. Start with
`forDate()`, select a `BulkReportType`, and call `get()`:

```php
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Facades\BirRegon;
use DateTimeImmutable;

$report = BirRegon::forDate(new DateTimeImmutable('2026-08-22'))
    ->reportType(BulkReportType::NewLegalEntitiesAndNaturalPersons)
    ->get();
```

`getBulkReport()` is an alias for `get()`.

### Available bulk report types

| Enum case | Registry change |
| --- | --- |
| `NewLegalEntitiesAndNaturalPersons` | Newly registered legal entities and natural persons |
| `UpdatedLegalEntitiesAndNaturalPersons` | Updated legal entities and natural persons |
| `DeletedLegalEntitiesAndNaturalPersons` | Deleted legal entities and natural persons |
| `NewLocalUnits` | Newly registered local units |
| `UpdatedLocalUnits` | Updated local units |
| `DeletedLocalUnits` | Deleted local units |

The result is a `BulkReportData` object:

```php
$report->date; // DateTimeImmutable
$report->reportType; // BulkReportType
$report->reportData; // array<int, string>
```

Calling a report method without first selecting `reportType()` throws a
`BirException`.

Continue with [Data objects](data-objects.md).
