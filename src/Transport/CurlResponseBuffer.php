<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use cieplik206\BirRegon\Protocol\RawTransportResult;
use CurlHandle;
use SensitiveParameterValue;

/** @internal */
final class CurlResponseBuffer
{
    private const MAX_HEADER_BYTES = 65_536;

    private SensitiveParameterValue $body;

    /** @var array<string, string> */
    private array $headers = [];

    private int $headerBytes = 0;

    private bool $headersComplete = false;

    private bool $invalid = false;

    private bool $overflow = false;

    private ?int $statusCode = null;

    public function __construct(private readonly int $maxResponseBytes)
    {
        $this->body = new SensitiveParameterValue('');
    }

    public function writeHeader(CurlHandle $handle, #[\SensitiveParameter] string $line): int
    {
        unset($handle);
        $lineLength = strlen($line);
        $this->headerBytes += $lineLength;

        if ($this->headerBytes > self::MAX_HEADER_BYTES || str_contains($line, "\0")) {
            $this->invalid = true;

            return 0;
        }

        if (preg_match('/\AHTTP\/(?:1\.[01]|2|3)\s+([0-9]{3})(?:\s+[^\r\n]*)?\r?\n\z/D', $line, $matches) === 1) {
            $body = $this->body->getValue();

            if (is_string($body) && $body !== '') {
                $this->invalid = true;

                return 0;
            }

            $this->statusCode = (int) $matches[1];
            $this->headers = [];
            $this->headersComplete = false;

            return $lineLength;
        }

        if ($this->headersComplete) {
            $this->invalid = true;

            return 0;
        }

        if ($line === "\r\n" || $line === "\n") {
            $this->headersComplete = $this->statusCode !== null;

            return $lineLength;
        }

        if (
            $this->statusCode === null
            || preg_match('/^[ \t]/', $line) === 1
            || preg_match('/\r(?!\n)/', $line) === 1
        ) {
            $this->invalid = true;

            return 0;
        }

        $separator = strpos($line, ':');

        if ($separator === false) {
            $this->invalid = true;

            return 0;
        }

        $name = strtolower(trim(substr($line, 0, $separator)));
        $value = trim(substr($line, $separator + 1));

        if (
            preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/D', $name) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            $this->invalid = true;

            return 0;
        }

        $protectedHeaders = ['content-encoding', 'content-length', 'content-type', 'transfer-encoding'];

        if (in_array($name, $protectedHeaders, true)) {
            if (array_key_exists($name, $this->headers)) {
                $this->invalid = true;

                return 0;
            }

            $this->headers[$name] = $value;
        }

        if ($name === 'content-length') {
            if (preg_match('/\A[0-9]+\z/D', $value) !== 1 || (int) $value > $this->maxResponseBytes) {
                $this->overflow = true;

                return 0;
            }

            if (isset($this->headers['transfer-encoding'])) {
                $this->invalid = true;

                return 0;
            }
        }

        if ($name === 'transfer-encoding') {
            if (strtolower($value) !== 'chunked' || isset($this->headers['content-length'])) {
                $this->invalid = true;

                return 0;
            }
        }

        if ($name === 'content-encoding' && strtolower($value) !== 'identity') {
            $this->invalid = true;

            return 0;
        }

        return $lineLength;
    }

    public function writeBody(CurlHandle $handle, #[\SensitiveParameter] string $chunk): int
    {
        unset($handle);
        $body = $this->body->getValue();

        if (! is_string($body)) {
            $this->invalid = true;

            return 0;
        }

        $chunkLength = strlen($chunk);

        if ($chunkLength > $this->maxResponseBytes - strlen($body)) {
            $this->overflow = true;

            return 0;
        }

        $this->body = new SensitiveParameterValue($body.$chunk);

        return $chunkLength;
    }

    public function result(): RawTransportResult
    {
        $body = $this->body->getValue();
        $contentType = $this->headers['content-type'] ?? null;

        if (
            $this->invalid
            || $this->overflow
            || ! $this->headersComplete
            || $this->statusCode !== 200
            || ! is_string($body)
            || ! is_string($contentType)
            || $contentType === ''
        ) {
            return RawTransportResult::failure();
        }

        return RawTransportResult::success($body, $contentType);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'body' => '[REDACTED]',
            'status' => $this->statusCode === null ? '[NONE]' : (string) $this->statusCode,
        ];
    }
}
