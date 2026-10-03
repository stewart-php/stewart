<?php

declare(strict_types=1);

namespace Stewart\Contracts;

interface App
{
    public function initialize(): void;

    public function dispose(): void;
}
