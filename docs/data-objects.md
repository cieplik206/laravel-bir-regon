[← Documentation](README.md)

# Data objects

Company, report, service-status, and diagnostics responses are typed objects
based on `spatie/laravel-data`. Singular identifier builders return collections
of these objects because one identifier can have records in several GUS silos.
Data objects can be read through public properties and converted to arrays or
JSON.

```php
$array = $company->toArray();
$json = $company->toJson();
```

## `CompanyData`

Every item returned by a singular or batch search is a `CompanyData`.

| Property | Type | Description |
| --- | --- | --- |
| `regon` | `string` | Primary REGON value |
| `nip` | `?string` | Tax identification number |
| `name` | `string` | Registered entity name |
| `city` | `?string` | Address city |
| `postalCode` | `?string` | Postal code |
| `street` | `?string` | Street name |
| `buildingNumber` | `?string` | Building number |
| `apartmentNumber` | `?string` | Apartment number |
| `province` | `?string` | Voivodeship |
| `district` | `?string` | District |
| `commune` | `?string` | Commune |
| `type` | `EntityType` | Strict GUS entity type |
| `regon14` | `?string` | 14-digit REGON only when GUS returned one |
| `nipStatus` | `?NipStatus` | Revoked or invalidated NIP status; otherwise `null` |
| `silo` | `Silo` | Strict GUS data-silo identifier |
| `activityEndDate` | `?string` | Normalized XML Schema `xs:date` lexical value, optionally with `Z` or a `+/-hh:mm` offset |
| `postCity` | `?string` | Postal city |

Empty optional values returned by GUS are normalized to `null`. `regon14` is
not padded, derived from a REGON-9, or copied from another field.

The closed response vocabularies are represented by backed enums:

| Enum | Cases and protocol values |
| --- | --- |
| `EntityType` | `LegalUnit` (`P`), `NaturalPerson` (`F`), `LegalUnitLocalUnit` (`LP`), `NaturalPersonLocalUnit` (`LF`) |
| `Silo` | `Ceidg` (`1`), `Agriculture` (`2`), `Other` (`3`), `DeletedBefore20141108` (`4`), `LegalUnits` (`6`) |
| `NipStatus` | `Revoked` (`Uchylony`), `Invalidated` (`Unieważniony`) |

An empty `StatusNip` becomes `null`. An undocumented value in one of these
closed fields is treated as an invalid GUS response and results in a
`BirProtocolException`; it is not exposed as a free-form string.

`activityEndDate` follows the complete `xs:date` lexical form accepted by the
GUS schema: `YYYY-MM-DD`, the same date suffixed with `Z`, or the same date with
a legal `+/-hh:mm` offset. XML whitespace is collapsed, but a supplied timezone
suffix is retained. Impossible dates and invalid offsets invalidate the
protocol response.

## `FullCompanyReportData`

Full reports retain the original decoded rows and add a stable typed projection:

```php
$report->basicData; // CompanyData
$report->reportType; // ReportType
$report->reportData; // list<array<string, string>>
$report->normalized; // NormalizedFullReportData
```

`reportData` is the forward-compatible source representation. GUS defines its
keys and they differ between report types; unknown fields remain available
there. `normalized` maps documented fields into one of five shapes:

| Property | Type | Used for |
| --- | --- | --- |
| `entity` | `?EntityDetailsData` | A single legal entity, natural-person activity, or local unit |
| `localUnits` | `list<EntityDetailsData>` | Local-unit list reports |
| `pkdActivities` | `list<PkdActivityData>` | PKD activity reports |
| `partners` | `list<PartnerData>` | Civil-partnership partner reports |
| `unitType` | `?EntityType` | `ReportType::UnitType` |

The nested DTOs group identity, person name, address, contact, legal-form,
registry, lifecycle, and activity-kind fields. Known dates become
`DateTimeImmutable`; known values use `NipStatus`, `Silo`, `EntityType`,
and `ActivityStatus`. `PkdActivityData::$classification` is a nullable string
of at most eight characters because the GUS XSD leaves this version identifier
open for future classifications; it is deliberately not an enum. Missing
components and fields stay `null`; list reports use empty lists.

`ActivityStatus` is deliberately conservative. A termination, removal,
bankruptcy, not-started marker, or an unresolved suspension produces
`Inactive`. A start or resumption date can establish `Active`; when a
suspension date is present, the resumption must be strictly later. When a
partial report contains only identity or historical metadata and no positive
lifecycle evidence, the status is `Unknown`; absence of an end date alone is
never treated as proof that an entity is active.

For the natural-person general report,
`EntityDetailsData::$activityKinds` is a nullable
`NaturalPersonActivityKindsData` with four nullable integer counts:
`ceidgCount`, `agricultureCount`, `otherCount`, and
`deletedBefore20141108Count`. These fields are counts from `xs:int`, not
booleans; zero remains `0`, while an absent value remains `null`.

```php
$entity = $report->normalized->entity;

$entity?->identity->name;
$entity?->address?->cityName;
$entity?->contact?->website;
$entity?->lifecycle?->startedAt;

foreach ($report->normalized->pkdActivities as $activity) {
    $activity->classification;
    $activity->code;
    $activity->predominant;
}
```

Use `reportData` when GUS adds a field that the current typed projection does
not yet cover. Do not infer that a missing normalized property means the raw
field was absent without inspecting the selected report's schema.

Empty rows and rows containing only unknown future fields remain unchanged in
`reportData`, but do not create phantom `entity`, local-unit, PKD, or partner
objects in `normalized`. List projections omit such rows. For report types
whose XSD permits only one row, cardinality is checked against the original raw
row list before empty or unknown-only rows are omitted; multiple raw rows still
raise `BirProtocolException`.

## Untrusted GUS data

Every public string originating at GUS is untrusted. This includes every string
in `CompanyData`, raw `reportData`, normalized report DTOs,
`DiagnosticsData::$message`, and `ServiceStatusData::$message`. The mappers
validate documented structure and known scalar types, but deliberately do
**not** sanitize text. A DTO is a structural projection, not an HTML/XSS,
SQL-injection, URL, email, spreadsheet-injection, or log-forging boundary.

- Render text with escaped Blade expressions such as `{{ $name }}`; do not pass
  GUS text to `{!! $name !!}`.
- Persist and query through Eloquent or parameter-bound SQL; never concatenate a
  GUS value into an SQL statement, column name, or `orderByRaw()` expression.
- Before creating a clickable website link, parse the value and allowlist only
  `http` and `https`. Validate an email address before creating a `mailto:` URL.
- Before CSV or XLSX export, neutralize cells whose first character is `=`, `+`,
  `-`, `@`, TAB, or CR. Quoting a CSV cell alone does not stop formula
  evaluation; prefix or otherwise encode the value according to the exporter.
- Before logging a GUS string, normalize CR, LF, Unicode
  format/bidirectional controls, and other control characters, bound its
  normalized length, or place a normalized value in structured logging
  context. Never concatenate an unchanged GUS message into a log line where it
  can forge additional entries.

## `BulkReportData`

```php
$report->date; // DateTimeImmutable
$report->reportType; // BulkReportType
$report->reportData; // list<string>
```

`date` is the requested registry day normalized to midnight in the
`Europe/Warsaw` time zone. `reportData` is a list of REGON strings; unlike a full
report, a bulk report does not expose arbitrary row keys.

## `ServiceStatusData`

```php
$status->status; // int
$status->message; // string
$status->isAvailable(); // bool
```

`isAvailable()` returns `true` when GUS reports status code `1`.
The `message` string remains untrusted GUS content.

## `DiagnosticsData`

```php
$diagnostics->messageCode; // int
$diagnostics->message; // string
$diagnostics->sessionStatus; // int
```

The diagnostic `message` remains untrusted GUS content and requires the same
output- and log-context handling described above.

## Other return values

Singular and batch search builders return an
`Illuminate\Support\Collection<int, CompanyData>`. Plural full reports return
an `Illuminate\Support\Collection<int, FullCompanyReportData>`, the
authenticated data-status operation returns a `DateTimeImmutable`, and
`logout()` returns a boolean. These values are not `spatie/laravel-data`
objects.

See [Service status and diagnostics](service-status-and-diagnostics.md) for
usage examples.
