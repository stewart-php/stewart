<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum ScaffoldError: string implements ExceptionReason
{
    case ClassNameInvalid = 'class_name_invalid';
    case FileExists = 'file_exists';
    case IdTaken = 'id_taken';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::ClassNameInvalid => '"{name}" is not a class name; use letters and digits starting with a capital, such as PorchLight.',
            self::FileExists => '{path} already exists.',
            self::IdTaken => 'An automation already has the ID "{appId}".',
        };
    }
}
