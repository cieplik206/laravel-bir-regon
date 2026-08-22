[← Documentation](README.md)

# Service status and diagnostics

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
$status = BirRegon::service()
    ->inDev()
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

    Log::warning($diagnostics->message);
}
```

The response contains:

- `messageCode` — numeric GUS result code
- `message` — human-readable GUS message
- `sessionStatus` — current authenticated-session status

Diagnostics require an API key and are tied to the current client session. If
you need to diagnose calls against the test environment, set
`BIR_ENVIRONMENT=dev` for the whole process instead of switching only one
builder with `inDev()`.

Continue with [Error handling](error-handling.md).
