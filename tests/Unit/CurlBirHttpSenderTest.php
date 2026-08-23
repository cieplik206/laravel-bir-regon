<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Transport\CurlBirHttpSender;
use cieplik206\BirRegon\Transport\CurlExecutorInterface;

function curlTraceContains(mixed $value, string $needle): bool
{
    if (is_string($value)) {
        return str_contains($value, $needle);
    }

    if ($value instanceof SensitiveParameterValue) {
        return false;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            if (curlTraceContains($item, $needle)) {
                return true;
            }
        }
    }

    return false;
}

it('does not expose the SOAP envelope or session through the cURL handle or a warning backtrace', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $soapEnvelope = '<soap:Envelope><api-key>'.$apiKey.'</api-key></soap:Envelope>';
    $observed = [
        'apiKeyInTrace' => false,
        'sessionInTrace' => false,
        'xmlInTrace' => false,
        'apiKeyInHandle' => false,
        'sessionInHandle' => false,
        'xmlInHandle' => false,
        'warnings' => 0,
    ];
    $executor = new class($observed) implements CurlExecutorInterface
    {
        /**
         * @param array{
         *     apiKeyInTrace: bool,
         *     sessionInTrace: bool,
         *     xmlInTrace: bool,
         *     apiKeyInHandle: bool,
         *     sessionInHandle: bool,
         *     xmlInHandle: bool,
         *     warnings: int
         * } $observed
         */
        public function __construct(public array &$observed) {}

        public function execute(
            CurlHandle $handle,
            #[SensitiveParameter] array $headers,
            #[SensitiveParameter] string $body,
        ): bool {
            unset($headers, $body);
            $handleDump = print_r($handle, true);

            $this->observed['apiKeyInHandle'] = str_contains($handleDump, 'APIKEYSENTINEL123456');
            $this->observed['sessionInHandle'] = str_contains($handleDump, 'SIDSESSION1234567890');
            $this->observed['xmlInHandle'] = str_contains($handleDump, '<soap:Envelope');

            trigger_error('Deterministic fake cURL executor warning.', E_USER_WARNING);

            return false;
        }
    };
    $handlerInstalled = false;

    try {
        set_error_handler(static function () use (&$observed, $apiKey, $sessionId): bool {
            $trace = debug_backtrace();
            $observed['apiKeyInTrace'] = $observed['apiKeyInTrace']
                || curlTraceContains($trace, $apiKey);
            $observed['sessionInTrace'] = $observed['sessionInTrace']
                || curlTraceContains($trace, $sessionId);
            $observed['xmlInTrace'] = $observed['xmlInTrace']
                || curlTraceContains($trace, '<soap:Envelope');
            $observed['warnings']++;

            return true;
        });
        $handlerInstalled = true;

        $result = (new CurlBirHttpSender(
            environment: Environment::Sandbox,
            connectionTimeout: 1,
            requestTimeout: 1,
            maxResponseBytes: 1024,
            userAgent: 'laravel-bir-regon-tests/2',
            executor: $executor,
        ))->send(BirOperation::Logout, $soapEnvelope, $sessionId);
    } finally {
        if ($handlerInstalled) {
            restore_error_handler();
        }
    }

    expect($result->successful)->toBeFalse()
        ->and($observed['warnings'])->toBeGreaterThanOrEqual(1)
        ->and($observed)->toMatchArray([
            'apiKeyInTrace' => false,
            'sessionInTrace' => false,
            'xmlInTrace' => false,
            'apiKeyInHandle' => false,
            'sessionInHandle' => false,
            'xmlInHandle' => false,
        ]);
});

it('does not emit a PHP 8.5 curl handle deprecation after a request', function (): void {
    $executor = new class implements CurlExecutorInterface
    {
        public function execute(
            CurlHandle $handle,
            #[SensitiveParameter] array $headers,
            #[SensitiveParameter] string $body,
        ): bool {
            unset($handle, $headers, $body);

            return false;
        }
    };
    $handlerInstalled = false;

    try {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        $handlerInstalled = true;

        $result = (new CurlBirHttpSender(
            environment: Environment::Sandbox,
            connectionTimeout: 1,
            requestTimeout: 1,
            maxResponseBytes: 1024,
            userAgent: 'laravel-bir-regon-tests/2',
            executor: $executor,
        ))->send(BirOperation::Login, '<soap:Envelope/>', null);
    } finally {
        if ($handlerInstalled) {
            restore_error_handler();
        }
    }

    expect($result->successful)->toBeFalse();
});

it('reuses a reset handle while replacing request headers and body', function (): void {
    $observed = [
        'handleIds' => [],
        'methods' => [],
        'headers' => [],
        'bodies' => [],
    ];
    $executor = new class($observed) implements CurlExecutorInterface
    {
        /**
         * @param array{
         *     handleIds: list<int>,
         *     methods: list<string>,
         *     headers: list<list<string>>,
         *     bodies: list<string>
         * } $observed
         */
        public function __construct(public array &$observed) {}

        /**
         * @param  list<string>  $headers
         */
        public function execute(
            CurlHandle $handle,
            #[SensitiveParameter] array $headers,
            #[SensitiveParameter] string $body,
        ): bool {
            $this->observed['handleIds'][] = spl_object_id($handle);
            $this->observed['methods'][] = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_METHOD);
            $this->observed['headers'][] = $headers;
            $this->observed['bodies'][] = $body;

            if (count($this->observed['handleIds']) === 1) {
                // The sender does not overwrite this option; its reset must remove it.
                curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }

            return false;
        }
    };
    $sender = new CurlBirHttpSender(
        environment: Environment::Sandbox,
        connectionTimeout: 1,
        requestTimeout: 1,
        maxResponseBytes: 1024,
        userAgent: 'laravel-bir-regon-tests/2',
        executor: $executor,
    );

    $sender->send(BirOperation::Logout, '<soap:first/>', 'FIRSTSESSION12345678');
    $sender->send(BirOperation::Login, '<soap:second/>', null);

    expect($observed['handleIds'])->toHaveCount(2)
        ->and($observed['handleIds'][1])->toBe($observed['handleIds'][0])
        ->and($observed['methods'][1])->toBe($observed['methods'][0])
        ->and($observed['methods'][1])->not->toBe('DELETE')
        ->and($observed['headers'][0])->toContain('sid: FIRSTSESSION12345678')
        ->and(array_values(array_filter(
            $observed['headers'][1],
            static fn (string $header): bool => str_starts_with(strtolower($header), 'sid:'),
        )))->toBe([])
        ->and($observed['headers'][0])->not->toContain('Connection: close')
        ->and($observed['headers'][1])->not->toContain('Connection: close')
        ->and($observed['bodies'])->toBe(['<soap:first/>', '<soap:second/>']);
});

it('isolates persistent handles between sender instances', function (): void {
    $handleIds = [];
    $executor = new class($handleIds) implements CurlExecutorInterface
    {
        /** @param list<int> $handleIds */
        public function __construct(public array &$handleIds) {}

        /** @param list<string> $headers */
        public function execute(
            CurlHandle $handle,
            #[SensitiveParameter] array $headers,
            #[SensitiveParameter] string $body,
        ): bool {
            unset($headers, $body);
            $this->handleIds[] = spl_object_id($handle);

            return false;
        }
    };

    $productionSender = new CurlBirHttpSender(
        environment: Environment::Production,
        connectionTimeout: 1,
        requestTimeout: 1,
        maxResponseBytes: 1024,
        userAgent: 'laravel-bir-regon-tests/2',
        executor: $executor,
    );

    $sandboxSender = new CurlBirHttpSender(
        environment: Environment::Sandbox,
        connectionTimeout: 1,
        requestTimeout: 1,
        maxResponseBytes: 1024,
        userAgent: 'laravel-bir-regon-tests/2',
        executor: $executor,
    );

    $productionSender->send(BirOperation::Login, '<soap:prod/>', null);
    $sandboxSender->send(BirOperation::Login, '<soap:sandbox/>', null);

    expect($handleIds)->toHaveCount(2)
        ->and($handleIds[1])->not->toBe($handleIds[0]);
});
