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
```

| Variable | Accepted values | Default | Purpose |
| --- | --- | --- | --- |
| `BIR_API_KEY` | A valid GUS BIR key | Empty | Authenticates searches and reports |
| `BIR_SANDBOX_API_KEY` | A valid GUS test key | Public GUS sandbox key | Authenticates sandbox requests |

Keep production credentials outside source control. If credentials are stored
in a database or another persistent store, encrypt them at rest.

## Published configuration

The complete configuration file contains two isolated credentials:

```php
<?php

return [
    'api_key' => env('BIR_API_KEY', ''),
    'sandbox_api_key' => env('BIR_SANDBOX_API_KEY', 'abcde12345abcde12345'),
];
```

The package merges these defaults with the application's configuration, so a
published file only needs to override values that differ from the defaults.

## Selecting the sandbox

Production is always the default service:

```php
use cieplik206\BirRegon\Facades\BirRegon;

$productionCompany = BirRegon::forNip('1234567890')->get();

$sandboxCompany = BirRegon::sandbox()
    ->forNip('7740001454')
    ->get();
```

`sandbox()` returns a service backed by the dedicated GUS test client. The
production and sandbox clients keep separate credentials and sessions for the
lifetime of their Laravel container bindings.

Keep the scoped service when several operations belong to one sandbox workflow:

```php
$sandbox = BirRegon::sandbox();

$company = $sandbox->forNip('7740001454')->get();
$diagnostics = $sandbox->diagnostics()->get();
```

This ensures that searches, reports, and diagnostics use the same authenticated
sandbox session. Request builders do not switch environments and never create
one-off clients.

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
