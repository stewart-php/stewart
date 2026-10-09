<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;

enum ComponentErrorCode: string
{
    case ProtocolMismatch = 'protocol_mismatch';
    case NoSession = 'no_session';
    case NotFound = 'not_found';
    case InvalidConfig = 'invalid_config';
    case InvalidState = 'invalid_state';

    public static function tryFromException(HaClientException $exception): ?self
    {
        $code = $exception->reason === HaClientError::CommandRejected ? $exception->findErrorCode() : null;

        return $code === null ? null : self::tryFrom($code);
    }
}
