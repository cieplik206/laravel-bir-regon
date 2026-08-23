<?php

declare(strict_types=1);

use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\GetValueParameter;

it('describes every GUS BIR SOAP operation exactly', function (
    BirOperation $operation,
    string $name,
    string $namespace,
    string $action,
    string $resultElement,
): void {
    expect($operation->value)->toBe($name)
        ->and($operation->namespace())->toBe($namespace)
        ->and($operation->action())->toBe($action)
        ->and($operation->responseAction())->toBe($action.'Response')
        ->and($operation->resultElement())->toBe($resultElement);
})->with([
    'login' => [
        BirOperation::Login,
        'Zaloguj',
        'http://CIS/BIR/PUBL/2014/07',
        'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj',
        'ZalogujResult',
    ],
    'logout' => [
        BirOperation::Logout,
        'Wyloguj',
        'http://CIS/BIR/PUBL/2014/07',
        'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Wyloguj',
        'WylogujResult',
    ],
    'search' => [
        BirOperation::Search,
        'DaneSzukajPodmioty',
        'http://CIS/BIR/PUBL/2014/07',
        'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty',
        'DaneSzukajPodmiotyResult',
    ],
    'full report' => [
        BirOperation::FullReport,
        'DanePobierzPelnyRaport',
        'http://CIS/BIR/PUBL/2014/07',
        'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport',
        'DanePobierzPelnyRaportResult',
    ],
    'bulk report' => [
        BirOperation::BulkReport,
        'DanePobierzRaportZbiorczy',
        'http://CIS/BIR/PUBL/2014/07',
        'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzRaportZbiorczy',
        'DanePobierzRaportZbiorczyResult',
    ],
    'diagnostics' => [
        BirOperation::GetValue,
        'GetValue',
        'http://CIS/BIR/2014/07',
        'http://CIS/BIR/2014/07/IUslugaBIR/GetValue',
        'GetValueResult',
    ],
]);

it('uses unique operation names and actions', function (): void {
    $operations = BirOperation::cases();
    $names = array_map(static fn (BirOperation $operation): string => $operation->value, $operations);
    $actions = array_map(static fn (BirOperation $operation): string => $operation->action(), $operations);

    expect(array_unique($names))->toHaveCount(count($operations))
        ->and(array_unique($actions))->toHaveCount(count($operations));
});

it('requires a session only for session-scoped GetValue parameters', function (
    GetValueParameter $parameter,
    bool $requiresSession,
): void {
    expect($parameter->requiresSession())->toBe($requiresSession);
})->with([
    'data status' => [GetValueParameter::DataStatus, true],
    'message code' => [GetValueParameter::MessageCode, true],
    'message text' => [GetValueParameter::Message, true],
    'session status' => [GetValueParameter::SessionStatus, true],
    'service status' => [GetValueParameter::ServiceStatus, false],
    'service message' => [GetValueParameter::ServiceMessage, false],
]);
