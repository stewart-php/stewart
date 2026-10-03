<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

enum ConnectReturnCode: int
{
    case Accepted = 0;
    case ProtocolVersionUnacceptable = 1;
    case ClientIdRejected = 2;
    case ServerUnavailable = 3;
    case CredentialsInvalid = 4;
    case NotAuthorized = 5;

    public function describeRefusal(): string
    {
        return match ($this) {
            self::Accepted => 'accepted',
            self::ProtocolVersionUnacceptable => 'MQTT 3.1.1 is not supported',
            self::ClientIdRejected => 'the client id is rejected',
            self::ServerUnavailable => 'the server is unavailable',
            self::CredentialsInvalid => 'the username or password is wrong',
            self::NotAuthorized => 'the client is not authorized',
        };
    }
}
