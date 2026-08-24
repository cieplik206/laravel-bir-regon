# Upgrade guide for 2.0

Version 2 replaces the runtime `gusapi/gusapi` integration with a native GUS
BIR 1.2 gateway, a bounded cURL transport for SOAP 1.2 and WS-Addressing, and
defensive XML decoders. The fluent facade, builders, `BirClientInterface`, and
package data objects remain available, but their result types now model the
actual plural and typed GUS contract. Both the consumer-facing and low-level
APIs therefore contain intentional breaking changes.

## 1. Update platform requirements

Version 2 requires PHP 8.4 or newer, Laravel 13, and these PHP extensions:

- `ext-dom`
- `ext-curl`
- `ext-libxml`

PHP 8.3 and Laravel 12 remain available on the 1.x package line but are not
supported by version 2. Security-fix eligibility for the latest 1.1.x release
ends on 2026-11-24; see [SECURITY.md](SECURITY.md). Upgrade the application
platform before changing the package constraint to `^2.0`.

SimpleXML and PHP's SOAP extension are no longer used by the package. The native
transport uses libcurl with verified HTTPS, a total request deadline, and a
streaming response limit. Update the dependency and its lock file with:

```bash
composer require cieplik206/laravel-bir-regon:^2.0 --with-all-dependencies
```

`gusapi/gusapi` is no longer installed by Laravel BIR REGON. If the consuming
application declared it directly and does not use it elsewhere, remove that
requirement separately.

## 2. Update published configuration

Existing `BIR_API_KEY` and `BIR_SANDBOX_API_KEY` values continue to work. Add
the native transport settings to a published `config/bir-regon.php` file:

```php
'connection_timeout' => env('BIR_CONNECTION_TIMEOUT', 10),
'request_timeout' => env('BIR_REQUEST_TIMEOUT', 30),
'max_response_bytes' => env('BIR_MAX_RESPONSE_BYTES', 10_000_000),
'user_agent' => env('BIR_USER_AGENT', 'laravel-bir-regon/2'),
'rate_limit' => [
    'enabled' => env('BIR_RATE_LIMIT_ENABLED', true),
    'store' => env('BIR_RATE_LIMIT_STORE'),
    'prefix' => env('BIR_RATE_LIMIT_PREFIX', 'bir-regon:rate-limit'),
],
```

The package defaults are merged when these keys are absent, but adding them
makes deployment behavior explicit. Rebuild the Laravel configuration cache
after the change.

The three numeric transport settings are now validated without permissive PHP
casts. They accept an integer or a canonical decimal integer string within
these inclusive ranges: connection timeout `1..60` seconds, request timeout
`1..300` seconds, and response size `1..50000000` bytes. Floats, booleans,
arrays, surrounding whitespace, and out-of-range values fail closed while the
Laravel client is resolved.

The native sender also requires TLS 1.2 or newer for the GUS endpoint and for
an HTTPS proxy, whether that proxy is configured explicitly or discovered by
libcurl. Explicit proxy credentials now require an `https://` proxy URL;
authenticated `http://` proxy configuration fails before network access,
while anonymous HTTP proxy routing remains available. A custom sender owns and
must enforce its own TLS and proxy-credential policy.

Laravel-resolved clients now enable a distributed, cache-backed GUS request
limiter by default. `CacheBirRequestLimiter` accepts only the exact base
`Illuminate\Cache\Repository` with `ArrayStore`, `DatabaseStore`, `FileStore`,
`MemcachedStore`, or `RedisStore`. Tagged caches, repository decorators and
subclasses, DynamoDB, `FailoverStore`, `MemoizedStore`, `NullStore`, and custom
stores fail closed even when they expose locks. Use one shared Redis store
through `BIR_RATE_LIMIT_STORE` for every application host; local Array/File
stores do not coordinate hosts. A custom backend requires a custom limiter.

Production and sandbox counters are isolated and the API key is represented
only by a SHA-256 fingerprint in cache keys. Login, search, report, diagnostic,
and logout operations all consume the official budget; a batch search consumes
one unit per identifier.

The enforced `Europe/Warsaw` policy is 3/second, 120/minute, and 6,000/hour
from 08:00 through 16:59; 3/second, 150/minute, and 8,000/hour from 06:00
through 07:59 and 17:00 through 21:59; and 4/second, 200/minute, and
10,000/hour overnight.

The limiter applies a conservative weighted GCRA model per second. Separate
unit-cost operations do not get an initial burst. A batch above the active
per-second rate is an explicit compromise: when prior debt permits, the one
indivisible SOAP request is admitted and leaves its complete weighted debt.
Minute and hour budgets use local fixed `Europe/Warsaw` calendar windows. This
does not claim that GUS itself uses fixed rather than rolling windows.

A new acquisition, and the first acquisition in a logical search/report scope,
may pace second-level debt for at most one second outside the cache lock. After
the first successful reservation in that scope, each later internal acquisition
may pace up to seven seconds. Diagnostics and recovery contain several
acquisitions, so the whole sequence may sleep longer than seven seconds. Minute
and hour blockers always fail fast with `BirRateLimitException`. The atomic lock
has a 30-second lease and waits at most one second for contention.

For quota failures, `retryAfterSeconds()` is the longest active second, minute,
or hour blocker plus clock-rollback recovery, rounded up. Use
`quotaWasExceeded()` to distinguish quota exhaustion from coordination failure.
`BIR_RATE_LIMIT_ENABLED=false` makes the Laravel provider select
`UnlimitedBirRequestLimiter`. Direct `NativeSoapTransport` construction now
requires a `BirRequestLimiterInterface`; there is no implicit unlimited
fallback.

The enabled value must be an actual PHP boolean. Use Laravel's standard
unquoted dotenv values `true` or `false`; empty, numeric, quoted, or malformed
values now raise `LogicException` instead of being coerced into a silent
limiter opt-out.

## 3. Replace custom GUS factories

`GusApiFactory` and `GusApiFactoryInterface` have been removed. Choose the new
extension point that matches the customization:

- bind `BirGatewayInterface` to replace GUS operation semantics while retaining
  `BirClient` and its public data mapping;
- bind `BirSoapTransportInterface` to replace SOAP envelope construction, HTTP
  execution, and SOAP/MIME decoding while retaining the gateway's login flow,
  session recovery, and nested report-XML decoding;
- bind `BirClientInterface` to replace the complete package client.

Custom `BirGatewayInterface` implementations must also implement
`diagnostics(): DiagnosticsSnapshot` and `logout(): bool`. A diagnostics
snapshot contains the message code, message, and session status read against
one captured SID. If session recovery changes the SID, rebuild the complete
snapshot instead of combining values from two sessions. Custom
`BirClientInterface` implementations continue to expose the mapped
`DiagnosticsData` through `getDiagnostics()` and must implement
`logout(): bool`. Logout is idempotent when no local session exists; native
implementations clear their local SID even when the remote logout fails.

Custom limiters can implement `BirRateLimitScopeInterface` when they need the
native logical recovery boundary. Its contract is the explicit
`beginRateLimitScope(): void` / `endRateLimitScope(): void` pair, not a closure.
`NativeBirGateway` brackets `callForRecords()` with the pair and
`NativeSoapTransport` forwards it to a scoped limiter.

`TransportResponse` no longer exposes the decoded result as a public property.
Read it through `result()`. Debug output redacts the result, and serialization
retains only an inert, credential-free tombstone whose `result()` call throws a
`LogicException`. Inspecting a restored tombstone is safe, but reports its
discarded fields as unavailable. Keep transport responses in memory and do not
queue, cache, or otherwise serialize them for later use. Custom transports must
not mark `GetValue` or logout results as nil because the official WSDL declares
those result elements as non-nillable. A custom decoder that encounters this
violation should return
`TransportResponse::failure(TransportFailureType::Protocol, resultWasNil: true)`
to prevent session recovery from obscuring the protocol error.

Fluent search builders and low-level `SearchCriteria` objects now also omit
their identifier values from dumps, exports, and serialized state. A restored
object is an inert tombstone and cannot issue a request. Queue protected input
values separately and construct a fresh builder or criterion at execution time.

The default gateway, transport, and client bindings customize the production
graph. `BirRegon::sandbox()` deliberately uses a separate native graph so a
production extension cannot receive the sandbox credential or session. Replace
`BirRegonService` as a whole when an application also needs custom sandbox
behavior. Manual `BirRegonService` construction must use different client
instances for production and sandbox; passing the same object for both now
raises `InvalidArgumentException` instead of silently routing `sandbox()` to
production.

Bindings should use Laravel's scoped lifetime:

```php
use App\Bir\CustomBirGateway;
use cieplik206\BirRegon\Contracts\BirGatewayInterface;

$this->app->scoped(
    BirGatewayInterface::class,
    CustomBirGateway::class,
);
```

See [Extending the package](docs/extending.md) for all three contracts.

## 4. Update direct `BirClient` construction

The 1.x constructor accepted a GUS factory, optional key, and environment:

```php
$client = new BirClient(
    $gusApiFactory,
    $apiKey,
    Environment::Sandbox,
);
```

The 2.x constructor accepts one `BirGatewayInterface`:

```php
use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Transport\NativeSoapTransport;

$transport = new NativeSoapTransport(
    apiKey: $apiKey,
    // Deliberate only when another layer enforces the GUS quota.
    requestLimiter: new UnlimitedBirRequestLimiter(),
    environment: Environment::Sandbox,
);

$client = new BirClient(new NativeBirGateway($transport));
```

Laravel applications that resolve `BirClientInterface` from the container do
not need to construct this graph manually.

## 5. Update vendor-specific data mappers

Package data objects no longer accept `GusApi\SearchReport` instances. The
following public helpers were removed:

- `CompanyData::fromGusApiResult()`
- `FullCompanyReportData::fromGusApiReport()`

Gateway implementations return `cieplik206\BirRegon\Protocol\SearchResult`.
Use the replacement mappers when adapting such a result:

```php
use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\FullCompanyReportData;

$company = CompanyData::fromSearchResult($searchResult);
$report = FullCompanyReportData::fromSearchResult(
    $searchResult,
    $reportType,
    $rows,
);
```

`FullCompanyReportData` now exposes the selected `reportType`, the original
GUS rows in `reportData`, and a typed `normalized` projection. The normalized
projection groups common entity, address, contact, registry, legal-form,
activity lifecycle, local-unit, PKD, partner, and unit-type data. Keep using
`reportData` for fields that GUS adds before the package gains a typed mapping.
Normalized lifecycle dates are nullable `DateTimeImmutable` values, activity
state uses `ActivityStatus`, and PKD classification is a nullable string of at
most eight characters. It remains open rather than becoming an enum so future
GUS classification versions do not require a package release.

Activity-state inference now fails closed for partial reports. `Active`
requires a start or resumption date; if a suspension date is present, the
resumption must be strictly later. Identity fields, historical
creation/registration/change dates, or merely missing termination fields
produce `Unknown`.

`NaturalPersonActivityKindsData` now exposes nullable integer counts:
`ceidgCount`, `agricultureCount`, `otherCount`, and
`deletedBefore20141108Count`. Do not migrate them as booleans. Empty and
unknown-only rows remain in the raw `list<array<string, string>>` but no longer
create phantom normalized objects. Single-row report cardinality is still
checked against the original raw rows before normalization omits them. Bulk
`reportData` is a `list<string>`.

Every public string returned by GUS is untrusted, including `CompanyData`, raw
and normalized reports, `DiagnosticsData::$message`, and
`ServiceStatusData::$message`. Escape HTML output (Laravel Blade `{{ }}` does
this), use bound SQL parameters, allowlist only `http`/`https` links, validate
addresses before creating `mailto:` links, and neutralize formula prefixes such
as `=`, `+`, `-`, `@`, tab, and carriage return for CSV/XLSX. Normalize CR, LF,
Unicode format/bidirectional controls, and other control characters, and bound
message length or use normalized structured context before logging to prevent
log forging. Mapping preserves source values; it is not a sanitizer.

## 6. Review report enums

`ReportType` now contains the official GUS report strings directly rather than
constants from another library. Two values require attention:

- `NaturalPersonDeletedBefore20141108` now uses
  `BIR12OsFizycznaDzialalnoscSkreslonaDo20141108` instead of the former BIR11
  value;
- `OrganizationLocalWithNip` is new and uses
  `BIR121JednLokalnaOsPrawnej`.

There are 17 full report cases in total. Version 2 also validates that the
selected report matches the entity type, silo, and REGON length returned by the
search. An incompatible report now throws `BirValidationException` before the
full-report request is sent.

Search response fields now follow the published XSD rather than arbitrary
strings. `CompanyData::$type` is `EntityType`, `CompanyData::$silo` is `Silo`,
and `CompanyData::$nipStatus` is nullable `NipStatus`. An empty NIP status is
`null`; the supported non-empty values are `Uchylony` and `Unieważniony`.
`regon14` is nullable and is populated only when GUS actually returned a
14-digit REGON. `activityEndDate` remains a nullable string for serialization
compatibility, but it now contains the normalized XML Schema `xs:date` lexical
value: `YYYY-MM-DD`, optionally followed by `Z` or a legal `+/-hh:mm` offset.
The timezone suffix is preserved; malformed or impossible dates make the whole
protocol response invalid instead of leaking through as data.

The exact enum cases are `EntityType::LegalUnit`,
`EntityType::NaturalPerson`, `EntityType::LegalUnitLocalUnit`, and
`EntityType::NaturalPersonLocalUnit`, plus `Silo::Ceidg`,
`Silo::Agriculture`, `Silo::Other`, `Silo::DeletedBefore20141108`, and
`Silo::LegalUnits`. Replace old string comparisons and any earlier provisional
case names accordingly.

## 7. Migrate searches and full reports to plural results

One NIP, REGON, or KRS can legitimately match more than one GUS row, including
records from different silos. The fluent `search()` and `get()` methods for a
single identifier therefore return `Collection<int, CompanyData>` and no
longer discard every row after the first:

```php
$companies = BirRegon::forNip($nip)->get();

foreach ($companies as $company) {
    // Inspect $company->type and $company->silo explicitly.
}
```

Direct `BirClientInterface::searchByNip()`, `searchByRegon()`, and
`searchByKrs()` calls similarly return lists. Update code that previously
treated their return value as one `CompanyData` instance. A lookup with no
matching rows still throws `BirNotFoundException`; it does not return an empty
collection.

Full-report lookup has the same ambiguity. Prefer the plural fluent method when
an identifier may resolve to several compatible records:

```php
$reports = BirRegon::forNip($nip)
    ->reportType(ReportType::NaturalPerson)
    ->getFullReports();
```

The plural client methods are `getFullReportsByNip()`,
`getFullReportsByKrs()`, and `getFullReports()`. The legacy singular
`getFullReport()` path remains available only for a unique compatible report;
it throws `BirAmbiguousResultException` instead of silently choosing one when
several distinct report REGONs match.

## 8. Account for scoped bindings

The package's service, client, gateway, and production transport changed from
singleton to scoped bindings. Within an HTTP request or worker scope, calls
still reuse an authenticated GUS session. The session is discarded at the next
scope boundary, preventing credentials and session state from living for the
entire lifetime of Octane or another long-running process.

Custom bindings should also use `scoped()`. Ensure custom queue runners and
long-running runtimes flush Laravel scoped instances between jobs or requests.
Production and sandbox clients remain isolated.

## 9. Review validation and exceptions

Identifiers and batch sizes are validated before authentication or network
access. NIP and KRS require exactly 10 digits, REGON requires 9 or 14 digits,
and a batch contains at most 20 string identifiers. The default search path
checks the official wire format only. Applications that require checksum
policy can set `BIR_IDENTIFIER_VALIDATION=checksum` for the production and
sandbox fluent clients without rejecting synthetic sandbox and fixture
identifiers by default. The stateless validator and low-level criteria factory
remain available for narrower policies:

```dotenv
BIR_IDENTIFIER_VALIDATION=checksum
```

```php
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Validation\PolishIdentifierChecksum;

PolishIdentifierChecksum::assertValidNip($nip);
$criteria = SearchCriteria::regon($regon, validateChecksum: true);
```

For REGON-14, checksum validation covers both the embedded REGON-9 part and the
14-digit checksum. KRS has no corresponding checksum rule. Bulk report dates
must be between yesterday and seven days ago in the `Europe/Warsaw` time zone.
The date sent to GUS and returned in `BulkReportData` is normalized to midnight
in that time zone.

Version 2 adds focused subclasses of `BirException`:

- `BirValidationException`
- `BirAmbiguousResultException`
- `BirRateLimitException`
- `BirTransportException`
- `BirProtocolException`
- `BirReportException`

Existing code that catches `BirException` remains compatible. Code that
asserted messages or concrete exceptions originating from `gusapi/gusapi` must
be updated to the package hierarchy.

The not-found and ambiguity exception constructors no longer accept or retain
the raw identifier:

```php
new BirNotFoundException(identifierType: 'NIP');
new BirAmbiguousResultException(
    identifierType: 'REGON',
    compatibleTargetCount: 2,
);
```

`BirAmbiguousResultException::$identifier` has been removed. Its safe
`$identifierType` and `$compatibleTargetCount` properties remain available.
Package-generated messages identify only the identifier type, and the native
call path marks NIP, REGON, KRS, search criteria, and operation parameter arrays
with `#[\SensitiveParameter]` so PHP redacts their stack-trace arguments.

PHP does not inherit parameter attributes from an implemented interface or an
overridden method. Custom clients, gateways, transports, limiters, validators,
and test fakes must therefore add `#[\SensitiveParameter]` to every
implementation parameter that can carry an identifier or an operation
parameter array.

## 10. Update tests and fakes

Tests that extended `GusApi`, returned `GusApi\SearchReport`, or replaced
`GusApiFactoryInterface` must move to package contracts. For application tests,
continue faking `BirClientInterface` when ready data objects are sufficient.
Fake `BirGatewayInterface` when the real `BirClient` validation and mapping
should be exercised without a network request. Gateway fakes must now implement
`diagnostics(): DiagnosticsSnapshot` with one coherent set of values and
`logout(): bool`. Client fakes must implement the complete public client
contract, including `getDiagnostics()` and `logout()`.

Register fakes before resolving the client, service, or facade in the current
scope:

```php
use cieplik206\BirRegon\Contracts\BirGatewayInterface;

$this->app->instance(BirGatewayInterface::class, $fakeGateway);
```

Transport fakes are intended for tests of login, session renewal, SOAP failure
classification, and decoding. The default test suite remains network-isolated;
run `composer test:sandbox` separately for the official GUS test service.
When a test inspects a successful `TransportResponse`, read its decoded value
through `result()` rather than a property. Do not use serialized transport
responses as fixtures because their serialized form is intentionally an inert
tombstone.

The development suite now uses Pest 5 together with
`pest-plugin-phpstan`; the complete development graph requires PHP 8.4.1 or
newer, while the runtime floor remains PHP 8.4.0. Update custom tests for the
new list-valued data contracts and explicit rate-limit scope methods.

When a workflow explicitly ends a session, call `$service->logout()` (or
`BirRegon::logout()`). The call returns `true` without I/O when there is no local
session. With an active session, the native gateway sends one logout request,
returns the GUS boolean result, and clears the local SID even when GUS returns
`false` or a typed transport/protocol exception is raised. Sandbox sessions are
ended separately through `BirRegon::sandbox()->logout()`.

The cURL sender now resets and reuses one handle within its scoped transport.
This preserves libcurl connection, DNS, and TLS session caches while replacing
request headers, body, callbacks, and SID after every call, and caps reusable
connection age at five minutes where libcurl supports it. Cloning
`NativeSoapTransport` also isolates its mutable SOAP envelope builder so SID
changes cannot split header and body session state. Custom transports should
offer equivalent isolation if they introduce their own connection pooling or
clone behavior.

## 11. Verify the upgrade

Run the local quality checks after updating custom integrations:

```bash
vendor/bin/pint --test
composer analyse -- --no-progress
composer test
composer validate --strict --no-check-publish
composer audit
```

If the application uses searches, reports, diagnostics, or explicit logout
against GUS, also run its own staging checks with a sandbox key. Never use or
log a production key in tests.
