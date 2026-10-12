<?php

declare(strict_types=1);

namespace Stewart\Support\State;

use Stewart\Contracts\State\Collection\EntityStateCollection;

/** @internal */
interface CurrentStateReader
{
    public function readCurrentStates(): EntityStateCollection;
}
