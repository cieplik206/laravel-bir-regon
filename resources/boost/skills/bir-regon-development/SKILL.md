---
name: bir-regon-development
description: Integrate Laravel applications with cieplik206/laravel-bir-regon and the Polish GUS BIR/REGON API. Use for searches by NIP, REGON, or KRS, batch lookups, full or bulk reports, service diagnostics, sandbox testing, or replacing the package client.
---

# Laravel BIR REGON

Use the package's fluent Laravel API. Keep SOAP transport, authentication, and
session handling encapsulated by the package; do not instantiate `GusApi`
directly in application code.

For exhaustive details, read only the relevant page under
`vendor/cieplik206/laravel-bir-regon/docs/`.

## Installation and configuration

Install the package and configure credentials through the environment:

```bash
composer require cieplik206/laravel-bir-regon
```

```dotenv
BIR_API_KEY=your-api-key
BIR_SANDBOX_API_KEY=your-test-key
```

Laravel discovers the service provider automatically. Publishing
`config/bir-regon.php` is optional:

```bash
php artisan vendor:publish --tag=bir-regon-config
```

Never log or commit a production BIR API key. The public service-status call is
unauthenticated; searches, reports, data status, and diagnostics require a key.

## Accessing the API

Use the facade for concise Laravel code:

```php
use cieplik206\BirRegon\Facades\BirRegon;

$company = BirRegon::forNip('1234567890')->get();
```

Use `BirRegonService` through dependency injection when the integration is an
explicit domain dependency:

```php
use cieplik206\BirRegon\BirRegonService;

class FindCompany
{
    public function __construct(
        private BirRegonService $birRegon,
    ) {}
}
```

Preserve NIP, REGON, and KRS values as strings. Numeric conversion can remove
leading zeroes.

## Searches

Choose the entry point that matches the identifier:

```php
$byNip = BirRegon::forNip($nip)->get();
$byRegon = BirRegon::forRegon($regon)->get();
$byKrs = BirRegon::forKrs($krs)->get();
```

`get()` and `search()` are aliases. A single search returns `CompanyData`.

Use dedicated batch methods:

```php
$byNips = BirRegon::forNips($nips)->get();
$byKrs = BirRegon::forKrsNumbers($krsNumbers)->get();
$byRegon9 = BirRegon::forRegons9($regons)->get();
$byRegon14 = BirRegon::forRegons14($regons)->get();
```

Batch calls return `Illuminate\Support\Collection<int, CompanyData>`. GUS
accepts at most 20 identifiers per request; chunk larger inputs into groups of
20. An empty input returns an empty collection without a request.

## Full and bulk reports

Full reports require a `ReportType` before `getFullReport()`:

```php
use cieplik206\BirRegon\Enums\ReportType;

$report = BirRegon::forRegon($regon)
    ->reportType(ReportType::Organization)
    ->getFullReport();
```

The call may start from NIP or KRS; the package resolves the REGON first. The
selected report type must be applicable to the entity. Read
`vendor/cieplik206/laravel-bir-regon/docs/reports.md` or inspect `ReportType`
instead of inventing GUS report-name strings.

Bulk reports require `DateTimeImmutable` and `BulkReportType`:

```php
use cieplik206\BirRegon\Enums\BulkReportType;
use DateTimeImmutable;

$report = BirRegon::forDate(new DateTimeImmutable('2026-08-22'))
    ->reportType(BulkReportType::NewLegalEntitiesAndNaturalPersons)
    ->get();
```

## Production, sandbox, and diagnostics

Production is the default. Select the dedicated sandbox service before creating
request builders:

```php
$sandbox = BirRegon::sandbox();

$company = $sandbox
    ->forNip($nip)
    ->get();
```

Production and sandbox clients have separate API keys and authenticated
sessions. Reuse the scoped `$sandbox` service for related calls. Do not add
environment selection to request builders or instantiate one-off clients.

Service operations are fluent:

```php
$status = BirRegon::service()->get();
$dataStatus = BirRegon::service()->dataStatus();
$diagnostics = BirRegon::diagnostics()->get();
```

Diagnostics describe the active GUS session. When diagnosing a failed sandbox
call, use the same scoped service for the request and diagnostics:

```php
$sandbox = BirRegon::sandbox();

try {
    $sandbox->forNip($nip)->get();
} catch (BirNotFoundException) {
    $diagnostics = $sandbox->diagnostics()->get();
}
```

## Results and exceptions

Response objects extend `Spatie\LaravelData\Data`. Access their typed public
properties or call `toArray()` / `toJson()`. Full and bulk report row keys are
defined by GUS and vary by report type.

Handle the package exception hierarchy from most specific to least specific:

```php
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;

try {
    $company = BirRegon::forNip($nip)->get();
} catch (BirNotFoundException $exception) {
    // No matching entity.
} catch (BirAuthenticationException $exception) {
    // Missing or rejected API key.
} catch (BirException $exception) {
    // Other GUS, transport, or package error.
}
```

Both specialized exceptions extend `BirException`. Wrapped upstream errors
remain available through `getPrevious()`.

## Testing

The package does not require a fresh Laravel application, PMS, or a database.
Its own test suite uses Pest and Orchestra Testbench:

```bash
composer test
composer analyse
```

The default suite never performs network requests. Run live GUS test-service
checks explicitly with `composer test:sandbox`; never use a production key for
them.

In a consuming application, replace `BirClientInterface` with a fake before
resolving `BirRegonService` to keep tests isolated from GUS.
