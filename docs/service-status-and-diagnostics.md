[← Documentation](README.md)

# Service status, diagnostics, and logout

The GUS BIR service exposes operational information independently from company
searches and reports.

## Service availability

The public service status does not require an API key:

```php
use cieplik206\BirRegon\Facades\BirRegon;

$status = BirRegon::service()->get();

$status->status;
$status->message;
$status->isAvailable();
```

`status()` is an alias for `get()`. `isAvailable()` returns `true` for GUS
status code `1`.

You can inspect the test environment in the same way:

```php
$status = BirRegon::sandbox()
    ->service()
    ->status();
```

## Data status

The data-status endpoint returns the date of the dataset currently exposed by
GUS:

```php
$dataStatus = BirRegon::service()->dataStatus();

$dataStatus->format('Y-m-d');
```

The result is a `DateTimeImmutable`. This operation requires an API key.

## Session diagnostics

Diagnostics expose the message left by the most recent operation in the active
GUS session:

```php
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use Illuminate\Support\Facades\Log;

try {
    BirRegon::forNip('0123456700')->get();
} catch (BirNotFoundException) {
    $diagnostics = BirRegon::diagnostics()->get();
    $safeMessage = preg_replace(
        '/[\p{Cc}\p{Zl}\p{Zp}]/u',
        ' ',
        $diagnostics->message,
    ) ?? '[invalid GUS diagnostic message]';

    Log::warning('GUS BIR diagnostic', [
        'message_code' => $diagnostics->messageCode,
        'message' => $safeMessage,
    ]);
}
```

The response contains:

- `messageCode` — numeric GUS result code
- `message` — human-readable GUS message
- `sessionStatus` — current authenticated-session status

Both the diagnostic `message` and the public service-status `message` are
untrusted strings returned by GUS. Do not log either value directly: normalize
CR, LF, and other control characters and prefer structured context, as above,
to prevent log forging. They also require normal HTML escaping, parameterized
SQL, validated URL/email handling, and spreadsheet formula protection when used
in those contexts.

The native gateway reads these three values into one protocol
`DiagnosticsSnapshot` using one captured SID. If that session expires while the
snapshot is being collected, the gateway renews the session once and repeats
the complete snapshot. The public `DiagnosticsData` therefore never combines
diagnostic values from two sessions.

The operation performs three authenticated `GetValue` calls: message code,
message, and session status. A cold client also logs in first. If local request
limiting interrupts any step, `BirRateLimitException` is propagated and no
partial snapshot is returned.

When the completed status and message-code pair authoritatively identifies an
expired session, the gateway discards all three first-attempt responses and
repeats the complete public snapshot once. Otherwise, a failed diagnostic call
is propagated as its actual `BirTransportException`,
`BirSoapFaultException`, or `BirProtocolException` instead of being replaced
with a generic incomplete-diagnostics error. No partial snapshot is returned.

The internal status-and-message-code diagnostics used after an ambiguously
empty search, report, or scalar response are stricter: either typed failure is
propagated, and a partial pair never triggers session renewal.

Diagnostics require an API key and are tied to the current client session. Keep
the sandbox service when a failed request and its diagnostics belong together:

```php
$sandbox = BirRegon::sandbox();

try {
    $sandbox->forNip('0123456700')->get();
} catch (BirNotFoundException) {
    $diagnostics = $sandbox->diagnostics()->get();
}
```

## Ending a session explicitly

Authenticated sessions are scoped and may be reused by related operations.
Explicit logout is optional, but is available when a workflow should end its
current session immediately:

```php
$productionLoggedOut = BirRegon::logout();

$sandbox = BirRegon::sandbox();
$sandboxLoggedOut = $sandbox->logout();
```

Production and sandbox sessions are independent. If the selected client has no
local SID, `logout()` returns `true` without sending a request. Otherwise the
native gateway sends exactly one GUS logout request and returns its boolean
result. The local SID is always cleared, including when GUS returns `false` or a
typed transport or protocol exception is raised. The next authenticated
operation therefore starts a new session.

Read diagnostics before logout when they belong to the operation that just
failed. A later diagnostics call will authenticate a new session.

## Request accounting

Authentication and diagnostic operations count against the same GUS request
budget as searches and reports. For a normally successful call:

| Public operation | Active session | No active session |
| --- | ---: | ---: |
| `service()->get()` or `status()` | 2 `GetValue` calls | 2 `GetValue` calls |
| `service()->dataStatus()` | 1 `GetValue` call | 1 login + 1 `GetValue` call |
| `diagnostics()->get()` | 3 `GetValue` calls | 1 login + 3 `GetValue` calls |
| `logout()` | 1 logout call | No call |

The public status and message calls are unauthenticated, but still pass through
the local limiter. Session expiry can add status/message diagnostics, a new
login, and a retry. Every real SOAP call is reserved separately; the package
does not treat a multi-call public method as one request. See
[Request limits](rate-limits.md) when planning polling or health checks.

Continue with [Error handling](error-handling.md).
