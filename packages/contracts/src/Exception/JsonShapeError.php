<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum JsonShapeError: string implements ExceptionReason
{
    case WrongType = 'wrong_type';
    case Missing = 'missing';
    case UnexpectedValue = 'unexpected_value';
    case InstantInvalid = 'instant_invalid';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::WrongType => '"{key}" is {actualType}; expected {expected}.',
            self::Missing => '"{key}" is missing; expected {expected}.',
            self::UnexpectedValue => '"{key}" is {value}; expected {expected}.',
            self::InstantInvalid => '"{key}" is invalid: {cause}',
        };
    }
}
