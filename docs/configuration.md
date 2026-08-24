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
BIR_IDENTIFIER_VALIDATION=format
BIR_PROXY_URL=https://proxy.example.com:8443
BIR_PROXY_USERNAME=proxy-user
BIR_PROXY_PASSWORD=proxy-password
BIR_RATE_LIMIT_ENABLED=true
BIR_RATE_LIMIT_STORE=redis
BIR_RATE_LIMIT_PREFIX=bir-regon:rate-limit
```

| Variable | Accepted values | Default | Purpose |
| --- | --- | --- | --- |
| `BIR_API_KEY` | Exactly 20 ASCII letters or digits | Empty | Authenticates searches and reports |
| `BIR_SANDBOX_API_KEY` | Exactly 20 ASCII letters or digits | Public GUS sandbox key | Authenticates sandbox requests |
| `BIR_CONNECTION_TIMEOUT` | Integer `1..60`, in seconds | `10` | Limits connection establishment time |
| `BIR_REQUEST_TIMEOUT` | Integer `1..300`, in seconds | `30` | Limits a complete SOAP request |
| `BIR_MAX_RESPONSE_BYTES` | Integer `1..50000000`, in bytes | `10000000` | Bounds streamed HTTP bodies, SOAP/MIME payloads, and nested report XML |
| `BIR_USER_AGENT` | 1-200 printable ASCII characters | `laravel-bir-regon/2` | Identifies the package transport to GUS |
| `BIR_IDENTIFIER_VALIDATION` | `format` or `checksum` | `format` | Selects local NIP and REGON validation before gateway access |
| `BIR_PROXY_URL` | `http://` or `https://` URL containing only a host and optional port | Empty | Routes production and sandbox HTTPS traffic through an explicit proxy |
| `BIR_PROXY_USERNAME` | String without surrounding whitespace or control characters; requires an `https://` proxy | Empty | Authenticates to the explicit proxy |
| `BIR_PROXY_PASSWORD` | String without surrounding whitespace or control characters; requires an `https://` proxy | Empty | Authenticates to the explicit proxy |
| `BIR_RATE_LIMIT_ENABLED` | Exact boolean `true` or `false` | `true` | Enables shared cache-backed request limiting |
| `BIR_RATE_LIMIT_STORE` | A configured Laravel cache store name | Default cache store | Selects the limiter state and lock backend |
| `BIR_RATE_LIMIT_PREFIX` | 1-100 ASCII letters, digits, `:`, `_`, or `-` | `bir-regon:rate-limit` | Namespaces limiter cache entries |

Keep production credentials outside source control. If credentials are stored
in a database or another persistent store, encrypt them at rest.

An empty production key still allows the unauthenticated public service-status
operation. Other authenticated operations reject an empty or malformed key
before opening a network connection and identify `BIR_API_KEY` or
`BIR_SANDBOX_API_KEY` according to the selected native environment. An invalid
`BIR_USER_AGENT` value is replaced with the package default.

## Published configuration

The complete configuration file contains two isolated credentials:

```php
<?php

return [
    'api_key' => env('BIR_API_KEY', ''),
    'sandbox_api_key' => env('BIR_SANDBOX_API_KEY', 'abcde12345abcde12345'),
    'connection_timeout' => env('BIR_CONNECTION_TIMEOUT', 10),
    'request_timeout' => env('BIR_REQUEST_TIMEOUT', 30),
    'max_response_bytes' => env('BIR_MAX_RESPONSE_BYTES', 10_000_000),
    'user_agent' => env('BIR_USER_AGENT', 'laravel-bir-regon/2'),
    'identifier_validation' => env('BIR_IDENTIFIER_VALIDATION', 'format'),
    'proxy' => [
        'url' => env('BIR_PROXY_URL'),
        'username' => env('BIR_PROXY_USERNAME'),
        'password' => env('BIR_PROXY_PASSWORD'),
    ],
    'rate_limit' => [
        'enabled' => env('BIR_RATE_LIMIT_ENABLED', true),
        'store' => env('BIR_RATE_LIMIT_STORE'),
        'prefix' => env('BIR_RATE_LIMIT_PREFIX', 'bir-regon:rate-limit'),
    ],
];
```

The package merges these defaults with the application's configuration, so a
published file only needs to override values that differ from the defaults.
Numeric transport settings accept only an integer or a canonical decimal
integer string. Floats, booleans, arrays, leading or trailing whitespace, and
values outside `1..60` connection seconds, `1..300` request seconds, or
`1..50000000` response bytes fail closed when the client is resolved. The
request timeout is a deadline for the complete cURL exchange, not only socket
inactivity. The same byte limit applies while streaming the outer HTTP body and
again when decoding the nested report XML. Direct `NativeSoapTransport`
construction enforces the same ranges and rejects invalid values with
`InvalidArgumentException`.

Multipart SOAP responses are additionally limited to 32 MIME parts. Each part
may contain at most 8,192 header bytes and 32 headers, and a parsed
`Content-Type` may contain at most 16 parameters. Excessive or control-bearing
MIME metadata is rejected as a protocol failure before unbounded header or part
collections are materialized.

## Identifier validation

`format` is the backward-compatible default. It requires exactly 10 digits for
NIP and KRS, and exactly 9 or 14 digits for REGON. `checksum` keeps those shape
checks and additionally validates the Polish NIP and REGON checksums before
login, rate-limit reservation, or any network request. The selected policy is
applied equally to production and sandbox clients, including single searches,
batches, and the search phase of full reports.

KRS has no checksum algorithm, so it remains a 10-digit format check in both
modes. Checksum validity is not evidence that a number exists, was assigned, or
belongs to an active entity; even an all-zero value can satisfy the checksum
mathematically. GUS or the relevant registry remains the source of those
facts.

The client accepts identifiers as undecorated digit strings and preserves
leading zeroes. It does not strip a `PL` prefix, whitespace, or dashes. Perform
UI-specific normalization before calling the package. Any configuration value
other than the exact strings `format` or `checksum`, including a non-string
value, fails closed with `LogicException` when the client is resolved.

## HTTP proxy

Leave `BIR_PROXY_URL`, `BIR_PROXY_USERNAME`, and `BIR_PROXY_PASSWORD` empty to
disable the package's explicit proxy configuration. In that state the native
sender does not override libcurl's ambient proxy discovery, so process-level
variables such as `HTTPS_PROXY` and `NO_PROXY` retain their normal libcurl
meaning.

Set `BIR_PROXY_URL` to an `http` or `https` URL containing only the scheme,
host, and optional port, for example `http://proxy.internal:8080` or
`https://proxy.example.com:8443`. User information, a path other than `/`, a
query, a fragment, whitespace, and control characters are rejected. Do not put
credentials in URL userinfo. Configure them separately:

```dotenv
BIR_PROXY_URL=https://proxy.example.com:8443
BIR_PROXY_USERNAME=proxy-user
BIR_PROXY_PASSWORD=proxy-password
```

The username and password must be configured together and require an
`https://` proxy URL. An authenticated `http://` proxy is rejected before any
network access so credentials cannot be negotiated over an unencrypted
client-to-proxy connection. Anonymous `http://` proxies remain supported.
Proxy configuration applies equally to the isolated production and sandbox
native transports. The sender forces an HTTP CONNECT tunnel and explicit
routing, so an ambient `NO_PROXY` value does not bypass an explicit
`BIR_PROXY_URL`.

TLS peer and hostname verification remains enabled for the GUS target, with
TLS 1.2 as the minimum protocol version. When the proxy URL uses `https`, its
TLS connection is also verified and requires TLS 1.2 or newer. The package does
not provide a switch to disable either check. Proxy configuration and
credentials are treated as sensitive state and are omitted from debug,
serialized, and translated exception data. Keep all three variables outside
source control and rebuild Laravel's configuration cache after changing them.

An explicit proxy cannot be combined with a custom HTTP sender passed to
`NativeSoapTransport`; that combination fails closed instead of silently
choosing one route. A replacement `BirSoapTransportInterface` owns all of its
network and proxy behavior. See [Extending the package](extending.md#replacing-only-the-soap-transport).

## Request limiting

The Laravel integration enables `CacheBirRequestLimiter` by default. It
requires the exact base `Illuminate\Cache\Repository` and one of the supported
Laravel stores: `ArrayStore`, `DatabaseStore`, `FileStore`, `MemcachedStore`, or
`RedisStore`. A tagged cache, any repository decorator or subclass, DynamoDB,
`FailoverStore`, `MemoizedStore`, `NullStore`, and custom stores fail closed with
`BirRateLimitException`; implementing `LockProvider` alone is not enough. An
empty `BIR_RATE_LIMIT_STORE` uses the application's default cache store.

`rate_limit.enabled` must resolve to an actual PHP boolean. The standard
unquoted dotenv values `true` and `false` are parsed by Laravel as booleans.
Empty, numeric, quoted, or otherwise malformed values fail closed with
`LogicException`; they never silently disable the limiter.

Use one shared Redis store for workers or application instances on multiple
hosts. Every host must use the same backend and database plus the same effective
namespace for state and lock connections, including the Redis client
(`REDIS_PREFIX`), Laravel cache (`CACHE_PREFIX`), and BIR prefixes. `ArrayStore`
coordinates only one PHP process; `FileStore` is local and does not coordinate
separate hosts. Limiter state is isolated by production or sandbox environment
and by a SHA-256 fingerprint of the API key. The raw key is not written into
cache keys. Set `BIR_RATE_LIMIT_ENABLED=false` only when another layer
coordinates every process using that credential.

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

The sandbox dataset is separate from production and changes independently. It
may be outdated, incomplete, artificial, or anonymized. Use it to verify the
integration contract and failure handling, not to decide whether an entity
currently exists or what its production data contains.

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
