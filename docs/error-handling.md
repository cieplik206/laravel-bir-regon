[← Documentation](README.md)

# Error handling

Laravel BIR REGON translates native transport, protocol, validation, and GUS
report failures into a package exception hierarchy.

## Exception types

| Exception | When it is thrown |
| --- | --- |
| `BirNotFoundException` | GUS returns no entity for a search identifier |
| `BirAmbiguousSearchResultException` | `BirSearchBuilder::sole()` receives more than one search row; use `get()` or `search()` to preserve them |
| `BirAmbiguousResultException` | A singular full-report call matches more than one distinct compatible report REGON; use `getFullReports()` |
| `BirAuthenticationException` | The API key is missing or rejected, or an expired session cannot be renewed |
| `BirRateLimitException` | The local request quota is exhausted or its cache-backed coordination is unavailable |
| `BirValidationException` | An identifier, batch, date, or report/entity combination is invalid before network access |
| `BirTransportException` | The package cannot safely complete the HTTPS/SOAP exchange, including when an HTTP 200 response contains a non-SOAP maintenance page |
| `BirSoapFaultException` | GUS returns a valid SOAP 1.2 Fault; `faultCode` contains a safe `SoapFaultCode` enum |
| `BirProtocolException` | GUS returns malformed, ambiguous, or unexpected SOAP/XML data |
| `BirReportException` | GUS rejects a full or bulk report; `gusCode` contains the numeric result code |
| `BirException` | A builder is incomplete or another package-level failure occurs |

Every specialized exception extends `BirException`, so applications may either
handle individual cases or catch the package's base exception.

For a missing native credential, the exception identifies the correct setting:
`BIR_API_KEY` for production or `BIR_SANDBOX_API_KEY` for the sandbox. A custom
transport that does not expose environment metadata receives a neutral message
that names both possibilities.

## Handling individual failures

```php
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirAmbiguousSearchResultException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Facades\BirRegon;

try {
    $company = BirRegon::forNip($nip)->sole();
} catch (BirNotFoundException $exception) {
    return null;
} catch (BirAmbiguousSearchResultException $exception) {
    report($exception);

    throw $exception;
} catch (BirRateLimitException $exception) {
    report($exception);

    throw $exception;
} catch (BirAuthenticationException $exception) {
    report($exception);

    throw $exception;
} catch (BirException $exception) {
    report($exception);

    throw $exception;
}
```

Catch the specialized exceptions before `BirException` because they inherit
from it. `BirAmbiguousSearchResultException` is specific to exact-one search
selection. It does not replace `BirAmbiguousResultException`, which continues
to report multiple compatible targets for singular `getFullReport()` calls.
The former exposes the complete search-row count as `resultCount`; the latter
exposes the count of distinct compatible report targets as
`compatibleTargetCount`.

## Catching every package error

For boundary services that report every package failure uniformly, catch the
base exception:

```php
use cieplik206\BirRegon\Exceptions\BirException;

try {
    $companies = BirRegon::forRegon($regon)->get();
} catch (BirException $exception) {
    report($exception);

    throw $exception;
}
```

Do not apply one fixed retry delay to every `BirException`. Validation,
not-found, authentication, protocol, and report failures have different
recovery rules. Use the calculated delay only for `BirRateLimitException` and
apply the application's retry policy deliberately to transport failures.

Transport and protocol messages are deliberately stable and do not include raw
SOAP requests, response bodies, the API key, or the session ID. Low-level cURL,
parser, and protocol failures are not retained as `getPrevious()` causes because
their messages or traces may contain those values. For a rejected report,
inspect `BirReportException::$gusCode` instead of parsing its message.
For a valid SOAP 1.2 Fault, inspect `BirSoapFaultException::$faultCode`; only the
standard `Sender`, `Receiver`, `MustUnderstand`, `VersionMismatch`, or
`DataEncodingUnknown` enum is retained. The upstream `Reason` and `Detail` are
discarded.

NIP, REGON, and KRS values are also omitted from package-generated not-found
and ambiguity messages and from their public properties. Native entry points
and downstream carriers use `#[\SensitiveParameter]`, so PHP replaces those
arguments with `SensitiveParameterValue` in stack traces. Do not parse an
identifier value from `BirNotFoundException` or either ambiguity exception.
Neither ambiguity exception has an `identifier` property; `identifierType`
contains only the sanitized identifier kind. Keep the operation input
separately when application policy requires a correlation value.

Fluent search builders and native `SearchCriteria` objects also redact their
identifier state from native dumps, `var_export`, Symfony VarDumper, and
serialized payloads. Deserializing one produces an inert tombstone rather than
a reusable request. Keep a separately protected identifier value when an
application needs to rebuild work in a queue; do not use a serialized builder
as a job payload.

A completed HTTP 200 exchange is successful only when its media type is one of
the supported SOAP representations. A `text/html` maintenance page or another
non-SOAP 200 response is treated as `BirTransportException`; its body is not
retained. The package does not retry the application operation automatically.
Choose any retry or queue-backoff policy at the application boundary.

An empty login result means the key was rejected and raises
`BirAuthenticationException`. A non-empty login result that is not a valid
20-character SID is malformed protocol data and raises
`BirProtocolException`, rather than blaming the configured key.

## Request-limit failures

`BirRateLimitException` is raised before opening the HTTP connection. The
limiter may first pace per-second debt outside its cache lock. A new acquisition
is limited to one second; after the first successful reservation inside one
search/report recovery scope, each subsequent internal acquisition may pace up
to seven seconds. Minute and hour blockers always fail fast, and the limiter
never retries the application operation automatically. In a queued job:

```php
use cieplik206\BirRegon\Exceptions\BirRateLimitException;

try {
    $companies = BirRegon::forRegon($regon)->get();
} catch (BirRateLimitException $exception) {
    $this->release($exception->retryAfterSeconds());

    return;
}
```

`quotaWasExceeded()` distinguishes an exhausted local quota (`true`) from a
cache, state, or atomic-lock failure (`false`). `retryAfterSeconds()` is always
at least one. For quota exhaustion it is the longest active second, minute, or
hour blocker plus clock-rollback recovery, rounded up. A contended cache lock
has a 30-second lease and can wait for up to one second before being reported as
unavailable. The exception is propagated directly and does not trigger a new
gateway diagnostic sequence.

This exception reports the package's local coordination decision. A remote GUS
rejection or an HTTP failure can still be reported as a typed report or
transport exception. See [Request limits](rate-limits.md) for cache-store and
multi-host configuration.

## Expired sessions

Long-lived workers do not need to renew GUS sessions manually. For an
ambiguously empty, successfully delivered response, the gateway checks both
the current session status and GUS message code using the same SID. A
successful but empty `KomunikatKod` also indicates an expired session. An
explicit decoded GUS error code `7` is treated as authoritative and triggers
renewal even if diagnostics momentarily report an active session.

An expired session is cleared, recreated, and the original operation is retried
at most once. A second expiry clears the replacement SID and raises
`BirAuthenticationException`. A transport or protocol failure is reported
immediately without issuing additional session-diagnostic requests.

When the original response was successfully delivered but ambiguously empty,
the gateway must read session status and message code before deciding whether
to renew. If either diagnostic request fails, its typed transport, SOAP-fault,
or protocol exception is propagated directly. A partial diagnostic pair never
triggers renewal and is not collapsed into a generic protocol error.

## Logout failures

`logout()` clears the local SID even when GUS returns `false`, the transport
fails, or the response is malformed. A `false` result is returned to the caller;
transport and malformed-response failures raise `BirTransportException` and
`BirProtocolException`, respectively. The next authenticated operation starts a
new session in every case.

## Diagnostics after a failed search

The GUS session may expose a more specific message after a failed request:

```php
try {
    BirRegon::forNip($nip)->get();
} catch (BirNotFoundException) {
    $diagnostics = BirRegon::diagnostics()->get();
}
```

See [Service status and diagnostics](service-status-and-diagnostics.md) for
session and environment considerations.

## Queued jobs and serialization

Store only the operation inputs on queued jobs. Resolve `BirRegonService` in
`handle()` and create the request builder there:

```php
use cieplik206\BirRegon\BirRegonService;
use Illuminate\Contracts\Queue\ShouldQueue;

final class FetchCompany implements ShouldQueue
{
    public function __construct(
        public readonly string $nip,
    ) {}

    public function handle(BirRegonService $birRegon): void
    {
        $companies = $birRegon->forNip($this->nip)->get();

        // Process every matching GUS record...
    }
}
```

Do not store `BirClient`, `BirRegonService`, or any package request builder on a
job, in a cache entry, or in another serialized payload. Their serialized form
intentionally contains no object state or credentials. Deserialization produces
an inert tombstone, and attempting to use it throws `LogicException`. Legacy
payloads created before this protection also discard their stored state without
throwing inside `unserialize()`, so credential-bearing input is not retained in
an exception trace. Debugging a restored tombstone is safe and reports its state
as unavailable; it never reconstructs or displays the discarded values. This
fail-closed behavior prevents a BIR API key from being persisted in queue
backends or caches.

Continue with [Testing](testing.md).
