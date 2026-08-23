[← Documentation](README.md)

# Configuration

Laravel BIR REGON reads its default connection settings from
`config/bir-regon.php`. Publishing the file is optional:

```bash
php artisan vendor:publish --tag=bir-regon-config
```

## Environment variables

```dotenv
BIR_API_KEY=your-api-key
BIR_SANDBOX_API_KEY=your-test-key
BIR_CONNECTION_TIMEOUT=10
BIR_REQUEST_TIMEOUT=30
BIR_MAX_RESPONSE_BYTES=10000000
BIR_USER_AGENT=laravel-bir-regon/2
BIR_RATE_LIMIT_ENABLED=true
BIR_RATE_LIMIT_STORE=redis
BIR_RATE_LIMIT_PREFIX=bir-regon:rate-limit
```

| Variable | Accepted values | Default | Purpose |
| --- | --- | --- | --- |
| `BIR_API_KEY` | Exactly 20 ASCII letters or digits | Empty | Authenticates searches and reports |
| `BIR_SANDBOX_API_KEY` | Exactly 20 ASCII letters or digits | Public GUS sandbox key | Authenticates sandbox requests |
| `BIR_CONNECTION_TIMEOUT` | Positive integer, in seconds | `10` | Limits connection establishment time |
| `BIR_REQUEST_TIMEOUT` | Positive integer, in seconds | `30` | Limits a complete SOAP request |
| `BIR_MAX_RESPONSE_BYTES` | Positive integer, in bytes | `10000000` | Bounds streamed HTTP bodies, SOAP/MIME payloads, and nested report XML |
| `BIR_USER_AGENT` | 1-200 printable ASCII characters | `laravel-bir-regon/2` | Identifies the package transport to GUS |
| `BIR_RATE_LIMIT_ENABLED` | Laravel boolean | `true` | Enables shared cache-backed request limiting |
| `BIR_RATE_LIMIT_STORE` | A configured Laravel cache store name | Default cache store | Selects the limiter state and lock backend |
| `BIR_RATE_LIMIT_PREFIX` | 1-100 ASCII letters, digits, `:`, `_`, or `-` | `bir-regon:rate-limit` | Namespaces limiter cache entries |

Keep production credentials outside source control. If credentials are stored
in a database or another persistent store, encrypt them at rest.

An empty production key still allows the unauthenticated public service-status
operation. Other authenticated operations reject an empty or malformed key
before opening a network connection. An invalid `BIR_USER_AGENT` value is
replaced with the package default.

## Published configuration

The complete configuration file contains two isolated credentials:

```php
<?php

return [
    'api_key' => env('BIR_API_KEY', ''),
    'sandbox_api_key' => env('BIR_SANDBOX_API_KEY', 'abcde12345abcde12345'),
    'connection_timeout' => (int) env('BIR_CONNECTION_TIMEOUT', 10),
    'request_timeout' => (int) env('BIR_REQUEST_TIMEOUT', 30),
    'max_response_bytes' => (int) env('BIR_MAX_RESPONSE_BYTES', 10_000_000),
    'user_agent' => env('BIR_USER_AGENT', 'laravel-bir-regon/2'),
    'rate_limit' => [
        'enabled' => (bool) env('BIR_RATE_LIMIT_ENABLED', true),
        'store' => env('BIR_RATE_LIMIT_STORE'),
        'prefix' => env('BIR_RATE_LIMIT_PREFIX', 'bir-regon:rate-limit'),
    ],
];
```

The package merges these defaults with the application's configuration, so a
published file only needs to override values that differ from the defaults.
Connection and request timeouts are clamped to at least one second by the
native transport. The request timeout is a deadline for the complete cURL
exchange, not only socket inactivity. Keep the response limit large enough for
the full or bulk reports used by the application, but avoid disabling it with
an unbounded value. The same byte limit applies while streaming the outer HTTP
body and again when decoding the nested report XML.

## Request limiting

The Laravel integration enables `CacheBirRequestLimiter` by default. It
requires the exact base `Illuminate\Cache\Repository` and one of the supported
Laravel stores: `ArrayStore`, `DatabaseStore`, `FileStore`, `MemcachedStore`, or
`RedisStore`. A tagged cache, any repository decorator or subclass, DynamoDB,
`FailoverStore`, `MemoizedStore`, `NullStore`, and custom stores fail closed with
`BirRateLimitException`; implementing `LockProvider` alone is not enough. An
empty `BIR_RATE_LIMIT_STORE` uses the application's default cache store.

Use one shared Redis store and the same prefix for workers or application
instances on multiple hosts. `ArrayStore` and `FileStore` are local and do not
coordinate separate hosts. Limiter state is isolated by production or sandbox
environment and by a SHA-256 fingerprint of the API key. The raw key is not
written into cache keys. Set `BIR_RATE_LIMIT_ENABLED=false` only when another
layer coordinates every process using that credential.

The limiter follows the three official GUS schedules in `Europe/Warsaw`, uses
a conservative weighted GCRA model for the per-second quota, and uses local
fixed calendar windows for minute and hour quotas. Those fixed windows do not
claim to reproduce GUS's undisclosed server-side accounting. A new acquisition
paces at most one second. During one search/report recovery scope, each internal
acquisition after the first successful reservation may pace up to seven
seconds; the entire recovery sequence may sleep longer. Minute/hour blockers
fail fast. The atomic lock has a 30-second lease and waits at most one second
for contention. See
[Request limits](rate-limits.md) for the complete policy and queue-backoff
example.

## Selecting the sandbox

Production is always the default service:

```php
use cieplik206\BirRegon\Facades\BirRegon;

$productionCompanies = BirRegon::forNip('1234567890')->get();

$sandboxCompanies = BirRegon::sandbox()
    ->forNip('7740001454')
    ->get();
```

`sandbox()` returns a service backed by a dedicated native GUS test transport.
The production and sandbox clients keep separate credentials and sessions for
the current Laravel container scope.

Keep the scoped service when several operations belong to one sandbox workflow:

```php
$sandbox = BirRegon::sandbox();

$companies = $sandbox->forNip('7740001454')->get();
$diagnostics = $sandbox->diagnostics()->get();
```

This ensures that searches, reports, and diagnostics use the same authenticated
sandbox session. Request builders do not switch environments and never create
one-off clients.

## Container scope

`BirRegonService`, `BirClientInterface`, `BirGatewayInterface`, and the
production `BirSoapTransportInterface` are scoped bindings. In a normal HTTP
application, their authenticated session is reused within one request and is
discarded at the next request boundary. Laravel queue workers, Octane, and
other long-running runtimes must flush scoped instances between jobs or
requests using their standard Laravel lifecycle.

The sandbox client remains a separate object inside the scoped service. Its API
key and session cannot be reused by the production transport.

Each native transport owns one reusable cURL handle for its scope. The sender
resets request headers, callbacks, body, and SID before and after every call,
while retaining only libcurl's connection, DNS, and TLS session caches. The
production and sandbox transports have different handles, so neither request
state nor a live connection is shared between environments.

## Configuration cache

After changing `.env` in a cached application, rebuild Laravel's configuration
cache during deployment:

```bash
php artisan config:cache
```

During local development, clear stale cached values with:

```bash
php artisan config:clear
```

Continue with [Basic usage](basic-usage.md).
