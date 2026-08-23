<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\TransportResponse;
use cieplik206\BirRegon\Tests\Support\QueueBirSoapTransport;

it('reuses the sandbox session without leaking it into the production gateway', function (): void {
    $productionSession = 'PRODUCTIONSESSION001';
    $sandboxSession = 'SANDBOXSESSION000001';
    $searchResult = sandboxSessionSearchResult();

    $productionTransport = (new QueueBirSoapTransport)
        ->queue(
            TransportResponse::success($productionSession),
            TransportResponse::success($searchResult),
        );
    $sandboxTransport = (new QueueBirSoapTransport)
        ->queue(
            TransportResponse::success($sandboxSession),
            TransportResponse::success($searchResult),
            TransportResponse::success($searchResult),
        );

    $service = new BirRegonService(
        new BirClient(new NativeBirGateway($productionTransport)),
        new BirClient(new NativeBirGateway($sandboxTransport)),
    );

    $firstSandboxResult = $service->sandbox()->forNip('1111111111')->get();
    $secondSandboxResult = $service->sandbox()->forNip('2222222222')->get();
    $productionResult = $service->forNip('3333333333')->get();

    expect($firstSandboxResult->sole()->regon)->toBe('012345678')
        ->and($secondSandboxResult->sole()->regon)->toBe('012345678')
        ->and($productionResult->sole()->regon)->toBe('012345678')
        ->and($sandboxTransport->authenticationChecks)->toBe(1)
        ->and($productionTransport->authenticationChecks)->toBe(1)
        ->and($sandboxTransport->calls)->toHaveCount(3)
        ->and($sandboxTransport->calls[0])->toBe([BirOperation::Login, [], null])
        ->and($sandboxTransport->calls[1])->toEqual([
            BirOperation::Search,
            ['criteria' => SearchCriteria::nip('1111111111')],
            $sandboxSession,
        ])
        ->and($sandboxTransport->calls[2])->toEqual([
            BirOperation::Search,
            ['criteria' => SearchCriteria::nip('2222222222')],
            $sandboxSession,
        ])
        ->and($productionTransport->calls)->toEqual([
            [BirOperation::Login, [], null],
            [
                BirOperation::Search,
                ['criteria' => SearchCriteria::nip('3333333333')],
                $productionSession,
            ],
        ])
        ->and($sandboxTransport->sessionIds)->toBe([
            null,
            $sandboxSession,
            $sandboxSession,
            $sandboxSession,
        ])
        ->and($productionTransport->sessionIds)->toBe([
            null,
            $productionSession,
            $productionSession,
        ])
        ->and($sandboxTransport->sessionIds)->not->toContain($productionSession)
        ->and($productionTransport->sessionIds)->not->toContain($sandboxSession);
});

function sandboxSessionSearchResult(): string
{
    $contents = file_get_contents(__DIR__.'/../Fixtures/Gus/inner/search-single.xml');

    if (! is_string($contents)) {
        throw new LogicException('The search response fixture could not be read.');
    }

    return $contents;
}
