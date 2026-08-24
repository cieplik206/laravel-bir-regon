<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\Enums\IdentifierValidationMode;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Tests\Support\FakeBirGateway;

it('keeps format-only validation as the direct client default', function (Closure $operation): void {
    $gateway = new FakeBirGateway;
    $client = new BirClient($gateway);

    expect(fn () => $operation($client))
        ->toThrow(BirNotFoundException::class)
        ->and($gateway->calls)->toHaveCount(1);
})->with([
    'NIP search' => [static fn (BirClient $client): mixed => $client->searchByNip('7740001455')],
    'REGON-9 search' => [static fn (BirClient $client): mixed => $client->searchByRegon('610188202')],
    'REGON-14 search' => [static fn (BirClient $client): mixed => $client->searchByRegon('61018820100004')],
    'NIP batch' => [static fn (BirClient $client): mixed => $client->searchByNips(['7740001455'])],
    'REGON-9 batch' => [static fn (BirClient $client): mixed => $client->searchByRegons9(['610188202'])],
    'REGON-14 batch' => [static fn (BirClient $client): mixed => $client->searchByRegons14(['61018820100004'])],
    'singular NIP full report' => [static fn (BirClient $client): mixed => $client->getFullReportByNip(
        '7740001455',
        ReportType::Organization,
    )],
    'plural NIP full report' => [static fn (BirClient $client): mixed => $client->getFullReportsByNip(
        '7740001455',
        ReportType::Organization,
    )],
    'singular REGON full report' => [static fn (BirClient $client): mixed => $client->getFullReport(
        '610188202',
        ReportType::Organization,
    )],
    'plural REGON full report' => [static fn (BirClient $client): mixed => $client->getFullReports(
        '61018820100004',
        ReportType::Organization,
    )],
]);

it('rejects invalid NIP and REGON checksums before gateway access', function (
    Closure $operation,
): void {
    $gateway = new FakeBirGateway;
    $client = new BirClient($gateway, IdentifierValidationMode::FormatAndChecksum);

    expect(fn () => $operation($client))
        ->toThrow(BirValidationException::class)
        ->and($gateway->calls)->toBe([]);
})->with([
    'NIP search' => [static fn (BirClient $client): mixed => $client->searchByNip('7740001455')],
    'REGON-9 search' => [static fn (BirClient $client): mixed => $client->searchByRegon('610188202')],
    'REGON-14 search' => [static fn (BirClient $client): mixed => $client->searchByRegon('61018820100004')],
    'NIP batch' => [static fn (BirClient $client): mixed => $client->searchByNips(['7740001454', '7740001455'])],
    'REGON-9 batch' => [static fn (BirClient $client): mixed => $client->searchByRegons9(['610188201', '610188202'])],
    'REGON-14 batch' => [static fn (BirClient $client): mixed => $client->searchByRegons14(['61018820100003', '61018820100004'])],
    'singular NIP full report' => [static fn (BirClient $client): mixed => $client->getFullReportByNip(
        '7740001455',
        ReportType::Organization,
    )],
    'plural NIP full report' => [static fn (BirClient $client): mixed => $client->getFullReportsByNip(
        '7740001455',
        ReportType::Organization,
    )],
    'singular REGON full report' => [static fn (BirClient $client): mixed => $client->getFullReport(
        '610188202',
        ReportType::Organization,
    )],
    'plural REGON full report' => [static fn (BirClient $client): mixed => $client->getFullReports(
        '61018820100004',
        ReportType::Organization,
    )],
]);

it('accepts checksum-valid NIP and REGON values before gateway access', function (
    Closure $operation,
): void {
    $gateway = new FakeBirGateway;
    $client = new BirClient($gateway, IdentifierValidationMode::FormatAndChecksum);

    expect(fn () => $operation($client))
        ->toThrow(BirNotFoundException::class)
        ->and($gateway->calls)->toHaveCount(1);
})->with([
    'NIP search' => [static fn (BirClient $client): mixed => $client->searchByNip('7740001454')],
    'REGON-9 search' => [static fn (BirClient $client): mixed => $client->searchByRegon('610188201')],
    'REGON-14 search' => [static fn (BirClient $client): mixed => $client->searchByRegon('61018820100003')],
    'NIP batch' => [static fn (BirClient $client): mixed => $client->searchByNips(['7740001454'])],
    'REGON-9 batch' => [static fn (BirClient $client): mixed => $client->searchByRegons9(['610188201'])],
    'REGON-14 batch' => [static fn (BirClient $client): mixed => $client->searchByRegons14(['61018820100003'])],
]);

it('keeps KRS as format-only in every validation mode', function (
    IdentifierValidationMode $mode,
    Closure $operation,
): void {
    $gateway = new FakeBirGateway;
    $client = new BirClient($gateway, $mode);

    expect(fn () => $operation($client))
        ->toThrow(BirNotFoundException::class)
        ->and($gateway->calls)->toHaveCount(1);
})->with([
    'format-only single KRS' => [
        IdentifierValidationMode::FormatOnly,
        static fn (BirClient $client): mixed => $client->searchByKrs('0000028860'),
    ],
    'checksum-mode single KRS' => [
        IdentifierValidationMode::FormatAndChecksum,
        static fn (BirClient $client): mixed => $client->searchByKrs('0000028860'),
    ],
    'format-only KRS batch' => [
        IdentifierValidationMode::FormatOnly,
        static fn (BirClient $client): mixed => $client->searchByKrsNumbers(['0000028860']),
    ],
    'checksum-mode KRS batch' => [
        IdentifierValidationMode::FormatAndChecksum,
        static fn (BirClient $client): mixed => $client->searchByKrsNumbers(['0000028860']),
    ],
]);

it('does not normalize decorated NIP input', function (string $nip): void {
    $gateway = new FakeBirGateway;
    $client = new BirClient($gateway, IdentifierValidationMode::FormatAndChecksum);

    expect(fn () => $client->searchByNip($nip))
        ->toThrow(BirValidationException::class)
        ->and($gateway->calls)->toBe([]);
})->with([
    'country prefix' => 'PL7740001454',
    'dashes' => '774-000-14-54',
    'spaces' => '774 000 14 54',
]);
