<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum TopicError: string implements ExceptionReason
{
    case PayloadInvalid = 'payload_invalid';
    case PublishFailed = 'publish_failed';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::PayloadInvalid => 'Topic payload at {path} is {actualType}; expected null, a finite scalar or an array of those.',
            self::PublishFailed => 'Could not publish to topic {topic}: {cause}',
        };
    }
}
