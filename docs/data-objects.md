[← Documentation](README.md)

# Data objects

All package responses are typed objects based on `spatie/laravel-data`. They
can be read through public properties and converted to arrays or JSON.

```php
$array = $company->toArray();
$json = $company->toJson();
```

## `CompanyData`

Single and batch searches return `CompanyData`.

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
| `type` | `?string` | GUS entity type |
| `regon14` | `?string` | 14-digit REGON, when present |
| `nipStatus` | `?string` | NIP status returned by GUS |
| `silo` | `int` | GUS data-silo identifier |
| `activityEndDate` | `?string` | Activity end date returned by GUS |
| `postCity` | `?string` | Postal city |

Empty optional values returned by GUS are normalized to `null`.

## `FullCompanyReportData`

Full reports contain both the basic search result and the raw, typed report
rows:

```php
$report->basicData; // CompanyData
$report->reportData; // array<int, array<string, string>>
```

GUS defines the keys in `reportData`; they differ between report types.

## `BulkReportData`

```php
$report->date; // DateTimeImmutable
$report->reportType; // BulkReportType
$report->reportData; // array<int, string>
```

## `ServiceStatusData`

```php
$status->status; // int
$status->message; // string
$status->isAvailable(); // bool
```

`isAvailable()` returns `true` when GUS reports status code `1`.

## `DiagnosticsData`

```php
$diagnostics->messageCode; // int
$diagnostics->message; // string
$diagnostics->sessionStatus; // int
```

See [Service status and diagnostics](service-status-and-diagnostics.md) for
usage examples.
