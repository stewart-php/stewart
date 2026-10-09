<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

/** @extends StewartException<CommandError> */
final class CommandException extends StewartException
{
    public static function rejected(string $reason): self
    {
        return self::createForReason(CommandError::Rejected, ['reason' => $reason]);
    }
}
