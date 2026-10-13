<?php

declare(strict_types=1);

namespace Stewart\Support\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

/** @internal */
enum JsonShapeError: string implements ExceptionReason
{
    case WrongType = 'wrong_type';
    case Missing = 'missing';
    case UnexpectedValue = 'unexpected_value';
    case InstantInvalid = 'instant_invalid';
    case ObjectRefused = 'object_refused';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::WrongType => '"{key}" is {actualType}; expected {expected}.',
            self::Missing => '"{key}" is missing; expected {expected}.',
            self::UnexpectedValue => '"{key}" is {value}; expected {expected}.',
            self::InstantInvalid => '"{key}" is invalid: {cause}',
            self::ObjectRefused => '"{key}" was refused: {cause}',
        };
    }
}
