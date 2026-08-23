---
name: bir-regon-development
description: Integrate Laravel applications with cieplik206/laravel-bir-regon and the Polish GUS BIR/REGON API. Use for searches by NIP, REGON, or KRS, batch lookups, full or bulk reports, service diagnostics, sandbox testing, or replacing the package client.
---

# Laravel BIR REGON

Use the package's fluent Laravel API. Keep SOAP transport, authentication, and
session handling encapsulated by the package. Version 2 implements GUS BIR 1.2
natively, requires PHP 8.4 or newer with Laravel 13, and does not install
`gusapi/gusapi`. Applications on PHP 8.3 or Laravel 12 must use version 1.x.

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
BIR_CONNECTION_TIMEOUT=10
BIR_REQUEST_TIMEOUT=30
BIR_MAX_RESPONSE_BYTES=10000000
BIR_RATE_LIMIT_ENABLED=true
BIR_RATE_LIMIT_STORE=redis
BIR_RATE_LIMIT_PREFIX=bir-regon:rate-limit
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

$companies = BirRegon::forNip('1234567890')->get();
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

For queued jobs, store only operation inputs and resolve `BirRegonService` in
`handle()`. Never store `BirClient`, `BirRegonService`, or a request builder on
a job or in a cache entry. Their credential-free serialized form intentionally
restores only as an inert tombstone that throws `LogicException` when used.
Treat `TransportResponse` the same way: its decoded result is available only
through `result()`, debug output is redacted for a live response and reports
unavailable state for a restored tombstone, and serialization never creates a
reusable response.

For a custom SOAP transport, `GetValue` and logout results are non-nillable. If
the decoder encounters `xsi:nil` there, return
`TransportResponse::failure(TransportFailureType::Protocol, resultWasNil: true)`
so the gateway does not retry a malformed response as an expired session.

## Searches

Choose the entry point that matches the identifier:

```php
$byNip = BirRegon::forNip($nip)->get();       // Collection<CompanyData>
$byRegon = BirRegon::forRegon($regon)->get();
$byKrs = BirRegon::forKrs($krs)->get();
```

`get()` and `search()` are aliases. They always return
`Illuminate\Support\Collection<int, CompanyData>`, including for one input
identifier. GUS can return multiple valid rows and silos for one NIP, REGON, or
KRS. Preserve all of them. Use `sole()` only when the consuming domain requires
exactly one row; do not silently replace the collection with `first()`. A
lookup with no rows throws `BirNotFoundException` rather than returning an
empty collection.

`CompanyData::$type`, `$silo`, and `$nipStatus` use `EntityType`, `Silo`, and
nullable `NipStatus` enums. `regon14` is nullable and must remain `null` unless
GUS returned a 14-digit REGON. Never synthesize or pad it.

Use the exact closed enum cases. `EntityType` contains `LegalUnit` (`P`),
`NaturalPerson` (`F`), `LegalUnitLocalUnit` (`LP`), and
`NaturalPersonLocalUnit` (`LF`). `Silo` contains `Ceidg` (`1`), `Agriculture`
(`2`), `Other` (`3`), `DeletedBefore20141108` (`4`), and `LegalUnits` (`6`).
`activityEndDate` is a nullable normalized `xs:date` lexical string, optionally
retaining `Z` or a legal `+/-hh:mm` suffix; do not assume it is always exactly
ten characters.

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

The fluent API validates identifier shape, not Polish checksums. When the
application requires a checksum check, call the stateless
`cieplik206\BirRegon\Validation\PolishIdentifierChecksum` predicates or
`assertValid*()` methods before searching. Do not add implicit checksum
rejection to package calls; it is stricter than the GUS request contract.

## Full and bulk reports

Full reports require a `ReportType`. Prefer `getFullReports()` so multiple
compatible GUS records are retained:

```php
use cieplik206\BirRegon\Enums\ReportType;

$reports = BirRegon::forRegon($regon)
    ->reportType(ReportType::Organization)
    ->getFullReports();
```

The call may start from NIP or KRS; the package resolves the REGON first. The
selected report type must match the returned entity type, silo, and REGON
length or `BirValidationException` is thrown before the report request.
`getFullReports()` returns a collection for every distinct compatible report
target. The singular `getFullReport()` is only valid when exactly one target
remains and throws `BirAmbiguousResultException` before report I/O otherwise.
The 17 cases include `ReportType::OrganizationLocalWithNip` for
`BIR121JednLokalnaOsPrawnej`. Read
`vendor/cieplik206/laravel-bir-regon/docs/reports.md` or inspect `ReportType`
instead of inventing GUS report-name strings.

`FullCompanyReportData` exposes:

```php
$report->basicData; // CompanyData
$report->reportType; // ReportType
$report->reportData; // raw list<array<string, string>>
$report->normalized; // NormalizedFullReportData
```

Use `$report->normalized->entity`, `localUnits`, `pkdActivities`, `partners`,
or `unitType` according to the selected report. These projections contain typed
identity, address, contact, legal-form, registry, lifecycle, PKD, partner, and
enum values. Keep `reportData` available for unknown future GUS fields; do not
reimplement report-key mapping in consuming applications.

`PkdActivityData::$classification` is a nullable string with a maximum length
of eight characters, not an enum. Treat it as a forward-compatible GUS
classification version identifier.

`NaturalPersonActivityKindsData` contains nullable integer counts named
`ceidgCount`, `agricultureCount`, `otherCount`, and
`deletedBefore20141108Count`; never map them to booleans. Keep empty and
unknown-only rows in raw `reportData`, but do not create normalized phantom
entities or list items from them. Enforce singleton-report cardinality against
the original raw row list before omitting empty projections. Bulk
`reportData` is `list<string>`.

Bulk reports require `DateTimeImmutable` and `BulkReportType`:

```php
use cieplik206\BirRegon\Enums\BulkReportType;
use DateTimeImmutable;
use DateTimeZone;

$date = new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Warsaw'));

$report = BirRegon::forDate($date)
    ->reportType(BulkReportType::NewLegalEntitiesAndNaturalPersons)
    ->get();
```

## Production, sandbox, and diagnostics

Production is the default. Select the dedicated sandbox service before creating
request builders:

```php
$sandbox = BirRegon::sandbox();

$companies = $sandbox
    ->forNip($nip)
    ->get();
```

Production and sandbox clients have separate API keys and authenticated
sessions and separate reusable cURL handles. Reuse the scoped `$sandbox`
service for related calls. The transport resets bodies and SID headers between
calls while retaining connection and TLS caches. Do not add environment
selection to request builders or instantiate one-off clients.

Laravel enables the cache-backed request limiter by default. On
`BirRateLimitException`, back off for `retryAfterSeconds()`; do not immediately
retry or bypass the limiter. Read
`vendor/cieplik206/laravel-bir-regon/docs/rate-limits.md` for configuration and
request weights.

The limiter uses conservative weighted GCRA spacing per second. Separate SOAP
operations receive no initial burst. A batch larger than the active per-second
rate is an explicit indivisible-request compromise: it may be accepted when
prior debt permits and then leaves its full weighted debt. Minute/hour budgets
are local fixed `Europe/Warsaw` calendar windows, not a claim about GUS's
undisclosed fixed-versus-rolling algorithm.

A new acquisition and the first acquisition in a logical search/report scope
may pace for at most one second. After the first successful reservation in that
scope, **each** subsequent internal acquisition may pace up to seven seconds;
multi-step recovery can therefore sleep longer than seven seconds in total.
Minute/hour blockers always fail fast. `retryAfterSeconds()` uses the longest
active second/minute/hour blocker plus clock-rollback recovery. Pacing happens
outside the lock. The lock lease is 30 seconds and contention waits at most one
second.

Use the exact base `Illuminate\Cache\Repository` with only `ArrayStore`,
`DatabaseStore`, `FileStore`, `MemcachedStore`, or `RedisStore`. Tagged caches,
repository decorators/subclasses, DynamoDB, `FailoverStore`, `MemoizedStore`,
`NullStore`, and custom stores must fail closed. `ArrayStore` and `FileStore` do
not coordinate hosts; use one shared Redis store and prefix for a multi-host
deployment. A custom backend requires a custom limiter.

Scoped custom limiters implement `BirRateLimitScopeInterface` with explicit
`beginRateLimitScope(): void` and `endRateLimitScope(): void` methods. Do not
invent a closure-based scope API.

Service operations are fluent:

```php
$status = BirRegon::service()->get();
$dataStatus = BirRegon::service()->dataStatus();
$diagnostics = BirRegon::diagnostics()->get();
```

Manual logout is optional. Use `BirRegon::logout()` for production or
`$sandbox->logout()` for the isolated sandbox session when a workflow must end
its current session immediately. The local SID is cleared even when remote
logout fails.

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

Application code receives `DiagnosticsData`. A custom `BirGatewayInterface`
must implement `diagnostics(): DiagnosticsSnapshot` and return the message code,
message, and session status from one captured SID. If session renewal changes
the SID, repeat the complete snapshot rather than mixing values from two
sessions. Custom gateways must also implement `logout(): bool`.

## Results and untrusted registry values

Response objects extend `Spatie\LaravelData\Data`. Access their typed public
properties or call `toArray()` / `toJson()`. A bulk report contains a list of
REGON strings and its normalized report date.

Treat every public GUS string as untrusted input. This includes `CompanyData`,
raw `reportData`, normalized DTOs, `DiagnosticsData::$message`, and
`ServiceStatusData::$message`. Values may contain HTML, SQL fragments, control
characters, or spreadsheet formulas. Mappers validate structure and known
scalar types; they **do not sanitize** text and are not an XSS, SQL-injection,
URL, email, spreadsheet-injection, or log-forging boundary. When generating
application code:

- render with escaped Blade output (`{{ $value }}`), never raw `{!! $value !!}`;
- use Eloquent or parameterized queries for values; never concatenate GUS text
  into SQL, identifiers, raw ordering, or clauses;
- parse outbound website URLs and allowlist only `http` and `https` schemes;
- validate an email address before creating a `mailto:` link;
- before CSV/XLSX export, neutralize values beginning with `=`, `+`, `-`, `@`,
  TAB, or CR; CSV quoting alone does not prevent formula execution.
- before logging, normalize CR, LF, and other control characters and prefer
  structured context; never concatenate an unchanged GUS string into a log
  line.

Keep raw registry strings for fidelity, then apply output-context protection at
the final HTML, database, link, mail, or spreadsheet boundary.

## Exceptions

Handle the package exception hierarchy from most specific to least specific:

```php
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirAmbiguousResultException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Exceptions\BirReportException;
use cieplik206\BirRegon\Exceptions\BirSoapFaultException;
use cieplik206\BirRegon\Exceptions\BirTransportException;
use cieplik206\BirRegon\Exceptions\BirValidationException;

try {
    $companies = BirRegon::forNip($nip)->get();
} catch (BirNotFoundException $exception) {
    // No matching entity.
} catch (BirAuthenticationException $exception) {
    // Missing or rejected API key.
} catch (BirAmbiguousResultException $exception) {
    // A singular full-report call had several targets; use getFullReports().
} catch (BirValidationException $exception) {
    // Invalid local input or an incompatible full-report type.
} catch (BirRateLimitException $exception) {
    // Back off for $exception->retryAfterSeconds().
} catch (BirReportException $exception) {
    // GUS rejected a report; inspect $exception->gusCode.
} catch (BirTransportException $exception) {
    // Network or SOAP transport failed safely.
} catch (BirSoapFaultException $exception) {
    // GUS returned a typed SOAP Fault; inspect $exception->faultCode.
} catch (BirProtocolException $exception) {
    // GUS returned an unexpected or malformed response.
} catch (BirException $exception) {
    // Another package-level error.
}
```

All specialized exceptions extend `BirException`. The package omits the active
API key, session ID, request XML, response body, and raw upstream exception from
the returned exception graph. Read `BirReportException::$gusCode` for a
rejected report. Use `BirValidationException`, `BirTransportException`, and
`BirProtocolException` when retry policy needs to distinguish local input,
connectivity, and malformed responses.
`BirSoapFaultException` extends `BirProtocolException` and retains only a safe
`SoapFaultCode` enum; the remote Reason, Detail, and raw response body are not
kept.

Never catch every `BirException` only to release a queued job for one fixed
delay. Release using `BirRateLimitException::retryAfterSeconds()` only for the
rate-limit case; validation, not-found, authentication, protocol, report, and
transport failures require their own policies.

## Testing

The package does not require a fresh Laravel application, PMS, or a database.
Its own test suite uses Pest 5, `pest-plugin-phpstan`, and Orchestra Testbench
11 on Laravel 13; the runtime floor is PHP 8.4:

```bash
composer test
composer analyse
```

The default suite never performs network requests. Run live GUS test-service
checks explicitly with `composer test:sandbox`; never use a production key for
them.

In a consuming application, replace `BirClientInterface` with a fake before
resolving `BirRegonService` to return ready data objects. Bind a fake
`BirGatewayInterface` when tests should exercise the real `BirClient`
validation and mapping without GUS. Keep replacements scoped and register them
before resolving the service or facade. A gateway fake must implement
`diagnostics(): DiagnosticsSnapshot` with one coherent set of values and
`logout(): bool`; a client fake must implement `getDiagnostics()` and
`logout()` as part of the complete public contract.
