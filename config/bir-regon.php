<?php

declare(strict_types=1);

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
        'enabled' => (bool) env('BIR_RATE_LIMIT_ENABLED', true),
        'store' => env('BIR_RATE_LIMIT_STORE'),
        'prefix' => env('BIR_RATE_LIMIT_PREFIX', 'bir-regon:rate-limit'),
    ],
];
