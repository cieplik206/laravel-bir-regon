<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Exceptions;

use cieplik206\BirRegon\Enums\SoapFaultCode;

final class BirSoapFaultException extends BirProtocolException
{
    public function __construct(public readonly SoapFaultCode $faultCode)
    {
        parent::__construct(match ($faultCode) {
            SoapFaultCode::Sender => 'GUS BIR rejected the SOAP request.',
            SoapFaultCode::Receiver => 'GUS BIR reported a SOAP service failure.',
            SoapFaultCode::MustUnderstand => 'GUS BIR rejected a required SOAP header.',
            SoapFaultCode::VersionMismatch => 'GUS BIR rejected the SOAP protocol version.',
            SoapFaultCode::DataEncodingUnknown => 'GUS BIR rejected the SOAP data encoding.',
        });
    }
}
