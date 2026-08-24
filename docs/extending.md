[← Documentation](README.md)

# Extending the package

Laravel BIR REGON separates its fluent API, GUS protocol gateway, and SOAP
transport behind public contracts. The default bindings are scoped, so a
session may be reused within one Laravel request or worker scope without
becoming global state in a long-running process.

## Replacing the client

Replace `BirClientInterface` when an application needs to change the complete
package-level behavior, including searches, reports, service information,
diagnostics, and logout:

```php
<?php

namespace App\Providers;

use App\Bir\CustomBirClient;
use cieplik206\BirRegon\BirClientInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(
            BirClientInterface::class,
            CustomBirClient::class,
        );
    }
}
```

Register the replacement before `BirRegonService` or the `BirRegon` facade is
first resolved in the current scope.

The singular-identifier client methods are list-valued despite their names:
`searchByNip()`, `searchByRegon()`, and `searchByKrs()` each return a
`list<CompanyData>`, because one identifier may represent several GUS silos.
Batch search methods use the same list contract. The plural full-report methods
`getFullReportsByNip()`, `getFullReportsByKrs()`, and `getFullReports()` return a
`list<FullCompanyReportData>`. The corresponding singular full-report methods
must throw `BirAmbiguousResultException` rather than silently choosing one of
several distinct compatible report REGON values.

## Replacing the GUS gateway

Implement `BirGatewayInterface` to keep the package's `BirClient`, fluent
builders, validation, and public data objects while replacing access to the GUS
operations:

```php
use App\Bir\CustomBirGateway;
use cieplik206\BirRegon\Contracts\BirGatewayInterface;

$this->app->scoped(
    BirGatewayInterface::class,
    CustomBirGateway::class,
);
```

The gateway contract works with package protocol values rather than vendor
objects:

- `search(SearchCriteria $criteria)` returns a list of `SearchResult` objects;
- `fullReport(string $regon, ReportType $reportType)` returns decoded report
  rows as a `list<array<string, string>>`, including an empty or multi-row
  list;
- `bulkReport(DateTimeImmutable $date, BulkReportType $reportType)` returns a
  list of REGON strings;
- `getValue(GetValueParameter $parameter)` returns the requested scalar value;
- `diagnostics()` returns a `DiagnosticsSnapshot` containing the message code,
  message, and session status;
- `logout()` ends the current session and returns the remote boolean result.

All three diagnostic values in `DiagnosticsSnapshot` must belong to one
captured session SID. If a custom gateway renews an expired session while
collecting diagnostics, it must repeat the complete snapshot instead of mixing
values from the previous and renewed sessions. `BirClient` validates and maps
this protocol snapshot to the public `DiagnosticsData` object.

This is the preferred boundary for deterministic integration fakes and for a
custom non-SOAP backend that can provide equivalent GUS BIR semantics.

Replacing the gateway bypasses the native transport graph, including its local
request limiter. A gateway that still calls GUS must provide equivalent quota
coordination; a deterministic fake that never performs network I/O does not
need it.

`SearchResult` uses `EntityType`, `Silo`, and nullable `NipStatus` enums for
closed protocol vocabularies. A custom gateway must construct those enums and
must not pass arbitrary response strings through as public types. Applications
that need stricter input policy may configure
`BIR_IDENTIFIER_VALIDATION=checksum`, call `PolishIdentifierChecksum`
directly, or use the optional checksum argument on the NIP and REGON
`SearchCriteria` factories. Direct `BirClient` construction accepts
`IdentifierValidationMode` as its second constructor argument. Format-only
validation remains the default because that is the GUS request contract. KRS
has no checksum rule.

## Replacing only the SOAP transport

Implement `BirSoapTransportInterface` when requests need a different HTTP or
SOAP execution layer but the native authentication, session recovery, report
decoding, and exception translation should remain in place:

```php
use App\Bir\CustomBirSoapTransport;
use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;

$this->app->scoped(
    BirSoapTransportInterface::class,
    CustomBirSoapTransport::class,
);
```

The transport receives a `BirOperation` and its typed package parameters and
must return a `TransportResponse`. It is also responsible for applying the
current session set through `useSession()`. Implementations must not expose the
API key, session identifier, request XML, or raw response content in exception
messages, stack traces, debug output, or serialized state.

Native public and downstream methods mark NIP, REGON, KRS, `SearchCriteria`,
and operation parameter arrays with `#[\SensitiveParameter]`. PHP does not
inherit parameter attributes from an interface declaration. Every custom
client, gateway, transport, limiter, validator, or fake must repeat the
attribute on its own implementation parameters that can carry these values;
annotating only the package interface does not redact the custom stack frame.

The native implementation reserves the operation's limiter cost after local
request construction and immediately before every HTTP exchange. Replacing
`BirSoapTransportInterface` bypasses that implementation, so the custom
transport must coordinate the GUS quota itself. It should propagate
`BirRateLimitException` without converting it to a generic transport failure.
See [Request limits](rate-limits.md).

The native transport classifies failures at the boundary that produced them.
An exception from a custom HTTP sender becomes a transport failure, while an
unexpected exception from a custom limiter becomes
`BirRateLimitException::limiterUnavailable()`. Envelope or response-decoding
failures remain protocol failures. A completed HTTP 200 response with an
unsupported media type, including a `text/html` maintenance page, is a
transport failure. Completed HTTP 400 and 500 exchanges are decoded only when
they carry a supported SOAP media type; a valid SOAP 1.2 Fault is preserved as
a safe typed fault code without retaining its raw `Reason`, `Detail`, or
response body.

A decoded transport result is private and is available only through
`TransportResponse::result()`. Its debug representation is redacted. A
serialized response restores only as an inert tombstone and `result()` then
throws `LogicException`; inspecting that tombstone reports unavailable fields
without accessing discarded state. Transport responses must not be queued or
cached for later processing. A successful custom transport response for
`GetValue` or logout must not set `resultWasNil`, because those result elements
are non-nillable in the official WSDL. If a custom decoder encounters such a
response, return
`TransportResponse::failure(TransportFailureType::Protocol, resultWasNil: true)`
so the gateway reports the protocol violation without attempting session
renewal.

Custom gateways must implement both `diagnostics(): DiagnosticsSnapshot` and
`logout(): bool`. Custom clients must expose public diagnostics through
`getDiagnostics()` and also implement `logout(): bool`. Logout implementations
should make the no-session case idempotent and clear local session state even
when the remote operation fails.

Custom gateway and transport bindings affect the production client resolved
from the container. `BirRegon::sandbox()` deliberately constructs an isolated
native sandbox graph so production extensions cannot accidentally receive the
sandbox credential or session. Replace `BirRegonService` as a whole if an
application also needs custom sandbox behavior.

`NativeSoapTransport` requires an explicit `BirRequestLimiterInterface` when it
is instantiated directly. Laravel's service provider passes
`CacheBirRequestLimiter` by default. Pass `UnlimitedBirRequestLimiter` only as
a deliberate opt-out when another layer coordinates every caller using the
credential, or in an isolated test that cannot perform network I/O.

The Laravel provider also passes one explicit proxy configuration to both
native environments when `BIR_PROXY_URL` is set. A direct
`NativeSoapTransport` construction can use its `proxyUrl`, `proxyUsername`, and
`proxyPassword` arguments. The URL accepts only an `http` or `https` scheme,
host, and optional port; credentials must use the separate arguments and the
username and password must be supplied together. The sender uses CONNECT,
keeps target TLS verification enabled with a TLS 1.2 minimum, and applies the
same minimum and verification to an HTTPS proxy.

Do not combine these explicit proxy arguments with a custom
`BirHttpSenderInterface`; construction fails closed because the package cannot
safely decide which component owns routing and credentials. When the explicit
proxy arguments are null, the native sender leaves libcurl's ambient proxy
behavior intact, including `HTTPS_PROXY` and `NO_PROXY`. A fully custom sender
owns its own proxy, TLS, credential-redaction, and routing policy, including
enforcement of the deployment's minimum TLS version.

The limiter contract is deliberately small:
`BirRequestLimiterInterface::acquire(BirOperation $operation, array $parameters = []): void`.
It returns normally when the caller may proceed and throws
`BirRateLimitException` otherwise. A search limiter can inspect the
`SearchCriteria` stored in `$parameters['criteria']` to charge its
`identifierCount()`; every other native operation costs one. The package
service provider constructs its configured limiter directly, so replacing the
interface in the container alone does not change the native production graph.
Pass a limiter to `NativeSoapTransport` or replace the transport when a custom
policy is required.

A limiter that needs the native search/report recovery boundary may also
implement `BirRateLimitScopeInterface`:

```php
interface BirRateLimitScopeInterface
{
    public function beginRateLimitScope(): void;

    public function endRateLimitScope(): void;
}
```

`NativeBirGateway` brackets each logical `callForRecords()` sequence with these
methods when the transport implements the interface, and `NativeSoapTransport`
forwards them when its limiter implements it. The protocol is an explicit,
nest-safe begin/end pair, not a closure. A custom transport or limiter that does
not need scoped pacing may implement only its primary interface.

The built-in cache limiter supports only the exact base Laravel
`Illuminate\Cache\Repository` with `ArrayStore`, `DatabaseStore`, `FileStore`,
`MemcachedStore`, or `RedisStore`. It deliberately rejects tagged/decorated or
subclassed repositories and every other store, even if it exposes locks. To use
DynamoDB, `FailoverStore`, `MemoizedStore`, `NullStore`, or a custom backend,
write a `BirRequestLimiterInterface` implementation with coordination semantics
appropriate to that backend and pass it to `NativeSoapTransport`.

Each native transport also owns one persistent cURL handle. `curl_reset()`
clears callbacks, request bodies, and SID headers between calls while keeping
that handle's connection, DNS, and TLS session caches reusable. Handles are not
global: separate transport instances, including the production and sandbox
graphs, never share them. A custom transport should preserve the same credential
and environment isolation even if it uses a different connection pool.

## Facade and dependency injection

Both access styles resolve `BirRegonService` from the same container scope:

```php
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Facades\BirRegon;

$service = app(BirRegonService::class);
$companies = $service->forNip($nip)->get();

$sameResults = BirRegon::forNip($nip)->get();
```

Choose dependency injection for explicit domain dependencies and the facade
for concise Laravel integration code. Applications upgrading a custom 1.x
factory or client should also read the
[Upgrade guide for 2.0](https://github.com/cieplik206/laravel-bir-regon/blob/main/UPGRADE-2.0.md).
