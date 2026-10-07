<?php

declare(strict_types=1);

namespace Stewart\Contracts\State;

use Stewart\Contracts\State\Collection\EntityStateCollection;

interface CurrentStateReader
{
    public function readCurrentStates(): EntityStateCollection;
}
