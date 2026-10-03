<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use BackedEnum;

interface ExceptionReason extends BackedEnum
{
    public function messageTemplate(): string;
}
