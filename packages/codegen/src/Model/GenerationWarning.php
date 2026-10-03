<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model;

interface GenerationWarning
{
    public function describeWarning(): string;
}
