[← Documentation](README.md)

# Error handling

Laravel BIR REGON translates errors from the SOAP client into a small package
exception hierarchy.

## Exception types

| Exception | When it is thrown |
| --- | --- |
| `BirNotFoundException` | GUS returns no entity for a search identifier |
| `BirAuthenticationException` | The API key is missing or rejected |
| `BirException` | A report type is missing, the service fails, or another GUS client error occurs |

Both specialized exceptions extend `BirException`, so applications may either
handle individual cases or catch the package's base exception.

## Handling individual failures

```php
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Facades\BirRegon;

try {
    $company = BirRegon::forNip($nip)->get();
} catch (BirNotFoundException $exception) {
    return null;
} catch (BirAuthenticationException $exception) {
    report($exception);

    throw $exception;
} catch (BirException $exception) {
    report($exception);

    throw $exception;
}
```

Catch the specialized exceptions before `BirException` because they inherit
from it.

## Catching every package error

For jobs or boundary services that should retry every GUS failure uniformly,
catch the base exception:

```php
use cieplik206\BirRegon\Exceptions\BirException;

try {
    $company = BirRegon::forRegon($regon)->get();
} catch (BirException $exception) {
    report($exception);

    $this->release(60);
}
```

The original exception is retained as `getPrevious()` when an error is wrapped
by the package.

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

Continue with [Testing](testing.md).
