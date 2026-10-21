<?php

declare(strict_types=1);

namespace Stewart\Contracts;

interface Disposable
{
    public function dispose(): void;
}
