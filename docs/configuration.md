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
BIR_ENVIRONMENT=prod
```

| Variable | Accepted values | Default | Purpose |
| --- | --- | --- | --- |
| `BIR_API_KEY` | A valid GUS BIR key | Empty | Authenticates searches and reports |
| `BIR_ENVIRONMENT` | `prod` or `dev` | `prod` | Selects the GUS production or test service |

Keep production credentials outside source control. If credentials are stored
in a database or another persistent store, encrypt them at rest.

## Published configuration

The complete configuration file contains two options:

```php
<?php

return [
    'api_key' => env('BIR_API_KEY', ''),
    'environment' => env('BIR_ENVIRONMENT', 'prod'),
];
```

The package merges these defaults with the application's configuration, so a
published file only needs to override values that differ from the defaults.

## Selecting an environment per request

Every fluent request builder supports `inProd()` and `inDev()`:

```php
use cieplik206\BirRegon\Facades\BirRegon;

$productionCompany = BirRegon::forNip('1234567890')
    ->inProd()
    ->get();

$sandboxCompany = BirRegon::forNip('7740001454')
    ->inDev()
    ->get();
```

The override applies only to that builder. Other calls continue to use the
environment configured in `BIR_ENVIRONMENT`.

For a sequence that includes diagnostics, configure the environment globally
for that process so both calls use the same client session.

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
