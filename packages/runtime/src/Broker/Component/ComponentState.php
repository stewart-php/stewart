<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Component;

enum ComponentState: string
{
    case Unchecked = 'unchecked';
    case Missing = 'missing';
    case ProtocolMismatch = 'protocol_mismatch';
    case Refused = 'refused';
    case Replaced = 'replaced';
    case Active = 'active';
}
