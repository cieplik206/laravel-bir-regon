<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function composerManifest(): array
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/composer.json');

    if ($contents === false) {
        throw new RuntimeException('Unable to read composer.json.');
    }

    return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
}

it('declares the version 2 PHP, Laravel, and test-tool requirements', function (): void {
    $composer = composerManifest();
    $requirements = $composer['require'] ?? null;
    $developmentRequirements = $composer['require-dev'] ?? null;

    expect($requirements)->toBeArray()
        ->and($requirements['php'] ?? null)->toBe('^8.4')
        ->and($requirements['illuminate/cache'] ?? null)->toBe('^13.0')
        ->and($requirements['illuminate/contracts'] ?? null)->toBe('^13.0')
        ->and($requirements['illuminate/support'] ?? null)->toBe('^13.0')
        ->and($developmentRequirements)->toBeArray()
        ->and($developmentRequirements['orchestra/testbench'] ?? null)->toBe('^11.0')
        ->and($developmentRequirements['pestphp/pest'] ?? null)->toBe('^5.0')
        ->and($developmentRequirements['pestphp/pest-plugin-laravel'] ?? null)->toBe('^5.0')
        ->and($developmentRequirements['pestphp/pest-plugin-phpstan'] ?? null)->toBe('^5.0');
});

it('declares the extensions required by the bounded HTTPS transport', function (): void {
    $composer = composerManifest();
    $requirements = $composer['require'] ?? null;

    expect($requirements)->toBeArray()
        ->and($requirements['ext-curl'] ?? null)->toBe('*')
        ->and($requirements['ext-dom'] ?? null)->toBe('*')
        ->and($requirements['ext-libxml'] ?? null)->toBe('*')
        ->and($requirements)->not->toHaveKey('ext-soap');
});
