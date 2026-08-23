<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Facades\BirRegon;

const SANDBOX_NIP = '7740001454';
const SANDBOX_REGON = '610188201';
const SANDBOX_KRS = '0000028860';

beforeEach(function (): void {
    config()->set('bir-regon.sandbox_api_key', sandboxApiKey());
});

it('reads service and data status from the GUS sandbox', function (): void {
    $serviceStatus = BirRegon::sandbox()->service()->get();
    $dataStatus = BirRegon::sandbox()->service()->dataStatus();

    expect(in_array($serviceStatus->status, [0, 1, 2], true))->toBeTrue()
        ->and($serviceStatus->message)->not->toBeEmpty()
        ->and($dataStatus->getTimezone()->getName())->toBe('Europe/Warsaw');
})->group('sandbox');

it('searches the GUS sandbox with every identifier variant', function (): void {
    $sandbox = BirRegon::sandbox();
    $byNip = $sandbox->forNip(SANDBOX_NIP)->get();
    $byRegon = $sandbox->forRegon(SANDBOX_REGON)->get();
    $byKrs = $sandbox->forKrs(SANDBOX_KRS)->get();
    $byNips = $sandbox->forNips([SANDBOX_NIP])->get();
    $byKrsNumbers = $sandbox->forKrsNumbers([SANDBOX_KRS])->get();
    $byRegons = $sandbox->forRegons9([SANDBOX_REGON])->get();

    expect($byNip->regon)->toBe(SANDBOX_REGON)
        ->and($byRegon->nip)->toBe(SANDBOX_NIP)
        ->and($byKrs->regon)->toBe(SANDBOX_REGON)
        ->and($byNips)->toHaveCount(1)
        ->and($byNips->first()?->regon)->toBe(SANDBOX_REGON)
        ->and($byKrsNumbers)->toHaveCount(1)
        ->and($byKrsNumbers->first()?->regon)->toBe(SANDBOX_REGON)
        ->and($byRegons)->toHaveCount(1)
        ->and($byRegons->first()?->regon)->toBe(SANDBOX_REGON);
})->group('sandbox');

it('fetches a full company report from the GUS sandbox', function (): void {
    $report = BirRegon::sandbox()
        ->forRegon(SANDBOX_REGON)
        ->reportType(ReportType::Organization)
        ->getFullReport();

    expect($report->basicData->regon)->toBe(SANDBOX_REGON)
        ->and($report->reportData)->not->toBeEmpty()
        ->and($report->reportData[0]['praw_regon9'])->toBe(SANDBOX_REGON);
})->group('sandbox');

it('reads diagnostics after an unsuccessful sandbox search', function (): void {
    $sandbox = BirRegon::sandbox();

    expect(fn () => $sandbox->forNip('0123456700')->get())
        ->toThrow(BirNotFoundException::class);

    $diagnostics = $sandbox->diagnostics()->get();

    expect($diagnostics->sessionStatus)->toBe(1)
        ->and($diagnostics->messageCode)->toBe(4)
        ->and($diagnostics->message)->toContain('Nie znaleziono');
})->group('sandbox');

function sandboxApiKey(): string
{
    $apiKey = getenv('BIR_SANDBOX_API_KEY');

    if (is_string($apiKey) && $apiKey !== '') {
        return $apiKey;
    }

    return 'abcde12345abcde12345';
}
