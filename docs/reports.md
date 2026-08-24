[← Documentation](README.md)

# Full and bulk reports

The package exposes the full and bulk report types documented for GUS BIR 1.2
as native PHP enums. This avoids passing protocol report-name strings through
application code.

## Full company reports

Start a search, select a `ReportType`, and call `getFullReports()`:

```php
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Facades\BirRegon;

$reports = BirRegon::forRegon('123456789')
    ->reportType(ReportType::Organization)
    ->getFullReports();
```

Full reports may also start with a NIP or KRS. The package first resolves the
matching records, filters them for compatibility with the selected report type,
and requests each distinct REGON:

```php
$reports = BirRegon::forNip('1234567890')
    ->reportType(ReportType::OrganizationActivity)
    ->getFullReports();
```

The plural method returns an
`Illuminate\Support\Collection<int, FullCompanyReportData>` and does not drop a
compatible entity merely because the input contained one identifier. Duplicate
search rows that target the same report REGON are requested only once.

Each `FullCompanyReportData` contains the selected report type, raw decoded GUS
rows, and a normalized typed projection:

```php
$report->basicData; // CompanyData
$report->reportType; // ReportType
$report->reportData; // list<array<string, string>>
$report->normalized; // NormalizedFullReportData
```

See [Data objects](data-objects.md#fullcompanyreportdata) for the `entity`,
`localUnits`, `pkdActivities`, `partners`, and `unitType` projections. Raw rows
remain available for fields introduced by GUS after the package release.
Empty or unknown-only raw rows are retained there but do not create normalized
phantom objects. Single-row report cardinality is still checked against the
original raw list before normalization omits such rows.

### Singular full-report convenience

`getFullReport()` is available only for a lookup with exactly one distinct,
compatible report target:

```php
$report = BirRegon::forRegon('123456789')
    ->reportType(ReportType::Organization)
    ->getFullReport();
```

If the search has multiple compatible targets, it throws
`BirAmbiguousResultException` before issuing any full-report request. Switch to
`getFullReports()`, or select a concrete REGON from the search collection and
start a new `forRegon()` request. If no returned record supports the selected
`ReportType`, both methods throw `BirValidationException`.

### Available full report types

| Enum case | GUS report name | Scope |
| --- | --- | --- |
| `NaturalPerson` | `BIR12OsFizycznaDaneOgolne` | Natural person from silo 1, 2, 3, or 4 |
| `NaturalPersonCeidg` | `BIR12OsFizycznaDzialalnoscCeidg` | CEIDG activity |
| `NaturalPersonAgro` | `BIR12OsFizycznaDzialalnoscRolnicza` | Agricultural activity |
| `NaturalPersonOther` | `BIR12OsFizycznaDzialalnoscPozostala` | Other natural-person activity |
| `NaturalPersonDeletedBefore20141108` | `BIR12OsFizycznaDzialalnoscSkreslonaDo20141108` | Activity deleted before 2014-11-08 |
| `NaturalPersonLocals` | `BIR12OsFizycznaListaJednLokalnych` | Local units of a natural person |
| `NaturalPersonLocal` | `BIR12JednLokalnaOsFizycznej` | A natural-person local unit |
| `NaturalPersonActivity` | `BIR12OsFizycznaPkd` | Natural-person PKD activity |
| `NaturalPersonLocalActivity` | `BIR12JednLokalnaOsFizycznejPkd` | Local-unit PKD activity for a natural person |
| `Organization` | `BIR12OsPrawna` | Legal entity or organizational unit |
| `OrganizationActivity` | `BIR12OsPrawnaPkd` | Organization PKD activity |
| `OrganizationLocals` | `BIR12OsPrawnaListaJednLokalnych` | Local units of an organization |
| `OrganizationLocal` | `BIR12JednLokalnaOsPrawnej` | An organization local unit |
| `OrganizationLocalWithNip` | `BIR121JednLokalnaOsPrawnej` | An organization local unit, including NIP data |
| `OrganizationLocalActivity` | `BIR12JednLokalnaOsPrawnejPkd` | Local-unit PKD activity for an organization |
| `OrganizationPartners` | `BIR12OsPrawnaSpCywilnaWspolnicy` | Organization partners |
| `UnitType` | `BIR12TypPodmiotu` | Unit type |

The selected report must match the strict `EntityType`, `Silo`, and REGON length
returned by the search. The package rejects an incompatible selection before a
full-report request. GUS still decides whether an applicable report is
available and which fields it contains.

`ReportType::NaturalPerson` is the general natural-person report and accepts
the historical `Silo::DeletedBefore20141108` (`4`) as well as the active
natural-person silos. This does not make every natural-person report compatible
with silo 4: `NaturalPersonActivity` and `NaturalPersonLocals` remain limited to
silos 1, 2, and 3, while `NaturalPersonDeletedBefore20141108` is the dedicated
activity report for the historical silo.

## Bulk reports

Bulk reports describe registry changes for a given date. Start with
`forDate()`, select a `BulkReportType`, and call `get()`:

```php
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Facades\BirRegon;
use DateTimeImmutable;
use DateTimeZone;

$date = new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Warsaw'));

$report = BirRegon::forDate($date)
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
$report->reportData; // list<string>
```

Calling a report method without first selecting `reportType()` throws a
`BirException`.

Continue with [Data objects](data-objects.md).
