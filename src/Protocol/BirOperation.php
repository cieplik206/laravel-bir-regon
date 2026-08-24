<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

enum BirOperation: string
{
    private const DIAGNOSTICS_ACTION_BASE = 'http://CIS/BIR/2014/07/IUslugaBIR/';

    private const DIAGNOSTICS_NAMESPACE = 'http://CIS/BIR/2014/07';

    private const PUBLIC_ACTION_BASE = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/';

    private const PUBLIC_NAMESPACE = 'http://CIS/BIR/PUBL/2014/07';

    case Login = 'Zaloguj';
    case Logout = 'Wyloguj';
    case Search = 'DaneSzukajPodmioty';
    case FullReport = 'DanePobierzPelnyRaport';
    case BulkReport = 'DanePobierzRaportZbiorczy';
    case GetValue = 'GetValue';

    public function action(): string
    {
        return ($this === self::GetValue ? self::DIAGNOSTICS_ACTION_BASE : self::PUBLIC_ACTION_BASE)
            .$this->value;
    }

    public function responseAction(): string
    {
        return $this->action().'Response';
    }

    public function namespace(): string
    {
        return $this === self::GetValue ? self::DIAGNOSTICS_NAMESPACE : self::PUBLIC_NAMESPACE;
    }

    public function resultElement(): string
    {
        return $this->value.'Result';
    }
}
