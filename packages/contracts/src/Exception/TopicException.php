<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Throwable;

/** @extends StewartException<TopicError> */
final class TopicException extends StewartException
{
    public static function payloadInvalid(string $path, string $actualType): self
    {
        return self::createForReason(TopicError::PayloadInvalid, ['path' => $path, 'actualType' => $actualType]);
    }

    public static function publishFailed(string $topic, Throwable $previous): self
    {
        return self::createForReason(TopicError::PublishFailed, ['topic' => $topic], $previous);
    }
}
