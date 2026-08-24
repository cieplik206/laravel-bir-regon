[← Documentation](README.md)

# Testing

The package is tested independently with Pest 5, `pest-plugin-phpstan`, and
Orchestra Testbench 11 on Laravel 13. The suite does not require a fresh Laravel
application, a database, or any application-specific dependency.

## Install development dependencies

From the package repository:

```bash
composer install
```

The runtime package supports PHP 8.4.0. Installing the complete Pest 5
development toolchain requires PHP 8.4.1 or newer because its Symfony Process
dependency uses that patch-level floor.

## Isolated test suite

Run the default test suite with:

```bash
composer test
```

These tests use local gateway and transport fakes plus fixture SOAP, multipart,
and nested report responses. They never perform network requests. The live
`sandbox` test group is explicitly excluded from the default PHPUnit
configuration.

The isolated transport tests also verify that one sender reuses its cURL handle
without retaining the previous body or SID header, and that production and
sandbox sender instances own different handles. Connection reuse therefore does
not require a live GUS request in the default suite.

## Code quality

Run static analysis and formatting with:

```bash
composer analyse
composer format
```

PHPStan loads `pest-plugin-phpstan` through `phpstan.neon.dist`, so Pest test
expectations and datasets participate in the static-analysis run rather than
being ignored as framework magic.

Validate the Composer package metadata with:

```bash
composer validate --strict --no-check-publish
composer audit
```

## Compatibility matrix

The isolated suite is designed to cover:

- Laravel 13 on PHP 8.4 with the lowest resolvable Pest 5 test toolchain
- Laravel 13 on PHP 8.4 with the highest supported dependencies
- Laravel 13 on PHP 8.5 with the highest supported dependencies

The Pest development plugins raise the minimum versions in the complete
development graph. A separate CI job therefore removes development-only
constraints and resolves the runtime package exactly against PHP 8.4.0,
`illuminate/cache` 13.0.0, `illuminate/contracts` 13.0.0, and
`illuminate/support` 13.0.0. This keeps the declared Laravel 13 runtime floor
independently verifiable.

Before a release, also validate Composer metadata, code style, static analysis,
and dependency security advisories. Keep the live GUS sandbox suite separate
from isolated checks because it depends on an external service.

## Verified release workflow

Create releases only from a commit already merged into `main` with a successful
`CI Passed` check. Create and push a signed annotated SemVer tag such as
`v2.0.0`. A tag push alone does not publish anything. After the protected tag
exists remotely, the repository owner deliberately requests publication:

```bash
gh api --method POST \
  repos/cieplik206/laravel-bir-regon/dispatches \
  -f event_type=publish-release \
  -F 'client_payload[tag]=v2.0.0'
```

The `repository_dispatch` event loads the privileged `Release` workflow from
the trusted default branch instead of from the tagged commit. The workflow
binds the requested ref to the signed annotated tag's internal name and target,
confirms that the commit is reachable from `main`, and requires a successful
push run of the exact `.github/workflows/ci.yml` at that SHA, including its
unique `CI Passed` job. It rechecks the remote tag object and ancestry
immediately before publishing.

The workflow attaches source archives and `SHA256SUMS` to the GitHub Release.
Repository release immutability freezes the published tag and assets and gives
the release a GitHub provenance attestation. Tag creation and release
publication remain deliberate maintainer operations.

The owner-only dispatch is the current manual gate. If the repository gains a
second trusted maintainer, add a protected `release` environment to the job,
require that independent reviewer, and prevent self-approval. An unprotected
environment or an approval performed by the same compromised owner would not
add meaningful protection.

## GUS sandbox tests

The opt-in integration suite sends real requests to the official GUS test
environment:

```bash
composer test:sandbox
```

It verifies:

- public service and authenticated data status
- NIP, REGON, and KRS searches
- batch searches by NIP, KRS, and REGON-9
- a full company report
- the general natural-person report for a historical silo-4 record
- session diagnostics after an unsuccessful search

The suite defaults to the public key published for the official GUS sandbox.
You may override it for the current command:

```bash
BIR_SANDBOX_API_KEY=your-test-key composer test:sandbox
```

Use a test key only. Never expose a production BIR key in source control, test
output, or a public CI configuration.

The integration suite calls `BirRegon::sandbox()`. It never changes the
production client configuration or sends `BIR_API_KEY` to the test endpoint.

Sandbox tests depend on an external service and a dataset that changes
independently from production. Records may be stale, incomplete, artificial,
or anonymized. Assert stable protocol shape and the small set of published test
identifiers needed by the scenario; do not infer production existence or exact
business data from a sandbox result. Live checks are useful as an integration
signal but should not replace the isolated suite.

## Testing request limiting

Laravel-created native transports receive `CacheBirRequestLimiter` by default.
Its tests must use the exact `Illuminate\Cache\Repository` class with one of the
five supported stores: `ArrayStore`, `DatabaseStore`, `FileStore`,
`MemcachedStore`, or `RedisStore`. Laravel's `ArrayStore` is suitable only for
deterministic single-process unit tests; it does not demonstrate multi-host
coordination. Tagged caches, repository decorators/subclasses, and unsupported
stores should be tested as fail-closed paths.

A `NativeSoapTransport` test fixture must pass a limiter explicitly. Use
`UnlimitedBirRequestLimiter` only for an isolated fixture that cannot open a
network connection; it does not exercise quota behavior. Inject a recording or
rejecting `BirRequestLimiterInterface` fake to assert operation parameters,
weighted batch cost, bounded short-delay pacing, fail-fast behavior, and
`BirRateLimitException` propagation. Add `#[\SensitiveParameter]` to any fake
parameter that can carry an identifier or operation parameter array because
interface attributes are not inherited. Tests should advance an injected
clock, inject a sleeper callback, or assert `retryAfterSeconds()`; they should
not use real wall-clock sleeps.

When a fake models the native logical recovery boundary, use the explicit
`BirRateLimitScopeInterface::beginRateLimitScope()` and
`endRateLimitScope()` pair. Test the one-second limit before the first
reservation, the per-acquisition seven-second limit afterward, cumulative
multi-step recovery sleep, minute/hour fail-fast behavior, longest-blocker
retry calculation, and backwards-clock delay. Do not replace the scope pair
with a closure helper; that is not the package contract.

## Testing consuming applications

Application domain code can continue to depend on `BirClientInterface`. Replace
that binding with a scoped fake before resolving `BirRegonService`:

```php
use cieplik206\BirRegon\BirClientInterface;

$this->app->scoped(
    BirClientInterface::class,
    FakeBirClient::class,
);
```

The fake must implement the complete public client contract, including
`logout(): bool`. This is the simplest way to return ready package data objects
from application tests. Its `searchByNip()`, `searchByRegon()`, and
`searchByKrs()` methods must return `list<CompanyData>`, not a single object.
Likewise, the plural `getFullReportsByNip()`, `getFullReportsByKrs()`, and
`getFullReports()` methods return `list<FullCompanyReportData>`. Model more than
one item when application behavior depends on an identifier spanning several
GUS silos.

Use a `BirGatewayInterface` fake when a test should exercise the real
`BirClient` validation and response mapping without opening a network
connection. A minimal not-found fake looks like this:

```php
<?php

use cieplik206\BirRegon\Contracts\BirGatewayInterface;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Protocol\DiagnosticsSnapshot;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use DateTimeImmutable;

final class FakeBirGateway implements BirGatewayInterface
{
    /** @return list<SearchResult> */
    public function search(#[\SensitiveParameter] SearchCriteria $criteria): array
    {
        return [];
    }

    /** @return list<array<string, string>> */
    public function fullReport(
        #[\SensitiveParameter] string $regon,
        ReportType $reportType,
    ): array {
        return [];
    }

    /** @return list<string> */
    public function bulkReport(
        DateTimeImmutable $date,
        BulkReportType $reportType,
    ): array {
        return [];
    }

    public function getValue(GetValueParameter $parameter): string
    {
        return '';
    }

    public function diagnostics(): DiagnosticsSnapshot
    {
        return new DiagnosticsSnapshot(
            messageCode: 4,
            message: 'No matching test entity.',
            sessionStatus: 1,
        );
    }

    public function logout(): bool
    {
        return true;
    }
}

$this->app->instance(BirGatewayInterface::class, new FakeBirGateway());
```

Bind or register the fake before resolving the facade, service, or client in
the current test. A transport fake is appropriate only when testing the native
gateway's authentication, retry, or XML-decoding behavior.

A gateway fake should build `SearchResult` with the real `EntityType`, `Silo`,
and nullable `NipStatus` enums. Its `fullReport()` result is always a list of
raw string-keyed rows, including when a fixture contains only one row;
`BirClient` maps that list to the normalized full-report DTO. Use
`PolishIdentifierChecksum` directly in validation tests when checksum policy is
part of the consuming application, or set
`bir-regon.identifier_validation` to `checksum` before resolving the scoped
client to exercise the fluent path. The default `format` mode intentionally
performs only the GUS-required length and digit checks. Tests using synthetic
identifiers should normally keep that default.

Normalization fixtures should model `NaturalPersonActivityKindsData` counts as
nullable integers, including zero. Preserve empty and unknown-only rows in the
raw `list<array<string, string>>`, assert that they do not create normalized
phantom objects, and still enforce singleton cardinality against the original
raw row count.

Protocol-focused fixtures should use the documented enum values and valid
`xs:date` activity end values: `YYYY-MM-DD`, optionally followed by `Z` or a
legal `±hh:mm` offset no greater than `±14:00`. Add separate malformed fixtures
when asserting that an unknown entity type, NIP status, silo, or impossible date
becomes `BirProtocolException`; do not make permissive fake values part of a
successful path.

The `DiagnosticsSnapshot` returned by a gateway fake should model one coherent
session. When a transport-focused test inspects a successful
`TransportResponse`, read the decoded value through `result()`. Debug output is
redacted for live responses and reports unavailable state for restored
tombstones. Serialized transport responses are intentionally inert rather than
reusable fixtures.

These client and gateway bindings affect the production graph. The sandbox
client is deliberately constructed as a separate native graph; replace
`BirRegonService` as a whole when a test also needs custom sandbox behavior.

Continue with [Extending the package](extending.md).
