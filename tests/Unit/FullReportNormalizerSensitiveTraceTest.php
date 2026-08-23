<?php

declare(strict_types=1);

use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use cieplik206\BirRegon\Protocol\SearchResult;

/** @param SplObjectStorage<object, null> $visited */
function fullReportThrowableGraphContainsRawValue(
    mixed $value,
    string $sensitiveValue,
    SplObjectStorage $visited,
): bool {
    if (is_string($value)) {
        return str_contains($value, $sensitiveValue);
    }

    if ($value instanceof SensitiveParameterValue) {
        return false;
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (
                fullReportThrowableGraphContainsRawValue($key, $sensitiveValue, $visited)
                || fullReportThrowableGraphContainsRawValue($item, $sensitiveValue, $visited)
            ) {
                return true;
            }
        }

        return false;
    }

    if (! is_object($value) || $visited->offsetExists($value)) {
        return false;
    }

    $visited->offsetSet($value);

    if ($value instanceof Throwable) {
        return str_contains($value->getMessage(), $sensitiveValue)
            || fullReportThrowableGraphContainsRawValue($value->getTrace(), $sensitiveValue, $visited)
            || ($value->getPrevious() instanceof Throwable
                && fullReportThrowableGraphContainsRawValue(
                    $value->getPrevious(),
                    $sensitiveValue,
                    $visited,
                ));
    }

    return fullReportThrowableGraphContainsRawValue((array) $value, $sensitiveValue, $visited);
}

it('redacts raw GUS full-report rows from the complete exception trace', function (): void {
    $originalExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');

    if (
        ini_set('zend.exception_ignore_args', '0') === false
        || ini_get('zend.exception_ignore_args') !== '0'
    ) {
        throw new RuntimeException('Unable to enable exception arguments for the full-report trace test.');
    }

    $sensitiveValue = 'RAW-GUS-ROW-SECURITY-SENTINEL';
    $report = SearchResult::tryFromRecord([
        'Regon' => '610188201',
        'Typ' => 'P',
        'SilosID' => '6',
    ]);
    $exception = null;

    try {
        FullCompanyReportData::fromSearchResult(
            $report ?? throw new RuntimeException('Expected a valid search-result fixture.'),
            ReportType::Organization,
            [[
                'praw_dataPowstania' => "invalid-date-{$sensitiveValue}",
                'future_raw_field' => $sensitiveValue,
            ]],
        );
    } catch (BirProtocolException $caught) {
        $exception = $caught;
    } finally {
        if (is_string($originalExceptionIgnoreArgs)) {
            ini_set('zend.exception_ignore_args', $originalExceptionIgnoreArgs);
        }
    }

    expect($exception)->toBeInstanceOf(BirProtocolException::class)
        ->and((string) $exception)->not->toContain($sensitiveValue)
        ->and(fullReportThrowableGraphContainsRawValue(
            $exception,
            $sensitiveValue,
            new SplObjectStorage,
        ))->toBeFalse();
});

it('redacts raw rows when report cardinality validation fails', function (): void {
    $originalExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');

    if (
        ini_set('zend.exception_ignore_args', '0') === false
        || ini_get('zend.exception_ignore_args') !== '0'
    ) {
        throw new RuntimeException('Unable to enable exception arguments for the full-report trace test.');
    }

    $sensitiveValue = 'RAW-GUS-CARDINALITY-SECURITY-SENTINEL';
    $report = SearchResult::tryFromRecord([
        'Regon' => '610188201',
        'Typ' => 'P',
        'SilosID' => '6',
    ]);
    $exception = null;

    try {
        FullCompanyReportData::fromSearchResult(
            $report ?? throw new RuntimeException('Expected a valid search-result fixture.'),
            ReportType::Organization,
            [
                ['future_raw_field' => $sensitiveValue],
                ['future_raw_field' => $sensitiveValue],
            ],
        );
    } catch (BirProtocolException $caught) {
        $exception = $caught;
    } finally {
        if (is_string($originalExceptionIgnoreArgs)) {
            ini_set('zend.exception_ignore_args', $originalExceptionIgnoreArgs);
        }
    }

    expect($exception)->toBeInstanceOf(BirProtocolException::class)
        ->and((string) $exception)->not->toContain($sensitiveValue)
        ->and(fullReportThrowableGraphContainsRawValue(
            $exception,
            $sensitiveValue,
            new SplObjectStorage,
        ))->toBeFalse();
});
